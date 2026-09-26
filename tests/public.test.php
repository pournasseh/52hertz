<?php
/** Public method handling that must work before SQLite is available. */
declare(strict_types=1);

require_once __DIR__ . '/../lib/public.php';
require_once __DIR__ . '/../lib/stream.php';

$failures = 0;
$results = [];
function check_public(bool $condition, string $message): void
{
    global $failures, $results;
    $results[] = [$condition, $message];
    if (!$condition) $failures++;
}

$_GET = ['p' => 'time'];
$_SERVER['REQUEST_METHOD'] = 'POST';
http_response_code(200);
ob_start();
$handled = dispatch_public();
$body = ob_get_clean();
check_public($handled, 'clock route is handled');
check_public(http_response_code() === 405, 'clock rejects write verbs');
check_public($body === '', 'rejected clock request has no response body');

// This proves preflight happens before publisher.php/migrate(): the local
// qualification environment intentionally has no pdo_sqlite extension.
$_GET = ['p' => 'station-feed', 'id' => 'anything'];
$_SERVER['REQUEST_METHOD'] = 'OPTIONS';
http_response_code(200);
ob_start();
$handled = dispatch_public();
$body = ob_get_clean();
check_public($handled, 'station preflight is handled');
check_public(http_response_code() === 204, 'station preflight returns 204');
check_public($body === '', 'station preflight has no response body');

$_GET = ['p' => 'time'];
$_SERVER['REQUEST_METHOD'] = 'GET';
http_response_code(200);
ob_start();
$handled = dispatch_public();
$body = ob_get_clean();
$clock = json_decode($body, true);
check_public($handled, 'clock GET is handled');
check_public(http_response_code() === 200, 'clock GET returns 200');
check_public(is_array($clock) && is_int($clock['nowMs'] ?? null) && is_string($clock['iso'] ?? null), 'clock GET returns its public shape');

check_public(
    direct_stream_header_value("  Radio\r\n\tName\0\x7f  ") === 'Radio Name',
    'stream metadata strips HTTP control bytes'
);

foreach ($results as [$ok, $message]) {
    fwrite($ok ? STDOUT : STDERR, ($ok ? 'ok - ' : 'not ok - ') . $message . "\n");
}
exit($failures === 0 ? 0 : 1);
