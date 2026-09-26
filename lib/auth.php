<?php
/**
 * One owner, one password, a server-checked session.
 *
 * No signup, roles or invitations: the product deliberately has one owner.
 * Every editing and publishing action calls require_owner() on the server;
 * nothing relies on the browser hiding a button. Public player code never
 * touches any of this.
 */

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $dir = str_replace('\\', '/', dirname($script));
    $cookiePath = ($dir === '/' || $dir === '.') ? '/' : rtrim($dir, '/') . '/';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $cookiePath,
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_name('radiopanel');
    session_start();
}

function owner_is_configured(): bool
{
    return setting('owner_password_hash') !== null;
}

function set_owner_password(string $password): void
{
    if (strlen($password) < 8) {
        throw new InvalidArgumentException('the owner password must be at least 8 characters');
    }
    set_setting('owner_password_hash', password_hash($password, PASSWORD_DEFAULT));
}

function attempt_login(string $password): bool
{
    start_session();

    // A brake on guessing: each recent failure costs the next attempt a little
    // more time. It is counted for the panel as a whole rather than per
    // browser session, because a guesser simply starts a new session each
    // time. Quiet for a quarter of an hour, and it forgets.
    $failures = (int) setting('login_failures', '0');
    if (time() - (int) setting('login_failed_at', '0') > 900) {
        $failures = 0;
    }
    if ($failures > 0) {
        usleep(min($failures, 12) * 250000);
    }

    $hash = setting('owner_password_hash');
    if ($hash === null || !password_verify($password, $hash)) {
        set_setting('login_failures', (string) ($failures + 1));
        set_setting('login_failed_at', (string) time());
        return false;
    }
    set_setting('login_failures', '0');

    if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
        set_setting('owner_password_hash', password_hash($password, PASSWORD_DEFAULT));
    }

    session_regenerate_id(true);
    $_SESSION['owner'] = true;
    $_SESSION['since'] = time();
    return true;
}

/**
 * Change the owner password from inside the panel.
 *
 * Every other browser that was signed in is signed out by it; this one stays.
 *
 * @return string|null what was wrong, or null when it was changed
 */
function change_owner_password(string $current, string $new, string $again): ?string
{
    $hash = setting('owner_password_hash');
    if ($hash === null || !password_verify($current, $hash)) {
        return t('The current password is not right.');
    }
    if (strlen($new) < 8) {
        return t('The new password needs at least 8 characters.');
    }
    if ($new !== $again) {
        return t('The two new passwords are not the same.');
    }
    if ($new === $current) {
        return t('That is the password you already have.');
    }
    set_owner_password($new);
    set_setting('password_changed_at', (string) time());
    start_session();
    session_regenerate_id(true);
    $_SESSION['since'] = time();
    return null;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function is_owner(): bool
{
    start_session();
    // A session from before the last password change no longer counts.
    return !empty($_SESSION['owner'])
        && (int) ($_SESSION['since'] ?? 0) >= (int) setting('password_changed_at', '0');
}

function require_owner(): void
{
    if (is_owner()) {
        return;
    }
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        json_out(['error' => 'not signed in'], 401);
    }
    // u() is defined by the router, which is the only thing that ever gets here.
    redirect(u('login'));
}

/* ------------------------------------------------------------------ CSRF */

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    start_session();
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $stored = $_SESSION['csrf'] ?? null;
    if (!is_string($stored) || $stored === '' || !is_string($sent) || $sent === '' || !hash_equals($stored, $sent)) {
        // 403, not a novelty code: Apache rewrites statuses it does not know
        // into a 500, which would report a refused token as a broken panel.
        json_out(['error' => 'stale form — reload the page and try again'], 403);
    }
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_out(['error' => 'POST required'], 405);
    }
}
