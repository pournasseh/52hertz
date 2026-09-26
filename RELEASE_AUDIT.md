# 52Hertz 1.0.0-rc.3 release audit

Audited candidate: **1.0.0-rc.3** (2026-09-26).

This repository is suitable for release-candidate publication. The hardening pass covered owner/session/CSRF boundaries, cross-station mutations, file/database atomicity, upload and media-path handling, public endpoint methods, ICY metadata, deployment rules, deterministic clock behavior, release hygiene, and documentation claims.

## Automated proof on the audited source

- schedule domain: 46/46
- programme domain: 7/7
- public HTTP-method/header checks: 10/10
- all 26 shipped PHP files: syntax linted
- shipped JavaScript/MJS and JSON: syntax checked
- release archive: deterministic and inspected for runtime/private state

The local audit workstation lacked `pdo_sqlite`, `mbstring`, and `gd`, so PHP-backed pipeline/parity/media suites were not falsely claimed as locally executed. Repository CI installs those extensions on PHP 8.3 and runs the complete Full suite.

## Still manual before calling it production-stable

- production-like Apache/nginx installation and deny/CORS/range/ETag checks
- physical iOS and Android background/sleep/offline/PWA testing
- optional FFmpeg/Icecast boundary and child-process cleanup test
- disposable backup + media restore drill

See [RELEASING.md](RELEASING.md) and [SECURITY.md](SECURITY.md).
