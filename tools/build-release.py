#!/usr/bin/env python3
"""Build a clean, deterministic-enough 52Hertz release ZIP and checksum."""

from __future__ import annotations

import hashlib
from pathlib import Path, PurePosixPath
import re
import zipfile


ROOT = Path(__file__).resolve().parents[1]
DIST = ROOT / "dist"
VERSION_FILE = ROOT / "VERSION"
ZIP_TIME = (1980, 1, 1, 0, 0, 0)

EXCLUDED_TOP_LEVEL = {
    ".git",
    ".idea",
    ".vscode",
    ".github",
    "dist",
    "shots",
    "__pycache__",
}
RUNTIME_CONTROLS = {
    "var": {".htaccess"},
    "media": {".htaccess"},
}
EXCLUDED_SUFFIXES = {
    ".bak",
    ".db",
    ".log",
    ".pyc",
    ".sqlite",
    ".sqlite3",
    ".tmp",
}


def version() -> str:
    value = VERSION_FILE.read_text(encoding="utf-8").strip()
    if not re.fullmatch(r"\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?", value):
        raise SystemExit(f"VERSION is not semantic: {value!r}")

    bootstrap = (ROOT / "lib" / "bootstrap.php").read_text(encoding="utf-8")
    match = re.search(r"const PRODUCT_VERSION = '([^']+)';", bootstrap)
    if not match or match.group(1) != value:
        raise SystemExit("VERSION and PRODUCT_VERSION do not match")
    return value


def included(path: Path) -> bool:
    relative = path.relative_to(ROOT)
    parts = relative.parts
    if not parts or parts[0] in EXCLUDED_TOP_LEVEL:
        return False
    if path.is_symlink():
        return False
    if any(part in {"__pycache__", "node_modules"} for part in parts):
        return False
    if path.suffix.lower() in EXCLUDED_SUFFIXES:
        return False
    if parts[0] in RUNTIME_CONTROLS:
        return len(parts) == 2 and parts[1] in RUNTIME_CONTROLS[parts[0]]
    return True


def build() -> tuple[Path, Path, int]:
    release_version = version()
    DIST.mkdir(exist_ok=True)
    archive = DIST / f"52hertz-{release_version}.zip"
    checksum = archive.with_suffix(".zip.sha256")
    files = sorted(path for path in ROOT.rglob("*") if path.is_file() and included(path))

    with zipfile.ZipFile(archive, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as output:
        for source in files:
            relative = PurePosixPath(source.relative_to(ROOT).as_posix())
            info = zipfile.ZipInfo(str(PurePosixPath("52hertz") / relative), ZIP_TIME)
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = (0o100644 & 0xFFFF) << 16
            output.writestr(info, source.read_bytes())

    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    checksum.write_text(f"{digest}  {archive.name}\n", encoding="ascii")

    with zipfile.ZipFile(archive) as built:
        names = built.namelist()
    forbidden = [
        name for name in names
        if name.endswith(tuple(EXCLUDED_SUFFIXES))
        or "/shots/" in name
        or "/dist/" in name
        or re.search(r"/(?:var|media)/(?!(?:\.htaccess)$)", name)
    ]
    if forbidden:
        archive.unlink(missing_ok=True)
        checksum.unlink(missing_ok=True)
        raise SystemExit("release contains runtime/private files: " + ", ".join(forbidden))

    return archive, checksum, len(files)


if __name__ == "__main__":
    archive_path, checksum_path, count = build()
    print(f"Built {archive_path} ({count} files)")
    print(f"SHA-256: {checksum_path}")
