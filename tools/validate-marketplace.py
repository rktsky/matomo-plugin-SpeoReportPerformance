#!/usr/bin/env python3
"""Check Marketplace packaging offline without notifying or submitting to Matomo."""

import json
import subprocess

from marketplace_package import validate_marketplace_payload
from release_package import ROOT, SOURCE, checked_file, metadata, release_files, source_payload


def main() -> None:
    files = release_files()
    info = metadata(files)
    payload = source_payload(files)
    source_kind = "reviewed source export"
    if SOURCE == ROOT and (ROOT / ".git").exists():
        # Include tracked files and new, non-ignored source files. Otherwise a new
        # development file could bypass the explicit source-export allowlist and
        # still be shipped by the real Marketplace Git-tag build.
        result = subprocess.run(
            ["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"],
            cwd=ROOT, check=True, stdout=subprocess.PIPE,
        )
        names = set(result.stdout.decode("utf-8").rstrip("\0").split("\0"))
        actual = {relative: checked_file(ROOT, relative).read_bytes() for relative in names}
        if actual != payload:
            extra = sorted(set(actual) - set(payload))
            missing = sorted(set(payload) - set(actual))
            changed = sorted(name for name in set(actual) & set(payload) if actual[name] != payload[name])
            raise ValueError(f"Public repository differs from its source export; extra={extra}, missing={missing}, changed={changed}")
        payload = actual
        source_kind = "public Git source tree"
    retained = validate_marketplace_payload(payload, info, set(files))
    print(json.dumps({"status": "ok", "plugin": info["name"], "version": info["version"],
                      "source": source_kind, "source_files": len(payload),
                      "marketplace_files": len(retained)}, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError, subprocess.CalledProcessError) as error:
        raise SystemExit(f"Marketplace validation failed: {error}") from error
