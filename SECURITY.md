# Security

## Reporting a vulnerability

Please do not publish working exploits or private station data in a public
issue. Use the repository host's private security-advisory feature when one is
available, or contact the maintainer through a private channel listed by the
repository. Include the affected version, server type, reproduction steps and
the smallest safe proof of concept.

## What 52Hertz protects

- The panel is private behind one owner password, server-side sessions and
  CSRF protection.
- Uploaded executable extensions are refused, covers are decoded and written
  again, and internal folders are denied by the bundled Apache rules.
- A fresh installation checks whether the database is accidentally public and
  warns when HTTPS or required server features are missing.

## What it does not protect

52Hertz is a publishing tool, not an anonymity system.

- The hosting company and its network may log the operator's and listeners'
  IP addresses. HTTPS protects traffic in transit but does not hide who is
  connecting from the host.
- Uploaded audio is published unchanged. Its embedded tags, comments, artwork
  or encoder metadata may identify its author. Remove sensitive metadata
  before uploading. New public media filenames are randomized, but that does
  not sanitize the bytes inside the file.
- The SQLite backup contains the password hash and private drafts. Keep it
  private. A complete backup also needs the `media/` folder.
- Anyone with filesystem or hosting-control-panel access can read or replace
  the station, reset its password, or inspect unpublished data.
- External links leave the player and may reveal a listener's IP address to
  another service, although the player sends no referrer header.
- A direct MP3 listener occupies one PHP request and one FFmpeg child. The
  application limits this to three connections per station; use an Icecast
  relay and web-server connection limits for a public audience or hostile
  traffic.

People facing targeted surveillance should use a threat model appropriate to
their situation, a host they trust, HTTPS, a separate publishing identity and
specialist operational-security advice. Do not describe a stock 52Hertz
installation as anonymous or safe against a powerful adversary.

## Deployment minimums

- Use HTTPS before entering the owner password.
- Confirm that `var/`, `lib/`, `tools/` and `tests/` return 403 or 404 over the
  public web. The installer checks `var/`; verify all four after server changes.
- On nginx or another server that ignores `.htaccess`, reproduce the deny,
  CORS and cache rules in the server configuration.
- Run PHP 8.3 or newer on a currently supported PHP branch, keep the web server patched, and take a database plus
  media backup before upgrades.
