import { resolve, validatePublication, DAY_MS } from '../engine/schedule.js';

let passed = 0;
const failures = [];
const test = (name, fn) => { try { fn(); passed++; } catch (error) { failures.push([name, error.message]); } };
const eq = (actual, expected, message = 'values differ') => {
  if (actual !== expected) throw new Error(`${message}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
};

const track = (id, durationMs) => ({ id, title: id.toUpperCase(), mediaUrl: `${id}.mp3`, durationMs });
function programme(overrides = {}) {
  return {
    programmeVersion: 1, stationId: 'test', revision: 1, enabled: true,
    station: { name: 'Test' }, dayStartMs: 0, epochMs: 0, shuffleSeed: 'seed',
    tracks: { a: track('a', 10000), b: track('b', 7000), c: track('c', 5000), e: track('e', 3000) },
    programmes: { p: { name: 'Day', mode: 'ordered', items: [
      { entryId: 'a-one', trackId: 'a' }, { entryId: 'b-one', trackId: 'b' }, { entryId: 'c-one', trackId: 'c' },
    ], events: [] } },
    schedule: { plans: [{ startsOn: 0, name: '', days: ['p'] }], overrides: [] }, ...overrides,
  };
}

test('new programme snapshots validate and resolve', () => {
  const pub = programme();
  eq(validatePublication(pub).errors.length, 0, 'validation errors');
  const pos = resolve(pub, 12000);
  eq(pos.track.id, 'b');
  eq(pos.sourceOffsetMs, 2000);
  eq(pos.programme.name, 'Day');
});

test('saving mid-track never cuts or restarts the current track', () => {
  const previous = programme();
  const next = programme({ revision: 2, programmes: { p: { name: 'Day', mode: 'ordered', items: [
    { entryId: 'b-one', trackId: 'b' }, { entryId: 'a-one', trackId: 'a' }, { entryId: 'c-one', trackId: 'c' },
  ], events: [] } }, handover: { requestedAtMs: 4000, previous } });
  eq(resolve(next, 3999).track.id, 'a', 'before save');
  eq(resolve(next, 6500).track.id, 'a', 'current track continues');
  eq(resolve(next, 6500).sourceOffsetMs, 6500, 'offset is unchanged');
  eq(resolve(next, 10000).track.id, 'b', 'new order starts at boundary');
});

test('removing the on-air entry lets it finish once', () => {
  const previous = programme();
  const next = programme({ revision: 2, programmes: { p: { name: 'Day', mode: 'ordered', items: [
    { entryId: 'b-one', trackId: 'b' }, { entryId: 'c-one', trackId: 'c' },
  ], events: [] } }, handover: { requestedAtMs: 3000, previous } });
  eq(resolve(next, 9000).track.id, 'a');
  eq(resolve(next, 10000).track.id, 'b');
});

test('duplicate occurrences remain independent', () => {
  const pub = programme({ programmes: { p: { name: 'Doubles', mode: 'ordered', events: [], items: [
    { entryId: 'a-first', trackId: 'a' }, { entryId: 'b', trackId: 'b' }, { entryId: 'a-again', trackId: 'a' },
  ] } } });
  eq(resolve(pub, 0).entryKey, 'a-first#0');
  eq(resolve(pub, 17000).entryKey, 'a-again#0');
});

test('strict events cut; soft events wait for the track boundary', () => {
  const strict = programme({ programmes: { p: { name: 'Events', mode: 'ordered', items: [{ entryId: 'a', trackId: 'a' }], events: [
    { id: 'strict', trackId: 'e', repeat: 'daily', timeMs: 4000, timing: 'strict' },
  ] } } });
  eq(resolve(strict, 3999).track.id, 'a');
  eq(resolve(strict, 4000).track.id, 'e');
  const soft = programme({ programmes: { p: { name: 'Events', mode: 'ordered', items: [{ entryId: 'a', trackId: 'a' }], events: [
    { id: 'soft', trackId: 'e', repeat: 'daily', timeMs: 4000, timing: 'soft' },
  ] } } });
  eq(resolve(soft, 9999).track.id, 'a');
  eq(resolve(soft, 10000).track.id, 'e');
});

test('programme changes cut at the 24-hour programme-day boundary', () => {
  const programmes = {
    p: { name: 'Regular', mode: 'ordered', items: [
      { entryId: 'a', trackId: 'a' }, { entryId: 'b', trackId: 'b' }, { entryId: 'c', trackId: 'c' },
    ], events: [] },
    q: { name: 'Special', mode: 'ordered', items: [{ entryId: 'e', trackId: 'e' }], events: [] },
  };
  const pub = programme({ programmes, schedule: { plans: [{ startsOn: 0, days: ['p'] }], overrides: [[1, 'q']] } });
  eq(resolve(pub, DAY_MS - 1).track.id, 'a', 'regular programme reaches the boundary mid-track');
  eq(resolve(pub, DAY_MS).track.id, 'e', 'the next programme begins exactly at the boundary');
  eq(resolve(pub, DAY_MS).sourceOffsetMs, 0, 'from its beginning');
});

test('the same programme also resets from the top on the next day', () => {
  const pub = programme();
  eq(resolve(pub, DAY_MS).track.id, 'a');
  eq(resolve(pub, DAY_MS).sourceOffsetMs, 0);
  eq(resolve(pub, DAY_MS).pass, 0);
});

for (const [name, message] of failures) console.log(`FAIL ${name}\n  ${message}`);
console.log(`${passed}/${passed + failures.length} passed`);
if (failures.length) process.exit(1);
