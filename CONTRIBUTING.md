# Contributing

52Hertz exists to make independent radio practical on inexpensive hosting.
Changes should preserve that constraint: PHP and SQLite on the server, plain
browser JavaScript in the player, no required build service, and no permanent
playout process.

## Before changing code

1. Read the domain contract at the start of `README.md`. A programme describes
   a full programme day; content length does not advance the schedule.
2. Do not commit a real `var/panel.sqlite`, uploaded `media/`, generated
   logs, screenshots, secrets or identifying audio metadata.
3. After changing any wording a person reads, run
   `php tools/extract-strings.php --write` and bring every language in
   `languages/` up to date with what it reports.
4. Prefer browser and platform features over new runtime dependencies.

## Verify a change

Run from the repository root:

```sh
node tests/schedule.test.mjs
node tests/programme.test.mjs
node tests/draw.test.mjs
php tests/media-tools.test.php
node tests/pipeline.test.mjs
```

Also lint every changed PHP file with `php -l` and check changed JavaScript
with `node --check`.

The pipeline suite builds its database, demo media and broadcast snapshots in a fresh
system temporary directory; it never opens the working installation. Player
changes also need a real-browser pass at desktop and phone widths; release
candidates need physical iOS and Android audio testing.

## Proposing work

Keep each change focused and explain the listener or operator problem it
solves. Include reproduction steps for bugs and note any privacy, bandwidth,
shared-hosting or accessibility tradeoffs. Report security issues privately as
described in `SECURITY.md`.
