/**
 * The panel's small interaction layer, loaded on every page.
 *
 * Dialogs are native <dialog> elements: the browser already handles the
 * backdrop, Esc, focus trapping and returning focus afterwards, and doing it
 * by hand would be worse. This adds openers, closers, backdrop dismissal,
 * draggable window-like title bars, confirmation dialogs and toast cleanup.
 */

import { t } from './i18n.js';

/* ------------------------------------------------------ navigable rows */

// Table rows are convenient click targets, but inline onclick handlers would
// weaken the panel's Content Security Policy. Keep them keyboard-accessible too.
for (const row of document.querySelectorAll('tr[data-href]')) {
  row.addEventListener('click', (event) => {
    if (event.target.closest('a, button, input, select, textarea')) return;
    location.assign(row.dataset.href);
  });
  row.addEventListener('keydown', (event) => {
    if (event.key !== 'Enter') return;
    event.preventDefault();
    location.assign(row.dataset.href);
  });
}

/* --------------------------------------------------------------- toasts */

function dismissToast(toast) {
  if (!toast || toast.classList.contains('is-leaving')) return;
  toast.classList.add('is-leaving');
  setTimeout(() => toast.closest('.toast-region')?.remove(), 160);
}

function armToast(toast) {
  toast.querySelector('[data-toast-close]')?.addEventListener('click', () => dismissToast(toast));
  const delay = toast.classList.contains('toast-bad') ? 9000
    : toast.classList.contains('toast-warn') ? 7000 : 4500;
  const timer = setTimeout(() => dismissToast(toast), delay);
  toast.addEventListener('mouseenter', () => clearTimeout(timer), { once: true });
}

for (const toast of document.querySelectorAll('[data-toast]')) armToast(toast);

/**
 * The same toast, raised by a page script rather than by a redirect: for
 * work that happens without leaving the page, such as saving a programme.
 * kind is 'ok', 'bad', 'warn' or 'info'. A new toast replaces the last one.
 */
export function toast(message, kind = 'ok') {
  document.querySelectorAll('.toast-region').forEach((region) => region.remove());
  const region = document.createElement('div');
  region.className = 'toast-region';
  region.setAttribute('aria-live', 'polite');
  region.setAttribute('aria-atomic', 'true');
  region.innerHTML = `<div class="toast toast-${kind}" role="${kind === 'bad' ? 'alert' : 'status'}" data-toast>
    <span></span><button type="button" class="toast-close" data-toast-close>&times;</button></div>`;
  region.querySelector('span').textContent = message;
  region.querySelector('button').setAttribute('aria-label', t('Dismiss notification'));
  (document.querySelector('main.content') || document.body).prepend(region);
  armToast(region.firstElementChild);
}

/* -------------------------------------------------------------- dragging */

function resetDialogPosition(dialog) {
  for (const property of ['position', 'left', 'top', 'right', 'bottom', 'margin']) {
    dialog.style.removeProperty(property);
  }
}

function makeDraggable(dialog) {
  if (dialog.dataset.draggable === 'yes') return;
  const handle = dialog.querySelector('.modal-card > h2, .modal-card > .sheet-head');
  if (!handle) return;
  dialog.dataset.draggable = 'yes';

  let drag = null;
  handle.addEventListener('pointerdown', (event) => {
    if (event.button !== 0) return;
    const rect = dialog.getBoundingClientRect();
    drag = {
      pointerId: event.pointerId,
      offsetX: event.clientX - rect.left,
      offsetY: event.clientY - rect.top,
      width: rect.width,
      height: rect.height,
    };
    Object.assign(dialog.style, {
      position: 'fixed', left: rect.left + 'px', top: rect.top + 'px',
      right: 'auto', bottom: 'auto', margin: '0',
    });
    handle.setPointerCapture(event.pointerId);
    event.preventDefault();
  });

  handle.addEventListener('pointermove', (event) => {
    if (!drag || event.pointerId !== drag.pointerId) return;
    const maxLeft = Math.max(0, window.innerWidth - drag.width);
    const maxTop = Math.max(0, window.innerHeight - drag.height);
    dialog.style.left = Math.min(maxLeft, Math.max(0, event.clientX - drag.offsetX)) + 'px';
    dialog.style.top = Math.min(maxTop, Math.max(0, event.clientY - drag.offsetY)) + 'px';
  });

  const stop = (event) => {
    if (!drag || event.pointerId !== drag.pointerId) return;
    drag = null;
    if (handle.hasPointerCapture(event.pointerId)) handle.releasePointerCapture(event.pointerId);
  };
  handle.addEventListener('pointerup', stop);
  handle.addEventListener('pointercancel', stop);
  dialog.addEventListener('close', () => resetDialogPosition(dialog));
}

/* ------------------------------------------------------------ open/close */

for (const dialog of document.querySelectorAll('dialog.modal')) makeDraggable(dialog);

for (const trigger of document.querySelectorAll('[data-modal-open]')) {
  trigger.addEventListener('click', () => {
    const dialog = document.getElementById(trigger.dataset.modalOpen);
    if (!dialog) return;
    dialog.showModal();
    dialog.querySelector('input, select, textarea, button')?.focus();
  });
}

// A link can open a dialog on the page it leads to - ...&tab=library#add-track
// - which is how the station guide reaches a step that lives on another tab.
// The page's own opener is clicked when there is one, so whatever it sets up
// happens here too.
function openFromHash() {
  const id = decodeURIComponent(location.hash.slice(1));
  const dialog = id && document.getElementById(id);
  if (!dialog?.matches('dialog.modal')) return;
  history.replaceState(null, '', location.pathname + location.search);
  const opener = document.querySelector(`[data-modal-open="${CSS.escape(id)}"]`);
  if (opener) opener.click();
  else dialog.showModal();
}
// After every module script, so a page's own setup of its dialogs is in place.
document.addEventListener('DOMContentLoaded', openFromHash);
addEventListener('hashchange', openFromHash);

for (const closer of document.querySelectorAll('[data-modal-close]')) {
  closer.addEventListener('click', () => closer.closest('dialog')?.close());
}

for (const dialog of document.querySelectorAll('dialog.modal')) {
  // A click that lands on the dialog element itself is a click on the
  // backdrop: the card inside stops its own.
  dialog.addEventListener('click', (event) => {
    if (event.target === dialog) dialog.close();
  });
}

/* --------------------------------------------------------------- explain */

// The browser opens and closes an explanation (it is a native popover); this
// only puts it under its "?", starting where the "?" starts and kept inside
// the window, or above the "?" when there is no room below.
for (const pop of document.querySelectorAll('.explain-pop')) {
  const button = document.querySelector(`[popovertarget="${CSS.escape(pop.id)}"]`);
  if (!button) continue;
  const place = () => {
    const at = button.getBoundingClientRect();
    const gap = 8, edge = 16;
    const rtl = getComputedStyle(button).direction === 'rtl';
    const left = rtl ? at.right - pop.offsetWidth : at.left;
    pop.style.left = Math.max(edge, Math.min(left, innerWidth - pop.offsetWidth - edge)) + 'px';
    const below = at.bottom + gap;
    pop.style.top = (below + pop.offsetHeight <= innerHeight - edge
      ? below : Math.max(edge, at.top - gap - pop.offsetHeight)) + 'px';
  };
  // Measured once it is open but before it is first painted, so it never
  // shows for a frame somewhere else.
  pop.addEventListener('beforetoggle', (event) => { if (event.newState === 'open') requestAnimationFrame(place); });
  // A scrolled page or a dragged dialog would leave it behind; close it.
  addEventListener('scroll', () => pop.hidePopover?.(), { capture: true, passive: true });
  addEventListener('resize', () => pop.hidePopover?.());
}

/* ------------------------------------------------------------------ copy */

// A button that copies a field: the station's link. The clipboard API only
// exists on https (and localhost); elsewhere the older command still works.
for (const button of document.querySelectorAll('[data-copy]')) {
  button.addEventListener('click', async () => {
    const field = document.getElementById(button.dataset.copy);
    if (!field) return;
    let copied = false;
    try { await navigator.clipboard.writeText(field.value); copied = true; } catch {
      field.select();
      copied = document.execCommand('copy');
    }
    if (copied) toast(button.dataset.copied || t('Copied.'));
  });
}

/* ---------------------------------------------------------- a new station */

// The address follows the name, in Latin letters, until the owner types one
// of their own. A name in another script leaves it for the owner to choose.
const stationName = document.querySelector('[data-station-name]');
const stationId = document.querySelector('[data-station-id]');
if (stationName && stationId) {
  const taken = new Set(JSON.parse(stationId.dataset.taken || '[]'));
  let typed = false;
  const slug = (text) => text.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
  const check = () => {
    const value = stationId.value;
    stationId.setCustomValidity(taken.has(value) ? stationId.dataset.takenMessage
      : value && stationId.validity.patternMismatch ? stationId.dataset.patternMessage : '');
  };
  stationName.addEventListener('input', () => {
    if (!typed) { stationId.value = slug(stationName.value); check(); }
  });
  stationId.addEventListener('input', () => {
    typed = stationId.value !== '';
    if (stationId.value !== stationId.value.toLowerCase()) stationId.value = stationId.value.toLowerCase();
    check();
  });
}

/* ---------------------------------------------------------------- confirm */

/**
 * Replaces window.confirm for destructive actions, so the panel asks in its
 * own voice rather than the browser's, and can say what will actually happen.
 *
 * Used by any form carrying data-confirm.
 */
let confirmDialog = null;

function askToConfirm({ title, detail, action }) {
  if (!confirmDialog) {
    confirmDialog = document.createElement('dialog');
    confirmDialog.className = 'modal';
    confirmDialog.innerHTML = `
      <div class="modal-card">
        <h2 data-role="title"></h2>
        <div class="modal-body"><p class="muted" data-role="detail"></p></div>
        <div class="modal-actions modal-actions-split">
          <button type="button" class="danger-button modal-danger" data-role="go"></button>
          <button type="button" data-role="cancel"></button>
        </div>
      </div>`;
    document.body.appendChild(confirmDialog);
    makeDraggable(confirmDialog);
    confirmDialog.addEventListener('click', (event) => {
      if (event.target === confirmDialog) confirmDialog.close();
    });
  }

  confirmDialog.querySelector('[data-role="title"]').textContent = title;
  const detailEl = confirmDialog.querySelector('[data-role="detail"]');
  detailEl.textContent = detail || '';
  detailEl.parentElement.hidden = !detail;
  confirmDialog.querySelector('[data-role="go"]').textContent = action || t('Confirm');
  confirmDialog.querySelector('[data-role="cancel"]').textContent = t('Cancel');

  return new Promise((resolve) => {
    const go = confirmDialog.querySelector('[data-role="go"]');
    const cancel = confirmDialog.querySelector('[data-role="cancel"]');
    const finish = (answer) => {
      go.removeEventListener('click', yes);
      cancel.removeEventListener('click', no);
      confirmDialog.close();
      resolve(answer);
    };
    const yes = () => finish(true);
    const no = () => finish(false);
    go.addEventListener('click', yes);
    cancel.addEventListener('click', no);
    confirmDialog.showModal();
    cancel.focus();
  });
}

for (const form of document.querySelectorAll('form[data-confirm]')) {
  form.addEventListener('submit', async (event) => {
    if (form.dataset.confirmed === 'yes') return;
    event.preventDefault();
    const ok = await askToConfirm({
      title: form.dataset.confirm,
      detail: form.dataset.confirmDetail,
      action: form.dataset.confirmAction,
    });
    if (ok) {
      form.dataset.confirmed = 'yes';
      form.submit();
    }
  });
}

/* ------------------------------------------------------------ phone menu */

// On a narrow screen the sidebar is a drawer behind the menu button, so the
// page itself starts at the top of the screen.
const navToggle = document.getElementById('nav-toggle');
const scrim = document.getElementById('nav-scrim');

function setNav(open) {
  document.body.classList.toggle('nav-open', open);
  navToggle?.setAttribute('aria-expanded', String(open));
  if (scrim) scrim.hidden = !open;
}

navToggle?.addEventListener('click', () => setNav(!document.body.classList.contains('nav-open')));
scrim?.addEventListener('click', () => setNav(false));
document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && document.body.classList.contains('nav-open')) setNav(false);
});
// A dialog opened from the drawer (New station) should not sit on top of it.
for (const opener of document.querySelectorAll('.sidebar [data-modal-open]')) {
  opener.addEventListener('click', () => setNav(false));
}
