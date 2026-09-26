/**
 * The playlist sheet: one dialog creates a playlist or changes one - its
 * name, and which programmes it draws from.
 */

import { t } from './i18n.js';

const dialog = document.getElementById('playlist-sheet');

if (dialog) {
  const form = dialog.querySelector('form');
  const remove = document.getElementById('playlist-delete');

  const open = (row) => {
    form.reset();
    const members = row ? row.dataset.programmes.split(',').filter(Boolean) : [];
    form.elements.playlist_id.value = row ? row.dataset.playlistEdit : '';
    form.elements.name.value = row ? row.dataset.name : '';
    for (const box of form.querySelectorAll('[name="programmes[]"]')) box.checked = members.includes(box.value);
    remove.elements.playlist_id.value = row ? row.dataset.playlistEdit : '';
    dialog.querySelector('[data-playlist-delete]').hidden = !row;
    dialog.querySelector('[data-playlist-title]').textContent = row ? t('Edit playlist') : t('New playlist');
    dialog.querySelector('[data-playlist-submit]').textContent = row ? t('Save playlist') : t('Create playlist');
    dialog.showModal();
    form.elements.name.focus();
  };

  document.querySelectorAll('[data-playlist-new]').forEach(button => button.addEventListener('click', () => open(null)));
  for (const row of document.querySelectorAll('[data-playlist-edit]')) {
    row.addEventListener('click', () => open(row));
    row.addEventListener('keydown', (event) => {
      if (event.target !== row || (event.key !== 'Enter' && event.key !== ' ')) return;
      event.preventDefault();
      open(row);
    });
  }
}
