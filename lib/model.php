<?php
/**
 * Stations, their library, their programmes and what plays when.
 *
 * Plain SQL over a small schema: the panel is a single-operator tool and there
 * is no reason for more machinery than this. Everything time-related is UTC;
 * `day_start_ms` is milliseconds after 00:00 UTC, and a day number is whole
 * days since 1970-01-01.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const DAY_MS = 86400000;

/** UTC day number for an instant. */
/** Day of the week for a day number, 0 = Sunday. 1970-01-01 was a Thursday. */
/** Weekday names, in the order a week is usually read. */
/* -------------------------------------------------------------- stations */

function stations(): array
{
    return db()->query(
        'SELECT s.*,
                (SELECT COUNT(*) FROM tracks t WHERE t.station_id = s.id) AS track_count,
                (SELECT COUNT(*) FROM programmes p WHERE p.station_id = s.id) AS programme_count
           FROM stations s
       ORDER BY s.name COLLATE NOCASE'
    )->fetchAll();
}

function station(string $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM stations WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function create_station(string $name, ?string $id = null): string
{
    $id = slugify($id ?: $name);
    $base = $id;
    $n = 2;
    while (station($id) !== null) {
        $id = $base . '-' . $n++;
    }
    $t = now_ms();
    $pdo = db();
    $media = station_media_dir($id);
    $createdMedia = false;
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO stations (id, name, day_start_ms, created_at, updated_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$id, $name, 0, $t, $t]);
        if (!is_dir($media)) {
            if (!@mkdir($media, 0775, true) && !is_dir($media)) {
                throw new RuntimeException('could not create the station media directory');
            }
            $createdMedia = true;
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($createdMedia) {
            try { remove_tree($media); } catch (Throwable) {}
        }
        throw $error;
    }
    return $id;
}

function update_station(string $id, array $fields): void
{
    $changesMedia = array_key_exists('art_url', $fields) || array_key_exists('logo_url', $fields);
    $before = $changesMedia ? station($id) : null;
    $allowed = ['name', 'tagline', 'accent', 'colophon', 'logo_url', 'art_url', 'home_url', 'enabled', 'day_start_ms',
        'player_language'];
    $sets = [];
    $args = [];
    foreach ($allowed as $key) {
        if (array_key_exists($key, $fields)) {
            $sets[] = "$key = ?";
            $args[] = in_array($key, ['enabled', 'day_start_ms'], true)
                ? (int) $fields[$key]
                : (string) $fields[$key];
        }
    }
    if (!$sets) {
        return;
    }
    $sets[] = 'updated_at = ?';
    $args[] = now_ms();
    $args[] = $id;
    db()->prepare('UPDATE stations SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
    foreach (['logo_url', 'art_url'] as $mediaField) {
        if ($before && array_key_exists($mediaField, $fields)
            && (string) $before[$mediaField] !== (string) $fields[$mediaField]) {
            try { forget_media($id, (string) $before[$mediaField]); } catch (Throwable) {}
        }
    }
}

/** Delete a station, and every file it owns. */
function delete_station(string $id): void
{
    db()->prepare('DELETE FROM stations WHERE id = ?')->execute([$id]);
    remove_tree(station_media_dir($id));
}

function touch_station(string $id): void
{
    db()->prepare('UPDATE stations SET updated_at = ? WHERE id = ?')->execute([now_ms(), $id]);
}

function has_unpublished_changes(array $station): bool
{
    return (int) $station['revision'] === 0
        || (int) $station['updated_at'] > (int) ($station['published_at'] ?? 0);
}

/* --------------------------------------------------------------- library */

/**
 * Everything the station has, ordered like a catalogue. A track knows nothing
 * about being on air; that is a programme's business, and a track can be in
 * several of them.
 */
function tracks(string $stationId): array
{
    $stmt = db()->prepare(
        "SELECT t.*,
                (SELECT COUNT(*)
                   FROM programme_items i
                  WHERE i.track_id = t.id) AS programme_count,
                COALESCE((
                    SELECT GROUP_CONCAT(name, ', ')
                      FROM (
                        SELECT p.name
                          FROM programme_items i
                          JOIN programmes p ON p.id = i.programme_id
                         WHERE i.track_id = t.id
                         ORDER BY p.name COLLATE NOCASE
                      )
                ), '') AS programme_names,
                (SELECT COUNT(*) FROM collection_tracks ct WHERE ct.track_id = t.id) AS collection_count,
                COALESCE((
                    SELECT GROUP_CONCAT(name, ', ')
                      FROM (
                        SELECT c.name
                          FROM collection_tracks ct
                          JOIN collections c ON c.id = ct.collection_id
                         WHERE ct.track_id = t.id
                         ORDER BY c.name COLLATE NOCASE
                      )
                ), '') AS collection_names
           FROM tracks t
          WHERE t.station_id = ?
          ORDER BY CASE WHEN t.title = '' THEN t.item_id ELSE t.title END COLLATE NOCASE"
    );
    $stmt->execute([$stationId]);
    return $stmt->fetchAll();
}

function track(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM tracks WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function add_track(string $stationId, array $fields): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $itemId = slugify((string) ($fields['item_id'] ?? $fields['title'] ?? 'track'));
        $base = $itemId;
        $n = 2;
        $exists = $pdo->prepare('SELECT 1 FROM tracks WHERE station_id = ? AND item_id = ?');
        $exists->execute([$stationId, $itemId]);
        while ($exists->fetchColumn() !== false) {
            $itemId = $base . '-' . $n++;
            $exists->execute([$stationId, $itemId]);
        }

        $pdo->prepare(
            'INSERT INTO tracks (station_id, item_id, title, credit, description, media_url, page_url,
                                 art_url, duration_ms, tags, measured_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $stationId,
            $itemId,
            trim((string) ($fields['title'] ?? '')),
            trim((string) ($fields['credit'] ?? '')),
            trim((string) ($fields['description'] ?? '')),
            trim((string) ($fields['media_url'] ?? '')),
            trim((string) ($fields['page_url'] ?? '')),
            trim((string) ($fields['art_url'] ?? '')),
            (int) ($fields['duration_ms'] ?? 0),
            canonical_tags($fields['tags'] ?? ''),
            isset($fields['duration_ms']) && (int) $fields['duration_ms'] > 0 ? now_ms() : null,
        ]);
        $id = (int) $pdo->lastInsertId();
        touch_station($stationId);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function update_track(int $id, array $fields): void
{
    $allowed = ['title', 'credit', 'description', 'media_url', 'page_url', 'art_url', 'duration_ms', 'tags'];
    $sets = [];
    $args = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $fields)) {
            continue;
        }
        $sets[] = "$key = ?";
        if ($key === 'duration_ms') {
            $args[] = (int) round((float) $fields[$key]);
        } elseif ($key === 'tags') {
            $args[] = canonical_tags($fields[$key]);
        } else {
            $args[] = trim((string) $fields[$key]);
        }
    }
    if (!$sets) {
        return;
    }
    if (array_key_exists('duration_ms', $fields)) {
        $sets[] = 'measured_at = ?';
        $args[] = now_ms();
    }

    $before = track($id);
    if ($before === null) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $args[] = $id;
        $pdo->prepare('UPDATE tracks SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($args);
        touch_station((string) $before['station_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // Media cleanup is garbage collection, not part of the committed edit.
    // A cleanup failure must never turn a successful DB update into an error
    // that makes the caller delete the newly adopted file.
    if (array_key_exists('art_url', $fields)
        && (string) $before['art_url'] !== trim((string) $fields['art_url'])) {
        try { forget_media((string) $before['station_id'], (string) $before['art_url']); } catch (Throwable) {}
    }
}

/** Delete one track owned by this station, and its audio and cover with it. */
function delete_track(string $stationId, int $id): bool
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM tracks WHERE id = ? AND station_id = ?');
        $stmt->execute([$id, $stationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            $pdo->rollBack();
            return false;
        }

        $pdo->prepare('DELETE FROM tracks WHERE id = ? AND station_id = ?')->execute([$id, $stationId]);
        touch_station($stationId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    try { forget_media($stationId, (string) $row['media_url'], (string) $row['art_url']); } catch (Throwable) {}
    return true;
}

/**
 * Give a track a different audio file, keeping everything else about it. The
 * new file has not been measured yet, so the track waits for that before it
 * can take airtime again; the old file is deleted.
 */
function replace_track_audio(int $id, string $mediaUrl): void
{
    $row = track($id);
    if ($row === null) {
        return;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE tracks SET media_url = ?, duration_ms = 0, measured_at = NULL WHERE id = ?')
            ->execute([$mediaUrl, $id]);
        touch_station((string) $row['station_id']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    try { forget_media((string) $row['station_id'], (string) $row['media_url']); } catch (Throwable) {}
}

/** Which of the station's programmes hold a given track. */
/* ------------------------------------------------------------ collections */

/** Reusable pools of library tracks, shown with useful membership counts. */
function collections(string $stationId): array
{
    $stmt = db()->prepare(
        "SELECT c.*,
                COUNT(ct.track_id) AS track_count,
                COALESCE(SUM(CASE WHEN t.duration_ms > 0 AND t.media_url <> '' THEN 1 ELSE 0 END), 0) AS playable_count,
                COALESCE(GROUP_CONCAT(t.title, ', '), '') AS track_names
           FROM collections c
           LEFT JOIN collection_tracks ct ON ct.collection_id = c.id
           LEFT JOIN tracks t ON t.id = ct.track_id
          WHERE c.station_id = ?
          GROUP BY c.id
          ORDER BY c.name COLLATE NOCASE"
    );
    $stmt->execute([$stationId]);
    return $stmt->fetchAll();
}

function collection(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM collections WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function collection_track_ids(int $collectionId, bool $playableOnly = false): array
{
    $sql = 'SELECT ct.track_id FROM collection_tracks ct JOIN tracks t ON t.id = ct.track_id WHERE ct.collection_id = ?';
    if ($playableOnly) {
        $sql .= " AND t.duration_ms > 0 AND t.media_url <> ''";
    }
    $sql .= ' ORDER BY ct.track_id';
    $stmt = db()->prepare($sql);
    $stmt->execute([$collectionId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Create a collection, or replace its name and complete membership. */
function save_collection(string $stationId, ?int $id, string $name, array $trackIds): int
{
    $name = trim($name) !== '' ? trim($name) : 'Untitled';
    $now = now_ms();
    db()->beginTransaction();
    try {
        if ($id === null) {
            db()->prepare('INSERT INTO collections (station_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$stationId, $name, $now, $now]);
            $id = (int) db()->lastInsertId();
        } else {
            $owned = collection($id);
            if ($owned === null || (string) $owned['station_id'] !== $stationId) {
                throw new InvalidArgumentException('collection is not owned by this station');
            }
            db()->prepare('UPDATE collections SET name = ?, updated_at = ? WHERE id = ? AND station_id = ?')
                ->execute([$name, $now, $id, $stationId]);
            db()->prepare('DELETE FROM collection_tracks WHERE collection_id = ?')->execute([$id]);
        }
        $add = db()->prepare('INSERT OR IGNORE INTO collection_tracks (collection_id, track_id) VALUES (?, ?)');
        foreach (array_unique(array_map('intval', $trackIds)) as $trackId) {
            $track = track($trackId);
            if ($track !== null && $track['station_id'] === $stationId) {
                $add->execute([$id, $trackId]);
            }
        }
        touch_station($stationId);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return $id;
}

/** Add tracks without disturbing a collection's existing members. */
function add_tracks_to_collection(string $stationId, int $collectionId, array $trackIds): int
{
    $collection = collection($collectionId);
    if ($collection === null || $collection['station_id'] !== $stationId) {
        return 0;
    }
    $add = db()->prepare('INSERT OR IGNORE INTO collection_tracks (collection_id, track_id) VALUES (?, ?)');
    $count = 0;
    foreach (array_unique(array_map('intval', $trackIds)) as $trackId) {
        $track = track($trackId);
        if ($track !== null && $track['station_id'] === $stationId) {
            $add->execute([$collectionId, $trackId]);
            $count += $add->rowCount();
        }
    }
    if ($count > 0) {
        db()->prepare('UPDATE collections SET updated_at = ? WHERE id = ?')->execute([now_ms(), $collectionId]);
        touch_station($stationId);
    }
    return $count;
}

/** A collection cannot disappear while a programme or event still names it. */
function collection_is_used(int $collectionId): bool
{
    $stmt = db()->prepare('SELECT 1 FROM programme_items WHERE collection_id = ? LIMIT 1');
    $stmt->execute([$collectionId]);
    if ($stmt->fetchColumn() !== false) {
        return true;
    }
    $needle = '"collectionId":' . $collectionId;
    $stmt = db()->prepare("SELECT 1 FROM programmes WHERE events LIKE ? ESCAPE '\\' LIMIT 1");
    $stmt->execute(['%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle) . '%']);
    return $stmt->fetchColumn() !== false;
}

function delete_collection(int $id): void
{
    $row = collection($id);
    if ($row === null) {
        return;
    }
    db()->prepare('DELETE FROM collections WHERE id = ?')->execute([$id]);
    touch_station((string) $row['station_id']);
}

/* ------------------------------------------------------------- programmes */

function programmes(string $stationId): array
{
    $stmt = db()->prepare(
        'SELECT p.*,
                (SELECT COUNT(*) FROM programme_items i WHERE i.programme_id = p.id) AS item_count
           FROM programmes p WHERE p.station_id = ? ORDER BY p.name COLLATE NOCASE'
    );
    $stmt->execute([$stationId]);
    return $stmt->fetchAll();
}

function programme(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM programmes WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

function create_programme(string $stationId, string $name, array $fields = []): int
{
    $t = now_ms();
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO programmes (station_id, name, mode, art_url, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $stationId,
            trim($name) !== '' ? trim($name) : 'Untitled',
            ($fields['mode'] ?? 'shuffle') === 'ordered' ? 'ordered' : 'shuffle',
            (string) ($fields['art_url'] ?? ''),
            $t,
            $t,
        ]);
        $id = (int) $pdo->lastInsertId();
        ensure_first_schedule_plan($stationId, $id);
        touch_station($stationId);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function delete_programme(int $id): void
{
    $row = programme($id);
    db()->prepare('DELETE FROM programmes WHERE id = ?')->execute([$id]);
    if ($row) {
        touch_station((string) $row['station_id']);
        forget_media((string) $row['station_id'], (string) ($row['art_url'] ?? ''));
    }
}

/** The running order, with each entry's track joined in. */
function programme_items(int $programmeId): array
{
    $stmt = db()->prepare(
        "SELECT i.id AS entry_id, i.uid, i.position, i.repeat_count,
                i.track_id AS source_track_id, i.collection_id,
                t.id, t.station_id, t.item_id, t.title, t.credit, t.description,
                t.media_url, t.page_url, t.art_url, t.duration_ms, t.tags, t.measured_at,
                c.name AS collection_name,
                (SELECT COUNT(*) FROM collection_tracks ct WHERE ct.collection_id = i.collection_id) AS collection_count,
                (SELECT COUNT(*) FROM collection_tracks ct JOIN tracks mt ON mt.id = ct.track_id
                  WHERE ct.collection_id = i.collection_id AND mt.duration_ms > 0 AND mt.media_url <> '') AS collection_playable_count
           FROM programme_items i
           LEFT JOIN tracks t ON t.id = i.track_id
           LEFT JOIN collections c ON c.id = i.collection_id
          WHERE i.programme_id = ? ORDER BY i.position, i.id"
    );
    $stmt->execute([$programmeId]);
    return $stmt->fetchAll();
}

function programme_length_ms(int $programmeId): int
{
    $total = 0;
    foreach (programme_items($programmeId) as $item) {
        if ($item['collection_id'] === null) {
            $total += (int) $item['duration_ms'] * max(1, (int) $item['repeat_count']);
        }
    }
    return $total;
}

function programme_is_ready(int $programmeId): bool
{
    $items = programme_items($programmeId);
    if (!$items) {
        return false;
    }
    foreach ($items as $item) {
        if ($item['collection_id'] !== null) {
            if ((int) $item['collection_playable_count'] <= 0) {
                return false;
            }
        } elseif ((int) $item['duration_ms'] <= 0) {
            return false;
        }
    }
    return true;
}

function add_to_programme(int $programmeId, int $trackId): void
{
    $row = programme($programmeId);
    if ($row === null) {
        return;
    }
    $next = db()->prepare('SELECT COALESCE(MAX(position), 0) + 1 FROM programme_items WHERE programme_id = ?');
    $next->execute([$programmeId]);
    db()->prepare(
        'INSERT INTO programme_items (programme_id, track_id, position, uid) VALUES (?, ?, ?, ?)'
    )->execute([$programmeId, $trackId, (int) $next->fetchColumn(), bin2hex(random_bytes(8))]);
    touch_station((string) $row['station_id']);
}

function set_programme_repeat(int $programmeId, int $trackId, int $repeat): void
{
    $row = programme($programmeId);
    db()->prepare('UPDATE programme_items SET repeat_count = ? WHERE programme_id = ? AND track_id = ?')
        ->execute([max(1, min(20, $repeat)), $programmeId, $trackId]);
    if ($row) {
        touch_station((string) $row['station_id']);
    }
}

/* -------------------------------------------------------------- schedule */

/**
 * Every saved plan with its ordered programme-day choices, plus one-off day
 * overrides. A plan repeats its choices until a later plan takes over.
 *
 * @return array{plans: array<int, array>, overrides: array<int, array>}
 */
function schedule(string $stationId): array
{
    $plansStmt = db()->prepare(
        'SELECT * FROM schedule_plans WHERE station_id = ? ORDER BY starts_on, id'
    );
    $plansStmt->execute([$stationId]);
    $daysStmt = db()->prepare(
        'SELECT d.*, p.name AS programme_name, l.name AS playlist_name
           FROM schedule_plan_days d
           LEFT JOIN programmes p ON p.id = d.programme_id
           LEFT JOIN playlists l ON l.id = d.playlist_id
          WHERE d.plan_id = ? ORDER BY d.position, d.id'
    );
    $plans = [];
    foreach ($plansStmt->fetchAll() as $plan) {
        $daysStmt->execute([(int) $plan['id']]);
        $plan['days'] = [];
        foreach ($daysStmt->fetchAll() as $day) {
            $day['choice'] = schedule_choice($day['programme_id'], $day['playlist_id']);
            $day['label'] = choice_label($day['programme_name'], $day['playlist_name']);
            $plan['days'][] = $day;
        }
        $plans[(int) $plan['id']] = $plan;
    }

    $overrideStmt = db()->prepare(
        'SELECT o.*, p.name AS programme_name, l.name AS playlist_name
           FROM schedule_overrides o
           LEFT JOIN programmes p ON p.id = o.programme_id
           LEFT JOIN playlists l ON l.id = o.playlist_id
          WHERE o.station_id = ? ORDER BY o.day'
    );
    $overrideStmt->execute([$stationId]);
    $overrides = [];
    foreach ($overrideStmt->fetchAll() as $row) {
        $row['choice'] = schedule_choice($row['programme_id'], $row['playlist_id']);
        $row['label'] = choice_label($row['programme_name'], $row['playlist_name']);
        $overrides[(int) $row['day']] = $row;
    }
    return ['plans' => $plans, 'overrides' => $overrides];
}

/**
 * What a schedule entry names, written the way the publication writes it: a
 * programme's id ("12"), or a playlist to draw from ("playlist:3").
 */
function schedule_choice($programmeId, $playlistId): ?string
{
    if ($playlistId !== null && (int) $playlistId > 0) {
        return 'playlist:' . (int) $playlistId;
    }
    return $programmeId !== null && (int) $programmeId > 0 ? (string) (int) $programmeId : null;
}

/** How a schedule entry reads to the operator. */
function choice_label(?string $programmeName, ?string $playlistName): string
{
    return $playlistName !== null ? t('Random from {name}', ['name' => $playlistName]) : (string) $programmeName;
}

/**
 * A choice sent by a form, checked: a ready programme of this station, or one
 * of its playlists with something to draw. Null when it is neither.
 *
 * @return array{programme_id: ?int, playlist_id: ?int}|null
 */
function read_schedule_choice(string $stationId, string $raw): ?array
{
    if (preg_match('/^playlist:(\d+)$/', $raw, $match)) {
        $playlist = playlist((int) $match[1]);
        return $playlist !== null && $playlist['station_id'] === $stationId && playlist_pool((int) $playlist['id'])
            ? ['programme_id' => null, 'playlist_id' => (int) $playlist['id']]
            : null;
    }
    $programme = programme((int) $raw);
    return $programme !== null && $programme['station_id'] === $stationId && programme_is_ready((int) $programme['id'])
        ? ['programme_id' => (int) $programme['id'], 'playlist_id' => null]
        : null;
}

/**
 * Internal schedule choices may deliberately name an empty programme (the
 * first programme of a new station does), but they may never cross stations.
 * Form choices use read_schedule_choice(), which additionally requires ready
 * content.
 *
 * @return array{programme_id: ?int, playlist_id: ?int}|null
 */
function owned_schedule_choice(string $stationId, array $raw): ?array
{
    $programmeId = isset($raw['programme_id']) ? (int) $raw['programme_id'] : 0;
    $playlistId = isset($raw['playlist_id']) ? (int) $raw['playlist_id'] : 0;
    if (($programmeId > 0) === ($playlistId > 0)) {
        return null;
    }
    if ($programmeId > 0) {
        $programme = programme($programmeId);
        return $programme !== null && (string) $programme['station_id'] === $stationId
            ? ['programme_id' => $programmeId, 'playlist_id' => null]
            : null;
    }
    $playlist = playlist($playlistId);
    return $playlist !== null && (string) $playlist['station_id'] === $stationId
        ? ['programme_id' => null, 'playlist_id' => $playlistId]
        : null;
}

/** Save a complete repeating plan. The start day uniquely identifies a change. */
function save_schedule_plan(string $stationId, string $name, int $startsOn, array $choices): ?int
{
    $checked = [];
    foreach ($choices as $raw) {
        $choice = is_array($raw)
            ? owned_schedule_choice($stationId, $raw)
            : read_schedule_choice($stationId, (string) $raw);
        if ($choice === null) {
            return null;
        }
        $checked[] = $choice;
    }
    if (!$checked) {
        return null;
    }

    $t = now_ms();
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO schedule_plans (station_id, name, starts_on, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(station_id, starts_on) DO UPDATE
                SET name = excluded.name, updated_at = excluded.updated_at'
        )->execute([$stationId, trim($name), $startsOn, $t, $t]);
        $find = $pdo->prepare('SELECT id FROM schedule_plans WHERE station_id = ? AND starts_on = ?');
        $find->execute([$stationId, $startsOn]);
        $planId = (int) $find->fetchColumn();
        $pdo->prepare('DELETE FROM schedule_plan_days WHERE plan_id = ?')->execute([$planId]);
        $insert = $pdo->prepare(
            'INSERT INTO schedule_plan_days (plan_id, position, programme_id, playlist_id) VALUES (?, ?, ?, ?)'
        );
        foreach ($checked as $position => $choice) {
            $insert->execute([$planId, $position, $choice['programme_id'], $choice['playlist_id']]);
        }
        touch_station($stationId);
        if ($ownsTransaction) $pdo->commit();
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $planId;
}

function delete_schedule_plan(string $stationId, int $planId): void
{
    db()->prepare('DELETE FROM schedule_plans WHERE id = ? AND station_id = ?')->execute([$planId, $stationId]);
    touch_station($stationId);
}

/** Whether a programme or playlist is named directly by any saved schedule row. */
function schedule_uses_choice(string $stationId, ?int $programmeId, ?int $playlistId): bool
{
    $column = $playlistId !== null ? 'playlist_id' : 'programme_id';
    $value = $playlistId ?? $programmeId;
    if ($value === null) {
        return false;
    }
    $stmt = db()->prepare(
        "SELECT 1 FROM schedule_plan_days d JOIN schedule_plans p ON p.id = d.plan_id
          WHERE p.station_id = ? AND d.$column = ?
         UNION ALL
         SELECT 1 FROM schedule_overrides o WHERE o.station_id = ? AND o.$column = ?
         LIMIT 1"
    );
    $stmt->execute([$stationId, $value, $stationId, $value]);
    return $stmt->fetchColumn() !== false;
}

/** The active plan for a programme day, or null before the first plan. */
function schedule_plan_for_day(array $schedule, int $day): ?array
{
    $active = null;
    foreach ($schedule['plans'] as $plan) {
        if ((int) $plan['starts_on'] > $day) {
            break;
        }
        $active = $plan;
    }
    return $active;
}

/** Choice made by the active repeating plan before an override is applied. */
function schedule_plan_choice(array $schedule, int $day): ?string
{
    $plan = schedule_plan_for_day($schedule, $day);
    $count = count($plan['days'] ?? []);
    if ($plan === null || $count === 0) {
        return null;
    }
    $distance = $day - (int) $plan['starts_on'];
    $position = (($distance % $count) + $count) % $count;
    return $plan['days'][$position]['choice'] ?? null;
}

/**
 * The same cycle re-anchored on a later day. Saving this as a new plan changes
 * nothing by itself: its first row is exactly what the old plan would have
 * played that day, followed by the remaining positions in order.
 *
 * @return string[]
 */
function schedule_plan_choices_from(array $plan, int $day): array
{
    $choices = array_values(array_map(
        static fn (array $entry): string => (string) $entry['choice'],
        $plan['days'] ?? []
    ));
    $count = count($choices);
    if ($count === 0) {
        return [];
    }
    $distance = $day - (int) $plan['starts_on'];
    $offset = (($distance % $count) + $count) % $count;
    return array_merge(array_slice($choices, $offset), array_slice($choices, 0, $offset));
}

/** @param array{programme_id: ?int, playlist_id: ?int} $choice */
function set_schedule_override(string $stationId, int $day, array $choice): void
{
    $choice = owned_schedule_choice($stationId, $choice);
    if ($choice === null) {
        throw new InvalidArgumentException('schedule choice is not owned by this station');
    }
    db()->prepare(
        'INSERT INTO schedule_overrides (station_id, day, programme_id, playlist_id) VALUES (?, ?, ?, ?)
         ON CONFLICT(station_id, day) DO UPDATE
            SET programme_id = excluded.programme_id, playlist_id = excluded.playlist_id'
    )->execute([$stationId, $day, $choice['programme_id'], $choice['playlist_id']]);
    touch_station($stationId);
}

function clear_schedule_override(string $stationId, int $day): void
{
    db()->prepare('DELETE FROM schedule_overrides WHERE station_id = ? AND day = ?')->execute([$stationId, $day]);
    touch_station($stationId);
}

/** Give a station its useful one-day plan when its first programme is created. */
function ensure_first_schedule_plan(string $stationId, int $programmeId): void
{
    $exists = db()->prepare('SELECT 1 FROM schedule_plans WHERE station_id = ? LIMIT 1');
    $exists->execute([$stationId]);
    if ($exists->fetchColumn() !== false) {
        return;
    }
    $station = station($stationId);
    if ($station !== null) {
        save_schedule_plan($stationId, '', programme_day_of($station, now_ms()), [
            ['programme_id' => $programmeId, 'playlist_id' => null],
        ]);
    }
}

/** The programme day an instant belongs to: 24 hours from the station's start time. */
function programme_day_of(array $station, int $instantMs): int
{
    $sinceFirstStart = $instantMs - (int) $station['day_start_ms'];
    $day = intdiv($sinceFirstStart, DAY_MS);
    return $sinceFirstStart % DAY_MS < 0 ? $day - 1 : $day;
}

/** The instant a programme day begins. */
function programme_day_start(array $station, int $day): int
{
    return $day * DAY_MS + (int) $station['day_start_ms'];
}

/**
 * The programme on air at an exact instant: that day's override, or the choice
 * at the corresponding position in the active plan's repeating day cycle.
 */
function programme_for_instant(string $stationId, int $instantMs, ?array $schedule = null): ?array
{
    $station = station($stationId);
    if ($station === null) {
        return null;
    }
    $schedule ??= schedule($stationId);
    $day = programme_day_of($station, $instantMs);
    $choice = $schedule['overrides'][$day]['choice'] ?? schedule_plan_choice($schedule, $day);
    return programme_for_choice($station, $choice, $day);
}

/** The programme a choice plays on a given day, with the playlist it was drawn from. */
function programme_for_choice(array $station, ?string $choice, int $day): ?array
{
    if ($choice === null) {
        return null;
    }
    $playlist = null;
    if (str_starts_with($choice, 'playlist:')) {
        $playlist = playlist((int) substr($choice, strlen('playlist:')));
        $programmeId = $playlist === null ? null : playlist_pick($station, (int) $playlist['id'], $day);
    } else {
        $programmeId = (int) $choice;
    }
    $programme = $programmeId ? programme($programmeId) : null;
    if ($programme === null) {
        return null;
    }
    return $programme + [
        'programme_id'   => (int) $programme['id'],
        'programme_name' => (string) $programme['name'],
        'programme_mode' => (string) $programme['mode'],
        'playlist_id'    => $playlist === null ? null : (int) $playlist['id'],
        'playlist_name'  => $playlist['name'] ?? null,
    ];
}

/* ------------------------------------------------------------- playlists */

function playlists(string $stationId): array
{
    $stmt = db()->prepare(
        "SELECT l.*,
                (SELECT COUNT(*) FROM playlist_programmes x WHERE x.playlist_id = l.id) AS programme_count,
                COALESCE((SELECT GROUP_CONCAT(name, ', ') FROM (
                    SELECT p.name FROM playlist_programmes x JOIN programmes p ON p.id = x.programme_id
                     WHERE x.playlist_id = l.id ORDER BY p.name COLLATE NOCASE)), '') AS programme_names
           FROM playlists l WHERE l.station_id = ? ORDER BY l.name COLLATE NOCASE"
    );
    $stmt->execute([$stationId]);
    return $stmt->fetchAll();
}

function playlist(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM playlists WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** The programmes in a playlist, in the order the publication lists them. */
function playlist_programme_ids(int $playlistId): array
{
    $stmt = db()->prepare('SELECT programme_id FROM playlist_programmes WHERE playlist_id = ? ORDER BY programme_id');
    $stmt->execute([$playlistId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** Create a playlist, or replace one's name and programmes. Returns its id. */
function save_playlist(string $stationId, ?int $id, string $name, array $programmeIds): int
{
    $name = trim($name) !== '' ? trim($name) : 'Untitled';
    $now = now_ms();
    db()->beginTransaction();
    try {
        if ($id === null) {
            db()->prepare('INSERT INTO playlists (station_id, name, created_at, updated_at) VALUES (?, ?, ?, ?)')
                ->execute([$stationId, $name, $now, $now]);
            $id = (int) db()->lastInsertId();
        } else {
            $owned = playlist($id);
            if ($owned === null || (string) $owned['station_id'] !== $stationId) {
                throw new InvalidArgumentException('playlist is not owned by this station');
            }
            db()->prepare('UPDATE playlists SET name = ?, updated_at = ? WHERE id = ? AND station_id = ?')->execute([$name, $now, $id, $stationId]);
            db()->prepare('DELETE FROM playlist_programmes WHERE playlist_id = ?')->execute([$id]);
        }
        $add = db()->prepare('INSERT OR IGNORE INTO playlist_programmes (playlist_id, programme_id) VALUES (?, ?)');
        foreach ($programmeIds as $programmeId) {
            $programme = programme((int) $programmeId);
            if ($programme !== null && $programme['station_id'] === $stationId) {
                $add->execute([$id, (int) $programme['id']]);
            }
        }
        touch_station($stationId);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }
    return $id;
}

/** Delete a playlist after callers have made sure no schedule names it. */
function delete_playlist(int $id): void
{
    $row = playlist($id);
    if ($row === null) {
        return;
    }
    db()->prepare('DELETE FROM playlists WHERE id = ?')->execute([$id]);
    touch_station((string) $row['station_id']);
}

/**
 * The programmes a playlist can draw from: those with something to play, in
 * the publication's order. The engine skips the same ones.
 */
function playlist_pool(int $playlistId): array
{
    $stmt = db()->prepare(
        'SELECT x.programme_id FROM playlist_programmes x
          WHERE x.playlist_id = ? AND EXISTS (
                SELECT 1 FROM programme_items i JOIN tracks t ON t.id = i.track_id
                 WHERE i.programme_id = x.programme_id AND t.duration_ms > 0)
          ORDER BY x.programme_id'
    );
    $stmt->execute([$playlistId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/** The programme a playlist draws for one programme day, exactly as the engine draws it. */
function playlist_pick(array $station, int $playlistId, int $day): ?int
{
    $pool = playlist_pool($playlistId);
    if (!$pool) {
        return null;
    }
    $seed = panel_settings()['shuffleSeed'] . '·' . $station['id'];
    $draw = seeded_draw($seed . "\0playlist\0" . $playlistId . "\0" . $day);
    return $pool[(int) floor($draw * count($pool))];
}

/*
 * The engine's seeded draw (engine/schedule.js: fnv1a into sfc32, the first
 * twelve values thrown away), bit for bit, so the panel names the programme
 * every listener hears. Unsigned 32-bit arithmetic throughout.
 */

function imul32(int $a, int $b): int
{
    $a &= 0xFFFFFFFF;
    $b &= 0xFFFFFFFF;
    return ((((($a >> 16) * $b) & 0xFFFF) << 16) + ($a & 0xFFFF) * $b) & 0xFFFFFFFF;
}

/** FNV-1a over UTF-16 code units, as JavaScript strings are made of. */
function fnv1a32(string $text): int
{
    $h = 0x811c9dc5;
    foreach (unpack('n*', mb_convert_encoding($text, 'UTF-16BE', 'UTF-8')) ?: [] as $unit) {
        $h = imul32($h ^ $unit, 0x01000193);
    }
    return $h;
}

function seeded_draw(string $seedText): float
{
    [$a, $b, $c, $d] = [fnv1a32($seedText . "\0a"), fnv1a32($seedText . "\0b"), fnv1a32($seedText . "\0c"), fnv1a32($seedText . "\0d")];
    $next = static function () use (&$a, &$b, &$c, &$d): float {
        $t = ($a + $b) & 0xFFFFFFFF;
        $a = $b ^ ($b >> 9);
        $b = ($c + (($c << 3) & 0xFFFFFFFF)) & 0xFFFFFFFF;
        $c = (($c << 21) & 0xFFFFFFFF) | ($c >> 11);
        $d = ($d + 1) & 0xFFFFFFFF;
        $t = ($t + $d) & 0xFFFFFFFF;
        $c = ($c + $t) & 0xFFFFFFFF;
        return $t / 4294967296;
    };
    for ($i = 0; $i < 12; $i++) {
        $next();
    }
    return $next();
}

/* ------------------------------------------------------------------ tags */

/**
 * Tags are kept as one comma-separated string per track: lowercased, trimmed,
 * de-duplicated and sorted. A join table would be tidier on paper, but this is
 * a single-operator panel and one column keeps reading, writing and searching
 * a track to one row with no joins to get wrong.
 *
 * @param string|array $tags
 */
function canonical_tags($tags): string
{
    $list = is_array($tags) ? $tags : explode(',', (string) $tags);
    $clean = [];
    foreach ($list as $tag) {
        $tag = trim(mb_strtolower((string) $tag));
        $tag = preg_replace('/\s+/u', ' ', $tag) ?? '';
        if ($tag !== '' && mb_strlen($tag) <= 40) {
            $clean[$tag] = true;
        }
    }
    $clean = array_keys($clean);
    sort($clean, SORT_NATURAL);
    return implode(', ', $clean);
}

/** @return string[] */
function tag_list(string $tags): array
{
    return array_values(array_filter(array_map('trim', explode(',', $tags))));
}

/**
 * Every tag used in a station, with how many tracks carry it — the vocabulary
 * offered when tagging something new, so the same idea does not get three
 * spellings.
 *
 * @return array<string, int>
 */
function station_tags(string $stationId): array
{
    $counts = [];
    foreach (tracks($stationId) as $track) {
        foreach (tag_list($track['tags']) as $tag) {
            $counts[$tag] = ($counts[$tag] ?? 0) + 1;
        }
    }
    ksort($counts, SORT_NATURAL);
    return $counts;
}

/* ---------------------------------------------------------------- uploads */

const UPLOAD_EXTENSIONS = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus', 'webm'];

/**
 * Take one uploaded file into a station's media folder.
 *
 * The extension allowlist is the security boundary, not a convenience: this
 * folder is served by the web server, so anything executable landing in it
 * would be reachable. media/.htaccess refuses to hand those to a handler as
 * well — two independent checks, because one of them will eventually be
 * misconfigured.
 *
 * @param array $file one entry of $_FILES
 * @return string the stored filename
 * @throws RuntimeException with a message worth showing the operator
 */
function store_upload(string $stationId, array $file): string
{
    $errors = [
        UPLOAD_ERR_INI_SIZE   => t('that file is larger than this server accepts (upload_max_filesize)'),
        UPLOAD_ERR_FORM_SIZE  => t('that file is larger than the form allows'),
        UPLOAD_ERR_PARTIAL    => t('the upload was interrupted'),
        UPLOAD_ERR_NO_FILE    => t('no file was chosen'),
        UPLOAD_ERR_NO_TMP_DIR => t('the server has no temporary folder to write to'),
        UPLOAD_ERR_CANT_WRITE => t('the server could not write the file'),
        UPLOAD_ERR_EXTENSION  => t('a PHP extension blocked the upload'),
    ];
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code !== UPLOAD_ERR_OK) {
        throw new RuntimeException($errors[$code] ?? t('the upload failed'));
    }
    if (!is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException(t('that was not an uploaded file'));
    }

    $original = (string) ($file['name'] ?? '');
    $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($extension, UPLOAD_EXTENSIONS, true)) {
        throw new RuntimeException(
            t('only audio files are accepted here ({types})', ['types' => implode(', ', UPLOAD_EXTENSIONS)])
        );
    }

    // The original filename can carry a person's name, device or location.
    // Keep it out of the public media URL; the operator chooses the public title.
    $dir = station_media_dir($stationId);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException(t("could not create this station's media folder"));
    }

    // Never overwrite: a file already in a publication is one a listener may
    // be playing, and its measured duration is recorded against those bytes.
    do {
        $name = 'audio-' . bin2hex(random_bytes(6)) . '.' . $extension;
    } while (is_file($dir . '/' . $name));

    if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $name)) {
        throw new RuntimeException(t('could not save the file'));
    }
    @chmod($dir . '/' . $name, 0644);
    return $name;
}

/* ----------------------------------------------------------------- covers */

/** Covers are drawn at about a hundred pixels; this leaves room for sharp screens. */
const COVER_MAX_PX = 1000;

/** Larger than any real cover, small enough that decoding it cannot exhaust memory. */
const COVER_MAX_PIXELS = 40_000_000;

/**
 * Take an uploaded cover image into a station's media folder.
 *
 * The image is decoded and written out again rather than moved: that shrinks
 * an 8 MB phone photo to something a listener can afford to download, and it
 * means the bytes on disk were produced by GD, never by whoever sent them.
 *
 * @param array $file one entry of $_FILES
 * @return string the media URL, as the publication carries it
 * @throws RuntimeException with a message worth showing the operator
 */
function store_cover(string $stationId, array $file, string $label): string
{
    $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($code === UPLOAD_ERR_INI_SIZE || $code === UPLOAD_ERR_FORM_SIZE) {
        throw new RuntimeException(t('that image is larger than this server accepts'));
    }
    if ($code !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
        throw new RuntimeException(t('the image did not arrive'));
    }

    $path = (string) $file['tmp_name'];
    $info = @getimagesize($path);
    $types = [IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if ($info === false || !isset($types[$info[2]])) {
        throw new RuntimeException(t('a cover has to be a JPG, PNG or WebP image'));
    }
    [$width, $height] = $info;
    if ($width < 1 || $height < 1 || $width * $height > COVER_MAX_PIXELS) {
        throw new RuntimeException(t('that image is too large to use as a cover'));
    }

    ini_set('memory_limit', '512M');
    $source = match ($types[$info[2]]) {
        'jpeg' => @imagecreatefromjpeg($path),
        'png'  => @imagecreatefrompng($path),
        'webp' => @imagecreatefromwebp($path),
    };
    if (!$source) {
        throw new RuntimeException(t('that image could not be read'));
    }

    $scale = min(1, COVER_MAX_PX / max($width, $height));
    $w = max(1, (int) round($width * $scale));
    $h = max(1, (int) round($height * $scale));
    $image = imagecreatetruecolor($w, $h);
    // PNG keeps its transparency; everything else becomes a plain JPEG.
    $keepAlpha = $types[$info[2]] === 'png';
    if ($keepAlpha) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
    }
    imagecopyresampled($image, $source, 0, 0, 0, 0, $w, $h, $width, $height);
    imagedestroy($source);

    $dir = station_media_dir($stationId) . '/covers';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException(t("could not create this station's cover folder"));
    }
    // A fresh name every time: the old cover may still be on a listener's
    // screen, from a revision they have not left yet.
    $name = (slugify($label) ?: 'cover') . '-' . bin2hex(random_bytes(3)) . ($keepAlpha ? '.png' : '.jpg');
    $ok = $keepAlpha
        ? imagepng($image, $dir . '/' . $name, 7)
        : imagejpeg($image, $dir . '/' . $name, 86);
    imagedestroy($image);
    if (!$ok) {
        throw new RuntimeException(t('could not save the cover'));
    }
    @chmod($dir . '/' . $name, 0644);
    return station_media_url($stationId, 'covers/' . $name);
}

/**
 * What a form asked to happen to a cover: a new one, none, or no change.
 *
 * @return string|null the new media URL, '' to remove it, null to leave it
 * @throws RuntimeException when the chosen image cannot be used
 */
function cover_from_request(string $stationId, string $label, string $field = 'art'): ?string
{
    $file = $_FILES[$field] ?? [];
    if ((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        return store_cover($stationId, $file, $label);
    }
    return ($_POST[$field . '_remove'] ?? '') === '1' ? '' : null;
}

/* ----------------------------------------------------------------- media */

/**
 * Delete files of a station's that nothing uses any more.
 *
 * Only files inside the station's own media folder are touched, and only
 * when no track, station logo, station cover or programme cover still points at them. A URL that leads
 * anywhere else - another site, another folder - is left alone.
 */
function forget_media(string $stationId, string ...$urls): void
{
    $prefix = station_media_url($stationId, '');
    $inUse = db()->prepare(
        'SELECT 1 FROM tracks WHERE station_id = ? AND (media_url = ? OR art_url = ?)
         UNION ALL
         SELECT 1 FROM stations WHERE id = ? AND (art_url = ? OR logo_url = ?)
         UNION ALL
         SELECT 1 FROM programmes WHERE station_id = ? AND art_url = ?
         LIMIT 1'
    );
    foreach ($urls as $url) {
        if ($url === '' || !str_starts_with($url, $prefix)) {
            continue;
        }
        $rest = substr($url, strlen($prefix));
        if (!preg_match('#^(covers/)?[A-Za-z0-9][A-Za-z0-9._-]*$#', $rest)) {
            continue;
        }
        $inUse->execute([$stationId, $url, $url, $stationId, $url, $url, $stationId, $url]);
        if ($inUse->fetchColumn() !== false) {
            continue;
        }
        $path = station_media_dir($stationId) . '/' . $rest;
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

/** Remove a folder and everything in it. */
function remove_tree(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') {
            continue;
        }
        $path = $dir . '/' . $name;
        is_dir($path) && !is_link($path) ? remove_tree($path) : @unlink($path);
    }
    @rmdir($dir);
}

/** Where a station's own audio lives on disk. */
function station_media_dir(string $id): string
{
    return PANEL_ROOT . '/media/' . $id;
}

/** The media URL carried by the public feed, relative to the panel root. */
function station_media_url(string $id, string $filename): string
{
    return 'media/' . $id . '/' . $filename;
}

