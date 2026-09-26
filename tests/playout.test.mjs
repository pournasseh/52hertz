/** The optional PHP stream source must hear exactly what the browser hears. */

import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { resolve, DAY_MS } from '../engine/schedule.js';

const phpModules = execFileSync('php', ['-r', "echo json_encode(['mbstring' => extension_loaded('mbstring')]);"], { encoding: 'utf8' });
if (!JSON.parse(phpModules).mbstring) {
  console.error('test prerequisite missing: PHP mbstring');
  process.exit(2);
}

const here = dirname(fileURLToPath(import.meta.url));
const bridge = join(here, 'playout-bridge.php');
const baseDay = 20000;
const base = baseDay * DAY_MS;

const publication = {
  programmeVersion: 1,
  stationId: 'parity', revision: 1, enabled: true,
  epochMs: base - DAY_MS, dayStartMs: 0,
  shuffleSeed: 'server-parity-seed', algorithm: 'fnv1a-sfc32-fy-v1',
  station: { name: 'Parity' },
  tracks: {
    a: { id: 'a', title: 'A', durationMs: 7003, mediaUrl: 'a.wav' },
    b: { id: 'b', title: 'B', durationMs: 11009, mediaUrl: 'b.wav' },
    c: { id: 'c', title: 'C', durationMs: 13001, mediaUrl: 'c.wav' },
    ident: { id: 'ident', title: 'Ident', durationMs: 3011, mediaUrl: 'ident.wav' },
  },
  collections: { picks: { name: 'Picks', tracks: ['a', 'b', 'c'] } },
  programmes: {
    varied: {
      name: 'Varied', mode: 'shuffle',
      items: [
        { entryId: 'one', trackId: 'a' },
        { entryId: 'draw', collectionId: 'picks', repeat: 2 },
        { entryId: 'last', trackId: 'b' },
      ],
      events: [{ id: 'hourly-ident', trackId: 'ident', timeMs: 5000, repeat: 'hourly', timing: 'strict' }],
    },
    ordered: {
      name: 'Ordered', mode: 'ordered',
      items: [{ entryId: 'c', trackId: 'c' }, { entryId: 'b', trackId: 'b' }], events: [],
    },
  },
  playlists: { mix: { name: 'Mix', programmes: ['ordered', 'varied'] } },
  schedule: {
    plans: [{ startsOn: baseDay - 10, name: 'Main', days: ['playlist:mix', 'varied'] }],
    overrides: [[baseDay + 2, 'ordered']],
  },
};

function compact(position) {
  return {
    state: position.state ?? null,
    reason: position.reason ?? null,
    trackId: position.track?.id ?? null,
    sourceOffsetMs: position.sourceOffsetMs ?? null,
    slotStartMs: position.slotStartMs ?? null,
    slotEndMs: position.slotEndMs ?? null,
    nextBoundaryMs: position.nextBoundaryMs ?? null,
    programmeId: position.programmeId ?? null,
    day: position.day ?? null,
    pass: position.pass ?? null,
    passIndex: position.passIndex ?? null,
    eventId: position.event?.id ?? null,
    cut: position.cut ?? null,
  };
}

function phpResolve(pub, times) {
  return JSON.parse(execFileSync('php', [bridge], {
    input: JSON.stringify({ publication: pub, times }), encoding: 'utf8', maxBuffer: 4 * 1024 * 1024,
  }));
}

test('PHP stream playout matches JS for shuffle, collections, playlists, events and overrides', () => {
  const times = [];
  for (let day = 0; day < 4; day++) {
    for (let i = 0; i < 75; i++) times.push(base + day * DAY_MS + ((i * 104729) % DAY_MS));
  }
  const expected = times.map((time) => compact(resolve(publication, time)));
  assert.deepEqual(phpResolve(publication, times), expected);
});

test('PHP stream playout matches JS through a live handover', () => {
  const changed = structuredClone(publication);
  changed.revision = 2;
  changed.programmes.varied.items.reverse();
  changed.handover = { requestedAtMs: base + 1234567, previous: publication };
  const times = [base + 1234500, base + 1234567, base + 1240000, base + 1300000, base + 7200000];
  const expected = times.map((time) => compact(resolve(changed, time)));
  assert.deepEqual(phpResolve(changed, times), expected);
});

