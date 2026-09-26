-- Panel storage. Drafts live here; nothing in this database is ever served.
-- The public files are written by a deliberate Publish action.
--
-- Everything time-related is UTC. A day is exactly 86,400,000 ms, always, so
-- there is no timezone database and no daylight-saving arithmetic anywhere in
-- this system. The panel shows the operator their own local clock; what it
-- stores and what it publishes are plain UTC numbers.

PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS settings (
  key         TEXT PRIMARY KEY,
  value       TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS stations (
  id           TEXT PRIMARY KEY,          -- slug, also the publication folder
  name         TEXT NOT NULL,
  tagline      TEXT NOT NULL DEFAULT '',
  accent       TEXT NOT NULL DEFAULT '#6a7f8c',
  colophon     TEXT NOT NULL DEFAULT '',
  logo_url     TEXT NOT NULL DEFAULT '',   -- stable station identity: app icon and favicon
  art_url      TEXT NOT NULL DEFAULT '',   -- cover for tracks that have none of their own
  home_url     TEXT NOT NULL DEFAULT '',
  enabled      INTEGER NOT NULL DEFAULT 1,   -- 0 = published, but off air
  day_start_ms INTEGER NOT NULL DEFAULT 0,   -- ms after 00:00 UTC: when a day begins
  player_language TEXT NOT NULL DEFAULT 'en', -- the language its player speaks to listeners
  player_seen  INTEGER NOT NULL DEFAULT 0,   -- 1 once the owner has opened its Player tab
  revision     INTEGER NOT NULL DEFAULT 0,   -- latest broadcast snapshot
  published_at INTEGER,
  created_at   INTEGER NOT NULL,
  updated_at   INTEGER NOT NULL
);

-- The library: everything a station has. A track knows nothing about being on
-- air — that is a programme's business, and a track can be in several.
CREATE TABLE IF NOT EXISTS tracks (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id   TEXT NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
  item_id      TEXT NOT NULL,             -- stable id carried into broadcast snapshots
  title        TEXT NOT NULL DEFAULT '',
  credit       TEXT NOT NULL DEFAULT '',
  description  TEXT NOT NULL DEFAULT '',   -- a few lines about the piece
  media_url    TEXT NOT NULL DEFAULT '',
  page_url     TEXT NOT NULL DEFAULT '',
  art_url      TEXT NOT NULL DEFAULT '',
  duration_ms  INTEGER NOT NULL DEFAULT 0,
  tags         TEXT NOT NULL DEFAULT '',   -- comma-separated, lowercased, sorted
  measured_at  INTEGER,
  UNIQUE (station_id, item_id)
);

CREATE INDEX IF NOT EXISTS tracks_by_station ON tracks (station_id);

-- A collection is a reusable pool of tracks. A programme item or timed event
-- may draw one playable member from it; tracks themselves remain independent
-- library records and may belong to any number of collections.
CREATE TABLE IF NOT EXISTS collections (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id   TEXT NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
  name         TEXT NOT NULL,
  created_at   INTEGER NOT NULL,
  updated_at   INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS collections_by_station ON collections (station_id);

CREATE TABLE IF NOT EXISTS collection_tracks (
  collection_id INTEGER NOT NULL REFERENCES collections(id) ON DELETE CASCADE,
  track_id      INTEGER NOT NULL REFERENCES tracks(id) ON DELETE CASCADE,
  PRIMARY KEY (collection_id, track_id)
);

-- A programme is the complete content plan for one 24-hour programme day,
-- beginning at the station's day_start_ms. Its content loops when shorter than
-- 24 hours and is cut at 24 hours when longer. Content length never selects or
-- advances to another programme; the schedule chooses one programme per day.
CREATE TABLE IF NOT EXISTS programmes (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id   TEXT NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
  name         TEXT NOT NULL,
  mode         TEXT NOT NULL DEFAULT 'shuffle',   -- 'shuffle' | 'ordered'
  events       TEXT NOT NULL DEFAULT '[]',
  art_url      TEXT NOT NULL DEFAULT '',          -- the programme's own cover, if it has one
  created_at   INTEGER NOT NULL,
  updated_at   INTEGER NOT NULL
);

CREATE INDEX IF NOT EXISTS programmes_by_station ON programmes (station_id);

CREATE TABLE IF NOT EXISTS programme_items (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  programme_id  INTEGER NOT NULL REFERENCES programmes(id) ON DELETE CASCADE,
  track_id     INTEGER REFERENCES tracks(id) ON DELETE CASCADE,
  collection_id INTEGER REFERENCES collections(id) ON DELETE CASCADE,
  position     INTEGER NOT NULL DEFAULT 0,
  repeat_count INTEGER NOT NULL DEFAULT 1,
  uid          TEXT NOT NULL DEFAULT '',
  CHECK ((track_id IS NULL) <> (collection_id IS NULL))
);

CREATE INDEX IF NOT EXISTS programme_items_by_programme ON programme_items (programme_id, position);

-- A playlist is a set of programmes. Wherever the schedule can name a
-- programme it can name a playlist instead, and each day then plays one of
-- its programmes, drawn at random.
CREATE TABLE IF NOT EXISTS playlists (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id   TEXT NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
  name         TEXT NOT NULL,
  created_at   INTEGER NOT NULL,
  updated_at   INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS playlist_programmes (
  playlist_id  INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
  programme_id INTEGER NOT NULL REFERENCES programmes(id) ON DELETE CASCADE,
  PRIMARY KEY (playlist_id, programme_id)
);

-- A plan is an arbitrary repeating cycle of programme days. A one-day cycle
-- is "always play this"; a seven-day cycle is a weekly plan; a seventy-day
-- cycle plays seventy programme days in order. The newest plan whose start day
-- has arrived owns the day. Editing content never shifts the calendar.
CREATE TABLE IF NOT EXISTS schedule_plans (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id   TEXT NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
  name         TEXT NOT NULL DEFAULT '',
  starts_on    INTEGER NOT NULL,           -- programme day number
  created_at   INTEGER NOT NULL,
  updated_at   INTEGER NOT NULL,
  UNIQUE (station_id, starts_on)
);

CREATE INDEX IF NOT EXISTS schedule_plans_by_station
  ON schedule_plans (station_id, starts_on);

-- One choice for each position in a plan's cycle: exactly one programme or
-- playlist. Positions are contiguous and zero-based when written by the model.
CREATE TABLE IF NOT EXISTS schedule_plan_days (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  plan_id      INTEGER NOT NULL REFERENCES schedule_plans(id) ON DELETE CASCADE,
  position     INTEGER NOT NULL,
  programme_id INTEGER REFERENCES programmes(id) ON DELETE CASCADE,
  playlist_id  INTEGER REFERENCES playlists(id) ON DELETE CASCADE,
  UNIQUE (plan_id, position),
  CHECK ((programme_id IS NULL) <> (playlist_id IS NULL))
);

CREATE INDEX IF NOT EXISTS schedule_plan_days_by_plan
  ON schedule_plan_days (plan_id, position);

-- A one-off programme day overrides the active plan for exactly that day.
CREATE TABLE IF NOT EXISTS schedule_overrides (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id   TEXT NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
  day          INTEGER NOT NULL,
  programme_id INTEGER REFERENCES programmes(id) ON DELETE CASCADE,
  playlist_id  INTEGER REFERENCES playlists(id) ON DELETE CASCADE,
  UNIQUE (station_id, day),
  CHECK ((programme_id IS NULL) <> (playlist_id IS NULL))
);

-- Broadcast snapshots are domain state, not public files. The public PHP feed
-- assembles the rows still relevant to the current programme day into one
-- response so the shared engine can reproduce boundary-safe handovers.
CREATE TABLE IF NOT EXISTS broadcast_snapshots (
  id           INTEGER PRIMARY KEY AUTOINCREMENT,
  station_id   TEXT NOT NULL REFERENCES stations(id) ON DELETE CASCADE,
  revision     INTEGER NOT NULL,
  payload      TEXT NOT NULL,
  published_at INTEGER NOT NULL,
  UNIQUE (station_id, revision)
);

CREATE INDEX IF NOT EXISTS broadcast_snapshots_by_station_time
  ON broadcast_snapshots (station_id, published_at, revision);
