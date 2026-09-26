/** End-to-end: database -> dynamic feed -> shared schedule engine. */

import { execFileSync } from 'node:child_process';
import { copyFileSync, cpSync, existsSync, mkdirSync, mkdtempSync, rmSync, statSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { tmpdir } from 'node:os';

import { resolve, upcoming, programmeAt, dayNumber, validatePublication, DAY_MS } from '../engine/schedule.js';

const phpModules = execFileSync('php', ['-r', "echo json_encode(['pdo_sqlite' => extension_loaded('pdo_sqlite'), 'mbstring' => extension_loaded('mbstring')]);"], { encoding: 'utf8' });
const phpPrerequisites = JSON.parse(phpModules);
const missingPhp = Object.entries(phpPrerequisites).filter(([, present]) => !present).map(([name]) => name);
if (missingPhp.length) {
  console.error('test prerequisite missing: PHP ' + missingPhp.join(', '));
  process.exit(2);
}

const sourceRoot = join(dirname(fileURLToPath(import.meta.url)), '..');
const temporary = mkdtempSync(join(tmpdir(), '52hertz-pipeline-'));
const root = join(temporary, '52hertz');
process.on('exit', () => rmSync(temporary, { recursive: true, force: true }));

mkdirSync(root, { recursive: true });
for (const directory of ['assets', 'engine', 'lib', 'tools']) {
  cpSync(join(sourceRoot, directory), join(root, directory), { recursive: true });
}
for (const directory of ['media', 'var']) {
  mkdirSync(join(root, directory), { recursive: true });
  const control = join(sourceRoot, directory, '.htaccess');
  if (existsSync(control)) copyFileSync(control, join(root, directory, '.htaccess'));
}

let passed = 0;
const failures = [];
const test = (name, fn) => {
  try { fn(); passed++; } catch (error) { failures.push({ name, message: error.message }); }
};
const assert = (condition, message) => { if (!condition) throw new Error(message || 'assertion failed'); };
const eq = (actual, expected, message) => {
  if (actual !== expected) throw new Error(`${message || 'values differ'} — expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
};

const php = (...args) => execFileSync('php', args, { cwd: root, encoding: 'utf8' });
const phpBytes = (...args) => execFileSync('php', args, { cwd: root });
const phpValue = (expression) => php('-r', `require 'lib/publisher.php'; migrate(); echo json_encode(${expression}, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);`);
const feed = () => JSON.parse(phpValue("publication_feed('demo')"));
const composeFeed = (document) => {
  let current = null;
  for (const snapshot of document.snapshots) {
    const next = structuredClone(snapshot.publication);
    if (current) next.handover = { requestedAtMs: snapshot.requestedAtMs, previous: current };
    current = next;
  }
  return current;
};

console.log(`Building an isolated dynamic station in ${temporary}\n`);
const python = process.env.PYTHON || (process.platform === 'win32' ? 'python' : 'python3');
execFileSync(python, ['tools/make-demo-media.py'], { cwd: root, encoding: 'utf8' });
php('tools/init-panel.php', 'pipeline-test-password', '--demo', '--force');
const schemaVersion = php('-r', "require 'lib/bootstrap.php'; migrate(); migrate(); echo setting('schema_version');");

const stationIsolation = JSON.parse(phpValue(`(function () {
    $other = create_station('Other station', 'other');
    $foreignTrack = add_track($other, ['title' => 'Foreign track', 'media_url' => 'https://example.invalid/foreign.mp3', 'duration_ms' => 1000]);
    $wrongDelete = delete_track('demo', $foreignTrack);
    $collection = save_collection($other, null, 'Foreign collection', [$foreignTrack]);
    try { save_collection('demo', $collection, 'Hijacked collection', []); $collectionBlocked = false; }
    catch (InvalidArgumentException $e) { $collectionBlocked = true; }
    $programme = create_programme($other, 'Foreign programme');
    $playlist = save_playlist($other, null, 'Foreign playlist', [$programme]);
    try { save_playlist('demo', $playlist, 'Hijacked playlist', []); $playlistBlocked = false; }
    catch (InvalidArgumentException $e) { $playlistBlocked = true; }
    return [
        'wrongDelete' => $wrongDelete,
        'trackStillExists' => track($foreignTrack) !== null,
        'collectionBlocked' => $collectionBlocked,
        'collectionMembers' => collection_track_ids($collection),
        'playlistBlocked' => $playlistBlocked,
        'playlistMembers' => playlist_programme_ids($playlist),
        'foreignTrack' => $foreignTrack,
        'foreignProgramme' => $programme,
    ];
})()`));

const atomicity = JSON.parse(phpValue(`(function () {
    $station = create_station('Fault station', 'fault-test');
    $existing = add_track($station, ['title' => 'Original', 'media_url' => 'https://example.invalid/original.mp3', 'duration_ms' => 1000]);
    db()->exec("CREATE TRIGGER fail_station_touch BEFORE UPDATE ON stations WHEN NEW.id = 'fault-test' BEGIN SELECT RAISE(ABORT, 'forced touch failure'); END");

    $results = [];
    try { add_track($station, ['title' => 'Must roll back', 'duration_ms' => 1000]); $results['addThrew'] = false; }
    catch (Throwable $e) { $results['addThrew'] = true; }
    $results['trackCountAfterAdd'] = count(tracks($station));

    try { update_track($existing, ['title' => 'Must roll back']); $results['updateThrew'] = false; }
    catch (Throwable $e) { $results['updateThrew'] = true; }
    $results['titleAfterUpdate'] = track($existing)['title'];

    try { replace_track_audio($existing, 'https://example.invalid/replacement.mp3'); $results['replaceThrew'] = false; }
    catch (Throwable $e) { $results['replaceThrew'] = true; }
    $results['mediaAfterReplace'] = track($existing)['media_url'];

    try { delete_track($station, $existing); $results['deleteThrew'] = false; }
    catch (Throwable $e) { $results['deleteThrew'] = true; }
    $results['trackAfterDelete'] = track($existing) !== null;

    try { create_programme($station, 'Must roll back'); $results['programmeThrew'] = false; }
    catch (Throwable $e) { $results['programmeThrew'] = true; }
    $results['programmeCount'] = count(programmes($station));
    $results['planCount'] = count(schedule($station)['plans']);

    db()->exec('DROP TRIGGER fail_station_touch');
    return $results;
})()`));

const stationCreateAtomicity = JSON.parse(phpValue(`(function () {
    $blocked = station_media_dir('cannot-create');
    file_put_contents($blocked, 'not a directory');
    try { create_station('Cannot create', 'cannot-create'); $threw = false; }
    catch (Throwable $e) { $threw = true; }
    $rowExists = station('cannot-create') !== null;
    @unlink($blocked);
    return ['threw' => $threw, 'rowExists' => $rowExists];
})()`));

const feed1 = feed();
const r1 = composeFeed(feed1);
const r1Bytes = JSON.stringify(feed1.snapshots[0].publication);

test('the v1 schema marker is written and migrations are idempotent', () => eq(schemaVersion, '1'));
test('station creation rolls back if its media directory cannot be created', () => {
  eq(stationCreateAtomicity.threw, true, 'creation reports the filesystem failure');
  eq(stationCreateAtomicity.rowExists, false, 'database row rolls back');
});
test('station ownership is enforced inside mutation helpers', () => {
  eq(stationIsolation.wrongDelete, false, 'foreign track delete refused');
  eq(stationIsolation.trackStillExists, true, 'foreign track preserved');
  eq(stationIsolation.collectionBlocked, true, 'foreign collection rewrite refused');
  eq(JSON.stringify(stationIsolation.collectionMembers), JSON.stringify([stationIsolation.foreignTrack]), 'collection membership preserved');
  eq(stationIsolation.playlistBlocked, true, 'foreign playlist rewrite refused');
  eq(JSON.stringify(stationIsolation.playlistMembers), JSON.stringify([stationIsolation.foreignProgramme]), 'playlist membership preserved');
});
test('track and initial-programme mutations roll back if station touch fails', () => {
  eq(atomicity.addThrew, true); eq(atomicity.trackCountAfterAdd, 1, 'add rolled back');
  eq(atomicity.updateThrew, true); eq(atomicity.titleAfterUpdate, 'Original', 'update rolled back');
  eq(atomicity.replaceThrew, true); eq(atomicity.mediaAfterReplace, 'https://example.invalid/original.mp3', 'replacement rolled back');
  eq(atomicity.deleteThrew, true); eq(atomicity.trackAfterDelete, true, 'delete rolled back');
  eq(atomicity.programmeThrew, true); eq(atomicity.programmeCount, 0, 'programme insert rolled back');
  eq(atomicity.planCount, 0, 'initial schedule rolled back with programme');
});
test('the public feed is assembled from database snapshots', () => {
  eq(feed1.feedVersion, 1, 'feed version');
  eq(feed1.revision, 1, 'revision');
  eq(feed1.snapshots.length, 1, 'one baseline');
});
test('the published snapshot validates', () => eq(validatePublication(r1).errors.length, 0));
test('the feed contains the station programme', () => {
  eq(Object.keys(r1.tracks).length, 6, 'tracks');
  eq(Object.keys(r1.programmes).length, 1, 'programmes');
  eq(r1.schedule.plans[0].days.length, 1, 'one-day plan');
});
test('durations came from the files', () => {
  const lengths = Object.values(r1.tracks).map((track) => track.durationMs);
  assert(lengths.every((ms) => Number.isInteger(ms) && ms > 0), 'all tracks measured');
  assert(lengths.some((ms) => ms % 1000 !== 0), 'not rounded durations');
});
test('every media URL resolves from the panel root', () => {
  for (const track of Object.values(r1.tracks)) {
    const size = statSync(join(root, track.mediaUrl)).size;
    const impliedMs = Math.round(((size - 44) / (22050 * 2)) * 1000);
    assert(Math.abs(impliedMs - track.durationMs) <= 2, `${track.id}: media duration differs`);
  }
});
test('the dynamic manifest gives this station its own app identity', () => {
  const manifest = JSON.parse(phpValue("station_manifest(latest_publication('demo'), '/52hertz/')"));
  eq(manifest.id, '/52hertz/player/demo');
  eq(manifest.start_url, '/52hertz/player/demo');
  eq(manifest.scope, '/52hertz/player/');
  assert(manifest.icons.every((icon) => icon.src.startsWith('/52hertz/stations/demo/icon-')));
});
test('asked for by its plain address, the manifest needs no rewriting either', () => {
  const manifest = JSON.parse(phpValue("station_manifest(latest_publication('demo'), '/52hertz/', false)"));
  eq(manifest.id, '/52hertz/player/?station=demo');
  eq(manifest.start_url, '/52hertz/player/?station=demo');
  eq(manifest.scope, '/52hertz/player/');
  eq(manifest.icons.map((icon) => icon.src).join(' '), [
    '/52hertz/index.php?p=station-icon&id=demo&size=192',
    '/52hertz/index.php?p=station-icon&id=demo&size=512',
    '/52hertz/index.php?p=station-icon&id=demo&size=512&maskable=1',
  ].join(' '));
});
test('dynamic station icons are exact-size PNGs', () => {
  for (const [size, maskable] of [[192, false], [512, false], [512, true]]) {
    const png = phpBytes('-r', `require 'lib/publisher.php'; migrate(); echo station_icon_png(latest_publication('demo'), ${size}, ${maskable ? 'true' : 'false'});`);
    eq(png.subarray(1, 4).toString(), 'PNG', 'PNG signature');
    eq(png.readUInt32BE(16), size, 'width');
    eq(png.readUInt32BE(20), size, 'height');
  }
});
test('station logo and cover remain separate roles', () => {
  assert(Object.hasOwn(r1.station, 'logoUrl'));
  assert(Object.hasOwn(r1.station, 'artUrl'));
});
test('a listener arrives at the live offset', () => {
  const pos = resolve(r1, Date.now());
  eq(pos.state, 'on-air');
  assert(pos.sourceOffsetMs >= 0 && pos.sourceOffsetMs < pos.track.durationMs);
});
test('two independent listeners resolve identically', () => {
  const a = structuredClone(r1), b = structuredClone(r1);
  for (let i = 0; i < 2000; i++) {
    const time = Date.now() + Math.floor(Math.random() * 90 * DAY_MS);
    eq(resolve(a, time).track.id, resolve(b, time).track.id);
    eq(resolve(a, time).sourceOffsetMs, resolve(b, time).sourceOffsetMs);
  }
});
test('the programme day resets at its configured start', () => {
  const today = dayNumber(Date.now());
  const start = (today + 1) * DAY_MS + r1.dayStartMs;
  eq(resolve(r1, start).pass, 0);
  assert(resolve(r1, start - 1).pass > 0);
});
test('an hour of listening never lands outside a track', () => {
  const end = Date.now() + 3600000;
  let time = Date.now(), slots = 0;
  while (time < end) {
    const pos = resolve(r1, time);
    assert(pos.state === 'on-air');
    assert(pos.sourceOffsetMs < pos.track.durationMs);
    time = pos.nextBoundaryMs; slots++;
  }
  assert(slots >= 60, `only ${slots} slots`);
});
test('the schedule answers far into the future', () => {
  const today = dayNumber(Date.now());
  for (const ahead of [1, 30, 400, 4000]) {
    assert(programmeAt(r1, (today + ahead) * DAY_MS + 12 * 3600000) !== null);
  }
});

console.log('Publishing a second database snapshot…\n');
const second = JSON.parse(php('tools/publish.php', 'demo'));
const feed2 = feed();
const r2 = composeFeed(feed2);

test('a second publish advances one dynamic feed revision', () => {
  eq(second.revision, 2);
  eq(feed2.revision, 2);
  eq(feed2.snapshots.length, 2);
});
test('database snapshots are flat and the baseline is unchanged', () => {
  assert(feed2.snapshots.every((snapshot) => !Object.hasOwn(snapshot.publication, 'handover')));
  eq(JSON.stringify(feed2.snapshots[0].publication), r1Bytes);
});
test('the client-composed handover is seamless', () => {
  const time = Date.now() + 500000;
  eq(resolve(r2, time).track.id, resolve(r1, time).track.id);
  eq(resolve(r2, time).sourceOffsetMs, resolve(r1, time).sourceOffsetMs);
});

console.log('Scheduling a special day…\n');
const specialDay = dayNumber(Date.now()) + 3;
php('-r', `require 'lib/router.php'; migrate(); $p = programmes('demo')[0]; set_schedule_override('demo', ${specialDay}, ['programme_id' => (int) $p['id'], 'playlist_id' => null]);`);
php('tools/publish.php', 'demo');
const r3 = composeFeed(feed());

test('an override is delivered by the dynamic feed', () => {
  eq(JSON.stringify(r3.schedule.overrides), JSON.stringify([[specialDay, Object.keys(r3.programmes)[0]]]));
  eq(validatePublication(r3).errors.length, 0);
});
test('the override starts from the top at the day boundary', () => {
  const pos = resolve(r3, specialDay * DAY_MS + r3.dayStartMs);
  eq(pos.override, true);
  eq(pos.passIndex, 0);
  eq(pos.sourceOffsetMs, 0);
});

for (const failure of failures) console.log(`  FAIL  ${failure.name}\n        ${failure.message}`);
const total = passed + failures.length;
console.log(`\n${passed}/${total} passed` + (failures.length ? `, ${failures.length} failed` : ''));
if (!failures.length) {
  const now = Date.now();
  const pos = resolve(r2, now);
  console.log('\nOn air right now, according to the dynamic feed:');
  console.log(`  ${pos.programme.name}: ${pos.track.title} — ${Math.floor(pos.sourceOffsetMs / 1000)}s in`);
  for (const next of upcoming(r2, now, 3)) {
    console.log(`  then ${new Date(next.slotStartMs).toISOString().slice(11, 19)}Z  ${next.track.title}`);
  }
}

process.exit(failures.length ? 1 : 0);
