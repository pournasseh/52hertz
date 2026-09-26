<?php
/** Read-only HTTP checks for a deployed 52Hertz release. */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

$base = rtrim((string) ($argv[1] ?? ''), '/');
$station = (string) ($argv[2] ?? '');
if (!filter_var($base, FILTER_VALIDATE_URL)) {
    exit("Usage: php tools/check-production.php https://radio.example/52hertz [station-id]\n");
}
if ($station !== '' && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $station)) {
    exit("The station id contains characters that cannot appear in a station URL.\n");
}

$failures = 0;
$warnings = 0;

/** @return array{status:int,headers:array<string,string>,body:string} */
function http_request(string $url, string $method = 'GET', array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method'          => $method,
        'header'          => implode("\r\n", $headers),
        'ignore_errors'   => true,
        'follow_location' => 0,
        'timeout'         => 12,
    ]]);
    $body = @file_get_contents($url, false, $context);
    $lines = $http_response_header ?? [];
    $status = 0;
    $parsed = [];
    foreach ($lines as $line) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match)) {
            $status = (int) $match[1];
            continue;
        }
        if (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $parsed[strtolower(trim($name))] = trim($value);
        }
    }
    return ['status' => $status, 'headers' => $parsed, 'body' => $body === false ? '' : $body];
}

function result(string $label, bool $ok, string $detail): void
{
    global $failures;
    echo $ok ? "PASS  " : "FAIL  ", $label, $detail === '' ? '' : " — $detail", "\n";
    if (!$ok) $failures++;
}

function warning(string $label, string $detail): void
{
    global $warnings;
    echo "WARN  $label — $detail\n";
    $warnings++;
}

/** Resolve a relative station media URL without a browser. */
function absolute_url(string $relative, string $base): string
{
    if (preg_match('#^https?://#i', $relative)) return $relative;
    $parts = parse_url($base);
    $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    $path = str_starts_with($relative, '/') ? $relative : dirname((string) ($parts['path'] ?? '/')) . '/' . $relative;
    $clean = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') array_pop($clean); else $clean[] = $part;
    }
    return $origin . '/' . implode('/', $clean);
}

$host = strtolower((string) parse_url($base, PHP_URL_HOST));
if (parse_url($base, PHP_URL_SCHEME) !== 'https' && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    warning('HTTPS', 'the deployed address is not HTTPS');
} else {
    result('HTTPS', true, 'secure address or local development host');
}

$clock = http_request($base . '/index.php?p=time');
$clockJson = json_decode($clock['body'], true);
result('Public clock', $clock['status'] === 200 && is_int($clockJson['nowMs'] ?? null), 'HTTP ' . $clock['status']);
result('Clock is not cached', str_contains(strtolower($clock['headers']['cache-control'] ?? ''), 'no-store'), $clock['headers']['cache-control'] ?? 'missing header');
result('Clock CORS', ($clock['headers']['access-control-allow-origin'] ?? '') === '*', $clock['headers']['access-control-allow-origin'] ?? 'missing header');

foreach (['var/panel.sqlite', 'lib/bootstrap.php', 'tools/publish.php', 'tests/schedule.test.mjs'] as $private) {
    $response = http_request($base . '/' . $private, 'HEAD');
    result("Private /$private", in_array($response['status'], [403, 404], true), 'HTTP ' . $response['status']);
}

// languages/ is the one directory this product invites strangers' files into,
// so the rule that nothing but .json is served from it is worth proving on
// the real host rather than trusting an .htaccess that a host may ignore.
$served = http_request($base . '/languages/en.json', 'HEAD');
result('Language file is readable', $served['status'] === 200, 'HTTP ' . $served['status']);

// README.md is shipped in that directory, so this asks about a file that is
// certainly there: a 404 would prove nothing on a host with no rule at all.
$refused = http_request($base . '/languages/README.md', 'HEAD');
result(
    'Only JSON is served from /languages',
    $refused['status'] === 403,
    $refused['status'] === 200
        ? 'HTTP 200 — this host ignores .htaccess, so a .php file dropped into languages/ could run'
        : 'HTTP ' . $refused['status']
);

if ($station === '') {
    warning('Station checks', 'pass a station id to test the player, the live feed, PWA and byte ranges');
} else {
    // Rewriting is optional: every clean address has a plain form, and the
    // player builds whichever form brought it. So the rest is checked in the
    // form this host can actually serve, the way the panel's links will be.
    $id = rawurlencode($station);
    $probe = http_request($base . "/stations/$id/feed.json", 'HEAD');
    $clean = str_contains(strtolower($probe['headers']['content-type'] ?? ''), 'json');
    if ($clean) {
        result('Clean addresses', true, 'rewriting works');
    } else {
        warning('Clean addresses', "this host does not rewrite addresses; links will be player/?station=$station");
    }
    $at = static fn (string $cleanPath, string $plainPath): string => $base . '/' . ($clean ? $cleanPath : $plainPath);

    $player = http_request($at("player/$id", "player/?station=$id"));
    result('Player page', $player['status'] === 200, 'HTTP ' . $player['status']);

    $feedUrl = $at("stations/$id/feed.json", "index.php?p=station-feed&id=$id");
    $feedResponse = http_request($feedUrl);
    $feed = json_decode($feedResponse['body'], true);
    $snapshots = is_array($feed['snapshots'] ?? null) ? $feed['snapshots'] : [];
    $latest = $snapshots ? end($snapshots) : null;
    $publication = is_array($latest) && is_array($latest['publication'] ?? null) ? $latest['publication'] : null;
    result('Dynamic station feed', $feedResponse['status'] === 200 && ($feed['feedVersion'] ?? null) === 1 && is_array($publication), 'HTTP ' . $feedResponse['status']);
    result('Feed CORS', ($feedResponse['headers']['access-control-allow-origin'] ?? '') === '*', $feedResponse['headers']['access-control-allow-origin'] ?? 'missing header');
    result('Feed revalidates', str_contains(strtolower($feedResponse['headers']['cache-control'] ?? ''), 'must-revalidate'), $feedResponse['headers']['cache-control'] ?? 'missing header');
    $etag = (string) ($feedResponse['headers']['etag'] ?? '');
    $notModified = $etag !== '' ? http_request($feedUrl, 'GET', ['If-None-Match: ' . $etag]) : ['status' => 0];
    result('Feed conditional request', $notModified['status'] === 304, 'HTTP ' . $notModified['status']);

    $manifest = http_request($at("stations/$id/manifest.webmanifest", "index.php?p=station-manifest&id=$id"));
    $manifestJson = json_decode($manifest['body'], true);
    result('Station PWA manifest', $manifest['status'] === 200 && is_array($manifestJson) && ($manifestJson['start_url'] ?? '') !== '', 'HTTP ' . $manifest['status']);
    $icon = http_request($at("stations/$id/icon-192.png", "index.php?p=station-icon&id=$id&size=192"));
    result('Station PWA icon', $icon['status'] === 200 && str_starts_with($icon['body'], "\x89PNG\r\n\x1a\n"), 'HTTP ' . $icon['status']);

    $firstTrack = is_array($publication['tracks'] ?? null) ? reset($publication['tracks']) : null;
    $mediaUrl = is_array($firstTrack) ? (string) ($firstTrack['mediaUrl'] ?? '') : '';
    if ($mediaUrl === '') {
        warning('Audio byte range', 'the selected station has no published track');
    } else {
        $audio = http_request(absolute_url($mediaUrl, $base . '/index.php'), 'GET', ['Range: bytes=0-0']);
        result('Audio byte range', $audio['status'] === 206, 'HTTP ' . $audio['status']);
    }

    $stream = http_request($at("streams/$id.mp3", "index.php?p=stream&id=$id"), 'HEAD');
    if ($stream['status'] === 503) {
        warning('Direct MP3 stream', 'inactive on this host; check FFmpeg, its MP3 encoder and the current schedule');
    } else {
        result(
            'Direct MP3 stream',
            $stream['status'] === 200
                && str_starts_with(strtolower($stream['headers']['content-type'] ?? ''), 'audio/mpeg')
                && ($stream['headers']['icy-br'] ?? '') === '128',
            'HTTP ' . $stream['status']
        );
    }
}

echo "\n", $failures === 0 ? 'Production HTTP checks passed.' : "$failures production HTTP check(s) failed.", "\n";
if ($warnings) echo "$warnings warning(s) need a manual decision.\n";
exit($failures === 0 ? 0 : 1);
