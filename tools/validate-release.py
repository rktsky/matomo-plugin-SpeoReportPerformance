#!/usr/bin/env python3
"""Validate metadata, allowlisted payloads and the current release checksum."""

import argparse
import hashlib
import json
from pathlib import Path
import zipfile

from release_package import PLUGIN, ROOT, metadata, release_files, source_payload, validate_archive, validate_export


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--archive", type=Path, help="Validate a particular ZIP instead of the current release")
    parser.add_argument("--source-only", action="store_true", help="Check release inputs without requiring a built ZIP")
    arguments = parser.parse_args()
    files = release_files()
    info = metadata(files)
    result = {"plugin": PLUGIN, "version": info["version"], "files": len(files)}
    if not arguments.source_only:
        archive = arguments.archive or ROOT / "dist" / f"{PLUGIN}-{info['version']}.zip"
        validate_archive(archive, files)
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        checksum = archive.with_suffix(".zip.sha256")
        expected = f"{digest}  {archive.name}\n"
        if checksum.is_symlink() or checksum.read_text(encoding="utf-8") != expected:
            raise ValueError("Release SHA-256 sidecar is missing or does not match")
        if arguments.archive is None:
            payload = source_payload(files)
            validate_export(ROOT / "dist" / "marketplace-source" / PLUGIN, payload)
            result["source_files"] = len(payload)
        result.update({"archive": archive.name, "sha256": digest})
    print(json.dumps({"status": "ok", **result}, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, zipfile.BadZipFile) as error:
        raise SystemExit(f"Release validation failed: {error}") from error
