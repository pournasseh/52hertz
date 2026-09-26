/** Deterministic programme playout. Edits retain their previous broadcast;
 * all listeners (including new arrivals) calculate the same handover. */
import { runAt, orderFor, prepareProgramme, collectionTrackFor, DAY_MS } from './schedule.js';

const cache = new WeakMap();
function memo(pub) { if (!cache.has(pub)) cache.set(pub, { runs: [] }); return cache.get(pub); }
const off = (reason, nextBoundaryMs = null) => ({ state: 'off-air', reason, track: null, nextBoundaryMs, programme: null, programmeId: null });

export function activationAt(pub) {
  if (!pub.handover) return null;
  const state = memo(pub);
  if (!state.activation) {
    const old = resolveProgramme(pub.handover.previous, pub.handover.requestedAtMs);
    state.activation = { at: old.state === 'on-air' ? old.slotEndMs : pub.handover.requestedAtMs, old };
  }
  return state.activation.at;
}

function eventsBetween(pub, programme, start, end) {
  const result = [];
  for (const event of programme.events || []) {
    const period = event.repeat === 'hourly' ? 3600000 : DAY_MS;
    let at = Math.floor(start / period) * period + event.timeMs;
    if (at < start) at += period;
    for (; at < end; at += period) {
      const track = event.collectionId !== undefined
        ? collectionTrackFor(pub, event.collectionId, `event:${event.id || ''}:${period}`, Math.floor(at / period))
        : pub.tracks[event.trackId];
      if (track?.durationMs > 0) result.push({ ...event, at, track });
    }
  }
  return result.sort((a,b) => a.at - b.at || (a.timing === 'strict' ? -1 : 1));
}

function plainPosition(pub, run, t) {
  const prepared = prepareProgramme(pub, run.programmeId);
  if (!prepared.totalMs) return null;
  const within = Math.max(0, t - run.startMs);
  const pass = Math.floor(within / prepared.totalMs);
  const order = orderFor(pub, run.programmeId, run.day, pass);
  const offset = within % prepared.totalMs;
  let lo = 0, hi = order.entries.length - 1;
  while (lo < hi) { const m = (lo + hi + 1) >> 1; if (order.prefixMs[m] <= offset) lo = m; else hi = m - 1; }
  const entry = order.entries[lo];
  const start = run.startMs + pass * prepared.totalMs + order.prefixMs[lo];
  return { entry, start, end: start + entry.durationMs, pass, index: lo, completedKeys: order.entries.slice(0, lo + 1).map(x => x.key) };
}

function compile(pub, nominal, start, continuation = null) {
  const programme = pub.programmes[nominal.programmeId];
  const run = { ...nominal, startMs: start, nominalEndMs: nominal.endMs, endMs: nominal.endMs };
  if (!programme) return run;
  const events = eventsBetween(pub, programme, start, nominal.endMs);
  if (!events.length && !continuation && !prepareProgramme(pub, nominal.programmeId).hasCollections) {
    return run;
  }
  run.slots = [];
  let cursor = start, pass = continuation?.pass ?? 0, index = 0;
  let order = continuation?.entries ?? orderFor(pub, nominal.programmeId, nominal.day, pass).entries;
  let completed = continuation?.completed ?? [];
  let eventIndex = 0, guard = 0;
  while (cursor < nominal.endMs && guard++ < 300000) {
    let event = events[eventIndex];
    let entry, eventSlot = false;
    if (event && event.at <= cursor) {
      entry = { track: event.track, durationMs: event.track.durationMs, key: 'event:' + event.id + ':' + event.at };
      eventIndex++; eventSlot = true;
    } else {
      if (index >= order.length) {
        pass++; index = 0; completed = [];
        order = orderFor(pub, nominal.programmeId, nominal.day, pass).entries;
      }
      if (!order.length) {
        if (!event) break;
        cursor = event.at; continue;
      }
      entry = order[index++]; completed = [...completed, entry.key];
    }
    let end = cursor + entry.durationMs;
    const hard = events.slice(eventIndex).find(e => e.timing === 'strict' && e.at > cursor && e.at < end);
    if (hard) end = hard.at;
    // A programme owns exactly one programme day. The boundary is hard even
    // when it lands inside a track; content duration never moves the schedule.
    end = Math.min(end, nominal.endMs);
    if (end <= cursor) break;
    run.slots.push({ entry, start: cursor, end, pass, index: index - 1, completedKeys: completed, event: eventSlot ? event : null });
    cursor = end;
  }
  return run;
}

function locate(pub, run, t) {
  if (!run.slots) return plainPosition(pub, run, t);
  let lo = 0, hi = run.slots.length - 1;
  while (lo < hi) { const m = (lo + hi + 1) >> 1; if (run.slots[m].start <= t) lo = m; else hi = m - 1; }
  const slot = run.slots[lo];
  return slot && slot.start <= t && t < slot.end ? slot : null;
}

export function resolveProgramme(pub, t) {
  t = Math.floor(t);
  if (pub.enabled === false) return off('disabled');
  const state = memo(pub);
  const at = activationAt(pub);
  if (at !== null && t < at) return resolveProgramme(pub.handover.previous, t);
  if (!state.runs.length) {
    let nominal, start, continuation = null;
    if (at !== null) {
      const old = state.activation.old;
      start = at;
      // Edits belong to the remainder of the current run, never to its past.
      nominal = runAt(pub, at);
      if (old.state === 'on-air' && old.programmeId === nominal.programmeId && at < old.runEndMs) {
        const consumed = new Set(old.completedKeys || []);
        const order = orderFor(pub, nominal.programmeId, nominal.day, old.pass ?? 0).entries;
        const entries = order.filter(x => !consumed.has(x.key));
        continuation = { entries, completed: [...consumed], pass: old.pass ?? 0 };
      }
    } else {
      start = Number(pub.epochMs) || runAt(pub, t).startMs;
      if (t < start) start = runAt(pub, t).startMs;
      nominal = runAt(pub, start);
    }
    state.runs.push(compile(pub, nominal, start, continuation));
  }
  let last = state.runs.at(-1);
  // Usually just one iteration at the next programme boundary. Arithmetic
  // within ordinary runs avoids building a day's worth of tiny audio slots.
  while (t >= last.endMs) {
    const nominal = runAt(pub, last.endMs);
    const next = compile(pub, nominal, last.endMs);
    if (next.endMs <= last.endMs) return off('nothing-scheduled');
    state.runs.push(next); last = next;
  }
  let lo = 0, hi = state.runs.length - 1;
  while (lo < hi) { const m = (lo + hi + 1) >> 1; if (state.runs[m].startMs <= t) lo = m; else hi = m - 1; }
  let run = state.runs[lo];
  if (t < run.startMs) run = compile(pub, runAt(pub, t), runAt(pub, t).startMs);
  const programme = pub.programmes[run.programmeId];
  if (!programme) return off('nothing-scheduled', run.endMs);
  const slot = locate(pub, run, t);
  if (!slot) {
    const next = run.slots?.find(x => x.start > t)?.start;
    return off('empty-programme', next ?? run.endMs);
  }
  const end = Math.min(slot.end, run.endMs);
  const passLength = orderFor(pub, run.programmeId, run.day, slot.pass).prefixMs.at(-1) || 0;
  return { state: 'on-air', reason: null, track: slot.entry.track, entryKey: slot.entry.key,
    sourceOffsetMs: t - slot.start, slotStartMs: slot.start, slotEndMs: end, nextBoundaryMs: end,
    programmeId: run.programmeId, programme: { id: run.programmeId, name: programme.name, mode: programme.mode },
    day: run.day, override: run.override, playlistId: run.playlistId, pass: slot.pass, passIndex: slot.index,
    programmeLengthMs: passLength,
    runStartMs: run.startMs, runEndMs: run.endMs, completedKeys: slot.completedKeys,
    event: slot.event || null, cut: end < slot.start + slot.entry.durationMs };
}
