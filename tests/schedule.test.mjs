/**
 * Tests for the schedule resolver.
 *
 * The model under test is days, plans and passes. A programme day is 24 hours
 * from the station's start time. A plan is an arbitrary repeating list with
 * one choice per programme day; a dated plan replaces the previous one, and a
 * one-off override replaces one day. Programme length never advances a plan.
 *
 * Run: node 52hertz/tests/schedule.test.mjs
 */

import {
  resolve, upcoming, prepareProgramme, orderFor, programmeAt, runAt,
  weekdayOf, dayNumber, programmeDayOf, validatePublication, DAY_MS, PROGRAMME_VERSION,
} from '../engine/schedule.js';

let passed = 0;
const failures = [];

function test(name, fn) {
  try { fn(); passed++; }
  catch (err) { failures.push({ name, message: err.message }); }
}
function assert(cond, message) {
  if (!cond) throw new Error(message || 'assertion failed');
}
function eq(actual, expected, message) {
  if (actual !== expected) {
    throw new Error((message || 'values differ') + ` — expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

const SIX_AM = 6 * 3600000;
const MONDAY = dayNumber(Date.UTC(2026, 0, 5));      // 2026-01-05 is a Monday
const HOUR = 3600000;

/** A station with two programmes and a one-day repeating plan. */
function station(overrides = {}) {
  return JSON.parse(JSON.stringify(Object.assign({
    // Exactly what the publisher writes. A fixture that is not the shape a
    // real publication has is how a dead code path stays alive unnoticed.
    programmeVersion: 1,
    stationId: 'test',
    revision: 1,
    enabled: true,
    station: { name: 'Test Station' },
    dayStartMs: SIX_AM,
    shuffleSeed: 'test-seed',
    // Deliberately awkward lengths. Round numbers divide a day evenly and hide
    // every truncation the daily reset is supposed to cause.
    tracks: {
      a: { id: 'a', title: 'Alpha', mediaUrl: 'a.wav', durationMs: 7003 },
      b: { id: 'b', title: 'Bravo', mediaUrl: 'b.wav', durationMs: 11017 },
      c: { id: 'c', title: 'Charlie', mediaUrl: 'c.wav', durationMs: 13009 },
      d: { id: 'd', title: 'Delta', mediaUrl: 'd.wav', durationMs: 5011 },
      i: { id: 'i', title: 'Ident', mediaUrl: 'i.wav', durationMs: 3001 },
    },
    programmes: {
      day: {
        name: 'Daytime', mode: 'shuffle',
        items: [
          { trackId: 'a' }, { trackId: 'b' }, { trackId: 'c' },
          { trackId: 'd' }, { trackId: 'i', repeat: 3 },
        ],
      },
      night: {
        name: 'Night', mode: 'ordered',
        items: [{ trackId: 'c' }, { trackId: 'b' }],
      },
    },
    schedule: { plans: [{ startsOn: MONDAY, name: 'Every day', days: ['day'] }], overrides: [] },
  }, overrides)));
}

const DAY_LENGTH = 7003 + 11017 + 13009 + 5011 + 3 * 3001;   // 45_043
const at = (day, msIntoDay) => day * DAY_MS + msIntoDay;

/* ------------------------------------------------------------ the calendar */

test('a UTC day is always exactly 86,400,000 ms', () => {
  eq(DAY_MS, 86400000, 'day length');
  eq(dayNumber(Date.UTC(1970, 0, 1)), 0, 'day zero');
  eq(dayNumber(Date.UTC(1970, 0, 2)) - dayNumber(Date.UTC(1970, 0, 1)), 1, 'one day apart');
});

test('weekdays are arithmetic, not a calendar library', () => {
  eq(weekdayOf(dayNumber(Date.UTC(2026, 0, 4))), 0, 'Sunday');
  eq(weekdayOf(MONDAY), 1, 'Monday');
  eq(weekdayOf(dayNumber(Date.UTC(2026, 0, 10))), 6, 'Saturday');
  // Agrees with the platform's own calendar, which the engine never consults.
  for (let i = 0; i < 400; i++) {
    const ms = Date.UTC(2026, 0, 1) + i * DAY_MS;
    eq(weekdayOf(dayNumber(ms)), new Date(ms).getUTCDay(), 'day ' + i);
  }
});

/* -------------------------------------------------------------- the ladder */

test('a programme day is 24 hours from the station start time', () => {
  const pub = station();
  eq(programmeDayOf(pub, at(MONDAY, SIX_AM)), MONDAY, 'begins at 06:00');
  eq(programmeDayOf(pub, at(MONDAY + 1, SIX_AM - 1)), MONDAY, 'and still owns 05:59 next morning');
  eq(programmeDayOf(pub, at(MONDAY + 1, SIX_AM)), MONDAY + 1, 'until 06:00');
});

test('a one-day plan plays the same programme in a fresh run each day', () => {
  const pub = station();
  const run = runAt(pub, at(MONDAY, 10 * HOUR));
  eq(run.programmeId, 'day', 'the everyday programme');
  eq(run.startMs, at(MONDAY, SIX_AM), 'from the day start');
  eq(run.endMs, at(MONDAY + 1, SIX_AM), 'for 24 hours');
  eq(resolve(pub, at(MONDAY, SIX_AM)).pass, 0, 'from its first pass');
});

test('the small hours belong to the programme day that started yesterday', () => {
  const run = runAt(station(), at(MONDAY + 1, 2 * HOUR));      // 02:00, before 06:00
  eq(run.day, MONDAY, 'still Monday programme');
  eq(run.endMs, at(MONDAY + 1, SIX_AM), 'until this morning');
});

test('a seven-day plan is a weekly cycle', () => {
  const pub = station({
    schedule: {
      plans: [{ startsOn: MONDAY, name: 'Week', days: ['day', 'day', 'day', 'day', 'night', 'night', 'night'] }],
      overrides: [],
    },
  });
  eq(programmeAt(pub, at(MONDAY, SIX_AM)), 'day', 'Monday');
  eq(programmeAt(pub, at(MONDAY + 3, 23 * HOUR)), 'day', 'Thursday');
  eq(programmeAt(pub, at(MONDAY + 4, SIX_AM)), 'night', 'Friday');
  eq(programmeAt(pub, at(MONDAY + 7, SIX_AM)), 'day', 'wraps to Monday');
});

test('every position in a cycle starts a fresh programme day', () => {
  const pub = station({
    schedule: { plans: [{ startsOn: MONDAY, days: ['day', 'day', 'night'] }], overrides: [] },
  });
  const wednesday = runAt(pub, at(MONDAY + 2, 12 * HOUR));
  eq(wednesday.programmeId, 'night', 'the third cycle position');
  eq(wednesday.startMs, at(MONDAY + 2, SIX_AM), 'as a run of its own day');
  eq(wednesday.endMs, at(MONDAY + 3, SIX_AM), 'that ends with the day');
  const first = resolve(pub, at(MONDAY + 2, SIX_AM));
  eq(first.pass, 0, 'Wednesday starts the programme from its first pass');
  eq(first.sourceOffsetMs, 0, 'at the beginning of a track');
  eq(programmeAt(pub, at(MONDAY + 3, 12 * HOUR)), 'day', 'then the cycle starts again');
});

test('the latest dated plan takes over without changing earlier days', () => {
  const pub = station({ schedule: { plans: [
    { startsOn: MONDAY, days: ['day'] },
    { startsOn: MONDAY + 3, days: ['night', 'day'] },
  ], overrides: [] } });
  eq(programmeAt(pub, at(MONDAY + 2, 12 * HOUR)), 'day', 'old plan before the change');
  eq(programmeAt(pub, at(MONDAY + 3, 12 * HOUR)), 'night', 'new plan at its boundary');
  eq(programmeAt(pub, at(MONDAY + 4, 12 * HOUR)), 'day', 'new cycle advances');
});

test('a new version can preserve the active cycle position', () => {
  const pub = station({ schedule: { plans: [
    { startsOn: MONDAY, days: ['day', 'night', 'night'] },
    // On Wednesday the old cycle is at its third position. Re-anchoring the
    // rotated cycle there makes an unchanged edit continuous across versions.
    { startsOn: MONDAY + 2, days: ['night', 'day', 'night'] },
  ], overrides: [] } });
  eq(programmeAt(pub, at(MONDAY + 1, 12 * HOUR)), 'night', 'old version through Tuesday');
  eq(programmeAt(pub, at(MONDAY + 2, 12 * HOUR)), 'night', 'new version keeps Wednesday');
  eq(programmeAt(pub, at(MONDAY + 3, 12 * HOUR)), 'day', 'then keeps the next cycle position');
  eq(programmeAt(pub, at(MONDAY + 4, 12 * HOUR)), 'night', 'and continues the cycle');
});

test('a seventy-day plan plays seventy programmes in order and loops', () => {
  const ids = Array.from({ length: 70 }, (_, index) => `p${index + 1}`);
  const programmes = Object.fromEntries(ids.map((id) => [id, {
    name: id, mode: 'ordered', items: [{ trackId: 'a' }],
  }]));
  const pub = station({ programmes, schedule: { plans: [{ startsOn: MONDAY, days: ids }], overrides: [] } });
  eq(programmeAt(pub, at(MONDAY, 12 * HOUR)), 'p1', 'first day');
  eq(programmeAt(pub, at(MONDAY + 69, 12 * HOUR)), 'p70', 'last day');
  eq(programmeAt(pub, at(MONDAY + 70, 12 * HOUR)), 'p1', 'loops to the beginning');
  eq(validatePublication(pub).errors.length, 0, 'the full plan validates');
});

/* --------------------------------------------------------------- overrides */

test('an override plays its own programme for exactly its 24 hours', () => {
  const pub = station({ schedule: { plans: [{ startsOn: MONDAY, days: ['day'] }], overrides: [[MONDAY + 2, 'night']] } });
  eq(programmeAt(pub, at(MONDAY + 2, SIX_AM - 1)), 'day', 'not before its start');
  eq(programmeAt(pub, at(MONDAY + 2, SIX_AM)), 'night', 'from the day start');
  eq(programmeAt(pub, at(MONDAY + 3, SIX_AM - 1)), 'night', 'through the small hours');
  eq(programmeAt(pub, at(MONDAY + 3, SIX_AM)), 'day', 'and not a moment longer');
  const run = runAt(pub, at(MONDAY + 2, 12 * HOUR));
  eq(run.override, true, 'it says it is an override');
  eq(run.startMs, at(MONDAY + 2, SIX_AM), 'run starts with the day');
  eq(run.endMs, at(MONDAY + 3, SIX_AM), 'run ends with the day');
  eq(resolve(pub, at(MONDAY + 2, SIX_AM)).passIndex, 0, 'from the top of the programme');
});

test('an override replaces one cycle position and the cycle then continues', () => {
  const pub = station({
    schedule: { plans: [{ startsOn: MONDAY, days: ['day', 'night', 'day'] }], overrides: [[MONDAY + 1, 'day']] },
  });
  eq(programmeAt(pub, at(MONDAY, 12 * HOUR)), 'day', 'normal first position');
  eq(programmeAt(pub, at(MONDAY + 1, 12 * HOUR)), 'day', 'override replaces the second position');
  const after = runAt(pub, at(MONDAY + 2, 12 * HOUR));
  eq(after.programmeId, 'day', 'third position is unchanged');
  const first = resolve(pub, at(MONDAY + 2, SIX_AM));
  eq(first.pass, 0, 'first pass');
  eq(first.passIndex, 0, 'first track');
  eq(first.sourceOffsetMs, 0, 'from its beginning');
});

test('two overrides in a row are two runs, each from the top', () => {
  const pub = station({ schedule: { plans: [{ startsOn: MONDAY, days: ['day'] }], overrides: [[MONDAY + 1, 'night'], [MONDAY + 2, 'night']] } });
  const first = runAt(pub, at(MONDAY + 1, 12 * HOUR));
  const second = runAt(pub, at(MONDAY + 2, 12 * HOUR));
  eq(first.endMs, second.startMs, 'back to back');
  eq(resolve(pub, at(MONDAY + 2, SIX_AM)).passIndex, 0, 'the second starts at its first track');
});

test('a past override leaves no trace on the days around it', () => {
  const plain = station();
  const withSpecial = station({ schedule: { plans: [{ startsOn: MONDAY, days: ['day'] }], overrides: [[MONDAY + 2, 'night']] } });
  for (const t of [at(MONDAY + 1, 9 * HOUR), at(MONDAY + 3, 9 * HOUR), at(MONDAY + 30, 9 * HOUR)]) {
    eq(resolve(withSpecial, t).track.id, resolve(plain, t).track.id, 'same track at ' + t);
    eq(resolve(withSpecial, t).sourceOffsetMs, resolve(plain, t).sourceOffsetMs, 'same position at ' + t);
  }
});

test('"coming up" crosses into an override at its start', () => {
  const pub = station({ schedule: { plans: [{ startsOn: MONDAY, days: ['day'] }], overrides: [[MONDAY + 1, 'night']] } });
  const next = upcoming(pub, at(MONDAY + 1, SIX_AM - 1000), 2);
  eq(next[0].slotStartMs, at(MONDAY + 1, SIX_AM), 'the special day starts on time');
  eq(next[0].programmeId, 'night', 'with its own programme');
});

/* -------------------------------------------------------------- playlists */

const withMix = (overrides = {}) => station({
  playlists: { mix: { name: 'Mix', programmes: ['day', 'night'] } },
  schedule: { plans: [{ startsOn: MONDAY, days: ['playlist:mix'] }], overrides: [] },
  ...overrides,
});

test('a playlist plays one of its programmes for a whole day', () => {
  const pub = withMix();
  for (let day = MONDAY; day < MONDAY + 30; day++) {
    const morning = programmeAt(pub, at(day, SIX_AM));
    assert(['day', 'night'].includes(morning), 'a programme from the playlist');
    eq(programmeAt(pub, at(day + 1, SIX_AM - 1)), morning, 'the same programme until the day ends');
  }
  eq(runAt(pub, at(MONDAY, 12 * HOUR)).playlistId, 'mix', 'the run says which playlist drew it');
});

test('a playlist draws afresh each day, the same for everyone', () => {
  const picks = [];
  for (let day = MONDAY; day < MONDAY + 60; day++) picks.push(programmeAt(withMix(), at(day, 12 * HOUR)));
  assert(picks.includes('day') && picks.includes('night'), 'both programmes come up over two months');
  const again = [];
  for (let day = MONDAY; day < MONDAY + 60; day++) again.push(programmeAt(withMix(), at(day, 12 * HOUR)));
  eq(again.join(), picks.join(), 'and a second listener hears the same days');
  const seeded = [];
  for (let day = MONDAY; day < MONDAY + 60; day++) seeded.push(programmeAt(withMix({ shuffleSeed: 'another' }), at(day, 12 * HOUR)));
  assert(seeded.join() !== picks.join(), 'another station draws its own days');
});

test('a playlist works in a cycle and in an override', () => {
  const pub = withMix({
    schedule: { plans: [{ startsOn: MONDAY, days: ['playlist:mix', 'playlist:mix', 'playlist:mix', 'playlist:mix', 'day'] }], overrides: [[MONDAY + 7, 'playlist:mix']] },
  });
  for (let day = MONDAY; day < MONDAY + 4; day++) {
    assert(['day', 'night'].includes(programmeAt(pub, at(day, 12 * HOUR))), 'Monday to Thursday draw from the playlist');
    eq(runAt(pub, at(day, 12 * HOUR)).playlistId, 'mix', 'each of those days is a draw');
  }
  eq(runAt(pub, at(MONDAY + 4, 12 * HOUR)).playlistId, null, 'Friday names a programme');
  const special = runAt(pub, at(MONDAY + 7, 12 * HOUR));
  eq(special.override, true, 'an override can draw too');
  assert(['day', 'night'].includes(special.programmeId), 'from the same playlist');
});

test('a playlist skips what has nothing to play, and is off air when nothing has', () => {
  const programmes = {
    ...station().programmes,
    empty: { name: 'Empty', mode: 'shuffle', items: [] },
  };
  const skips = withMix({ programmes, playlists: { mix: { name: 'Mix', programmes: ['empty', 'night'] } } });
  for (let day = MONDAY; day < MONDAY + 20; day++) eq(programmeAt(skips, at(day, 12 * HOUR)), 'night', 'never the empty one');
  const silent = withMix({ programmes, playlists: { mix: { name: 'Mix', programmes: ['empty'] } } });
  eq(resolve(silent, at(MONDAY, 12 * HOUR)).state, 'off-air', 'nothing to draw is off air');
});

test('the validator knows playlists', () => {
  const missing = validatePublication(withMix({ schedule: { plans: [{ startsOn: MONDAY, days: ['playlist:nope'] }], overrides: [] } }));
  assert(missing.errors.some((e) => e.includes('does not exist')), 'naming a playlist that is not there');
  const stray = validatePublication(withMix({ playlists: { mix: { name: 'Mix', programmes: ['day', 'gone'] } } }));
  assert(stray.errors.some((e) => e.includes('holds a programme that does not exist')), 'a playlist holding a missing programme');
  const empty = validatePublication(withMix({ playlists: { mix: { name: 'Mix', programmes: [] } } }));
  assert(empty.warnings.some((w) => w.includes('nothing to play')), 'an empty playlist is a warning');
  eq(validatePublication(withMix()).errors.length, 0, 'a good one passes');
});

/* --------------------------------------------------------------- playback */

test('the programme length is the sum of every occurrence', () => {
  eq(prepareProgramme(station(), 'day').totalMs, DAY_LENGTH, 'length');
});

test('a listener joins partway into whatever is on', () => {
  const pub = station();
  const pos = resolve(pub, at(MONDAY, SIX_AM + 100000));
  eq(pos.state, 'on-air', 'on air');
  assert(pos.sourceOffsetMs >= 0 && pos.sourceOffsetMs < pos.track.durationMs, 'inside its track');
  eq(pos.programmeId, 'day', 'playing the standing programme');
});

test('slots tile a pass with no gap and no overlap', () => {
  const pub = station();
  let t = at(MONDAY, SIX_AM);
  let covered = 0, slots = 0;
  while (covered < DAY_LENGTH) {
    const pos = resolve(pub, t);
    eq(pos.slotStartMs, t, 'each slot starts where the last ended');
    covered += pos.slotEndMs - pos.slotStartMs;
    slots++;
    t = pos.nextBoundaryMs;
  }
  eq(covered, DAY_LENGTH, 'one whole pass covered');
  eq(slots, 7, 'seven slots, the ident counted three times');
});

test('every copy of every track airs once per pass', () => {
  const pub = station();
  for (const pass of [0, 1, 17, 900]) {
    const counts = new Map();
    let t = at(MONDAY, SIX_AM) + pass * DAY_LENGTH;
    const end = t + DAY_LENGTH;
    while (t < end) {
      const pos = resolve(pub, t);
      counts.set(pos.track.id, (counts.get(pos.track.id) || 0) + 1);
      t = pos.nextBoundaryMs;
    }
    eq(counts.get('a'), 1, `pass ${pass}: Alpha`);
    eq(counts.get('i'), 3, `pass ${pass}: the ident, three times`);
  }
});

test('the programme repeats through the day and resets when the next run starts', () => {
  const pub = station();
  const passes = Math.floor(DAY_MS / DAY_LENGTH);
  assert(passes > 1000, 'a 45s programme goes round many times a day');

  const early = resolve(pub, at(MONDAY, SIX_AM + 1000));
  const late = resolve(pub, at(MONDAY + 1, SIX_AM - 1000));
  eq(early.pass, 0, 'first pass of the day');
  assert(late.pass > 1000, 'many passes later, still the same run');
  eq(late.day, MONDAY, 'still Monday programme');

  // And at 06:00 the next day it is pass zero again — the reset.
  eq(resolve(pub, at(MONDAY + 1, SIX_AM)).pass, 0, 'reset at the start of the day');
  eq(resolve(pub, at(MONDAY + 1, SIX_AM)).day, MONDAY + 1, 'of the new day');
});

test('the last pass of the day is cut off rather than overrunning', () => {
  const pub = station();
  const handover = at(MONDAY + 1, SIX_AM);
  const before = resolve(pub, handover - 1);
  eq(before.slotEndMs, handover, 'the final slot is clipped to the handover');
  assert(before.slotEndMs - before.slotStartMs < before.track.durationMs,
    'and so is shorter than the track it holds');
  eq(resolve(pub, handover).sourceOffsetMs, 0, 'the new day starts a track cleanly');
});

test('a programme longer than a day has a tail that never airs', () => {
  const pub = station();
  pub.tracks.long = { id: 'long', title: 'Long', mediaUrl: 'l.wav', durationMs: 30 * 3600000 };
  pub.programmes.day.items = [{ trackId: 'long' }];

  const last = resolve(pub, at(MONDAY + 1, SIX_AM - 1));
  eq(last.pass, 0, 'still the first pass after 24 hours');
  eq(last.sourceOffsetMs, DAY_MS - 1, 'the day ends 6 hours short of the track');
  eq(resolve(pub, at(MONDAY + 1, SIX_AM)).sourceOffsetMs, 0, 'and starts again from zero');
});

/* ------------------------------------------------------------- the shuffle */

test('each pass draws a different order, and the same one on every device', () => {
  const pub = station();
  const orders = new Set();
  for (let pass = 0; pass < 200; pass++) {
    orders.add(orderFor(pub, 'day', MONDAY, pass).entries.map((e) => e.key).join(','));
  }
  assert(orders.size > 150, `only ${orders.size} distinct orders in 200 passes`);

  const again = station();
  eq(orderFor(again, 'day', MONDAY, 7).entries.map((e) => e.key).join(','),
     orderFor(pub, 'day', MONDAY, 7).entries.map((e) => e.key).join(','),
     'a separately parsed copy draws the same order');
});

test('the same pass number on a different day is a different order', () => {
  const pub = station();
  const monday = orderFor(pub, 'day', MONDAY, 0).entries.map((e) => e.key).join(',');
  const tuesday = orderFor(pub, 'day', MONDAY + 1, 0).entries.map((e) => e.key).join(',');
  assert(monday !== tuesday, 'days should differ');
});

test('an ordered programme plays its published sequence every pass', () => {
  const pub = station({ schedule: { plans: [{ startsOn: MONDAY, days: ['night'] }], overrides: [] } });
  const seen = [];
  let t = at(MONDAY, SIX_AM);
  for (let i = 0; i < 4; i++) {
    const pos = resolve(pub, t);
    seen.push(pos.track.id);
    t = pos.nextBoundaryMs;
  }
  eq(seen.join(','), 'c,b,c,b', 'the same order, round and round');
});

/* -------------------------------------------------------------- determinism */

test('two independent copies resolve any instant identically', () => {
  const a = station(), b = station();
  for (let i = 0; i < 4000; i++) {
    const t = at(MONDAY, SIX_AM) + Math.floor(Math.random() * 400 * DAY_MS);
    const x = resolve(a, t), y = resolve(b, t);
    eq(x.track.id, y.track.id, `track at ${t}`);
    eq(x.sourceOffsetMs, y.sourceOffsetMs, 'and the same position in it');
  }
});

test('resolution cost does not grow with the age of the station', () => {
  const pub = station();
  const sample = (base) => {
    const t0 = process.hrtime.bigint();
    for (let i = 0; i < 20000; i++) resolve(pub, base + i * 37);
    return Number(process.hrtime.bigint() - t0) / 1e6;
  };
  sample(at(MONDAY, SIX_AM));
  const young = sample(at(MONDAY + 1, SIX_AM));
  const old = sample(at(MONDAY + 365 * 40, SIX_AM));
  assert(old < young * 4 + 5, `40 years in took ${old.toFixed(1)}ms vs ${young.toFixed(1)}ms fresh`);
});

/* -------------------------------------------------------------- edge cases */

test('a station switched off is off air, whatever its schedule says', () => {
  const pub = station({ enabled: false });
  const pos = resolve(pub, at(MONDAY, SIX_AM + 1000));
  eq(pos.state, 'off-air', 'state');
  eq(pos.reason, 'disabled', 'reason');
  eq(upcoming(pub, at(MONDAY, SIX_AM), 3).length, 0, 'and nothing is coming up');
});

test('an empty programme reports itself off air rather than throwing', () => {
  const pub = station();
  pub.programmes.day.items = [];
  eq(resolve(pub, at(MONDAY, SIX_AM + 1000)).reason, 'empty-programme', 'reason');
});

test('a programme entry naming a missing track is skipped, not fatal', () => {
  const pub = station();
  pub.programmes.day.items.push({ trackId: 'ghost' });
  eq(prepareProgramme(pub, 'day').totalMs, DAY_LENGTH, 'length is unchanged');
});

test('an unmeasured track cannot take up airtime', () => {
  const pub = station();
  pub.tracks.a.durationMs = 0;
  eq(prepareProgramme(pub, 'day').totalMs, DAY_LENGTH - 7003, 'it is left out');
});

test('"coming up" returns distinct consecutive slots', () => {
  const pub = station();
  const next = upcoming(pub, at(MONDAY, SIX_AM + 1000), 3);
  eq(next.length, 3, 'three entries');
  eq(next[0].sourceOffsetMs, 0, 'each begins at its own start');
  eq(next[0].slotEndMs, next[1].slotStartMs, 'and they are contiguous');
});

/* -------------------------------------------------------------- validation */

test('validation blocks an unmeasured track', () => {
  const pub = station();
  pub.tracks.b.durationMs = 0;
  assert(validatePublication(pub).errors.some((e) => /must be measured/.test(e)), 'should block');
});

test('validation blocks a programme holding a track outside the library', () => {
  const pub = station();
  pub.programmes.day.items.push({ trackId: 'ghost' });
  assert(validatePublication(pub).errors.some((e) => /not in the library/.test(e)), 'should block');
});

test('a station with no programmes can be saved and reports itself off air', () => {
  const pub = station({ programmes: {}, tracks: {}, schedule: { plans: [], overrides: [] } });
  eq(validatePublication(pub).errors.length, 0, 'valid while it is being set up');
  eq(resolve(pub, at(MONDAY, SIX_AM)).state, 'off-air', 'nothing to play yet');
});

test('validation warns that a programme longer than a day has a dead tail', () => {
  const pub = station();
  pub.tracks.long = { id: 'long', title: 'Long', mediaUrl: 'l.wav', durationMs: 30 * 3600000 };
  pub.programmes.day.items = [{ trackId: 'long' }];
  const { warnings } = validatePublication(pub);
  assert(warnings.some((w) => /never air/.test(w)), 'should warn: ' + JSON.stringify(warnings));
});

test('validation warns that a fixed order starves its own tail', () => {
  const pub = station();
  const { warnings } = validatePublication(pub);
  assert(warnings.some((w) => /cut short every day/.test(w)),
    'the ordered night programme should warn: ' + JSON.stringify(warnings));
});

test('validation blocks two overrides on the same day, or a missing programme', () => {
  const twice = station({ schedule: { plans: [{ startsOn: MONDAY, days: ['day'] }], overrides: [[MONDAY, 'night'], [MONDAY, 'day']] } });
  assert(validatePublication(twice).errors.some((e) => /same day/.test(e)), 'should block duplicates');
  const ghost = station({ schedule: { plans: [{ startsOn: MONDAY, days: ['day'] }], overrides: [[MONDAY, 'ghost']] } });
  assert(validatePublication(ghost).errors.some((e) => /does not exist/.test(e)), 'should block a ghost');
});

test('validation warns when an enabled station has no plan', () => {
  const pub = station({ schedule: { plans: [], overrides: [] } });
  assert(validatePublication(pub).warnings.some((e) => /no plan/.test(e)), 'should warn');
});

test('a clean publication validates with no errors', () => {
  eq(validatePublication(station()).errors.length, 0, 'errors');
});

test('a publication in a format this player cannot read is refused, not guessed at', () => {
  for (const version of [2, 0, '1', null, undefined]) {
    const pub = station();
    if (version === undefined) delete pub.programmeVersion; else pub.programmeVersion = version;
    const { errors } = validatePublication(pub);
    assert(errors.length === 1 && /cannot be read/.test(errors[0]),
      `format ${JSON.stringify(version)} should be refused, got ${JSON.stringify(errors)}`);
  }
  eq(validatePublication(station({ programmeVersion: PROGRAMME_VERSION })).errors.length, 0, 'the version it does read');
});

/* ------------------------------------------------------------------ report */

for (const f of failures) console.log(`  FAIL  ${f.name}\n        ${f.message}`);
const total = passed + failures.length;
console.log(`\n${passed}/${total} passed` + (failures.length ? `, ${failures.length} failed` : ''));
process.exit(failures.length ? 1 : 0);
