/**
 * The one job the browser has in the editor: measuring media.
 *
 * The operator should not have to know how long a file is, and the server has
 * no decoder. So the browser loads each unmeasured track's metadata, reports
 * the length it found, and the row fills in. Failures are stated plainly —
 * an unmeasurable file cannot take airtime, and should not be.
 */

import { num, t } from './i18n.js';

const table = document.getElementById('tracks');
if (table) measurePending();

async function measurePending() {
  const csrf = document.querySelector('input[name="csrf"]').value;
  const endpoint = table.dataset.endpoint;

  const rows = [...table.querySelectorAll('tr[data-track]')]
    .filter((row) => Number(row.dataset.duration) <= 0);
  const state = document.getElementById('measure-state');

  let done = 0;
  let liveError = '';
  for (const row of rows) {
    const cell = row.querySelector('.duration');
    try {
      const body = await measureTrack(endpoint, csrf, Number(row.dataset.track), row.dataset.audio
        ? new URL(row.dataset.audio, location.href).href
        : new URL(row.dataset.media, location.href).href);
      cell.textContent = clock(body.durationMs);
      row.dataset.duration = String(body.durationMs);
      if (!body.live && state) {
        liveError = body.liveError || t('live update waiting');
        state.title = liveError;
      } else if (body.live) {
        liveError = '';
        if (state) state.removeAttribute('title');
      }
    } catch (err) {
      const bad = document.createElement('span');
      bad.className = 'bad';
      bad.title = String(err.message || err);
      bad.textContent = t('unreadable');
      cell.replaceChildren(bad);
    }
    done++;
    if (state) {
      state.textContent = liveError
        ? t('measured; live update waiting')
        : (done < rows.length ? t('{n} to measure', { n: rows.length - done }) : t('measured'));
    }
  }

}

/**
 * Measure one track's file and record the length. Resolves with the
 * panel's answer: { durationMs, live, liveError }.
 */
export async function measureTrack(endpoint, csrf, trackId, url) {
  const durationMs = await measure(url);
  const res = await fetch(endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ action: 'measure', csrf, track_id: trackId, duration_ms: durationMs }),
  });
  const body = await res.json();
  if (!res.ok) throw new Error(body.error || ('HTTP ' + res.status));
  return body;
}

function measure(url) {
  return new Promise((resolve, reject) => {
    const probe = new Audio();
    probe.preload = 'metadata';
    probe.crossOrigin = 'anonymous';

    const done = (fn) => () => {
      clearTimeout(timer);
      probe.removeAttribute('src');
      fn();
    };
    const timer = setTimeout(done(() => reject(new Error(t('timed out')))), 20000);

    probe.addEventListener('loadedmetadata', () => {
      const seconds = probe.duration;
      if (!Number.isFinite(seconds) || seconds <= 0) {
        return done(() => reject(new Error(t('the file reports no length'))))();
      }
      done(() => resolve(Math.round(seconds * 1000)))();
    });
    probe.addEventListener('error', () => done(() => reject(new Error(t('could not be loaded'))))());

    probe.src = url;
  });
}

function clock(ms) {
  const t = Math.floor(ms / 1000);
  const h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), s = t % 60;
  return num(h > 0
    ? h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0')
    : m + ':' + String(s).padStart(2, '0'));
}
