<?php
/**
 * Routing, for the panel.
 *
 * A listener's requests (the clock, a station's feed, manifest, icons and
 * stream) are answered by lib/public.php before this file is even loaded.
 * Everything else arrives here and names its page in the query string:
 * `?p=station&id=demo&tab=settings`. Nothing depends on rewriting: the
 * clean public addresses in the root .htaccess (player/<id>, stations/<id>/…,
 * streams/<id>.mp3) are shorter names for pages that are all here too.
 *
 * Three rules hold throughout:
 *   - anything that changes state is POST, and carries a CSRF token
 *   - anything private calls require_owner() here, not in the page
 *   - public clock and reserved-stream responses are answered before a session is started
 */

declare(strict_types=1);

require_once __DIR__ . '/public.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/model.php';
require_once __DIR__ . '/publisher.php';
require_once __DIR__ . '/layout.php';
require_once __DIR__ . '/media-tools.php';
require_once __DIR__ . '/stream.php';
require_once __DIR__ . '/install.php';

/**
 * A link to a page of the panel. Relative on purpose: the panel does not need
 * to know where it is mounted. It names the folder, not index.php: the server
 * already runs index.php for the folder, or nobody could have opened the
 * panel to install it.
 */
function u(string $page = '', array $params = []): string
{
    $query = $page === '' ? $params : array_merge(['p' => $page], $params);
    return './' . ($query ? '?' . http_build_query($query) : '');
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function dispatch(): void
{
    $page = (string) ($_GET['p'] ?? '');
    $id   = (string) ($_GET['id'] ?? '');

    // index.php has normally answered these already; asked again here so
    // the panel is whole on its own.
    if (dispatch_public()) {
        return;
    }

    // A panel with no owner yet has one page: the one that gives it one.
    if (!owner_is_configured()) {
        page_install();
        return;
    }

    // A reset-password file, put in var/ by whoever holds the server, is the
    // way back in for an owner who forgot the password.
    if (password_reset_requested()) {
        page_password_reset();
        return;
    }

    if ($page !== 'login') {
        require_owner();
    }

    // A form larger than post_max_size arrives with nothing in it at all, not
    // even its CSRF token. Say what happened, rather than blame a stale form.
    if (is_post() && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > ini_bytes('post_max_size')) {
        too_large_for_server();
    }

    switch ($page) {
        case '':
        case 'stations':          page_stations();             return;
        case 'login':             page_login();                return;
        case 'install':           redirect(u());
        case 'logout':            page_logout();               return;
        case 'settings':          page_settings();             return;
        case 'media-tools-check': page_media_tools_check();    return;
        case 'backup-download':   page_backup_download();      return;

        case 'station':           page_station($id);           return;
        case 'draft':             page_station_draft($id);     return;
        case 'station-create':    page_station_create();       return;
        case 'station-remove':    page_station_remove($id);    return;

        case 'track-upload':      page_track_upload($id);      return;
        case 'track-update':      page_track_update($id);      return;
        case 'track-remove':      page_track_remove($id);      return;
        case 'track-replace':     page_track_replace($id);     return;
        case 'track-bulk':        page_track_bulk($id);        return;
        case 'measure':           page_measure();              return;

        case 'programme-create':   page_programme_create($id);   return;
        case 'programme-delete':   page_programme_delete($id);   return;
        case 'programme-save':     page_programme_save($id);     return;

        case 'playlist-save':     page_playlist_save($id);     return;
        case 'playlist-delete':   page_playlist_delete($id);   return;
        case 'collection-save':   page_collection_save($id);   return;
        case 'collection-delete': page_collection_delete($id); return;

        case 'schedule-plan':     page_schedule_plan($id);     return;
        case 'schedule-plan-delete': page_schedule_plan_delete($id); return;
        case 'schedule-override': page_schedule_override($id); return;
        case 'schedule-override-delete': page_schedule_override_delete($id); return;

        default:                  page_not_found($page);
    }
}

/**
 * The station a page refers to, or a 404. Every station page goes through
 * here, so a mistyped id cannot reach a page that assumes one exists.
 */
function route_station(string $id): array
{
    $station = station($id);
    if ($station === null) {
        page_not_found(t('Station “{id}”', ['id' => $id]));
        exit;
    }
    return $station;
}

/** A post the server threw away for its size: back where it came from, saying so. */
function too_large_for_server(): never
{
    $message = t('That file is larger than this server accepts ({size}).', ['size' => human_size(upload_limit_bytes())]);
    if (str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
        json_out(['error' => $message], 413);
    }
    // Only ever back to a page of this panel.
    $from = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $sameHost = parse_url($from, PHP_URL_HOST) === parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    after_post($from !== '' && $sameHost ? $from : u(), $message, 'bad');
}

/** Finish a POST: remember a message, and send the browser somewhere sane. */
function after_post(string $to, ?string $message = null, string $kind = 'ok'): never
{
    if ($message !== null) {
        flash($message, $kind);
    }
    redirect($to);
}

/** Save to the listener-facing station as part of the same operator action. */
function after_station_change(string $stationId, string $to, ?string $message = null): never
{
    $message ??= t('Saved.');
    $sync = sync_station($stationId);
    if ($sync['ok']) {
        after_post($to, $message);
    }
    after_post(
        $to,
        $message . "\n" . t('The live station could not update yet: {reason}', ['reason' => $sync['error'] ?? t('unknown error')]),
        'warn'
    );
}

require_once __DIR__ . '/pages.php';
require_once __DIR__ . '/station-pages.php';
require_once __DIR__ . '/library-pages.php';
require_once __DIR__ . '/schedule-pages.php';
require_once __DIR__ . '/programme-editor.php';
