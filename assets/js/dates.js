/**
 * Dates, in whichever calendar and language the panel is set to.
 *
 * The browser can *display* a Jalali date (Intl knows the Persian calendar),
 * but it cannot do arithmetic in one, and its date input is Gregorian only.
 * So the conversion lives here - the standard arithmetic (Borkowski's
 * algorithm, as used by jalaali-js) - and the picker below is our own.
 *
 * Everything is converted through a Julian Day Number, and every date this
 * panel stores is a programme day number: the day a station's 24 hours begin.
 */

const root = document.documentElement;

/** The panel's settings, as the page was rendered with them. */
export const LANG = root.lang || 'en';
/** The locale the language file asked to be formatted with. */
export const LOCALE = root.dataset.locale || LANG;
export const CALENDAR = root.dataset.calendar === 'persian' ? 'persian' : 'gregorian';
/** The order a whole date is written in, where the language file gives one: "weekday day month year". */
const DATE_PARTS = (root.dataset.dateParts || '').split(' ').filter(Boolean);
/** Calendar column heads, as the language file asks: "short" or "narrow". */
const WEEKDAY_NAMES = root.dataset.weekdayNames === 'narrow' ? 'narrow' : 'short';

const div = (a, b) => Math.trunc(a / b);
const mod = (a, b) => a - Math.trunc(a / b) * b;

/* ------------------------------------------------------- the arithmetic */

function gregorianToJdn(gy, gm, gd) {
  let d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
  d = d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
  return d;
}

function jdnToGregorian(jdn) {
  let j = 4 * jdn + 139361631;
  j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
  const i = div(mod(j, 1461), 4) * 5 + 308;
  const gd = div(mod(i, 153), 5) + 1;
  const gm = mod(div(i, 153), 12) + 1;
  const gy = div(j, 1461) - 100100 + div(8 - gm, 6);
  return { y: gy, m: gm, d: gd };
}

const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060,
  2097, 2192, 2262, 2324, 2394, 2456, 3178];

/** Where a Jalali year sits: whether it is leap, and the March day it begins. */
function jalaliYear(jy) {
  const gy = jy + 621;
  let leapJ = -14;
  let jp = BREAKS[0];
  let jump = 0;
  for (let i = 1; i < BREAKS.length; i++) {
    const jm = BREAKS[i];
    jump = jm - jp;
    if (jy < jm) break;
    leapJ = leapJ + div(jump, 33) * 8 + div(mod(jump, 33), 4);
    jp = jm;
  }
  let n = jy - jp;
  leapJ = leapJ + div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
  if (mod(jump, 33) === 4 && jump - n === 4) leapJ += 1;
  const leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
  const march = 20 + leapJ - leapG;
  if (jump - n < 6) n = n - jump + div(jump + 4, 33) * 33;
  let leap = mod(mod(n + 1, 33) - 1, 4);
  if (leap === -1) leap = 4;
  return { leap: leap === 0, gy, march };
}

function jalaliToJdn(jy, jm, jd) {
  const r = jalaliYear(jy);
  return gregorianToJdn(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
}

function jdnToJalali(jdn) {
  const gy = jdnToGregorian(jdn).y;
  let jy = gy - 621;
  const r = jalaliYear(jy);
  let k = jdn - gregorianToJdn(gy, 3, r.march);
  if (k >= 0) {
    if (k <= 185) return { y: jy, m: 1 + div(k, 31), d: mod(k, 31) + 1 };
    k -= 186;
  } else {
    jy -= 1;
    k += 179;
    if (jalaliYear(jy).leap) k += 1;
  }
  return { y: jy, m: 7 + div(k, 30), d: mod(k, 30) + 1 };
}

/* ---------------------------------------------- one calendar, either kind */

/** A date in the panel's calendar, from a Julian Day Number, and back. */
export const calendar = {
  fromJdn: (jdn) => (CALENDAR === 'persian' ? jdnToJalali(jdn) : jdnToGregorian(jdn)),
  toJdn: ({ y, m, d }) => (CALENDAR === 'persian' ? jalaliToJdn(y, m, d) : gregorianToJdn(y, m, d)),
  monthLength(y, m) {
    if (CALENDAR === 'persian') return m <= 6 ? 31 : m <= 11 ? 30 : jalaliYear(y).leap ? 30 : 29;
    return new Date(Date.UTC(y, m, 0)).getUTCDate();
  },
  /** Day of the week the calendar's weeks start on: Saturday for Jalali, Sunday otherwise. */
  weekStart: CALENDAR === 'persian' ? 6 : 0,
};

/** The Julian Day Number of a local calendar date that contains `ms`. */
export function jdnOfInstant(ms) {
  const local = new Date(ms);
  return gregorianToJdn(local.getFullYear(), local.getMonth() + 1, local.getDate());
}

/** Local midnight of a Julian Day Number, as an instant. */
export function instantOfJdn(jdn) {
  const g = jdnToGregorian(jdn);
  return new Date(g.y, g.m - 1, g.d).getTime();
}

/** Day of the week of a Julian Day Number, 0 = Sunday. */
export const weekdayOfJdn = (jdn) => mod(jdn + 1, 7);

/* ------------------------------------------------------------ formatting */

// The calendar is ours to say; the digits are the locale's own business, so
// a language dropped in here is numbered the way its readers expect without
// this file being told anything about it.
const intlLocale = LOCALE + '-u-ca-' + (CALENDAR === 'persian' ? 'persian' : 'gregory');

const formats = new Map();
function format(options) {
  const key = JSON.stringify(options);
  if (!formats.has(key)) formats.set(key, new Intl.DateTimeFormat(intlLocale, options));
  return formats.get(key);
}

/** "Saturday 1 Farvardin 1405" - a whole date, in the panel's language and calendar. */
export function formatDate(ms, { weekday = 'long', year = 'numeric' } = {}) {
  const options = { day: 'numeric', month: 'long' };
  if (weekday) options.weekday = weekday;
  if (year) options.year = year;
  if (DATE_PARTS.length) {
    // Assembled by hand, in the order the language file names: a browser's
    // own pattern for a locale is not always the one its readers use.
    // Without one, the locale's pattern stands.
    const part = Object.fromEntries(format(options).formatToParts(new Date(ms)).map((p) => [p.type, p.value]));
    return DATE_PARTS.map((name) => part[name]).filter(Boolean).join(' ');
  }
  // "1405 AP" says nothing a Jalali panel needs said.
  return format(options).format(new Date(ms)).replace(/\s?AP$/, '');
}

export function formatTime(ms, { seconds = false } = {}) {
  const options = { hour: '2-digit', minute: '2-digit', hourCycle: 'h23' };
  if (seconds) options.second = '2-digit';
  return format(options).format(new Date(ms));
}

/**
 * What goes between a date and the time after it.
 *
 * Intl will not hand over a bare separator, so it is asked to join two things
 * and the join is read back out of the middle. That way Persian gets its "،"
 * and every other language whatever its own readers use, with nothing here
 * naming a single one of them.
 */
let comma = null;
export function listComma() {
  if (comma === null) {
    try {
      const parts = new Intl.ListFormat(LOCALE, { style: 'narrow', type: 'unit' }).formatToParts(['1', '2']);
      comma = parts.find((p) => p.type === 'literal')?.value ?? ', ';
    } catch {
      comma = ', ';
    }
  }
  return comma;
}

export function formatNumber(n) {
  return new Intl.NumberFormat(LOCALE).format(n);
}

/** "Farvardin 1405" for a picker header. */
export function formatMonth(y, m) {
  const mid = instantOfJdn(calendar.toJdn({ y, m, d: 15 }));
  return format({ month: 'long', year: 'numeric' }).format(new Date(mid)).replace(/\s?AP$/, '');
}

/** Short weekday names in calendar order, starting on the week's first day. */
export function weekdayNames() {
  const names = [];
  // 2023-01-01 was a Sunday.
  for (let i = 0; i < 7; i++) {
    const day = (calendar.weekStart + i) % 7;
    names.push(format({ weekday: WEEKDAY_NAMES }).format(new Date(2023, 0, 1 + day)));
  }
  return names;
}
