/**
 * The panel and the players must name the same programme for a playlist's
 * day, or the panel would show one thing while listeners hear another. The
 * engine draws in JavaScript; the panel mirrors that draw in PHP. This runs
 * both over many days, seeds and pool sizes and demands they agree exactly.
 *
 *   node tests/draw.test.mjs
 *
 * Needs `php` on the path. Touches no database.
 */

import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { programmeForDay } from '../engine/schedule.js';

const phpModules = execFileSync('php', ['-r', "echo json_encode(['mbstring' => extension_loaded('mbstring')]);"], { encoding: 'utf8' });
if (!JSON.parse(phpModules).mbstring) {
  console.error('test prerequisite missing: PHP mbstring');
  process.exit(2);
}

const here = dirname(fileURLToPath(import.meta.url));
const model = join(here, '..', 'lib', 'model.php').replace(/\\/g, '/');

// Seeds as the publisher writes them - the radio's seed, a middle dot, the
// station - including one that is not plain ASCII.
const seeds = ['3f9a1c0b77de·demo', 'radio·tone-test', 'رادیو·ایستگاه'];
const days = Array.from({ length: 120 }, (_, i) => 20000 + i * 7 + (i % 5));
const sizes = [1, 2, 3, 5];

const cases = [];
for (const seed of seeds) for (const size of sizes) for (const day of days) cases.push({ seed, size, day });

// The engine's picks, from a publication shaped like the real one.
function publication(seed, size) {
  const programmes = {};
  const members = [];
  for (let n = 1; n <= size; n++) {
    programmes[String(n * 10)] = { name: 'P' + n, mode: 'shuffle', items: [{ trackId: 't' }] };
    members.push(String(n * 10));
  }
  return {
    shuffleSeed: seed, programmes, tracks: { t: { id: 't', durationMs: 60000, mediaUrl: 't.wav' } },
    playlists: { 7: { name: 'Mix', programmes: members } },
  };
}
const engine = cases.map(({ seed, size, day }) => programmeForDay(publication(seed, size), 'playlist:7', day));

// The panel's picks: the same pool, the same seed text, the PHP draw.
const script = `
  require '${model}';
  $cases = json_decode(stream_get_contents(STDIN), true);
  $out = [];
  foreach ($cases as $c) {
    $pool = array_map(fn ($n) => (string) ($n * 10), range(1, $c['size']));
    $draw = seeded_draw($c['seed'] . "\\0playlist\\0" . '7' . "\\0" . $c['day']);
    $out[] = $pool[(int) floor($draw * count($pool))];
  }
  echo json_encode($out);
`;
const panel = JSON.parse(execFileSync('php', ['-r', script], { input: JSON.stringify(cases) }).toString());

let mismatches = 0;
cases.forEach((c, i) => {
  if (engine[i] !== panel[i]) {
    if (mismatches++ < 5) console.log(`  FAIL  seed ${c.seed}, pool ${c.size}, day ${c.day}: engine ${engine[i]}, panel ${panel[i]}`);
  }
});
const varied = new Set(engine.filter((_, i) => cases[i].size === 5)).size;
if (varied < 5) { console.log('  FAIL  a pool of five should see all five over the days drawn'); mismatches++; }

console.log(mismatches ? `\n${mismatches} of ${cases.length} draws disagree` : `${cases.length}/${cases.length} draws agree`);
process.exit(mismatches ? 1 : 0);
