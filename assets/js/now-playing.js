/**
 * What each station is playing right now, wherever the page asks for it:
 * a cell on the stations list, a line under a station's name.
 *
 * Resolved here with the same engine every player runs, from the station as
 * it is saved, so it is what a listener hears at this moment. It moves on by
 * itself when the track ends.
 */

import { resolve } from '../../engine/schedule.js';
import { t } from './i18n.js';

const REFRESH_MS = 5 * 60 * 1000;

const escape = (text) => String(text ?? '').replace(/[&<>"]/g, (c) => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;',
}[c]));

function describe(pos) {
  if (pos.state === 'on-air') {
    return { title: pos.track.title || pos.track.id, credit: pos.track.credit || '' };
  }
  return { off: pos.reason === 'disabled' ? t('Off air') : t('Nothing to play') };
}

function paint(element, pos) {
  const what = describe(pos);
  const isCell = element.tagName === 'TD';
  if (what.off) {
    element.innerHTML = `<span class="muted">${what.off}</span>`;
    return;
  }
  element.innerHTML = isCell
    ? `<span class="strong">${escape(what.title)}</span>` +
      (what.credit ? `<span class="sub-line">${escape(what.credit)}</span>` : '')
    : `<span class="now-label">${escape(t('Now playing'))}</span> <strong>${escape(what.title)}</strong>` +
      (what.credit ? ` <span class="muted">· ${escape(what.credit)}</span>` : '');
}

async function follow(element) {
  let publication = null;
  let timer = 0;

  const tick = () => {
    clearTimeout(timer);
    if (!publication) return;
    const pos = resolve(publication, Date.now());
    paint(element, pos);
    const next = pos.nextBoundaryMs ?? null;
    if (next !== null && Number.isFinite(next)) {
      timer = setTimeout(tick, Math.max(1000, next - Date.now() + 250));
    }
  };

  const load = async () => {
    try {
      const res = await fetch(element.dataset.nowPlaying, {
        headers: { Accept: 'application/json' }, credentials: 'same-origin',
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      publication = await res.json();
      tick();
    } catch {
      // Nothing to say is better than a wrong answer.
      if (!publication) element.hidden = element.tagName !== 'TD';
    }
  };

  await load();
  setInterval(load, REFRESH_MS);
}

for (const element of document.querySelectorAll('[data-now-playing]')) follow(element);
