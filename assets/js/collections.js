/** Collection editor: one searchable sheet for creating and changing pools. */
import { t, tn } from './i18n.js';

const dialog = document.getElementById('collection-sheet');

if (dialog) {
  const form = dialog.querySelector('form');
  const remove = document.getElementById('collection-delete');
  const rows = [...dialog.querySelectorAll('[data-collection-track]')];
  const search = dialog.querySelector('[data-collection-search]');
  const tag = dialog.querySelector('[data-collection-tag]');
  const picked = dialog.querySelector('[data-collection-picked]');

  function refresh() {
    const query = search.value.trim().toLocaleLowerCase();
    let shown = 0;
    for (const row of rows) {
      const tags = (row.dataset.tags || '').split(',').map(value => value.trim());
      row.hidden = !((!query || row.dataset.search.includes(query)) && (!tag.value || tags.includes(tag.value)));
      if (!row.hidden) shown++;
    }
    const count = rows.filter(row => row.querySelector('input').checked).length;
    picked.textContent = tn(count, '{n} track selected', '{n} tracks selected');
    const visible = rows.filter(row => !row.hidden);
    const allVisible = visible.length > 0 && visible.every(row => row.querySelector('input').checked);
    dialog.querySelector('[data-collection-visible]').textContent = allVisible ? t('Clear visible') : t('Select visible');
  }

  function open(row = null) {
    form.reset();
    search.value = '';
    tag.value = '';
    const members = new Set(row ? row.dataset.tracks.split(',').filter(Boolean) : []);
    form.elements.collection_id.value = row ? row.dataset.collectionEdit : '';
    form.elements.name.value = row ? row.dataset.name : '';
    for (const box of form.querySelectorAll('[name="tracks[]"]')) box.checked = members.has(box.value);
    remove.elements.collection_id.value = row ? row.dataset.collectionEdit : '';
    dialog.querySelector('[data-collection-delete]').hidden = !row;
    dialog.querySelector('[data-collection-title]').textContent = row ? t('Edit collection') : t('New collection');
    dialog.querySelector('[data-collection-submit]').textContent = row ? t('Save collection') : t('Create collection');
    refresh();
    dialog.showModal();
    form.elements.name.focus();
  }

  search.addEventListener('input', refresh);
  tag.addEventListener('change', refresh);
  form.addEventListener('change', event => { if (event.target.matches('[name="tracks[]"]')) refresh(); });
  dialog.querySelector('[data-collection-visible]').addEventListener('click', () => {
    const visible = rows.filter(row => !row.hidden);
    const check = !visible.length || !visible.every(row => row.querySelector('input').checked);
    for (const row of visible) row.querySelector('input').checked = check;
    refresh();
  });
  document.querySelectorAll('[data-collection-new]').forEach(button => button.addEventListener('click', () => open()));
  for (const row of document.querySelectorAll('[data-collection-edit]')) {
    row.addEventListener('click', () => open(row));
    row.addEventListener('keydown', event => {
      if (event.target !== row || !['Enter', ' '].includes(event.key)) return;
      event.preventDefault(); open(row);
    });
  }
}
