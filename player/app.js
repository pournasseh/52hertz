/**
 * The player.
 *
 * One player for every station of this panel. Which station is in its
 * address - player/<its-address>, or player/?station=<its-address> where the
 * server does not rewrite addresses - and everything that makes it that station's
 * player (its name, its colour, its covers, the language it speaks) comes from
 * what the station publishes. The timing rules are not copied here either:
 * they are loaded from the panel's shared engine at runtime.
 *
 * It reads the station's dynamic PHP feed, asks `resolve()` what is on air, and moves
 * audio towards that answer. It never keeps a queue of its own: if a file
 * fails, or the tab sleeps, or the listener leaves for an hour, the station's
 * position is recomputed from the clock and the player rejoins there.
 *
 * Runs as-is in a browser. No build step, no framework, no dependencies.
 */

// The player lives in the panel's player/ folder, so everything else it
// needs has a known place one level up.
const playerRoot = new URL('./', location.href);
const panel = new URL('../', playerRoot);
// A station has two addresses: the clean player/<address>, which exists only
// where the server rewrites addresses, and player/?station=<address>, which
// works on any host. Arriving by the clean one proves rewriting works, so the
// feed is asked for by its clean address too; arriving by the other, it is
// asked for straight from index.php, which needs nothing but PHP.
const ADDRESS = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;
const pathStationId = (location.pathname.match(/\/player\/([a-z0-9]+(?:-[a-z0-9]+)*)$/) || [])[1] || '';
const queryStationId = new URLSearchParams(location.search).get('station') || '';
const clean = pathStationId !== '';
const stationId = clean ? pathStationId : (ADDRESS.test(queryStationId) ? queryStationId : '');
const publicPlayerUrl = !stationId ? location.href
  : new URL(clean ? encodeURIComponent(stationId) : './?station=' + encodeURIComponent(stationId), playerRoot).href;

const addresses = {
  engineBase: new URL('engine/', panel).href,
  feedUrl: new URL(clean
    ? 'stations/' + encodeURIComponent(stationId) + '/feed.json'
    : 'index.php?p=station-feed&id=' + encodeURIComponent(stationId), panel).href,
  timeUrl: new URL('index.php?p=time', panel).href,
};

// Programme revisions are uncommon, so checking for them should stay cheap.
// Returning to the player, reconnecting, or pressing Play also checks at once.
const REVISION_POLL_VISIBLE_MS = 2 * 60 * 1000;
const REVISION_POLL_HIDDEN_MS = 10 * 60 * 1000;
const DRIFT_SOFT_MS = 350;
const DRIFT_HARD_MS = 2000;

const { resolve, upcoming, validatePublication } = await import(new URL('schedule.js', addresses.engineBase).href);
const { createClock } = await import(new URL('clock.js', addresses.engineBase).href);

/* ---------------------------------------------------------------- words */

// The player carries no dictionary. A station publishes the words it speaks,
// keyed by their English wording, so a language added to the panel reaches
// listeners without this file being touched - and a player deployed on its
// own domain still speaks it, because the words travelled with the station.
//
// Until the publication arrives there is nothing to say but the English the
// keys already are, which is what an untranslated language falls back to too.
let words = {};
let digitMap = null;
let language = "en";

function say(key, vars = {}) {
  let text = words[key] || key;
  for (const [name, value] of Object.entries(vars)) text = text.split("{" + name + "}").join(value);
  return text;
}

/** Digits in the player's language, where it writes its own. */
function digits(text) {
  const out = String(text);
  return digitMap ? out.replace(/[0-9]/g, (d) => digitMap[Number(d)]) : out;
}

/* ------------------------------------------------------------------ page */

const el = (id) => document.getElementById(id);
const audio = el('audio');
const PLAY = 'M8 5l12 7-12 7z';
const STOP = 'M6 6h12v12H6z';

const ui = {
  app: el('app'), body: document.body,
  stage: document.querySelector('.stage'), controls: document.querySelector('.controls'),
  name: el('station-name'), tagline: el('station-tagline'), onair: el('onair-text'),
  hero: el('stage-art'),
  sleeve: el('sleeve'), sleeveImg: el('sleeve-img'), sleeveLetter: el('sleeve-letter'),
  eyebrow: el('eyebrow'), title: el('title'), credit: el('credit'), length: el('length'),
  more: el('more'), play: el('play'), glyph: el('play-glyph'), share: el('share'), notice: el('notice'),
  install: el('install'), installLabel: el('install-label'),
  queueTitle: el('queue-title'), later: el('later'),
  about: el('about'), aboutTitle: el('about-title'), shut: el('shut'),
  aboutBackdrop: el('about-backdrop'), aboutArt: el('about-art'),
  aboutArtImg: el('about-art-img'), aboutArtLetter: el('about-art-letter'),
  aboutKicker: el('about-kicker'), aboutTrackTitle: el('about-track-title'),
  aboutTrackCredit: el('about-track-credit'), aboutStart: el('about-start'),
  aboutDuration: el('about-duration'),
  left: el('countdown-left'), leftTime: el('countdown-time'),
  description: el('about-description'), programme: el('about-programme'), link: el('track-link'),
  foot: el('foot'), colophon: el('colophon'), home: el('home-link'),
  toast: el('toast'),
};

const clock = createClock({
  timeUrl: addresses.timeUrl,
  fallbackUrl: addresses.feedUrl,
});

const state = {
  publication: null,
  staged: null,
  feedEtag: null,
  listening: false,
  currentItemId: null,
  currentSlotStart: null,
  current: null,         // what resolve() said about the slot on screen
  failedItemId: null,
  failedUntilMs: 0,
  loading: false,
  notice: null,
  heroUrl: null,
  sleeveUrl: null,
  aboutArtUrl: null,
};

/**
 * Adopt a station's language: its words, its direction and its digits, all
 * as the station published them.
 */
function speak(code, published) {
  language = code || 'en';
  words = published?.strings || {};
  const own = published?.digits;
  digitMap = typeof own === 'string' && [...own].length === 10 ? [...own] : null;
  document.documentElement.lang = language;
  document.documentElement.dir = published?.direction === 'rtl' ? 'rtl' : 'ltr';
  useFont(published?.font);
  ui.eyebrow.textContent = say('Now playing');
  ui.more.setAttribute('aria-label', say('About this track'));
  ui.more.title = say('About this track');
  ui.share.setAttribute('aria-label', say('Share'));
  ui.share.title = say('Share');
  ui.installLabel.textContent = say('Install');
  ui.install.setAttribute('aria-label', say('Install this station'));
  ui.install.title = say('Install this station');
  ui.queueTitle.textContent = say('Coming up');
  ui.aboutTitle.textContent = say('About this track');
  ui.aboutKicker.textContent = say('Now playing');
  ui.shut.setAttribute('aria-label', say('Close'));
  ui.shut.title = say('Close');
  ui.link.textContent = say('Open this track’s page');
  setPlaying(state.listening);
}

/** The typeface the station's language names for itself, from the panel's assets/fonts/. */
let fontLoaded = null;
function useFont(file) {
  const valid = typeof file === 'string' && /^[A-Za-z0-9._-]+\.woff2$/.test(file);
  document.documentElement.toggleAttribute('data-font', valid);
  if (!valid || fontLoaded === file) return;
  fontLoaded = file;
  const url = new URL('assets/fonts/' + file, panel).href;
  const face = new FontFace('Language', `url("${url}") format("woff2")`, { weight: '100 900', display: 'swap' });
  document.fonts.add(face);
  face.load().catch(() => {});
}

/* --------------------------------------------------------------- helpers */

function absolute(url, against) {
  try { return new URL(url, against).href; } catch { return ''; }
}

/** Listener-facing links may navigate only to ordinary web URLs. */
function webLink(url, against = panel) {
  const resolved = absolute(url, against);
  if (!resolved) return '';
  try {
    const parsed = new URL(resolved);
    return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
  } catch {
    return '';
  }
}

function clockTime() { return clock.now(); }

function mmss(ms) {
  if (!Number.isFinite(ms) || ms < 0) ms = 0;
  const total = Math.ceil(ms / 1000);
  const h = Math.floor(total / 3600), m = Math.floor(total % 3600 / 60), s = total % 60;
  return digits(h ? h + ':' + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0') : m + ':' + String(s).padStart(2, '0'));
}

function hhmm(ms) {
  return new Date(ms).toLocaleTimeString(language || [], { hour: '2-digit', minute: '2-digit' });
}

/** A picture is shown once it has arrived: a half-drawn cover snapping in is worse. */
function showImage(target, url, apply) {
  if (!url) { apply(null); return; }
  const probe = new Image();
  probe.onload = () => { if (target.dataset.wanted === url) apply(url); };
  probe.onerror = () => { if (target.dataset.wanted === url) apply(null); };
  target.dataset.wanted = url;
  probe.src = url;
}

/* ----------------------------------------------------------------- boot */

async function boot() {
  speak(language, null);
  ui.onair.textContent = say('tuning');
  if (!stationId) return fatal(say('This link does not name a station.'));
  try {
    const publication = await fetchPublicationFeed();
    if (validatePublication(publication).errors.length) return fatal(say('This station published something this player cannot read.'));

    adopt(publication);
    await clock.sync();
    tick();
    setInterval(tick, 250);
    scheduleRevisionPoll();
  } catch (err) {
    fatal(err && err.status === 404 ? say('There is no station at this link.') : say('The station could not be reached.'));
  }
}

function composeFeed(feed) {
  if (!feed || feed.feedVersion !== 1 || !Array.isArray(feed.snapshots) || !feed.snapshots.length) {
    throw new Error('unreadable station feed');
  }
  let current = null;
  for (const snapshot of feed.snapshots) {
    const next = snapshot && snapshot.publication;
    if (!next || typeof next !== 'object') throw new Error('unreadable station snapshot');
    if (current) next.handover = { requestedAtMs: Number(snapshot.requestedAtMs), previous: current };
    current = next;
  }
  // The words came once, for the whole feed, and belong to the revision that
  // is about to go on air rather than to any of the ones behind it.
  current.words = feed.words || null;
  return current;
}

async function fetchPublicationFeed() {
  const headers = state.feedEtag ? { 'If-None-Match': state.feedEtag } : {};
  const res = await fetch(addresses.feedUrl, { cache: 'no-cache', headers });
  if (res.status === 304) return null;
  if (!res.ok) throw Object.assign(new Error('HTTP ' + res.status + ' for ' + addresses.feedUrl), { status: res.status });
  state.feedEtag = res.headers.get('ETag');
  return composeFeed(await res.json());
}

function adopt(publication) {
  state.publication = publication;
  state.currentSlotStart = null;        // repaint what is on from the new text

  const s = publication.station || {};
  speak(s.language, publication.words);
  ui.name.textContent = s.name || publication.stationId;
  ui.tagline.textContent = s.tagline || '';
  if (s.accent) {
    document.documentElement.style.setProperty('--accent', s.accent);
    document.querySelector('meta[name="theme-color"]')?.setAttribute('content', s.accent);
  }
  ui.colophon.textContent = s.colophon || '';
  const homeUrl = webLink(s.homeUrl);
  if (homeUrl) {
    ui.home.href = homeUrl;
    ui.home.textContent = homeUrl.replace(/^https?:\/\//, '').replace(/\/$/, '');
    ui.home.hidden = false;
  } else {
    ui.home.removeAttribute('href');
    ui.home.hidden = true;
  }
  ui.foot.hidden = !s.colophon && !homeUrl;
  if (!state.listening) document.title = s.name || '52Hertz';
  document.querySelector('meta[name="apple-mobile-web-app-title"]')?.setAttribute('content', s.name || '52Hertz');
  ui.body.dataset.state = 'ready';
}

function fatal(message) {
  ui.body.dataset.state = 'error';
  ui.onair.textContent = say('off air');
  ui.title.textContent = message;
  ui.credit.textContent = '';
  ui.length.textContent = '';
  ui.play.disabled = true;
  ui.more.disabled = true;
  ui.share.disabled = !stationId;
}

/* ------------------------------------------------------------ the tick */

function tick() {
  const pub = state.publication;
  if (!pub) return;

  const now = clockTime();
  maybeActivateStaged(now);

  const pos = resolve(state.publication, now);

  if (pos.state !== 'on-air') {
    renderOffAir(pos);
    return;
  }

  // A new slot. This is the only place the "current track" changes, and it
  // changes because of the time — never because a file ended.
  if (pos.slotStartMs !== state.currentSlotStart) {
    state.currentSlotStart = pos.slotStartMs;
    state.currentItemId = pos.track.id;
    state.current = pos;
    if (state.failedItemId && state.failedItemId !== pos.track.id) clearFailure();
    renderTrack(pos, now);
    if (state.listening) load(pos, 'boundary');
  }

  align(pos);
  renderCountdown(pos, now);
  renderStatus();
}

/* ------------------------------------------------------------ playback */

function load(pos, why) {
  if (state.failedItemId === pos.track.id && clockTime() < state.failedUntilMs) return;

  const src = absolute(pos.track.mediaUrl, panel);
  state.loading = true;
  ui.play.dataset.busy = '';

  const start = () => {
    state.loading = false;
    // Loading took time. The station moved on while it happened, so the
    // offset computed before the fetch is already stale — resolve again.
    const fresh = resolve(state.publication, clockTime());
    if (fresh.state !== 'on-air') return;
    if (fresh.track.id !== pos.track.id) return load(fresh, 'overtaken');

    seekTo(fresh.sourceOffsetMs / 1000);
    audio.play().then(() => {
      ui.body.dataset.state = 'playing';
    }).catch(() => {
      // Autoplay policy, almost always. The listener presses the button.
      state.listening = false;
      setPlaying(false);
      ui.body.dataset.state = 'ready';
      state.notice = say('The browser asked for a tap before playing sound.');
    });
  };

  if (audio.src !== src) {
    audio.src = src;
    audio.load();
    audio.addEventListener('loadedmetadata', start, { once: true });
  } else if (audio.readyState >= 1) {
    // The same file, scheduled again — a cycle rolling over onto the track it
    // just finished, or a one-track station. There is nothing to load and no
    // `loadedmetadata` to wait for, so start now. Waiting for an event that
    // will never fire leaves the element sitting at the end of the last play,
    // silent, for the whole slot.
    start();
  } else {
    audio.addEventListener('loadedmetadata', start, { once: true });
  }
}

function seekTo(seconds) {
  try { audio.currentTime = Math.max(0, seconds); } catch { /* not seekable yet */ }
}

/**
 * Keep playback near the station's position — without jumping at every
 * hundredth of a second. Small errors are absorbed by a slight rate change,
 * large ones by a seek.
 */
function align(pos) {
  if (!state.listening || state.loading || audio.paused || audio.readyState < 2) return;
  if (state.failedItemId === pos.track.id) return;

  const intended = pos.sourceOffsetMs / 1000;
  const driftMs = Math.round((audio.currentTime - intended) * 1000);

  if (Math.abs(driftMs) > DRIFT_HARD_MS) {
    seekTo(intended);
    audio.playbackRate = 1;
  } else if (Math.abs(driftMs) > DRIFT_SOFT_MS) {
    audio.playbackRate = driftMs > 0 ? 0.98 : 1.02;
  } else if (audio.playbackRate !== 1) {
    audio.playbackRate = 1;
  }
}

audio.addEventListener('playing', () => { delete ui.play.dataset.busy; });
audio.addEventListener('error', () => {
  delete ui.play.dataset.busy;
  if (!state.currentItemId || !state.listening) return;
  // One unplayable file must not turn this listener's station into a private
  // programme. Hold, keep the clock running, and rejoin at the next boundary.
  const pos = resolve(state.publication, clockTime());
  state.failedItemId = state.currentItemId;
  state.failedUntilMs = pos.nextBoundaryMs || (clockTime() + 30000);
  state.notice = say('This track would not load. Rejoining at {time}.', { time: hhmm(state.failedUntilMs) });
  ui.body.dataset.state = 'error';
});

// A file that ends before its slot does (short, or mis-measured) is not a
// reason to advance: the tick moves on when the schedule says so.

function clearFailure() {
  state.failedItemId = null;
  state.failedUntilMs = 0;
  state.notice = null;
  if (ui.body.dataset.state === 'error') ui.body.dataset.state = state.listening ? 'playing' : 'ready';
}

function setPlaying(on) {
  ui.play.setAttribute('aria-pressed', String(on));
  ui.play.setAttribute('aria-label', on ? say('Stop') : say('Tune in'));
  ui.play.title = on ? say('Stop') : say('Tune in');
  ui.glyph.setAttribute('d', on ? STOP : PLAY);
}

async function tuneIn() {
  if (state.listening || !state.publication) return;
  state.listening = true;
  state.notice = null;
  setPlaying(true);
  ui.play.dataset.busy = '';
  pollForNewRevision();
  await clock.sync();
  const pos = resolve(state.publication, clockTime());
  if (pos.state === 'on-air') {
    state.currentSlotStart = pos.slotStartMs;
    state.currentItemId = pos.track.id;
    state.current = pos;
    renderTrack(pos, clockTime());
    load(pos, 'tune-in');
  }
}

function stopListening() {
  if (!state.listening) return;
  // "Stop" means stop, not pause: there is nowhere to resume to. The station
  // carries on without this listener.
  state.listening = false;
  audio.pause();
  audio.removeAttribute('src');
  audio.load();
  delete ui.play.dataset.busy;
  setPlaying(false);
  ui.body.dataset.state = 'ready';
  document.title = (state.publication?.station?.name) || '52Hertz';
}

ui.play.addEventListener('click', () => (state.listening ? stopListening() : tuneIn()));

// The phone's own controls - the lock screen, headphones, the car - do the
// same as the button.
if ('mediaSession' in navigator) {
  const on = (action, handler) => { try { navigator.mediaSession.setActionHandler(action, handler); } catch { /* unsupported */ } };
  on('play', tuneIn);
  on('pause', stopListening);
  on('stop', stopListening);
}

/* --------------------------------------------- sleep, return, reconnect */

async function resync(reason) {
  await clock.sync();
  state.currentSlotStart = null;        // force a re-read of what is on now
  if (state.listening) {
    const pos = resolve(state.publication, clockTime());
    if (pos.state === 'on-air') load(pos, reason);
  }
  tick();
}

document.addEventListener('visibilitychange', () => {
  if (!state.publication) return;
  if (document.visibilityState === 'visible') {
    resync('woke');
    pollForNewRevision();
  } else {
    scheduleRevisionPoll();
  }
});
window.addEventListener('focus', () => { if (state.publication) pollForNewRevision(); });
window.addEventListener('online', () => {
  if (!state.publication) return;
  resync('reconnected');
  pollForNewRevision();
});

/* ------------------------------------------------- revisions in the wild */

let revisionPollTimer = null;
let revisionCheckInFlight = null;

function revisionPollDelay() {
  return document.visibilityState === 'hidden'
    ? REVISION_POLL_HIDDEN_MS
    : REVISION_POLL_VISIBLE_MS;
}

function scheduleRevisionPoll(delay = revisionPollDelay()) {
  clearTimeout(revisionPollTimer);
  revisionPollTimer = setTimeout(pollForNewRevision, delay);
}

async function pollForNewRevision() {
  clearTimeout(revisionPollTimer);
  if (revisionCheckInFlight) return revisionCheckInFlight;

  revisionCheckInFlight = checkForNewRevision();
  try {
    await revisionCheckInFlight;
  } finally {
    revisionCheckInFlight = null;
    scheduleRevisionPoll();
  }
}

async function checkForNewRevision() {
  try {
    const next = await fetchPublicationFeed();
    if (!next || next.revision === state.publication.revision) return;
    if (state.staged && state.staged.revision === next.revision) return;
    if (validatePublication(next).errors.length) return;
    state.staged = next;
  } catch { /* the station keeps playing on what it already has */ }
}

/** Adopt the new feed now; its snapshot chain preserves the current track. */
function maybeActivateStaged() {
  if (!state.staged) return;
  adopt(state.staged);
  state.staged = null;
}

/* ------------------------------------------------------------ rendering */

function programmeOf(pos) {
  const pub = state.publication;
  return pos && pos.programme && pub.programmes ? pub.programmes[String(pos.programme.id)] : null;
}

/** Behind the station's name: its programme's cover, else its own. */
function heroFor(pos) {
  const pub = state.publication;
  const url = (programmeOf(pos) || {}).artUrl || (pub.station && pub.station.artUrl) || '';
  return url ? absolute(url, panel) : '';
}

/** Beside what is playing: the track's cover, else the programme's, else the station's. */
function sleeveFor(pos) {
  const url = pos.track.artUrl ? absolute(pos.track.artUrl, panel) : heroFor(pos);
  return url || '';
}

function paintHero(url) {
  if (url === state.heroUrl) return;
  state.heroUrl = url;
  showImage(ui.hero, url, (shown) => {
    ui.hero.style.backgroundImage = shown ? 'url("' + shown + '")' : '';
    ui.hero.toggleAttribute('data-ready', Boolean(shown));
  });
}

// The last cover stays until the next one has arrived, then gives way to it
// at once: no letter flashing up between two tracks that both have pictures.
function paintSleeve(url, letter) {
  ui.sleeveLetter.textContent = letter;
  if (url === state.sleeveUrl) return;
  state.sleeveUrl = url;
  showImage(ui.sleeveImg, url, (shown) => {
    ui.sleeveImg.hidden = !shown;
    if (!shown) { ui.sleeveImg.removeAttribute('data-ready'); return; }
    ui.sleeveImg.src = shown;
    requestAnimationFrame(() => ui.sleeveImg.setAttribute('data-ready', ''));
  });
}

/* The full-screen profile has its own small copy of the current cover. */
function paintAboutArt(url, letter) {
  ui.aboutArtLetter.textContent = letter;
  if (url === state.aboutArtUrl) return;
  state.aboutArtUrl = url;
  showImage(ui.aboutArtImg, url, (shown) => {
    ui.aboutArtImg.hidden = !shown;
    ui.aboutBackdrop.style.backgroundImage = shown ? 'url("' + shown + '")' : '';
    if (!shown) { ui.aboutArtImg.removeAttribute('data-ready'); return; }
    ui.aboutArtImg.src = shown;
    requestAnimationFrame(() => ui.aboutArtImg.setAttribute('data-ready', ''));
  });
}

function renderTrack(pos, now) {
  const item = pos.track;
  const station = state.publication.station || {};
  const title = item.title || item.id;

  ui.title.textContent = title;
  ui.credit.textContent = item.credit || '';
  // How long it plays here: its slot, which an exact event may cut short.
  ui.length.textContent = mmss(pos.slotEndMs - pos.slotStartMs);
  paintHero(heroFor(pos));
  paintSleeve(sleeveFor(pos), title.trim().charAt(0).toUpperCase());
  paintAboutArt(sleeveFor(pos), title.trim().charAt(0).toUpperCase());

  ui.aboutTrackTitle.textContent = title;
  ui.aboutTrackCredit.textContent = item.credit || '';
  ui.aboutStart.textContent = say('Starts at {time}', { time: hhmm(pos.slotStartMs) });
  ui.aboutDuration.textContent = say('{time} total', { time: mmss(pos.slotEndMs - pos.slotStartMs) });

  // About this track. Its title and credit are in sight above, so not here.
  ui.description.textContent = item.description || '';
  ui.description.hidden = !item.description;
  const programme = programmeOf(pos);
  const programmeName = (programme && programme.name) || (pos.programme && pos.programme.name) || '';
  ui.programme.textContent = programmeName ? say('In the programme “{name}”', { name: programmeName }) : '';
  ui.programme.hidden = !programmeName;
  const itemLink = webLink(item.pageUrl);
  if (itemLink) {
    ui.link.href = itemLink;
    ui.link.hidden = false;
  } else {
    ui.link.removeAttribute('href');
    ui.link.hidden = true;
  }
  // The countdown starts full; let it jump there rather than run backwards.
  ui.left.setAttribute('data-jump', '');
  ui.left.style.setProperty('--left', '100%');
  void ui.left.offsetWidth;
  ui.left.removeAttribute('data-jump');

  renderNext(now);

  if (state.listening) document.title = title + ' · ' + (station.name || '52Hertz');
  if ('mediaSession' in navigator && typeof MediaMetadata === 'function') {
    const art = sleeveFor(pos);
    navigator.mediaSession.metadata = new MediaMetadata({
      title,
      artist: item.credit || station.name || '',
      album: station.name || '',
      artwork: art ? [{ src: art }] : [],
    });
  }

  ui.play.disabled = false;
  ui.more.disabled = false;
  ui.share.disabled = false;
}

/** What comes next: when, a small cover, the title and who made it. */
function renderNext(now) {
  const next = upcoming(state.publication, now, 1);
  ui.later.innerHTML = '';
  for (const pos of next) {
    const li = document.createElement('li');
    const time = document.createElement('span');
    time.className = 'later-time';
    time.textContent = hhmm(pos.slotStartMs);
    const art = document.createElement('span');
    art.className = 'later-art';
    const cover = sleeveFor(pos);
    if (cover) art.style.backgroundImage = 'url("' + cover + '")';
    const words = document.createElement('span');
    words.className = 'later-words';
    const name = document.createElement('span');
    name.className = 'later-name';
    name.dir = 'auto';
    name.textContent = pos.track.title || pos.track.id;
    const credit = document.createElement('span');
    credit.className = 'later-credit';
    credit.dir = 'auto';
    credit.textContent = pos.track.credit || '';
    words.append(name, credit);
    li.append(time, art, words);
    ui.later.appendChild(li);
  }
  ui.queueTitle.hidden = !next.length;
}

/** How long this track has left, whether or not anyone is listening. */
function renderCountdown(pos, now) {
  const slotMs = pos.slotEndMs - pos.slotStartMs;
  const leftMs = Math.max(0, pos.slotEndMs - now);
  ui.left.style.setProperty('--left', (slotMs > 0 ? leftMs / slotMs * 100 : 0).toFixed(2) + '%');
  ui.leftTime.textContent = say('{time} left', { time: mmss(leftMs) });
}

function renderStatus() {
  ui.onair.textContent = say('on air');
  ui.notice.textContent = state.notice || '';
}

function renderOffAir(pos) {
  ui.body.dataset.state = 'offair';
  ui.onair.textContent = say('off air');
  if (state.currentSlotStart !== 'off') {
    state.currentSlotStart = 'off';
    const station = state.publication.station || {};
    paintHero(heroFor(null));
    paintSleeve(heroFor(null), (station.name || '?').trim().charAt(0).toUpperCase());
  }
  ui.title.textContent = {
    'disabled': say('Off air'),
    'nothing-scheduled': say('Not on air yet'),
    'empty-programme': say('Nothing to play'),
  }[pos.reason] || say('Off air');
  ui.length.textContent = '';
  ui.credit.textContent = pos.reason === 'disabled'
    ? say('This station has been taken off air by whoever runs it.')
    : (pos.nextBoundaryMs ? say('Back on air at {time}', { time: hhmm(pos.nextBoundaryMs) }) : '');
  ui.notice.textContent = '';
  ui.play.disabled = true;
  ui.more.disabled = true;
  ui.later.innerHTML = '';
  ui.queueTitle.hidden = true;
  closeAbout();
}

/* ------------------------------------------------------ about the track */

// The info button swaps what comes next for what there is to know about the
// track, in the same place, and back again.
function openAbout() {
  ui.app.setAttribute('data-about', '');
  ui.stage.inert = true;
  ui.controls.inert = true;
  ui.share.inert = true;
  ui.about.inert = false;
  ui.more.setAttribute('aria-expanded', 'true');
  ui.shut.focus({ preventScroll: true });
}

function closeAbout() {
  if (!ui.app.hasAttribute('data-about')) return;
  ui.app.removeAttribute('data-about');
  ui.about.inert = true;
  ui.stage.inert = false;
  ui.controls.inert = false;
  ui.share.inert = false;
  ui.more.setAttribute('aria-expanded', 'false');
}

ui.more.addEventListener('click', () => (ui.app.hasAttribute('data-about') ? closeAbout() : openAbout()));
ui.shut.addEventListener('click', () => { closeAbout(); ui.more.focus({ preventScroll: true }); });
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeAbout();
  if (event.key !== 'Tab' || !ui.app.hasAttribute('data-about')) return;
  const focusable = [...ui.about.querySelectorAll('button:not([disabled]), a[href]:not([hidden])')];
  if (!focusable.length) { event.preventDefault(); return; }
  const first = focusable[0], last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) {
    event.preventDefault(); last.focus();
  } else if (!event.shiftKey && document.activeElement === last) {
    event.preventDefault(); first.focus();
  }
});

/* ----------------------------------------------------------------- share */

let toastTimer = 0;
function toast(message) {
  ui.toast.textContent = message;
  ui.toast.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { ui.toast.hidden = true; }, 2200);
}

// The phone's own share sheet where there is one; elsewhere the link is
// copied. What is shared is this page, which always plays what is on now.
ui.share.addEventListener('click', async () => {
  const station = (state.publication && state.publication.station && state.publication.station.name) || '';
  const track = state.current && state.current.track && (state.current.track.title || state.current.track.id);
  const text = track && state.listening ? say('{track}, now on {station}', { track, station }) : say('Listen to {station}', { station });
  if (navigator.share) {
    try { await navigator.share({ title: station, text, url: publicPlayerUrl }); return; } catch (err) {
      if (err && err.name === 'AbortError') return;   // the listener changed their mind
    }
  }
  try {
    await navigator.clipboard.writeText(publicPlayerUrl);
    toast(say('Link copied.'));
  } catch { /* nothing more to try; the address bar still has it */ }
});

boot();
