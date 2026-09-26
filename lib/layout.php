<?php
/**
 * The panel's chrome: a fixed header, a sidebar that always shows every
 * station and its on-air state, and a content column with tabs.
 *
 * Server-rendered. The browser only does the two things it alone can —
 * measuring media, and resolving the schedule for the preview.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/** A per-response nonce for the two inert JSON blocks and the installer probe. */
function csp_nonce(): string
{
    static $nonce = null;
    return $nonce ??= rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
}

/** The private panel never needs to be framed or load code from another site. */
function panel_security_headers(): void
{
    $nonce = csp_nonce();
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'none'; form-action 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store, max-age=0');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}

function flash(?string $message = null, string $kind = 'ok'): ?array
{
    start_session();
    if ($message !== null) {
        $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
        return null;
    }
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

/** What the dot beside a station in the sidebar means. */
function station_state(array $s): array
{
    if ((int) ($s['enabled'] ?? 1) !== 1) {
        return ['off', t('off air')];
    }
    $onAir = programme_for_instant((string) $s['id'], now_ms());
    if ($onAir === null) {
        return programmes((string) $s['id'])
            ? ['new', t('add a playback plan')]
            : ['new', t('add a programme')];
    }
    if (!programme_is_ready((int) $onAir['programme_id'])) {
        return ['new', t('scheduled programme is not ready')];
    }
    if ((int) $s['revision'] === 0) {
        return ['new', t('preparing audio')];
    }
    if (has_unpublished_changes($s)) {
        return ['draft', t('updating')];
    }
    return ['live', t('on air')];
}

/**
 * A new station's way to its first listener, shown under its tabs until it
 * gets there: four steps read from what the station already has, and the one
 * action that moves the next of them on. Once the station is on air and its
 * Player tab - where its link is - has been opened, it is gone.
 */
function station_guide(array $station): void
{
    $state = station_state($station)[0];
    $onAir = $state !== 'new';
    $linkSeen = (int) ($station['player_seen'] ?? 0) === 1;
    // A station switched off is off on purpose; it needs no way to air.
    if ($state === 'off' || ($onAir && $linkSeen)) {
        return;
    }
    $id = (string) $station['id'];
    $count = db()->prepare('SELECT COUNT(*) FROM tracks WHERE station_id = ?');
    $count->execute([$id]);
    $hasAudio = (int) $count->fetchColumn() > 0;
    $scheduled = programme_for_instant($id, now_ms());
    $ready = $scheduled !== null && programme_is_ready((int) $scheduled['programme_id']);

    // What the programme step needs depends on what is missing. The first
    // programme is scheduled by itself; a station without a plan is rare.
    if (!programmes($id)) {
        $programmeAction = [t('New programme'), u('station', ['id' => $id]) . '#new-programme'];
    } elseif ($scheduled === null) {
        $programmeAction = [t('Open Schedule'), u('station', ['id' => $id, 'tab' => 'schedule'])];
    } else {
        $programmeAction = [t('Open “{name}”', ['name' => $scheduled['programme_name']]),
            programme_url($id, (int) $scheduled['programme_id'])];
    }
    $steps = [
        [t('Add audio to the library'), t('Upload what this station will play.'), $hasAudio,
            [t('Add track'), u('station', ['id' => $id, 'tab' => 'library']) . '#add-track']],
        [t('Put it in a programme'), t('A programme is what plays: the whole day, over and over.'), $ready, $programmeAction],
        [t('Live on air'), t('It goes on air by itself once its programme is ready.'), $onAir, null],
        [t('Share its link'), t('The Player tab has the link listeners open.'), $linkSeen,
            [t('Open the Player tab'), u('station', ['id' => $id, 'tab' => 'player'])]],
    ];
    $next = array_search(false, array_column($steps, 2), true);
    ?>
    <section class="card station-guide">
      <h2><?= h('Four steps to your first listener') ?></h2>
      <ol class="guide-steps">
        <?php foreach ($steps as $i => [$title, $hint, $done, $action]): ?>
          <li class="guide-step<?= $done ? ' is-done' : ($i === $next ? ' is-next' : '') ?>"<?= $i === $next ? ' aria-current="step"' : '' ?>>
            <span class="guide-mark" aria-hidden="true"><?= $done ? '' : num($i + 1) ?></span>
            <div>
              <strong><?= e($title) ?><?php if ($done): ?><span class="sr-only"> · <?= h('done') ?></span><?php endif; ?></strong>
              <p><?= e($hint) ?></p>
              <?php if ($i === $next && $action): ?>
                <a class="button primary" href="<?= e($action[1]) ?>"><?= e($action[0]) ?></a>
              <?php endif; ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ol>
    </section>
    <?php
}

/**
 * One icon from the sprite.
 *
 * Icons here are labels for things that repeat, never decoration: the same
 * shape means the same action everywhere in the panel. They inherit the
 * colour of the text beside them, and are hidden from screen readers, because
 * the word is always there too.
 */
function icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . e($class) . '" aria-hidden="true" focusable="false">'
        . '<use href="assets/img/icons.svg#' . e($name) . '"></use></svg>';
}

/**
 * A "?" beside one of the few ideas a label cannot carry, opening a short
 * explanation under it. A click opens it and a click anywhere else, or Esc,
 * closes it (a native popover; ui.js only places it). Never on hover: a
 * phone has none, and an explanation is something you ask for.
 *
 * Inside a <label>, give the label a `for`: a button is labelable, and would
 * otherwise become the label's control instead of its field.
 */
function explain(string $title, string $text): string
{
    static $n = 0;
    $id = 'explain-' . ++$n;
    return '<button type="button" class="explain" popovertarget="' . $id . '" aria-label="'
        . h('What does this mean?') . '" title="' . h('What does this mean?') . '">?</button>'
        . '<span class="explain-pop" id="' . $id . '" popover role="note">'
        . '<strong>' . e($title) . '</strong><span>' . e($text) . '</span></span>';
}

/**
 * A cover picker: a square that shows what a listener will see. assets/covers.js
 * makes it work; without JavaScript it is still a plain file input.
 *
 * @param string $saved web URL of the image already saved, if any
 * @param string $field form field name; its remove control adds `_remove`
 * @param string $kind this image's role, `cover` or `logo`; it picks the wording shown
 */
function cover_field(string $saved = '', string $id = '', string $field = 'art', string $kind = 'cover'): void
{
    $logo = $kind === 'logo';
    $name = $logo ? h('Logo') : h('Cover');
    $choose = $logo ? h('Choose a logo image') : h('Choose a cover image');
    $remove = $logo ? h('Remove the logo') : h('Remove the cover');
    ?>
    <div class="cover-field" data-cover data-saved="<?= e($saved) ?>"<?= $id !== '' ? ' id="' . e($id) . '"' : '' ?>>
      <label class="cover-tile" title="<?= $choose ?>">
        <input type="file" name="<?= e($field) ?>" accept="image/jpeg,image/png,image/webp" class="cover-input" data-cover-input>
        <img alt="" data-cover-img hidden>
        <span class="cover-empty" data-cover-empty><?= icon('image') ?> <?= $name ?></span>
      </label>
      <button type="button" class="cover-clear" data-cover-clear aria-label="<?= $remove ?>" hidden>&times;</button>
      <span class="cover-note" data-cover-note><?= h('Optional') ?></span>
      <input type="hidden" name="<?= e($field) ?>_remove" value="0" data-cover-remove>
    </div>
    <?php
}

/**
 * The top of every page: language, direction and calendar on <html>, so CSS
 * can mirror the layout and scripts can speak and date things the same way.
 */
function page_head(string $title, string $bodyClass = ''): void
{
    panel_security_headers();
    ?><!doctype html>
<html lang="<?= e(panel_language()) ?>" dir="<?= is_rtl() ? 'rtl' : 'ltr' ?>" data-calendar="<?= e(panel_calendar()) ?>" data-locale="<?= e(language_current()['locale']) ?>" data-date-parts="<?= e(language_current()['dateParts']) ?>" data-weekday-names="<?= e(language_current()['weekdayNames']) ?>"<?= language_current()['font'] !== null ? ' data-font' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(PRODUCT_NAME) ?></title>
<link rel="icon" href="assets/img/favicon.ico" type="image/vnd.microsoft.icon">
<?= language_font_head(language_current()['font']) ?>
<link rel="stylesheet" href="assets/css/panel.css">
<script type="application/json" id="i18n" nonce="<?= e(csp_nonce()) ?>"><?= json_encode([
    'digits'  => language_current()['digits'],
    'strings' => translations(),
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</head>
<body<?= $bodyClass !== '' ? ' class="' . e($bodyClass) . '"' : '' ?>>
<?php
}

function ms_to_clock(int $ms): string
{
    $s = intdiv($ms, 1000);
    $h = intdiv($s, 3600);
    $m = intdiv($s % 3600, 60);
    return num($h > 0
        ? sprintf('%d:%02d:%02d', $h, $m, $s % 60)
        : sprintf('%d:%02d', $m, $s % 60));
}

/**
 * Render a page inside the shell.
 *
 * @param array{title:string, subtitle?:string, station?:array, tab?:string,
 *              actions?:string, script?:string, nav?:string, guide?:bool} $page
 */
function render(array $page, callable $body): void
{
    $flash = flash();
    $stations = stations();
    $current = $page['station']['id'] ?? null;
    page_head($page['title']);
    ?>

<header class="topbar">
  <a class="brand" href="<?= e(u()) ?>">
    <img class="brand-mark" src="assets/img/sqr-logo.png" alt="" aria-hidden="true">
    <?= e(PRODUCT_NAME) ?>
  </a>
  <?php if ($current !== null): ?>
    <span class="crumb-sep" aria-hidden="true">/</span>
    <span class="topbar-context"><?= e($page['station']['name']) ?></span>
  <?php endif; ?>
  <button type="button" class="nav-toggle" id="nav-toggle" aria-controls="sidebar" aria-expanded="false"
          aria-label="<?= h('Menu') ?>"><?= icon('list', 'nav-open-icon') ?><?= icon('x-lg', 'nav-close-icon') ?></button>
</header>

<div class="shell">

  <nav class="sidebar" id="sidebar" aria-label="<?= h('Stations') ?>">
    <p class="sidebar-heading"><?= icon('broadcast-pin') ?> <?= h('Stations') ?></p>
    <ul class="station-list">
      <?php foreach ($stations as $s): ?>
        <?php [$state, $label] = station_state($s); ?>
        <li>
          <a href="<?= e(u('station', ['id' => $s['id']])) ?>"
             class="station-link<?= $s['id'] === $current ? ' is-current' : '' ?>">
            <span class="dot dot-<?= e($state) ?>" title="<?= e($label) ?>"></span>
            <span class="station-link-name"><?= e($s['name']) ?></span>
            <span class="station-link-meta"><?= num((int) $s['track_count']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
      <?php if (!$stations): ?>
        <li class="sidebar-empty"><?= h('none yet') ?></li>
      <?php endif; ?>
    </ul>

    <button type="button" class="sidebar-new" data-modal-open="new-station">
      <?= icon('plus-lg') ?> <?= h('New station') ?>
    </button>

    <div class="sidebar-foot">
      <a href="<?= e(u('settings')) ?>" class="sidebar-foot-link<?= ($page['nav'] ?? '') === 'settings' ? ' is-current' : '' ?>"<?= ($page['nav'] ?? '') === 'settings' ? ' aria-current="page"' : '' ?>><?= icon('gear') ?> <?= h('Settings') ?></a>
      <form method="post" action="<?= e(u('logout')) ?>" class="inline">
        <?= csrf_field() ?>
        <button class="sidebar-foot-link" type="submit"><?= icon('box-arrow-right') ?> <?= h('Sign out') ?></button>
      </form>
    </div>
  </nav>

  <div class="nav-scrim" id="nav-scrim" hidden></div>

  <main class="content">
    <?php if ($flash): ?>
      <div class="toast-region" aria-live="polite" aria-atomic="true">
        <div class="toast toast-<?= e($flash['kind']) ?>" role="<?= $flash['kind'] === 'bad' ? 'alert' : 'status' ?>" data-toast>
          <span><?= nl2br(e($flash['message'])) ?></span>
          <button type="button" class="toast-close" data-toast-close aria-label="<?= h('Dismiss notification') ?>">&times;</button>
        </div>
      </div>
    <?php endif; ?>

    <div class="page-head">
      <div class="page-head-text">
        <h1><?= e($page['title']) ?></h1>
        <?php if (!empty($page['subtitle'])): ?>
          <p class="page-sub"><?= $page['subtitle'] ?></p>
        <?php endif; ?>
      </div>
      <?php if (!empty($page['actions'])): ?>
        <div class="page-actions"><?= $page['actions'] ?></div>
      <?php endif; ?>
    </div>

    <?php if ($current !== null): ?>
      <nav class="tabs" aria-label="<?= h('Station sections') ?>">
        <?php
        $tabs = [
            ''          => [t('Programmes'), 'music-note-list'],
            'playlists' => [t('Playlists'), 'list'],
            'library'   => [t('Library'), 'collection'],
            'collections' => [t('Collections'), 'collection'],
            'schedule' => [t('Schedule'), 'calendar-week'],
            'settings' => [t('Settings'), 'sliders2'],
            'player'   => [t('Player'), 'eye'],
        ];
        foreach ($tabs as $tab => [$label, $glyph]): ?>
          <a href="<?= e(u('station', array_filter(['id' => $current, 'tab' => $tab]))) ?>"
             class="tab<?= ($page['tab'] ?? '') === $tab ? ' is-active' : '' ?>"><?= icon($glyph) ?> <?= e($label) ?></a>
        <?php endforeach; ?>
      </nav>
      <?php if ($page['guide'] ?? true) station_guide($page['station']); ?>
    <?php endif; ?>

    <?php $body(); ?>
  </main>
</div>

<dialog class="modal" id="new-station">
  <form method="post" action="<?= e(u('station-create')) ?>" class="modal-card" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <h2><?= h('New station') ?></h2>
    <div class="modal-body">
      <p class="muted"><?= h('Everything here can be changed later in its Settings, except the address in its link.') ?></p>

      <div class="cover-row">
        <?php cover_field(); ?>
        <div class="cover-row-main">
          <label><?= h('Name') ?> <input name="name" placeholder="<?= h('Evening Selection') ?>" required data-station-name></label>
          <label><?= h('Tagline') ?> <input name="tagline" placeholder="<?= h('one line under the name') ?>"></label>
        </div>
      </div>

      <!-- The address is the one thing that cannot change: it is in the link
           listeners are given. It follows the name until the owner types one. -->
      <label><?= h('Address in its link') ?>
        <span class="address-field" dir="ltr"><span class="address-prefix">…/player/</span><input name="id" required
          minlength="2" maxlength="40" pattern="[a-z0-9]+(-[a-z0-9]+)*" autocomplete="off" spellcheck="false"
          placeholder="evening-selection" data-station-id data-taken="<?= e(json_encode(array_column($stations, 'id'))) ?>"
          data-taken-message="<?= h('Another station already has this address.') ?>"
          data-pattern-message="<?= h('Use small Latin letters, digits and dashes.') ?>"></span>
        <span class="hint"><?= h('Small Latin letters, digits and dashes. It cannot be changed later.') ?></span>
      </label>

      <div class="modal-fields">
        <label><?= h('Accent') ?> <input name="accent" type="color" value="#6a7f8c"></label>
        <label><?= h('Home link') ?> <input name="home_url" placeholder="https://…" dir="ltr"></label>
      </div>

      <div class="modal-fields">
        <label><?= h('Player language') ?>
          <select name="player_language">
            <?php foreach (languages() as $code => $label): ?>
              <option value="<?= e($code) ?>"<?= $code === panel_language() ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label><?= h('Colophon') ?>
          <input name="colophon" placeholder="<?= h('the smallest text on the player') ?>">
        </label>
      </div>
    </div>

    <div class="modal-actions">
      <button type="button" data-modal-close><?= h('Cancel') ?></button>
      <button type="submit" class="primary"><?= h('Create station') ?></button>
    </div>
  </form>
</dialog>

<script type="module" src="assets/js/ui.js"></script>
<script type="module" src="assets/js/covers.js"></script>
<script type="module" src="assets/js/now-playing.js"></script>
<script type="module" src="assets/js/time-fields.js"></script>
<?php foreach ((array) ($page['script'] ?? []) as $script): ?>
<script type="module" src="assets/js/<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** The sign-in page gets no shell: there is nothing to navigate yet. */
function render_bare(string $title, callable $body): void
{
    page_head($title, 'bare');
    ?>
<main class="signin">
  <?php $body(); ?>
</main>
</body>
</html>
<?php
}
