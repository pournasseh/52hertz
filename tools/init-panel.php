<?php
/**
 * Set the panel up.
 *
 *   php 52hertz/tools/init-panel.php "an owner password"
 *   php 52hertz/tools/init-panel.php "an owner password" --demo
 *   php 52hertz/tools/init-panel.php "an owner password" --demo --force   (start over)
 *
 * --demo seeds the tone station from 52hertz/tools/make-demo-media.py and
 * creates its first database snapshot.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Run this from the command line.\n");
}

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/model.php';
require_once __DIR__ . '/../lib/publisher.php';

$args = array_slice($argv, 1);
$flags = array_values(array_filter($args, static fn ($a) => str_starts_with($a, '--')));
$password = (string) (array_values(array_filter($args, static fn ($a) => !str_starts_with($a, '--')))[0] ?? '');
$demo = in_array('--demo', $flags, true);
$force = in_array('--force', $flags, true);

if ($password === '') {
    exit("Usage: php 52hertz/tools/init-panel.php \"an owner password\" [--demo] [--force]\n");
}

if ($force) {
    if (file_exists(PANEL_DB)) {
        foreach (glob(PANEL_VAR . '/panel.sqlite*') ?: [] as $file) {
            unlink($file);
        }
        echo "Removed the old database.\n";
    }
}

migrate();
set_owner_password($password);
echo "Owner password set.\n";

if (!$demo) {
    echo "Done. The panel is at /52hertz/ on your web server.\n";
    exit;
}

/* ------------------------------------------------------------------ demo */

if (station('demo') !== null && !$force) {
    exit("The demo station already exists. Add --force to rebuild it.\n");
}

$media = PANEL_ROOT . '/media/demo';
if (!is_dir($media) || !glob($media . '/*.wav')) {
    exit("No demo media. Run: python 52hertz/tools/make-demo-media.py\n");
}

$id = create_station('Tone Test Radio', 'demo');
update_station($id, [
    'tagline'  => 'six tones, one clock',
    'accent'   => '#7d93a6',
    'colophon' => 'A demonstration station. The audio is generated test tones.',
    // 06:00 UTC. The panel shows the operator this in their own local clock.
    'day_start_ms' => 6 * 3600000,
]);
set_setting('shuffle_seed', 'tone-test-radio');

$demoTracks = [
    ['tone-a', 'Low Hum',       'A3 · 220 Hz',    'tone',           1],
    ['tone-b', 'Middle Sweep',  'C4 · 261.63 Hz', 'tone',           1],
    ['tone-c', 'Deep Rest',     'E3 · 164.81 Hz', 'tone',           1],
    ['tone-d', 'Bright Step',   'E4 · 329.63 Hz', 'tone',           1],
    ['tone-e', 'Long Sustain',  'G3 · 196 Hz',    'tone',           1],
    ['ident',  'Station Ident', 'A4 · 440 Hz',    'ident, station', 3],
];

$programme = create_programme($id, 'All day', ['mode' => 'shuffle']);

foreach ($demoTracks as [$file, $title, $credit, $tags, $plays]) {
    $path = "$media/$file.wav";
    $trackId = add_track($id, [
        'item_id'   => $file,
        'title'     => $title,
        'credit'    => $credit,
        'tags'      => $tags,
        'media_url' => station_media_url($id, "$file.wav"),
    ]);
    // The browser normally measures a track. A seed has no browser, so the
    // WAV header is read directly - the same idea: take the length from the
    // file, never from what someone typed.
    update_track($trackId, ['duration_ms' => wav_duration_ms($path)]);
    add_to_programme($programme, $trackId);
    if ($plays > 1) {
        set_programme_repeat($programme, $trackId, $plays);
    }
}

$result = publish_station($id);
printf(
    "Seeded \"Tone Test Radio\": %d tracks, one programme of %.3fs, day starts at 06:00 UTC.
Published database revision %d
",
    count($demoTracks),
    programme_length_ms($programme) / 1000,
    $result['revision']
);

/**
 * Length of a PCM WAV, in whole milliseconds, from its own header.
 */
function wav_duration_ms(string $path): int
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("cannot read $path");
    }
    $header = (string) fread($handle, 12);
    if (substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WAVE') {
        fclose($handle);
        throw new RuntimeException("$path is not a WAV file");
    }

    $byteRate = 0;
    $dataSize = 0;
    while (!feof($handle)) {
        $chunk = (string) fread($handle, 8);
        if (strlen($chunk) < 8) {
            break;
        }
        ['id' => $chunkId, 'size' => $size] = unpack('a4id/Vsize', $chunk);
        if ($chunkId === 'fmt ') {
            $fmt = unpack('vformat/vchannels/Vrate/VbyteRate/valign/vbits', (string) fread($handle, $size));
            $byteRate = (int) $fmt['byteRate'];
        } elseif ($chunkId === 'data') {
            $dataSize = (int) $size;
            break;
        } else {
            fseek($handle, $size, SEEK_CUR);
        }
    }
    fclose($handle);

    if ($byteRate <= 0 || $dataSize <= 0) {
        throw new RuntimeException("could not measure $path");
    }
    return (int) round($dataSize / $byteRate * 1000);
}
