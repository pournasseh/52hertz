/**
 * Cover pickers, and reading the cover an MP3 already carries.
 *
 * A picker is a square tile showing the cover this thing has: the image just
 * chosen, the one already saved, or nothing.
 * Clicking or dropping replaces it; the corner button removes it. The form
 * posts the input's own field name (normally `art`, or `logo`) and the same
 * name plus `_remove` when the saved image should go.
 */

import { t } from './i18n.js';

const NOTES = {
  empty: t('Optional'),
  saved: t('Click to replace'),
  chosen: t('Ready to save'),
  embedded: t('Found in the file — click to replace'),
};

function parts(field) {
  return {
    tile: field.querySelector('.cover-tile'),
    input: field.querySelector('[data-cover-input]'),
    img: field.querySelector('[data-cover-img]'),
    empty: field.querySelector('[data-cover-empty]'),
    clear: field.querySelector('[data-cover-clear]'),
    note: field.querySelector('[data-cover-note]'),
    remove: field.querySelector('[data-cover-remove]'),
  };
}

function paint(field, src, state) {
  const p = parts(field);
  if (field.dataset.objectUrl) {
    URL.revokeObjectURL(field.dataset.objectUrl);
    delete field.dataset.objectUrl;
  }
  if (src.startsWith('blob:')) field.dataset.objectUrl = src;

  p.img.hidden = src === '';
  if (src) p.img.src = src; else p.img.removeAttribute('src');
  p.empty.hidden = src !== '';
  p.tile.classList.toggle('has-image', src !== '');
  p.clear.hidden = src === '';
  p.note.textContent = NOTES[state];
}

/**
 * Put a picker into a known state.
 *
 * @param {HTMLElement} field
 * @param {{url?: string, file?: File|null, from?: 'user'|'embedded'}} what
 */
export function setCover(field, { url = '', file = null, from = 'user' } = {}) {
  const p = parts(field);
  p.remove.value = '0';
  if (file) {
    const carrier = new DataTransfer();
    carrier.items.add(file);
    p.input.files = carrier.files;
    field.dataset.coverFrom = from;
    paint(field, URL.createObjectURL(file), from === 'embedded' ? 'embedded' : 'chosen');
    return;
  }
  p.input.value = '';
  delete field.dataset.coverFrom;
  field.dataset.saved = url;
  paint(field, url, url ? 'saved' : 'empty');
}

function clearCover(field) {
  const p = parts(field);
  p.input.value = '';
  delete field.dataset.coverFrom;
  // Only a cover that was already saved needs to be removed on the server.
  p.remove.value = field.dataset.saved ? '1' : '0';
  paint(field, '', 'empty');
}

const IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

function wire(field) {
  const p = parts(field);

  p.input.addEventListener('change', () => {
    const file = p.input.files[0];
    if (!file) return;
    if (!IMAGE_TYPES.includes(file.type)) {
      p.input.value = '';
      p.note.textContent = t('Use a JPG, PNG or WebP image');
      return;
    }
    setCover(field, { file });
  });

  p.clear.addEventListener('click', () => clearCover(field));

  for (const type of ['dragenter', 'dragover']) {
    p.tile.addEventListener(type, (event) => {
      event.preventDefault();
      event.stopPropagation();
      p.tile.classList.add('is-over');
    });
  }
  for (const type of ['dragleave', 'dragend']) {
    p.tile.addEventListener(type, () => p.tile.classList.remove('is-over'));
  }
  p.tile.addEventListener('drop', (event) => {
    event.preventDefault();
    event.stopPropagation();
    p.tile.classList.remove('is-over');
    const file = event.dataTransfer.files[0];
    if (!file) return;
    if (!IMAGE_TYPES.includes(file.type)) {
      p.note.textContent = t('Use a JPG, PNG or WebP image');
      return;
    }
    setCover(field, { file });
  });

  // A form that is reset, or a dialog opened again, starts from what is saved.
  field.closest('form')?.addEventListener('reset', () => setCover(field, { url: field.dataset.saved || '' }));

  setCover(field, { url: field.dataset.saved || '' });
}

for (const field of document.querySelectorAll('[data-cover]')) wire(field);

/* ------------------------------------------------------- embedded covers */

const syncsafe = (b, i) => ((b[i] & 0x7f) << 21) | ((b[i + 1] & 0x7f) << 14) | ((b[i + 2] & 0x7f) << 7) | (b[i + 3] & 0x7f);
const uint32 = (b, i) => ((b[i] << 24) | (b[i + 1] << 16) | (b[i + 2] << 8) | b[i + 3]) >>> 0;

/** Undo ID3 unsynchronisation: every 0xFF 0x00 was once a plain 0xFF. */
function resync(bytes) {
  const out = [];
  for (let i = 0; i < bytes.length; i++) {
    out.push(bytes[i]);
    if (bytes[i] === 0xff && bytes[i + 1] === 0x00) i++;
  }
  return Uint8Array.from(out);
}

/** What the bytes say they are, which beats what the tag says they are. */
function sniff(b) {
  if (b[0] === 0xff && b[1] === 0xd8) return ['image/jpeg', 'jpg'];
  if (b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4e && b[3] === 0x47) return ['image/png', 'png'];
  if (b[0] === 0x52 && b[1] === 0x49 && b[8] === 0x57 && b[9] === 0x45) return ['image/webp', 'webp'];
  return null;
}

/**
 * The front cover inside an audio file's ID3v2 tag (MP3, and anything else
 * that carries one), as a File — or null when there is none worth using.
 * Handles ID3 v2.2, v2.3 and v2.4; prefers the picture marked "front cover".
 */
export async function embeddedCover(file) {
  const head = new Uint8Array(await file.slice(0, 10).arrayBuffer());
  if (head[0] !== 0x49 || head[1] !== 0x44 || head[2] !== 0x33) return null; // "ID3"
  const version = head[3];
  const flags = head[5];
  const size = syncsafe(head, 6);
  if (version < 2 || version > 4 || size > 30 * 1024 * 1024) return null;

  let tag = new Uint8Array(await file.slice(10, 10 + size).arrayBuffer());
  if (flags & 0x80 && version < 4) tag = resync(tag);

  let pos = 0;
  if (flags & 0x40 && version > 2) pos = version === 3 ? uint32(tag, 0) + 4 : syncsafe(tag, 0);

  const idLength = version === 2 ? 3 : 4;
  const headerLength = version === 2 ? 6 : 10;
  let best = null;

  while (pos + headerLength <= tag.length) {
    const id = String.fromCharCode(...tag.subarray(pos, pos + idLength));
    if (!/^[A-Z0-9]+$/.test(id)) break; // padding
    const frameSize = version === 2 ? (tag[pos + 3] << 16) | (tag[pos + 4] << 8) | tag[pos + 5]
      : version === 4 ? syncsafe(tag, pos + 4) : uint32(tag, pos + 4);
    const format = version === 2 ? 0 : tag[pos + 9];
    let body = tag.subarray(pos + headerLength, pos + headerLength + frameSize);
    pos += headerLength + frameSize;
    if (id !== 'APIC' && id !== 'PIC') continue;

    if (version === 3) {
      if (format & 0xc0) continue; // compressed or encrypted
      if (format & 0x20) body = body.subarray(1);
    } else if (version === 4) {
      if (format & 0x0c) continue;
      if (format & 0x40) body = body.subarray(1);
      if (format & 0x01) body = body.subarray(4);
      if (format & 0x02) body = resync(body);
    }

    const encoding = body[0];
    let i = 1;
    if (id === 'PIC') {
      i = 4; // three-letter image format
    } else {
      while (i < body.length && body[i] !== 0) i++;
      i++;
    }
    const pictureType = body[i++];
    if (encoding === 1 || encoding === 2) {
      while (i + 1 < body.length && !(body[i] === 0 && body[i + 1] === 0)) i += 2;
      i += 2;
    } else {
      while (i < body.length && body[i] !== 0) i++;
      i++;
    }

    const data = body.subarray(i);
    const kind = sniff(data);
    if (!kind) continue;
    const picture = { data, kind };
    if (pictureType === 3) { best = picture; break; }
    best ??= picture;
  }

  if (!best) return null;
  return new File([best.data], 'cover.' + best.kind[1], { type: best.kind[0] });
}
