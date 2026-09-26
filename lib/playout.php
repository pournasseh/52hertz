<?php
/**
 * Server-side mirror of engine/schedule.js + engine/programme.js.
 *
 * The browser remains the canonical implementation. The direct MP3 origin
 * needs the same answer without requiring Node at runtime, so this class is
 * kept deliberately mechanical and is parity-tested against the JS engine.
 */

declare(strict_types=1);

final class ServerPlayout
{
    private const DAY_MS = 86400000;

    private array $publication;
    private array $prepared = [];
    private array $orders = [];
    private array $runs = [];
    private ?array $activation = null;
    private ?self $previous = null;

    public function __construct(array $publication)
    {
        $this->publication = $publication;
    }

    /** @return array<string,mixed> */
    public function resolve(int $timeMs): array
    {
        $pub = $this->publication;
        if (($pub['enabled'] ?? true) === false) {
            return $this->off('disabled');
        }

        $activationAt = $this->activationAt();
        if ($activationAt !== null && $timeMs < $activationAt) {
            return $this->previous()->resolve($timeMs);
        }

        if (!$this->runs) {
            $continuation = null;
            if ($activationAt !== null) {
                $old = $this->activation['old'];
                $start = $activationAt;
                $nominal = $this->runAt($activationAt);
                if (($old['state'] ?? '') === 'on-air'
                    && ($old['programmeId'] ?? null) === $nominal['programmeId']
                    && $activationAt < (int) ($old['runEndMs'] ?? 0)) {
                    $consumed = array_fill_keys((array) ($old['completedKeys'] ?? []), true);
                    $order = $this->orderFor(
                        (string) $nominal['programmeId'],
                        (int) $nominal['day'],
                        (int) ($old['pass'] ?? 0)
                    )['entries'];
                    $entries = array_values(array_filter(
                        $order,
                        static fn (array $entry): bool => !isset($consumed[(string) $entry['key']])
                    ));
                    $continuation = [
                        'entries' => $entries,
                        'completed' => array_keys($consumed),
                        'pass' => (int) ($old['pass'] ?? 0),
                    ];
                }
            } else {
                $start = (int) ($pub['epochMs'] ?? 0);
                $atTime = $this->runAt($timeMs);
                if ($start === 0 || $timeMs < $start) {
                    $start = (int) $atTime['startMs'];
                }
                $nominal = $this->runAt($start);
            }
            $this->runs[] = $this->compile($nominal, $start, $continuation);
        }

        $last = $this->runs[array_key_last($this->runs)];
        while ($timeMs >= (int) $last['endMs']) {
            $nominal = $this->runAt((int) $last['endMs']);
            $next = $this->compile($nominal, (int) $last['endMs']);
            if ((int) $next['endMs'] <= (int) $last['endMs']) {
                return $this->off('nothing-scheduled');
            }
            $this->runs[] = $next;
            $last = $next;
        }

        $low = 0;
        $high = count($this->runs) - 1;
        while ($low < $high) {
            $middle = ($low + $high + 1) >> 1;
            if ((int) $this->runs[$middle]['startMs'] <= $timeMs) $low = $middle;
            else $high = $middle - 1;
        }
        $run = $this->runs[$low];
        if ($timeMs < (int) $run['startMs']) {
            $nominal = $this->runAt($timeMs);
            $run = $this->compile($nominal, (int) $nominal['startMs']);
        }

        $programmeId = $run['programmeId'];
        $programme = $programmeId === null
            ? null : ($pub['programmes'][(string) $programmeId] ?? null);
        if (!is_array($programme)) {
            return $this->off('nothing-scheduled', (int) $run['endMs']);
        }

        $slot = $this->locate($run, $timeMs);
        if ($slot === null) {
            $next = null;
            foreach (($run['slots'] ?? []) as $candidate) {
                if ((int) $candidate['start'] > $timeMs) {
                    $next = (int) $candidate['start'];
                    break;
                }
            }
            return $this->off('empty-programme', $next ?? (int) $run['endMs']);
        }

        $end = min((int) $slot['end'], (int) $run['endMs']);
        $passOrder = $this->orderFor((string) $programmeId, (int) $run['day'], (int) $slot['pass']);
        $prefix = $passOrder['prefixMs'];
        $passLength = (int) ($prefix[array_key_last($prefix)] ?? 0);

        return [
            'state' => 'on-air',
            'reason' => null,
            'track' => $slot['entry']['track'],
            'entryKey' => $slot['entry']['key'],
            'sourceOffsetMs' => $timeMs - (int) $slot['start'],
            'slotStartMs' => (int) $slot['start'],
            'slotEndMs' => $end,
            'nextBoundaryMs' => $end,
            'programmeId' => $programmeId,
            'programme' => [
                'id' => $programmeId,
                'name' => (string) ($programme['name'] ?? ''),
                'mode' => (string) ($programme['mode'] ?? 'shuffle'),
            ],
            'day' => (int) $run['day'],
            'override' => (bool) $run['override'],
            'playlistId' => $run['playlistId'],
            'pass' => (int) $slot['pass'],
            'passIndex' => (int) $slot['index'],
            'programmeLengthMs' => $passLength,
            'runStartMs' => (int) $run['startMs'],
            'runEndMs' => (int) $run['endMs'],
            'completedKeys' => $slot['completedKeys'],
            'event' => $slot['event'],
            'cut' => $end < (int) $slot['start'] + (int) $slot['entry']['durationMs'],
        ];
    }

    private function previous(): self
    {
        if ($this->previous === null) {
            $previous = $this->publication['handover']['previous'] ?? [];
            $this->previous = new self(is_array($previous) ? $previous : []);
        }
        return $this->previous;
    }

    private function activationAt(): ?int
    {
        if (!isset($this->publication['handover']) || !is_array($this->publication['handover'])) {
            return null;
        }
        if ($this->activation === null) {
            $requestedAt = (int) ($this->publication['handover']['requestedAtMs'] ?? 0);
            $old = $this->previous()->resolve($requestedAt);
            $this->activation = [
                'at' => ($old['state'] ?? '') === 'on-air'
                    ? (int) $old['slotEndMs'] : $requestedAt,
                'old' => $old,
            ];
        }
        return (int) $this->activation['at'];
    }

    /** @return array<string,mixed> */
    private function runAt(int $timeMs): array
    {
        $dayStart = (int) ($this->publication['dayStartMs'] ?? 0);
        $day = (int) floor(($timeMs - $dayStart) / self::DAY_MS);
        $start = $day * self::DAY_MS + $dayStart;

        $plans = (array) ($this->publication['schedule']['plans'] ?? []);
        usort($plans, static fn (array $a, array $b): int => (int) ($a['startsOn'] ?? 0) <=> (int) ($b['startsOn'] ?? 0));
        $plan = null;
        foreach ($plans as $candidate) {
            if ((int) ($candidate['startsOn'] ?? PHP_INT_MAX) <= $day) $plan = $candidate;
            else break;
        }
        $choice = null;
        $cycleDay = null;
        if ($plan !== null && ($plan['days'] ?? [])) {
            $days = array_values(array_map('strval', (array) $plan['days']));
            $distance = $day - (int) $plan['startsOn'];
            $cycleDay = (($distance % count($days)) + count($days)) % count($days);
            $choice = $days[$cycleDay];
        }

        $override = false;
        foreach ((array) ($this->publication['schedule']['overrides'] ?? []) as $row) {
            if (is_array($row) && (int) ($row[0] ?? PHP_INT_MAX) === $day) {
                $choice = (string) ($row[1] ?? '');
                $override = true;
                break;
            }
        }

        $playlistId = null;
        $programmeId = null;
        if ($choice !== null && str_starts_with($choice, 'playlist:')) {
            $playlistId = substr($choice, strlen('playlist:'));
            $playlist = $this->publication['playlists'][$playlistId] ?? null;
            $pool = [];
            foreach ((array) ($playlist['programmes'] ?? []) as $candidate) {
                $candidate = (string) $candidate;
                if ((int) $this->prepareProgramme($candidate)['totalMs'] > 0) $pool[] = $candidate;
            }
            if ($pool) {
                $draw = ($this->seededRandom(
                    (string) ($this->publication['shuffleSeed'] ?? '') . "\0playlist\0$playlistId\0$day"
                ))();
                $programmeId = $pool[(int) floor($draw * count($pool))];
            }
        } elseif ($choice !== null && $choice !== '') {
            $programmeId = $choice;
        }

        return [
            'day' => $day,
            'startMs' => $start,
            'endMs' => $start + self::DAY_MS,
            'programmeId' => $programmeId,
            'playlistId' => $playlistId,
            'override' => $override,
            'planName' => (string) ($plan['name'] ?? ''),
            'planStartsOn' => $plan === null ? null : (int) $plan['startsOn'],
            'cycleDay' => $cycleDay,
        ];
    }

    /** @return array{mode:string,canonical:array,prefixMs:array,totalMs:int,hasCollections:bool} */
    private function prepareProgramme(string $programmeId): array
    {
        if (isset($this->prepared[$programmeId])) return $this->prepared[$programmeId];
        $programme = $this->publication['programmes'][$programmeId] ?? null;
        $occurrences = [];
        foreach ((array) ($programme['items'] ?? []) as $position => $item) {
            $copies = max(1, (int) ($item['repeat'] ?? 1));
            for ($copy = 0; $copy < $copies; $copy++) {
                $trackId = array_key_exists('trackId', $item) ? (string) $item['trackId'] : null;
                $collectionId = array_key_exists('collectionId', $item) ? (string) $item['collectionId'] : null;
                $track = $trackId === null ? null : ($this->publication['tracks'][$trackId] ?? null);
                $duration = is_array($track) ? (int) ($track['durationMs'] ?? 0) : 0;
                if ($collectionId !== null || ($duration > 0 && is_array($track))) {
                    $occurrences[] = [
                        'key' => (string) ($item['entryId'] ?? $trackId ?? ('collection:' . $collectionId)) . '#' . $copy,
                        'trackId' => $trackId,
                        'collectionId' => $collectionId,
                        'copyIndex' => $copy,
                        'position' => (int) $position,
                        'durationMs' => $duration,
                        'track' => $track,
                    ];
                }
            }
        }
        $mode = (($programme['mode'] ?? '') === 'ordered') ? 'ordered' : 'shuffle';
        if ($mode === 'shuffle') {
            usort($occurrences, static fn (array $a, array $b): int => strcmp((string) $a['key'], (string) $b['key']));
        }
        $prefix = [0];
        foreach ($occurrences as $entry) $prefix[] = $prefix[array_key_last($prefix)] + (int) $entry['durationMs'];
        return $this->prepared[$programmeId] = [
            'mode' => $mode,
            'canonical' => $occurrences,
            'prefixMs' => $prefix,
            'totalMs' => (int) $prefix[array_key_last($prefix)],
            'hasCollections' => (bool) array_filter($occurrences, static fn (array $entry): bool => $entry['collectionId'] !== null),
        ];
    }

    /** @return array{entries:array,prefixMs:array} */
    private function orderFor(string $programmeId, int $day, int $pass): array
    {
        $cacheKey = "$programmeId|$day|$pass";
        if (isset($this->orders[$cacheKey])) return $this->orders[$cacheKey];
        $base = $this->prepareProgramme($programmeId);
        $totals = [];
        foreach ($base['canonical'] as $entry) {
            if ($entry['collectionId'] !== null) {
                $id = (string) $entry['collectionId'];
                $totals[$id] = ($totals[$id] ?? 0) + 1;
            }
        }
        $seen = [];
        $entries = [];
        foreach ($base['canonical'] as $entry) {
            if ($entry['collectionId'] !== null) {
                $id = (string) $entry['collectionId'];
                $ordinal = $seen[$id] ?? 0;
                $seen[$id] = $ordinal + 1;
                $track = $this->collectionTrack(
                    $id,
                    "programme:$programmeId:$day",
                    $pass * $totals[$id] + $ordinal
                );
                if ($track === null) continue;
                $entry['trackId'] = (string) $track['id'];
                $entry['track'] = $track;
                $entry['durationMs'] = (int) ($track['durationMs'] ?? 0);
            }
            $entries[] = $entry;
        }
        if ($base['mode'] === 'shuffle') {
            $random = $this->seededRandom(
                (string) ($this->publication['shuffleSeed'] ?? '') . "\0$programmeId\0$day\0$pass"
            );
            for ($i = count($entries) - 1; $i > 0; $i--) {
                $j = (int) floor($random() * ($i + 1));
                [$entries[$i], $entries[$j]] = [$entries[$j], $entries[$i]];
            }
        }
        $prefix = [0];
        foreach ($entries as $entry) $prefix[] = $prefix[array_key_last($prefix)] + (int) $entry['durationMs'];
        if (count($this->orders) > 64) $this->orders = [];
        return $this->orders[$cacheKey] = ['entries' => $entries, 'prefixMs' => $prefix];
    }

    private function collectionTrack(string $collectionId, string $scope, int $index): ?array
    {
        $collection = $this->publication['collections'][$collectionId] ?? null;
        $members = [];
        foreach ((array) ($collection['tracks'] ?? []) as $trackId) {
            $track = $this->publication['tracks'][(string) $trackId] ?? null;
            if (is_array($track) && (int) ($track['durationMs'] ?? 0) > 0) $members[] = (string) $trackId;
        }
        if (!$members) return null;
        $index = max(0, $index);
        $cycle = intdiv($index, count($members));
        $bag = $members;
        $random = $this->seededRandom(
            (string) ($this->publication['shuffleSeed'] ?? '') . "\0collection\0$collectionId\0$scope\0$cycle"
        );
        for ($i = count($bag) - 1; $i > 0; $i--) {
            $j = (int) floor($random() * ($i + 1));
            [$bag[$i], $bag[$j]] = [$bag[$j], $bag[$i]];
        }
        return $this->publication['tracks'][$bag[$index % count($bag)]] ?? null;
    }

    /** @return array<string,mixed> */
    private function compile(array $nominal, int $start, ?array $continuation = null): array
    {
        $run = $nominal;
        $run['startMs'] = $start;
        $run['nominalEndMs'] = $nominal['endMs'];
        $run['endMs'] = $nominal['endMs'];
        $programmeId = $nominal['programmeId'];
        $programme = $programmeId === null ? null : ($this->publication['programmes'][(string) $programmeId] ?? null);
        if (!is_array($programme)) return $run;
        $events = $this->eventsBetween($programme, $start, (int) $nominal['endMs']);
        $prepared = $this->prepareProgramme((string) $programmeId);
        if (!$events && $continuation === null && !$prepared['hasCollections']) return $run;

        $run['slots'] = [];
        $cursor = $start;
        $pass = (int) ($continuation['pass'] ?? 0);
        $index = 0;
        $order = $continuation['entries'] ?? $this->orderFor((string) $programmeId, (int) $nominal['day'], $pass)['entries'];
        $completed = $continuation['completed'] ?? [];
        $eventIndex = 0;
        $guard = 0;
        while ($cursor < (int) $nominal['endMs'] && $guard++ < 300000) {
            $event = $events[$eventIndex] ?? null;
            $eventSlot = false;
            if ($event !== null && (int) $event['at'] <= $cursor) {
                $entry = [
                    'track' => $event['track'],
                    'durationMs' => (int) $event['track']['durationMs'],
                    'key' => 'event:' . ($event['id'] ?? '') . ':' . $event['at'],
                ];
                $eventIndex++;
                $eventSlot = true;
            } else {
                if ($index >= count($order)) {
                    $pass++;
                    $index = 0;
                    $completed = [];
                    $order = $this->orderFor((string) $programmeId, (int) $nominal['day'], $pass)['entries'];
                }
                if (!$order) {
                    if ($event === null) break;
                    $cursor = (int) $event['at'];
                    continue;
                }
                $entry = $order[$index++];
                $completed[] = $entry['key'];
            }

            $end = $cursor + (int) $entry['durationMs'];
            for ($i = $eventIndex; $i < count($events); $i++) {
                $candidate = $events[$i];
                if (($candidate['timing'] ?? '') === 'strict'
                    && (int) $candidate['at'] > $cursor
                    && (int) $candidate['at'] < $end) {
                    $end = (int) $candidate['at'];
                    break;
                }
            }
            $end = min($end, (int) $nominal['endMs']);
            if ($end <= $cursor) break;
            $run['slots'][] = [
                'entry' => $entry,
                'start' => $cursor,
                'end' => $end,
                'pass' => $pass,
                'index' => $index - 1,
                'completedKeys' => $completed,
                'event' => $eventSlot ? $event : null,
            ];
            $cursor = $end;
        }
        return $run;
    }

    private function eventsBetween(array $programme, int $start, int $end): array
    {
        $events = [];
        foreach ((array) ($programme['events'] ?? []) as $event) {
            $period = ($event['repeat'] ?? '') === 'hourly' ? 3600000 : self::DAY_MS;
            $at = (int) floor($start / $period) * $period + (int) ($event['timeMs'] ?? 0);
            if ($at < $start) $at += $period;
            for (; $at < $end; $at += $period) {
                if (array_key_exists('collectionId', $event)) {
                    $track = $this->collectionTrack(
                        (string) $event['collectionId'],
                        'event:' . ($event['id'] ?? '') . ':' . $period,
                        (int) floor($at / $period)
                    );
                } else {
                    $track = $this->publication['tracks'][(string) ($event['trackId'] ?? '')] ?? null;
                }
                if (is_array($track) && (int) ($track['durationMs'] ?? 0) > 0) {
                    $copy = $event;
                    $copy['at'] = $at;
                    $copy['track'] = $track;
                    $events[] = $copy;
                }
            }
        }
        usort($events, static function (array $a, array $b): int {
            $time = (int) $a['at'] <=> (int) $b['at'];
            if ($time !== 0) return $time;
            return ($a['timing'] ?? '') === 'strict' ? -1 : 1;
        });
        return $events;
    }

    private function locate(array $run, int $timeMs): ?array
    {
        if (!array_key_exists('slots', $run)) {
            $programmeId = (string) $run['programmeId'];
            $prepared = $this->prepareProgramme($programmeId);
            if ((int) $prepared['totalMs'] <= 0) return null;
            $within = max(0, $timeMs - (int) $run['startMs']);
            $pass = intdiv($within, (int) $prepared['totalMs']);
            $order = $this->orderFor($programmeId, (int) $run['day'], $pass);
            $offset = $within % (int) $prepared['totalMs'];
            $low = 0;
            $high = count($order['entries']) - 1;
            while ($low < $high) {
                $middle = ($low + $high + 1) >> 1;
                if ((int) $order['prefixMs'][$middle] <= $offset) $low = $middle;
                else $high = $middle - 1;
            }
            $entry = $order['entries'][$low] ?? null;
            if ($entry === null) return null;
            $start = (int) $run['startMs'] + $pass * (int) $prepared['totalMs'] + (int) $order['prefixMs'][$low];
            return [
                'entry' => $entry,
                'start' => $start,
                'end' => $start + (int) $entry['durationMs'],
                'pass' => $pass,
                'index' => $low,
                'completedKeys' => array_map(
                    static fn (array $row): string => (string) $row['key'],
                    array_slice($order['entries'], 0, $low + 1)
                ),
                'event' => null,
            ];
        }
        $slots = $run['slots'];
        if (!$slots) return null;
        $low = 0;
        $high = count($slots) - 1;
        while ($low < $high) {
            $middle = ($low + $high + 1) >> 1;
            if ((int) $slots[$middle]['start'] <= $timeMs) $low = $middle;
            else $high = $middle - 1;
        }
        $slot = $slots[$low];
        return (int) $slot['start'] <= $timeMs && $timeMs < (int) $slot['end'] ? $slot : null;
    }

    /** @return array<string,mixed> */
    private function off(string $reason, ?int $nextBoundaryMs = null): array
    {
        return [
            'state' => 'off-air', 'reason' => $reason, 'track' => null,
            'nextBoundaryMs' => $nextBoundaryMs, 'programme' => null, 'programmeId' => null,
        ];
    }

    /** @return Closure():float */
    private function seededRandom(string $seed): Closure
    {
        $a = $this->fnv1a($seed . "\0a");
        $b = $this->fnv1a($seed . "\0b");
        $c = $this->fnv1a($seed . "\0c");
        $d = $this->fnv1a($seed . "\0d");
        $next = static function () use (&$a, &$b, &$c, &$d): float {
            $t = ($a + $b) & 0xFFFFFFFF;
            $a = ($b ^ ($b >> 9)) & 0xFFFFFFFF;
            $b = ($c + (($c << 3) & 0xFFFFFFFF)) & 0xFFFFFFFF;
            $c = ((($c << 21) & 0xFFFFFFFF) | ($c >> 11)) & 0xFFFFFFFF;
            $d = ($d + 1) & 0xFFFFFFFF;
            $t = ($t + $d) & 0xFFFFFFFF;
            $c = ($c + $t) & 0xFFFFFFFF;
            return $t / 4294967296;
        };
        for ($i = 0; $i < 12; $i++) $next();
        return $next;
    }

    private function fnv1a(string $text): int
    {
        $hash = 0x811c9dc5;
        $units = unpack('n*', mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) ?: [];
        foreach ($units as $unit) $hash = $this->imul($hash ^ $unit, 0x01000193);
        return $hash;
    }

    private function imul(int $a, int $b): int
    {
        $a &= 0xFFFFFFFF;
        $b &= 0xFFFFFFFF;
        return ((((($a >> 16) * $b) & 0xFFFF) << 16) + ($a & 0xFFFF) * $b) & 0xFFFFFFFF;
    }
}

