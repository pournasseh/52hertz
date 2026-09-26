<?php
/**
 * The panel's pages that are not a station's tabs: signing in, the list of
 * stations, and the panel's own settings.
 *
 * One function per page. GET pages render; POST pages do the work, leave a
 * message and redirect, so a reload never repeats an action.
 */

declare(strict_types=1);

/* ------------------------------------------------------------- sign in */

function page_login(): void
{
    if (is_owner()) {
        redirect(u());
    }

    $error = null;
    if (is_post()) {
        require_csrf();
        if (attempt_login((string) ($_POST['password'] ?? ''))) {
            redirect(u());
        }
        $error = t('That password did not match.');
    }

    render_bare(t('Sign in'), function () use ($error) { ?>
      <div class="signin-card">
        <img class="signin-logo" src="assets/img/logo-v.png" alt="<?= e(PRODUCT_NAME) ?>" width="333" height="92">
        <p class="muted"><?= h('One operator. Every saved change is carried to listeners automatically.') ?></p>
        <?php if ($error): ?><p class="flash flash-bad"><?= e($error) ?></p><?php endif; ?>
        <form method="post" action="<?= e(u('login')) ?>" class="stack">
          <?= csrf_field() ?>
          <label for="password"><?= h('Owner password') ?></label>
          <input id="password" name="password" type="password" autocomplete="current-password" required autofocus>
          <button type="submit" class="primary"><?= h('Sign in') ?></button>
        </form>
        <details class="signin-forgot">
          <summary><?= h('Forgot the password?') ?></summary>
          <p><?= h('With your host’s file manager, create an empty file named reset-password in the panel’s var folder, then reload this page. You will be asked for a new password; nothing else changes.') ?></p>
        </details>
      </div>
    <?php });
}

function page_logout(): void
{
    if (is_post()) {
        require_csrf();
        logout();
    }
    redirect(u('login'));
}

/* ----------------------------------------------------------- dashboard */

function page_stations(): void
{
    $list = stations();

    render([
        'title'    => t('Stations'),
        'subtitle' => e(tn(count($list), '{n} station', '{n} stations')),
    ], function () use ($list) { ?>

      <?php if (!$list): ?>
        <div class="card empty-state">
          <h2><?= h('Nothing on air yet') ?></h2>
          <p class="muted"><?= h('Start with a station. Give it some audio, put that audio in a programme, and schedule the programme.') ?></p>
          <button type="button" class="primary" data-modal-open="new-station"><?= icon('plus-lg') ?> <?= h('New station') ?></button>
        </div>
      <?php else: ?>
        <table class="grid-table is-clickable fits-narrow">
          <thead>
            <tr><th><?= h('Station') ?></th><th><?= h('Now playing') ?></th><th class="num col-opt"><?= h('Library') ?></th>
                <th class="num col-opt"><?= h('Programmes') ?></th><th><?= h('State') ?></th></tr>
          </thead>
          <tbody>
          <?php foreach ($list as $s): ?>
            <?php [$state, $label] = station_state($s); ?>
            <tr data-href="<?= e(u('station', ['id' => $s['id']])) ?>" tabindex="0" role="link">
              <td>
                <div class="track-cell">
                  <span class="thumb">
                    <?php if ($s['art_url'] !== ''): ?>
                      <img src="<?= e(media_web_url((string) $s['id'], $s['art_url'])) ?>" alt="" loading="lazy">
                    <?php else: ?>
                      <?= icon('broadcast-pin') ?>
                    <?php endif; ?>
                  </span>
                  <span class="strong"><?= e($s['name']) ?></span>
                </div>
              </td>
              <td class="now-cell" data-now-playing="<?= e(u('draft', ['id' => $s['id']])) ?>">
                <span class="muted">—</span>
              </td>
              <td class="num col-opt"><?= num((int) $s['track_count']) ?></td>
              <td class="num col-opt"><?= num((int) $s['programme_count']) ?></td>
              <td><span class="pill pill-<?= e($state) ?>"><?= e($label) ?></span></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>

    <?php });
}

function page_station_create(): void
{
    if (!is_post()) {
        redirect(u());
    }
    require_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    if ($name === '') {
        after_post(u(), t('A station needs a name.'), 'bad');
    }
    // The address goes into the station's public link and its folders, so it
    // is checked here as well as in the form, and never quietly changed.
    $id = strtolower(trim((string) ($_POST['id'] ?? '')));
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $id) || strlen($id) < 2 || strlen($id) > 40) {
        after_post(u(), t('Use small Latin letters, digits and dashes.'), 'bad');
    }
    if (station($id) !== null) {
        after_post(u(), t('Another station already has this address.'), 'bad');
    }
    $language = (string) ($_POST['player_language'] ?? '');

    try {
        $id = create_station($name, $id);
    } catch (Throwable $error) {
        after_post(u(), t('The station could not be created: {reason}', ['reason' => $error->getMessage()]), 'bad');
    }
    // Everything a station has an identity for is asked at the moment it is
    // created. Coming back later to fill in five blank fields is not simpler,
    // it is just unfinished.
    update_station($id, [
        'tagline'  => (string) ($_POST['tagline'] ?? ''),
        'accent'   => (string) ($_POST['accent'] ?? '#6a7f8c'),
        'home_url' => (string) ($_POST['home_url'] ?? ''),
        'colophon' => (string) ($_POST['colophon'] ?? ''),
        'player_language' => language_exists($language) ? $language : panel_language(),
    ]);
    $message = t('Created “{name}”. Now give it something to play.', ['name' => $name]);
    try {
        $art = cover_from_request($id, $name);
        if ($art !== null) {
            update_station($id, ['art_url' => $art]);
        }
    } catch (Throwable $err) {
        // The station exists either way; only its cover is missing.
        sync_station($id);
        after_post(u('station', ['id' => $id, 'tab' => 'settings']),
            t('Created “{name}”, without a cover: {reason}. You can add one here.', ['name' => $name, 'reason' => $err->getMessage()]), 'warn');
    }
    after_station_change($id, u('station', ['id' => $id]), $message);
}

function page_station_remove(string $id): void
{
    if (!is_post()) {
        redirect(u());
    }
    require_csrf();
    $station = route_station($id);
    update_station($id, ['enabled' => 0]);
    $sync = sync_station($id);
    if (!$sync['ok']) {
        after_post(u('station', ['id' => $id, 'tab' => 'settings']),
            t('The station was not removed because listeners could not be taken off air: {reason}', ['reason' => $sync['error'] ?? t('unknown error')]),
            'bad');
    }
    delete_station($id);
    after_post(u(), t('Removed “{name}”.', ['name' => $station['name']]));
}

/**
 * The current saved station for the preview to resolve. Owner-only and never
 * cached; live delivery happens automatically in the mutation request.
 */
function page_station_draft(string $id): void
{
    $station = route_station($id);
    $feed = publication_feed($id);
    json_out($feed !== null
        ? compose_publication($feed)
        : build_publication($station, (int) $station['revision'] + 1));
}

/* ------------------------------------------------------------ settings */

/**
 * The panel's own settings, in tabs: what every station shares, the panel's
 * language and calendar, and the owner's password.
 */
function page_settings(): void
{
    $tabs = [
        'general'  => [t('General'), 'sliders2'],
        'password' => [t('Password'), 'key'],
    ];
    $tab = (string) ($_GET['tab'] ?? 'general');
    if (!isset($tabs[$tab])) {
        $tab = 'general';
    }

    if (is_post() && $tab === 'password') {
        require_csrf();
        $problem = change_owner_password(
            (string) ($_POST['current_password'] ?? ''),
            (string) ($_POST['new_password'] ?? ''),
            (string) ($_POST['new_password_again'] ?? '')
        );
        after_post(
            u('settings', ['tab' => 'password']),
            $problem ?? t('Password changed. Anywhere else you were signed in has been signed out.'),
            $problem === null ? 'ok' : 'bad'
        );
    }

    if (is_post()) {
        require_csrf();
        $language = (string) ($_POST['language'] ?? '');
        $calendar = (string) ($_POST['calendar'] ?? '');
        if (language_exists($language)) {
            set_setting('language', $language);
        }
        if (in_array($calendar, CALENDARS, true)) {
            set_setting('calendar', $calendar);
        }

        $oldSeed = (string) setting('shuffle_seed', 'radio');
        $seed = trim((string) ($_POST['shuffle_seed'] ?? ''));
        if ($seed !== '') {
            set_setting('shuffle_seed', $seed);
        }
        $failed = [];
        if ($seed !== '' && $seed !== $oldSeed) {
            foreach (stations() as $station) {
                $sync = sync_station((string) $station['id']);
                if (!$sync['ok']) {
                    $failed[] = (string) $station['name'];
                }
            }
        }
        // Say it in the language just chosen.
        i18n_reset();
        after_post(
            u('settings'),
            $failed ? t('Saved, but these stations could not update yet: {names}', ['names' => implode(', ', $failed)]) : t('Saved.'),
            $failed ? 'warn' : 'ok'
        );
    }

    $panel = panel_settings();
    $mediaTools = media_tools_status();

    render([
        'title'    => t('Settings'),
        'subtitle' => h('What the whole panel shares'),
        'nav'      => 'settings',
    ], function () use ($tabs, $tab, $panel, $mediaTools) { ?>
      <nav class="tabs" aria-label="<?= h('Settings sections') ?>">
        <?php foreach ($tabs as $key => [$label, $glyph]): ?>
          <a href="<?= e(u('settings', ['tab' => $key])) ?>"
             class="tab<?= $key === $tab ? ' is-active' : '' ?>"><?= icon($glyph) ?> <?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>

      <?php if ($tab === 'general'): ?>
        <?php foreach (server_warnings() as $warning): ?>
          <p class="flash flash-warn"><?= e($warning) ?></p>
        <?php endforeach; ?>
        <form method="post" action="<?= e(u('settings')) ?>" class="card">
          <?= csrf_field() ?>
          <h2><?= h('Language and calendar') ?></h2>
          <div class="field-grid">
            <label><?= h('Language') ?>
              <select name="language">
                <?php foreach (languages() as $code => $name): ?>
                  <option value="<?= e($code) ?>" <?= panel_language() === $code ? 'selected' : '' ?>><?= e($name) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label><?= h('Calendar') ?>
              <select name="calendar">
                <?php foreach (CALENDARS as $code): ?>
                  <option value="<?= e($code) ?>" <?= panel_calendar() === $code ? 'selected' : '' ?>><?= e(match ($code) {
                      'persian' => t('Jalali (Solar Hijri)'),
                      default   => t('Gregorian'),
                  }) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>

          <h2><?= h('Shuffle') ?></h2>
          <div class="field-grid">
            <label><?= h('Shuffle seed') ?>
              <input name="shuffle_seed" value="<?= e($panel['shuffleSeed']) ?>" dir="ltr">
              <span class="hint"><?= h('Combined with the station, the programme and the day, so no two draw the same order — and every device draws the same order as every other.') ?></span>
            </label>
          </div>
          <div class="form-actions"><button type="submit" class="primary"><?= h('Save settings') ?></button></div>
        </form>
        <?php
        $mediaReady = $mediaTools['state'] === 'available' && $mediaTools['mp3Encoder'] !== '' && $mediaTools['ffprobe'];
        $mediaLimited = $mediaTools['state'] === 'available' && !$mediaReady;
        $mediaLabel = $mediaReady ? t('Available') : ($mediaLimited ? t('Limited') : t('Unavailable'));
        ?>
        <section class="card">
          <div class="card-head">
            <h2><?= h('Media tools') ?></h2>
            <span class="pill <?= $mediaReady ? 'pill-live' : ($mediaLimited ? 'pill-draft' : 'pill-bad') ?>"><?= e($mediaLabel) ?></span>
          </div>
          <p class="muted"><?= h('52Hertz works without FFmpeg. When available, it can power automatic metadata cleanup, format conversion and an optional continuous stream.') ?></p>
          <dl class="facts">
            <div><dt>FFmpeg</dt><dd><?= $mediaTools['version'] !== '' ? e($mediaTools['version']) : h('Not available') ?></dd></div>
            <div><dt><?= h('MP3 encoder') ?></dt><dd><?= $mediaTools['mp3Encoder'] !== '' ? e($mediaTools['mp3Encoder']) : h('Not found') ?></dd></div>
            <div><dt>FFprobe</dt><dd><?= $mediaTools['ffprobe'] ? e($mediaTools['ffprobeVersion']) : h('Not found') ?></dd></div>
            <div><dt><?= h('Continuous streaming') ?></dt><dd><?= h('Not tested — it needs a separate host capability test') ?></dd></div>
            <div><dt><?= h('Last checked') ?></dt><dd><?= e(gmdate('Y-m-d H:i', (int) $mediaTools['checkedAt'])) ?> UTC</dd></div>
          </dl>
          <form method="post" action="<?= e(u('media-tools-check')) ?>" class="form-actions">
            <?= csrf_field() ?>
            <button type="submit"><?= h('Check again') ?></button>
          </form>
        </section>
        <form method="post" action="<?= e(u('backup-download')) ?>" class="card">
          <?= csrf_field() ?>
          <h2><?= h('Backup') ?></h2>
          <p class="muted"><?= h('This file contains every station, schedule and setting, including the password hash. Keep it private. Download the media folder separately from your host to make a complete backup.') ?></p>
          <div class="form-actions"><button type="submit"><?= h('Download database backup') ?></button></div>
        </form>
        <section class="card">
          <h2><?= h('System') ?></h2>
          <dl class="facts">
            <div><dt><?= h('Version') ?></dt><dd><?= e(PRODUCT_VERSION) ?></dd></div>
            <div><dt><?= h('Database schema') ?></dt><dd><?= num(PANEL_SCHEMA_VERSION) ?></dd></div>
          </dl>
        </section>
      <?php else: ?>
        <form method="post" action="<?= e(u('settings', ['tab' => 'password'])) ?>" class="card">
          <?= csrf_field() ?>
          <h2><?= h('Change password') ?></h2>
          <!-- Lets a password manager know whose password this is. -->
          <input type="text" name="username" value="owner" autocomplete="username" hidden>
          <div class="field-grid one-column">
            <label><?= h('Current password') ?>
              <input type="password" name="current_password" autocomplete="current-password" required>
            </label>
            <label><?= h('New password') ?>
              <input type="password" name="new_password" autocomplete="new-password" minlength="8" required>
              <span class="hint"><?= h('At least 8 characters.') ?></span>
            </label>
            <label><?= h('New password, again') ?>
              <input type="password" name="new_password_again" autocomplete="new-password" minlength="8" required>
            </label>
          </div>
          <div class="form-actions"><button type="submit" class="primary"><?= h('Change password') ?></button></div>
        </form>
      <?php endif; ?>
    <?php });
}

/** Re-run the optional command-line media capability probe. */
function page_media_tools_check(): void
{
    if (!is_post()) {
        redirect(u('settings'));
    }
    require_csrf();
    $status = media_tools_status(true);
    $warning = media_tools_warning($status);
    if ($warning !== null) {
        after_post(u('settings'), $warning, 'warn');
    }
    after_post(
        u('settings'),
        t('FFmpeg {version}, MP3 encoder {encoder} and FFprobe are available.', [
            'version' => $status['version'],
            'encoder' => $status['mp3Encoder'],
        ]),
        'ok'
    );
}

/** Download a transactionally consistent copy without exposing the live database. */
function page_backup_download(): void
{
    if (!is_post()) {
        redirect(u('settings'));
    }
    require_csrf();

    $copy = PANEL_VAR . '/backup-' . bin2hex(random_bytes(8)) . '.sqlite';
    try {
        db()->exec('VACUUM INTO ' . db()->quote($copy));
    } catch (Throwable $err) {
        @unlink($copy);
        after_post(u('settings'), t('The database backup could not be created: {reason}', ['reason' => $err->getMessage()]), 'bad');
    }
    if (!is_file($copy)) {
        after_post(u('settings'), t('The database backup could not be created.'), 'bad');
    }
    register_shutdown_function(static function () use ($copy): void {
        @unlink($copy);
    });

    session_write_close();
    header('Content-Type: application/vnd.sqlite3');
    header('Content-Disposition: attachment; filename="52hertz-backup-' . gmdate('Ymd-His') . '.sqlite"');
    header('Content-Length: ' . filesize($copy));
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    readfile($copy);
    @unlink($copy);
    exit;
}

/* -------------------------------------------------------------- shared */

/** The panel's own public address, for the snippet a player is configured with. */
function panel_public_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $scheme = $https ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . $dir . '/';
}

function page_not_found(string $what): void
{
    http_response_code(404);
    render(['title' => t('Not found'), 'subtitle' => h('{what} is not a page of this panel.', ['what' => $what])], function () { ?>
      <div class="card empty-state">
        <p class="muted"><?= h('Pick a station from the sidebar, or go back to') ?>
           <a href="<?= e(u()) ?>"><?= h('the stations list') ?></a>.</p>
      </div>
    <?php });
}
