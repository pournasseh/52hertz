<?php
/**
 * What plays when: the Schedule tab (playback plans, date overrides, the next
 * two weeks) and the verbs its dialogs post to.
 */

declare(strict_types=1);

/* ------------------------------------------------------------- the tab */

function schedule_cycle_label(int $days): string
{
    return match ($days) {
        1 => t('Every day'),
        7 => t('7-day cycle'),
        default => t('{n}-day cycle', ['n' => $days]),
    };
}

/** One honest preview row per complete programme day. */
function schedule_preview_days(array $station, array $schedule, int $firstDay, int $count = 14): array
{
    $rows = [];
    for ($day = $firstDay; $day < $firstDay + $count; $day++) {
        $rows[] = [
            'day' => $day,
            'start' => programme_day_start($station, $day),
            'programme' => programme_for_instant((string) $station['id'], programme_day_start($station, $day), $schedule),
            'override' => isset($schedule['overrides'][$day]),
            'plan' => schedule_plan_for_day($schedule, $day),
        ];
    }
    return $rows;
}

/**
 * What the schedule offers wherever it asks what to play: every playlist
 * first, as "Random from …", then every programme.
 */
function schedule_choice_options(array $playlists, array $lists, ?string $selected = null): void
{
    foreach ($playlists as $playlist) {
        $value = 'playlist:' . (int) $playlist['id']; ?>
      <option value="<?= e($value) ?>" <?= !$playlist['_ready'] ? 'disabled' : '' ?> <?= $selected === $value ? 'selected' : '' ?>>
        <?= h('Random from {name}', ['name' => $playlist['name']]) ?><?= !$playlist['_ready'] ? ' ' . h('(nothing to draw)') : '' ?>
      </option>
    <?php }
    foreach ($lists as $list) {
        $value = (string) (int) $list['id']; ?>
      <option value="<?= e($value) ?>" <?= !$list['_ready'] ? 'disabled' : '' ?> <?= $selected === $value ? 'selected' : '' ?>>
        <?= e($list['name']) ?><?= !$list['_ready'] ? ' ' . h('(not ready)') : '' ?>
      </option>
    <?php }
}

/** The plan-based scheduler: one repeating list of complete programme days. */
function station_schedule(array $station): void
{
    $id = (string) $station['id'];
    $lists = programmes($id);
    foreach ($lists as &$list) {
        $list['_ready'] = programme_is_ready((int) $list['id']);
    }
    unset($list);
    $playlists = playlists($id);
    foreach ($playlists as &$playlist) {
        $playlist['_ready'] = (bool) playlist_pool((int) $playlist['id']);
    }
    unset($playlist);
    $readyLists = array_values(array_filter($lists, static fn (array $row): bool => (bool) $row['_ready']));
    $hasReady = (bool) $readyLists || (bool) array_filter($playlists, static fn (array $row): bool => (bool) $row['_ready']);
    $schedule = schedule($id);
    $plans = array_values($schedule['plans']);
    $today = programme_day_of($station, now_ms());
    $active = schedule_plan_for_day($schedule, $today);
    $overrides = array_filter($schedule['overrides'], static fn (int $day): bool => $day > $today, ARRAY_FILTER_USE_KEY);
    $onAir = programme_for_instant($id, now_ms(), $schedule);
    $preview = schedule_preview_days($station, $schedule, $today);
    // A change made to the active plan becomes a new version tomorrow. Rotate
    // its cycle to tomorrow's natural position so an unchanged save is a true
    // no-op on the broadcast sequence.
    $seedChoices = $active ? schedule_plan_choices_from($active, $today + 1) : [];
    if (!$seedChoices && $readyLists) {
        $seedChoices[] = (string) (int) $readyLists[0]['id'];
    }
    $allProgrammeChoices = array_map(static fn (array $row): string => (string) (int) $row['id'], $readyLists);

    render([
        'title' => $station['name'], 'subtitle' => station_subtitle($station),
        'station' => $station, 'tab' => 'schedule', 'script' => ['schedule-page.js'],
        'actions' => '<button type="button" data-modal-open="schedule-preview">' . h('Preview next 2 weeks') . '</button> '
            . '<button type="button" class="primary" data-plan-new data-modal-open="plan-editor"' . ($hasReady ? '' : ' disabled') . '>'
            . icon('plus-lg') . ' ' . h($active ? 'Change playback' : 'Set playback') . '</button>',
    ], function () use ($id, $station, $lists, $playlists, $hasReady, $plans, $overrides, $today, $active, $onAir, $preview, $seedChoices, $allProgrammeChoices) { ?>
      <?php if (!$hasReady): ?><p class="flash flash-warn"><?= h('Add tracks to a programme before setting playback.') ?></p><?php endif; ?>

      <section class="card schedule-now">
        <div><span class="eyebrow"><?= h('On air now') ?></span>
          <h2><?= $onAir ? e($onAir['programme_name']) : h('Nothing scheduled') ?></h2>
          <?php if ($onAir['playlist_name'] ?? null): ?><p class="note"><?= h('Drawn today from {name}', ['name' => $onAir['playlist_name']]) ?></p><?php endif; ?>
          <?php if (!$onAir): ?><p class="note"><?= h('Create a playback plan to put the station on air.') ?></p><?php endif; ?>
        </div>
        <?php if ($active): ?><div class="schedule-now-plan"><span class="muted small"><?= h('Active plan') ?></span>
          <strong><?= e($active['name'] !== '' ? $active['name'] : schedule_cycle_label(count($active['days']))) ?></strong>
          <span class="muted small"><?= e(schedule_cycle_label(count($active['days']))) ?></span></div><?php endif; ?>
      </section>

      <section class="card">
        <div class="card-head"><div><h2><?= h('Playback plans') ?></h2><p class="note"><?= h('A plan is a repeating list of programme days. One day means always; seven days makes a week; any length is valid.') ?></p></div>
          <button type="button" class="quiet" data-plan-new data-modal-open="plan-editor" <?= $hasReady ? '' : 'disabled' ?>>+ <?= h($active ? 'Schedule a change' : 'Create plan') ?></button></div>
        <?php if (!$plans): ?><p class="empty"><?= h('No playback plan yet.') ?></p><?php else: ?>
          <table class="grid-table schedule-table fits-narrow"><thead><tr><th><?= h('Starts') ?></th><th><?= h('Plan') ?></th><th><?= h('Cycle') ?></th><th></th></tr></thead><tbody>
          <?php foreach (array_reverse($plans) as $plan):
              $startsOn = (int) $plan['starts_on']; $isActive = (int) ($active['id'] ?? 0) === (int) $plan['id']; $isFuture = $startsOn > $today;
              $planData = json_encode([
                  'id' => (int) $plan['id'], 'name' => (string) $plan['name'],
                  'startsOn' => $isActive ? $today + 1 : $startsOn,
                  'version' => $isActive,
                  'choices' => $isActive
                      ? schedule_plan_choices_from($plan, $today + 1)
                      : array_map(static fn (array $day): string => (string) $day['choice'], $plan['days']),
              ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
            <tr><td><span class="strong" data-local-date="<?= programme_day_start($station, $startsOn) ?>"></span>
              <?php if ($isActive): ?> <span class="pill pill-live"><?= h('active') ?></span><?php elseif (!$isFuture): ?> <span class="muted small"><?= h('past') ?></span><?php endif; ?></td>
              <td><?= $plan['name'] !== '' ? e($plan['name']) : '<span class="muted">' . h('Untitled') . '</span>' ?></td><td><?= e(schedule_cycle_label(count($plan['days']))) ?></td>
              <td class="num"><?php if ($isFuture): ?><button type="button" class="quiet" data-plan-edit="<?= e($planData) ?>" data-modal-open="plan-editor"><?= h('Edit') ?></button> <span class="muted">&middot;</span>
                <form method="post" action="<?= e(u('schedule-plan-delete', ['id' => $id])) ?>" class="inline" data-confirm="<?= h('Delete this future plan?') ?>"><?= csrf_field() ?><input type="hidden" name="plan_id" value="<?= (int) $plan['id'] ?>"><button type="submit" class="quiet danger"><?= h('Remove') ?></button></form>
                <?php elseif ($isActive): ?><button type="button" class="quiet" data-plan-edit="<?= e($planData) ?>" data-modal-open="plan-editor"><?= h('Edit') ?></button>
                <?php else: ?><span class="muted">&mdash;</span><?php endif; ?></td></tr>
          <?php endforeach; ?></tbody></table>
        <?php endif; ?>
      </section>

      <section class="card">
        <div class="card-head"><div><h2><?= h('Date overrides') ?> <?= explain(t('A date override'), t('An override puts the programme you choose on one date, instead of what the plan would play. It wins over everything else, including a playlist’s draw. For several days, add one per day. Afterwards the plan carries on as if the day had not been replaced, and an override never repeats, not even the next year.')) ?></h2><p class="note"><?= h('Replace one future programme day. The plan continues at its normal position the following day.') ?></p></div>
          <button type="button" class="quiet" data-override-new data-modal-open="override-editor" <?= $hasReady ? '' : 'disabled' ?>>+ <?= h('Add override') ?></button></div>
        <?php if (!$overrides): ?><p class="empty"><?= h('No upcoming overrides.') ?></p><?php else: ?>
          <table class="grid-table schedule-table fits-narrow"><thead><tr><th><?= h('Day') ?></th><th><?= h('Programme') ?></th><th></th></tr></thead><tbody>
          <?php foreach ($overrides as $day => $entry): ?><tr><td><span class="strong" data-local-date="<?= programme_day_start($station, (int) $day) ?>"></span></td><td><?= e($entry['label']) ?></td>
            <td class="num"><button type="button" class="quiet" data-override-edit="<?= (int) $day ?>" data-choice="<?= e($entry['choice']) ?>" data-modal-open="override-editor"><?= h('Edit') ?></button> <span class="muted">&middot;</span>
              <form method="post" action="<?= e(u('schedule-override-delete', ['id' => $id])) ?>" class="inline"><?= csrf_field() ?><input type="hidden" name="day" value="<?= (int) $day ?>"><button type="submit" class="quiet danger"><?= h('Remove') ?></button></form></td></tr><?php endforeach; ?>
          </tbody></table>
        <?php endif; ?>
      </section>

      <dialog class="modal modal-wide" id="plan-editor"><form method="post" action="<?= e(u('schedule-plan', ['id' => $id])) ?>" class="modal-card" data-plan-form
        data-station-start-utc="<?= (int) $station['day_start_ms'] ?>" data-today="<?= $today ?>" data-has-active="<?= $active ? '1' : '0' ?>"
        data-seed-choices="<?= e(json_encode($seedChoices)) ?>" data-all-programmes="<?= e(json_encode($allProgrammeChoices)) ?>">
        <?= csrf_field() ?><h2 data-plan-title><?= h($active ? 'Schedule a playback change' : 'Create playback plan') ?></h2><input type="hidden" name="plan_id"><input type="hidden" name="starts_on">
        <div class="modal-body">
          <p class="note" data-plan-version-note hidden><?= h('Today stays unchanged. Saving creates a new version from the next programme day, while this plan remains in history.') ?></p>
          <label><?= h('Name (optional)') ?><input name="name" maxlength="160" placeholder="<?= h('Main rotation') ?>"></label>
          <div><span class="field-label"><?= h('Starts on') ?></span><div class="date-picker" data-plan-date-picker></div><p class="special-when" data-plan-when></p></div>
          <div class="cycle-head"><div><span class="field-label"><?= h('Repeating cycle') ?></span><p class="hint"><?= h('Each row is one complete 24-hour programme day.') ?></p></div><div class="cycle-presets">
            <button type="button" class="quiet" data-cycle-size="1"><?= h('1 day') ?></button><button type="button" class="quiet" data-cycle-size="7"><?= h('7 days') ?></button><button type="button" class="quiet" data-cycle-all><?= h('All programmes in order') ?></button></div></div>
          <div class="cycle-days" data-plan-days></div><button type="button" class="quiet cycle-add" data-cycle-add>+ <?= h('Add day') ?></button>
          <template data-plan-day-template><div class="cycle-day"><span class="cycle-number"></span><select name="choices[]" required><?php schedule_choice_options($playlists, $lists); ?></select><button type="button" class="quiet" data-cycle-up aria-label="<?= h('Move up') ?>">&uarr;</button><button type="button" class="quiet" data-cycle-down aria-label="<?= h('Move down') ?>">&darr;</button><button type="button" class="quiet danger" data-cycle-remove aria-label="<?= h('Remove') ?>">&times;</button></div></template>
        </div>
        <div class="modal-actions"><button type="button" data-modal-close><?= h('Cancel') ?></button><button type="submit" class="primary" data-plan-submit <?= $hasReady ? '' : 'disabled' ?>><?= h('Save plan') ?></button></div>
      </form></dialog>

      <dialog class="modal" id="override-editor"><form method="post" action="<?= e(u('schedule-override', ['id' => $id])) ?>" class="modal-card" data-override-form
        data-station-start-utc="<?= (int) $station['day_start_ms'] ?>" data-today="<?= $today ?>" data-taken="<?= e(json_encode(array_map('intval', array_keys($overrides)))) ?>">
        <?= csrf_field() ?><h2 data-override-title><?= h('Add override') ?></h2>
        <div class="modal-body">
          <div class="date-picker" data-override-date-picker></div><p class="special-when" data-override-when></p>
          <label><?= h('Programme') ?><select name="choice" required><?php schedule_choice_options($playlists, $lists); ?></select></label><input type="hidden" name="day"><input type="hidden" name="original_day">
        </div>
        <div class="modal-actions"><button type="button" data-modal-close><?= h('Cancel') ?></button><button type="submit" class="primary" data-override-submit disabled><?= h('Add override') ?></button></div>
      </form></dialog>

      <dialog class="modal modal-wide" id="schedule-preview"><div class="modal-card"><h2><?= h('Next two weeks') ?></h2>
        <div class="modal-body">
          <p class="muted"><?= h('One row is one complete programme day, shown in your local time.') ?></p>
          <table class="grid-table"><thead><tr><th><?= h('Day') ?></th><th><?= h('Programme') ?></th><th><?= h('Source') ?></th></tr></thead><tbody>
          <?php foreach ($preview as $row): ?><tr><td><span data-local-date="<?= (int) $row['start'] ?>"></span><br><span class="muted small" data-local-span="<?= (int) $row['start'] ?>"></span></td>
            <td><?= $row['programme'] ? e($row['programme']['programme_name']) : '<span class="muted">' . h('Nothing — off air') . '</span>' ?><?php if ($row['programme']['playlist_name'] ?? null): ?><br><span class="muted small"><?= h('drawn from {name}', ['name' => $row['programme']['playlist_name']]) ?></span><?php endif; ?></td>
            <td><?php if ($row['override']): ?><span class="pill pill-special"><?= h('override') ?></span><?php elseif ($row['plan']): ?><?= e(($row['plan']['name'] ?? '') !== '' ? $row['plan']['name'] : schedule_cycle_label(count($row['plan']['days']))) ?><?php else: ?><span class="muted">&mdash;</span><?php endif; ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
        <div class="modal-actions"><button type="button" data-modal-close><?= h('Close') ?></button></div>
      </div></dialog>
    <?php });
}

/* ----------------------------------------------------------- the verbs */

function page_schedule_plan(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id, 'tab' => 'schedule']));
    }
    require_csrf();
    $station = route_station($id);
    $back = u('station', ['id' => $id, 'tab' => 'schedule']);
    $saved = schedule($id);
    $today = programme_day_of($station, now_ms());
    $existingId = (int) ($_POST['plan_id'] ?? 0);
    $existing = $existingId > 0 ? ($saved['plans'][$existingId] ?? null) : null;
    if ($existingId > 0 && $existing === null) {
        after_post($back, t('That plan is not in this station.'), 'bad');
    }
    if ($existing !== null && (int) $existing['starts_on'] <= $today) {
        after_post($back, t('An active or past plan cannot be changed directly. Save it as a new version instead.'), 'bad');
    }
    $startsOn = filter_var($_POST['starts_on'] ?? '', FILTER_VALIDATE_INT);
    $hasActive = schedule_plan_for_day($saved, $today) !== null;
    if ($startsOn === false || $startsOn < ($hasActive ? $today + 1 : $today)) {
        after_post($back, $hasActive ? t('A playback change must start on a future programme day.') : t('Choose when the plan starts.'), 'bad');
    }
    foreach ($saved['plans'] as $plan) {
        if ((int) $plan['starts_on'] === $startsOn && (int) $plan['id'] !== $existingId) {
            after_post($back, t('A plan already starts on that day. Edit it instead.'), 'bad');
        }
    }
    $choices = array_values((array) ($_POST['choices'] ?? []));
    if (!$choices || count($choices) > 1000) {
        after_post($back, t('A plan needs at least one programme day.'), 'bad');
    }
    $name = trim((string) ($_POST['name'] ?? ''));
    if (mb_strlen($name) > 160) {
        after_post($back, t('The plan name is too long.'), 'bad');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $planId = save_schedule_plan($id, $name, $startsOn, $choices);
        if ($planId === null) {
            $pdo->rollBack();
            after_post($back, t('Every day must name a ready programme or playlist from this station.'), 'bad');
        }
        if ($existing !== null && (int) $existing['id'] !== $planId) {
            delete_schedule_plan($id, (int) $existing['id']);
        }
        $pdo->commit();
    } catch (Throwable $err) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $err;
    }
    after_station_change($id, $back, $existing ? t('Playback plan saved.') : t('Playback plan scheduled.'));
}

function page_schedule_plan_delete(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id, 'tab' => 'schedule']));
    }
    require_csrf();
    $station = route_station($id);
    $back = u('station', ['id' => $id, 'tab' => 'schedule']);
    $planId = (int) ($_POST['plan_id'] ?? 0);
    $plan = schedule($id)['plans'][$planId] ?? null;
    if ($plan === null || (int) $plan['starts_on'] <= programme_day_of($station, now_ms())) {
        after_post($back, t('Only a future plan can be removed.'), 'bad');
    }
    delete_schedule_plan($id, $planId);
    after_station_change($id, $back, t('Future plan removed.'));
}

/** Add or change one future programme-day override. */
function page_schedule_override(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id, 'tab' => 'schedule']));
    }
    require_csrf();
    $station = route_station($id);
    $back = u('station', ['id' => $id, 'tab' => 'schedule']);

    $day = filter_var($_POST['day'] ?? '', FILTER_VALIDATE_INT);
    $original = filter_var($_POST['original_day'] ?? '', FILTER_VALIDATE_INT);
    $choice = read_schedule_choice($id, (string) ($_POST['choice'] ?? ''));
    $today = programme_day_of($station, now_ms());

    if ($day === false || $day <= $today) {
        after_post($back, t('Choose a future programme day.'), 'bad');
    }
    if ($choice === null) {
        after_post($back, t('Choose a ready programme or playlist from this station.'), 'bad');
    }
    $overrides = schedule($id)['overrides'];
    if (isset($overrides[$day]) && $original !== $day) {
        after_post($back, t('That day already has an override.'), 'bad');
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($original !== false && $original !== $day) {
            clear_schedule_override($id, $original);
        }
        set_schedule_override($id, $day, $choice);
        $pdo->commit();
    } catch (Throwable $err) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $err;
    }
    after_station_change($id, $back, $original === false ? t('Override added.') : t('Override saved.'));
}

function page_schedule_override_delete(string $id): void
{
    if (!is_post()) {
        redirect(u('station', ['id' => $id, 'tab' => 'schedule']));
    }
    require_csrf();
    route_station($id);
    clear_schedule_override($id, (int) ($_POST['day'] ?? 0));
    after_station_change($id, u('station', ['id' => $id, 'tab' => 'schedule']), t('Override removed.'));
}
