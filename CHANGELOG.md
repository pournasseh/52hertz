# Changelog

52Hertz follows semantic versioning. The publication format has its own
`programmeVersion`; a player refuses formats it does not understand rather
than guessing at a broadcast.

## 1.0.0-rc.3 — 2026-09-26

Second release-hardening pass.

- Define deterministic DST semantics for the shared-clock primitive: a skipped
  local `dayStart` begins at the first real minute after the gap, while a
  repeated local `dayStart` uses its first occurrence. Empty stations remain
  valid and explicitly off air.
- Make station creation atomic with its media directory, so a filesystem
  failure cannot leave a half-created database row.
- Enforce POST as well as CSRF for programme save/delete routes and strip all
  ASCII control bytes from ICY response metadata.
- Correct the nginx deployment example so the executable-file deny remains
  effective inside the `^~ /52hertz/media/` location.
- Add the public-route regression test to CI, make release ZIPs reproducible,
  verify the Lite vendored primitive during packaging, and prove a second build
  has identical SHA-256 sums.
- Expand Lite URL handling so relative station media resolve from the published
  station root, reject executable/data schemes, and preserve track links through
  the editor round trip.
- Clarify capacity and security claims: deterministic browser playout removes a
  per-listener playout process, not ordinary host/CDN bandwidth or request limits.

## 1.0.0-rc.2 — 2026-09-26

Release-hardening candidate.

- Require PHP 8.3 or newer so the documented minimum is on a supported PHP branch.
- Bind track deletion and collection/playlist replacement to the station that owns them,
  preventing cross-station mutations from a malformed request or future internal caller.
- Remove uploaded replacement files when a later database operation fails, and remove a
  newly uploaded cover/audio pair when track creation fails.
- Reject non-read HTTP methods on public clock/feed/manifest/icon endpoints.
- Clean source/release packaging so runtime databases, locks, generated demo media and
  previous release artifacts never ship as source state.
- Harden the interfaces shared with the independently released Lite and JavaScript companion repositories.

## 1.0.0-rc.1 — 2026-09-23

The first public release.

- One-owner PHP and SQLite radio panel, translated in full into every
  language in `languages/`.
- Drop-in languages: one JSON file per language in `languages/`, offered in
  Settings as soon as it is there. Everything a language changes comes from
  its own file - direction, digits, date locale, its own typeface, the order
  a date is written in and how calendar columns are headed - and no code asks
  which language it is. A station's player speaks whichever of them it is set
  to, in that language's typeface, and is handed those words in its
  publication rather than shipping a dictionary.
- The server check that runs before installation speaks English and, beneath
  it, the visitor's browser language when the panel has it.
- Deterministic 24-hour programmes, rotations, playlists, collections, timed
  events and special programme days.
- Flat SQLite broadcast snapshots, a dynamic ETag feed and boundary-safe live updates.
- A responsive public player with clock correction, Media Session controls,
  clean station URLs and one independently installable PWA per station.
- Runs without `mod_rewrite`: every clean public address has a plain
  `index.php` / `player/?station=` form, the player follows whichever form it
  was opened by, and the panel shows the plain links where rewriting is
  missing. The installer finds this out from the browser and warns rather
  than refusing.
- Station logos, covers, favicons and generated application icons.
- Browser installation, server capability checks, password recovery and
  transactionally consistent database backups.
- An optional, real-time FFmpeg MP3 origin for an Icecast server to relay, so
  a station can also be heard in radio apps, car receivers and directories.
  Icecast opens one connection and fans the audience out itself; listeners
  themselves go to the station player, which needs no per-listener PHP playout
  connection but remains subject to ordinary web-host/CDN capacity. Carries
  ICY now-playing metadata, mirrors the browser engine exactly (parity-tested),
  cleans up its child processes, and bounds its own connections. Where a file
  ends before its slot, it sends silent MP3 frames until the slot does, so the
  stream never stalls and a relay that disconnects frees its slot at once.

Known limits are documented in `README.md`. In particular, 1.0.0 has one
owner, does not alter uploaded audio, and leaves listener fan-out to an optional
Icecast relay.

There is no upgrade path into 1.0.0 and none is wanted: nothing was released
before it, so the code carries no reader for an older shape of anything. One
unreachable resolver remained from before programmes replaced rotations, kept
alive only by test fixtures in a shape the publisher never wrote; it and they
are gone, and the fixtures now match a real publication exactly.
`validatePublication()` refuses any `programmeVersion` but the one it reads,
which is what makes the compatibility promise above true rather than merely
stated.
