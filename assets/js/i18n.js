/**
 * The panel's language, for text a script writes.
 *
 * The page carries the same dictionary PHP translates with (languages/*.json),
 * keyed by the English wording, so a sentence reads the same whichever side
 * prints it. A lookup with no translation is itself, which is the English.
 *
 * The digits come from the language file too, so a language that writes its
 * numbers in its own script needs no code here to say so.
 */

const source = document.getElementById('i18n');
const data = source ? JSON.parse(source.textContent) : {};
const dictionary = data.strings ?? {};

/** The ten digits this language writes numbers with, or null for 0-9. */
const DIGITS = typeof data.digits === 'string' && [...data.digits].length === 10 ? [...data.digits] : null;

export const LANG = document.documentElement.lang || 'en';

/** Digits in the panel's language. */
export function num(value) {
  const text = String(value);
  return DIGITS ? text.replace(/[0-9]/g, (d) => DIGITS[Number(d)]) : text;
}

/** A sentence in the panel's language, with `{name}` placeholders filled. */
export function t(text, vars = {}) {
  let out = dictionary[text] ?? text;
  for (const [key, value] of Object.entries(vars)) {
    out = out.split('{' + key + '}').join(typeof value === 'number' ? num(value) : String(value));
  }
  return out;
}

/** A count with its noun: tn(3, '{n} track', '{n} tracks'). */
export function tn(n, one, many, vars = {}) {
  return t(n === 1 ? one : many, { n, ...vars });
}
