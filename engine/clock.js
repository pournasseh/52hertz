/**
 * Time agreement.
 *
 * The schedule is derived from absolute time, so a device with a wrong clock
 * hears the wrong part of the station. This module estimates the publisher's
 * time and — just as importantly — says how sure it is.
 *
 * Deliberately modest for browser audio:
 *   - a slow round trip is not a precise measurement; uncertainty is kept
 *   - an HTTP `Date` from a cached response can be old, so `Age` is inspected
 *     and a response that admits to being cached is rejected as a time source
 *   - between samples the elapsed-time reference is monotonic, so an edit to
 *     the device's wall clock is detected rather than followed
 *   - sleep and reconnection invalidate the estimate; the caller re-samples
 *   - with no reachable source the device clock is used and *labelled* as such
 */

const monotonic = () => (typeof performance !== 'undefined' && performance.now
  ? performance.now()
  : Date.now());

export function createClock(options = {}) {
  const timeUrl = options.timeUrl || null;
  const fallbackUrl = options.fallbackUrl || null;
  const maxSampleAgeMs = options.maxSampleAgeMs || 15 * 60 * 1000;

  let best = null;          // { serverMs, atMonotonic, atWall, uncertaintyMs, source }

  function now() {
    if (!best) return Date.now();
    const drift = monotonic() - best.atMonotonic;
    // If the wall clock and the monotonic reference disagree, the device was
    // asleep or its clock was edited. Trust the monotonic one, flag the sample.
    return Math.floor(best.serverMs + drift);
  }

  function status() {
    if (!best) {
      return { source: 'device', uncertaintyMs: null, ageMs: null, offsetMs: 0, trusted: false };
    }
    const ageMs = Math.floor(monotonic() - best.atMonotonic);
    const wallDrift = Math.abs((Date.now() - best.atWall) - ageMs);
    return {
      source: best.source,
      uncertaintyMs: best.uncertaintyMs,
      ageMs,
      offsetMs: Math.floor(best.serverMs - best.atWall),
      suspected: wallDrift > 2000 ? 'clock-changed-or-slept' : null,
      trusted: ageMs < maxSampleAgeMs && best.uncertaintyMs <= 2000,
    };
  }

  function accept(sample) {
    // Prefer the tightest round trip; a fresher but sloppier sample only wins
    // once the old one is stale.
    if (!best
      || sample.uncertaintyMs <= best.uncertaintyMs
      || (monotonic() - best.atMonotonic) > maxSampleAgeMs) {
      best = sample;
    }
    return status();
  }

  async function sampleFrom(url, kind) {
    const started = monotonic();
    const res = await fetch(url, {
      cache: 'no-store',
      method: kind === 'header' ? 'HEAD' : 'GET',
      headers: kind === 'header' ? undefined : { 'Accept': 'application/json' },
    });
    const finished = monotonic();
    const rttMs = finished - started;

    let serverMs = null;
    if (kind === 'json') {
      const body = await res.json();
      if (Number.isFinite(Number(body.nowMs))) serverMs = Number(body.nowMs);
    } else {
      // A cached response carries an old Date. `Age` is the honest signal;
      // its absence does not prove freshness, so a HEAD is only ever a
      // fallback, never preferred over a real time endpoint.
      const age = res.headers.get('Age');
      if (age !== null && Number(age) > 1) throw new Error('response was served from cache');
      const date = res.headers.get('Date');
      if (date) serverMs = Date.parse(date);
    }
    if (!Number.isFinite(serverMs)) throw new Error('no usable time in the response');

    return accept({
      serverMs: serverMs + rttMs / 2,      // assume a symmetric round trip
      atMonotonic: finished,
      atWall: Date.now(),
      // Half the round trip bounds the error of that assumption. An HTTP date
      // is whole seconds, so a further second of ignorance is added.
      uncertaintyMs: Math.ceil(rttMs / 2) + (kind === 'header' ? 1000 : 0),
      source: kind === 'json' ? 'time-endpoint' : 'http-date',
    });
  }

  /** Re-estimate. Resolves to a status; never throws, never blocks playback. */
  async function sync() {
    if (timeUrl) {
      try { return await sampleFrom(timeUrl, 'json'); } catch { /* fall through */ }
    }
    if (fallbackUrl) {
      try { return await sampleFrom(fallbackUrl, 'header'); } catch { /* fall through */ }
    }
    return status();
  }

  return { now, sync, status };
}
