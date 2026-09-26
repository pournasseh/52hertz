/**
 * Static playout — schedule resolution.
 *
 * One pure function decides what a station is playing at an instant:
 *
 *   resolve(publication, nowMs) -> position
 *
 * No network, no audio, no clock of its own, and — deliberately — no calendar.
 * Everything here is UTC integer arithmetic on a day that is always exactly
 * 86,400,000 ms long, so there is no timezone database, no daylight-saving
 * rule, and no horizon past which a publication stops working. The panel
 * converts the operator's local clock into these numbers once, at the edge.
 *
 * The model, in three words: **days, plans, passes.**
 *
 *   A day  is a programme day: 24 hours from the station's own start time.
 *   A plan is a dated, repeating cycle with one choice per programme day. A
 *          one-day plan means "always"; seven days make a weekly plan; any
 *          length is valid. A one-off override may replace one chosen day.
 *   A run  is one programme day of the programme selected by that plan.
 *   A pass is one time through the programme inside that run. A programme
 *          shorter than the day repeats; the final pass is cut off by the next
 *          day rather than being allowed to overrun.
 *
 * Anywhere the schedule names a programme it may name a playlist instead, as
 * "playlist:<id>". That day then plays one programme drawn from the playlist
 * at random: a fresh, independent draw each day, the same for every listener
 * because it comes from the station's seed and the day number alone.
 */

import { resolveProgramme } from './programme.js';

/**
 * The publication format this engine reads. The panel writes it into every
 * publication and `validatePublication()` refuses anything else, so a player
 * that has not been updated says so instead of misplaying a newer station.
 */
export const PROGRAMME_VERSION = 1;

export const DAY_MS = 86400000;

/* ---------------------------------------------------------------- hashing */

function fnv1a(str) {
  let h = 0x811c9dc5;
  for (let i = 0; i < str.length; i++) {
    h ^= str.charCodeAt(i);
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  return h >>> 0;
}

/** Small, fast, well-distributed PRNG with a 128-bit state. */
function sfc32(a, b, c, d) {
  return function next() {
    a >>>= 0; b >>>= 0; c >>>= 0; d >>>= 0;
    let t = (a + b) >>> 0;
    a = b ^ (b >>> 9);
    b = (c + (c << 3)) >>> 0;
    c = (c << 21) | (c >>> 11);
    d = (d + 1) >>> 0;
    t = (t + d) >>> 0;
    c = (c + t) >>> 0;
    return (t >>> 0) / 4294967296;
  };
}

function seededRandom(seedText) {
  const rand = sfc32(
    fnv1a(seedText + '\u0000a'),
    fnv1a(seedText + '\u0000b'),
    fnv1a(seedText + '\u0000c'),
    fnv1a(seedText + '\u0000d')
  );
  for (let i = 0; i < 12; i++) rand();   // discard weak first draws
  return rand;
}

/**
 * One deterministic shuffle-bag draw from a collection. `scope` identifies a
 * particular stream (a programme day or timed event); consecutive indexes
 * visit every playable member once before a freshly shuffled bag begins.
 */
export function collectionTrackFor(publication, collectionId, scope, index) {
  const collection = (publication.collections || {})[String(collectionId)];
  const tracks = publication.tracks || {};
  const members = (collection && Array.isArray(collection.tracks) ? collection.tracks : [])
    .map(String).filter(id => Math.floor(Number(tracks[id]?.durationMs) || 0) > 0);
  if (!members.length) return null;
  const at = Math.max(0, Math.floor(Number(index) || 0));
  const cycle = Math.floor(at / members.length);
  const bag = members.slice();
  const rand = seededRandom((publication.shuffleSeed || '') + '\u0000collection\u0000'
    + collectionId + '\u0000' + scope + '\u0000' + cycle);
  for (let i = bag.length - 1; i > 0; i--) {
    const j = Math.floor(rand() * (i + 1));
    [bag[i], bag[j]] = [bag[j], bag[i]];
  }
  return tracks[bag[at % bag.length]] || null;
}

/* ---------------------------------------------------------------- days */

/** UTC day number. Day 0 is 1970-01-01. */
export function dayNumber(ms) {
  return Math.floor(ms / DAY_MS);
}

/**
 * Day of the week for a UTC day number, 0 = Sunday.
 * 1970-01-01 was a Thursday, hence the 4.
 */
export function weekdayOf(day) {
  return (((day + 4) % 7) + 7) % 7;
}

function dayStartOf(publication) {
  return Math.floor(Number(publication.dayStartMs) || 0);
}

/**
 * The programme day an instant belongs to. A station's day begins at its own
 * start time and lasts exactly 24 hours, so programme day `p` is the span
 * [p * DAY_MS + dayStartMs, (p + 1) * DAY_MS + dayStartMs).
 */
export function programmeDayOf(publication, t) {
  return Math.floor((t - dayStartOf(publication)) / DAY_MS);
}

/** The instant programme day `p` begins. */
export function programmeDayStart(publication, p) {
  return p * DAY_MS + dayStartOf(publication);
}

/* ------------------------------------------------------------------ runs */

const prepared = new WeakMap();

function memo(publication) {
  let store = prepared.get(publication);
  if (!store) {
    store = { passes: new Map(), lengths: new Map(), plans: null, overrides: null };
    prepared.set(publication, store);
  }
  return store;
}

function programmeOf(publication, id) {
  return id === null ? null : (publication.programmes || {})[id] || null;
}

/* ------------------------------------------------------------- playlists */

const PLAYLIST = 'playlist:';

/** The playlist a schedule entry names, or null when it names a programme. */
export function playlistOf(ref) {
  return typeof ref === 'string' && ref.startsWith(PLAYLIST) ? ref.slice(PLAYLIST.length) : null;
}

/**
 * The programme a schedule entry plays on programme day `day`. A programme
 * is itself; a playlist draws one of its programmes that has something to
 * play, or nothing - off air - when none has.
 */
export function programmeForDay(publication, ref, day) {
  if (ref === null || ref === undefined) return null;
  const playlistId = playlistOf(String(ref));
  if (playlistId === null) return String(ref);
  const playlist = (publication.playlists || {})[playlistId];
  const pool = (playlist && Array.isArray(playlist.programmes) ? playlist.programmes : [])
    .map(String)
    .filter((id) => prepareProgramme(publication, id).totalMs > 0);
  if (!pool.length) return null;
  const draw = seededRandom((publication.shuffleSeed || '') + '\u0000playlist\u0000' + playlistId + '\u0000' + day)();
  return pool[Math.floor(draw * pool.length)];
}

/** Valid dated plans in start-day order. */
function schedulePlans(publication) {
  const store = memo(publication);
  if (store.plans) return store.plans;
  const rows = Array.isArray(publication.schedule && publication.schedule.plans)
    ? publication.schedule.plans : [];
  store.plans = rows
    .map((plan) => ({
      startsOn: Number(plan && plan.startsOn),
      name: String((plan && plan.name) || ''),
      days: Array.isArray(plan && plan.days) ? plan.days.map(String) : [],
    }))
    .filter((plan) => Number.isInteger(plan.startsOn))
    .sort((a, b) => a.startsOn - b.startsOn);
  return store.plans;
}

/** One-off programme-day overrides. */
function overrideDays(publication) {
  const store = memo(publication);
  if (store.overrides) return store.overrides;
  const byDay = new Map();
  const rows = Array.isArray(publication.schedule && publication.schedule.overrides)
    ? publication.schedule.overrides : [];
  for (const row of rows) {
    const day = Number(row && row[0]);
    if (Number.isInteger(day)) byDay.set(day, String(row[1]));
  }
  store.overrides = { byDay, days: [...byDay.keys()].sort((a, b) => a - b) };
  return store.overrides;
}

/**
 * What the active plan names for programme day `p`, before an override. The
 * plan's own start day anchors position zero, so edits become new dated plans
 * rather than silently changing the meaning of earlier days.
 */
function planChoice(publication, p) {
  const plans = schedulePlans(publication);
  let plan = null;
  for (const candidate of plans) {
    if (candidate.startsOn <= p) plan = candidate;
    else break;
  }
  if (!plan || !plan.days.length) return { ref: null, plan: null, cycleDay: null };
  const distance = p - plan.startsOn;
  const cycleDay = ((distance % plan.days.length) + plan.days.length) % plan.days.length;
  return { ref: plan.days[cycleDay], plan, cycleDay };
}

/**
 * The run covering an instant: the programme day it falls in, and the
 * programme that day plays.
 *
 * An override replaces exactly one selected day; the cycle position remains
 * calendar-derived, so the plan carries on normally the following day.
 */
export function runAt(publication, t) {
  const p = programmeDayOf(publication, t);
  const startMs = programmeDayStart(publication, p);
  const selected = planChoice(publication, p);
  const override = overrideDays(publication).byDay.has(p);
  const choice = override ? overrideDays(publication).byDay.get(p) : selected.ref;
  return {
    day: p,
    startMs,
    endMs: startMs + DAY_MS,
    programmeId: programmeForDay(publication, choice, p),
    playlistId: playlistOf(choice),
    override,
    planName: selected.plan?.name || '',
    planStartsOn: selected.plan?.startsOn ?? null,
    cycleDay: selected.cycleDay,
  };
}

/**
 * The programme on air at an instant.
 *
 * For testing only: no player or panel code calls this. It stays because the
 * tests (schedule.test.mjs, pipeline.test.mjs) use it to check which
 * programme a schedule puts on air on a given day, and those checks cover live
 * scheduling rules. Removing it would lose them. Players use `resolve()`.
 */
export function programmeAt(publication, t) {
  return runAt(publication, t).programmeId;
}

/* ------------------------------------------------------------ the passes */

function expand(programme) {
  const out = [];
  const items = Array.isArray(programme.items) ? programme.items : [];
  for (let i = 0; i < items.length; i++) {
    const entry = items[i];
    const copies = Math.max(1, Math.floor(Number(entry.repeat) || 1));
    for (let n = 0; n < copies; n++) {
      out.push({
        key: (entry.entryId || entry.trackId || ('collection:' + entry.collectionId)) + '#' + n,
        trackId: entry.trackId === undefined ? null : String(entry.trackId),
        collectionId: entry.collectionId === undefined ? null : String(entry.collectionId),
        copyIndex: n,
        position: i,
        durationMs: 0,          // filled in against the track table
      });
    }
  }
  return out;
}

/** Occurrences, prefix sums and total length of one programme. O(n), cached. */
export function prepareProgramme(publication, programmeId) {
  const store = memo(publication);
  const hit = store.lengths.get(programmeId);
  if (hit) return hit;

  const programme = programmeOf(publication, programmeId);
  const tracks = publication.tracks || {};
  const occurrences = programme ? expand(programme) : [];

  for (const occurrence of occurrences) {
    const track = occurrence.collectionId === null ? tracks[occurrence.trackId] : null;
    occurrence.durationMs = Math.floor(Number(track && track.durationMs) || 0);
    occurrence.track = track || null;
  }

  const usable = occurrences.filter((o) => o.collectionId !== null || (o.durationMs > 0 && o.track !== null));
  const mode = (programme && programme.mode) === 'ordered' ? 'ordered' : 'shuffle';

  // In shuffle mode the list is sorted first, so re-ordering rows in the panel
  // cannot change what listeners hear.
  const canonical = usable.slice();
  if (mode === 'shuffle') {
    canonical.sort((x, y) => (x.key < y.key ? -1 : x.key > y.key ? 1 : 0));
  }

  const prefixMs = new Array(canonical.length + 1);
  prefixMs[0] = 0;
  for (let i = 0; i < canonical.length; i++) prefixMs[i + 1] = prefixMs[i] + canonical[i].durationMs;

  const result = {
    mode, canonical, prefixMs, totalMs: prefixMs[canonical.length],
    hasCollections: canonical.some(entry => entry.collectionId !== null),
  };
  store.lengths.set(programmeId, result);
  return result;
}

/** The order for one pass. Shuffled per day and per pass, so it never repeats. */
export function orderFor(publication, programmeId, day, pass) {
  const store = memo(publication);
  const cacheKey = programmeId + '|' + day + '|' + pass;
  const hit = store.passes.get(cacheKey);
  if (hit) return hit;

  const base = prepareProgramme(publication, programmeId);
  const totals = new Map();
  for (const entry of base.canonical) {
    if (entry.collectionId !== null) totals.set(entry.collectionId, (totals.get(entry.collectionId) || 0) + 1);
  }
  const seen = new Map();
  const resolved = base.canonical.map(entry => {
    if (entry.collectionId === null) return entry;
    const ordinal = seen.get(entry.collectionId) || 0;
    seen.set(entry.collectionId, ordinal + 1);
    const drawIndex = pass * totals.get(entry.collectionId) + ordinal;
    const track = collectionTrackFor(publication, entry.collectionId, `programme:${programmeId}:${day}`, drawIndex);
    return track ? { ...entry, trackId: String(track.id), track, durationMs: Math.floor(Number(track.durationMs) || 0) } : null;
  }).filter(Boolean);
  let order;

  if (base.mode === 'ordered') {
    const prefixMs = new Array(resolved.length + 1); prefixMs[0] = 0;
    for (let i = 0; i < resolved.length; i++) prefixMs[i + 1] = prefixMs[i] + resolved[i].durationMs;
    order = { entries: resolved, prefixMs };
  } else {
    const entries = resolved.slice();
    const seed = (publication.shuffleSeed || '') + '\u0000' + programmeId + '\u0000' + day + '\u0000' + pass;
    const rand = seededRandom(seed);
    for (let i = entries.length - 1; i > 0; i--) {          // Fisher-Yates
      const j = Math.floor(rand() * (i + 1));
      const t = entries[i]; entries[i] = entries[j]; entries[j] = t;
    }
    const prefixMs = new Array(entries.length + 1);
    prefixMs[0] = 0;
    for (let i = 0; i < entries.length; i++) prefixMs[i + 1] = prefixMs[i] + entries[i].durationMs;
    order = { entries, prefixMs };
  }

  if (store.passes.size > 64) store.passes.clear();
  store.passes.set(cacheKey, order);
  return order;
}

/* ----------------------------------------------------------------- resolve */

/**
 * What is on air at `nowMs`.
 *
 * Returns the intended position: the track, how far into it the station is,
 * the slot it occupies, and the next instant at which the answer changes.
 * Whether the audio has actually loaded is the player's problem, not this
 * function's.
 *
 * The work is done in programme.js. This stays the engine's entry point
 * because that is what every caller - the player, the panel preview, the
 * tests - asks for, and what they should keep asking for.
 */
export function resolve(publication, nowMs) {
  return resolveProgramme(publication, nowMs);
}

/** The next few positions after `nowMs` — for "coming up", and for prefetching. */
export function upcoming(publication, nowMs, count = 3) {
  const out = [];
  if (publication.enabled === false) return out;

  let t = Math.floor(nowMs);
  let guard = 0;
  while (out.length < count && guard++ < count * 4) {
    const here = resolve(publication, t);
    if (here.nextBoundaryMs === null) break;
    t = here.nextBoundaryMs;
    const next = resolve(publication, t);
    if (next.state !== 'on-air') break;
    out.push(next);
  }
  return out;
}

/* -------------------------------------------------------------- validation */

/**
 * Checks a publication before it is published or played. Problems come in two
 * grades: `errors` block a publish, `warnings` are shown to the operator, who
 * may well have meant it.
 */
export function validatePublication(pub) {
  const errors = [];
  const warnings = [];

  if (!pub || typeof pub !== 'object') return { errors: ['publication is not an object'], warnings };

  // A player may be older than the panel that published to it, so it says
  // plainly that it cannot read a format rather than playing it wrongly.
  // Nothing is guessed from a missing version: every publication declares one.
  if (pub.programmeVersion !== PROGRAMME_VERSION) {
    return {
      errors: ['publication format ' + JSON.stringify(pub.programmeVersion ?? null)
        + ' cannot be read by this player, which understands ' + PROGRAMME_VERSION],
      warnings,
    };
  }

  if (!pub.stationId) errors.push('stationId is missing');
  const dayStartMs = Number(pub.dayStartMs);
  if (!Number.isInteger(dayStartMs) || dayStartMs < 0 || dayStartMs >= DAY_MS) {
    errors.push('dayStartMs must be a time inside a day');
  }
  const tracks = pub.tracks || {};
  const collections = pub.collections || {};
  for (const [id, collection] of Object.entries(collections)) {
    const members = Array.isArray(collection.tracks) ? collection.tracks.map(String) : [];
    for (const member of members) {
      if (!tracks[member]) errors.push('collection "' + (collection.name || id) + '" holds a track that is not in the library');
    }
  }
  for (const [id, track] of Object.entries(tracks)) {
    const where = 'track ' + (track.title ? '"' + track.title + '"' : id);
    if (!track.mediaUrl) errors.push(where + ': mediaUrl is missing');
    if (pub.enabled !== false && !(Math.floor(Number(track.durationMs) || 0) > 0)) {
      errors.push(where + ': durationMs must be measured before publishing');
    }
  }

  const schedule = pub.schedule || {};
  const programmes = pub.programmes || {};
  for (const [id, programme] of Object.entries(programmes)) {
    const where = 'programme "' + (programme.name || id) + '"';
    if (programme.mode !== 'ordered' && programme.mode !== 'shuffle') {
      errors.push(where + ': mode must be "ordered" or "shuffle"');
    }
    const items = Array.isArray(programme.items) ? programme.items : [];
    if (!items.length) {
      warnings.push(where + ' is empty — the station reports itself off air while it runs');
    }
    for (const item of items) {
      if (item.collectionId !== undefined) {
        const collection = collections[String(item.collectionId)];
        if (!collection) errors.push(where + ': it holds a collection that does not exist');
        else if (!(collection.tracks || []).length) errors.push(where + ': it holds a collection with nothing playable');
      } else if (!tracks[item.trackId]) errors.push(where + ': it holds a track that is not in the library');
    }

    const length = items.reduce((sum, item) => {
      if (item.collectionId !== undefined) return sum;
      const track = tracks[item.trackId];
      return sum + (track ? Math.floor(Number(track.durationMs) || 0) : 0) * Math.max(1, Number(item.repeat) || 1);
    }, 0);
    const variable = items.some(item => item.collectionId !== undefined);

    for (const event of Array.isArray(programme.events) ? programme.events : []) {
      if (event.collectionId !== undefined) {
        const collection = collections[String(event.collectionId)];
        if (!collection || !(collection.tracks || []).length) errors.push(where + ': an event collection has nothing playable');
      } else if (!tracks[event.trackId]) errors.push(where + ': an event track is not in the library');
    }

    if (!variable && length > DAY_MS) {
      warnings.push(where + ': it is longer than a day, so the last '
        + Math.round((length - DAY_MS) / 60000) + ' minutes never air');
    } else if (!variable && length > 0 && programme.mode === 'ordered' && DAY_MS % length !== 0) {
      warnings.push(where + ': it plays in a fixed order and does not divide the day evenly, '
        + 'so the tracks at its end are cut short every day');
    }
  }

  const playlists = pub.playlists || {};
  for (const [id, playlist] of Object.entries(playlists)) {
    const where = 'playlist "' + (playlist.name || id) + '"';
    const members = Array.isArray(playlist.programmes) ? playlist.programmes.map(String) : [];
    for (const member of members) {
      if (!programmes[member]) errors.push(where + ' holds a programme that does not exist');
    }
    if (!members.some((member) => programmes[member] && orderFor(pub, member, 0, 0).entries.length > 0)) {
      warnings.push(where + ' has nothing to play — the station is off air on the days it is drawn');
    }
  }
  // A schedule entry names a programme, or a playlist as "playlist:<id>".
  const names = (ref) => {
    const playlistId = playlistOf(String(ref));
    return playlistId === null ? Boolean(programmes[String(ref)]) : Boolean(playlists[playlistId]);
  };

  const plans = Array.isArray(schedule.plans) ? schedule.plans : [];
  const seenStarts = new Set();
  for (const plan of plans) {
    const startsOn = Number(plan && plan.startsOn);
    if (!Number.isInteger(startsOn)) errors.push('a plan has no start day');
    if (seenStarts.has(startsOn)) errors.push('two plans start on the same day');
    seenStarts.add(startsOn);
    const days = Array.isArray(plan && plan.days) ? plan.days : [];
    if (!days.length) warnings.push('a plan has no programme days — the station is off air while it is active');
    for (const ref of days) {
      if (!names(ref)) errors.push('a plan names a programme or playlist that does not exist');
    }
  }
  if (!plans.length && pub.enabled !== false) {
    warnings.push('the station has no plan yet, so it is off air');
  }

  const seenOverrides = new Set();
  for (const row of Array.isArray(schedule.overrides) ? schedule.overrides : []) {
    const day = Number(row && row[0]);
    const programmeId = String(row && row[1]);
    if (!Number.isInteger(day)) errors.push('an override has no day');
    if (seenOverrides.has(day)) errors.push('two overrides fall on the same day');
    seenOverrides.add(day);
    if (!names(programmeId)) errors.push('an override names a programme or playlist that does not exist');
  }

  return { errors, warnings };
}
