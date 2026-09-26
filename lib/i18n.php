<?php
/**
 * The panel's language and calendar.
 *
 * Two separate settings: the language the panel speaks and the calendar it
 * shows dates in (Gregorian or Jalali). Either can be used with the other.
 *
 * Languages are drop-in. Each is one JSON file in languages/, named by its
 * code — fa.json, ja.json, pt-br.json — and a file that is put there is
 * offered in Settings on the next page load. Nothing else has to be edited
 * and nothing is registered anywhere, because a person who can translate is
 * rarely a person who can write PHP.
 *
 * JSON and not PHP on purpose: a language file arrives from a stranger, and
 * a .php file from a stranger is a program with the run of the server, while
 * JSON is only ever data.
 *
 * Text is looked up by its English wording, so the code reads as the English
 * panel does and a missing translation simply shows the English. The browser
 * gets the same dictionary, so a sentence is translated once whether PHP or
 * JavaScript prints it.
 */

declare(strict_types=1);

/** The calendars the panel can do arithmetic in. Their names are in page_settings(). */
const CALENDARS = ['gregorian', 'persian'];

/**
 * A language code as a file may be named: a base, and an optional region.
 *
 * It is also the guard on a path built from a setting or a form, so it stays
 * strict: letters, digits and one hyphen, and never a dot or a separator.
 */
const LANGUAGE_CODE = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/';

/** English is the wording in the code, so it is always offered. */
const DEFAULT_LANGUAGE = 'en';

/**
 * Every language file present, by code.
 *
 * @return array<string, string> code => path
 */
function language_files(): array
{
    if (isset($GLOBALS['i18n']['files'])) {
        return $GLOBALS['i18n']['files'];
    }
    $files = [];
    foreach (glob(PANEL_LANGUAGES . '/*.json') ?: [] as $path) {
        $code = strtolower(basename($path, '.json'));
        if (preg_match(LANGUAGE_CODE, $code)) {
            $files[$code] = $path;
        }
    }
    ksort($files);
    return $GLOBALS['i18n']['files'] = $files;
}

/**
 * One language file, read and checked.
 *
 * A file that is not readable JSON is not an error to shout about: a panel
 * whose owner dropped in a broken translation should still open, in English.
 *
 * @return array{name:string, englishName:string, direction:string, digits:?string, panel:array<string,string>, player:array<string,string>}|null
 */
function language_file(string $code): ?array
{
    $path = language_files()[$code] ?? null;
    if ($path === null) {
        return null;
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return null;
    }
    $meta = (array) ($data['language'] ?? []);

    // Digits are ten characters, one per 0-9. Anything else is ignored rather
    // than half-applied, which would turn numbers into nonsense.
    $digits = (string) ($meta['digits'] ?? '');
    // Counted without mbstring: the installer reads this file on a server
    // that may not have it yet.
    $digits = count(preg_split('//u', $digits, -1, PREG_SPLIT_NO_EMPTY) ?: []) === 10 ? $digits : null;

    // The locale the browser formats dates and numbers with. It is asked for
    // separately because a language is not a region: a Persian panel wants
    // fa-IR, and an English one reads dates better as en-GB than en-US.
    $locale = (string) ($meta['locale'] ?? '');
    $locale = preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})*$/', $locale) ? $locale : $code;

    return [
        'name'        => trim((string) ($meta['name'] ?? '')) ?: $code,
        'englishName' => trim((string) ($meta['englishName'] ?? '')) ?: $code,
        'direction'   => ($meta['direction'] ?? '') === 'rtl' ? 'rtl' : 'ltr',
        'locale'      => $locale,
        'digits'      => $digits,
        // The calendar this language would rather be read in, when it is
        // first chosen. Only a suggestion: Settings keeps the two apart.
        'calendar'    => in_array((string) ($meta['calendar'] ?? ''), CALENDARS, true) ? (string) $meta['calendar'] : 'gregorian',
        // A typeface of its own, from assets/fonts/, for a script the
        // system's fonts draw poorly. Without one, the system's is used.
        'font'        => font_file($meta['font'] ?? null),
        // How a whole date is put together, where the locale's own pattern
        // is not the one its readers use: "weekday day month year".
        'dateParts'   => preg_match('/^(weekday|day|month|year)( (weekday|day|month|year))*$/', (string) ($meta['dateParts'] ?? ''))
            ? (string) $meta['dateParts'] : '',
        // Calendar column heads: "short" (Mon) or "narrow" (M).
        'weekdayNames' => ($meta['weekdayNames'] ?? '') === 'narrow' ? 'narrow' : 'short',
        'panel'       => wordings((array) ($data['panel'] ?? [])),
        'player'      => wordings((array) ($data['player'] ?? [])),
    ];
}

/** A typeface file in assets/fonts/, or null if the name is not one. */
function font_file(mixed $name): ?string
{
    $name = (string) $name;
    return preg_match('/^[A-Za-z0-9._-]+\.woff2$/', $name) && is_file(PANEL_ROOT . '/assets/fonts/' . $name)
        ? $name : null;
}

/**
 * The @font-face a page needs for a language's own typeface, under the one
 * name the stylesheets use for it, "Language".
 */
function language_font_head(?string $font): string
{
    if ($font === null) {
        return '';
    }
    $url = e('assets/fonts/' . $font);
    return '<link rel="preload" href="' . $url . '" as="font" type="font/woff2" crossorigin>' . "\n"
        . '<style>@font-face { font-family: "Language"; src: url("' . $url . '") format("woff2-variations"), url("' . $url . '") format("woff2"); font-weight: 100 900; font-display: swap; }</style>' . "\n";
}

/**
 * The usable half of a dictionary.
 *
 * A wording that is its own key says nothing - it is what a lookup falls back
 * to anyway - and languages/en.json is entirely made of those, being the
 * template a translator starts from. Dropping them is what keeps an English
 * page from carrying six hundred strings to the browser to no purpose.
 *
 * @return array<string, string>
 */
function wordings(array $dictionary): array
{
    $words = [];
    foreach ($dictionary as $key => $value) {
        if (is_string($value) && $value !== '' && $value !== $key) {
            $words[(string) $key] = $value;
        }
    }
    return $words;
}

/**
 * The languages to offer, by code, each with its own name in its own script.
 *
 * @return array<string, string> code => name
 */
function languages(): array
{
    if (isset($GLOBALS['i18n']['languages'])) {
        return $GLOBALS['i18n']['languages'];
    }
    $languages = [];
    foreach (array_keys(language_files()) as $code) {
        $file = language_file($code);
        if ($file !== null) {
            $languages[$code] = $file['name'];
        }
    }
    // English is the wording in the code, so it works with no file at all.
    $languages[DEFAULT_LANGUAGE] ??= 'English';
    return $GLOBALS['i18n']['languages'] = $languages;
}

/** Whether a code names a language this panel can speak. */
function language_exists(string $code): bool
{
    return isset(languages()[$code]);
}

/**
 * The file a code should be read from: itself, or the base it belongs to.
 *
 * A listener who asked for pt-br on a panel that only has pt.json is better
 * served Portuguese than English.
 */
function language_resolve(string $code): ?string
{
    $code = strtolower($code);
    if (!preg_match(LANGUAGE_CODE, $code)) {
        return null;
    }
    if (isset(language_files()[$code])) {
        return $code;
    }
    $base = explode('-', $code)[0];
    return isset(language_files()[$base]) ? $base : null;
}

function panel_language(): string
{
    return $GLOBALS['i18n']['language'] ??= language_exists((string) setting('language'))
        ? (string) setting('language') : DEFAULT_LANGUAGE;
}

function panel_calendar(): string
{
    return $GLOBALS['i18n']['calendar'] ??= in_array((string) setting('calendar'), CALENDARS, true)
        ? (string) setting('calendar') : 'gregorian';
}

/** Forget what was read, after the settings change or a file is dropped in. */
function i18n_reset(): void
{
    $GLOBALS['i18n'] = [];
}

/** The current language's metadata and wording. */
function language_current(): array
{
    return $GLOBALS['i18n']['current'] ??= language_file(panel_language()) ?? [
        'name' => 'English', 'englishName' => 'English', 'direction' => 'ltr',
        'locale' => 'en-GB', 'digits' => null, 'calendar' => 'gregorian',
        'font' => null, 'dateParts' => '', 'weekdayNames' => 'short',
        'panel' => [], 'player' => [],
    ];
}

function is_rtl(): bool
{
    return language_current()['direction'] === 'rtl';
}

/** @return array<string, string> the current language's wording, by English wording */
function translations(): array
{
    return language_current()['panel'];
}

/**
 * The words a station's player speaks, for its publication.
 *
 * The player carries no dictionary of its own: it is handed these, and falls
 * back to the key, which is the English wording. So a station in a language
 * nobody has translated yet is an English player, not a broken one.
 *
 * @return array{strings:array<string,string>, direction:string, digits:?string}
 */
function player_words(string $code): array
{
    $file = language_file(language_resolve($code) ?? '');
    return [
        'strings'   => $file['player'] ?? [],
        'direction' => $file['direction'] ?? 'ltr',
        'digits'    => $file['digits'] ?? null,
        'font'      => $file['font'] ?? null,
    ];
}

/**
 * A sentence in the panel's language. `{name}` placeholders are filled from
 * $vars; whole numbers among them are written in the language's digits.
 * Returns plain text: escape it (or use h()) before it goes into HTML.
 */
function t(string $text, array $vars = []): string
{
    $out = translations()[$text] ?? $text;
    foreach ($vars as $key => $value) {
        $out = str_replace('{' . $key . '}', is_int($value) ? num($value) : (string) $value, $out);
    }
    return $out;
}

/** t(), escaped for HTML. */
function h(string $text, array $vars = []): string
{
    return e(t($text, $vars));
}

/** A count with its noun: tn(3, '{n} track', '{n} tracks'). */
function tn(int $n, string $one, string $many, array $vars = []): string
{
    return t($n === 1 ? $one : $many, ['n' => $n] + $vars);
}

/**
 * A sentence from a station's player words, said by the panel on its behalf -
 * in the manifest an installed station carries. tools/extract-strings.php
 * files these under "player", beside what player/app.js says itself.
 */
function player_say(array $words, string $text, array $vars = []): string
{
    $out = (string) (($words['strings'] ?? [])[$text] ?? $text);
    foreach ($vars as $key => $value) {
        $out = str_replace('{' . $key . '}', (string) $value, $out);
    }
    return $out;
}

/** Digits in the panel's language. */
function num(int|string $value): string
{
    return digits_in((string) $value, language_current()['digits']);
}

/** A number written with a language's own digits, where it has them. */
function digits_in(string $value, ?string $digits): string
{
    if ($digits === null) {
        return $value;
    }
    $map = [];
    foreach (preg_split('//u', $digits, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $i => $digit) {
        $map[(string) $i] = $digit;
    }
    return strtr($value, $map);
}

/**
 * t(), escaped, with some placeholders filled by ready-made HTML - for a
 * sentence with markup in the middle of it, which may sit anywhere in the
 * translated word order.
 *
 * @param array<string, string> $html placeholder => trusted HTML
 */
function h_html(string $text, array $html): string
{
    $out = e(t($text));
    foreach ($html as $key => $markup) {
        $out = str_replace('{' . $key . '}', $markup, $out);
    }
    return $out;
}
