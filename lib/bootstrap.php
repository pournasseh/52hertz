<?php
/**
 * Panel bootstrap: paths, database, session, and the small helpers every
 * page uses. Required by every entry point, including the CLI installer.
 */

declare(strict_types=1);

// The product's name. A name, so it is never translated.
const PRODUCT_NAME = '52Hertz';
const PRODUCT_VERSION = '1.0.0-rc.3';
const PANEL_SCHEMA_VERSION = 1;

// The product's directory *is* the web root: /52hertz/ is the sign-in page and
// /52hertz/player/ is every station's player. The directories that must never
// be served — lib, var, tools, tests — each carry an .htaccess that denies
// everything, because on this host the document root sits above them.
const PANEL_ROOT   = __DIR__ . '/..';
const PANEL_VAR    = PANEL_ROOT . '/var';                 // denied to the web
const PANEL_DB     = PANEL_VAR . '/panel.sqlite';

// Translations, one JSON file per language. It sits beside the panel rather
// than inside lib/ because it is meant to be opened, copied and added to by
// whoever runs the radio; see i18n.php.
const PANEL_LANGUAGES = PANEL_ROOT . '/languages';

/** @return PDO */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    if (!is_dir(PANEL_VAR)) {
        mkdir(PANEL_VAR, 0770, true);
    }
    $pdo = new PDO('sqlite:' . PANEL_DB, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function migrate(): void
{
    db()->exec((string) file_get_contents(__DIR__ . '/schema.sql'));
    $installedVersion = (int) setting('schema_version', '0');
    if ($installedVersion > PANEL_SCHEMA_VERSION) {
        throw new RuntimeException(
            'This database needs a newer 52Hertz release (schema '
            . $installedVersion . ', supported ' . PANEL_SCHEMA_VERSION . ').'
        );
    }

    // v1 starts from one clean schema. Pre-release databases are deliberately
    // not a compatibility target; future versions add explicit migrations.
    if (setting('shuffle_seed') === null) {
        set_setting('shuffle_seed', bin2hex(random_bytes(6)));
    }
    if ($installedVersion < PANEL_SCHEMA_VERSION) {
        set_setting('schema_version', (string) PANEL_SCHEMA_VERSION);
    }
}

/**
 * Panel-wide settings, with their defaults in one place.
 *
 * @return array{shuffleSeed:string}
 */
function panel_settings(): array
{
    return [
        'shuffleSeed' => (string) setting('shuffle_seed', 'radio'),
    ];
}

function setting(string $key, ?string $default = null): ?string
{
    $row = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $row->execute([$key]);
    $value = $row->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function set_setting(string $key, string $value): void
{
    db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?)
                   ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

function now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

/** A slug safe in station media folders and public URLs. */
function slugify(string $text): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    return $slug !== '' ? substr($slug, 0, 60) : 'station';
}

function e(?string $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_out(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

require_once __DIR__ . '/i18n.php';
