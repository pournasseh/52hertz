<?php
/**
 * Setting the panel up, and getting back into it, from the browser.
 *
 * A fresh copy of the panel has no owner. Until it has one, every page is the
 * installer: choose a language, choose a password, and the panel is yours -
 * nothing to run on a command line. Before any of that the server itself is
 * checked, so a host that cannot run the panel says why in words rather than
 * with a blank error page.
 *
 * A forgotten password is recovered the same way, without a command line
 * and without losing anything: whoever holds the server puts a file named
 * reset-password in var/ (a host's file manager can), and the next visit asks
 * for a new password.
 */

declare(strict_types=1);

const PASSWORD_RESET_FILE = PANEL_VAR . '/reset-password';

/* ---------------------------------------------------------- the server */

/**
 * The visitor's own language, for the page that shows before any setting can
 * be read: the first language their browser asks for that has a file here.
 * English needs none - the page is written in it - so it ends the search.
 *
 * @return array{code:string, direction:string, font:?string, panel:array<string,string>}|null
 */
function install_language(): ?array
{
    static $found = false;
    if ($found !== false) {
        return $found;
    }
    $found = null;
    foreach (explode(',', (string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')) as $asked) {
        $code = language_resolve(trim(explode(';', $asked)[0]));
        if ($code === DEFAULT_LANGUAGE) {
            break;
        }
        if ($code !== null && ($file = language_file($code)) !== null) {
            $found = ['code' => $code] + $file;
            break;
        }
    }
    return $found;
}

/**
 * One line of that page, as HTML: the English, and beneath it the visitor's
 * own language where its file has the line.
 */
function install_say(string $text, array $vars = []): string
{
    $fill = static function (string $line) use ($vars): string {
        foreach ($vars as $key => $value) {
            $line = str_replace('{' . $key . '}', (string) $value, $line);
        }
        return $line;
    };
    $html = e($fill($text));
    $own = install_language();
    if (isset($own['panel'][$text])) {
        $html .= '<span class="install-own" lang="' . e($own['code']) . '" dir="' . e($own['direction']) . '">'
            . e($fill($own['panel'][$text])) . '</span>';
    }
    return $html;
}

/**
 * What stops this server running the panel at all, each already said by
 * install_say: nothing can be read from the database yet, so the language
 * is the one the visitor's browser asks for.
 *
 * @return list<string> HTML
 */
function server_problems(): array
{
    $problems = [];
    if (!extension_loaded('pdo_sqlite')) {
        $problems[] = install_say('The PHP extension pdo_sqlite is not enabled. The panel keeps everything in SQLite.');
    }
    if (!extension_loaded('mbstring')) {
        $problems[] = install_say('The PHP extension mbstring is not enabled.');
    }
    foreach (['var' => PANEL_VAR, 'media' => PANEL_ROOT . '/media'] as $name => $dir) {
        if (!is_writable(is_dir($dir) ? $dir : dirname($dir))) {
            $problems[] = install_say('The web server cannot write to the folder {name}/ inside the panel.', ['name' => $name]);
        }
    }
    return $problems;
}

/** @param list<string> $problems HTML, as install_say gives it */
function show_server_problems(array $problems): never
{
    http_response_code(503);
    panel_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    $font = install_language()['font'] ?? null;
    ?><!doctype html>
<html lang="en"<?= $font !== null ? ' data-font' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(PRODUCT_NAME) ?></title>
<link rel="icon" href="assets/img/favicon.ico" type="image/vnd.microsoft.icon">
<link rel="stylesheet" href="assets/css/panel.css">
<?= language_font_head($font) ?>
</head>
<body class="bare">
<main class="signin install">
  <div class="signin-card">
    <img class="signin-logo" src="assets/img/logo-v.png" alt="<?= e(PRODUCT_NAME) ?>" width="333" height="92">
    <h1 class="install-title"><?= install_say('This server cannot run the panel yet') ?></h1>
    <ul class="install-problems">
      <?php foreach ($problems as $problem): ?>
        <li><?= $problem ?></li>
      <?php endforeach; ?>
    </ul>
    <p class="muted"><?= install_say("Your hosting's control panel usually has a page for PHP extensions and folder permissions. If it does not, send this list to your host.") ?></p>
  </div>
</main>
</body>
</html>
<?php
    exit;
}

/** A php.ini size such as "100M", in bytes. */
function ini_bytes(string $key): int
{
    $value = trim((string) ini_get($key));
    $number = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 ** 3,
        'm' => $number * 1024 ** 2,
        'k' => $number * 1024,
        default => $number,
    };
}

/** The largest file a form can carry here: the smaller of the two limits. */
function upload_limit_bytes(): int
{
    $post = ini_bytes('post_max_size');
    $file = ini_bytes('upload_max_filesize');
    return $post > 0 ? min($post, $file) : $file;
}

function human_size(int $bytes): string
{
    if ($bytes >= 1024 ** 3) {
        // 1.5 GB, but 2 GB rather than 2.0 GB.
        return t('{n} GB', ['n' => num(rtrim(rtrim(number_format($bytes / 1024 ** 3, 1, '.', ''), '0'), '.'))]);
    }
    return t('{n} MB', ['n' => max(1, (int) round($bytes / 1024 ** 2))]);
}

/**
 * What works, but less well than it should, on this server. Shown on the
 * installer; none of it stops the panel.
 *
 * @return list<string>
 */
function server_warnings(): array
{
    $warnings = [];
    $limit = upload_limit_bytes();
    if ($limit < 32 * 1024 ** 2) {
        $warnings[] = t('This server accepts files up to {size}, and a song is often larger. Ask your host to raise upload_max_filesize and post_max_size.',
            ['size' => human_size($limit)]);
    }
    if (!extension_loaded('gd')) {
        $warnings[] = t('Covers cannot be uploaded: the PHP extension GD is not enabled.');
    }
    if ($mediaWarning = media_tools_warning(media_tools_status())) {
        $warnings[] = $mediaWarning;
    }
    $host = strtolower((string) parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    if (empty($_SERVER['HTTPS']) && !in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)) {
        $warnings[] = t('This address is not https, so your password would travel unprotected. If your host offers a free certificate, turn it on first.');
    }
    return $warnings;
}


/* ------------------------------------------------------- one password */

/** What is wrong with a new password typed twice, or null. */
function password_pair_error(string $password, string $again): ?string
{
    if (strlen($password) < 8) {
        return t('The new password needs at least 8 characters.');
    }
    return $password !== $again ? t('The two new passwords are not the same.') : null;
}

function password_pair_form(string $action, string $button): void
{ ?>
        <form method="post" action="<?= e($action) ?>" class="stack">
          <?= csrf_field() ?>
          <label for="password"><?= h('Password') ?></label>
          <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" required autofocus>
          <label for="again"><?= h('The same password again') ?></label>
          <input id="again" name="again" type="password" autocomplete="new-password" minlength="8" required>
          <button type="submit" class="primary"><?= e($button) ?></button>
        </form>
<?php }

/* ---------------------------------------------------------- the installer */

/** Every page, until the panel has an owner. */
function page_install(): void
{
    // Before any output, or the forms' CSRF token has no session to live in.
    start_session();
    $error = null;
    if (is_post()) {
        require_csrf();
        $language = (string) ($_POST['language'] ?? '');
        if (language_exists($language)) {
            // The language switch at the top. A Persian panel counts days on
            // the Persian calendar; either can be changed later in Settings.
            set_setting('language', $language);
            set_setting('calendar', language_file($language)['calendar'] ?? 'gregorian');
            redirect(u('install'));
        }
        $password = (string) ($_POST['password'] ?? '');
        $error = password_pair_error($password, (string) ($_POST['again'] ?? ''));
        if ($error === null) {
            set_owner_password($password);
            attempt_login($password);
            flash(t('Your radio is ready. Start with a station.'));
            redirect(u());
        }
    }

    render_bare(t('Welcome'), function () use ($error) { ?>
      <div class="signin-card install-card">
        <img class="install-hero" src="assets/img/hero-img.png" alt="<?= e(PRODUCT_NAME) ?>" width="600" height="600">

        <form method="post" action="<?= e(u('install')) ?>" class="segmented install-language" aria-label="<?= h('Language') ?>">
          <?= csrf_field() ?>
          <?php foreach (languages() as $code => $name): ?>
            <button type="submit" name="language" value="<?= e($code) ?>" lang="<?= e($code) ?>"
                    class="seg<?= $code === panel_language() ? ' is-active' : '' ?>"
                    <?= $code === panel_language() ? 'aria-pressed="true"' : '' ?>><?= e($name) ?></button>
          <?php endforeach; ?>
        </form>

        <h1 class="install-title"><?= h('Set up your radio') ?></h1>
        <p class="muted"><?= h('One password, and the panel is yours. You can change it later in Settings.') ?></p>

        <p class="flash flash-warn" id="no-rewrite" hidden><?= h('This server does not rewrite addresses, so station links will be the longer player/?station=name instead of player/name. They work the same. For the short ones, ask your host to enable mod_rewrite and allow .htaccess files.') ?></p>
        <p class="flash flash-bad" id="exposed" hidden><?= h('Anyone can download this panel’s database from this server: it ignores the .htaccess files that keep var/ private, as nginx does. Ask your host to deny web access to the folders var, lib, tools and tests before you go on.') ?></p>
        <?php foreach (server_warnings() as $warning): ?>
          <p class="flash flash-warn"><?= e($warning) ?></p>
        <?php endforeach; ?>
        <?php if ($error): ?><p class="flash flash-bad"><?= e($error) ?></p><?php endif; ?>

        <?php password_pair_form(u('install'), t('Start')); ?>
      </div>
      <script nonce="<?= e(csp_nonce()) ?>">
        // Asked from the browser, because only a request from outside shows
        // what the web server hands out. Here it should refuse.
        fetch('var/panel.sqlite', { method: 'HEAD', cache: 'no-store' })
          .then((response) => { if (response.ok) document.getElementById('exposed').hidden = false; })
          .catch(() => {});
        // And here, with addresses rewritten, PHP should answer - in JSON,
        // though no such station exists. PHP alone cannot know this: the
        // server hands the request to PHP only after the rewrite has run.
        fetch('stations/no-station/feed.json', { method: 'HEAD', cache: 'no-store' })
          .then((response) => {
            if (!(response.headers.get('Content-Type') || '').includes('json')) document.getElementById('no-rewrite').hidden = false;
          })
          .catch(() => {});
      </script>
    <?php });
}

/* --------------------------------------------------- a forgotten password */

/**
 * A reset file is honoured once. Deleting it after use is the plan; the
 * record of which file was used is what makes it safe when the server will
 * not let PHP delete it.
 */
function password_reset_requested(): bool
{
    clearstatcache(true, PASSWORD_RESET_FILE);
    return is_file(PASSWORD_RESET_FILE)
        && (string) filemtime(PASSWORD_RESET_FILE) !== setting('password_reset_used');
}

/** Every page, while a reset file waits. */
function page_password_reset(): void
{
    start_session();
    $error = null;
    if (is_post()) {
        require_csrf();
        $password = (string) ($_POST['password'] ?? '');
        $error = password_pair_error($password, (string) ($_POST['again'] ?? ''));
        if ($error === null) {
            set_setting('password_reset_used', (string) filemtime(PASSWORD_RESET_FILE));
            $deleted = @unlink(PASSWORD_RESET_FILE);
            set_owner_password($password);
            // Every browser signed in with the old password is signed out.
            set_setting('password_changed_at', (string) time());
            attempt_login($password);
            if ($deleted) {
                flash(t('Password changed. Welcome back.'));
            } else {
                flash(t('Password changed, but the file var/reset-password could not be deleted. Delete it yourself.'), 'warn');
            }
            redirect(u());
        }
    }

    render_bare(t('Choose a new password'), function () use ($error) { ?>
      <div class="signin-card">
        <img class="signin-logo" src="assets/img/logo-v.png" alt="<?= e(PRODUCT_NAME) ?>" width="333" height="92">
        <h1 class="install-title"><?= h('Choose a new password') ?></h1>
        <p class="muted"><?= h('The file reset-password was found in var/, so this panel lets you in with a new password. Everything else stays as it is, and the file is deleted afterwards.') ?></p>
        <?php if ($error): ?><p class="flash flash-bad"><?= e($error) ?></p><?php endif; ?>
        <?php password_pair_form(u(), t('Save and sign in')); ?>
      </div>
    <?php });
}
