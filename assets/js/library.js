/**
 * The library's own behaviour: searching, tag filtering, selecting several
 * tracks for one action, the add dialog, and the track sheet.
 *
 * Searching happens in the browser against rows already on the page. A
 * station's library is tens or hundreds of tracks, not millions, so filtering
 * locally is both simpler than a round trip and instant to type against —
 * which is the whole point of a search box.
 */

import { embeddedCover, setCover } from './covers.js';
import { formatDate, formatNumber } from './dates.js';
import { t, tn } from './i18n.js';

const table = document.getElementById('tracks');

/* ------------------------------------------------------------- searching */

if (table) {
  const search = document.getElementById('library-search');
  const tagFilter = document.getElementById('tag-filter');
  const empty = document.getElementById('no-matches');
  const rows = [...table.querySelectorAll('tr[data-track]')];

  let term = '';
  let tag = '';

  function apply() {
    let shown = 0;
    for (const row of rows) {
      const haystack = row.dataset.search || '';
      const tags = (row.dataset.tags || '').split(',').map((t) => t.trim());
      const matchesTerm = term === '' || haystack.includes(term);
      const matchesTag = tag === '' || tags.includes(tag);
      const visible = matchesTerm && matchesTag;
      row.hidden = !visible;
      if (visible) {
        // Renumber what is actually on screen, so the first visible row is 1
        // rather than whatever it happens to be in the full list.
        row.querySelector('.row-number').textContent = ++shown;
      }
    }
    if (empty) empty.hidden = shown !== 0;
    refreshSelection();
  }

  /* ------------------------------------------------------------ selecting */

  // A checkbox beside every row. Once anything is ticked, a bar offers what
  // can be done to all of it at once; the forms it opens carry the ids.
  const bar = document.getElementById('bulk-bar');
  const selectAll = document.getElementById('select-all');
  const checks = rows.map((row) => row.querySelector('.row-check'));
  const selectedIds = () => checks.filter((c) => c.checked).map((c) => c.value);

  function refreshSelection() {
    if (!bar) return;
    const ids = selectedIds();
    bar.hidden = ids.length === 0;
    document.getElementById('bulk-count').textContent = ids.length;
    for (const el of document.querySelectorAll('[data-bulk-count]')) el.textContent = ids.length;
    for (const row of rows) row.classList.toggle('is-selected', row.querySelector('.row-check').checked);

    const shown = rows.filter((row) => !row.hidden).map((row) => row.querySelector('.row-check'));
    const ticked = shown.filter((c) => c.checked).length;
    selectAll.checked = shown.length > 0 && ticked === shown.length;
    selectAll.indeterminate = ticked > 0 && ticked < shown.length;

    const remove = document.getElementById('bulk-delete');
    remove.dataset.confirm = t('Delete {tracks}?', { tracks: tn(ids.length, '{n} track', '{n} tracks') });
  }

  if (bar) {
    for (const check of checks) {
      check.addEventListener('change', refreshSelection);
      // Ticking a box is not opening the track.
      check.closest('td').addEventListener('click', (event) => event.stopPropagation());
    }
    // "All" means all that is shown: a filtered view selects what it shows.
    selectAll.addEventListener('change', () => {
      for (const row of rows) {
        if (!row.hidden) row.querySelector('.row-check').checked = selectAll.checked;
      }
      refreshSelection();
    });
    document.getElementById('bulk-clear').addEventListener('click', () => {
      for (const check of checks) check.checked = false;
      refreshSelection();
    });
    for (const form of document.querySelectorAll('.bulk-form')) {
      form.addEventListener('formdata', (event) => {
        for (const id of selectedIds()) event.formData.append('track_ids[]', id);
      });
    }

    // The tags dialog offers to remove only what the selection actually has.
    document.querySelector('[data-modal-open="bulk-tags"]').addEventListener('click', () => {
      const carried = new Set();
      for (const row of rows) {
        if (!row.querySelector('.row-check').checked) continue;
        for (const t of (row.dataset.tags || '').split(',')) if (t.trim()) carried.add(t.trim());
      }
      const dialog = document.getElementById('bulk-tags');
      dialog.querySelector('form').reset();
      for (const label of dialog.querySelectorAll('.tag-check')) label.hidden = !carried.has(label.dataset.tag);
      for (const chip of dialog.querySelectorAll('[data-add-tag]')) chip.classList.remove('is-active');
      const block = document.getElementById('bulk-remove');
      if (block) block.hidden = carried.size === 0;
    });
    refreshSelection();
  }

  if (search) {
    search.addEventListener('input', () => {
      term = search.value.trim().toLowerCase();
      apply();
    });
    // Esc clears the filter rather than the field's own history.
    search.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && search.value !== '') {
        event.preventDefault();
        search.value = '';
        term = '';
        apply();
      }
    });
  }

  if (tagFilter) {
    tagFilter.addEventListener('change', () => {
      tag = tagFilter.value;
      apply();
    });
  }
}

/* ------------------------------------------------------- the add dialog */

for (const segmented of document.querySelectorAll('.segmented')) {
  const dialog = segmented.closest('dialog');
  const activate = button => {
    for (const other of segmented.querySelectorAll('.seg')) other.classList.toggle('is-active', other === button);
    for (const pane of dialog.querySelectorAll('.pane')) {
      const active = pane.id === button.dataset.pane;
      pane.classList.toggle('is-active', active);
      for (const control of pane.querySelectorAll('input, select, textarea')) control.disabled = !active;
    }
    dialog.querySelector('.pane.is-active input, .pane.is-active select')?.focus();
  };
  for (const button of segmented.querySelectorAll('.seg')) {
    button.addEventListener('click', () => activate(button));
  }
  activate(segmented.querySelector('.seg.is-active') || segmented.querySelector('.seg'));
}

/* --------------------------------------------------------- tag shortcuts */

for (const group of document.querySelectorAll('.tag-suggestions')) {
  const input = document.getElementById(group.dataset.for);
  for (const chip of group.querySelectorAll('[data-add-tag]')) {
    chip.addEventListener('click', () => {
      const tag = chip.dataset.addTag;
      const current = input.value.split(',').map((t) => t.trim()).filter(Boolean);
      const at = current.indexOf(tag);
      if (at === -1) current.push(tag); else current.splice(at, 1);   // click again to drop it
      input.value = current.join(', ');
      chip.classList.toggle('is-active', at === -1);
      input.focus();
    });
  }
}

/* ------------------------------------------------------- the track sheet */

const sheet = document.getElementById('track-sheet');
const audio = document.getElementById('sheet-audio');

/**
 * Open the sheet for one track. `track` carries what a library row carries
 * in its data attributes, so the library passes a row's dataset and a
 * programme's page passes the same fields for a track it holds.
 */
export function openTrackSheet(track) {
  if (!sheet) return;
  const programmeCount = Number(track.programmeCount || 0);
  sheet.querySelector('#sheet-title').textContent = track.title || t('(untitled)');
  sheet.querySelector('#sheet-credit').textContent = track.credit || '';
  sheet.querySelector('#sheet-length').textContent = track.length || '—';
  sheet.querySelector('#sheet-programmes').textContent = track.programmes || t('none');
  sheet.querySelector('#sheet-collections').textContent = track.collections || t('none');
  sheet.querySelector('#sheet-file').textContent = track.media || '';
  sheet.querySelector('#sheet-added').textContent = track.added
    ? formatDate(Number(track.added), { weekday: null })
    : t('not yet');

  const state = sheet.querySelector('#sheet-state');
  state.textContent = programmeCount > 0
    ? tn(programmeCount, 'in {n} programme', 'in {n} programmes')
    : t('library only');
  state.className = 'pill ' + (programmeCount > 0 ? 'pill-live' : 'pill-new');

  // Loaded only when the sheet opens, so browsing the library does not
  // quietly fetch every file in it.
  audio.src = track.audio || '';
  audio.load();

  sheet.querySelector('#sheet-track-id').value = track.track;
  sheet.querySelector('#sheet-delete-id').value = track.track;
  sheet.querySelector('#sheet-replace-id').value = track.track;
  sheet.querySelector('#sheet-replace-file').value = '';
  sheet.querySelector('#sheet-edit-title').value = track.title || '';
  sheet.querySelector('#sheet-edit-credit').value = track.credit || '';
  sheet.querySelector('#sheet-edit-description').value = track.description || '';
  setCover(sheet.querySelector('#sheet-cover'), { url: track.art || '' });
  sheet.querySelector('#sheet-tags').value = track.tags || '';

  const carried = (track.tags || '').split(',').map((t) => t.trim());
  for (const chip of sheet.querySelectorAll('[data-add-tag]')) {
    chip.classList.toggle('is-active', carried.includes(chip.dataset.addTag));
  }

  sheet.showModal();
}

if (sheet) {
  // Stop the audio when the sheet closes; a dialog that keeps playing after
  // you dismiss it is a small horror.
  sheet.addEventListener('close', () => {
    audio.pause();
    audio.removeAttribute('src');
    audio.load();
  });

  // Replacing the audio: choose a file, confirm, and it is sent.
  const replaceFile = sheet.querySelector('#sheet-replace-file');
  sheet.querySelector('#sheet-replace').addEventListener('click', () => replaceFile.click());
  replaceFile.addEventListener('change', () => {
    if (replaceFile.files.length) replaceFile.form.requestSubmit();
  });

  for (const row of document.querySelectorAll('tr[data-track]')) {
    row.addEventListener('click', () => openTrackSheet(row.dataset));
    row.addEventListener('keydown', (event) => {
      if (event.target !== row) return;
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        openTrackSheet(row.dataset);
      }
    });
  }
}

/* ------------------------------------------------- plays, on the programme */

// A number you have to press a button to save is a number people forget to
// save. Changing it submits its own row.
for (const input of document.querySelectorAll('.plays-input')) {
  input.addEventListener('change', () => {
    if (input.value === '' || Number(input.value) < 1) return;
    input.form.submit();
  });
}

/* ---------------------------------------------------------- the dropzone */

const dropzone = document.getElementById('dropzone');

if (dropzone) {
  const input = dropzone.querySelector('input[type="file"]');
  const headline = dropzone.querySelector('[data-role="headline"]');
  const detail = dropzone.querySelector('[data-role="detail"]');
  const accepted = ['mp3', 'wav', 'ogg', 'oga', 'm4a', 'aac', 'flac', 'opus', 'webm'];

  const show = (file) => {
    if (!file) {
      headline.textContent = t('Drop an audio file here');
      detail.textContent = t('or click to choose one');
      dropzone.classList.remove('has-file', 'is-wrong');
      return;
    }
    const extension = file.name.split('.').pop().toLowerCase();
    if (!accepted.includes(extension)) {
      headline.textContent = file.name;
      detail.textContent = t('not an audio file — {types}', { types: accepted.join(', ') });
      dropzone.classList.add('is-wrong');
      dropzone.classList.remove('has-file');
      return;
    }
    headline.textContent = file.name;
    detail.textContent = t('{size} MB — drop another to replace it', { size: formatNumber(Math.round(file.size / 104857.6) / 10) });
    dropzone.classList.add('has-file');
    dropzone.classList.remove('is-wrong');
    offerEmbeddedCover(file);
  };

  // A file that already carries its cover brings it along. It is only ever an
  // offer: a cover the operator chose is never replaced, and the one found in
  // the file can be swapped or removed like any other before saving.
  const cover = document.getElementById('add-track-cover');
  const offerEmbeddedCover = async (file) => {
    if (!cover || cover.dataset.coverFrom === 'user') return;
    let found = null;
    try {
      found = await embeddedCover(file);
    } catch {
      found = null;
    }
    if (cover.dataset.coverFrom === 'user' || input.files[0] !== file) return;
    if (found) {
      setCover(cover, { file: found, from: 'embedded' });
    } else if (cover.dataset.coverFrom === 'embedded') {
      setCover(cover, {});
    }
  };

  input.addEventListener('change', () => show(input.files[0]));

  for (const type of ['dragenter', 'dragover']) {
    dropzone.addEventListener(type, (event) => {
      event.preventDefault();
      dropzone.classList.add('is-over');
    });
  }
  for (const type of ['dragleave', 'dragend']) {
    dropzone.addEventListener(type, () => dropzone.classList.remove('is-over'));
  }

  dropzone.addEventListener('drop', (event) => {
    event.preventDefault();
    dropzone.classList.remove('is-over');
    const file = event.dataTransfer.files[0];
    if (!file) return;
    // Hand the dropped file to the input itself, so the form posts exactly as
    // it would have if the file had been chosen through the picker.
    const carrier = new DataTransfer();
    carrier.items.add(file);
    input.files = carrier.files;
    show(file);
  });

  // A file dropped anywhere else would otherwise navigate away from the panel,
  // losing whatever was typed into the form.
  for (const type of ['dragover', 'drop']) {
    window.addEventListener(type, (event) => {
      if (!event.target.closest('#dropzone')) event.preventDefault();
    });
  }

  document.getElementById('add-track')?.addEventListener('close', () => show(null));
}
