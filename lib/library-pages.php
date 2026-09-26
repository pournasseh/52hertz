<?php
/**
 * A station's library: its tab, the upload dialog and the track sheet (the
 * programme editor opens both as well), and the verbs they post to.
 */

declare(strict_types=1);

/* ------------------------------------------------------------- the tab */

/** Programme entries that still need browser measurement before they can air. */
function unmeasured_count(string $stationId): int
{
    $unmeasured = 0;
    foreach (programmes($stationId) as $list) {
        foreach (programme_items((int) $list['id']) as $item) {
            if ((int) $item['duration_ms'] <= 0) {
                $unmeasured++;
            }
        }
    }
    return $unmeasured;
}

/**
 * The library: everything this station has.
 *
 * One row per track, and clicking a row opens that track — where you can hear
 * it, see where it came from, retag it, and see which programmes use it. There
 * is one way audio gets in: you upload it.
 */
function station_library(array $station): void
{
    $id = $station['id'];
    $rows = tracks($id);
    $unmeasured = unmeasured_count($id);
    $vocabulary = station_tags($id);
    $collectionRows = collections($id);

    render([
        'title'    => $station['name'],
        'subtitle' => station_subtitle($station),
        'station'  => $station,
        'tab'      => 'library',
        'actions'  => '<button type="button" class="primary" data-modal-open="add-track">'
            . icon('plus-lg') . ' ' . h('Add track') . '</button>',
        'script'   => ['panel.js', 'library.js'],
    ], function () use ($id, $rows, $unmeasured, $vocabulary, $collectionRows) { ?>

      <section class="card">
        <div class="card-head">
          <h2><?= h('Everything this station has') ?></h2>
          <?php if ($unmeasured): ?>
            <span class="pill pill-draft" id="measure-state"><?= h('{n} to measure', ['n' => $unmeasured]) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($rows): ?>
          <div class="library-controls">
            <span class="search-wrap"><?= icon('search', 'search-icon') ?>
            <input type="search" id="library-search" class="search"
                   placeholder="<?= h('Search titles, credits, descriptions and tags…') ?>" autocomplete="off"
                   aria-label="<?= h('Filter the library') ?>"></span>
            <select id="tag-filter" class="library-tag-filter" aria-label="<?= h('Filter by tag') ?>">
              <option value=""><?= h('All tags') ?> (<?= num(count($rows)) ?>)</option>
              <?php foreach ($vocabulary as $tag => $count): ?><option value="<?= e($tag) ?>"><?= e($tag) ?> (<?= num($count) ?>)</option><?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <?php if (!$rows): ?>
          <p class="empty"><?= h("Nothing here yet. Add track uploads audio into this station's library.") ?></p>
          <div class="empty-action"><button type="button" class="primary" data-modal-open="add-track"><?= icon('plus-lg') ?> <?= h('Add track') ?></button></div>
        <?php else: ?>
          <!-- Appears once something is selected: one action for all of it. -->
          <div class="bulk-bar" id="bulk-bar" hidden>
            <span class="bulk-count"><?= h_html('{count} selected', ['count' => '<strong id="bulk-count">0</strong>']) ?></span>
            <button type="button" class="primary" data-modal-open="bulk-collection"><?= icon('collection') ?> <?= h('Add to collection') ?></button>
            <button type="button" data-modal-open="bulk-tags"><?= icon('tag') ?> <?= h('Edit tags') ?></button>
            <form method="post" action="<?= e(u('track-bulk', ['id' => $id])) ?>" class="inline bulk-form" id="bulk-delete"
                  data-confirm="<?= h('Delete the selected tracks?') ?>"
                  data-confirm-detail="<?= h('They leave every programme, and their audio files and covers are deleted.') ?>"
                  data-confirm-action="<?= h('Delete') ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <button type="submit" class="danger-button"><?= icon('trash') ?> <?= h('Delete') ?></button>
            </form>
            <button type="button" class="quiet bulk-clear" id="bulk-clear"><?= h('Clear selection') ?></button>
          </div>

          <table class="grid-table tracks is-clickable fits-narrow" id="tracks" data-station="<?= e($id) ?>"
                 data-endpoint="<?= e(u('measure')) ?>">
            <thead>
              <tr>
                <th class="check-col"><input type="checkbox" id="select-all" aria-label="<?= h('Select every track shown') ?>"></th>
                <th class="num col-opt">#</th><th><?= h('Title') ?></th><th class="col-opt"><?= h('Credit') ?></th><th class="col-opt"><?= h('Tags') ?></th>
                 <th class="num"><?= h('Length') ?></th><th class="col-opt"><?= h('Programmes') ?></th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $i => $t): ?>
              <?php $tags = tag_list($t['tags']); ?>
              <tr data-track="<?= (int) $t['id'] ?>"
                  data-media="<?= e(basename($t['media_url'])) ?>"
                  data-audio="<?= e(media_web_url($id, $t['media_url'])) ?>"
                  data-duration="<?= (int) $t['duration_ms'] ?>"
                  data-length="<?= e((int) $t['duration_ms'] > 0 ? ms_to_clock((int) $t['duration_ms']) : t('not measured yet')) ?>"
                  data-title="<?= e($t['title']) ?>"
                  data-credit="<?= e($t['credit']) ?>"
                  data-description="<?= e($t['description']) ?>"
                  data-art="<?= e(media_web_url($id, $t['art_url'])) ?>"
                  data-tags="<?= e($t['tags']) ?>"
                  data-programme-count="<?= (int) $t['programme_count'] ?>"
                  data-programmes="<?= e($t['programme_names']) ?>"
                  data-collection-count="<?= (int) $t['collection_count'] ?>"
                  data-collections="<?= e($t['collection_names']) ?>"
                  data-added="<?= e($t['measured_at'] ? (string) (int) $t['measured_at'] : '') ?>"
                  data-search="<?= e(mb_strtolower($t['title'] . ' ' . $t['credit'] . ' ' . $t['description'] . ' ' . $t['tags'] . ' ' . basename($t['media_url']))) ?>"
                  tabindex="0">
                <td class="check-col">
                  <input type="checkbox" class="row-check" value="<?= (int) $t['id'] ?>"
                         aria-label="<?= h('Select {name}', ['name' => $t['title'] !== '' ? $t['title'] : $t['item_id']]) ?>">
                </td>
                <td class="num muted row-number col-opt"><?= num($i + 1) ?></td>
                <td>
                  <div class="track-cell">
                    <span class="thumb">
                      <?php if ($t['art_url'] !== ''): ?>
                        <img src="<?= e(media_web_url($id, $t['art_url'])) ?>" alt="" loading="lazy">
                      <?php else: ?>
                        <?= icon('music-note-list') ?>
                      <?php endif; ?>
                    </span>
                    <span>
                      <span class="strong"><?= e($t['title'] !== '' ? $t['title'] : $t['item_id']) ?></span>
                      <span class="path col-opt"><?= e(basename($t['media_url'])) ?></span>
                      <?php if ($t['credit'] !== ''): ?>
                        <span class="sub-line only-narrow"><?= e($t['credit']) ?></span>
                      <?php endif; ?>
                    </span>
                  </div>
                </td>
                <td class="muted col-opt"><?= e($t['credit']) ?></td>
                <td class="tags-cell col-opt">
                  <?php foreach ($tags as $tag): ?>
                    <span class="tag"><?= e($tag) ?></span>
                  <?php endforeach; ?>
                  <?php if (!$tags): ?><span class="muted small">—</span><?php endif; ?>
                </td>
                <td class="num duration">
                  <?php if ((int) $t['duration_ms'] > 0): ?>
                    <?= ms_to_clock((int) $t['duration_ms']) ?>
                  <?php else: ?>
                    <span class="measuring"><?= h('measuring…') ?></span>
                  <?php endif; ?>
                </td>
                <td class="muted col-opt">
                  <?= (int) $t['programme_count'] > 0
                      ? e($t['programme_names'])
                      : '<span class="muted small">&mdash;</span>' ?>
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <p class="empty" id="no-matches" hidden><?= h('Nothing in the library matches that.') ?></p>
        <?php endif; ?>

      </section>

      <?php tag_picker_template($vocabulary); ?>

      <dialog class="modal" id="bulk-tags">
        <form method="post" action="<?= e(u('track-bulk', ['id' => $id])) ?>" class="modal-card bulk-form">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="tags">
          <h2><?= h_html('Tags for {count} tracks', ['count' => '<span data-bulk-count>0</span>']) ?></h2>
          <div class="modal-body">
            <?php tag_field($vocabulary, 'bulk-add-tags', 'add_tags', t('Add'),
                t('Added to every selected track, next to the tags it already has.')); ?>
            <?php if ($vocabulary): ?>
              <div class="bulk-remove" id="bulk-remove">
                <span class="field-label"><?= h('Remove') ?></span>
                <div class="tag-checks">
                  <?php foreach (array_keys($vocabulary) as $tag): ?>
                    <label class="tag-check" data-tag="<?= e($tag) ?>">
                      <input type="checkbox" name="remove_tags[]" value="<?= e($tag) ?>"> <?= e($tag) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
                <span class="hint"><?= h('Only tags the selected tracks carry are shown.') ?></span>
              </div>
            <?php endif; ?>
          </div>
          <div class="modal-actions">
            <button type="button" data-modal-close><?= h('Cancel') ?></button>
            <button type="submit" class="primary"><?= h('Apply') ?></button>
          </div>
        </form>
      </dialog>

        <dialog class="modal" id="bulk-collection">
          <form method="post" action="<?= e(u('track-bulk', ['id' => $id])) ?>" class="modal-card bulk-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="collection">
            <h2><?= h_html('Add {count} tracks to a collection', ['count' => '<span data-bulk-count>0</span>']) ?></h2>
            <div class="modal-body">
              <?php if ($collectionRows): ?>
                <div class="segmented" role="tablist"><button type="button" class="seg is-active" data-pane="existing-collection"><?= h('Existing') ?></button><button type="button" class="seg" data-pane="new-collection"><?= h('New collection') ?></button></div>
                <div class="pane is-active" id="existing-collection"><label><?= h('Collection') ?><select name="collection_id"><?php foreach ($collectionRows as $collection): ?><option value="<?= (int) $collection['id'] ?>"><?= e($collection['name']) ?></option><?php endforeach; ?></select></label></div>
                <div class="pane" id="new-collection"><label><?= h('Collection name') ?><input name="new_collection_name" maxlength="160" placeholder="<?= h('Family law') ?>"></label></div>
              <?php else: ?>
                <label><?= h('Collection name') ?><input name="new_collection_name" required maxlength="160" placeholder="<?= h('Family law') ?>"></label>
                <p class="hint"><?= h('Your first collection will be created with the selected tracks.') ?></p>
              <?php endif; ?>
            </div>
            <div class="modal-actions">
              <button type="button" data-modal-close><?= h('Cancel') ?></button>
              <button type="submit" class="primary"><?= h('Add to collection') ?></button>
            </div>
          </form>
        </dialog>

      <?php track_upload_dialog($id, $vocabulary); ?>
      <?php track_sheet_dialog($id, $vocabulary); ?>
    <?php });
}

/** Where a track's audio sits, as a panel-root-relative or absolute URL. */
function media_web_url(string $stationId, string $mediaUrl): string
{
    return $mediaUrl;
}

/* ----------------------------------------------------- upload and edit */

/** Tells a library form which programme page to return to, if any. */
function programme_return_field(int $programmeId): string
{
    return $programmeId > 0 ? '<input type="hidden" name="programme" value="' . $programmeId . '">' : '';
}

/**
 * Upload a track into the library. Rendered by the library, and by a
 * programme's page so a track can be added without leaving it; given a
 * programme, the upload returns there.
 */
function track_upload_dialog(string $id, array $vocabulary, int $programmeId = 0): void
{
    $mediaTools = media_tools_status();
    ?>
    <dialog class="modal" id="add-track">
      <form method="post" action="<?= e(u('track-upload', ['id' => $id])) ?>"
            enctype="multipart/form-data" class="modal-card">
        <?= csrf_field() ?>
        <?= programme_return_field($programmeId) ?>
        <h2><?= h('Add a track') ?></h2>
        <div class="modal-body">
          <label class="dropzone" id="dropzone">
            <input type="file" name="audio" accept="audio/*" required>
            <span class="dropzone-face">
              <strong data-role="headline"><?= h('Drop an audio file here') ?></strong>
              <span class="hint" data-role="detail"><?= h('or click to choose one') ?></span>
            </span>
          </label>
          <p class="hint"><?= h('MP3 gives listeners the widest browser support. Other accepted audio formats may not play on every device.') ?></p>
          <?php if ($mediaTools['state'] === 'available' && $mediaTools['mp3Encoder'] !== ''): ?>
            <p class="flash flash-warn media-tool-note"><?= h('FFmpeg {version} is available, but automatic cleanup is not enabled yet. Audio is still published unchanged; remove identifying metadata from sensitive recordings.', ['version' => $mediaTools['version']]) ?></p>
          <?php elseif ($mediaTools['state'] === 'available'): ?>
            <p class="flash flash-warn media-tool-note"><?= h('FFmpeg was found, but this build has no MP3 encoder. Uploads still work and are published unchanged.') ?></p>
          <?php elseif ($mediaTools['state'] === 'blocked'): ?>
            <p class="flash flash-warn media-tool-note"><?= h('This host blocks PHP from starting FFmpeg. This upload still works, but automatic metadata cleanup and format conversion are unavailable.') ?></p>
          <?php else: ?>
            <p class="flash flash-warn media-tool-note"><?= h('FFmpeg is not available here. This upload still works, but automatic metadata cleanup and format conversion are unavailable. Remove identifying metadata from sensitive recordings.') ?></p>
          <?php endif; ?>
          <div class="cover-row">
            <?php cover_field('', 'add-track-cover'); ?>
            <div class="cover-row-main">
              <label><?= h('Title') ?> <input name="title" placeholder="<?= h('Shown as “Untitled track” if left empty') ?>"></label>
              <label><?= h('Credit') ?> <input name="credit" placeholder="<?= h('Poet, performer, source') ?>"></label>
            </div>
          </div>
          <label><?= h('Description') ?>
            <textarea name="description" rows="3" placeholder="<?= h('A few lines about this piece — the poem, the recording, the story behind it') ?>"></textarea>
          </label>
          <?php tag_field($vocabulary); ?>
        </div>
        <div class="modal-actions">
          <button type="button" data-modal-close><?= h('Cancel') ?></button>
          <button type="submit" class="primary"><?= h('Add to library') ?></button>
        </div>
      </form>
    </dialog>
    <?php
}

/** One track's sheet: hear it, see where it came from, change it. */
function track_sheet_dialog(string $id, array $vocabulary, int $programmeId = 0): void
{
    ?>
    <dialog class="modal modal-wide" id="track-sheet">
      <div class="modal-card">
        <div class="sheet-head">
          <div>
            <h2 id="sheet-title">&nbsp;</h2>
            <p class="muted" id="sheet-credit"></p>
          </div>
          <span class="pill" id="sheet-state"></span>
        </div>
        <div class="modal-body">
          <audio id="sheet-audio" controls preload="none"></audio>

          <dl class="facts">
            <div><dt><?= h('Length') ?></dt><dd id="sheet-length">—</dd></div>
            <div><dt><?= h('Used in programmes') ?></dt><dd id="sheet-programmes">—</dd></div>
            <div><dt><?= h('In collections') ?></dt><dd id="sheet-collections">—</dd></div>
            <div>
              <dt><?= h('File') ?></dt>
              <dd><span id="sheet-file" class="mono">—</span>
                <button type="button" class="quiet replace-audio" id="sheet-replace"><?= icon('arrow-repeat') ?> <?= h('Replace') ?></button>
              </dd>
            </div>
            <div><dt><?= h('Measured') ?></dt><dd id="sheet-added">—</dd></div>
          </dl>

          <form id="track-edit-form" method="post" action="<?= e(u('track-update', ['id' => $id])) ?>" class="sheet-form" enctype="multipart/form-data">
            <?= csrf_field() ?>
          <?= programme_return_field($programmeId) ?>
            <input type="hidden" name="track_id" id="sheet-track-id">
            <div class="cover-row">
              <?php cover_field('', 'sheet-cover'); ?>
              <div class="cover-row-main">
                <label><?= h('Title') ?> <input name="title" id="sheet-edit-title"></label>
                <label><?= h('Credit') ?> <input name="credit" id="sheet-edit-credit"></label>
              </div>
            </div>
            <label><?= h('Description') ?>
              <textarea name="description" id="sheet-edit-description" rows="3" placeholder="<?= h('A few lines about this piece') ?>"></textarea>
            </label>
            <?php tag_field($vocabulary, 'sheet-tags'); ?>
          </form>

          <form id="track-replace-form" method="post" action="<?= e(u('track-replace', ['id' => $id])) ?>"
                enctype="multipart/form-data" class="sheet-delete"
                data-confirm="<?= h("Replace this track's audio?") ?>"
                data-confirm-detail="<?= h('The new file takes its place everywhere, keeping the title, cover, tags and programmes. The old file is deleted.') ?>"
                data-confirm-action="<?= h('Replace audio') ?>">
            <?= csrf_field() ?>
          <?= programme_return_field($programmeId) ?>
            <input type="hidden" name="track_id" id="sheet-replace-id">
            <input type="file" name="audio" accept="audio/*" id="sheet-replace-file">
          </form>

          <form id="track-delete-form" method="post" action="<?= e(u('track-remove', ['id' => $id])) ?>" class="sheet-delete"
                data-confirm="<?= h('Delete this from the library?') ?>"
                data-confirm-detail="<?= h('It leaves every programme, and its audio file and cover are deleted.') ?>"
                data-confirm-action="<?= h('Delete from library') ?>">
            <?= csrf_field() ?>
          <?= programme_return_field($programmeId) ?>
            <input type="hidden" name="track_id" id="sheet-delete-id">
          </form>
        </div>

        <div class="modal-actions modal-actions-split">
          <button class="quiet danger modal-danger" type="submit" form="track-delete-form"><?= icon('trash') ?> <?= h('Delete from library') ?></button>
          <button type="button" data-modal-close><?= h('Close') ?></button>
          <button type="submit" class="primary" form="track-edit-form"><?= h('Save') ?></button>
        </div>
      </div>
    </dialog>
    <?php
}

function tag_field(array $vocabulary, string $inputId = '', string $name = 'tags', ?string $label = null,
                   ?string $hint = null): void
{
    $label ??= t('Tags');
    $hint ??= t('Comma separated. Used for searching the library, not shown to listeners.');
    $inputId = $inputId ?: 'tags-' . bin2hex(random_bytes(3));
    ?>
    <label><?= e($label) ?>
      <input name="<?= e($name) ?>" id="<?= e($inputId) ?>" class="tag-input" list="known-tags"
             placeholder="<?= h('doom, persian, instrumental') ?>" autocomplete="off">
      <span class="hint"><?= e($hint) ?></span>
    </label>
    <?php if ($vocabulary): ?>
      <div class="tag-suggestions" data-for="<?= e($inputId) ?>">
        <?php foreach (array_slice(array_keys($vocabulary), 0, 12) as $tag): ?>
          <button type="button" class="tag-chip" data-add-tag="<?= e($tag) ?>"><?= e($tag) ?></button>
        <?php endforeach; ?>
      </div>
    <?php endif;
}

/** The vocabulary, once per page, for every tags input to autocomplete against. */
function tag_picker_template(array $vocabulary): void
{
    ?>
    <datalist id="known-tags">
      <?php foreach (array_keys($vocabulary) as $tag): ?>
        <option value="<?= e($tag) ?>"></option>
      <?php endforeach; ?>
    </datalist>
    <?php
}

/* ----------------------------------------------------------- the verbs */

/**
 * Where a change to the library sends the browser afterwards: back to the
 * programme it was made from, when it was made from one, otherwise the library.
 */
function library_return(string $id): string
{
    $programme = programme((int) ($_POST['programme'] ?? 0));
    return $programme !== null && $programme['station_id'] === $id
        ? programme_url($id, (int) $programme['id'])
        : u('station', ['id' => $id, 'tab' => 'library']);
}

/**
 * Take an uploaded file into the station's own folder and put it in the
 * library in one step - the common case should not be two.
 */
function page_track_upload(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id]));
    }
    require_csrf();
    route_station($id);

    $title = trim((string) ($_POST['title'] ?? ''));
    $publicTitle = $title !== '' ? $title : t('Untitled track');
    $art = null;
    $audioUrl = '';
    try {
        // Covers are decoded before audio is moved. If anything after either
        // write fails, both fresh files are removed before the error returns.
        $art = cover_from_request($id, $title !== '' ? $title : 'cover');
        $name = store_upload($id, $_FILES['audio'] ?? []);
        $audioUrl = station_media_url($id, $name);
        add_track($id, [
            'title'       => $publicTitle,
            'credit'      => (string) ($_POST['credit'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'media_url'   => $audioUrl,
            'art_url'     => (string) $art,
            'tags'        => (string) ($_POST['tags'] ?? ''),
        ]);
    } catch (Throwable $err) {
        try { forget_media($id, (string) $art, $audioUrl); } catch (Throwable) {}
        after_post(library_return($id), t('Nothing was uploaded: {reason}', ['reason' => $err->getMessage()]), 'bad');
    }

    after_station_change(
        $id,
        library_return($id),
        t('Added “{name}”. Its length is being measured.', ['name' => $publicTitle])
    );
}

function page_track_update(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id]));
    }
    require_csrf();
    route_station($id);

    $trackId = (int) ($_POST['track_id'] ?? 0);
    $row = track($trackId);
    if ($row === null || $row['station_id'] !== $id) {
        after_post(u('station', ['id' => $id]), t('That track is not in this station.'), 'bad');
    }

    $fields = [];
    try {
        $art = cover_from_request($id, (string) ($_POST['title'] ?? $row['title']));
        if ($art !== null) {
            $fields['art_url'] = $art;
        }
    } catch (Throwable $err) {
        after_post(library_return($id), t('Nothing was saved: {reason}', ['reason' => $err->getMessage()]), 'bad');
    }

    try {
        update_track($trackId, $fields + [
            'title'       => (string) ($_POST['title'] ?? ''),
            'credit'      => (string) ($_POST['credit'] ?? ''),
            'description' => (string) ($_POST['description'] ?? ''),
            'tags'        => (string) ($_POST['tags'] ?? ''),
        ]);
    } catch (Throwable $err) {
        if (isset($fields['art_url']) && $fields['art_url'] !== '') {
            try { forget_media($id, (string) $fields['art_url']); } catch (Throwable) {}
        }
        after_post(library_return($id), t('Nothing was saved: {reason}', ['reason' => $err->getMessage()]), 'bad');
    }
    after_station_change($id, library_return($id));
}

/**
 * Give a track a new audio file. Its title, description, cover, tags and
 * place in every programme stay; its length is measured again.
 */
function page_track_replace(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id]));
    }
    require_csrf();
    route_station($id);
    $back = library_return($id);

    $row = track((int) ($_POST['track_id'] ?? 0));
    if ($row === null || $row['station_id'] !== $id) {
        after_post($back, t('That track is not in this station.'), 'bad');
    }
    $audioUrl = '';
    try {
        $name = store_upload($id, $_FILES['audio'] ?? []);
        $audioUrl = station_media_url($id, $name);
        replace_track_audio((int) $row['id'], $audioUrl);
    } catch (Throwable $err) {
        try { forget_media($id, $audioUrl); } catch (Throwable) {}
        after_post($back, t('The audio was not replaced: {reason}', ['reason' => $err->getMessage()]), 'bad');
    }
    $title = $row['title'] !== '' ? $row['title'] : $row['item_id'];
    after_station_change($id, $back, t('Replaced the audio of “{name}”. Its length is being measured.', ['name' => $title]));
}

function page_track_remove(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id]));
    }
    require_csrf();
    route_station($id);
    if (!delete_track($id, (int) ($_POST['track_id'] ?? 0))) {
        after_post(library_return($id), t('That track is not in this station.'), 'bad');
    }
    after_station_change($id, library_return($id), t('Deleted from the library.'));
}

/**
 * One action on several library tracks at once: tag them, untag them, put
 * them in a collection, or delete them.
 */
function page_track_bulk(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id]));
    }
    require_csrf();
    route_station($id);
    $back = u('station', ['id' => $id, 'tab' => 'library']);

    $rows = [];
    foreach ((array) ($_POST['track_ids'] ?? []) as $trackId) {
        $row = track((int) $trackId);
        if ($row !== null && $row['station_id'] === $id) {
            $rows[(int) $row['id']] = $row;
        }
    }
    if (!$rows) {
        after_post($back, t('Nothing was selected.'), 'bad');
    }
    $tracks = tn(count($rows), '{n} track', '{n} tracks');

    switch ((string) ($_POST['action'] ?? '')) {
        case 'tags':
            $add = tag_list(canonical_tags((string) ($_POST['add_tags'] ?? '')));
            $drop = tag_list(canonical_tags(implode(',', (array) ($_POST['remove_tags'] ?? []))));
            if (!$add && !$drop) {
                after_post($back, t('No tags were given to add or remove.'), 'bad');
            }
            foreach ($rows as $trackId => $row) {
                $tags = array_diff(array_unique([...tag_list($row['tags']), ...$add]), $drop);
                update_track($trackId, ['tags' => implode(',', $tags)]);
            }
            after_station_change($id, $back, t('Updated the tags of {tracks}.', ['tracks' => $tracks]));

        case 'collection':
            $newName = trim((string) ($_POST['new_collection_name'] ?? ''));
            if ($newName !== '') {
                if (mb_strlen($newName) > 160) after_post($back, t('Give this collection a shorter name.'), 'bad');
                $collectionId = save_collection($id, null, $newName, array_keys($rows));
                $collection = collection($collectionId);
            } else {
                $collection = collection((int) ($_POST['collection_id'] ?? 0));
                if ($collection === null || $collection['station_id'] !== $id) {
                    after_post($back, t('Choose a collection of this station.'), 'bad');
                }
                add_tracks_to_collection($id, (int) $collection['id'], array_keys($rows));
            }
            after_station_change($id, $back, t('Added {tracks} to “{name}”.', ['tracks' => $tracks, 'name' => $collection['name']]));

        case 'delete':
            foreach (array_keys($rows) as $trackId) {
                delete_track($id, $trackId);
            }
            after_station_change($id, $back, t('Deleted {tracks}, and their files.', ['tracks' => $tracks]));
    }
    after_post($back, t('That action is not one the library knows.'), 'bad');
}

/**
 * The browser reporting a file's real length. It is the only thing here that
 * can decode media, so it measures and the panel records whole milliseconds.
 */
function page_measure(): void
{
    require_post();
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        json_out(['error' => 'expected a JSON body'], 400);
    }
    $_POST['csrf'] = $body['csrf'] ?? '';
    require_csrf();

    $trackId = (int) ($body['track_id'] ?? 0);
    $ms = (int) round((float) ($body['duration_ms'] ?? 0));
    $row = track($trackId);
    if ($row === null) {
        json_out(['error' => 'no such track'], 404);
    }
    if ($ms <= 0) {
        json_out(['error' => 'that file reported no length'], 422);
    }
    update_track($trackId, ['duration_ms' => $ms]);
    $sync = sync_station((string) $row['station_id']);
    json_out([
        'ok'         => true,
        'trackId'    => $trackId,
        'durationMs' => $ms,
        'live'       => $sync['ok'],
        'liveError'  => $sync['ok'] ? null : ($sync['error'] ?? 'unknown error'),
    ]);
}
