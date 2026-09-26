<?php
/**
 * The station as one continuous MP3, for an Icecast server to carry.
 *
 * This is not where listeners are sent - the player is, and it needs no
 * per-listener PHP playout connection because it reads web files and a clock.
 * Normal host/CDN bandwidth and request limits still apply. This address exists so that a
 * station can *also* reach the places a plain stream is expected: radio apps,
 * car receivers, directories. An Icecast server opens it once and fans the
 * audience out itself, so the audience can grow without anything changing
 * here.
 *
 * The connection limit below is a guard on an anonymous route that forks
 * FFmpeg, not a listener budget. One connection is what the intended use
 * needs.
 */

declare(strict_types=1);

require_once __DIR__ . '/publisher.php';
require_once __DIR__ . '/media-tools.php';
require_once __DIR__ . '/playout.php';

const DIRECT_STREAM_BITRATE_KBPS = 128;
const DIRECT_STREAM_SAMPLE_RATE = 44100;
const DIRECT_STREAM_MAX_CONNECTIONS = 3;

/**
 * Bytes of audio between two ICY metadata blocks. At 128 kbps this is one
 * second, so a title lands about as fast as the track it names.
 */
const DIRECT_STREAM_METADATA_INTERVAL = 16000;

/**
 * Writes the response body, carrying the name of what is playing inside it.
 *
 * Internet radio has no second channel for "now playing": a client that asks
 * with `Icy-MetaData: 1` is told an interval, and from then on the server
 * inserts a small block after every interval bytes of audio. Icecast relays
 * these through to its own listeners, and directories read them, so this is
 * what makes a station's listing say a track name instead of only a station
 * name forever.
 *
 * A client that did not ask gets the audio untouched: sending it a block it
 * is not expecting would be heard as a click.
 */
final class DirectStreamBody
{
    private int $untilMetadata;
    private string $pending = '';
    private string $announced = '';

    public function __construct(private bool $withMetadata)
    {
        $this->untilMetadata = DIRECT_STREAM_METADATA_INTERVAL;
    }

    /** Whether the client asked to be told what is playing. */
    public static function wanted(): bool
    {
        return trim((string) ($_SERVER['HTTP_ICY_METADATA'] ?? '')) === '1';
    }

    /** What is playing now. Sent at the next interval, once, until it changes. */
    public function nowPlaying(string $title): void
    {
        $this->pending = $title;
    }

    /** Audio out, with a metadata block wherever the interval falls due. */
    public function write(string $audio): void
    {
        if (!$this->withMetadata) {
            echo $audio;
            return;
        }
        while ($audio !== '') {
            $take = min($this->untilMetadata, strlen($audio));
            echo substr($audio, 0, $take);
            $audio = substr($audio, $take);
            $this->untilMetadata -= $take;
            // The moment the interval is full, not when more audio turns up:
            // a client counts exactly this many bytes and then reads the
            // length byte, so it must already be there.
            if ($this->untilMetadata === 0) {
                echo $this->block();
                $this->untilMetadata = DIRECT_STREAM_METADATA_INTERVAL;
            }
        }
    }

    /**
     * One ICY block: a length in sixteen-byte units, then the padded text. A
     * zero length is the way to say "still the same", which is most of them.
     */
    private function block(): string
    {
        if ($this->pending === $this->announced) {
            return "\x00";
        }
        $this->announced = $this->pending;
        if ($this->pending === '') {
            return "\x00";
        }
        // The field is delimited by quotes and semicolons and cannot carry a
        // newline, so they go rather than truncating someone's track title.
        $title = trim(str_replace(["'", ';', "\r", "\n", "\0"], ' ', $this->pending));
        $title = mb_substr($title, 0, 200);
        $payload = "StreamTitle='" . $title . "';";
        $blocks = (int) ceil(strlen($payload) / 16);
        return chr($blocks) . str_pad($payload, $blocks * 16, "\0");
    }
}

/** "Credit - Title", as internet radio has always written it. */
function direct_stream_now_playing(array $position): string
{
    $track = (array) ($position['track'] ?? []);
    $title = trim((string) ($track['title'] ?? ''));
    $credit = trim((string) ($track['credit'] ?? ''));
    if ($title === '') return '';
    return $credit === '' ? $title : $credit . ' - ' . $title;
}

/**
 * Bound public CPU use. An Icecast relay needs one slot and the rest are
 * slack - for a second relay, a reconnection that overlaps the one it
 * replaces, or someone checking the address works. Without a bound, an
 * anonymous request could fork FFmpeg without limit.
 *
 * @return array{handle:resource,path:string}|null
 */
function direct_stream_acquire_slot(string $stationId): ?array
{
    for ($number = 1; $number <= DIRECT_STREAM_MAX_CONNECTIONS; $number++) {
        $path = PANEL_VAR . '/stream-' . $stationId . '-' . $number . '.lock';
        $handle = @fopen($path, 'c+b');
        if ($handle !== false && @flock($handle, LOCK_EX | LOCK_NB)) {
            return ['handle' => $handle, 'path' => $path];
        }
        if (is_resource($handle)) fclose($handle);
    }
    return null;
}

/**
 * Give a slot back.
 *
 * The lock file is deliberately left on disk. Deleting it would free the
 * *name* while another connection may already hold a lock on the file that
 * name used to point at: the next arrival would then create a fresh file at
 * the same name, lock that, and two listeners would be counted as one slot.
 * On POSIX, where a name can be unlinked out from under an open descriptor,
 * that is a real way past the only limit standing between an anonymous
 * request and an unbounded number of FFmpeg processes. The files are empty
 * and there are at most DIRECT_STREAM_MAX_CONNECTIONS of them per station.
 *
 * @param array{handle:resource,path:string}|null $slot
 */
function direct_stream_release_slot(?array $slot): void
{
    if ($slot === null) return;
    @flock($slot['handle'], LOCK_UN);
    @fclose($slot['handle']);
}

/** A published track may only make FFmpeg read inside its own station folder. */
function direct_stream_audio_path(string $stationId, string $mediaUrl): ?string
{
    $prefix = station_media_url($stationId, '');
    if (!str_starts_with($mediaUrl, $prefix)) return null;
    $name = substr($mediaUrl, strlen($prefix));
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name)) return null;
    $path = station_media_dir($stationId) . '/' . $name;
    return is_file($path) ? $path : null;
}

/**
 * One fixed, shell-free FFmpeg invocation for the remaining part of a slot.
 * Uniform CBR output makes track boundaries safe to concatenate in one HTTP
 * audio/mpeg response. PHP paces the bytes itself: FFmpeg's `-re` permits a
 * short startup burst every time a new input file is opened.
 *
 * @param list<string> $ffmpeg
 * @return list<string>
 */
function direct_stream_command(
    string $path,
    int $offsetMs,
    int $lengthMs,
    string $encoder,
    array $ffmpeg = ['ffmpeg']
): array {
    if (!in_array($encoder, ['libmp3lame', 'libshine', 'mp3_mf'], true)) {
        throw new InvalidArgumentException('unsupported MP3 encoder');
    }
    $seconds = static fn (int $milliseconds): string => number_format(max(0, $milliseconds) / 1000, 3, '.', '');
    return [
        ...$ffmpeg,
        '-hide_banner', '-loglevel', 'error', '-nostdin',
        '-ss', $seconds($offsetMs), '-i', $path,
        '-t', $seconds($lengthMs),
        '-map', '0:a:0', '-vn', '-map_metadata', '-1',
        '-ac', '2', '-ar', (string) DIRECT_STREAM_SAMPLE_RATE,
        '-c:a', $encoder, '-b:a', DIRECT_STREAM_BITRATE_KBPS . 'k',
        '-f', 'mp3', '-write_xing', '0', '-id3v2_version', '0',
        '-flush_packets', '1', 'pipe:1',
    ];
}

/**
 * Transcode and forward one scheduled slot. Each listener owns this child;
 * disconnecting that listener terminates it immediately.
 *
 * True when FFmpeg finished cleanly, even having sent nothing: a file that
 * ends early can end before the offset it was asked to start at, and the
 * rest of its slot is silence, not a fault. False when the listener left or
 * FFmpeg failed.
 *
 * @param list<string> $command
 */
function direct_stream_run_process(array $command, ?DirectStreamBody $body = null): bool
{
    // Pacing counts audio only. Metadata blocks are bytes on the wire but not
    // seconds of sound, so they must not shorten the sleep between chunks.
    $body ??= new DirectStreamBody(false);
    $pipes = [];
    $process = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) return false;

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], true);
    stream_set_blocking($pipes[2], false);
    $sent = 0;
    $clockStart = null;
    $bytesPerSecond = DIRECT_STREAM_BITRATE_KBPS * 1000 / 8;
    $exitCode = -1;
    $aborted = false;

    while (!feof($pipes[1])) {
        $chunk = fread($pipes[1], 4096);
        if ($chunk === false) break;
        if ($chunk !== '') {
            $clockStart ??= microtime(true);
            $body->write($chunk);
            $sent += strlen($chunk);
            flush();
            if (connection_aborted()) {
                $aborted = true;
                break;
            }
            $due = $clockStart + $sent / $bytesPerSecond;
            while (($wait = $due - microtime(true)) > 0 && !connection_aborted()) {
                usleep((int) min(100000, max(1000, $wait * 1000000)));
            }
        }
    }

    $status = proc_get_status($process);
    if (!$status['running']) $exitCode = (int) $status['exitcode'];
    if ($aborted || $status['running']) {
        $pid = (int) ($status['pid'] ?? 0);
        proc_terminate($process);
        usleep(30000);
        $after = proc_get_status($process);
        if ($after['running']) {
            if (PHP_OS_FAMILY === 'Windows' && $pid > 0) media_tool_kill_windows($pid);
            else proc_terminate($process, 9);
        } elseif ($exitCode < 0) {
            $exitCode = (int) $after['exitcode'];
        }
    }

    fclose($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $closed = proc_close($process);
    if ($exitCode < 0 && $closed >= 0) $exitCode = $closed;
    return !$aborted && $exitCode === 0;
}

/**
 * Frame $index of a run of silence, at the stream's own format: MPEG-1
 * Layer III, joint stereo, no CRC.
 *
 * The side information is all zero, so the frame carries no audio data -
 * every decoder plays it as silence - and borrows nothing from the frames
 * around it, so it sits between two FFmpeg segments without disturbing
 * either. A frame is 1152 samples and 144 * bitrate / sample rate bytes,
 * 417.96 here: 417, with a padding byte where the run falls behind, so a run
 * averages the bitrate exactly, as an encoder's does.
 */
function direct_stream_silent_frame(int $index): string
{
    static $header = null;
    $header ??= "\xFF\xFB"
        . chr(array_search(DIRECT_STREAM_BITRATE_KBPS, [0, 32, 40, 48, 56, 64, 80, 96, 112, 128, 160, 192, 224, 256, 320], true) << 4
            | array_search(DIRECT_STREAM_SAMPLE_RATE, [44100, 48000, 32000], true) << 2)
        . "\x40";
    $bytes = 144 * DIRECT_STREAM_BITRATE_KBPS * 1000;
    $size = intdiv(($index + 1) * $bytes, DIRECT_STREAM_SAMPLE_RATE) - intdiv($index * $bytes, DIRECT_STREAM_SAMPLE_RATE);
    $padded = $size > intdiv($bytes, DIRECT_STREAM_SAMPLE_RATE);
    return $header[0] . $header[1] . chr(ord($header[2]) | ($padded ? 0x02 : 0)) . $header[3]
        . str_repeat("\0", $size - 4);
}

/**
 * Silence until a moment on the station's clock: the end of a slot whose
 * file finished early, where every player falls silent too.
 *
 * Sent as silence, not as nothing. Nothing drains a listener's buffer by the
 * length of the gap, and it never refills, because what follows arrives in
 * real time; a gap longer than an Icecast source timeout drops the relay
 * altogether. And PHP only learns a listener has gone when a write fails, so
 * one who left during a gap that wrote nothing kept a stream slot until the
 * next track.
 *
 * False once the listener has gone.
 */
function direct_stream_silence_until(int $timeMs, DirectStreamBody $body): bool
{
    $frameSeconds = 1152 / DIRECT_STREAM_SAMPLE_RATE;
    $frames = (int) round(max(0, $timeMs - now_ms()) / 1000 / $frameSeconds);
    if ($frames === 0) {
        // Less than half a frame: too short to hear, and to fill.
        usleep(max(0, $timeMs - now_ms()) * 1000);
        return !connection_aborted();
    }
    $start = microtime(true);
    for ($index = 0; $index < $frames; $index++) {
        $body->write(direct_stream_silent_frame($index));
        flush();
        if (connection_aborted()) return false;
        $due = $start + ($index + 1) * $frameSeconds;
        while (($wait = $due - microtime(true)) > 0) {
            usleep((int) min(100000, $wait * 1000000));
        }
    }
    return true;
}

function direct_stream_header_value(string $value): string
{
    // ICY metadata is sent in HTTP headers. Strip every ASCII control byte,
    // not only CR/LF, so station metadata can never create ambiguous headers.
    return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
}

function direct_stream_error(int $status, string $message, ?int $retryAfter = null): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if ($retryAfter !== null) header('Retry-After: ' . $retryAfter);
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $message . "\n";
}

/** The real /streams/<station>.mp3 response. */
function page_station_stream(string $stationId): void
{
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if ($method === 'OPTIONS') {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
        http_response_code(204);
        return;
    }
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD, OPTIONS');
        direct_stream_error(405, 'Only GET and HEAD are available at this stream address.');
        return;
    }

    $station = station($stationId);
    if ($station === null || (int) ($station['revision'] ?? 0) < 1) {
        direct_stream_error(404, 'No published station exists at this stream address.');
        return;
    }

    $tools = media_tools_status();
    $missing = continuous_stream_prerequisite($tools);
    if ($missing !== null) {
        $reason = match ($missing) {
            'process-blocked' => 'This host blocks PHP from starting FFmpeg.',
            'mp3-encoder-missing' => 'This FFmpeg build has no supported MP3 encoder.',
            default => 'FFmpeg is not available to 52Hertz on this server.',
        };
        direct_stream_error(503, $reason, 86400);
        return;
    }

    $feed = publication_feed($stationId);
    $publication = $feed === null ? null : compose_publication($feed);
    if ($publication === null) {
        direct_stream_error(404, 'This station has no broadcast snapshot.');
        return;
    }
    $revision = (int) ($feed['revision'] ?? 0);
    $resolver = new ServerPlayout($publication);
    $position = $resolver->resolve(now_ms());
    $path = ($position['state'] ?? '') === 'on-air'
        ? direct_stream_audio_path($stationId, (string) ($position['track']['mediaUrl'] ?? ''))
        : null;
    if ($path === null) {
        direct_stream_error(503, 'This station has nothing playable on air right now.', 60);
        return;
    }

    $slot = $method === 'GET' ? direct_stream_acquire_slot($stationId) : null;
    if ($method === 'GET' && $slot === null) {
        direct_stream_error(503, 'This stream address is already in use. It carries one Icecast server, not an audience; listeners belong on the station player.', 5);
        return;
    }
    if ($slot !== null) {
        register_shutdown_function(static fn () => direct_stream_release_slot($slot));
    }

    $identity = (array) ($publication['station'] ?? []);
    header('Content-Type: audio/mpeg');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Accept-Ranges: none');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');
    header('X-Accel-Buffering: no');
    header('X-Content-Type-Options: nosniff');
    header('icy-br: ' . DIRECT_STREAM_BITRATE_KBPS);
    header('icy-name: ' . direct_stream_header_value((string) ($identity['name'] ?? $stationId)));
    header('icy-description: ' . direct_stream_header_value((string) ($identity['tagline'] ?? '')));
    header('icy-url: ' . direct_stream_header_value((string) ($identity['homeUrl'] ?? '')));
    header('icy-pub: 0');
    // Only when asked: a client that did not request metadata would play the
    // blocks as if they were audio.
    $withMetadata = DirectStreamBody::wanted();
    if ($withMetadata) header('icy-metaint: ' . DIRECT_STREAM_METADATA_INTERVAL);
    if ($method === 'HEAD') return;

    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
    while (ob_get_level() > 0) @ob_end_clean();
    ignore_user_abort(true);
    set_time_limit(0);

    $encoder = (string) $tools['mp3Encoder'];
    $body = new DirectStreamBody($withMetadata);
    while (!connection_aborted()) {
        $now = now_ms();
        $position = $resolver->resolve($now);
        if (($position['state'] ?? '') !== 'on-air') break;
        $path = direct_stream_audio_path($stationId, (string) ($position['track']['mediaUrl'] ?? ''));
        if ($path === null) break;

        $offset = max(0, (int) $position['sourceOffsetMs']);
        $remaining = max(0, (int) $position['slotEndMs'] - $now);
        if ($remaining < 100) {
            if (!direct_stream_silence_until((int) $position['slotEndMs'], $body)) break;
        } else {
            $body->nowPlaying(direct_stream_now_playing($position));
            $command = direct_stream_command($path, $offset, $remaining, $encoder);
            if (!direct_stream_run_process($command, $body)) break;
            // Whatever the file did not fill. Filling it also keeps FFmpeg
            // from being started again and again inside a slot it has no
            // audio left for.
            if (!direct_stream_silence_until((int) $position['slotEndMs'], $body)) break;
        }
        if (connection_aborted()) break;

        // Saves are visible at the next track boundary. The feed's handover
        // chain guarantees this resolver reaches the same result as players.
        $nextFeed = publication_feed($stationId);
        $nextRevision = (int) ($nextFeed['revision'] ?? 0);
        if ($nextFeed !== null && $nextRevision !== $revision) {
            $nextPublication = compose_publication($nextFeed);
            if ($nextPublication !== null) {
                $revision = $nextRevision;
                $publication = $nextPublication;
                $resolver = new ServerPlayout($publication);
            }
        }
    }
}
