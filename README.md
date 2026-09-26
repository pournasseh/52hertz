<p align="center"><img src="assets/img/logo-v.png" alt="52Hertz" width="170"></p>

# 52Hertz

Free, open-source radio for people and communities who need an affordable way
to be heard. It is designed to run on ordinary low-cost PHP hosting, without a
continuous playout server or a paid platform.

A station that is already playing. Nobody starts it; a listener joins whatever
is on now, at the position it is at. There is no playout process running: the
station's dynamic feed plus the current time is enough to know what should be
sounding.

The domain contract below is part of the implementation, not just a product
description: changes to scheduling or playout need to preserve it.

- PHP + SQLite for the private panel, plain browser JavaScript for the player.
- No build step, no bundler, no npm, no Node at runtime. Node is used only to
  run the tests.
- Every successful panel change atomically stores a flat broadcast snapshot in
  SQLite. PHP builds a conditionally cached public feed from the snapshots the
  current programme day needs; players never touch a private session.
- One implementation of the timing rules, in `engine/schedule.js`. The
  panel keeps it, previews with it, and every player loads that same file.


## About

52Hertz is a deterministic, self-hosted radio system. Instead of keeping a playout server running continuously, it derives what should be on air from published station state plus a shared clock. The full edition adds a PHP/SQLite station manager, programme-day scheduling, immutable broadcast snapshots, a public player, optional Icecast-compatible origin streaming, and deployment/release tooling around that core model.



## Companion projects

- [52Hertz Lite](https://github.com/pournasseh/52hertz-lite) — the static-hosting version: no PHP and no database.
- [52hertz.js](https://github.com/pournasseh/52hertz.js) — the shared-clock playout primitive by itself.

## Core domain contract: what a programme means

In this product, a **programme is the complete content plan for one programme
day**. It is not a finite show whose natural duration decides when the next
programme starts.

A programme day is always the 24-hour interval beginning at the station's
configured start time. For example, when a station starts its days at 06:00,
Monday's programme owns the interval from Monday 06:00 up to Tuesday 06:00.

The selected programme owns that entire interval:

- when its content is shorter than 24 hours, it loops for as many passes as
  needed to fill the programme day;
- when its content is longer than 24 hours, only the first 24 hours can air and
  the remainder is not reached that day;
- at the next station start time, a new programme day begins and the programme
  selected for that day starts from its beginning, even when it is the same
  programme again.

Scheduling therefore chooses **one programme for each programme day**. The
length of a programme never advances the schedule. Any schedule design, data
model, panel wording, preview, or playout implementation that treats a
programme as a finite show and advances when its content ends violates this
contract.

## Run it

Upload the `52hertz/` directory to a PHP-capable web host, then open it in a
browser. For example, when the document root is `d:\WWWROOT\http-www` and
Apache is at `http://localhost:8000/`, the addresses are:

| | |
|---|---|
| Panel | <http://localhost:8000/52hertz/> — the sign-in page |
| Player | <http://localhost:8000/52hertz/player/demo> — no login, no session |
| Clock | <http://localhost:8000/52hertz/index.php?p=time> — public, used by players |

Set it up by opening the panel in a browser. On a fresh copy every page is
the installer: pick a language, choose the owner password, and you are
signed in on an empty panel. Nothing to run on a command line. Before that,
the server is checked - PHP 8.3 or newer, the `pdo_sqlite` and `mbstring`
extensions, and write access to `var/` and `media/` - and
anything missing is listed in words - in English, and in the visitor's
browser language if the panel has it - instead of an error page. The installer also warns about what works but less well: an
upload limit under 32 MB, no GD (no covers), a public address without
https, or a server that does not rewrite addresses (see below).

**`mod_rewrite` is optional.** Every clean public address has a plain form
that needs nothing but PHP:

| Clean (needs rewriting) | Plain (works anywhere) |
| --- | --- |
| `player/<id>` | `player/?station=<id>` |
| `stations/<id>/feed.json` | `index.php?p=station-feed&id=<id>` |
| `stations/<id>/manifest.webmanifest` | `index.php?p=station-manifest&id=<id>` |
| `stations/<id>/icon-192.png` (and 512, maskable) | `index.php?p=station-icon&id=<id>&size=192` |
| `streams/<id>.mp3` | `index.php?p=stream&id=<id>` |

Nothing is configured. The player works out which form to use from its own
address: opened as `player/demo`, rewriting plainly works, so it asks for
the clean feed; opened as `player/?station=demo`, it asks `index.php`. The
manifest answers in the form it was asked in. The panel's Player tab asks the
clean feed address from the browser and, if what comes back is not JSON,
shows the plain links instead. PHP cannot answer this itself - the server
rewrites before PHP runs, and `apache_get_modules()` exists only under
mod_php, not under the PHP-FPM or CGI that shared hosts run.

Nothing else needs starting: PHP serves the panel, and the player is static.

The demo station, for development and the tests, still comes from the
command line:

```sh
python tools/make-demo-media.py             # six short test tones
php tools/init-panel.php "a password" --demo
```

```sh
node tests/schedule.test.mjs     # the timing rules
node tests/programme.test.mjs    # programme editing rules
node tests/draw.test.mjs         # the panel's random draw matches the players'
php tests/media-tools.test.php   # safe FFmpeg capability detection
node tests/pipeline.test.mjs     # isolated database -> dynamic feed -> player
```

`pipeline.test.mjs` builds a complete disposable installation under the system
temporary directory. It never opens or changes the working database or media.

## What is here

```
./                    the whole product: one PHP entry point plus public assets
  index.php          every page, form and JSON call arrives here
  lib/               listener requests, router, pages, model, publisher   .htaccess: denied
    schema.sql       the database's definition, read by migrate()
  assets/            the panel's front end, one folder per kind of file
    css/             panel.css, programme.css
    js/              ui.js (dialogs), library.js, programme.js, dates.js, …
    img/  fonts/     pictures, the icon sprite, Vazirmatn
  engine/            THE SHARED ENGINE — schedule.js, programme.js, clock.js.
                     Public and CORS-readable: the panel imports it, every
                     player loads it, the tests run it.
  player/            one player, serving every station at player/<address>
                     (or player/?station=<address> without mod_rewrite)
  languages/         one JSON per language, drop one in       only .json served
  stations/<id>/...  clean URLs for the dynamic feed, manifest and icons
  media/<station>/   each station's own audio, in its own folder
  tools/             installer, publisher CLI, media generator, screenshotter   denied
  tests/             the suites                                                 denied
  var/               the SQLite database — runtime data, kept across upgrades   denied
```

**Why `engine/` is not inside `player/`.** It is not the player's; it is
shared. `assets/js/preview.js`, `now-playing.js` and `programme.js` import it
so the panel previews with the very code a listener runs, and four test files
run it directly. A folder with three kinds of consumer belongs above all
three. When a downloadable player is packaged, `engine/` is copied in beside
it.

Panel pages are named in the query string — `?p=station&id=demo&tab=settings`.
Public listener URLs use the Apache mappings in the root `.htaccess` where
they can: `player/demo` is internally served by the shared player, while
`stations/demo/feed.json` is generated by PHP from SQLite. Without
`mod_rewrite` the same pages answer at their plain addresses (above). The
shell around every panel page is the same: a fixed header, a sidebar listing
every station with a dot for its on-air state, and tabs for the station you
are in.

**A library is not a programme.** They are separate tabs because they are
separate things:

- The **library** is everything a station has uploaded, each with a
  title, a credit, a description, a cover and tags, searchable as you type. Something can sit in the
  library for months without ever going on air.
- The **programme** is what it actually plays: which library items are on air,
  in what order, and which timed events belong to the day. It supports a fixed
  running order or a deterministic shuffle.

Adding to one is not adding to the other. A new library item is off air until
someone puts it in a programme, and taking something off air leaves it in the
library with its tags intact.

A station opens on its **programme** — that is what a station is. The library
is the tab behind it.

**A new station shows its way to its first listener** above its tabs: add
audio, put it in a programme, it is on air, share its link. Each step is read
from what the station already has (the same facts as the state beside it in
the sidebar, and whether its Player tab has been opened), only the next one
carries a button, and the whole card is gone once the station is on air and
its link has been seen. A
button can lead to another tab and open a dialog there (`…&tab=library#add-track`).
Every empty list likewise says what comes next and carries the button that
does it.

A station may have many programmes. Its playback plan either keeps one explicit
default programme on air, or uses recurring weekly change points. A weekly
change holds until the next one, wrapping across the end of the week, and like
every day, each day it holds starts its programme afresh. The station owns one programme-day start time; programmes and weekly rows
cannot introduce another clock. Saving any change updates listeners without a
separate Publish action. A save never interrupts the track already on air. At a
programme boundary the default policy also lets that track finish; an operator
can choose an exact start for a bulletin or another clock-critical programme.

**A programme day** is 24 hours from the station's start time: with a 06:00
start, 1 Farvardin runs from 06:00 on 1 Farvardin to 06:00 on 2 Farvardin.
That is the only definition of a day anywhere in the system.

**Special days** are one programme day each, with a programme of their own - a
holiday, an anniversary. Two days in a row are two entries. A special day
overrides whatever the plan says for exactly its 24 hours; when it ends, the
plan starts a fresh run from the top of its programme, and the special day
leaves no other trace. They do not repeat yearly (Islamic holidays move and
depend on the moon; nothing could compute them reliably in advance). Days that
are over are dropped from the database the next time one is saved.

**A playlist is a set of programmes**, with a tab of its own. Wherever the
schedule asks what to play - the default programme, a weekly change, a special
day - the dropdown offers "Random from" each playlist before the programmes.
Each programme day then plays one programme drawn from it at random: a fresh,
independent draw every day, so the same one can come up twice in a row.
Programmes with nothing to play are never drawn. The draw comes from the
station's seed and the day number alone, so every listener hears the same
programme, and the panel - which mirrors the draw in PHP - names it.

**Media belongs to a station, not to the panel.** Each one gets
`media/<station-id>/`, created with it. There is one way audio gets in:
**Add track** uploads it with a title, a credit, a description, a cover and
tags. An MP3 that already carries a cover (ID3v2) offers it in the dialog; the
operator can swap or remove it before saving, and change it later. It stays in the
library until it is added to a programme. Clicking any row in the library opens
that track on its own — play it, see its length, its file and when it was
measured, retag it, replace its audio file (everything else about it stays,
and the new file is measured again), or delete it. Ticking the checkbox beside
rows opens a bar for acting on all of them at once: add or remove tags, add
them to a programme, or delete them. Uploads are
checked twice: an extension allowlist in PHP, and an `.htaccess` in `media/`
that refuses to hand anything executable to an interpreter. Verified: a `.php`
file placed in that folder answers 403 while the audio beside it answers 200.

**Logos and covers** live in `media/<station-id>/covers/`. They are decoded and written
out again by GD, never stored as sent: at most 1000 px on the long side, PNG
kept as PNG for its transparency, everything else JPEG. Every upload gets a
fresh name, because a listener on an older revision may still be showing the
previous one. A station's logo is its stable identity for favicon and app
icons; its separate cover is player artwork and the fallback for tracks. Both
are published as they are (`station.logoUrl`, `station.artUrl`, and `artUrl`
on tracks that have one); which cover to show when a track has none is the
player's decision, not the panel's.

**Nothing is kept that nothing uses.** Deleting a track deletes its audio and
cover; replacing a cover or an audio file deletes the old one; deleting a
station deletes its whole `media/<station-id>/` folder. A file is only removed
when no track, station logo, station cover or programme cover still points at it. The owner's choice,
2026-09-21: a listener who is mid-way through a deleted track on an older
revision may be cut off, and that is accepted.

**Settings** has two tabs: *General* (the shuffle seed) and *Password*, where
the owner changes the password. Changing it signs out every other browser.

**A forgotten password** needs no command line and loses nothing: create an
empty file named `reset-password` in `var/` (a host's file manager can),
and the next visit to any page asks for a new password, signs every other
browser out and deletes the file. The sign-in page says how, under
"Forgot the password?". A reset file is honoured once: its time is recorded,
so one that PHP could not delete does not stay a way in.
Wrong passwords at sign-in slow the next attempt down, counted for the panel
as a whole rather than per browser session, and forgotten after 15 quiet minutes.

**On a phone** the sidebar becomes a drawer behind the menu button, tables drop
the columns that are details, and dialogs use the full width.

**The shuffle seed belongs to the radio, not to each station.** *Settings*
holds the seed behind every shuffle. A station's own order still differs from
its neighbour's, because the seed is
combined with the station id - two stations holding identical tracks do not
play them in lockstep. Asking every station for both was asking the same
question twice.

**A station can be taken off air without being deleted.** The switch is in
Settings; saving it carries `"enabled": false` into the live output, and
`resolve()` returns off-air for it whatever the schedule says. The player
then states that the station is off air and disables its own play button,
rather than being left to fail at fetching media. Switch it back on and the
station resumes wherever the clock has reached — it never stopped running, it
just stopped being served.


**Every station has a player, and its link, from the moment it exists.** One
player in `player/` serves every station of the panel; which one is in the
clean path, `player/<address>`, or in `player/?station=<address>` on a server
that does not rewrite addresses. The address is chosen when the station is
created (it follows the name until the owner types one, and a name in another
script leaves it to the owner) and never changes, because it is the link
listeners were given. The station's *Player* tab shows the link, with Copy and
Open, the player itself as listeners see it, and the station at any moment.

Everything that makes it that station's player comes from what the station
publishes: its name, tagline, accent colour, colophon and home link, and the
language it speaks to listeners (*Player language*, any language in
`languages/`, set when the station is created and in its Settings -
independent of the panel's own language). Beside what is playing it shows the track's cover, else its
programme's, else the station's, else the title's first letter. The engine,
the published schedule and the clock sit one folder up, so nothing needs
configuring, and a player on the panel's own address needs no cross-origin
headers. What it never gets is its own copy of the timing rules.

**The player is one screen, and nothing on it scrolls.** Two parts, 58/42,
with a line of the station's colour between them. Above, on the programme's
cover or the station's own (without either, its colour): the station's name,
tagline and ON AIR, and resting on the line, in one row, the track's cover
with its title, credit and length. The cover is the track's own, else its
programme's, else the station's, else the first letter of its title; the last
one stays until the next has arrived. Below, darker: three buttons as in
fn-rock - *about this track*, *tune in*, *share* - and what comes next, with
small covers. There is no progress bar: a radio has no scrubber. *About this
track* slides a pane up over the whole lower part, as far as the line, with a
chevron to take it back down; it has the one moving line, a countdown of the
time the track has left, then its description and its programme, and the
track itself stays in sight above it. Share opens the phone's own share sheet,
or copies the link where there is none. The track's title and cover reach the
lock screen and the phone's media controls (Media Session), which tune in and
stop like the button. Anything the station wrote carries `dir="auto"`, so a
left-to-right line on a right-to-left station keeps its punctuation in place,
and the words beside the cover keep to its side in either direction.

A player on another site of the station's own is not built yet: that is a
download, later.


### The contract

```js
resolve(publication, nowMs) -> {
  state,            // 'on-air' | 'off-air'
  reason,           // why it is off air: 'disabled' | 'nothing-scheduled' | 'empty-programme'
  track,            // what should be sounding
  sourceOffsetMs,   // how far into it the station is
  slotStartMs, slotEndMs,
  nextBoundaryMs,   // when this answer changes
  day,              // the programme day of the current run
  override,         // true when a one-off date override selected this day
  programme,        // { id, name, mode }
  pass, passIndex, programmeLengthMs,
  runStartMs, runEndMs,
}
```

The model is **days, plans and passes**. A plan is a dated, repeating list with
one choice per programme day: one entry means "always", seven entries make a
week, and seventy entries make a seventy-day rotation. A newer dated plan takes
over without rewriting history, and a one-off override may replace one day.
The selected programme owns exactly that 24-hour day. Inside it the programme
repeats: `pass = floor(elapsed /
L)`, with the order for each pass drawn from a seeded, versioned shuffle
(`fnv1a-sfc32-fy-v1`) - so every device draws the same order, forever, without
replaying the station's history. Integer milliseconds throughout, half-open
slots `[start, end)`, and finding the slot inside a pass is a binary search
over prefix sums.

Weighted duplicates (an ident three times a cycle) get stable occurrence keys,
`ident#0`, `ident#1`, `ident#2`, and in shuffle mode the list is sorted
canonically before it is shuffled — so re-ordering rows in the panel cannot
change what listeners hear.

### The publication

```json
{
  "stationId": "demo",
  "revision": 3,
  "enabled": true,
  "station": { "name": "...", "tagline": "...", "accent": "#7d93a6", "logoUrl": "...", "artUrl": "..." },
  "dayStartMs": 21600000,
  "shuffleSeed": "tone-test-radio·demo",
  "algorithm": "fnv1a-sfc32-fy-v1",
  "tracks": { "tone-a": { "id": "tone-a", "title": "Low Hum", "credit": "...",
                          "mediaUrl": "...", "durationMs": 7003 } },
  "programmeVersion": 1,
  "programmes": { "1": { "name": "All day", "mode": "shuffle",
                        "items": [ { "entryId": "a1", "trackId": "tone-a" },
                                   { "entryId": "i1", "trackId": "ident" } ], "events": [] } },
  "playlists": { "4": { "name": "Weekend", "programmes": ["1", "2"] } },
  "schedule": {
    "plans": [
      { "startsOn": 20718, "name": "Main", "days": ["1", "1", "1", "1", "1", "playlist:4", "2"] }
    ],
    "overrides": [ [20720, "2"] ]
  }
}
```

Each plan's `startsOn` is a programme-day number and `days` is its repeating
cycle. Override rows are `[programme day number, choice]`. Anywhere a programme
is named, `"playlist:<id>"` may stand instead, and that day's programme is drawn
from the playlist.

The canonical public document is `stations/<id>/feed.json`. PHP assembles it
on request from flat, immutable rows in SQLite. It includes one programme-day
baseline plus later changes needed to reproduce a seamless handover. The
player composes that chain in memory, so a save updates station metadata and
future programming immediately without cutting the audio already on air.

The response carries an ETag. While nothing changed, the player's occasional
conditional request receives `304 Not Modified` with no JSON body. A visible
tab checks every two minutes, a hidden tab every ten minutes, and focus,
reconnect and Play trigger an immediate check.

### Installable station apps

PHP generates a station-specific `manifest.webmanifest` and icons on request
from the latest snapshot. Its stable manifest `id`, name, colour,
`player/<id>` start URL (or `player/?station=<id>`, when the manifest itself
was asked for by its plain address) and icons
belong to that station, so several stations from one 52Hertz panel can be
installed side by side. They still use the same small player and service
worker under `player/`; no player code is copied per station.

The station logo produces 192 px and 512 px regular icons, a safe-area
maskable icon, the browser favicon and the Apple touch icon. The logo is
contained rather than cropped. With no logo, the station cover is cropped;
with neither, the publisher draws a monogram from the stable station id over
its accent colour. Built-in exact-size icons keep installation available when
GD is absent.

On browsers that expose the install prompt, the player shows **Install**. On
iPhone and iPad, installation remains available through Safari's **Add to Home
Screen** command. HTTPS is required in production (localhost is the usual
development exception). A station needs one successful save before its player
can be installed, because its public identity comes from its first snapshot.

The service worker caches only the player shell: HTML, CSS, JavaScript, the
shared schedule engine, font and fallback icon. The dynamic feed stays
network-only, and audio is never put in the offline cache. An
installed player can therefore open cleanly without a connection and explain
that the station cannot be reached, but live radio naturally still needs the
network.

## What the player does when things go wrong

| Situation | Behaviour |
|---|---|
| The same file is scheduled twice in a row | It replays properly. A cycle rolling over onto the track it just finished, an ident repeated back to back, or a one-track station all hit this, and the obvious implementation goes silent for the whole slot waiting for a load event that never fires. |
| The station is switched off | `resolve()` reports off air before it looks at anything else, and the player says so plainly instead of failing at media it was never going to get. |
| A file will not load | It holds, says so, and rejoins at the next scheduled boundary. It never advances a private queue, so every listener stays on the same broadcast. |
| Loading took three seconds | The position is resolved *again* after metadata arrives, because the station moved while the fetch was in flight. |
| Playback has slipped | Under 350ms, nothing. Up to 2s, a 2% rate nudge that converges without an audible jump. Beyond that, a seek. |
| The tab slept | On `visibilitychange` the clock is re-sampled and the position recomputed from scratch. |
| The device clock is wrong | the panel's clock route is consulted, with half the round trip kept as declared uncertainty, shown in the player's own diagnostics line. |
| The clock was edited mid-listen | The elapsed-time reference is monotonic, so this is detected and reported rather than followed. |
| A snapshot needs a newer player | It is refused, and the player stays on the snapshot it understands. |

## Deployment

The panel directory is the web root of the panel, and `index.php` is the only
file in it. Because the document root on this host sits *above* it, the
directories that must never be served carry their own `.htaccess` denying
everything: `lib/`, `tools/`, `tests/`, `var/`. Verify that all four answer
403 or 404, while `engine/`, `media/`, the station feed and clock answer 200.

If you ever point a virtual host straight at `52hertz/`, move `var/` outside it
and the deny rules become belt-and-braces rather than the only protection.

**nginx does not read `.htaccess`**, so there the same four folders need
denying in the server block, or anyone can download the database:

```nginx
location ~ ^/52hertz/(var|lib|tools|tests)/ { deny all; }
location ~ ^/52hertz/languages/(?![^/]+\.json$) { deny all; }
location ~* ^/52hertz/.*\.(log|sqlite3?|db|bak|sql|zip|tar|tgz|gz)$ { deny all; }

# Media stays static even when the server has a broad PHP/CGI handler. Keep
# the executable deny nested inside the ^~ block: a separate top-level regex
# would be skipped by nginx once ^~ wins prefix matching.
location ^~ /52hertz/media/ {
    location ~* \.(php|phps|phtml|phar|inc|cgi|pl|py|rb|sh|html?|xhtml|svg|shtml)$ {
        deny all;
    }
    add_header Access-Control-Allow-Origin "*" always;
    add_header X-Content-Type-Options "nosniff" always;
}

# Optional clean URLs; adjust /52hertz if the panel lives elsewhere.
# Without them the panel hands out the plain player/?station= links.
rewrite ^/52hertz/player/([a-z0-9]+(?:-[a-z0-9]+)*)$
        /52hertz/player/index.html last;
rewrite ^/52hertz/stations/([a-z0-9]+(?:-[a-z0-9]+)*)/feed\.json$
        /52hertz/index.php?p=station-feed&id=$1 last;
rewrite ^/52hertz/stations/([a-z0-9]+(?:-[a-z0-9]+)*)/manifest\.webmanifest$
        /52hertz/index.php?p=station-manifest&id=$1 last;
rewrite ^/52hertz/stations/([a-z0-9]+(?:-[a-z0-9]+)*)/icon-192\.png$
        /52hertz/index.php?p=station-icon&id=$1&size=192 last;
rewrite ^/52hertz/stations/([a-z0-9]+(?:-[a-z0-9]+)*)/icon-512\.png$
        /52hertz/index.php?p=station-icon&id=$1&size=512 last;
rewrite ^/52hertz/stations/([a-z0-9]+(?:-[a-z0-9]+)*)/icon-maskable-512\.png$
        /52hertz/index.php?p=station-icon&id=$1&size=512&maskable=1 last;
rewrite ^/52hertz/streams/([a-z0-9]+(?:-[a-z0-9]+)*)\.mp3$
        /52hertz/index.php?p=stream&id=$1 last;
```

The installer checks this from the browser (a `HEAD` request for
`var/panel.sqlite`) and says so in red if the file is handed out.

The bundled player is deliberately same-origin. The feed also sends a CORS
header, but copying the player to a different domain is not a supported v1
deployment and is not part of the release contract.

Media hosting must answer Range requests, or a browser has to download a whole
file before it can seek into it. Apache does this by default.

## Two things the dev server hid

Both were found by running this under the real Apache, and neither would have
shown up otherwise.

A refused CSRF token replied `419`. PHP's built-in server passed that through;
Apache does not know the code and rewrites it to `500`, so a correctly refused
form looked like a broken panel. It is a `403` now.

Station media URLs are relative to the panel root. Both the panel and player
resolve them from that one base, so a duration check and listener playback
always address the same file.

## Credits

Icons are [Bootstrap Icons](https://icons.getbootstrap.com) 1.13.1, MIT
licensed, bundled as `assets/img/icons.svg` — only the sixteen the panel
uses, as one sprite, so it is a single cached request and every glyph takes
the colour of the text beside it in either theme. Nothing else is vendored,
and there are no runtime dependencies.

Full third-party license notices are in
[`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md).

## Backup and restore

*Settings -> General* can download a transactionally consistent SQLite backup.
The database contains the owner password hash and unpublished drafts, so store
it privately. A complete backup also requires a copy of the `media/` directory.

To restore, temporarily take the site out of use, replace `var/panel.sqlite`
with the saved database, restore `media/`, and then open the panel and player to
verify them. The database backup already includes the broadcast snapshots.

## Optional media tools

52Hertz does not require FFmpeg. The installer and *Settings -> General* check
whether PHP can start FFmpeg, whether an MP3 encoder is present, and whether
FFprobe is available. The result is cached for one day; *Check again* refreshes
it immediately. Detection runs fixed commands directly, with no shell and a
four-second timeout.

Uploads remain unchanged on disk. When FFmpeg and a supported MP3 encoder are
available, every published station also has a live origin at
`streams/<station-id>.mp3`. It resolves the same schedule and handovers as the
browser player, joins the current track at its live offset, and transcodes each
slot to CBR 128 kbps, 44.1 kHz stereo MP3, with conventional ICY headers. A
file that ends before its slot is followed by silent MP3 frames until the slot
does - the same silence every player falls into there - so the bytes never
pause: a relay's buffer does not drain through the gap, and a listener who
leaves during it is noticed at once.

**This address is not where listeners go.** The station player is. It does
not keep a PHP playout response open per listener: listeners fetch ordinary
web media and clock/feed data. Audience size still consumes the normal
bandwidth, HTTP request capacity and any CDN quota of the host serving those
files. The stream exists so a station can *also* be carried by an Icecast
server, and through it reach radio apps, car receivers and directories — the
places that expect a plain stream URL.

Configure Icecast as an on-demand relay of the URL. It opens **one** origin
connection here and fans its audience out itself, so listener count does not
multiply FFmpeg processes or long-running PHP responses on the 52Hertz origin.
Icecast still has its own listener, bandwidth and host limits.

The route therefore permits three connections per station — one for the
relay, and slack for a second relay, an overlapping reconnection, or somebody
checking that the address works. It is a guard on an anonymous route that
forks FFmpeg, not an audience budget, and it is the reason this URL is not
one to hand out: anyone past the third is refused. A host must allow
`proc_open` and long-running PHP responses. Reverse proxies must not buffer
the route; the response sends `X-Accel-Buffering: no`, but host configuration
still wins.

If FFmpeg, an MP3 encoder, a live schedule or a readable source file is
missing, the route answers `503 Service Unavailable` with a plain explanation
instead of pretending to be audio. Disconnecting a client terminates its
FFmpeg child. FFprobe is useful for inspection but is not required to stream.

A client that asks with `Icy-MetaData: 1` is told `icy-metaint: 16000` and
then receives the usual in-band blocks, one per second of audio, naming what
is on as `Credit - Title`. Icecast passes these to its own listeners, so a
relayed station shows its track names in players and directories. A client
that does not ask gets the audio untouched.

### Relaying it with Icecast, and listing the station

This is what the stream address is for, and the only thing it is for. Icecast
pulls it on demand and serves the audience itself. In `icecast.xml`, inside
`<icecast>`:

```xml
<relay>
    <server>radio.example.org</server>
    <port>80</port>
    <mount>/52hertz/streams/demo.mp3</mount>
    <local-mount>/demo.mp3</local-mount>
    <on-demand>1</on-demand>
</relay>
```

`<server>`, `<port>` and `<mount>` are this panel's address taken apart;
`<local-mount>` is what Icecast will serve, so listeners and directories get
`http://your-icecast:8000/demo.mp3` — that is the address an audience and a
directory get, and it is Icecast's to scale. `<on-demand>1</on-demand>` keeps
52Hertz idle until somebody is actually listening.

**Check that your Icecast can reach the panel over the transport your Icecast
build supports.** Keep the public panel and owner login on HTTPS. If a relay
cannot fetch an HTTPS source directly, use a private/internal origin or a
proper TLS-terminating proxy rather than exposing the panel login over plain
HTTP.

Directory listings are then Icecast's business rather than this product's.
Icecast supports directory/YP configuration, and independent directories may
also accept a stream URL submitted by hand. Submit the **Icecast listener URL**,
not the 52Hertz origin URL. Directory policies, fees and submission workflows
change independently of this project; check their current rules before
publishing a listing.

## Security and privacy

Read [`SECURITY.md`](SECURITY.md) before a public deployment. In particular,
use HTTPS, verify that private directories are denied by the web server, and
remove identifying tags or metadata from sensitive audio before uploading it.
Uploads are published unchanged. MP3 has the widest browser compatibility;
other accepted browser audio formats may not work for every listener.

Contributions are welcome; start with [`CONTRIBUTING.md`](CONTRIBUTING.md) and
the [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md). Security reports belong in a
private channel as described in [`SECURITY.md`](SECURITY.md), not a public
issue.

## Honest limits

- **No device testing.** The drift thresholds, the rate-correction figure and
  the hand-over behaviour are reasoned starting points measured only in
  headless Chrome on this machine. iOS and Android background audio are
  untested, and the notes are right that a desktop demo proves nothing there.
- **UTC weeks are deliberate.** A weekly rule stores only its weekday and uses
  the station's single UTC programme-day start. The panel converts that clock
  to and from the operator's local time; broadcast snapshots have no DST calendar.
- **Publication compatibility is explicit.** v1 freezes `programmeVersion: 1`,
  named once on each side as `PROGRAMME_VERSION` (`engine/schedule.js`,
  `lib/publisher.php`). `validatePublication()` refuses any other value, and
  a missing one, so a player older than the panel that published to it says
  it cannot read the format instead of misplaying it. A test proves it.
  Release notes must call out any future publication-format change.
- **Two validators.** The browser's `validatePublication()` warns the operator
  early; `check_publication()` in PHP refuses to publish. Neither interprets
  the timeline. The duplication is deliberate: a server should not trust a
  browser.
- **One owner.** No signup, roles, invitations or billing, as specified.
- **Audio is measured in the browser.** A newly uploaded track cannot take
  airtime until its metadata has loaded and its duration is known.

## Language and calendar

*Settings → General* has two independent choices: the panel's **language** and
its **calendar** (Gregorian or Jalali). Either can be used with the other.

### Adding a language

A language is one JSON file in `languages/`, named by its code —
`xx.json`, or `xx-yy.json` with a region. Put one there and it is offered in
Settings on the next page load. Nothing is registered anywhere, no PHP is
edited and no document lists it, because a person who can translate is rarely
a person who can write PHP. The region is optional: `xx-yy` falls back to
`xx.json` if that is what is present.

```json
{
  "language": {
    "name": "<the language's own name, in its own script>",
    "englishName": "<the same name in English>",
    "direction": "ltr",
    "locale": "xx-YY",
    "digits": "0123456789",
    "calendar": "gregorian"
  },
  "panel":  { "Stations": "<translation>" },
  "player": { "on air": "<translation>" }
}
```

| in `language` | what it is | if it is missing |
| --- | --- | --- |
| `name` | the language in its own script; this is what Settings shows | the file's code |
| `englishName` | the same name in English | the file's code |
| `direction` | `rtl` turns the whole panel around | `ltr` |
| `locale` | how dates and numbers are formatted, e.g. `xx-YY` | the file's code |
| `digits` | this language's own ten digits, `0` to `9`, or omitted | `0123456789` |
| `calendar` | `gregorian` or `persian`, the one to start with | `gregorian` |
| `font` | a `.woff2` file in `assets/fonts/`, for a script system fonts draw poorly | the system's font |
| `dateParts` | the order a whole date is written in, e.g. `weekday day month year`, where the locale's own pattern is not the one its readers use | the locale's pattern |
| `weekdayNames` | calendar column heads: `short` (Mon) or `narrow` (M) | `short` |

Copy `languages/en.json` to start: it is the full list of what there is to
say, every string mapped to itself. `php tools/extract-strings.php` reports
what each language is still missing and what it has that the panel no longer
says; `--write` regenerates the English template after wording changes.

The file is JSON and not PHP on purpose. A translation arrives from a
stranger, and a `.php` file from a stranger is a program with the run of the
server, while JSON is only ever data. `languages/.htaccess` serves nothing
but `.json` for the same reason.

**`panel` and `player` are separate** because they travel separately. The
panel reads its own words from disk. A station's player words are attached to
its feed — once per feed, beside `snapshots` rather than inside any of them,
because a feed can carry a whole programme day's revisions and a listener
needs only the words the station speaks now. So a language added here reaches
listeners without the player being touched, and a player deployed on another
domain still speaks it. A listener downloads the thirty-odd player strings,
never the panel's nearly six hundred.

They are read when the feed is built, not frozen into a snapshot, so
**correcting a translation reaches every listener without republishing a
single station**. The same goes for an installed station's manifest.

Text is looked up by its **English wording**, in PHP (`t()` / `h()`,
`lib/i18n.php`), in the panel's browser code (`t()`,
`assets/js/i18n.js`) and in the player (`say()`). So an entry nobody has
translated shows readable English rather than a bare key, and a half-finished
translation is usable rather than broken.

Nothing in the code asks which language it is: everything a language changes
comes from its own file. `"direction": "rtl"` turns the panel around,
`digits` gives its numerals, and `font` names a typeface in
`assets/fonts/` (Vazirmatn, SIL Open Font License, is bundled there),
so nothing loads from the internet. The panel, the installer and the player
all take the typeface from the language file; a language without one uses the
operator's system font. The installer, which runs before any setting exists,
speaks English and, beneath it, the visitor's browser language if a file for
it is here.

Dates are formatted in the browser, because only it knows the operator's
timezone; `assets/js/dates.js` does the Jalali arithmetic (the browser
can display a Jalali date but cannot calculate one) and draws the date
picker. Its conversion matches the browser's own Persian calendar on every
day from 1925 to 2123. Everything else — month names, digit shapes, number
formats — comes from the locale the language file names, so a language needs
no code here to be formatted the way its readers expect.

The panel's language and a station's **player language** are set separately:
one is what the operator reads, the other is what that station's listeners
hear, and a panel run in one language can carry a station that speaks another.

## What the tests actually prove

`tests/schedule.test.mjs` (46 checks) covers the timing rules, through
the same `resolve()` every listener runs. The day arithmetic: a UTC day of
exactly 86,400,000 ms, weekdays without a calendar library, and the small
hours belonging to the programme day that began yesterday. Plans: one-day,
seven-day and seventy-day cycles, a later dated plan taking over without
disturbing the days before it, and a new revision keeping its place in the
cycle. Overrides: a single day replaced, two in a row, and a past one leaving
no trace. Playlists: one programme drawn per day, the same draw for every
listener, skipping what has nothing to play. Playout: slots tiling a pass with
no gap or overlap, every copy of a track airing once per pass, the last pass
of a day cut off rather than overrunning, and a programme longer than a day
having a tail that never airs. The shuffle: a fresh order per pass, a
different one per day, the same one on every device. And that two independently
parsed copies sharing no state resolve any instant identically, at exact
boundaries and forty years apart, at a cost that does not grow with the
station's age.

`tests/programme.test.mjs` (7 checks) covers programme editing and
validation. `tests/media-tools.test.php` covers successful, missing
and stalled media-tool processes, safe stream commands, path confinement and
the connection limit without depending on a real FFmpeg installation.
`tests/playout.test.mjs` compares the PHP stream resolver against the
JavaScript engine across shuffle, collections, playlists, events, overrides
and live handovers. `tests/pipeline.test.mjs` (19 checks) runs the real PHP
publisher against an isolated real SQLite database and reads what it wrote with the real resolver: durations that
came from the files rather than from round numbers, media URLs that resolve to
files whose byte length matches the published duration, an hour of listening
that never lands outside a track, and a second publish that leaves the earlier
revision byte-for-byte identical.

These automated suites do not replace a physical-device pass. Background audio,
lock-screen controls and hand-over behaviour still need testing on real iOS and
Android devices before calling the player broadly production-ready.

## Releases and upgrades

The application version is in `VERSION`; database schema versions are recorded
in the panel settings and only migrate forward. Read [`UPGRADING.md`](UPGRADING.md)
before replacing an existing installation. Maintainers build clean archives and
run the production checklist in [`RELEASING.md`](RELEASING.md); generated release
archives deliberately exclude databases, station media, logs and
screenshots.

## License

52Hertz is free software licensed under the
[GNU Affero General Public License, version 3 or later](LICENSE). See
[`THIRD_PARTY_NOTICES.md`](THIRD_PARTY_NOTICES.md) for bundled components.
