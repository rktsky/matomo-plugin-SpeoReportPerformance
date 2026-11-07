#!/usr/bin/env python3
"""Build a deterministic installable ZIP and a clean plugin-root source export."""

import hashlib
import json
from pathlib import Path
import shutil
import tempfile
import zipfile

from release_package import PLUGIN, ROOT, metadata, release_files, source_payload, validate_archive, validate_export


def main() -> None:
    files = release_files()
    info = metadata(files)
    payload = source_payload(files)
    target = ROOT / "dist"
    if target.is_symlink():
        raise ValueError("The release output directory must not be a symlink")
    target.mkdir(exist_ok=True)
    output = target / f"{PLUGIN}-{info['version']}.zip"
    checksum = output.with_suffix(".zip.sha256")
    if output.is_symlink() or checksum.is_symlink():
        raise ValueError("Release output files must not be symlinks")

    # ZIP timestamps, permissions and ordering must not depend on local checkout state.
    with tempfile.TemporaryDirectory(prefix=".speo-release-", dir=target) as temporary:
        staged = Path(temporary) / output.name
        with zipfile.ZipFile(staged, "w", zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
            for relative, path in files.items():
                entry = zipfile.ZipInfo(f"{PLUGIN}/{relative}", (1980, 1, 1, 0, 0, 0))
                entry.create_system = 3
                entry.external_attr = 0o100644 << 16
                entry.compress_type = zipfile.ZIP_DEFLATED
                archive.writestr(entry, path.read_bytes(), compresslevel=9)
        validate_archive(staged, files)
        staged.replace(output)

    export_parent = target / "marketplace-source"
    if export_parent.is_symlink():
        raise ValueError("The source export parent must not be a symlink")
    export_parent.mkdir(exist_ok=True)
    export = export_parent / PLUGIN
    if (export / ".git").exists():
        raise ValueError("Refusing to replace a source export that has become a Git checkout")
    if export.is_symlink() or (export.exists() and any(path.is_symlink() for path in export.rglob("*"))):
        raise ValueError("The source export must not contain symlinks")
    with tempfile.TemporaryDirectory(prefix=".speo-source-", dir=export_parent) as temporary:
        staged_export = Path(temporary) / PLUGIN
        staged_export.mkdir()
        for relative, data in payload.items():
            destination = staged_export / relative
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(data)
            destination.chmod(0o644)
        validate_export(staged_export, payload)
        if export.exists():
            shutil.rmtree(export)
        staged_export.replace(export)

    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    checksum.write_text(f"{digest}  {output.name}\n", encoding="utf-8")
    print(json.dumps({"archive": str(output), "sha256": digest, "files": len(files),
                      "source_export": str(export), "source_files": len(payload)}, sort_keys=True))


if __name__ == "__main__":
    try:
        main()
    except (OSError, ValueError, KeyError, TypeError) as error:
        raise SystemExit(f"Release build failed: {error}") from error
