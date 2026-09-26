<?php
declare(strict_types=1);

if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "test prerequisite missing: PHP mbstring\n");
    exit(2);
}

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/media-tools.php';
require_once __DIR__ . '/../lib/stream.php';

$passed = 0;
$total = 0;
$check = static function (bool $condition, string $message) use (&$passed, &$total): void {
    $total++;
    if (!$condition) {
        fwrite(STDERR, "not ok $total - $message\n");
        exit(1);
    }
    $passed++;
};

$fixture = __DIR__ . '/fixtures/media-tool.php';
$status = media_tools_probe(
    [PHP_BINARY, $fixture, 'ffmpeg'],
    [PHP_BINARY, $fixture, 'ffprobe']
);
$check($status['state'] === 'available', 'recognises FFmpeg');
$check($status['version'] === '7.1-test', 'reads the FFmpeg version');
$check($status['mp3Encoder'] === 'libmp3lame', 'finds the MP3 encoder');
$check($status['ffprobe'] === true, 'recognises FFprobe');
$check($status['ffprobeVersion'] === '7.1-test', 'reads the FFprobe version');

$missing = media_tools_probe(
    [__DIR__ . '/definitely-not-an-ffmpeg-command'],
    [__DIR__ . '/definitely-not-an-ffprobe-command']
);
$check($missing['state'] === 'missing', 'a missing executable is not an application error');

$slow = media_tool_run([PHP_BINARY, $fixture, 'ffmpeg', '--slow'], 100);
$check($slow['timedOut'], 'a stalled executable reaches the execution deadline');

$check(continuous_stream_prerequisite($status) === null, 'FFmpeg with MP3 encoder satisfies the codec prerequisite');
$check(continuous_stream_prerequisite($missing) === 'ffmpeg-missing', 'missing FFmpeg explains an inactive stream');
$check(continuous_stream_prerequisite(['state' => 'blocked']) === 'process-blocked', 'blocked processes are distinguished');
$check(continuous_stream_prerequisite(['state' => 'available']) === 'mp3-encoder-missing', 'missing MP3 encoder is distinguished');

$command = direct_stream_command(
    'C:/radio/input.wav', 1250, 9750, 'libmp3lame', [PHP_BINARY, $fixture, 'stream']
);
$check(!in_array('-re', $command, true), 'PHP owns real-time pacing instead of accepting FFmpeg startup bursts');
$check($command[array_search('-ss', $command, true) + 1] === '1.250', 'stream seek is expressed exactly');
$check($command[array_search('-t', $command, true) + 1] === '9.750', 'stream slot length is bounded');
$check($command[array_search('-c:a', $command, true) + 1] === 'libmp3lame', 'detected encoder is selected without a shell');

ob_start();
$ran = direct_stream_run_process($command);
$bytes = (string) ob_get_clean();
$check($ran && str_starts_with($bytes, 'stream-bytes-'), 'stream child bytes reach the HTTP output loop');

// ICY metadata: what makes a directory listing say a track name rather than
// only a station name. A block must never reach a client that did not ask,
// and must never land anywhere but on an exact interval boundary, or the
// audio around it is corrupted.
$withMeta = static function (string $title, array $writes): string {
    $body = new DirectStreamBody(true);
    $body->nowPlaying($title);
    ob_start();
    foreach ($writes as $chunk) $body->write($chunk);
    return (string) ob_get_clean();
};

$plain = new DirectStreamBody(false);
ob_start();
$plain->nowPlaying('Somebody - Something');
$plain->write(str_repeat('a', DIRECT_STREAM_METADATA_INTERVAL * 2));
$untouched = (string) ob_get_clean();
$check(strlen($untouched) === DIRECT_STREAM_METADATA_INTERVAL * 2, 'a client that did not ask gets its audio byte for byte');
$check(!str_contains($untouched, 'StreamTitle'), 'no metadata leaks into a plain stream');

// One interval of audio, then the block, then the rest.
$out = $withMeta('Nobody - Nothing', [str_repeat('a', DIRECT_STREAM_METADATA_INTERVAL + 10)]);
$audioBefore = substr($out, 0, DIRECT_STREAM_METADATA_INTERVAL);
$blocks = ord($out[DIRECT_STREAM_METADATA_INTERVAL]);
$meta = substr($out, DIRECT_STREAM_METADATA_INTERVAL + 1, $blocks * 16);
$check($audioBefore === str_repeat('a', DIRECT_STREAM_METADATA_INTERVAL), 'audio runs untouched up to the interval');
$check($blocks > 0 && strlen($meta) === $blocks * 16, 'the block declares its own length in sixteens');
$check(str_contains($meta, "StreamTitle='Nobody - Nothing';"), 'the block names what is playing');
$check(substr($out, DIRECT_STREAM_METADATA_INTERVAL + 1 + $blocks * 16) === str_repeat('a', 10), 'audio resumes immediately after the block');

// Chunks arrive from FFmpeg at arbitrary sizes; the boundary is a property of
// the stream, not of the chunk it happens to fall inside.
$split = $withMeta('Split - Across', array_fill(0, DIRECT_STREAM_METADATA_INTERVAL / 100 + 1, str_repeat('b', 100)));
$check(substr($split, 0, DIRECT_STREAM_METADATA_INTERVAL) === str_repeat('b', DIRECT_STREAM_METADATA_INTERVAL), 'the interval is counted across chunk boundaries');
$check(str_contains($split, "StreamTitle='Split - Across';"), 'a title split across chunks still arrives');

// An unchanged title costs one zero byte, not a repeated block.
$repeat = new DirectStreamBody(true);
$repeat->nowPlaying('Same - Track');
ob_start();
$repeat->write(str_repeat('c', DIRECT_STREAM_METADATA_INTERVAL * 2 + 1));
$twice = (string) ob_get_clean();
$check(substr_count($twice, 'StreamTitle') === 1, 'an unchanged title is announced once, not at every interval');
$check(ord($twice[DIRECT_STREAM_METADATA_INTERVAL * 2 + 1 + ord($twice[DIRECT_STREAM_METADATA_INTERVAL]) * 16]) === 0, 'the second interval says "no change" in one byte');

// A title carrying the delimiters must not be able to break the field.
$nasty = $withMeta("Ev'il;\r\nName", [str_repeat('d', DIRECT_STREAM_METADATA_INTERVAL)]);
$check(preg_match("/StreamTitle='([^']*)';/", $nasty, $m) === 1, 'quotes and semicolons in a title cannot break the field');
$check(!str_contains($m[1], "'") && !str_contains($m[1], ';') && !str_contains($m[1], "\n"), 'the delimiters are removed from the title itself');

$check(direct_stream_now_playing(['track' => ['title' => 'Song', 'credit' => 'Artist']]) === 'Artist - Song', 'a credited track reads "Artist - Song"');
$check(direct_stream_now_playing(['track' => ['title' => 'Song']]) === 'Song', 'an uncredited track is just its title');
$check(direct_stream_now_playing(['track' => []]) === '', 'a track with no title announces nothing');

// A file that ends before its slot does leaves silence, and silence is sent,
// not nothing: real frames at the stream's own rate, so a listener's buffer
// does not drain through the gap and one who left is noticed at the next
// frame instead of holding a slot until the next track.
$frame = direct_stream_silent_frame(0);
$check(substr($frame, 0, 2) === "\xFF\xFB", 'a silent frame opens with MPEG-1 Layer III sync and no CRC');
$check(ord($frame[2]) >> 4 === 9 && (ord($frame[2]) >> 2 & 3) === 0, 'a silent frame says 128 kbps at 44.1 kHz, as the stream does');
$check(trim(substr($frame, 4), "\0") === '', 'a silent frame carries no audio data at all');
$run = '';
$framesOk = true;
for ($i = 0; $i < 441; $i++) {
    $one = direct_stream_silent_frame($i);
    $framesOk = $framesOk && in_array(strlen($one), [417, 418], true)
        && (strlen($one) === 418) === (bool) (ord($one[2]) & 0x02);
    $run .= $one;
}
$check($framesOk, 'a silent frame is 417 bytes, or 418 with its padding bit set');
$check(strlen($run) === 184320, '441 silent frames are exactly 11.52 seconds at 128 kbps');

$quiet = new DirectStreamBody(false);
ob_start();
$began = microtime(true);
$kept = direct_stream_silence_until(now_ms() + 200, $quiet);
$took = microtime(true) - $began;
$filled = (string) ob_get_clean();
$check($kept && $filled !== '' && str_starts_with($filled, "\xFF\xFB"), 'a gap is filled with frames');
$check(strlen($filled) >= 7 * 417 && strlen($filled) <= 9 * 418, 'a gap of 200 ms is filled with about 200 ms of frames');
$check($took >= 0.15, 'silence is paced in real time, not sent in a burst');

// With a real FFmpeg on this machine, the frames are also played.
if ((media_tools_probe(['ffmpeg'], ['ffprobe'])['state'] ?? '') === 'available') {
    $file = tempnam(sys_get_temp_dir(), 'silence');
    file_put_contents($file, $run);
    $decoded = media_tool_run(['ffmpeg', '-hide_banner', '-f', 'mp3', '-i', $file, '-af', 'volumedetect', '-f', 'null', '-'], 20000);
    unlink($file);
    $check($decoded['exitCode'] === 0 && !preg_match('/error|invalid/i', $decoded['stderr']), 'FFmpeg decodes silent frames without a complaint');
    $check(preg_match('/max_volume: (-inf|-9\d(\.\d+)?) dB/', $decoded['stderr']) === 1, 'FFmpeg hears silent frames as silence');
}

$stationDir = PANEL_ROOT . '/media/__stream-test';
if (!is_dir($stationDir)) mkdir($stationDir, 0775, true);
$audioPath = $stationDir . '/safe.wav';
file_put_contents($audioPath, 'test');
$check(direct_stream_audio_path('__stream-test', 'media/__stream-test/safe.wav') === $audioPath, 'station audio resolves inside its media folder');
$check(direct_stream_audio_path('__stream-test', 'media/__stream-test/../demo/tone-a.wav') === null, 'station audio cannot traverse out of its folder');
unlink($audioPath);
rmdir($stationDir);

$slots = [];
for ($i = 0; $i < DIRECT_STREAM_MAX_CONNECTIONS; $i++) {
    $slots[] = direct_stream_acquire_slot('__stream-test');
}
$check(!in_array(null, $slots, true), 'the bounded direct-stream slots can be acquired');
$check(direct_stream_acquire_slot('__stream-test') === null, 'an extra direct stream is refused before it can fork FFmpeg');

// Releasing must not delete the lock file. A neighbouring connection can
// already hold a lock on it, and deleting the name lets the next arrival
// create a fresh file at that name and lock it too - two listeners in one
// slot, and the limit no longer a limit. See direct_stream_release_slot().
$releasedPath = $slots[0]['path'];
direct_stream_release_slot($slots[0]);
$check(is_file($releasedPath), 'releasing a slot leaves its lock file for the next connection');
$slots[0] = direct_stream_acquire_slot('__stream-test');
$check($slots[0] !== null && $slots[0]['path'] === $releasedPath, 'a released slot is handed to the next connection');
$check(direct_stream_acquire_slot('__stream-test') === null, 'the limit still holds after a slot has been recycled');

foreach ($slots as $slot) direct_stream_release_slot($slot);
foreach (glob(PANEL_VAR . '/stream-__stream-test-*.lock') ?: [] as $leftover) unlink($leftover);

echo "\n$passed/$total passed\n";
