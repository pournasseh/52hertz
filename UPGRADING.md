# Upgrading 52Hertz

An upgrade must preserve two runtime locations:

- `var/panel.sqlite` — stations, schedules, settings and password hash;
- `media/` — uploaded audio and artwork.

A language you added yourself — `languages/xx.json` — survives an upgrade,
because a release archive does not contain it and extracting over the
directory leaves it alone. A release *does* replace every language file in
its own `languages/` folder, so keep a copy of any of those you changed, or
better, save your version under a code that release does not use.

## Before an upgrade

1. Download the database backup from **Settings → Backup**.
2. Download a separate copy of the complete `media/` directory from the host.
3. Keep the previous 52Hertz release archive until the upgraded player and
   panel have both been verified.

The database backup alone is not a complete station backup; it does not contain
audio or artwork.

## Upgrade

1. Put the panel briefly into host-level maintenance mode if listeners or the
   owner may be changing it during the upload.
2. Extract the new release over the existing `52hertz/` directory. Do not
   delete or replace the runtime contents listed above. Release archives carry
   only their protective control files inside those directories.
3. Open the panel once. 52Hertz applies forward, idempotent schema migrations
   before serving the page.
4. Open one station player, confirm audio and artwork, then save a harmless
   station change and confirm that the updated feed reaches the player.
5. Remove maintenance mode.

Never run `tools/init-panel.php --force` against an installation whose data is
needed. The automated pipeline test uses its own temporary installation and is
safe to run from the working tree.

## Rollback

Stop writes, restore the previous application files and the database backup
taken immediately before the upgrade, then restore `media/` if it changed.

A database whose `schema_version` is newer than the running code is refused;
older code will not guess how to read it.
