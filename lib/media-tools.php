<?php
/**
 * Optional server media tools.
 *
 * The radio itself has no command-line dependency. When FFmpeg is available,
 * later features may use it for short media jobs and stream output. Detection
 * is cached because starting a process on every panel request would be wasteful.
 */

declare(strict_types=1);

const MEDIA_TOOLS_CACHE_KEY = 'media_tools_status_v1';
const MEDIA_TOOLS_CACHE_SECONDS = 86400;
const MEDIA_TOOL_TIMEOUT_MS = 4000;

/**
 * Return the cached capability report, probing once a day or when requested.
 *
 * @return array{schema:int,state:string,checkedAt:int,version:string,ffprobe:bool,ffprobeVersion:string,mp3Encoder:string,detail:string}
 */
function media_tools_status(bool $refresh = false): array
{
    if (!$refresh) {
        $cached = json_decode((string) setting(MEDIA_TOOLS_CACHE_KEY, ''), true);
        if (is_array($cached)
            && (int) ($cached['schema'] ?? 0) === 1
            && (int) ($cached['checkedAt'] ?? 0) > time() - MEDIA_TOOLS_CACHE_SECONDS) {
            return media_tools_normalize($cached);
        }
    }

    $status = media_tools_probe();
    set_setting(MEDIA_TOOLS_CACHE_KEY, (string) json_encode($status, JSON_UNESCAPED_SLASHES));
    return $status;
}

/**
 * Probe fixed commands without invoking a shell. Command prefixes are exposed
 * as parameters only so the detector can be tested with a harmless fixture.
 *
 * @param list<string>|null $ffmpegCommand
 * @param list<string>|null $ffprobeCommand
 * @return array{schema:int,state:string,checkedAt:int,version:string,ffprobe:bool,ffprobeVersion:string,mp3Encoder:string,detail:string}
 */
function media_tools_probe(?array $ffmpegCommand = null, ?array $ffprobeCommand = null): array
{
    $report = media_tools_normalize(['checkedAt' => time()]);
    if (!media_processes_allowed()) {
        $report['state'] = 'blocked';
        $report['detail'] = 'process-functions-disabled';
        return $report;
    }

    $ffmpegCommand ??= ['ffmpeg'];
    $ffprobeCommand ??= ['ffprobe'];
    $deadline = microtime(true) + MEDIA_TOOL_TIMEOUT_MS / 1000;
    $versionRun = media_tool_run([...$ffmpegCommand, '-hide_banner', '-version'], media_tools_time_left($deadline));
    if (!$versionRun['launched']) {
        $report['state'] = 'missing';
        $report['detail'] = 'not-found';
        return $report;
    }
    if ($versionRun['timedOut']) {
        $report['state'] = 'error';
        $report['detail'] = 'timed-out';
        return $report;
    }
    if ($versionRun['exitCode'] !== 0) {
        $report['state'] = 'error';
        $report['detail'] = 'version-failed';
        return $report;
    }

    $versionOutput = $versionRun['stdout'] . "\n" . $versionRun['stderr'];
    if (!preg_match('/^ffmpeg version\s+([^\s]+)/mi', $versionOutput, $match)) {
        $report['state'] = 'error';
        $report['detail'] = 'unexpected-version';
        return $report;
    }
    $report['state'] = 'available';
    $report['version'] = substr(preg_replace('/[^\x20-\x7E]/', '', $match[1]) ?? '', 0, 100);

    if (($remaining = media_tools_time_left($deadline)) > 100) {
        $encoders = media_tool_run([...$ffmpegCommand, '-hide_banner', '-encoders'], $remaining);
        if ($encoders['launched'] && !$encoders['timedOut'] && $encoders['exitCode'] === 0) {
            $encoderOutput = $encoders['stdout'] . "\n" . $encoders['stderr'];
            if (preg_match('/^\s*A\S*\s+(libmp3lame|libshine|mp3_mf)\b/mi', $encoderOutput, $match)) {
                $report['mp3Encoder'] = strtolower($match[1]);
            }
        }
    }

    if (($remaining = media_tools_time_left($deadline)) > 100) {
        $probe = media_tool_run([...$ffprobeCommand, '-hide_banner', '-version'], $remaining);
        if ($probe['launched'] && !$probe['timedOut'] && $probe['exitCode'] === 0) {
            $probeOutput = $probe['stdout'] . "\n" . $probe['stderr'];
            if (preg_match('/^ffprobe version\s+([^\s]+)/mi', $probeOutput, $match)) {
                $report['ffprobe'] = true;
                $report['ffprobeVersion'] = substr(preg_replace('/[^\x20-\x7E]/', '', $match[1]) ?? '', 0, 100);
            }
        }
    }

    return $report;
}

function media_tools_time_left(float $deadline): int
{
    return max(100, (int) floor(($deadline - microtime(true)) * 1000));
}

/** @return array{schema:int,state:string,checkedAt:int,version:string,ffprobe:bool,ffprobeVersion:string,mp3Encoder:string,detail:string} */
function media_tools_normalize(array $status): array
{
    return [
        'schema'         => 1,
        'state'          => in_array(($status['state'] ?? ''), ['available', 'missing', 'blocked', 'error'], true)
            ? (string) $status['state'] : 'missing',
        'checkedAt'      => (int) ($status['checkedAt'] ?? time()),
        'version'        => (string) ($status['version'] ?? ''),
        'ffprobe'        => (bool) ($status['ffprobe'] ?? false),
        'ffprobeVersion' => (string) ($status['ffprobeVersion'] ?? ''),
        'mp3Encoder'     => (string) ($status['mp3Encoder'] ?? ''),
        'detail'         => (string) ($status['detail'] ?? ''),
    ];
}

function media_processes_allowed(): bool
{
    foreach (['proc_open', 'proc_get_status', 'proc_terminate', 'proc_close'] as $function) {
        if (!function_exists($function)) {
            return false;
        }
    }
    return true;
}

/**
 * @param list<string> $command
 * @return array{launched:bool,timedOut:bool,exitCode:int,stdout:string,stderr:string}
 */
function media_tool_run(array $command, int $timeoutMs = MEDIA_TOOL_TIMEOUT_MS): array
{
    $empty = ['launched' => false, 'timedOut' => false, 'exitCode' => -1, 'stdout' => '', 'stderr' => ''];
    if (!$command || !media_processes_allowed()) {
        return $empty;
    }

    $pipes = [];
    $process = @proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return $empty;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $exitCode = -1;
    $timedOut = false;
    $deadline = microtime(true) + max(100, $timeoutMs) / 1000;

    while (true) {
        media_tool_collect($pipes[1], $stdout);
        media_tool_collect($pipes[2], $stderr);
        $processStatus = proc_get_status($process);
        if (!$processStatus['running']) {
            $exitCode = (int) $processStatus['exitcode'];
            break;
        }
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            $pid = (int) ($processStatus['pid'] ?? 0);
            proc_terminate($process);
            // TerminateProcess is asynchronous on Windows; give it a short
            // moment before falling back to killing the process tree.
            usleep(150000);
            $after = proc_get_status($process);
            if ($after['running']) {
                if (PHP_OS_FAMILY === 'Windows' && $pid > 0) {
                    media_tool_kill_windows($pid);
                } else {
                    proc_terminate($process, 9);
                }
                usleep(30000);
                $after = proc_get_status($process);
            }
            if (!$after['running']) {
                $exitCode = (int) $after['exitcode'];
            } else {
                // Do not let proc_close turn a four-second probe into an
                // unbounded request when a host refuses termination.
                $exitCode = -1;
            }
            break;
        }
        usleep(10000);
    }

    media_tool_collect($pipes[1], $stdout);
    media_tool_collect($pipes[2], $stderr);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $finalStatus = proc_get_status($process);
    $closed = $finalStatus['running'] ? -1 : proc_close($process);
    if ($exitCode < 0 && $closed >= 0) {
        $exitCode = $closed;
    }

    return [
        'launched' => true,
        'timedOut' => $timedOut,
        'exitCode' => $exitCode,
        'stdout'   => $stdout,
        'stderr'   => $stderr,
    ];
}

/** Windows' proc_terminate may not stop a console child; taskkill does. */
function media_tool_kill_windows(int $pid): void
{
    $windows = rtrim((string) (getenv('SystemRoot') ?: 'C:\\Windows'), '\\/');
    $taskkill = $windows . '/System32/taskkill.exe';
    if (!is_file($taskkill)) {
        return;
    }
    $pipes = [];
    $killer = @proc_open([$taskkill, '/PID', (string) $pid, '/T', '/F'], [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ], $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($killer)) {
        return;
    }
    fclose($pipes[0]);
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($killer);
}

/** Drain a pipe while retaining at most 256 KiB of diagnostic output. */
function media_tool_collect($pipe, string &$output): void
{
    $chunk = stream_get_contents($pipe);
    if ($chunk === false || $chunk === '') {
        return;
    }
    if (strlen($output) < 262144) {
        $output .= substr($chunk, 0, 262144 - strlen($output));
    }
}

/** A concise warning for the installer, settings page and upload dialog. */
function media_tools_warning(array $status): ?string
{
    if ($status['state'] === 'available' && $status['mp3Encoder'] !== '' && $status['ffprobe']) {
        return null;
    }
    if ($status['state'] === 'blocked') {
        return t('This host blocks PHP from starting media tools. 52Hertz still works, but automatic metadata cleanup, format conversion and stream generation need FFmpeg.');
    }
    if ($status['state'] === 'available') {
        if ($status['mp3Encoder'] !== '') {
            return t('FFmpeg and its MP3 encoder are available, but FFprobe was not found. 52Hertz still works, but inspecting uploaded media needs FFprobe.');
        }
        return t('FFmpeg is available, but no MP3 encoder was found. 52Hertz still works, but MP3 conversion and stream generation need an FFmpeg build with an MP3 encoder.');
    }
    return t('FFmpeg is not available on this server. 52Hertz still works, but automatic metadata cleanup, format conversion and stream generation need FFmpeg.');
}

/**
 * The first missing prerequisite for producing an MP3 source stream.
 * A null result means the direct MP3 origin can run. Icecast is optional: it
 * may relay that origin when one PHP/FFmpeg process per listener is too much.
 */
function continuous_stream_prerequisite(array $status): ?string
{
    $status = media_tools_normalize($status);
    if ($status['state'] === 'blocked') {
        return 'process-blocked';
    }
    if ($status['state'] !== 'available') {
        return 'ffmpeg-missing';
    }
    if ($status['mp3Encoder'] === '') {
        return 'mp3-encoder-missing';
    }
    return null;
}
