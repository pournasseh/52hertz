import { num, t, tn } from './i18n.js';
import { toast } from './ui.js';
import { setCover } from './covers.js';
import { openTrackSheet } from './library.js';
import { measureTrack } from './panel.js';
import { collectionTrackFor, orderFor as engineOrderFor } from '../../engine/schedule.js';

const $ = (q, root = document) => root.querySelector(q);
const $$ = (q, root = document) => [...root.querySelectorAll(q)];
const source = JSON.parse($('#programme-data').textContent);
const csrf = $('#programme-csrf input').value;
const handoffKey = `radio.programme.handoff.${source.stationId}.${source.id}`;
const byId = new Map(source.library.map(track => [Number(track.id), track]));
const byCollection = new Map((source.collections || []).map(collection => [Number(collection.id), collection]));
const previewPublication = {
  stationId: source.stationId,
  shuffleSeed: source.shuffleSeed,
  tracks: Object.fromEntries(source.library.map(track => [String(track.id), track])),
  collections: Object.fromEntries((source.collections || []).map(collection => [String(collection.id), collection])),
  programmes: {},
};
const audio = $('#programme-audio');
const cover = $('#programme-cover');

const DAY = 86400000, HOUR = 3600000, MINUTE = 60000;

const fresh = {
  version: source.version, name: source.name, mode: source.mode,
  items: source.items,
  events: source.events || [],
};
let saved = JSON.stringify(fresh);
let state = structuredClone(fresh);
let insertion = null, chosen = new Set(), playing = null, editingEvent = null;
let selectedTimeline = null, dragging = null, rundownCache = { key: '', items: [] }, timelineHit = [], plannedHit = [];
// The row whose plays stand out on the timeline, by uid.
let highlighted = null;
const calm = matchMedia('(prefers-reduced-motion: reduce)');

// Nothing unsaved is kept: a refresh starts from what is saved. The one
// exception is a library form sent from this page (upload a track, change or
// delete one), which reloads the page as a side effect; what was unsaved is
// handed across that one reload and no other.
try {
  const kept = JSON.parse(sessionStorage.getItem(handoffKey));
  sessionStorage.removeItem(handoffKey);
  const reloaded = performance.getEntriesByType('navigation')[0]?.type === 'reload';
  if (kept && !reloaded && Date.now() - kept.at < 60000 && kept.state?.version === source.version) {
    // A track deleted from the library in the meantime leaves the programme too.
    const available = entry => entry.collectionId !== undefined
      ? byCollection.has(Number(entry.collectionId)) : byId.has(Number(entry.trackId));
    kept.state.items = kept.state.items.filter(available);
    kept.state.events = kept.state.events.filter(available);
    state = kept.state;
  }
} catch { /* without session storage, the reload simply starts from what is saved */ }

const esc = (value) => String(value ?? '').replace(/[&<>"']/g, c => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
}[c]));
const uid = () => crypto.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random().toString(36).slice(2)}`;
const duration = ms => {
  const total = Math.max(0, Math.round(ms / 1000));
  const h = Math.floor(total / 3600), m = Math.floor(total % 3600 / 60), s = total % 60;
  return num(h ? `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}` : `${m}:${String(s).padStart(2, '0')}`);
};
const length = item => item.durationMs > 0 ? duration(item.durationMs) : t('measuring…');
const pad = n => String(n).padStart(2, '0');
// hh:mm in plain digits, for a time input to read.
const hhmm = ms => `${pad(Math.floor(ms / HOUR))}:${pad(Math.floor(ms % HOUR / MINUTE))}`;
const clock = ms => ms === DAY ? num('24:00') : num(hhmm(wrap(ms)));
const wrap = (ms, period = DAY) => ((ms % period) + period) % period;
const icon = name => `<svg class="icon" aria-hidden="true" focusable="false"><use href="assets/icons.svg#${name}"></use></svg>`;

// Event times are stored in UTC, like every time in the panel, and read and
// typed on the operator's own clock. The timeline's axis is an offset into
// the programme day, which begins at the station's day start.
const localOffset = () => -new Date().getTimezoneOffset() * MINUTE;
const wallClock = offset => clock(wrap(source.dayStartMs + offset + localOffset()));
const skew = source.serverNowMs - Date.now();
const nowOffset = () => wrap(Date.now() + skew - source.dayStartMs);
const previewDay = () => Math.floor((Date.now() + skew - source.dayStartMs) / DAY);

// An hour at a time, now a quarter of the way in, starting on a round five
// minutes.
let zoom = 1, viewStart = Math.floor((nowOffset() - zoom * HOUR / 4) / (5 * MINUTE)) * 5 * MINUTE / HOUR;

const track = entry => byId.get(Number(entry.trackId));
const collection = entry => byCollection.get(Number(entry.collectionId));
const isCollection = entry => entry && entry.collectionId !== undefined;
const collectionMembers = entry => (collection(entry)?.tracks || []).map(Number).map(id => byId.get(id)).filter(item => item?.durationMs > 0);
const total = () => state.items.reduce((sum, entry) => sum + (isCollection(entry) ? 0 : (track(entry)?.durationMs || 0)), 0);
// A chosen cover is a file; it is pending until saved.
const coverPending = () => Boolean(cover.querySelector('[data-cover-input]').files.length)
  || cover.querySelector('[data-cover-remove]').value === '1';
const dirty = () => JSON.stringify(state) !== saved || coverPending();

function edit(change) {
  change();
  changed();
}
function changed() {
  rundownCache.key = '';
  render();
}

function render() {
  $('#programme-name').value = state.name;
  $('.page-head h1').textContent = state.name;
  $$('[data-mode]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.mode === state.mode)));
  $('#programme-save').disabled = !dirty() || !state.items.length;
  if (!state.items.some(entry => entry.uid === highlighted)) highlighted = null;
  renderEntries();
  renderEvents();
  renderTimeline();
}

function renderEntries() {
  const ordered = state.mode === 'ordered';
  $('#sequence-heading').textContent = ordered ? t('Running order') : t('Tracks in this programme');
  $('#sequence-summary').textContent = ordered
    ? t('Drag tracks into the exact order listeners will hear.')
    : t('Every pass draws a fresh order. Each occurrence gets one turn before the next pass.');
  // Order is only something to arrange when it is played in order.
  $('.studio-sequence').classList.toggle('is-shuffle', !ordered);
  const plays = playsPerRow();
  const rows = state.items.map((entry, index) => {
    const pool = isCollection(entry) ? collection(entry) : null;
    const item = pool || track(entry) || { title: t('Missing track'), name: t('Missing collection'), credit: '', durationMs: 0 };
    const title = pool ? item.name : (item.title || item.itemId);
    const subtitle = pool
      ? t('Collection · {count}', { count: tn(collectionMembers(entry).length, '{n} playable track', '{n} playable tracks') })
      : (item.credit || t('No artist'));
    const count = plays.get(entry.uid) || 0;
    return `<div class="sequence-row" role="listitem" tabindex="0"${ordered ? ' draggable="true"' : ''} data-index="${index}" data-uid="${esc(entry.uid)}">
      ${ordered ? `<button type="button" class="row-grip" data-grip title="${esc(t('Drag to reorder'))}" aria-label="${esc(t('Drag to reorder'))}">⠿</button>` : ''}
      <span class="row-number">${num(index + 1)}</span>
      ${pool ? `<span class="row-play row-source-icon" aria-label="${esc(t('Collection'))}">${icon('collection')}</span>` : `<button type="button" class="row-play" data-preview="${item.id || ''}" aria-label="${esc(t('Preview track'))}">${playing === item.id ? '■' : '▶'}</button>`}
      <span class="row-art${pool ? ' is-collection' : ''}"${item.art ? ` style="background-image:url('${esc(item.art)}')"` : ''}>${item.art ? '' : pool ? icon('collection') : esc((item.title || '?')[0])}</span>
      <span class="row-copy">${pool ? `<strong class="row-title-static">${esc(title)}</strong>` : `<button type="button" class="row-title" data-sheet="${item.id || ''}">${esc(title)}</button>`}<small>${esc(subtitle)}</small></span>
      <span class="row-duration">${pool ? esc(t('varies')) : length(item)}</span>
      <span class="row-plays" title="${esc(playsLabel(count))}">${count ? esc(t('{n}×', { n: count })) : '—'}</span>
      <span class="row-actions"><button type="button" data-duplicate="${index}" title="${esc(t('Duplicate here'))}" aria-label="${esc(t('Duplicate here'))}">⧉</button><button type="button" data-remove="${index}" title="${esc(t('Remove'))}" aria-label="${esc(t('Remove'))}">×</button></span>
      ${ordered ? `<button type="button" class="insert-here" data-insert="${index + 1}">${esc(t('Insert here'))}</button>` : ''}
    </div>`;
  }).join('');
  $('#programme-entries').innerHTML = rows || `<div class="sequence-empty"><strong>${esc(t('Your programme starts here.'))}</strong><span>${esc(t('Add a track or collection.'))}</span><button type="button" class="primary" data-open-library>${icon('plus-lg')} ${esc(t('Add content'))}</button></div>`;
  $('#sequence-total').textContent = t('{count} · {length}', {
    count: tn(state.items.length, '{n} item', '{n} items'),
    length: state.items.some(isCollection) ? t('variable length') : duration(total()),
  });
}

/* ------------------------------------------------------------ add tracks */

function renderLibrary() {
  const query = $('#programme-search').value.trim().toLocaleLowerCase();
  const poolMatches = (source.collections || []).filter(item => !query || `${item.name} collection`.toLocaleLowerCase().includes(query));
  const trackMatches = source.library.filter(item => !query || `${item.title} ${item.credit} ${item.tags}`.toLocaleLowerCase().includes(query));
  $('#library-insertion').textContent = insertion === null
    ? t('Selected tracks will be added to the end.')
    : t('Selected tracks will be inserted after track {n}.', { n: num(insertion) });
  const pools = poolMatches.map(item => {
    const key = `collection:${item.id}`, on = chosen.has(key);
    return `<div class="pick-row pick-collection${on ? ' is-selected' : ''}" role="option" aria-selected="${on}" tabindex="0" data-pick="${key}">
      <span class="pick-check" aria-hidden="true"></span><span class="thumb">${icon('collection')}</span>
      <span class="pick-copy"><strong>${esc(item.name)}</strong><small>${esc(t('Collection · {count}', { count: tn(item.playableCount, '{n} playable track', '{n} playable tracks') }))}</small></span>
      <span class="pick-tags"><span class="tag">${esc(t('Collection'))}</span></span><span class="pick-length">${esc(t('varies'))}</span>
    </div>`;
  }).join('');
  const tracks = trackMatches.map(item => {
    const key = `track:${item.id}`, on = chosen.has(key);
    const tags = String(item.tags || '').split(',').map(tag => tag.trim()).filter(Boolean);
    return `<div class="pick-row${on ? ' is-selected' : ''}" role="option" aria-selected="${on}" tabindex="0" data-pick="${key}">
      <span class="pick-check" aria-hidden="true"></span>
      <span class="thumb">${item.art ? `<img src="${esc(item.art)}" alt="" loading="lazy">` : icon('music-note-list')}</span>
      <span class="pick-copy"><strong>${esc(item.title || item.itemId)}</strong><small>${esc(item.credit)}</small></span>
      <span class="pick-tags">${tags.map(tag => `<span class="tag">${esc(tag)}</span>`).join('')}</span>
      <span class="pick-length">${length(item)}</span>
      <button type="button" class="pick-play" data-preview="${item.id}" aria-label="${esc(t('Preview track'))}">${playing === item.id ? '■' : '▶'}</button>
    </div>`;
  }).join('');
  $('#programme-library').innerHTML = pools + tracks || `<p class="pick-empty">${esc(t('No content matches this search.'))}</p>`;
  renderPickCount();
}

function renderPickCount() {
  $('#library-selected-count').textContent = chosen.size ? tn(chosen.size, '{n} selected', '{n} selected') : '';
  $('#add-selected').disabled = !chosen.size;
}

function togglePick(row) {
  const key = row.dataset.pick;
  if (chosen.has(key)) chosen.delete(key); else chosen.add(key);
  row.classList.toggle('is-selected', chosen.has(key));
  row.setAttribute('aria-selected', String(chosen.has(key)));
  renderPickCount();
}

function addChosen() {
  if (!chosen.size) return;
  const sources = [...chosen].map(value => {
    const [kind, raw] = value.split(':');
    return kind === 'collection' ? { collectionId: Number(raw) } : { trackId: Number(raw) };
  });
  edit(() => {
    const at = insertion === null ? state.items.length : insertion;
    state.items.splice(at, 0, ...sources.map(item => ({ uid: uid(), ...item })));
    insertion = null; chosen.clear();
  });
  $('#library-dialog').close();
}

function openLibrary(at = null) {
  insertion = at;
  chosen.clear();
  $('#programme-search').value = '';
  renderLibrary();
  $('#library-dialog').showModal();
  requestAnimationFrame(() => $('#programme-search').focus());
}

/* ---------------------------------------------------------------- events */

function renderEvents() {
  $('#programme-events').innerHTML = state.events.map((event, index) => {
    const pool = isCollection(event) ? collection(event) : null;
    const item = pool || byId.get(Number(event.trackId));
    const when = event.repeat === 'hourly'
      ? t('Every hour at :{minute}', { minute: num(pad(Math.floor(wrap(event.timeMs + localOffset(), HOUR) / MINUTE))) })
      : t('Every day at {time}', { time: clock(wrap(event.timeMs + localOffset())) });
    const timing = event.timing === 'strict' ? t('exact · may cut the previous track') : t('after the current track');
    return `<div class="event-row" data-event-index="${index}"><span class="event-time">${esc(when)}</span>
      <span>${pool ? `<strong class="row-title-static">${esc(pool.name)}</strong>` : `<button type="button" class="row-title" data-sheet="${item?.id || ''}">${esc(item?.title || t('Missing track'))}</button>`}<small>${esc(pool ? t('Random from collection · {timing}', { timing }) : timing)}</small></span>
      <span class="event-actions"><button type="button" data-edit-event="${index}" title="${esc(t('Edit'))}" aria-label="${esc(t('Edit'))}">${icon('pencil')}</button><button type="button" data-remove-event="${index}" title="${esc(t('Remove'))}" aria-label="${esc(t('Remove'))}">×</button></span></div>`;
  }).join('') || `<p class="empty">${esc(t('No timed events. The programme flows without interruption.'))}</p>`;
}

/* -------------------------------------------------------------- the day */

function dayItems() {
  const key = JSON.stringify([state.mode, state.items, state.events, source.library.map(item => item.durationMs), source.collections]);
  if (rundownCache.key === key) return rundownCache.items;
  const items = [];
  const playable = state.items.filter(entry => isCollection(entry) ? collectionMembers(entry).length : track(entry)?.durationMs > 0);
  if (!playable.length) { rundownCache = { key, items }; return items; }
  previewPublication.programmes[String(source.id)] = { mode: state.mode, items: playable };
  const day = previewDay();
  const events = [];
  state.events.forEach((event, eventIndex) => {
    if (isCollection(event) ? !collectionMembers(event).length : !(track(event)?.durationMs > 0)) return;
    // The engine reads timeMs as a UTC time of day (or minute of the UTC
    // hour); on this axis 0 is the day start.
    const period = event.repeat === 'hourly' ? HOUR : DAY;
    for (let at = wrap(event.timeMs - source.dayStartMs, period); at < DAY; at += period) events.push({ ...event, at, eventIndex });
  });
  events.sort((a, b) => a.at - b.at || (a.timing === 'strict' ? -1 : 1));
  const orderFor = pass => engineOrderFor(previewPublication, String(source.id), day, pass).entries;
  let cursor = 0, index = 0, next = 0, pass = 0, order = orderFor(0), guard = 0;
  while (cursor < DAY && guard++ < 100000) {
    const event = events[next];
    let entry, item, kind = 'track', plannedAt = null;
    if (event && event.at <= cursor) {
      entry = event;
      const period = event.repeat === 'hourly' ? HOUR : DAY;
      const absoluteAt = day * DAY + source.dayStartMs + event.at;
      item = isCollection(event)
        ? collectionTrackFor(previewPublication, event.collectionId, `event:${event.id || ''}:${period}`, Math.floor(absoluteAt / period))
        : track(event);
      kind = 'event'; plannedAt = event.at; next++;
    } else {
      if (index >= order.length) { index = 0; pass++; order = orderFor(pass); }
      entry = order[index++];
      item = entry.track;
    }
    if (!item) continue;
    let end = cursor + item.durationMs;
    const hard = events.slice(next).find(candidate => candidate.timing === 'strict' && candidate.at > cursor && candidate.at < end);
    if (hard) end = hard.at;
    // The engine knows a row by its place among the playable rows, not by
    // its uid; the uid is what ties a play back to its row in the list.
    const row = kind === 'track' ? playable[entry.position]?.uid : null;
    items.push({ start: cursor, end: Math.min(end, DAY), item, kind, pass, uid: row, event: kind === 'event' ? event : null, plannedAt });
    cursor = end;
  }
  rundownCache = { key, items };
  return items;
}

// How many times each row airs in the day, by uid. A play cut off at the
// end of the day still counts: it starts.
function playsPerRow() {
  const counts = new Map();
  for (const entry of dayItems()) if (entry.uid) counts.set(entry.uid, (counts.get(entry.uid) || 0) + 1);
  return counts;
}
const playsLabel = count => count ? tn(count, 'Plays once in the day', 'Plays {n} times in the day') : t('Does not play in the day');

function canvasContext(canvas) {
  const box = canvas.getBoundingClientRect(), ratio = Math.min(devicePixelRatio || 1, 2);
  canvas.width = Math.max(1, Math.round(box.width * ratio));
  canvas.height = Math.max(1, Math.round(box.height * ratio));
  const context = canvas.getContext('2d');
  context.setTransform(ratio, 0, 0, ratio, 0, 0);
  return { context, width: box.width, height: box.height };
}

let palette = null;
function colours() {
  if (palette) return palette;
  const css = getComputedStyle($('#programme-studio')), get = name => css.getPropertyValue(name).trim();
  palette = {
    line: get('--line'), surface: get('--surface-2'), text: get('--text'), ink: get('--tl-ink'), now: get('--bad'),
    tracks: [1, 2, 3, 4].map(n => get(`--tl-${n}`)), event: get('--tl-event'), strict: get('--tl-exact'),
    font: getComputedStyle(document.body).fontFamily,
  };
  return palette;
}
matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => { palette = null; renderTimeline(); });

// A track keeps one colour wherever it airs, so repeats read as repeats.
const shade = new Map(source.library.map((item, index) => [Number(item.id), index]));
function fillOf(entry, c) {
  if (entry.kind === 'event') return entry.event?.timing === 'strict' ? c.strict : c.event;
  return c.tracks[(shade.get(Number(entry.item.id)) ?? 0) % c.tracks.length];
}
// While a row is highlighted, everything that is not one of its plays (or
// the block selected) steps back.
const alphaOf = entry => highlighted && entry.uid !== highlighted && entry.start !== selectedTimeline ? .2 : 1;

// Label steps in minutes, each with its minor tick: the first step whose
// labels sit at least 90px apart.
const STEPS = [[1, .25], [2, .5], [5, 1], [10, 2], [15, 5], [30, 5], [60, 15], [120, 30], [180, 60], [240, 60], [360, 60]];

function renderRuler(from, span, width) {
  const perMinute = width / (span / MINUTE);
  const [major, minor] = (STEPS.find(([step]) => step * perMinute >= 90) || STEPS[STEPS.length - 1]).map(m => m * MINUTE);
  // Ticks fall on round times of the operator's clock, wherever the day starts.
  const base = wrap(source.dayStartMs + localOffset());
  const x = at => (at - from) / span * width;
  const grid = [];
  let html = '';
  for (let at = Math.ceil((base + from) / minor) * minor - base; at <= from + span; at += minor) {
    const isMajor = (base + at) % major === 0;
    html += `<i class="tick${isMajor ? ' is-major' : ''}" style="left:${x(at).toFixed(1)}px"></i>`;
    if (!isMajor) continue;
    grid.push(x(at));
    if (x(at) > 18 && x(at) < width - 18) html += `<span style="left:${x(at).toFixed(1)}px">${wallClock(at)}</span>`;
  }
  $('#detail-ruler').innerHTML = html;
  return grid;
}

function drawOverview(all) {
  const { context: ctx, width, height } = canvasContext($('#timeline-overview')), c = colours();
  ctx.clearRect(0, 0, width, height); ctx.fillStyle = c.surface; ctx.fillRect(0, 0, width, height);
  for (const entry of all) {
    const x = entry.start / DAY * width, w = Math.max(entry.kind === 'event' ? 1.5 : .5, (entry.end - entry.start) / DAY * width);
    const top = entry.kind === 'event' ? 3 : 7;
    ctx.globalAlpha = alphaOf(entry);
    ctx.fillStyle = fillOf(entry, c); ctx.fillRect(x, top, w, height - top * 2);
  }
  ctx.globalAlpha = 1;
  ctx.fillStyle = c.now; ctx.fillRect(Math.round(nowOffset() / DAY * width) - 1, 0, 2, height);
  const window = $('#timeline-window');
  window.style.left = `${viewStart / 24 * width}px`;
  window.style.width = `${Math.max(6, zoom / 24 * width)}px`;
}

function drawDetail(visible, from, span, grid) {
  const air = canvasContext($('#timeline-detail')), planned = canvasContext($('#timeline-planned'));
  const ctx = air.context, pctx = planned.context, width = air.width, height = air.height, c = colours();
  for (const [g, w, h] of [[ctx, width, height], [pctx, planned.width, planned.height]]) {
    g.clearRect(0, 0, w, h); g.fillStyle = c.surface; g.fillRect(0, 0, w, h);
    g.fillStyle = c.line; for (const x of grid) g.fillRect(Math.round(x), 0, 1, h);
  }
  timelineHit = [];
  for (const entry of visible) {
    const event = entry.kind === 'event';
    const x = (Math.max(entry.start, from) - from) / span * width;
    const w = Math.max(event ? 3 : .5, (Math.min(entry.end, from + span) - Math.max(entry.start, from)) / span * width);
    const y = event ? 4 : 8, h = event ? height - 8 : height - 16;
    ctx.globalAlpha = alphaOf(entry);
    ctx.fillStyle = fillOf(entry, c);
    // Rounded, and a pixel apart from the next, once a block is wide enough
    // to be seen as one.
    if (w >= 4) { ctx.beginPath(); ctx.roundRect(x, y, w - 1, h, Math.min(4, (w - 1) / 2)); ctx.fill(); }
    else ctx.fillRect(x, y, w, h);
    if (selectedTimeline === entry.start) {
      ctx.strokeStyle = c.text; ctx.lineWidth = 2; ctx.beginPath(); ctx.roundRect(x + 1, y + 1, Math.max(1, w - 3), h - 2, 3); ctx.stroke();
    }
    if (w > 46) {
      ctx.save(); ctx.beginPath(); ctx.rect(x + 3, y, w - 8, h); ctx.clip(); ctx.fillStyle = c.ink;
      ctx.font = `600 11px ${c.font}`; ctx.fillText(entry.item.title, x + 7, y + 17);
      if (w > 90) { ctx.globalAlpha *= .75; ctx.font = `10.5px ${c.font}`; ctx.fillText(entry.item.credit || wallClock(entry.start), x + 7, y + 32); }
      ctx.restore();
    }
    timelineHit.push({ x, w, entry });
  }
  ctx.globalAlpha = 1;
  plannedHit = [];
  for (const entry of visible.filter(item => item.kind === 'event')) {
    const plannedAt = entry.plannedAt ?? entry.start;
    const px = (plannedAt - from) / span * width, actualX = (entry.start - from) / span * width;
    const colour = entry.event?.timing === 'strict' ? c.strict : c.event;
    pctx.globalAlpha = alphaOf(entry);
    if (entry.start > plannedAt + 1000) {
      pctx.strokeStyle = colour; pctx.setLineDash([3, 3]); pctx.beginPath(); pctx.moveTo(px, 20); pctx.lineTo(px, 39); pctx.lineTo(actualX, 39); pctx.stroke(); pctx.setLineDash([]);
    }
    pctx.save(); pctx.translate(px, 13); pctx.rotate(Math.PI / 4); pctx.fillStyle = colour; pctx.beginPath(); pctx.roundRect(-6, -6, 12, 12, 2); pctx.fill();
    if (selectedTimeline === entry.start) { pctx.strokeStyle = c.text; pctx.lineWidth = 2; pctx.strokeRect(-8, -8, 16, 16); } pctx.restore();
    plannedHit.push({ x: px - 11, w: 22, entry });
  }
  pctx.globalAlpha = 1;
}

function renderNow(from, span) {
  const at = nowOffset(), line = $('#timeline-now');
  line.hidden = at < from || at > from + span;
  if (!line.hidden) line.style.left = `calc(var(--tl-label) + ${(at - from) / span * $('#timeline-detail').clientWidth}px)`;
}

// What is selected on the timeline, and the row highlighted on it, are marked
// faintly where they are listed.
function markSelection(selected) {
  $$('.sequence-row').forEach(row => row.classList.toggle('is-current',
    (selected?.kind === 'track' && row.dataset.uid === selected.uid) || row.dataset.uid === highlighted));
  $$('.event-row').forEach(row => row.classList.toggle('is-current', selected?.kind === 'event' && Number(row.dataset.eventIndex) === selected.event.eventIndex));
}

function renderTimeline() {
  viewStart = Math.max(0, Math.min(24 - zoom, viewStart));
  const from = viewStart * HOUR, span = zoom * HOUR, to = from + span;
  const all = dayItems(), visible = all.filter(item => item.end > from && item.start < to);
  const grid = renderRuler(from, span, $('#timeline-detail').clientWidth);
  drawOverview(all); drawDetail(visible, from, span, grid);
  $$('[data-zoom]').forEach(button => button.setAttribute('aria-pressed', String(Number(button.dataset.zoom) === zoom)));
  $('#day-range').textContent = `${wallClock(from)}–${wallClock(to)}`;
  const selected = all.find(entry => entry.start === selectedTimeline);
  $('#day-selection').innerHTML = selected ? `<i class="selection-swatch" style="background:${fillOf(selected, colours())}"></i><strong>${esc(selected.item.title)}</strong><span>${esc(selected.item.credit || '')}</span><span><b>${wallClock(selected.start)}–${wallClock(selected.end)}</b></span><span>${duration(selected.end - selected.start)}</span>${selected.kind === 'event' ? `<b class="selection-kind">${esc(selected.event.timing === 'strict' ? t('exact event') : t('event after track'))}</b>` : ''}${selected.end - selected.start < selected.item.durationMs ? `<em>${esc(t('Cut short by an exact event'))}</em>` : ''}` : highlighted ? highlightReadout() : `<span>${esc(t('Click a track or event to inspect it. Drag the timeline to move through the day.'))}</span>`;
  markSelection(selected);
  renderNow(from, span);
}

/* ---------------------------------------------- a row, on the timeline */

function highlightReadout() {
  const entry = state.items.find(item => item.uid === highlighted);
  const pool = isCollection(entry) ? collection(entry) : null, item = pool ? null : track(entry);
  const count = playsPerRow().get(highlighted) || 0;
  return `${item ? `<i class="selection-swatch" style="background:${fillOf({ kind: 'track', item }, colours())}"></i>` : ''}`
    + `<strong>${esc(pool ? pool.name : item?.title || t('Missing track'))}</strong>`
    + `<span>${esc(pool ? t('Collection') : item?.credit || '')}</span><span><b>${esc(playsLabel(count))}</b></span>`
    + `<button type="button" class="quiet selection-clear" data-clear-highlight>${esc(t('Show all'))}</button>`;
}

// A row clicked in the list shows where it airs: the day comes into view,
// on one of its plays, and everything else on the timeline steps back.
// The same row again lets go.
function highlightRow(uid) {
  highlighted = highlighted === uid ? null : uid;
  selectedTimeline = null;
  if (highlighted) {
    const plays = dayItems().filter(entry => entry.uid === highlighted);
    const from = viewStart * HOUR, to = from + zoom * HOUR, middle = from + zoom * HOUR / 2;
    const centre = entry => (entry.start + entry.end) / 2;
    if (plays.length && !plays.some(entry => entry.end > from && entry.start < to)) {
      const nearest = plays.reduce((best, entry) => Math.abs(centre(entry) - middle) < Math.abs(centre(best) - middle) ? entry : best);
      setTimelineView(centre(nearest) / HOUR - zoom / 2);
    }
    const day = $('.studio-day'), box = day.getBoundingClientRect(), top = $('.topbar')?.offsetHeight || 0;
    if (box.top < top || box.bottom > innerHeight) day.scrollIntoView({ behavior: calm.matches ? 'auto' : 'smooth', block: 'start' });
  }
  renderTimeline();
}

const entries = $('#programme-entries');
entries.addEventListener('click', e => {
  // The row's own buttons do their own things.
  if (e.target.closest('button, a, input')) return;
  const row = e.target.closest('.sequence-row'); if (row) highlightRow(row.dataset.uid);
});
entries.addEventListener('keydown', e => {
  const row = e.target.closest('.sequence-row');
  if (!row || e.target !== row || (e.key !== 'Enter' && e.key !== ' ')) return;
  e.preventDefault(); highlightRow(row.dataset.uid);
});
$('#day-selection').addEventListener('click', e => {
  if (e.target.closest('[data-clear-highlight]')) { highlighted = null; renderTimeline(); }
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape' && highlighted && !document.querySelector('dialog[open]')) { highlighted = null; renderTimeline(); }
});

/* ------------------------------------------------------ preview and save */

function syncPlay() {
  $$('[data-preview]').forEach(button => { button.textContent = Number(button.dataset.preview) === playing ? '■' : '▶'; });
}

async function preview(id) {
  const item = byId.get(Number(id));
  if (!item) return;
  if (playing === item.id) { audio.pause(); playing = null; syncPlay(); return; }
  audio.src = item.url; playing = item.id; syncPlay();
  try { await audio.play(); } catch { playing = null; syncPlay(); }
}
audio.addEventListener('ended', () => { playing = null; syncPlay(); });

// The library's own sheet, for a track this programme holds.
function showTrack(id) {
  const item = byId.get(Number(id));
  if (!item) return;
  if (playing !== null) { audio.pause(); playing = null; syncPlay(); }
  openTrackSheet({
    track: item.id, title: item.title, credit: item.credit, description: item.description, tags: item.tags,
    length: item.durationMs > 0 ? duration(item.durationMs) : t('not measured yet'), media: item.media,
    added: item.measuredAt || '', programmes: item.programmeNames, programmeCount: item.programmeCount,
    audio: item.url, art: item.art,
  });
}

async function save() {
  if (!state.items.length || !dirty()) return;
  const button = $('#programme-save');
  button.disabled = true; button.textContent = t('Saving…');
  const body = new FormData(); body.set('csrf', csrf); body.set('document', JSON.stringify(state));
  const file = cover.querySelector('[data-cover-input]').files[0];
  if (file) body.set('art', file);
  body.set('art_remove', cover.querySelector('[data-cover-remove]').value);
  try {
    const response = await fetch(source.saveUrl, { method: 'POST', body, headers: { Accept: 'application/json' } });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) { toast(result.error || t('Could not save.'), 'bad'); return; }
    state.version = result.version; source.version = result.version; source.art = result.art;
    saved = JSON.stringify(state);
    setCover(cover, { url: result.art });
    if (result.live) toast(t('Saved · the current track will finish, then the new order takes over'), 'ok');
    else toast(t('Saved, but the live station could not update: {reason}', { reason: result.error || t('unknown error') }), 'warn');
    history.replaceState(null, '', source.editorUrl);
  } catch {
    toast(t('Could not save.'), 'bad');
  } finally {
    button.textContent = t('Save changes');
    render();
  }
}

// A length is measured where the file can be decoded: here, for anything
// uploaded from this page and not yet measured.
async function measureNew() {
  for (const item of source.library.filter(entry => entry.durationMs <= 0)) {
    try {
      const result = await measureTrack(source.measureUrl, csrf, item.id, new URL(item.url, location.href).href);
      item.durationMs = result.durationMs;
      render();
    } catch { /* it stays unmeasured, and off the air, as in the library */ }
  }
}

$('#programme-name').addEventListener('change', e => edit(() => { state.name = e.target.value.trim() || source.name; }));
// The cover picker changes itself; the page only has to notice.
for (const type of ['change', 'click', 'drop']) cover.addEventListener(type, () => setTimeout(render));
$$('[data-mode]').forEach(button => button.addEventListener('click', () => {
  if (button.dataset.mode !== state.mode) edit(() => { state.mode = button.dataset.mode; });
}));
$('#programme-save').addEventListener('click', save);
for (const form of $$('#add-track form, #track-sheet form')) {
  // 'formdata' fires only when the form really goes, including after a
  // confirmation; a cancelled one hands nothing over.
  form.addEventListener('formdata', () => {
    if (JSON.stringify(state) === saved) return;
    try { sessionStorage.setItem(handoffKey, JSON.stringify({ at: Date.now(), state })); } catch { /* see above */ }
  });
}

$('#programme-search').addEventListener('input', renderLibrary);
const picks = $('#programme-library');
picks.addEventListener('click', e => {
  if (e.target.closest('[data-preview]')) return;
  const row = e.target.closest('[data-pick]'); if (row) togglePick(row);
});
picks.addEventListener('keydown', e => {
  const row = e.target.closest('[data-pick]');
  if (!row || e.target !== row) return;
  if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); togglePick(row); }
  if (e.key === 'ArrowDown') { e.preventDefault(); row.nextElementSibling?.focus(); }
  if (e.key === 'ArrowUp') { e.preventDefault(); row.previousElementSibling?.focus(); }
});
$('#programme-search').addEventListener('keydown', e => {
  if (e.key === 'ArrowDown') { e.preventDefault(); $('[data-pick]', picks)?.focus(); }
});
$('#add-selected').addEventListener('click', addChosen);
$('#open-library').addEventListener('click', () => openLibrary());
$$('[data-library-close]').forEach(button => button.addEventListener('click', () => $('#library-dialog').close()));

document.addEventListener('click', e => {
  const previewButton = e.target.closest('[data-preview]'); if (previewButton) preview(previewButton.dataset.preview);
  const sheet = e.target.closest('[data-sheet]'); if (sheet) showTrack(sheet.dataset.sheet);
  const remove = e.target.closest('[data-remove]'); if (remove) edit(() => state.items.splice(Number(remove.dataset.remove), 1));
  const duplicate = e.target.closest('[data-duplicate]'); if (duplicate) edit(() => {
    const i = Number(duplicate.dataset.duplicate), copy = { ...state.items[i], uid: uid() }; state.items.splice(i + 1, 0, copy);
  });
  const insert = e.target.closest('[data-insert]'); if (insert) openLibrary(Number(insert.dataset.insert));
  if (e.target.closest('[data-open-library]')) openLibrary();
  const editEvent = e.target.closest('[data-edit-event]'); if (editEvent) openEvent(Number(editEvent.dataset.editEvent));
  const removeEvent = e.target.closest('[data-remove-event]'); if (removeEvent) edit(() => state.events.splice(Number(removeEvent.dataset.removeEvent), 1));
});

entries.addEventListener('dragstart', e => { const row = e.target.closest('.sequence-row'); if (!row) return; dragging = Number(row.dataset.index); row.classList.add('is-dragging'); e.dataTransfer.effectAllowed = 'move'; });
entries.addEventListener('dragover', e => { if (dragging === null) return; e.preventDefault(); const row = e.target.closest('.sequence-row'); $$('.sequence-row', entries).forEach(x => x.classList.remove('is-drop')); row?.classList.add('is-drop'); });
entries.addEventListener('drop', e => { e.preventDefault(); const row = e.target.closest('.sequence-row'); if (!row || dragging === null) return; const to = Number(row.dataset.index); if (to !== dragging) edit(() => { const [item] = state.items.splice(dragging, 1); state.items.splice(to, 0, item); }); dragging = null; });
entries.addEventListener('dragend', () => { dragging = null; $$('.sequence-row', entries).forEach(x => x.classList.remove('is-dragging', 'is-drop')); });

// One dialog adds an event or, given its index, changes one.
function openEvent(index = null) {
  editingEvent = index;
  const event = index === null ? null : state.events[index];
  $('#event-track').innerHTML = source.library.map(item => `<option value="${item.id}">${esc(item.title)} · ${length(item)}</option>`).join('');
  $('#event-form').reset();
  if (event) {
    $('#event-track').value = String(event.trackId);
    $('#event-repeat').value = event.repeat;
    $('#event-timing').value = event.timing;
    $('#event-time').value = event.repeat === 'hourly'
      ? `00:${pad(Math.floor(wrap(event.timeMs + localOffset(), HOUR) / MINUTE))}`
      : hhmm(wrap(event.timeMs + localOffset()));
  } else {
    $('#event-time').value = '12:00';
  }
  updateEventForm(); $('#event-dialog').showModal();
}
function updateEventForm() {
  const hourly = $('#event-repeat').value === 'hourly';
  $('#event-time-label').textContent = hourly ? t('Minute of the hour') : t('Time');
  $('#event-time').value = hourly ? `00:${($('#event-time').value.split(':')[1] || '00')}` : $('#event-time').value;
  $('#event-timing-note').textContent = $('#event-timing').value === 'strict'
    ? t('The previous track will be cut if it overlaps this time.') : t('This event waits for the current track to finish.');
}
$('#add-event').addEventListener('click', () => openEvent());
$('#event-repeat').addEventListener('change', updateEventForm);
$('#event-timing').addEventListener('change', updateEventForm);
$('#event-form').addEventListener('submit', e => {
  e.preventDefault(); const [hours, minutes] = $('#event-time').value.split(':').map(Number);
  const repeat = $('#event-repeat').value;
  // Typed on the operator's clock, stored in UTC.
  const timeMs = repeat === 'hourly' ? wrap(minutes * MINUTE - localOffset(), HOUR) : wrap((hours * 60 + minutes) * MINUTE - localOffset());
  if (state.events.some((x, i) => i !== editingEvent && x.repeat === repeat && x.timeMs === timeMs)) { $('#event-timing-note').textContent = t('There is already an event at this time.'); return; }
  const changes = { trackId: Number($('#event-track').value), repeat, timeMs, timing: $('#event-timing').value };
  edit(() => {
    if (editingEvent === null) state.events.push({ id: uid(), ...changes });
    else state.events[editingEvent] = { ...state.events[editingEvent], ...changes };
  });
  $('#event-dialog').close();
});
$$('#event-dialog [data-modal-close]').forEach(button => button.addEventListener('click', () => $('#event-dialog').close()));

// A thrown timeline keeps going after it is let go and slows to a stop, as a
// page does under a finger. Anything else that moves it stops the glide.
let glide = 0;
function stopGlide() { cancelAnimationFrame(glide); glide = 0; }
function throwTimeline(velocity, width) {
  let speed = velocity, last = performance.now();
  const step = now => {
    const dt = Math.min(32, now - last); last = now;
    speed *= Math.exp(-dt / 325);
    const before = viewStart;
    setTimelineView(viewStart - speed * dt / width * zoom, zoom, true);
    // Slow enough to stop, or held up by either end of the day.
    glide = Math.abs(speed) < .02 || viewStart === before ? 0 : requestAnimationFrame(step);
  };
  glide = requestAnimationFrame(step);
}

function setTimelineView(start, nextZoom = zoom, gliding = false) {
  if (!gliding) stopGlide();
  zoom = Math.max(.25, Math.min(24, nextZoom));
  viewStart = Math.max(0, Math.min(24 - zoom, start));
  renderTimeline();
}
$$('[data-zoom]').forEach(button => button.addEventListener('click', () => {
  const next = Number(button.dataset.zoom);
  const selected = dayItems().find(entry => entry.start === selectedTimeline);
  const focus = selected ? (selected.start + selected.end) / 2 / HOUR : viewStart + zoom / 2;
  setTimelineView(focus - next / 2, next);
}));

function timelineEntryAt(canvas, clientX, hits) {
  const box = canvas.getBoundingClientRect(), x = clientX - box.left;
  return hits.find(candidate => x >= candidate.x && x <= candidate.x + candidate.w)?.entry || null;
}
function showTimelineTip(entry, clientX, clientY) {
  const tip = $('#timeline-tip');
  if (!entry) { tip.hidden = true; return; }
  const late = entry.kind === 'event' ? Math.max(0, entry.start - (entry.plannedAt ?? entry.start)) : 0;
  tip.innerHTML = `<strong>${esc(entry.item.title)}</strong><span>${esc(entry.item.credit || '')}</span><span><b>${wallClock(entry.start)}–${wallClock(entry.end)}</b> · ${duration(entry.end - entry.start)}</span>${late ? `<em>${esc(t('{delay} late', { delay: duration(late) }))}</em>` : ''}`;
  tip.hidden = false;
  const root = $('#programme-tl').getBoundingClientRect();
  let left = clientX - root.left + 12, top = clientY - root.top + 12;
  if (left + tip.offsetWidth > root.width) left -= tip.offsetWidth + 24;
  if (top + tip.offsetHeight > root.height) top -= tip.offsetHeight + 24;
  tip.style.left = `${Math.max(0, left)}px`; tip.style.top = `${Math.max(0, top)}px`;
}
for (const [canvas, hits] of [[$('#timeline-detail'), () => timelineHit], [$('#timeline-planned'), () => plannedHit]]) {
  canvas.addEventListener('pointermove', event => { if (!timelineGesture) showTimelineTip(timelineEntryAt(canvas, event.clientX, hits()), event.clientX, event.clientY); });
  canvas.addEventListener('pointerleave', () => { if (!timelineGesture) $('#timeline-tip').hidden = true; });
}

let timelineGesture = null;
for (const canvas of [$('#timeline-detail'), $('#timeline-planned')]) {
  canvas.addEventListener('pointerdown', event => {
    if (event.button !== 0) return;
    stopGlide();
    timelineGesture = { canvas, x: event.clientX, start: viewStart, moved: false, trail: [{ x: event.clientX, at: event.timeStamp }] };
    canvas.setPointerCapture(event.pointerId); canvas.classList.add('is-panning'); $('#timeline-tip').hidden = true;
  });
  canvas.addEventListener('pointermove', event => {
    if (!timelineGesture || timelineGesture.canvas !== canvas) return;
    const dx = event.clientX - timelineGesture.x;
    if (Math.abs(dx) > 3) timelineGesture.moved = true;
    if (timelineGesture.moved) setTimelineView(timelineGesture.start - dx / canvas.clientWidth * zoom);
    // The last tenth of a second of movement is what the throw is made of.
    const trail = timelineGesture.trail;
    trail.push({ x: event.clientX, at: event.timeStamp });
    while (trail.length > 2 && event.timeStamp - trail[0].at > 100) trail.shift();
  });
  canvas.addEventListener('pointerup', event => {
    if (!timelineGesture || timelineGesture.canvas !== canvas) return;
    const gesture = timelineGesture; timelineGesture = null; canvas.classList.remove('is-panning');
    if (!gesture.moved) {
      // A click on a block selects it; a click on nothing lets go of it. A
      // highlighted row stays only while what is clicked is one of its plays.
      const entry = timelineEntryAt(canvas, event.clientX, canvas === $('#timeline-detail') ? timelineHit : plannedHit);
      selectedTimeline = entry ? entry.start : null;
      if (entry?.uid !== highlighted) highlighted = null;
      renderTimeline();
      return;
    }
    // A hand that stopped before letting go has nothing to throw.
    const first = gesture.trail[0], last = gesture.trail[gesture.trail.length - 1];
    const velocity = event.timeStamp - last.at < 60 && last.at > first.at ? (last.x - first.x) / (last.at - first.at) : 0;
    if (Math.abs(velocity) > .15 && !calm.matches) throwTimeline(velocity, canvas.clientWidth);
  });
  canvas.addEventListener('pointercancel', () => { timelineGesture = null; canvas.classList.remove('is-panning'); });
}

const minimap = $('#timeline-overview');
let minimapMoving = false;
function moveMinimap(event) {
  const box = minimap.getBoundingClientRect(), hour = (event.clientX - box.left) / box.width * 24;
  setTimelineView(hour - zoom / 2);
}
minimap.addEventListener('pointerdown', event => { minimapMoving = true; minimap.setPointerCapture(event.pointerId); moveMinimap(event); });
minimap.addEventListener('pointermove', event => { if (minimapMoving) moveMinimap(event); });
minimap.addEventListener('pointerup', () => { minimapMoving = false; });
minimap.addEventListener('pointercancel', () => { minimapMoving = false; });

$('#programme-tl').addEventListener('wheel', event => {
  if (event.ctrlKey || event.metaKey) {
    event.preventDefault();
    const next = Math.max(.25, Math.min(24, zoom * Math.exp(event.deltaY * .002)));
    setTimelineView(viewStart + (zoom - next) / 2, next);
  } else if (event.shiftKey || Math.abs(event.deltaX) > Math.abs(event.deltaY)) {
    event.preventDefault(); setTimelineView(viewStart + (event.deltaX || event.deltaY) / $('#timeline-detail').clientWidth * zoom);
  }
}, { passive: false });
$('#programme-tl').addEventListener('keydown', event => {
  if (event.key === 'ArrowLeft') { setTimelineView(viewStart - zoom * .1); event.preventDefault(); }
  if (event.key === 'ArrowRight') { setTimelineView(viewStart + zoom * .1); event.preventDefault(); }
  if (event.key === '+' || event.key === '=') { setTimelineView(viewStart + zoom / 4, zoom / 2); event.preventDefault(); }
  if (event.key === '-') { setTimelineView(viewStart - zoom / 2, zoom * 2); event.preventDefault(); }
});
let resizeFrame = 0;
new ResizeObserver(() => { cancelAnimationFrame(resizeFrame); resizeFrame = requestAnimationFrame(renderTimeline); }).observe($('.studio-day'));
// Now moves; the drawing follows it. Every second only the line is moved — one
// style write, no canvas — and the whole timeline every half minute, because
// the overview draws its own now mark. A hidden tab draws nothing and catches
// up when it comes back. A second is finer than the eye can tell: at an hour's
// zoom a pixel is about three seconds, at a day's more than a minute.
const drawsNow = () => !document.hidden && !timelineGesture && !minimapMoving;
setInterval(() => { if (drawsNow()) renderNow(viewStart * HOUR, zoom * HOUR); }, 1000);
setInterval(() => { if (drawsNow()) renderTimeline(); }, 30000);
document.addEventListener('visibilitychange', () => { if (drawsNow()) renderTimeline(); });

render();
measureNew();
