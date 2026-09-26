<?php
/**
 * Publish a station from the command line.
 *
 *   php tools/publish.php demo
 *
 * The same code path the panel's Publish button uses. Useful for a scripted
 * update, and for the pipeline test.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../lib/publisher.php';

$stationId = (string) ($argv[1] ?? '');
if ($stationId === '') {
    exit("Usage: php tools/publish.php <station-id>\n");
}

try {
    $result = publish_station($stationId);
} catch (Throwable $err) {
    fwrite(STDERR, "Not published:\n" . $err->getMessage() . "\n");
    exit(1);
}

foreach ($result['warnings'] as $warning) {
    fwrite(STDERR, "note: $warning\n");
}
echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
