/** Plan editor and date overrides for the programme-day scheduler. */

import {
  calendar, formatDate, formatMonth, formatNumber, formatTime,
  instantOfJdn, jdnOfInstant, weekdayNames, weekdayOfJdn,
} from './dates.js';
import { t } from './i18n.js';

const DAY = 86400000;
const startOf = (day, startUtc) => day * DAY + startUtc;
const dayOfDate = (jdn, startUtc) => Math.ceil((instantOfJdn(jdn) - startUtc) / DAY);

for (const element of document.querySelectorAll('[data-local-date]')) {
  element.textContent = formatDate(Number(element.dataset.localDate));
}
for (const element of document.querySelectorAll('[data-local-span]')) {
  const start = Number(element.dataset.localSpan);
  element.textContent = t('{from} to {to}', {
    from: formatTime(start),
    to: `${formatDate(start + DAY, { weekday: 'short', year: null })} ${formatTime(start + DAY)}`,
  });
}

function shiftMonth({ y, m }, by) {
  const index = y * 12 + m - 1 + by;
  return { y: Math.floor(index / 12), m: ((index % 12) + 12) % 12 + 1 };
}

/** Calendar-aware picker whose value is a programme-day number. */
function datePicker(root, { startUtc, minDay, initialDay, taken = new Set(), original = null, onSelect }) {
  const state = { selected: initialDay, view: null };
  const date = calendar.fromJdn(jdnOfInstant(startOf(initialDay, startUtc)));
  state.view = { y: date.y, m: date.m };

  function select(day) {
    state.selected = day;
    onSelect(day);
    draw();
  }

  function draw() {
    const { y, m } = state.view;
    root.innerHTML = '';
    const head = document.createElement('div');
    head.className = 'picker-head';
    const previous = document.createElement('button');
    previous.type = 'button'; previous.className = 'picker-nav';
    previous.setAttribute('aria-label', t('Previous month'));
    const rtl = document.documentElement.dir === 'rtl';
    previous.textContent = rtl ? '›' : '‹';
    const next = previous.cloneNode();
    next.setAttribute('aria-label', t('Next month'));
    next.textContent = rtl ? '‹' : '›';
    const title = document.createElement('strong');
    title.textContent = formatMonth(y, m);
    head.append(previous, title, next);

    const earliest = calendar.fromJdn(jdnOfInstant(startOf(minDay, startUtc)));
    previous.disabled = y < earliest.y || (y === earliest.y && m <= earliest.m);
    previous.addEventListener('click', () => { state.view = shiftMonth(state.view, -1); draw(); });
    next.addEventListener('click', () => { state.view = shiftMonth(state.view, 1); draw(); });

    const grid = document.createElement('div');
    grid.className = 'picker-grid'; grid.setAttribute('role', 'grid');
    for (const name of weekdayNames()) {
      const cell = document.createElement('span'); cell.className = 'picker-weekday'; cell.textContent = name; grid.append(cell);
    }
    const firstJdn = calendar.toJdn({ y, m, d: 1 });
    const lead = (weekdayOfJdn(firstJdn) - calendar.weekStart + 7) % 7;
    for (let i = 0; i < lead; i++) grid.append(document.createElement('span'));
    for (let d = 1; d <= calendar.monthLength(y, m); d++) {
      const day = dayOfDate(firstJdn + d - 1, startUtc);
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'picker-day'; button.textContent = formatNumber(d);
      const occupied = taken.has(day) && day !== original;
      button.disabled = day < minDay || occupied;
      button.classList.toggle('is-taken', occupied);
      button.classList.toggle('is-selected', day === state.selected);
      if (occupied) button.title = t('Already scheduled');
      button.addEventListener('click', () => select(day));
      grid.append(button);
    }
    root.append(head, grid);
  }

  select(initialDay);
}

/* -------------------------------------------------------------- plan editor */

const planForm = document.querySelector('[data-plan-form]');
if (planForm) {
  const startUtc = Number(planForm.dataset.stationStartUtc);
  const today = Number(planForm.dataset.today);
  const hasActive = planForm.dataset.hasActive === '1';
  const seedChoices = JSON.parse(planForm.dataset.seedChoices || '[]');
  const allProgrammes = JSON.parse(planForm.dataset.allProgrammes || '[]');
  const days = planForm.querySelector('[data-plan-days]');
  const template = planForm.querySelector('[data-plan-day-template]');
  const startsOn = planForm.querySelector('[name="starts_on"]');
  const when = planForm.querySelector('[data-plan-when]');

  function renumber() {
    const rows = [...days.querySelectorAll('.cycle-day')];
    rows.forEach((row, index) => {
      row.querySelector('.cycle-number').textContent = t('Day {n}', { n: index + 1 });
      row.querySelector('[data-cycle-up]').disabled = index === 0;
      row.querySelector('[data-cycle-down]').disabled = index === rows.length - 1;
      row.querySelector('[data-cycle-remove]').disabled = rows.length === 1;
    });
  }

  function addDay(choice = null) {
    const row = template.content.firstElementChild.cloneNode(true);
    const select = row.querySelector('select');
    if (choice !== null && [...select.options].some(option => option.value === String(choice) && !option.disabled)) {
      select.value = String(choice);
    } else {
      const first = [...select.options].find(option => !option.disabled);
      if (first) select.value = first.value;
    }
    days.append(row); renumber();
  }

  function setDays(choices) {
    days.innerHTML = '';
    for (const choice of choices.length ? choices : [null]) addDay(choice);
    renumber();
  }

  function resize(size) {
    const choices = [...days.querySelectorAll('select')].map(select => select.value);
    const fallback = choices.at(-1) ?? seedChoices[0] ?? null;
    while (choices.length < size) choices.push(fallback);
    setDays(choices.slice(0, size));
  }

  days.addEventListener('click', (event) => {
    const row = event.target.closest('.cycle-day');
    if (!row) return;
    if (event.target.closest('[data-cycle-remove]') && days.children.length > 1) row.remove();
    if (event.target.closest('[data-cycle-up]') && row.previousElementSibling) days.insertBefore(row, row.previousElementSibling);
    if (event.target.closest('[data-cycle-down]') && row.nextElementSibling) days.insertBefore(row.nextElementSibling, row);
    renumber();
  });
  planForm.querySelector('[data-cycle-add]').addEventListener('click', () => addDay(days.querySelector('.cycle-day:last-child select')?.value));
  for (const button of planForm.querySelectorAll('[data-cycle-size]')) button.addEventListener('click', () => resize(Number(button.dataset.cycleSize)));
  planForm.querySelector('[data-cycle-all]').addEventListener('click', () => setDays(allProgrammes));

  function openPlan(plan = null) {
    const editing = Boolean(plan);
    const versioning = Boolean(plan?.version);
    const minDay = hasActive ? today + 1 : today;
    const selectedDay = editing ? plan.startsOn : minDay;
    planForm.querySelector('[name="plan_id"]').value = editing && !versioning ? String(plan.id) : '';
    planForm.querySelector('[name="name"]').value = editing ? plan.name : '';
    planForm.querySelector('[data-plan-title]').textContent = versioning
      ? t('Edit active plan')
      : editing ? t('Edit future plan') : t(hasActive ? 'Schedule a playback change' : 'Create playback plan');
    planForm.querySelector('[data-plan-submit]').textContent = versioning
      ? t('Save from next programme day')
      : editing ? t('Save plan') : t('Schedule plan');
    planForm.querySelector('[data-plan-version-note]').hidden = !versioning;
    setDays(editing ? plan.choices : seedChoices);
    const picker = planForm.querySelector('[data-plan-date-picker]');
    picker.hidden = versioning;
    if (versioning) {
      startsOn.value = String(selectedDay);
      const start = startOf(selectedDay, startUtc);
      when.textContent = `${formatDate(start)} \u00b7 ${formatTime(start)}`;
      return;
    }
    datePicker(picker, {
      startUtc, minDay, initialDay: selectedDay,
      onSelect(day) {
        startsOn.value = String(day);
        const start = startOf(day, startUtc);
        when.textContent = `${formatDate(start)} \u00b7 ${formatTime(start)}`;
      },
    });
  }

  for (const button of document.querySelectorAll('[data-plan-new]')) button.addEventListener('click', () => openPlan());
  for (const button of document.querySelectorAll('[data-plan-edit]')) button.addEventListener('click', () => openPlan(JSON.parse(button.dataset.planEdit)));
  openPlan();
}

/* ----------------------------------------------------------- date overrides */

const overrideForm = document.querySelector('[data-override-form]');
if (overrideForm) {
  const startUtc = Number(overrideForm.dataset.stationStartUtc);
  const today = Number(overrideForm.dataset.today);
  const taken = new Set(JSON.parse(overrideForm.dataset.taken || '[]'));
  const dayInput = overrideForm.querySelector('[name="day"]');
  const originalInput = overrideForm.querySelector('[name="original_day"]');
  const when = overrideForm.querySelector('[data-override-when]');
  const submit = overrideForm.querySelector('[data-override-submit]');

  function openOverride(day = null, choice = null) {
    const editing = day !== null;
    const selected = editing ? day : today + 1;
    originalInput.value = editing ? String(day) : '';
    if (choice) overrideForm.querySelector('[name="choice"]').value = choice;
    overrideForm.querySelector('[data-override-title]').textContent = editing ? t('Edit override') : t('Add override');
    submit.textContent = editing ? t('Save override') : t('Add override');
    datePicker(overrideForm.querySelector('[data-override-date-picker]'), {
      startUtc, minDay: today + 1, initialDay: selected, taken, original: day,
      onSelect(value) {
        dayInput.value = String(value); submit.disabled = false;
        const start = startOf(value, startUtc);
        when.textContent = t('{from} to {to}', {
          from: `${formatDate(start)} ${formatTime(start)}`,
          to: `${formatDate(start + DAY, { weekday: 'short', year: null })} ${formatTime(start + DAY)}`,
        });
      },
    });
  }

  document.querySelector('[data-override-new]')?.addEventListener('click', () => openOverride());
  for (const button of document.querySelectorAll('[data-override-edit]')) {
    button.addEventListener('click', () => openOverride(Number(button.dataset.overrideEdit), button.dataset.choice));
  }
  openOverride();
}
