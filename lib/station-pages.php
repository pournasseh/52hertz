<?php
/**
 * A station, and its smaller tabs: programmes, collections, playlists,
 * settings and player, each followed by the verbs its forms post to.
 *
 * The two large tabs have files of their own, library-pages.php and
 * schedule-pages.php, and so does the programme editor.
 */

declare(strict_types=1);

/** A time of day, as the panel shows it: UTC, with the operator's clock beside it. */
function time_of_day(int $ms): string
{
    return num(gmdate('H:i', intdiv($ms, 1000))) . ' UTC';
}

function local_time_of_day(int $ms): string
{
    return '<span data-local-clock="' . $ms . '">' . e(time_of_day($ms)) . '</span>';
}

function station_subtitle(array $station): string
{
    [$state, $label] = station_state($station);
    $lists = programmes($station['id']);
    $library = count(tracks($station['id']));

    return '<span class="pill pill-' . e($state) . '">' . e($label) . '</span>'
        . '<span class="sub-dot">·</span>' . e(tn(count($lists), '{n} programme', '{n} programmes'))
        . '<span class="sub-dot">·</span>' . e(tn($library, '{n} in the library', '{n} in the library'))
        . '<span class="sub-dot">·</span>' . h('programme day starts') . ' '
        . local_time_of_day((int) $station['day_start_ms'])
        // Drawn with its label from the start, so the tabs below do not jump
        // when now-playing.js fills in the track.
        . '<span class="now-line" data-now-playing="' . e(u('draft', ['id' => $station['id']])) . '">'
        . '<span class="now-label">' . h('Now playing') . '</span> <span class="muted">…</span></span>';
}

function page_station(string $id): void
{
    $station = route_station($id);
    match ((string) ($_GET['tab'] ?? '')) {
        'playlists' => station_playlists($station),
        'library'  => station_library($station),
        'collections' => station_collections($station),
        'schedule' => station_schedule($station),
        'settings' => station_settings($station),
        'player'   => station_player($station),
        'programme' => station_programme_editor($station),
        default    => station_programmes($station),
    };
}

/* ---------------------------------------------------------- programmes */

function station_programmes(array $station): void
{
    $id = $station['id'];
    $lists = programmes($id);
    $schedule = schedule($id);
    $onAirNow = programme_for_instant($id, now_ms(), $schedule);

    render([
        'title'    => $station['name'],
        'subtitle' => station_subtitle($station),
        'station'  => $station,
        'tab'      => '',
        'actions'  => '<button type="button" class="primary" data-modal-open="new-programme">'
            . icon('plus-lg') . ' ' . h('New programme') . '</button>',
    ], function () use ($id, $lists, $station, $onAirNow) { ?>

      <section class="card">
        <div class="card-head">
          <h2><?= h('Programmes') ?> <?= explain(t('A programme'), t('A programme fills one programme day: 24 hours from the time your station’s day starts. When its tracks run out before then, it plays them again; when they would run past it, the day ends wherever it has got to. The next programme day starts afresh.')) ?></h2>
          <span class="muted small"><?= num(count($lists)) ?></span>
        </div>

        <?php if (!$lists): ?>
          <p class="empty"><?= h('None yet. A programme is a running order; a station can have several, and the schedule decides which one runs on each programme day.') ?></p>
          <div class="empty-action"><button type="button" class="primary" data-modal-open="new-programme"><?= icon('plus-lg') ?> <?= h('New programme') ?></button></div>
        <?php else: ?>
          <table class="grid-table is-clickable fits-narrow">
            <thead>
              <tr><th><?= h('Name') ?></th><th class="num"><?= h('Tracks') ?></th><th class="num col-opt"><?= h('Length') ?></th>
                  <th class="col-opt"><?= h('Order') ?></th><th><?= h('State') ?></th></tr>
            </thead>
            <tbody>
            <?php foreach ($lists as $list): ?>
              <?php
              $length = programme_length_ms((int) $list['id']);
              $onAirProgrammeId = $onAirNow['programme_id'] ?? $onAirNow['id'] ?? null;
              $isOnAir = $onAirProgrammeId !== null && (int) $onAirProgrammeId === (int) $list['id'];
              ?>
              <tr data-href="<?= e(u('station', ['id' => $id, 'tab' => 'programme', 'programme' => $list['id']])) ?>" tabindex="0" role="link">
                <td><span class="strong"><?= e($list['name']) ?></span></td>
                <td class="num"><?= num((int) $list['item_count']) ?></td>
                <td class="num col-opt">
                  <?= ms_to_clock($length) ?>
                  <?php if ($length > DAY_MS): ?>
                    <span class="path bad"><?= h('longer than a day') ?></span>
                  <?php endif; ?>
                </td>
                <td class="muted col-opt"><?= $list['mode'] === 'ordered' ? h('in order') : h('shuffled') ?></td>
                <td><?= $isOnAir ? '<span class="pill pill-live">' . h('on air now') . '</span>' : '<span class="muted small">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>

      <dialog class="modal modal-wide" id="new-programme">
        <form method="post" action="<?= e(u('programme-create', ['id' => $id])) ?>" enctype="multipart/form-data" class="modal-card">
          <?= csrf_field() ?>
          <h2><?= h('New programme') ?></h2>
          <div class="modal-body">
            <p class="muted"><?= h("It starts empty. Add tracks to it from this station's library.") ?></p>
            <div class="cover-row">
              <?php cover_field('', 'new-programme-cover'); ?>
              <div class="cover-row-main">
                <label><?= h('Name') ?> <input name="name" placeholder="<?= h('Evening') ?>" required></label>
                <label for="new-programme-mode"><span><?= h('Order') ?> <?= explain(t('Shuffle or in order'), t('In order plays the tracks as you arrange them, top to bottom, then again from the top. Shuffle draws a new order for every pass, and every track has its turn before any comes round again. Either way every listener hears the same track at the same moment: the order comes from the clock, not from their device.')) ?></span>
                  <select name="mode" id="new-programme-mode">
                    <option value="shuffle"><?= h('shuffled each pass') ?></option>
                    <option value="ordered"><?= h('in a fixed order') ?></option>
                  </select>
                </label>
              </div>
            </div>
          </div>
          <div class="modal-actions">
            <button type="button" data-modal-close><?= h('Cancel') ?></button>
            <button type="submit" class="primary"><?= h('Create programme') ?></button>
          </div>
        </form>
      </dialog>
    <?php });
}

/** Every programme verb needs the same two things checked first. */
function route_programme(string $stationId): array
{
    require_post();
    require_csrf();
    route_station($stationId);
    $list = programme((int) ($_GET['programme'] ?? $_POST['programme_id'] ?? 0));
    if ($list === null || $list['station_id'] !== $stationId) {
        after_post(u('station', ['id' => $stationId]), t('That programme is not in this station.'), 'bad');
    }
    return $list;
}

function programme_url(string $stationId, int $programmeId): string
{
    return u('station', ['id' => $stationId, 'tab' => 'programme', 'programme' => $programmeId]);
}

function page_programme_create(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id]));
    }
    require_csrf();
    route_station($id);

    try {
        $art = (string) cover_from_request($id, (string) ($_POST['name'] ?? 'cover'));
    } catch (Throwable $err) {
        after_post(u('station', ['id' => $id]), t('Nothing was saved: {reason}', ['reason' => $err->getMessage()]), 'bad');
    }
    try {
        $programmeId = create_programme($id, (string) ($_POST['name'] ?? ''), [
            'mode'    => (string) ($_POST['mode'] ?? 'shuffle'),
            'art_url' => $art,
        ]);
    } catch (Throwable $err) {
        if ($art !== '') {
            try { forget_media($id, $art); } catch (Throwable) {}
        }
        after_post(u('station', ['id' => $id]), t('Nothing was saved: {reason}', ['reason' => $err->getMessage()]), 'bad');
    }
    after_station_change($id, programme_url($id, $programmeId), t('Created. Now add some tracks to it.'));
}

function page_programme_delete(string $id): void
{
    $list = route_programme($id);
    if (schedule_uses_choice($id, (int) $list['id'], null)) {
        after_post(programme_url($id, (int) $list['id']), t('This programme is used by a playback plan or override. Change the schedule before deleting it.'), 'bad');
    }
    delete_programme((int) $list['id']);
    after_station_change($id, u('station', ['id' => $id]), t('Deleted. Its tracks are still in the library.'));
}

/* --------------------------------------------------------- collections */

/** Named, reusable pools of tracks for programme rows and timed events. */
function station_collections(array $station): void
{
    $id = (string) $station['id'];
    $rows = collections($id);
    $library = tracks($id);
    $members = [];
    foreach ($rows as $row) {
        $members[(int) $row['id']] = collection_track_ids((int) $row['id']);
    }

    render([
        'title' => $station['name'], 'subtitle' => station_subtitle($station),
        'station' => $station, 'tab' => 'collections', 'script' => ['collections.js'],
        'actions' => '<button type="button" class="primary" data-collection-new' . ($library ? '' : ' disabled') . '>'
            . icon('plus-lg') . ' ' . h('New collection') . '</button>',
    ], function () use ($id, $rows, $library, $members) { ?>
      <section class="card">
        <div class="card-head"><div><h2><?= h('Collections') ?></h2>
          <p class="note"><?= h('A collection is a reusable pool of tracks. A programme draws one track from it each time the collection comes up.') ?></p></div>
          <span class="muted small"><?= num(count($rows)) ?></span></div>
        <?php if (!$library): ?>
          <p class="empty"><?= h('Add tracks to the library before creating a collection.') ?></p>
          <div class="empty-action"><a class="button primary" href="<?= e(u('station', ['id' => $id, 'tab' => 'library'])) ?>#add-track"><?= icon('plus-lg') ?> <?= h('Add track') ?></a></div>
        <?php elseif (!$rows): ?>
          <p class="empty"><?= h('No collections yet. Create one for a subject, format, speaker or any pool you want to draw from.') ?></p>
          <div class="empty-action"><button type="button" class="primary" data-collection-new><?= icon('plus-lg') ?> <?= h('New collection') ?></button></div>
        <?php else: ?>
          <table class="grid-table is-clickable fits-narrow">
            <thead><tr><th><?= h('Name') ?></th><th class="num"><?= h('Tracks') ?></th><th class="col-opt"><?= h('Contents') ?></th><th><?= h('State') ?></th></tr></thead>
            <tbody><?php foreach ($rows as $row): ?>
              <tr tabindex="0" data-collection-edit="<?= (int) $row['id'] ?>" data-name="<?= e($row['name']) ?>"
                  data-tracks="<?= e(implode(',', $members[(int) $row['id']])) ?>">
                <td><span class="strong"><?= e($row['name']) ?></span></td>
                <td class="num"><?= num((int) $row['track_count']) ?></td>
                <td class="muted col-opt"><?= $row['track_names'] !== '' ? e($row['track_names']) : '&mdash;' ?></td>
                <td><?= (int) $row['playable_count'] > 0
                    ? '<span class="pill pill-live">' . h('ready') . '</span>'
                    : '<span class="pill pill-draft">' . h('empty') . '</span>' ?></td>
              </tr>
            <?php endforeach; ?></tbody>
          </table>
        <?php endif; ?>
      </section>

      <?php if ($library): ?>
      <dialog class="modal modal-wide" id="collection-sheet">
        <form method="post" action="<?= e(u('collection-save', ['id' => $id])) ?>" class="modal-card">
          <?= csrf_field() ?><input type="hidden" name="collection_id">
          <h2 data-collection-title><?= h('New collection') ?></h2>
          <div class="modal-body">
            <label><?= h('Name') ?><input name="name" required maxlength="160" placeholder="<?= h('Family law') ?>"></label>
            <div class="collection-picker-controls">
              <span class="search-wrap"><?= icon('search', 'search-icon') ?><input type="search" class="search" data-collection-search placeholder="<?= h('Search tracks…') ?>"></span>
              <select data-collection-tag aria-label="<?= h('Filter by tag') ?>"><option value=""><?= h('All tags') ?></option>
                <?php foreach (station_tags($id) as $tag => $count): ?><option value="<?= e($tag) ?>"><?= e($tag) ?> (<?= num($count) ?>)</option><?php endforeach; ?>
              </select>
            </div>
            <div class="collection-picker-head"><span data-collection-picked></span><button type="button" class="quiet" data-collection-visible><?= h('Select visible') ?></button></div>
            <fieldset class="check-list collection-track-list">
              <legend><?= h('Tracks') ?></legend>
              <?php foreach ($library as $track): $title = $track['title'] !== '' ? $track['title'] : $track['item_id']; ?>
                <label class="check-row" data-collection-track data-search="<?= e(mb_strtolower($title . ' ' . $track['credit'] . ' ' . $track['tags'])) ?>" data-tags="<?= e($track['tags']) ?>">
                  <input type="checkbox" name="tracks[]" value="<?= (int) $track['id'] ?>">
                  <span class="check-name"><?= e($title) ?></span><span class="muted small"><?= e($track['credit']) ?></span>
                  <span class="choice-meta"><?= (int) $track['duration_ms'] > 0 ? ms_to_clock((int) $track['duration_ms']) : h('not ready') ?></span>
                </label>
              <?php endforeach; ?>
            </fieldset>
          </div>
          <div class="modal-actions modal-actions-split">
            <button type="submit" class="danger-button modal-danger" form="collection-delete" data-collection-delete hidden><?= icon('trash') ?> <?= h('Delete') ?></button>
            <button type="button" data-modal-close><?= h('Cancel') ?></button>
            <button type="submit" class="primary" data-collection-submit><?= h('Create collection') ?></button>
          </div>
        </form>
      </dialog>
      <form method="post" action="<?= e(u('collection-delete', ['id' => $id])) ?>" id="collection-delete" hidden
            data-confirm="<?= h('Delete this collection?') ?>"
            data-confirm-detail="<?= h('Its tracks stay in the library. A collection used by a programme must be replaced there first.') ?>"
            data-confirm-action="<?= h('Delete collection') ?>">
        <?= csrf_field() ?><input type="hidden" name="collection_id">
      </form>
      <?php endif; ?>
    <?php });
}

function page_collection_save(string $id): void
{
    if (!is_post()) redirect(u('station', ['id' => $id, 'tab' => 'collections']));
    require_csrf(); route_station($id);
    $back = u('station', ['id' => $id, 'tab' => 'collections']);
    $collectionId = (int) ($_POST['collection_id'] ?? 0);
    $existing = $collectionId > 0 ? collection($collectionId) : null;
    if ($collectionId > 0 && ($existing === null || $existing['station_id'] !== $id)) {
        after_post($back, t('That collection is not in this station.'), 'bad');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) after_post($back, t('Give this collection a name.'), 'bad');
    save_collection($id, $existing === null ? null : (int) $existing['id'], $name, (array) ($_POST['tracks'] ?? []));
    after_station_change($id, $back, $existing === null ? t('Collection created.') : t('Collection saved.'));
}

function page_collection_delete(string $id): void
{
    if (!is_post()) redirect(u('station', ['id' => $id, 'tab' => 'collections']));
    require_csrf(); route_station($id);
    $back = u('station', ['id' => $id, 'tab' => 'collections']);
    $row = collection((int) ($_POST['collection_id'] ?? 0));
    if ($row === null || $row['station_id'] !== $id) after_post($back, t('That collection is not in this station.'), 'bad');
    if (collection_is_used((int) $row['id'])) after_post($back, t('This collection is used by a programme. Replace it there before deleting it.'), 'bad');
    delete_collection((int) $row['id']);
    after_station_change($id, $back, t('Collection deleted.'));
}

/* ----------------------------------------------------------- playlists */

/**
 * A station's playlists. A playlist is a set of programmes; named in the
 * schedule as "Random from …", each day plays one of them, drawn at random.
 */
function station_playlists(array $station): void
{
    $id = (string) $station['id'];
    $rows = playlists($id);
    $lists = programmes($id);
    $members = [];
    foreach ($rows as $row) {
        $members[(int) $row['id']] = playlist_programme_ids((int) $row['id']);
    }

    render([
        'title'    => $station['name'],
        'subtitle' => station_subtitle($station),
        'station'  => $station,
        'tab'      => 'playlists',
        'actions'  => '<button type="button" class="primary" data-playlist-new' . ($lists ? '' : ' disabled') . '>'
            . icon('plus-lg') . ' ' . h('New playlist') . '</button>',
        'script'   => ['playlists.js'],
    ], function () use ($id, $rows, $lists, $members) { ?>

      <section class="card">
        <div class="card-head">
          <h2><?= h('Playlists') ?> <?= explain(t('Random from a playlist'), t('A playlist is a set of programmes. Choose “Random from” it in the schedule, and each programme day plays one of them, drawn at random; the same one can come up two days running. The Schedule tab shows which one today drew, and editing the playlist can change it.')) ?></h2>
          <span class="muted small"><?= num(count($rows)) ?></span>
        </div>
        <?php if (!$rows): ?>
          <p class="empty"><?= h('None yet. A playlist is a set of programmes: choose “Random from” it in the schedule, and each day plays one of them, drawn at random.') ?></p>
          <?php if ($lists): ?><div class="empty-action"><button type="button" class="primary" data-playlist-new><?= icon('plus-lg') ?> <?= h('New playlist') ?></button></div><?php endif; ?>
        <?php else: ?>
          <table class="grid-table is-clickable fits-narrow">
            <thead><tr><th><?= h('Name') ?></th><th><?= h('Programmes') ?></th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
              <tr tabindex="0" data-playlist-edit="<?= (int) $row['id'] ?>" data-name="<?= e($row['name']) ?>"
                  data-programmes="<?= e(implode(',', $members[(int) $row['id']])) ?>">
                <td><span class="strong"><?= e($row['name']) ?></span></td>
                <td class="muted"><?= $row['programme_names'] !== '' ? e($row['programme_names']) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </section>

      <dialog class="modal" id="playlist-sheet">
        <form method="post" action="<?= e(u('playlist-save', ['id' => $id])) ?>" class="modal-card">
          <?= csrf_field() ?>
          <input type="hidden" name="playlist_id" value="">
          <h2 data-playlist-title><?= h('New playlist') ?></h2>
          <div class="modal-body">
            <label><?= h('Name') ?> <input name="name" required maxlength="160" placeholder="<?= h('Weekend') ?>"></label>
            <fieldset class="check-list">
              <legend><?= h('Programmes') ?></legend>
              <?php foreach ($lists as $list): ?>
                <label class="check-row">
                  <input type="checkbox" name="programmes[]" value="<?= (int) $list['id'] ?>">
                  <span class="check-name"><?= e($list['name']) ?></span>
                  <?php if ((int) $list['item_count'] === 0): ?><span class="muted small"><?= h('empty, so never drawn') ?></span><?php endif; ?>
                </label>
              <?php endforeach; ?>
            </fieldset>
          </div>
          <div class="modal-actions modal-actions-split">
            <button type="submit" class="danger-button modal-danger" form="playlist-delete" data-playlist-delete hidden><?= icon('trash') ?> <?= h('Delete') ?></button>
            <button type="button" data-modal-close><?= h('Cancel') ?></button>
            <button type="submit" class="primary" data-playlist-submit><?= h('Create playlist') ?></button>
          </div>
        </form>
      </dialog>
      <form method="post" action="<?= e(u('playlist-delete', ['id' => $id])) ?>" id="playlist-delete" hidden
            data-confirm="<?= h('Delete this playlist?') ?>"
            data-confirm-detail="<?= h('Its programmes stay. A playlist used by the schedule must be replaced there first.') ?>"
            data-confirm-action="<?= h('Delete playlist') ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="playlist_id" value="">
      </form>
    <?php });
}

function page_playlist_save(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id, 'tab' => 'playlists']));
    }
    require_csrf();
    route_station($id);
    $back = u('station', ['id' => $id, 'tab' => 'playlists']);
    $playlistId = (int) ($_POST['playlist_id'] ?? 0);
    $existing = $playlistId > 0 ? playlist($playlistId) : null;
    if ($playlistId > 0 && ($existing === null || $existing['station_id'] !== $id)) {
        after_post($back, t('That playlist is not in this station.'), 'bad');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '' || mb_strlen($name) > 160) {
        after_post($back, t('Give this playlist a name.'), 'bad');
    }
    save_playlist($id, $existing === null ? null : (int) $existing['id'], $name, (array) ($_POST['programmes'] ?? []));
    after_station_change($id, $back, $existing === null ? t('Playlist created.') : t('Playlist saved.'));
}

function page_playlist_delete(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id, 'tab' => 'playlists']));
    }
    require_csrf();
    route_station($id);
    $back = u('station', ['id' => $id, 'tab' => 'playlists']);
    $playlist = playlist((int) ($_POST['playlist_id'] ?? 0));
    if ($playlist === null || $playlist['station_id'] !== $id) {
        after_post($back, t('That playlist is not in this station.'), 'bad');
    }
    if (schedule_uses_choice($id, null, (int) $playlist['id'])) {
        after_post($back, t('This playlist is used by a playback plan or override. Change the schedule before deleting it.'), 'bad');
    }
    delete_playlist((int) $playlist['id']);
    after_station_change($id, $back, t('Playlist deleted.'));
}

/* ------------------------------------------------------------ settings */

function station_settings(array $station): void
{
    $id = $station['id'];

    if (is_post()) {
        require_csrf();
        $dayStart = (string) ($_POST['day_start_ms'] ?? '');
        $fields = [];
        $newImages = [];
        try {
            $logo = cover_from_request($id, (string) ($_POST['name'] ?? $station['name']) . '-logo', 'logo');
            if ($logo !== null) {
                $fields['logo_url'] = $logo;
                if ($logo !== '') $newImages[] = $logo;
            }
            $art = cover_from_request($id, (string) ($_POST['name'] ?? $station['name']));
            if ($art !== null) {
                $fields['art_url'] = $art;
                if ($art !== '') $newImages[] = $art;
            }
        } catch (Throwable $err) {
            try { forget_media($id, ...$newImages); } catch (Throwable) {}
            after_post(u('station', ['id' => $id, 'tab' => 'settings']), t('Nothing was saved: {reason}', ['reason' => $err->getMessage()]), 'bad');
        }
        try {
            update_station($id, $fields + [
                'name'     => (string) ($_POST['name'] ?? $station['name']),
                'tagline'  => (string) ($_POST['tagline'] ?? ''),
                'accent'   => (string) ($_POST['accent'] ?? $station['accent']),
                'colophon' => (string) ($_POST['colophon'] ?? ''),
                'home_url' => (string) ($_POST['home_url'] ?? ''),
                'player_language' => language_exists((string) ($_POST['player_language'] ?? ''))
                    ? (string) $_POST['player_language'] : (string) $station['player_language'],
                'enabled'  => isset($_POST['enabled']) ? 1 : 0,
                'day_start_ms' => ctype_digit($dayStart)
                    ? (int) $dayStart % DAY_MS : (int) $station['day_start_ms'],
            ]);
        } catch (Throwable $err) {
            foreach ($newImages as $url) {
                try { forget_media($id, $url); } catch (Throwable) {}
            }
            after_post(u('station', ['id' => $id, 'tab' => 'settings']), t('Nothing was saved: {reason}', ['reason' => $err->getMessage()]), 'bad');
        }
        after_station_change($id, u('station', ['id' => $id, 'tab' => 'settings']));
    }

    render([
        'title'    => $station['name'],
        'subtitle' => station_subtitle($station),
        'station'  => $station,
        'tab'      => 'settings',
    ], function () use ($station, $id) { ?>

      <form method="post" action="<?= e(u('station', ['id' => $id, 'tab' => 'settings'])) ?>" class="card" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <h2><?= h('Identity') ?></h2>
        <div class="cover-row cover-row-settings">
          <?php cover_field(media_web_url($id, (string) $station['logo_url']), 'station-logo', 'logo', 'logo'); ?>
          <p class="hint"><?= h("The station's logo. It becomes the installed app icon and browser-tab icon; a square image works best.") ?></p>
        </div>
        <div class="cover-row cover-row-settings">
          <?php cover_field(media_web_url($id, (string) $station['art_url'])); ?>
          <p class="hint"><?= h("The station's cover. It is the player's backdrop and the fallback artwork for tracks; it is resized automatically.") ?></p>
        </div>
        <div class="field-grid">
          <label><?= h('Name') ?> <input name="name" value="<?= e($station['name']) ?>" required></label>
          <label><?= h('Tagline') ?> <input name="tagline" value="<?= e($station['tagline']) ?>" placeholder="<?= h('one line under the name') ?>"></label>
          <label><?= h('Accent') ?> <input name="accent" type="color" value="<?= e($station['accent']) ?>"></label>
          <label><?= h('Home link') ?> <input name="home_url" value="<?= e($station['home_url']) ?>" placeholder="https://…" dir="ltr"></label>
          <label><?= h('Player language') ?>
            <select name="player_language">
              <?php foreach (languages() as $code => $label): ?>
                <option value="<?= e($code) ?>"<?= $code === $station['player_language'] ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="hint"><?= h('The language its player speaks to listeners.') ?></span>
          </label>
          <label><?= h('Colophon') ?>
            <input name="colophon" value="<?= e($station['colophon']) ?>" placeholder="<?= h('the smallest text on the player') ?>">
          </label>
        </div>

        <h2><?= h('On air') ?></h2>
        <div class="field-grid">
          <label><?= h('Programme day starts at') ?>
            <input type="time" name="day_start_ms" class="local-time"
              data-utc="<?= (int) $station['day_start_ms'] ?>" required>
            <span class="hint"><?= h('The only schedule time. For example, a Saturday programme at 06:00 runs from Saturday 06:00 until Sunday 06:00.') ?></span>
          </label>
        </div>
        <label class="switch">
          <input type="checkbox" name="enabled" value="1" <?= (int) $station['enabled'] === 1 ? 'checked' : '' ?>>
          <span>
            <strong><?= h('This station is on air') ?></strong>
            <span class="hint"><?= h('Switch it off and players are updated automatically. Nothing is deleted, and switching it back on resumes wherever the clock has reached.') ?></span>
          </span>
        </label>

        <div class="form-actions"><button type="submit" class="primary"><?= h('Save settings') ?></button></div>
      </form>

      <section class="card danger-zone">
        <h2><?= h('Remove this station') ?></h2>
        <p class="muted"><?= h('The station is taken off air first, then its programmes, library and files are deleted.') ?></p>
        <form method="post" action="<?= e(u('station-remove', ['id' => $id])) ?>"
              data-confirm="<?= h('Delete {name}?', ['name' => $station['name']]) ?>"
              data-confirm-detail="<?= h('It is taken off air first, then its programmes, library and files are deleted.') ?>"
              data-confirm-action="<?= h('Delete station') ?>">
          <?= csrf_field() ?>
          <button type="submit" class="danger-button"><?= h('Delete station') ?></button>
        </form>
      </section>
    <?php });
}

/* -------------------------------------------------------------- player */

/**
 * The address listeners are given for a station. The clean form needs the
 * server to rewrite addresses (.htaccess); the plain one works on any host.
 * PHP cannot tell which this server does, so the Player tab asks from the
 * browser and shows the plain one where the clean one would not answer.
 */
function player_url(string $stationId, bool $clean = true): string
{
    return rtrim(panel_public_url(), '/') . '/player/' . ($clean ? '' : '?station=') . rawurlencode($stationId);
}

/** This station's MP3 stream, for an Icecast server; clean or plain as above. */
function station_stream_url(string $stationId, bool $clean = true): string
{
    return rtrim(panel_public_url(), '/') . ($clean
        ? '/streams/' . rawurlencode($stationId) . '.mp3'
        : '/index.php?p=stream&id=' . rawurlencode($stationId));
}

/**
 * A station's player: the link to give listeners, and what it plays at any
 * moment.
 */
function station_player(array $station): void
{
    $id = $station['id'];
    $url = player_url($id);
    $streamUrl = station_stream_url($id);
    $streamTools = media_tools_status();
    $streamPrerequisite = continuous_stream_prerequisite($streamTools);
    $streamActive = $streamPrerequisite === null && (int) ($station['revision'] ?? 0) > 0;
    // Seeing this tab is the last step of a new station's way to air. Not an
    // edit: the station itself has not changed, so nothing is republished.
    if ((int) $station['player_seen'] !== 1) {
        db()->prepare('UPDATE stations SET player_seen = 1 WHERE id = ?')->execute([$id]);
        $station['player_seen'] = 1;
    }

    render([
        'title'    => $station['name'],
        'subtitle' => station_subtitle($station),
        'station'  => $station,
        'tab'      => 'player',
        'script'   => 'preview.js',
    ], function () use ($id, $url, $streamUrl, $streamTools, $streamPrerequisite, $streamActive) { ?>
      <section class="card">
        <div class="card-head"><div>
          <h2><?= h('The link to this station') ?></h2>
          <p class="note"><?= h('Anyone with this link can listen: on your website, in a message, anywhere.') ?></p>
        </div></div>
        <div class="link-row">
          <input type="text" id="player-link" value="<?= e($url) ?>" data-plain="<?= e(player_url($id, false)) ?>" readonly dir="ltr" aria-label="<?= h('The link to this station') ?>">
          <button type="button" class="primary" data-copy="player-link" data-copied="<?= h('Link copied.') ?>"><?= h('Copy') ?></button>
          <a class="button" href="<?= e($url) ?>" data-plain="<?= e(player_url($id, false)) ?>" target="_blank" rel="noopener"><?= h('Open') ?></a>
        </div>
      </section>

      <section class="card stream-card">
        <div class="card-head">
          <div>
            <h2><?= h('Continuous stream') ?></h2>
            <p class="note"><?= h('The address you give an Icecast server, for the day you have one. Your listeners use the link above.') ?></p>
          </div>
          <span class="pill <?= $streamActive ? 'pill-good' : 'pill-bad' ?>"><?= h($streamActive ? 'Active' : 'Inactive') ?></span>
        </div>
        <div class="link-row">
          <input type="text" id="stream-link" value="<?= e($streamUrl) ?>" data-plain="<?= e(station_stream_url($id, false)) ?>" readonly dir="ltr" aria-label="<?= h('The reserved stream address') ?>"<?= $streamActive ? '' : ' disabled' ?>>
          <button type="button" data-copy="stream-link" data-copied="<?= h('Stream address copied.') ?>"<?= $streamActive ? '' : ' disabled' ?>><?= h('Copy stream URL') ?></button>
        </div>
        <?php if ($streamPrerequisite === 'process-blocked'): ?>
          <p class="flash flash-warn stream-status"><?= h('This stream is inactive because the host blocks PHP from starting FFmpeg.') ?></p>
        <?php elseif ($streamPrerequisite === 'ffmpeg-missing'): ?>
          <p class="flash flash-warn stream-status"><?= h('This stream is inactive because FFmpeg is not available to 52Hertz on this server.') ?></p>
        <?php elseif ($streamPrerequisite === 'mp3-encoder-missing'): ?>
          <p class="flash flash-warn stream-status"><?= h('This stream is inactive because this FFmpeg build has no MP3 encoder.') ?></p>
        <?php else: ?>
          <p class="flash flash-good stream-status"><?= h('The stream is ready with FFmpeg {version} and encoder {encoder}. Paste this address into an Icecast server and it will carry your station, with the name of whatever is playing.', [
              'version' => $streamTools['version'], 'encoder' => $streamTools['mp3Encoder'],
          ]) ?></p>
        <?php endif; ?>
        <p class="note stream-footnote"><?= h('Icecast opens one connection here and serves your listeners itself, however many there are. Nothing changes on this server as the audience grows.') ?></p>

        <?php if ($streamActive): ?>
          <details class="stream-reach">
            <summary><?= h('What this address is for') ?></summary>
            <div class="stream-reach-body">
              <p><?= h('Your listeners already have somewhere to listen: the link at the top of this page. It does not keep a PHP stream open per listener; normal web-host or CDN bandwidth and request limits still apply. This address is for something else — handing your station to an Icecast server, so it can also be heard in radio apps, in a car, and anywhere a plain stream is expected.') ?></p>
              <p><?= h('Icecast connects to this address once. Everything after that is its job: it serves the audience, and it is the address you give a radio directory. This one is not for sharing, and it answers at most {n} connections at a time for that reason.', ['n' => DIRECT_STREAM_MAX_CONNECTIONS]) ?></p>
              <p><?= h('Some hosts offer an Icecast server for a small monthly fee, and there are free community servers. Until you have one, nothing here is wasted: the station is already on air and already has a link.') ?></p>
              <p class="note"><?= h('If your panel is on https, check that your Icecast build can relay from an https source. Many can only relay plain http.') ?></p>
            </div>
          </details>
        <?php endif; ?>
      </section>

      <section class="card" id="preview" data-draft="<?= e(u('draft', ['id' => $id])) ?>">
        <div class="card-head">
          <h2><?= h('The station, at any moment') ?></h2>
          <label class="follow"><input type="checkbox" id="follow" checked> <?= h('follow the clock') ?></label>
        </div>
        <p class="note"><?= h('Worked out by the same engine every player runs, so this is what a listener would hear — including at moments years away.') ?></p>

        <div class="scrub">
          <div class="scrub-head">
            <strong id="at-time">—</strong>
            <span class="muted" id="at-offset"></span>
          </div>
          <input type="range" id="scrub" min="-3600" max="86400" step="1" value="0" aria-label="<?= h('time from now, in seconds') ?>">
          <div class="scrub-marks">
            <button type="button" data-jump="-3600" class="quiet"><?= h('an hour ago') ?></button>
            <button type="button" data-jump="0" class="quiet"><?= h('now') ?></button>
            <button type="button" data-jump="3600" class="quiet"><?= h('in an hour') ?></button>
            <button type="button" data-jump="86400" class="quiet"><?= h('tomorrow') ?></button>
            <button type="button" data-jump="31536000" class="quiet"><?= h('a year out') ?></button>
          </div>
        </div>

        <div class="resolved" id="resolved"><p class="empty"><?= h('Loading the station…') ?></p></div>

        <h3 class="section-title"><?= h('What follows') ?></h3>
        <ol class="upcoming" id="upcoming"></ol>

        <div id="issues"></div>
      </section>
      <script nonce="<?= e(csp_nonce()) ?>">
        // Asked from the browser, because only a request from outside shows
        // what the web server hands out. With addresses rewritten, PHP answers
        // the clean feed address in JSON, even for a station not yet on air;
        // without, the server answers it with its own not-found page, and the
        // clean links above would not work either, so the plain ones replace them.
        fetch(<?= json_encode('stations/' . rawurlencode($id) . '/feed.json') ?>, { method: 'HEAD', cache: 'no-store' })
          .then((response) => {
            if ((response.headers.get('Content-Type') || '').includes('json')) return;
            for (const el of document.querySelectorAll('[data-plain]')) {
              if (el.tagName === 'A') el.href = el.dataset.plain; else el.value = el.dataset.plain;
            }
          })
          .catch(() => {});
      </script>
    <?php });
}
