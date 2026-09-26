/**
 * Times in, times out.
 *
 * Everything this system stores, publishes and computes is UTC, because a UTC
 * day is always exactly 24 hours and needs no timezone database. But nobody
 * thinks in UTC, so every time field here is filled in and read back in the
 * operator's own clock, and converted at the edge — here — and nowhere else.
 *
 * A field carries the stored value as `data-utc` (ms after 00:00 UTC, or empty
 * for "none"). On load it is shown as local time; on submit it is converted
 * back to UTC milliseconds. The UTC value is what the form actually posts.
 */

import { num } from './i18n.js';

const MINUTE = 60000;
const DAY = 86400000;

/** The browser's offset from UTC right now, in ms. Positive means ahead. */
function offsetMs() {
  return -new Date().getTimezoneOffset() * MINUTE;
}

const wrap = (ms) => ((ms % DAY) + DAY) % DAY;

function toLocalField(utcMs) {
  const local = wrap(utcMs + offsetMs());
  const h = String(Math.floor(local / 3600000)).padStart(2, '0');
  const m = String(Math.floor((local % 3600000) / MINUTE)).padStart(2, '0');
  return h + ':' + m;
}

function toUtcMs(value) {
  const [h, m] = value.split(':').map(Number);
  if (!Number.isFinite(h) || !Number.isFinite(m)) return null;
  return wrap(h * 3600000 + m * MINUTE - offsetMs());
}

for (const element of document.querySelectorAll('[data-local-clock]')) {
  const utcMs = Number(element.dataset.localClock);
  if (!Number.isFinite(utcMs)) continue;
  element.textContent = num(toLocalField(utcMs));
  element.title = num(toUtcFieldLabel(utcMs)) + ' UTC';
}

function toUtcFieldLabel(utcMs) {
  const h = String(Math.floor(utcMs / 3600000)).padStart(2, '0');
  const m = String(Math.floor((utcMs % 3600000) / MINUTE)).padStart(2, '0');
  return h + ':' + m;
}

for (const field of document.querySelectorAll('.local-time')) {
  const stored = field.dataset.utc;
  const name = field.getAttribute('name');

  // The visible field is local and carries no name; a hidden twin posts UTC.
  const hidden = document.createElement('input');
  hidden.type = 'hidden';
  hidden.name = name;
  field.removeAttribute('name');
  field.after(hidden);

  if (stored !== '' && stored !== undefined) {
    field.value = toLocalField(Number(stored));
    hidden.value = String(Number(stored));
  }

  const sync = () => {
    if (field.value === '') { hidden.value = ''; return; }
    const utc = toUtcMs(field.value);
    hidden.value = utc === null ? '' : String(utc);
    note.textContent = utc === null ? '' : '= ' + num(toUtcField(utc)) + ' UTC';
  };

  const toUtcField = (utcMs) => {
    const h = String(Math.floor(utcMs / 3600000)).padStart(2, '0');
    const m = String(Math.floor((utcMs % 3600000) / MINUTE)).padStart(2, '0');
    return h + ':' + m;
  };

  // Say what it will actually be stored as. The operator sets their own clock
  // and can still see the number that ends up in the publication.
  const note = document.createElement('span');
  note.className = 'utc-note';
  field.parentNode.insertBefore(note, field.nextSibling.nextSibling);

  field.addEventListener('input', sync);
  field.addEventListener('change', sync);
  sync();
}

/** Anything tagged `data-utc-ms` is a stored instant shown in local time. */
for (const el of document.querySelectorAll('[data-utc-ms]')) {
  const ms = Number(el.dataset.utcMs);
  if (!Number.isFinite(ms)) continue;
  el.title = new Date(ms).toLocaleString();
}
