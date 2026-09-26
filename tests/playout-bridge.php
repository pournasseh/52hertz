<?php
/** Test-only JSON bridge for comparing PHP playout with the browser engine. */

declare(strict_types=1);

require_once __DIR__ . '/../lib/playout.php';

$input = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$resolver = new ServerPlayout((array) ($input['publication'] ?? []));
$result = [];
foreach ((array) ($input['times'] ?? []) as $time) {
    $position = $resolver->resolve((int) $time);
    $result[] = [
        'state' => $position['state'] ?? null,
        'reason' => $position['reason'] ?? null,
        'trackId' => $position['track']['id'] ?? null,
        'sourceOffsetMs' => $position['sourceOffsetMs'] ?? null,
        'slotStartMs' => $position['slotStartMs'] ?? null,
        'slotEndMs' => $position['slotEndMs'] ?? null,
        'nextBoundaryMs' => $position['nextBoundaryMs'] ?? null,
        'programmeId' => $position['programmeId'] ?? null,
        'day' => $position['day'] ?? null,
        'pass' => $position['pass'] ?? null,
        'passIndex' => $position['passIndex'] ?? null,
        'eventId' => $position['event']['id'] ?? null,
        'cut' => $position['cut'] ?? null,
    ];
}
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

