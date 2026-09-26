<?php
/**
 * Publishing.
 *
 * Every successful panel change becomes one flat broadcast snapshot in
 * SQLite. A public PHP endpoint assembles the snapshots still relevant to the
 * current programme day and returns them as one conditional JSON response.
 *
 * Nothing here knows how to resolve a schedule — that lives in one place, in
 * panel/engine/schedule.js. This file only decides *what is true* and writes
 * it down: the library, the programmes, the playlists, and the dated repeating
 * day plans. The engine does the rest from those numbers alone.
 */

declare(strict_types=1);

require_once __DIR__ . '/model.php';

const SHUFFLE_ALGORITHM = 'fnv1a-sfc32-fy-v1';

/**
 * The publication format this panel writes. It must match PROGRAMME_VERSION
 * in engine/schedule.js, which is what refuses a format a player cannot read.
 */
const PROGRAMME_VERSION = 1;

/** Grace period so players that are awake can fetch a revision before it applies. */
function build_publication(array $station, int $revision): array
{
    $panel = panel_settings();
    $id = (string) $station['id'];

    // Only tracks a programme actually holds. The rest of the library is the
    // operator's business, not a listener's download.
    $used = [];
    $usedCollections = [];
    $lists = [];
    foreach (programmes($id) as $programme) {
        $items = [];
        foreach (programme_items((int) $programme['id']) as $entry) {
            $item = ['entryId' => $entry['uid'] ?: $entry['entry_id'] . ':0'];
            if ($entry['collection_id'] !== null) {
                $collectionId = (int) $entry['collection_id'];
                $usedCollections[$collectionId] = true;
                $item['collectionId'] = (string) $collectionId;
            } else {
                $used[(int) $entry['id']] = $entry;
                $item['trackId'] = (string) $entry['item_id'];
            }
            if ((int) $entry['repeat_count'] > 1) {
                $item['repeat'] = (int) $entry['repeat_count'];
            }
            $items[] = $item;
        }
        $events = json_decode($programme['events'] ?? '[]', true) ?: [];
        foreach ($events as &$event) {
            if (isset($event['collectionId'])) {
                $collection = collection((int) $event['collectionId']);
                if ($collection && $collection['station_id'] === $id) {
                    $usedCollections[(int) $collection['id']] = true;
                    $event['collectionId'] = (string) $collection['id'];
                }
                unset($event['trackId']);
            } else {
                $track = track((int) ($event['trackId'] ?? 0));
                if ($track && $track['station_id'] === $id) {
                    $used[(int) $track['id']] = $track;
                    $event['trackId'] = $track['item_id'];
                }
            }
        }
        unset($event);
        $lists[(string) $programme['id']] = [
            'name'    => $programme['name'],
            'mode'    => $programme['mode'] === 'ordered' ? 'ordered' : 'shuffle',
            'items'   => $items,
            'events' => $events,
        ];
        if (($programme['art_url'] ?? '') !== '') {
            $lists[(string) $programme['id']]['artUrl'] = $programme['art_url'];
        }
    }

    $publishedCollections = [];
    foreach (array_keys($usedCollections) as $collectionId) {
        $collection = collection((int) $collectionId);
        if ($collection === null || $collection['station_id'] !== $id) {
            continue;
        }
        $members = [];
        foreach (collection_track_ids((int) $collectionId, true) as $trackId) {
            $track = track($trackId);
            if ($track === null || $track['station_id'] !== $id) {
                continue;
            }
            $used[$trackId] = $track;
            $members[] = (string) $track['item_id'];
        }
        $publishedCollections[(string) $collectionId] = [
            'name' => (string) $collection['name'],
            'tracks' => $members,
        ];
    }

    $tracks = [];
    foreach ($used as $entry) {
        $track = [
            'id'         => (string) $entry['item_id'],
            'title'      => $entry['title'],
            'credit'     => $entry['credit'],
            'mediaUrl'   => $entry['media_url'],
            'durationMs' => (int) $entry['duration_ms'],
        ];
        if ($entry['description'] !== '') {
            $track['description'] = $entry['description'];
        }
        if ($entry['page_url'] !== '') {
            $track['pageUrl'] = $entry['page_url'];
        }
        if ($entry['art_url'] !== '') {
            $track['artUrl'] = $entry['art_url'];
        }
        $tracks[(string) $entry['item_id']] = $track;
    }

    // Every playlist, with its programmes in id order: the engine draws from
    // that order, and the panel's own copy of the draw assumes it.
    $playlists = [];
    foreach (playlists($id) as $playlist) {
        $playlists[(string) $playlist['id']] = [
            'name'       => $playlist['name'],
            'programmes' => array_map('strval', playlist_programme_ids((int) $playlist['id'])),
        ];
    }

    // A plan is an arbitrary repeating list of programme-day choices. The
    // latest one whose start day has arrived is active; one-off overrides win.
    $schedule = schedule($id);
    $plans = [];
    foreach ($schedule['plans'] as $plan) {
        $plans[] = [
            'startsOn' => (int) $plan['starts_on'],
            'name' => (string) $plan['name'],
            'days' => array_values(array_map(static fn (array $day): string => (string) $day['choice'], $plan['days'])),
        ];
    }
    $overrides = [];
    foreach ($schedule['overrides'] as $day => $entry) {
        $overrides[] = [(int) $day, $entry['choice']];
    }

    return [
        'programmeVersion' => PROGRAMME_VERSION,
        'epochMs' => (int) (floor((now_ms() - (int) $station['day_start_ms']) / DAY_MS) * DAY_MS + (int) $station['day_start_ms'] - DAY_MS),
        'stationId'   => $id,
        'revision'    => $revision,
        'publishedAt' => gmdate('c'),
        // A station can be published and still be off air. Players read this
        // and say so, rather than being left to fail at fetching media.
        'enabled'     => (int) ($station['enabled'] ?? 1) === 1,
        'station'     => [
            'name'     => $station['name'],
            'tagline'  => $station['tagline'],
            'accent'   => $station['accent'],
            'homeUrl'  => $station['home_url'],
            'colophon' => $station['colophon'],
            'logoUrl'  => (string) ($station['logo_url'] ?? ''),
            'artUrl'   => (string) ($station['art_url'] ?? ''),
            // The language the station's player speaks to its listeners, which
            // need not be the one the panel speaks to its owner. The words
            // themselves are not in here: a snapshot is what was broadcast,
            // and a translation is not. They are attached to the feed once,
            // in publication_feed().
            'language' => (string) ($station['player_language'] ?? 'en'),
        ],
        // Milliseconds after 00:00 UTC. A day is always exactly 86,400,000 ms,
        // so nothing downstream needs a timezone or a daylight-saving rule.
        'dayStartMs'  => (int) $station['day_start_ms'],
        'shuffleSeed' => $panel['shuffleSeed'] . '·' . $id,
        'algorithm'   => SHUFFLE_ALGORITHM,
        'tracks'      => $tracks,
        'collections' => $publishedCollections,
        'programmes'   => $lists,
        'playlists'    => $playlists,
        'schedule'    => [
            'plans'     => $plans,
            'overrides' => $overrides,
        ],
    ];
}

/**
 * Structural checks before anything is written.
 *
 * This deliberately mirrors the error cases in `validatePublication()` on the
 * browser side. The duplication is the point: the browser's copy is there to
 * tell the operator early, this one is there because a server may not trust a
 * browser. Neither copy interprets the timeline — they check the inputs.
 *
 * @return array{0: string[], 1: string[]} errors, warnings
 */
function check_publication(array $pub): array
{
    $errors = [];
    $warnings = [];

    if ($pub['stationId'] === '') {
        $errors[] = 'the station has no id';
    }
    if ((int) $pub['dayStartMs'] < 0 || (int) $pub['dayStartMs'] >= DAY_MS) {
        $errors[] = "the station's day start is not a time of day";
    }

    foreach ($pub['tracks'] as $id => $track) {
        $where = 'track ' . ($track['title'] !== '' ? '"' . $track['title'] . '"' : $id);
        if ($track['mediaUrl'] === '') {
            $errors[] = "$where has no media URL";
        }
        if ($pub['enabled'] !== false && (int) $track['durationMs'] <= 0) {
            $errors[] = "$where has not been measured yet";
        }
        if ($track['title'] === '') {
            $warnings[] = "$where has no title — listeners will see its id";
        }
    }

    foreach ($pub['programmes'] as $id => $programme) {
        $where = 'programme "' . $programme['name'] . '"';
        if (!$programme['items']) {
            $warnings[] = "$where is empty — the station is off air on the days it runs";
            continue;
        }
        $length = 0;
        $variable = false;
        foreach ($programme['items'] as $item) {
            if (isset($item['collectionId'])) {
                $collection = $pub['collections'][(string) $item['collectionId']] ?? null;
                if ($collection === null) {
                    $errors[] = "$where holds a collection that does not exist";
                } elseif (!($collection['tracks'] ?? [])) {
                    $errors[] = "$where holds a collection with nothing playable";
                }
                $variable = true;
                continue;
            }
            $track = $pub['tracks'][$item['trackId'] ?? ''] ?? null;
            if ($track === null) {
                $errors[] = "$where holds a track that is not in the library";
                continue;
            }
            $length += (int) $track['durationMs'] * max(1, (int) ($item['repeat'] ?? 1));
        }
        foreach (($programme['events'] ?? []) as $event) {
            if (isset($event['collectionId'])) {
                $collection = $pub['collections'][(string) $event['collectionId']] ?? null;
                if ($collection === null || !($collection['tracks'] ?? [])) {
                    $errors[] = "$where has an event whose collection has nothing playable";
                }
            } elseif (!isset($pub['tracks'][(string) ($event['trackId'] ?? '')])) {
                $errors[] = "$where has an event whose track is not in the library";
            }
        }
        if (!$variable && $length > DAY_MS) {
            $warnings[] = "$where is longer than a day, so its last "
                . round(($length - DAY_MS) / 60000) . ' minutes never air';
        } elseif (!$variable && $length > 0 && $length < 60000) {
            $warnings[] = "$where is only " . round($length / 1000, 1)
                . 's long — listeners will hear it repeat quickly';
        }
        if (!$variable && $length > 0 && $programme['mode'] === 'ordered' && DAY_MS % $length !== 0) {
            $warnings[] = "$where plays in a fixed order and does not divide the day evenly, "
                . 'so the tracks at its end are cut short every day';
        }
    }

    foreach (($pub['playlists'] ?? []) as $playlist) {
        foreach ($playlist['programmes'] as $programmeId) {
            if (!isset($pub['programmes'][(string) $programmeId])) {
                $errors[] = 'playlist "' . $playlist['name'] . '" holds a programme that does not exist';
            }
        }
    }
    // A schedule entry names a programme, or a playlist as "playlist:<id>".
    $names = static fn (string $ref): bool => str_starts_with($ref, 'playlist:')
        ? isset($pub['playlists'][substr($ref, strlen('playlist:'))])
        : isset($pub['programmes'][$ref]);

    $seenStarts = [];
    foreach (($pub['schedule']['plans'] ?? []) as $plan) {
        $startsOn = $plan['startsOn'] ?? null;
        if (!is_int($startsOn)) {
            $errors[] = 'a plan has no start day';
        } elseif (isset($seenStarts[$startsOn])) {
            $errors[] = 'two plans start on the same day';
        }
        $seenStarts[$startsOn] = true;
        if (!is_array($plan['days'] ?? null) || !$plan['days']) {
            $warnings[] = 'a plan has no programme days, so the station is off air while it is active';
        }
        foreach (($plan['days'] ?? []) as $ref) {
            if (!$names((string) $ref)) {
                $errors[] = 'a plan names a programme or playlist that does not exist';
            }
        }
    }
    if (!($pub['schedule']['plans'] ?? []) && $pub['enabled'] !== false) {
        $warnings[] = 'the station has no plan yet, so it is off air';
    }
    foreach (($pub['schedule']['overrides'] ?? []) as [$day, $programmeId]) {
        if (!is_int($day)) {
            $errors[] = 'an override has no day';
        }
        if (!$names((string) $programmeId)) {
            $errors[] = 'an override names a programme or playlist that does not exist';
        }
    }

    return [$errors, $warnings];
}

/** The beginning of the station's programme day containing this instant. */
function publication_day_start(array $station, int $instantMs): int
{
    $offset = (int) $station['day_start_ms'];
    return (int) (floor(($instantMs - $offset) / DAY_MS) * DAY_MS + $offset);
}

/**
 * A new flat broadcast snapshot, committed atomically with the station's live
 * revision. JSON is transport data inside SQLite, never a public filesystem.
 *
 * @return array{revision:int, warnings:string[], publishedAtMs:int}
 * @throws RuntimeException when the saved station cannot be made live
 */
function publish_station(string $stationId): array
{
    $station = station($stationId);
    if ($station === null) {
        throw new RuntimeException('no such station');
    }

    $revision = (int) $station['revision'] + 1;
    $pub = build_publication($station, $revision);
    [$errors, $warnings] = check_publication($pub);
    if ($errors) {
        throw new RuntimeException(implode("\n", $errors));
    }

    $publishedAtMs = now_ms();
    $payload = json_encode($pub, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    db()->beginTransaction();
    try {
        db()->prepare(
            'INSERT INTO broadcast_snapshots (station_id, revision, payload, published_at) VALUES (?, ?, ?, ?)'
        )->execute([$stationId, $revision, $payload, $publishedAtMs]);
        db()->prepare('UPDATE stations SET revision = ?, published_at = ? WHERE id = ?')
            ->execute([$revision, $publishedAtMs, $stationId]);
        db()->commit();
    } catch (Throwable $err) {
        db()->rollBack();
        throw $err;
    }

    prune_publication_snapshots($station, $publishedAtMs);

    return [
        'revision'     => $revision,
        'warnings'     => $warnings,
        'publishedAtMs' => $publishedAtMs,
    ];
}

/** Keep one baseline plus changes made during the current programme day. */
function prune_publication_snapshots(array $station, int $nowMs): void
{
    $dayStart = publication_day_start($station, $nowMs);
    $baseline = db()->prepare(
        'SELECT MAX(revision) FROM broadcast_snapshots WHERE station_id = ? AND published_at <= ?'
    );
    $baseline->execute([$station['id'], $dayStart]);
    $revision = $baseline->fetchColumn();
    if ($revision !== false && $revision !== null) {
        db()->prepare('DELETE FROM broadcast_snapshots WHERE station_id = ? AND revision < ?')
            ->execute([$station['id'], (int) $revision]);
    }
}

/**
 * Flat snapshots needed to reconstruct the live handover chain right now.
 * The first is the day's baseline; each later row was requested at its own
 * publishedAtMs. A new programme day makes all earlier handovers irrelevant.
 */
function publication_feed(string $stationId, ?int $nowMs = null): ?array
{
    $station = station($stationId);
    if ($station === null || (int) $station['revision'] < 1) return null;
    $nowMs ??= now_ms();
    $dayStart = publication_day_start($station, $nowMs);

    $baseline = db()->prepare(
        'SELECT MAX(revision) FROM broadcast_snapshots WHERE station_id = ? AND published_at <= ?'
    );
    $baseline->execute([$stationId, $dayStart]);
    $firstRevision = $baseline->fetchColumn();
    if ($firstRevision === false || $firstRevision === null) {
        $minimum = db()->prepare('SELECT MIN(revision) FROM broadcast_snapshots WHERE station_id = ?');
        $minimum->execute([$stationId]);
        $firstRevision = $minimum->fetchColumn();
    }
    if ($firstRevision === false || $firstRevision === null) return null;

    $query = db()->prepare(
        'SELECT revision, payload, published_at FROM broadcast_snapshots
         WHERE station_id = ? AND revision >= ? ORDER BY revision'
    );
    $query->execute([$stationId, (int) $firstRevision]);
    $snapshots = [];
    foreach ($query->fetchAll() as $row) {
        $publication = json_decode((string) $row['payload'], true, 512, JSON_THROW_ON_ERROR);
        $snapshots[] = [
            'revision'      => (int) $row['revision'],
            'requestedAtMs' => (int) $row['published_at'],
            'publication'   => $publication,
        ];
    }
    if (!$snapshots) return null;
    return [
        'feedVersion' => 1,
        'stationId'   => $stationId,
        'revision'    => (int) end($snapshots)['revision'],
        // Once for the whole feed, not once per snapshot: a listener needs
        // the words the station speaks now, and the feed can carry a day's
        // worth of revisions. Read here rather than frozen into a snapshot,
        // so correcting a translation in languages/ reaches every listener
        // without republishing a single station.
        'words'       => player_words((string) ($station['player_language'] ?? 'en')),
        'snapshots'   => $snapshots,
    ];
}

/** Rebuild the engine's in-memory handover shape from a flat feed. */
function compose_publication(array $feed): ?array
{
    $current = null;
    foreach (($feed['snapshots'] ?? []) as $snapshot) {
        $next = $snapshot['publication'] ?? null;
        if (!is_array($next)) continue;
        if ($current !== null) {
            $next['handover'] = [
                'requestedAtMs' => (int) $snapshot['requestedAtMs'],
                'previous'      => $current,
            ];
        }
        $current = $next;
    }
    return $current;
}

/** The latest flat publication, used for station identity/PWA responses. */
function latest_publication(string $stationId): ?array
{
    $query = db()->prepare(
        'SELECT payload FROM broadcast_snapshots WHERE station_id = ? ORDER BY revision DESC LIMIT 1'
    );
    $query->execute([$stationId]);
    $payload = $query->fetchColumn();
    return is_string($payload) ? json_decode($payload, true, 512, JSON_THROW_ON_ERROR) : null;
}

/**
 * Best-effort live sync for panel mutations. Callers can report the rare
 * failure without exposing revision numbers or requiring a second action.
 *
 * @return array{ok:bool, error?:string, warnings?:string[]}
 */
function sync_station(string $stationId): array
{
    try {
        $result = publish_station($stationId);
        return ['ok' => true, 'warnings' => $result['warnings']];
    } catch (Throwable $err) {
        return ['ok' => false, 'error' => $err->getMessage()];
    }
}

/**
 * The install manifest is generated from the latest published identity.
 *
 * `$clean` is whether it was asked for by its clean address. If so, the
 * server rewrites addresses and the app is built on them; if not, on the
 * player/?station= and index.php forms that work on any host. An installed
 * app that opened on an address its server does not have would never start.
 */
function station_manifest(array $publication, string $panelPath, bool $clean = true): array
{
    $stationId = (string) $publication['stationId'];
    $station = (array) ($publication['station'] ?? []);
    $name = trim((string) ($station['name'] ?? '')) ?: $stationId;
    $tagline = trim((string) ($station['tagline'] ?? ''));
    $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) ($station['accent'] ?? ''))
        ? strtolower((string) $station['accent']) : '#6a7f8c';
    $language = language_resolve((string) ($station['language'] ?? '')) ?? 'en';
    // Read now, for the same reason the feed reads them now: an installed
    // station should pick up a corrected translation on its next manifest
    // fetch, not only when its operator happens to republish.
    $words = player_words($language);

    $id = rawurlencode($stationId);
    $icon = static fn (int $size, bool $maskable): string => $clean
        ? $panelPath . "stations/$id/icon-" . ($maskable ? 'maskable-' : '') . "$size.png"
        : $panelPath . "index.php?p=station-icon&id=$id&size=$size" . ($maskable ? '&maskable=1' : '');
    $icons = [
        ['src' => $icon(192, false), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon(512, false), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $icon(512, true), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ];
    $playerUrl = $panelPath . 'player/' . ($clean ? $id : "?station=$id");

    return [
        // A different ID makes every station an independently installable app
        // even though their start URLs share the same player document.
        'id'          => $playerUrl,
        'name'        => $name,
        'short_name'  => mb_substr($name, 0, 24),
        // The station's own words describe it, so an installed station reads
        // in its own language on the home screen as well as in the player.
        'description' => $tagline !== '' ? $tagline : player_say($words, 'Live radio from {station}', ['station' => $name]),
        'lang'        => $language,
        'dir'         => (string) ($words['direction'] ?? 'ltr') === 'rtl' ? 'rtl' : 'ltr',
        'start_url'   => $playerUrl,
        'scope'       => $panelPath . 'player/',
        'display'     => 'standalone',
        'background_color' => '#07090b',
        'theme_color' => $accent,
        'categories'  => ['music', 'entertainment'],
        'icons'       => $icons,
        'prefer_related_applications' => false,
    ];
}

/** Resolve one of this station's own published image URLs to its file. */
function pwa_station_image_path(string $stationId, string $url): ?string
{
    $prefix = station_media_url($stationId, '');
    if ($url !== '' && str_starts_with($url, $prefix)) {
        $relative = substr($url, strlen($prefix));
        if (preg_match('#^(covers/)?[A-Za-z0-9][A-Za-z0-9._-]*$#', $relative)) {
            $candidate = station_media_dir($stationId) . '/' . $relative;
            if (is_file($candidate)) {
                return $candidate;
            }
        }
    }
    return null;
}

/**
 * Encode one exact-size app icon. The route is conditional-cacheable, so this
 * work happens only when the published station revision changes.
 */
function station_icon_png(array $publication, int $size, bool $maskable): string
{
    if (!in_array($size, [192, 512], true)) {
        throw new InvalidArgumentException('unsupported station icon size');
    }
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        $bytes = file_get_contents(PANEL_ROOT . "/assets/img/app-icon-$size.png");
        if ($bytes === false) throw new RuntimeException("the built-in $size px app icon is missing");
        return $bytes;
    }

    $stationId = (string) $publication['stationId'];
    $station = (array) ($publication['station'] ?? []);
    $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) ($station['accent'] ?? ''))
        ? strtolower((string) $station['accent']) : '#6a7f8c';
    $logoPath = pwa_station_image_path($stationId, (string) ($station['logoUrl'] ?? ''));
    $coverPath = pwa_station_image_path($stationId, (string) ($station['artUrl'] ?? ''));
    $sourcePath = $logoPath ?? $coverPath;
    $mode = $logoPath !== null ? 'logo' : ($coverPath !== null ? 'cover' : 'monogram');

    $source = false;
    if ($sourcePath !== null) {
        $bytes = @file_get_contents($sourcePath);
        $source = $bytes === false ? false : @imagecreatefromstring($bytes);
    }
    if (!$source) {
        $mode = 'monogram';
        $source = imagecreatetruecolor(64, 64);
        [$red, $green, $blue] = sscanf($accent, '#%02x%02x%02x');
        $background = imagecolorallocate($source, (int) $red, (int) $green, (int) $blue);
        imagefilledrectangle($source, 0, 0, 63, 63, $background);
        $light = ((int) $red * 299 + (int) $green * 587 + (int) $blue * 114) < 145000;
        $letter = strtoupper(substr($stationId, 0, 1)) ?: 'R';
        $glyph = imagecreatetruecolor(9, 15);
        $key = imagecolorallocate($glyph, 1, 2, 3);
        imagefilledrectangle($glyph, 0, 0, 8, 14, $key);
        imagecolortransparent($glyph, $key);
        $glyphInk = $light
            ? imagecolorallocate($glyph, 255, 255, 255)
            : imagecolorallocate($glyph, 15, 18, 22);
        imagestring($glyph, 5, 0, 0, $letter, $glyphInk);
        imagecopyresized($source, $glyph, 18, 8, 0, 0, 28, 48, 9, 15);
        imagedestroy($glyph);
    }

    $width = imagesx($source);
    $height = imagesy($source);
    if ($width < 1 || $height < 1) {
        imagedestroy($source);
        throw new RuntimeException('the station icon has no usable dimensions');
    }

    [$red, $green, $blue] = sscanf($accent, '#%02x%02x%02x');
    $icon = imagecreatetruecolor($size, $size);
    $background = imagecolorallocate($icon, (int) $red, (int) $green, (int) $blue);
    imagefilledrectangle($icon, 0, 0, $size - 1, $size - 1, $background);
    imagealphablending($icon, true);
    if ($mode === 'logo') {
        $available = (int) round($size * ($maskable ? 0.56 : 0.84));
        $scale = min($available / $width, $available / $height);
        $drawWidth = max(1, (int) round($width * $scale));
        $drawHeight = max(1, (int) round($height * $scale));
        imagecopyresampled($icon, $source,
            (int) floor(($size - $drawWidth) / 2), (int) floor(($size - $drawHeight) / 2),
            0, 0, $drawWidth, $drawHeight, $width, $height);
    } else {
        $side = min($width, $height);
        imagecopyresampled($icon, $source, 0, 0,
            (int) floor(($width - $side) / 2), (int) floor(($height - $side) / 2),
            $size, $size, $side, $side);
    }
    ob_start();
    $written = imagepng($icon, null, 7);
    $png = ob_get_clean();
    imagedestroy($icon);
    imagedestroy($source);
    if (!$written || !is_string($png)) {
        throw new RuntimeException('could not encode the station app icon');
    }
    return $png;
}
