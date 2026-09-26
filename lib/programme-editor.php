<?php
declare(strict_types=1);

function programme_editor_data(array $station, array $programme): array
{
    $items = [];
    foreach (programme_items((int) $programme['id']) as $entry) {
        for ($n = 0; $n < max(1, (int) $entry['repeat_count']); $n++) {
            $item = ['uid' => ($entry['uid'] ?: $entry['entry_id'] . ':0') . ($n ? ':' . $n : '')];
            if ($entry['collection_id'] !== null) {
                $item['collectionId'] = (int) $entry['collection_id'];
            } else {
                $item['trackId'] = (int) $entry['id'];
            }
            $items[] = $item;
        }
    }
    $library = [];
    foreach (tracks($station['id']) as $track) {
        $library[] = ['id' => (int) $track['id'], 'itemId' => $track['item_id'], 'title' => $track['title'],
            'credit' => $track['credit'], 'description' => $track['description'], 'durationMs' => (int) $track['duration_ms'],
            'tags' => $track['tags'], 'url' => media_web_url($station['id'], $track['media_url']),
            'media' => basename($track['media_url']), 'measuredAt' => (int) ($track['measured_at'] ?? 0),
            'programmeCount' => (int) $track['programme_count'], 'programmeNames' => $track['programme_names'],
            'collectionCount' => (int) $track['collection_count'], 'collectionNames' => $track['collection_names'],
            'art' => $track['art_url'] ? media_web_url($station['id'], $track['art_url']) : ''];
    }
    $collectionRows = [];
    foreach (collections($station['id']) as $collection) {
        $collectionRows[] = [
            'id' => (int) $collection['id'],
            'name' => (string) $collection['name'],
            'tracks' => collection_track_ids((int) $collection['id'], true),
            'trackCount' => (int) $collection['track_count'],
            'playableCount' => (int) $collection['playable_count'],
        ];
    }
    return ['id' => (int) $programme['id'], 'stationId' => $station['id'], 'version' => (int) $programme['updated_at'],
        'name' => $programme['name'], 'mode' => $programme['mode'],
        'items' => $items, 'events' => json_decode($programme['events'] ?? '[]', true) ?: [], 'library' => $library,
        'collections' => $collectionRows,
        'shuffleSeed' => panel_settings()['shuffleSeed'] . '·' . $station['id'],
        'dayStartMs' => (int) $station['day_start_ms'], 'serverNowMs' => now_ms(),
        'art' => $programme['art_url'] !== '' ? media_web_url($station['id'], $programme['art_url']) : '',
        'saveUrl' => u('programme-save', ['id' => $station['id'], 'programme' => $programme['id']]),
        'measureUrl' => u('measure'),
        'editorUrl' => programme_url($station['id'], (int) $programme['id']),
        'onAir' => (int) (programme_for_instant($station['id'], now_ms())['programme_id'] ?? 0) === (int) $programme['id']];
}

function station_programme_editor(array $station): void
{
    $programme = programme((int) ($_GET['programme'] ?? 0));
    if (!$programme || $programme['station_id'] !== $station['id']) { page_not_found(t('That programme')); return; }
    $data = programme_editor_data($station, $programme);
    $vocabulary = station_tags($station['id']);
    $subtitle = '<a href="' . e(u('station', ['id' => $station['id']])) . '">' . h('All programmes') . '</a>'
        . ($data['onAir'] ? ' <span class="pill pill-live">' . h('on air now') . '</span>' : '');
    render([
        'title'    => $programme['name'],
        'subtitle' => $subtitle,
        'station'  => $station,
        'tab'      => '',
        // Saving here does not reload the page, so the guide would go stale.
        'guide'    => false,
        'script'   => ['programme.js'],
        'actions'  => '<div class="studio-actions">'
            . '<button type="button" data-modal-open="add-track">' . icon('cloud-arrow-up') . ' ' . h('Upload track') . '</button>'
            . '<button type="button" id="add-event">' . icon('plus-lg') . ' ' . h('Add event') . '</button>'
            . '<button type="button" id="open-library">' . icon('plus-lg') . ' ' . h('Add content') . '</button>'
            . '<button type="button" class="primary studio-save" id="programme-save" disabled>' . h('Save changes') . '</button>'
            . '</div>',
    ], function () use ($data, $station, $programme, $vocabulary) { ?>
      <link rel="stylesheet" href="assets/css/programme.css">
      <section id="programme-studio" class="programme-studio">
        <script type="application/json" id="programme-data" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
        <div id="programme-csrf" hidden><?= csrf_field() ?></div>

        <!-- The settings, folded away, then the day; everything below the day
             is what it is made of. -->
        <details class="card studio-options">
          <summary><?= h('Programme settings') ?></summary>
          <div class="studio-options-body">
            <?php cover_field($data['art'], 'programme-cover'); ?>
            <div class="studio-options-fields">
              <label><?= h('Programme name') ?> <input id="programme-name" maxlength="160" value="<?= e($programme['name']) ?>"></label>
            </div>
          </div>
          <div class="studio-options-foot">
            <a href="<?= e(u('station', ['id' => $station['id'], 'tab' => 'schedule'])) ?>"><?= h('Choose when this programme plays') ?> →</a>
            <form method="post" action="<?= e(u('programme-delete', ['id' => $station['id'], 'programme' => $programme['id']])) ?>"
                  data-confirm="<?= h('Delete this programme?') ?>"
                  data-confirm-detail="<?= h('The tracks stay in the library. A programme used by the schedule must be replaced there first.') ?>"
                  data-confirm-action="<?= h('Delete programme') ?>">
              <?= csrf_field() ?>
              <button type="submit" class="danger-button"><?= icon('trash') ?> <?= h('Delete programme') ?></button>
            </form>
          </div>
        </details>

        <section class="card studio-day">
          <div class="programme-tl-toolbar">
            <div class="studio-modes" role="group" aria-label="<?= h('Timeline zoom') ?>">
              <button type="button" data-zoom="24">24h</button>
              <button type="button" data-zoom="6">6h</button>
              <button type="button" data-zoom="1">1h</button>
              <button type="button" data-zoom="0.5">30m</button>
              <button type="button" data-zoom="0.25">15m</button>
            </div>
            <strong id="day-range"></strong>
            <div class="programme-tl-legend">
              <span><i class="legend-track"></i><?= h('Track') ?></span>
              <span><i class="legend-event"></i><?= h('Event') ?></span>
              <span><i class="legend-exact"></i><?= h('Exact') ?></span>
            </div>
          </div>
          <div class="programme-tl" id="programme-tl" dir="ltr" tabindex="0">
            <div class="programme-minimap-wrap">
              <canvas id="timeline-overview" height="28" aria-label="<?= h('Overview of the full day') ?>"></canvas>
              <div id="timeline-window" class="programme-minimap-window"></div>
            </div>
            <div class="programme-ruler" id="detail-ruler"></div>
            <div class="programme-lane"><div class="programme-lane-label"><?= h('Planned') ?></div><canvas id="timeline-planned" height="46"></canvas></div>
            <div class="programme-lane"><div class="programme-lane-label"><?= h('On air') ?></div><canvas id="timeline-detail" height="58" aria-label="<?= h('Detailed programme timeline') ?>"></canvas></div>
            <div class="timeline-now" id="timeline-now" hidden><span><?= h('now') ?></span></div>
            <div class="programme-tl-tip" id="timeline-tip" hidden></div>
          </div>
          <div id="day-selection" class="day-selection" aria-live="polite"></div>
        </section>

        <section class="card studio-events">
          <div class="card-head"><h2><?= h('At a set time') ?></h2></div>
          <div id="programme-events"></div>
        </section>

        <section class="card studio-sequence">
          <div class="card-head">
            <div>
              <h2 id="sequence-heading"><?= h('Running order') ?></h2>
              <p id="sequence-summary" class="muted small"></p>
            </div>
            <div class="studio-order">
              <div class="studio-modes" role="group" aria-label="<?= h('Playback order') ?>">
                <button type="button" data-mode="ordered"><?= h('In order') ?></button>
                <button type="button" data-mode="shuffle"><?= h('Shuffle') ?></button>
              </div>
              <?= explain(t('Shuffle or in order'), t('In order plays the tracks as you arrange them, top to bottom, then again from the top. Shuffle draws a new order for every pass, and every track has its turn before any comes round again. Either way every listener hears the same track at the same moment: the order comes from the clock, not from their device.')) ?>
            </div>
          </div>
          <div class="sequence-columns" aria-hidden="true"><span class="col-grip"></span><span>#</span><span></span><span></span><span><?= h('Track') ?></span><span><?= h('Duration') ?></span><span><?= h('Per day') ?></span><span></span></div>
          <div id="programme-entries" role="list" aria-label="<?= h('Running order') ?>"></div>
          <div class="sequence-foot"><span><?= h('Repeats until the next programme.') ?></span><span id="sequence-total"></span></div>
        </section>

        <audio id="programme-audio" preload="none"></audio>

        <dialog class="modal pick-dialog" id="library-dialog" aria-labelledby="library-title">
          <div class="modal-card">
            <div class="sheet-head">
              <div>
                <h2 id="library-title"><?= h('Add content') ?></h2>
                <p id="library-insertion" class="muted"></p>
              </div>
            </div>
            <div class="modal-body">
              <span class="search-wrap pick-search"><?= icon('search', 'search-icon') ?>
                <input type="search" id="programme-search" class="search" placeholder="<?= h('Search tracks, collections or tags…') ?>"
                       autocomplete="off" aria-label="<?= h('Search content') ?>"></span>
              <div id="programme-library" class="pick-list" role="listbox" aria-multiselectable="true" aria-labelledby="library-title"></div>
            </div>
            <div class="modal-actions">
              <span id="library-selected-count" class="pick-count"></span>
              <button type="button" data-library-close><?= h('Cancel') ?></button>
              <button type="button" class="primary" id="add-selected" disabled><?= h('Add selected') ?></button>
            </div>
          </div>
        </dialog>

        <dialog class="modal" id="event-dialog"><form id="event-form" class="modal-card"><h2><?= h('Timed event') ?></h2><div class="modal-body"><label><?= h('Content') ?><select id="event-track" required></select><span class="hint"><?= h('Choose one track, or draw from a collection.') ?></span></label><div class="field-grid"><label><?= h('Repeat') ?><select id="event-repeat"><option value="daily"><?= h('Once a day') ?></option><option value="hourly"><?= h('Every hour') ?></option></select></label><label><span id="event-time-label"><?= h('Time') ?></span><input type="time" id="event-time" required value="12:00"></label></div><label for="event-timing"><span><?= h('Timing') ?> <?= explain(t('After the current track, or exactly on time'), t('After the current track: the event waits for the track that is playing to end, so it can start a little late, but nothing is cut. Exactly on time: it starts on the minute, and whatever is playing then is cut off. Use it for what has to be on time, such as the news.')) ?></span><select id="event-timing"><option value="soft"><?= h('After the current track') ?></option><option value="strict"><?= h('Exactly on time') ?></option></select></label><p class="note" id="event-timing-note"></p></div><div class="modal-actions"><button type="button" data-modal-close><?= h('Cancel') ?></button><button type="submit" class="primary"><?= h('Keep event') ?></button></div></form></dialog>
      </section>

      <?php tag_picker_template($vocabulary); ?>
      <?php track_upload_dialog($station['id'], $vocabulary, (int) $programme['id']); ?>
      <?php track_sheet_dialog($station['id'], $vocabulary, (int) $programme['id']); ?>
    <?php });
}

function page_programme_save(string $stationId): void
{
    $programme = route_programme($stationId);
    $input = json_decode((string) ($_POST['document'] ?? ''), true);
    if (!is_array($input) || !is_array($input['items'] ?? null) || count($input['items']) > 3000) json_out(['error' => t('Invalid programme.')], 422);
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160 || !in_array($input['mode'] ?? '', ['ordered','shuffle'], true)) json_out(['error' => t('Give this programme a name.')], 422);
    $library = array_column(tracks($stationId), null, 'id');
    $collectionRows = array_column(collections($stationId), null, 'id');
    $seen = [];
    foreach ($input['items'] as $entry) {
        $uid = (string) ($entry['uid'] ?? '');
        $trackId = isset($entry['trackId']) ? (int) $entry['trackId'] : 0;
        $collectionId = isset($entry['collectionId']) ? (int) $entry['collectionId'] : 0;
        $validSource = ($trackId > 0) !== ($collectionId > 0);
        if (!preg_match('/^[a-zA-Z0-9:_-]{1,90}$/', $uid) || isset($seen[$uid]) || !$validSource
            || ($trackId > 0 && !isset($library[$trackId]))
            || ($collectionId > 0 && !isset($collectionRows[$collectionId]))) {
            json_out(['error' => t('A track or collection is no longer available. Reload before saving.')], 422);
        }
        $seen[$uid] = true;
    }
    $events = $input['events'] ?? [];
    if (!is_array($events) || count($events) > 100) json_out(['error' => t('Invalid events.')], 422);
    $eventKeys = [];
    foreach ($events as $event) {
        $key = ($event['repeat'] ?? '') . ':' . ($event['timeMs'] ?? '');
        $trackId = isset($event['trackId']) ? (int) $event['trackId'] : 0;
        $collectionId = isset($event['collectionId']) ? (int) $event['collectionId'] : 0;
        $validSource = ($trackId > 0) !== ($collectionId > 0)
            && ($trackId > 0 ? isset($library[$trackId]) : isset($collectionRows[$collectionId]));
        if (!$validSource || !in_array($event['repeat'] ?? '', ['daily','hourly'], true) || !in_array($event['timing'] ?? '', ['soft','strict'], true) || !is_int($event['timeMs'] ?? null) || $event['timeMs'] < 0 || $event['timeMs'] >= ($event['repeat'] === 'hourly' ? 3600000 : DAY_MS) || isset($eventKeys[$key])) json_out(['error' => t('Choose distinct, valid times for your events.')], 422);
        $eventKeys[$key] = true;
    }
    try {
        // A new cover, '' to remove it, or null to keep the one it has.
        $cover = cover_from_request($stationId, $name);
    } catch (Throwable $e) {
        json_out(['error' => t('Nothing was saved: {reason}', ['reason' => $e->getMessage()])], 422);
    }
    db()->beginTransaction();
    try {
        $latest = programme((int) $programme['id']);
        if ((int) ($input['version'] ?? 0) !== (int) $latest['updated_at']) {
            db()->rollBack();
            if ($cover) forget_media($stationId, $cover);
            json_out(['error' => t('This programme changed in another tab. Reload to see it.')], 409);
        }
        $art = $cover ?? (string) $latest['art_url'];
        db()->prepare('DELETE FROM programme_items WHERE programme_id = ?')->execute([$programme['id']]);
        $insert = db()->prepare('INSERT INTO programme_items(programme_id,track_id,collection_id,position,repeat_count,uid) VALUES (?,?,?,?,1,?)');
        foreach ($input['items'] as $i => $entry) {
            $insert->execute([
                $programme['id'],
                isset($entry['trackId']) ? (int) $entry['trackId'] : null,
                isset($entry['collectionId']) ? (int) $entry['collectionId'] : null,
                $i,
                $entry['uid'],
            ]);
        }
        $version = max(now_ms(), (int) $latest['updated_at'] + 1);
        db()->prepare('UPDATE programmes SET name=?,mode=?,events=?,art_url=?,updated_at=? WHERE id=?')->execute([$name, $input['mode'], json_encode($events), $art, $version, $programme['id']]);
        touch_station($stationId);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        if ($cover) {
            try { forget_media($stationId, $cover); } catch (Throwable) {}
        }
        throw $e;
    }
    if ($art !== $latest['art_url']) {
        try { forget_media($stationId, (string) $latest['art_url']); } catch (Throwable) {}
    }
    $sync = sync_station($stationId);
    json_out(['ok' => true, 'version' => $version, 'art' => $art !== '' ? media_web_url($stationId, $art) : '',
        'live' => $sync['ok'], 'error' => $sync['error'] ?? null]);
}
