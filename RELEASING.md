# Releasing 52Hertz

This is the maintainer checklist. It does not install, uninstall or reset a
working station.

## 1. Freeze the candidate

- Update `VERSION`, `PRODUCT_VERSION` in `lib/bootstrap.php`, and
  `CHANGELOG.md` together.
- Keep direct streaming marked active only when the host can run FFmpeg with a
  supported MP3 encoder and sustain long-running PHP responses.
- Review `README.md` and `SECURITY.md` for claims that changed.

## 2. Verify the source

Run every suite; the end-to-end pipeline uses a temporary installation and is
safe for the working database:

```sh
node --test tests/*.test.mjs
php tests/media-tools.test.php
php tests/public.test.php
```

Lint all PHP and JavaScript changed since the last release. Then build the
archive:

```sh
python tools/build-release.py
```

The builder writes `dist/52hertz-<version>.zip` and a SHA-256 file. It refuses
to include database files, station media, screenshots, logs or release output
from an earlier run. The companion [52Hertz Lite](https://github.com/pournasseh/52hertz-lite) and
[52hertz.js](https://github.com/pournasseh/52hertz.js) repositories are released
independently; their versions and checksums are not implied by this archive.

## 3. Validate the archive on a production-like host

Install the archive into a new directory and verify:

- PHP 8.3 minimum, `pdo_sqlite` and `mbstring`; GD is recommended;
- HTTPS and no PHP errors displayed to the browser;
- `var/`, `lib/`, `tools/` and `tests/` return 403 or 404;
- a root `*.log`, `*.sqlite` or backup file cannot be downloaded;
- player, feed, manifest and icon URLs resolve in the form the host serves
  (clean with `mod_rewrite`, plain without), and the Player tab shows that form;
- the dynamic feed carries a CORS header;
- a byte-range request for station audio returns 206;
- when FFmpeg is present, a timed GET of `streams/<id>.mp3` decodes as CBR
  128 kbps MP3 across at least one track boundary and leaves no child process;
- an Icecast relay, when offered, holds one source connection and reconnects;
- the feed returns an ETag and answers a matching conditional request with 304;
- installation and independent PWA identity work for two stations;
- sleep/wake, offline/online, revision handover and background audio work on
  physical iOS and Android devices.

The read-only HTTP half of this checklist can be run against that deployment:

```sh
php tools/check-production.php https://radio.example/52hertz station-id
```

For nginx or another server that ignores `.htaccess`, reproduce every deny,
rewrite, cache and CORS rule described in `README.md` before exposing the site.

## 4. Prove recovery

On the disposable candidate installation, restore the database plus `media/`,
open the panel, publish one change and play it. Do not call backup complete
until that restore has succeeded.

## 5. Publish

- Record the final date and remove “release candidate” from `CHANGELOG.md`.
- Rebuild the archive and compare its SHA-256 file.
- Tag the exact source as `v<version>` and attach both generated files.
- Keep the prior release and its restore instructions available.
