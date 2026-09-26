/**
 * Panel preview.
 *
 * Imports the player's own resolver, so what the operator sees here is what a
 * listener will hear — not a second, subtly different implementation.
 */

// The shared engine. The panel keeps it, the panel previews with it, and every
// player loads this same file — so what is previewed here is what is heard.
import { resolve, upcoming, validatePublication } from '../../engine/schedule.js';
import { formatDate, formatTime, listComma, LOCALE } from './dates.js';
import { num, t } from './i18n.js';

const root = document.getElementById('preview');
const draftUrl = root.dataset.draft;

const ui = {
  scrub: document.getElementById('scrub'),
  follow: document.getElementById('follow'),
  atTime: document.getElementById('at-time'),
  atOffset: document.getElementById('at-offset'),
  resolved: document.getElementById('resolved'),
  upcoming: document.getElementById('upcoming'),
  issues: document.getElementById('issues'),
};

let publication = null;

const mmss = (ms) => {
  const seconds = Math.max(0, Math.floor(ms / 1000));
  return num(Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0'));
};
const stamp = (ms) => formatDate(ms, { weekday: 'short' }) + listComma()
  + formatTime(ms, { seconds: true });
const relative = (seconds) => {
  const abs = Math.abs(seconds);
  const unit = abs < 90 ? [seconds, 'second'] :
    abs < 5400 ? [seconds / 60, 'minute'] :
      abs < 172800 ? [seconds / 3600, 'hour'] :
        abs < 63072000 ? [seconds / 86400, 'day'] : [seconds / 31536000, 'year'];
  return new Intl.RelativeTimeFormat(LOCALE, { numeric: 'auto' })
    .format(Math.round(unit[0]), unit[1]);
};

function at() {
  return Date.now() + Number(ui.scrub.value) * 1000;
}

function render() {
  if (!publication) return;
  const moment = at();
  const offset = Number(ui.scrub.value);

  ui.atTime.textContent = stamp(moment);
  ui.atOffset.textContent = offset === 0 ? t('right now') : relative(offset);

  const pos = resolve(publication, moment);

  if (pos.state !== 'on-air') {
    ui.resolved.innerHTML = '<p class="empty">' + escape(pos.reason === 'disabled'
      ? t('Off air — the station is switched off.')
      : t('Off air — there are no tracks to play.')) + '</p>';
    ui.upcoming.innerHTML = '';
    return;
  }

  const into = moment - pos.slotStartMs;
  const length = pos.slotEndMs - pos.slotStartMs;
  ui.resolved.innerHTML = `
    <div class="resolved-now">
      <p class="eyebrow">${escape(t('on air at that moment'))}${pos.override ? ' · ' + escape(t('day override')) : ''}</p>
      <h3>${escape(pos.track.title || pos.track.id)}</h3>
      <p class="muted">${escape(pos.track.credit || '')}</p>
      <div class="meter"><div class="meter-fill" style="width:${((into / length) * 100).toFixed(2)}%"></div></div>
      <p class="mono">
        ${escape(t('{into} into {length}', { into: mmss(into), length: mmss(length) }))}
        · ${escape(t('started {when}', { when: stamp(pos.slotStartMs) }))}
      </p>
      <p class="mono muted">
        ${escape(t('{programme} · pass {pass}, track {slot} · a full pass is {length}', {
          programme: pos.programme.name, pass: pos.pass + 1, slot: pos.passIndex + 1, length: mmss(pos.programmeLengthMs),
        }))}
      </p>
    </div>`;

  ui.upcoming.innerHTML = '';
  for (const next of upcoming(publication, moment, 8)) {
    const li = document.createElement('li');
    li.innerHTML = `<span class="mono muted">${formatTime(next.slotStartMs, { seconds: true })}</span>` +
      `<span>${escape(next.track.title || next.track.id)}</span>` +
      `<span class="mono muted">${mmss(next.slotEndMs - next.slotStartMs)}</span>`;
    ui.upcoming.appendChild(li);
  }
}

function escape(text) {
  const d = document.createElement('div');
  d.textContent = text;
  return d.innerHTML;
}

function showIssues(pub) {
  const { errors, warnings } = validatePublication(pub);
  const parts = [];
  for (const e of errors) parts.push('<p class="flash flash-bad">' + escape(e) + '</p>');
  for (const w of warnings) parts.push('<p class="flash flash-warn">' + escape(w) + '</p>');
  ui.issues.innerHTML = parts.join('');
}

ui.scrub.addEventListener('input', () => { ui.follow.checked = false; render(); });
for (const button of document.querySelectorAll('[data-jump]')) {
  button.addEventListener('click', () => {
    const to = Number(button.dataset.jump);
    if (to > Number(ui.scrub.max)) ui.scrub.max = String(to);
    ui.scrub.value = String(to);
    ui.follow.checked = to === 0;
    render();
  });
}

(async function start() {
  const res = await fetch(draftUrl, { cache: 'no-store', headers: { Accept: 'application/json' } });
  publication = await res.json();
  showIssues(publication);
  render();
  setInterval(() => { if (ui.follow.checked) render(); }, 500);
}());
