<?php
/**
 * Collect every translatable string in the product, for translators.
 *
 * Text is keyed by its English wording, so the English "translation" is the
 * key itself and languages/en.json is really a template: a translator copies
 * it to their own code and replaces the right-hand side.
 *
 * Run it after adding or rewording anything a person reads:
 *
 *     php tools/extract-strings.php            # report what is missing
 *     php tools/extract-strings.php --write    # rewrite languages/en.json
 *
 * It reads the panel's PHP and JavaScript for t(), h(), tn(), h_html() and
 * the installer's install_say(), and the player for say(). The panel's player_say() - words the panel says
 * on a station's behalf, in its manifest - is filed with the player's. A call
 * whose first argument is not a plain quoted string cannot be collected; it
 * is listed under "no fixed wording" so it can be rewritten.
 */

declare(strict_types=1);

require_once __DIR__ . '/../lib/bootstrap.php';

/**
 * Files that hold text a person reads, by the section they belong to.
 *
 * The two sections stay apart because they travel apart: the panel's wording
 * is read from disk by the panel alone, while the player's is copied into
 * every station's publication, and a listener should not download seven
 * hundred panel strings to be told "on air".
 *
 * @return array<string, list<string>> section => files
 */
function source_files(): array
{
    // The two files that *define* t(), h() and tn() would otherwise report
    // their own signatures as untranslatable calls.
    $defines = ['i18n.php', 'i18n.js'];
    $sections = ['panel' => ['lib', 'assets/js'], 'player' => ['player']];
    $files = [];
    foreach ($sections as $section => $dirs) {
        $files[$section] = [];
        foreach ($dirs as $dir) {
            foreach (glob(PANEL_ROOT . '/' . $dir . '/*.{php,js}', GLOB_BRACE) ?: [] as $file) {
                if (!in_array(basename($file), $defines, true)) {
                    $files[$section][] = $file;
                }
            }
        }
        sort($files[$section]);
    }
    return $files;
}

/**
 * Every quoted string handed to a translating call in one file.
 *
 * @return array{strings: list<string>, player: list<string>, unclear: list<string>}
 */
function strings_in(string $code, string $label): array
{
    // t('…'), h("…"), tn(n, '…', '…'), h_html('…', …), say('…'),
    // player_say($words, '…'), install_say('…'). The look-behind keeps
    // format(), split() and $obj->t() out of it.
    $call = '/(?<![\w$>\-\\\\])(t|h|tn|h_html|say|player_say|install_say)\s*\(/';
    $strings = [];
    $player = [];
    $unclear = [];

    $offset = 0;
    while (preg_match($call, $code, $m, PREG_OFFSET_CAPTURE, $offset)) {
        $name = $m[1][0];
        $at = (int) $m[0][1] + strlen($m[0][0]);
        $offset = $at;

        // A definition - function say(key, vars) - names no wording.
        if (preg_match('/function\s+$/', substr($code, max(0, (int) $m[0][1] - 20), min(20, (int) $m[0][1])))) {
            continue;
        }

        // tn(count, 'one', 'many'): the wordings are the second and third.
        // player_say($words, '…'): the second, and it belongs to the player.
        $wanted = match ($name) {
            'tn' => [2, 3],
            'player_say' => [2],
            default => [1],
        };
        $found = read_arguments($code, $at, max($wanted));
        foreach ($wanted as $position) {
            $argument = $found[$position - 1] ?? null;
            if ($argument === null) {
                continue;
            }
            $literal = quoted_value($argument);
            if ($literal !== null) {
                if ($literal !== '' && $name === 'player_say') {
                    $player[] = $literal;
                } elseif ($literal !== '') {
                    $strings[] = $literal;
                }
                continue;
            }
            // Not one plain string. Most of these are a choice between two
            // wordings — h($active ? 'Change playback' : 'Set playback') —
            // and both wordings need translating, so take every quoted
            // string in the argument. Only an argument with none at all is
            // out of a translator's reach.
            $inside = quoted_values_in($argument);
            if ($inside === []) {
                $line = substr_count($code, "\n", 0, $at) + 1;
                $unclear[] = $label . ':' . $line . '  ' . $name . '(' . trim($argument) . '…';
                continue;
            }
            $strings = array_merge($strings, $inside);
        }
    }

    return ['strings' => $strings, 'player' => $player, 'unclear' => $unclear];
}

/**
 * The first $count arguments of a call whose "(" has just been passed, split
 * on the commas that are not inside a string, a bracket or a comment.
 *
 * @return list<string>
 */
function read_arguments(string $code, int $at, int $count): array
{
    $arguments = [];
    $current = '';
    $depth = 0;
    $quote = null;
    $length = strlen($code);

    for ($i = $at; $i < $length; $i++) {
        $char = $code[$i];

        if ($quote !== null) {
            $current .= $char;
            if ($char === '\\' && $i + 1 < $length) {
                $current .= $code[++$i];
            } elseif ($char === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $current .= $char;
            continue;
        }
        if (strpos('([{', $char) !== false) {
            $depth++;
        } elseif (strpos(')]}', $char) !== false) {
            if ($char === ')' && $depth === 0) {
                $arguments[] = $current;
                return $arguments;
            }
            $depth--;
        } elseif ($char === ',' && $depth === 0) {
            $arguments[] = $current;
            if (count($arguments) >= $count) {
                return $arguments;
            }
            $current = '';
            continue;
        }
        $current .= $char;
    }

    return $arguments;
}

/**
 * Every quoted string inside an argument that is more than one literal.
 *
 * @return list<string>
 */
function quoted_values_in(string $argument): array
{
    $found = [];
    if (!preg_match_all('/([\'"])((?:[^\\\\]|\\\\.)*?)\1/s', $argument, $matches, PREG_SET_ORDER)) {
        return [];
    }
    foreach ($matches as $match) {
        $text = quoted_value($match[0]);
        // A bare word in quotes is a key or a class name, not a sentence.
        if ($text !== null && $text !== '' && preg_match('/\s|[a-z]{4}/i', $text)) {
            $found[] = $text;
        }
    }
    return $found;
}

/** The text of a single-quoted or double-quoted literal, or null if it is not one. */
function quoted_value(string $argument): ?string
{
    $argument = trim($argument);
    if (strlen($argument) < 2) {
        return null;
    }
    $quote = $argument[0];
    if (($quote !== "'" && $quote !== '"') || substr($argument, -1) !== $quote) {
        return null;
    }
    $inner = substr($argument, 1, -1);
    // A closing quote in the middle means the argument was a concatenation.
    if (preg_match('/(?<!\\\\)(?:\\\\\\\\)*' . preg_quote($quote, '/') . '/', $inner)) {
        return null;
    }
    // A double-quoted PHP string interpolating a variable is not a fixed wording.
    if ($quote === '"' && preg_match('/(?<!\\\\)\$\w/', $inner)) {
        return null;
    }
    return stripcslashes($inner);
}

/* ------------------------------------------------------------------- run */

$write = in_array('--write', $argv, true);
$found = ['panel' => [], 'player' => []];
$unclear = [];
$read = 0;
// Every source file as one string, for the staleness check further down: a
// translation is only called unused when its wording is nowhere in the code.
$corpus = '';
foreach (['lib', 'assets/js', 'player'] as $dir) {
    foreach (glob(PANEL_ROOT . '/' . $dir . '/*.{php,js}', GLOB_BRACE) ?: [] as $file) {
        $corpus .= file_get_contents($file) . "\n";
    }
}

foreach (source_files() as $section => $files) {
    foreach ($files as $file) {
        $label = basename(dirname($file)) . '/' . basename($file);
        $result = strings_in((string) file_get_contents($file), $label);
        foreach ($result['strings'] as $text) {
            $found[$section][$text] = true;
        }
        foreach ($result['player'] as $text) {
            $found['player'][$text] = true;
        }
        $unclear = array_merge($unclear, $result['unclear']);
        $read++;
    }
}

$keys = [];
foreach ($found as $section => $strings) {
    $keys[$section] = array_keys($strings);
    sort($keys[$section], SORT_NATURAL | SORT_FLAG_CASE);
    fwrite(STDERR, count($keys[$section]) . ' ' . $section . " strings\n");
}
fwrite(STDERR, "from $read files\n");

if ($unclear) {
    fwrite(STDERR, "\n" . count($unclear) . " call(s) with no fixed wording, which no translator can reach:\n");
    foreach ($unclear as $line) {
        fwrite(STDERR, '  ' . $line . "\n");
    }
}

// What each language still has to say. A translation is never rewritten here:
// only the English template is, and only when asked.
foreach (language_files() as $code => $file) {
    if ($code === 'en') {
        continue;
    }
    $data = (array) json_decode((string) file_get_contents($file), true);
    fwrite(STDERR, "\n" . basename($file) . "\n");
    foreach ($keys as $section => $wanted) {
        $have = array_keys((array) ($data[$section] ?? []));
        $missing = array_values(array_diff($wanted, $have));
        // A wording the calls do not name may still be reached through a
        // variable - a tab label, a calendar name - so a translation is only
        // called stale when the wording is nowhere in the source at all.
        // Getting this wrong would tell a translator to delete a line the
        // panel still shows.
        $stale = array_values(array_filter(
            array_diff($have, $wanted),
            static fn (string $text): bool => !str_contains($corpus, "'" . $text . "'")
                && !str_contains($corpus, '"' . $text . '"')
        ));
        fwrite(STDERR, '  ' . $section . ': ' . (count($wanted) - count($missing)) . '/' . count($wanted) . ' translated');
        fwrite(STDERR, $missing ? ', ' . count($missing) . ' missing' : '');
        fwrite(STDERR, $stale ? ', ' . count($stale) . ' no longer used' : '');
        fwrite(STDERR, "\n");
        foreach (array_slice($missing, 0, 15) as $text) {
            fwrite(STDERR, '    missing: ' . $text . "\n");
        }
        foreach (array_slice($stale, 0, 15) as $text) {
            fwrite(STDERR, '    unused:  ' . $text . "\n");
        }    }
}

if (!$write) {
    fwrite(STDERR, "\nNothing written. Pass --write to rewrite languages/en.json.\n");
    exit(0);
}

$template = (array) json_decode((string) file_get_contents(PANEL_LANGUAGES . '/en.json'), true);
foreach ($keys as $section => $wanted) {
    $template[$section] = [];
    foreach ($wanted as $text) {
        $template[$section][$text] = $text;
    }
}
file_put_contents(
    PANEL_LANGUAGES . '/en.json',
    json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);
fwrite(STDERR, "\nlanguages/en.json rewritten.\n");
