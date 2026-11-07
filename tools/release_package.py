"""Shared, offline validation for this plugin's explicit release manifest."""

from __future__ import annotations

import json
from pathlib import Path, PurePosixPath
import re
import stat
from urllib.parse import urlparse
import zipfile

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = "SpeoReportPerformance"
SOURCE = ROOT if (ROOT / "plugin.json").is_file() else ROOT / "src" / PLUGIN
MANIFEST = ROOT / "tools" / "release-files.json"

# Reject operating paths, remote root access and key material in portable files.
PRIVATE_PATTERNS = (
    rb"/var[/]www/",
    rb"ssh\s+root@",
    rb"-----BEGIN (?:OPENSSH |RSA )?PRIVATE KEY-----",
    # Connection URLs containing a user and password must never enter a release.
    rb"\b[a-z][a-z0-9+.-]*://[^\s/@:]+:[^\s/@]+@",
)


def safe_relative(value: str) -> str:
    """Reject ambiguous names before they become filesystem or ZIP paths."""
    path = PurePosixPath(value)
    if (not value or "\\" in value or path.is_absolute()
            or any(part in ("", ".", "..") for part in value.split("/"))
            or any(ord(character) < 32 for character in value)):
        raise ValueError(f"Unsafe release path: {value!r}")
    return value


def checked_file(base: Path, relative: str) -> Path:
    """Do not follow symlinks, including symlinked parent directories."""
    safe_relative(relative)
    candidate = ROOT
    base_relative = base.relative_to(ROOT)
    for component in (*base_relative.parts, *PurePosixPath(relative).parts):
        candidate = candidate / component
        if candidate.is_symlink():
            raise ValueError(f"Symlink is not allowed in a release input: {candidate}")
    if not candidate.is_file():
        raise ValueError(f"Missing release input: {candidate}")
    if not candidate.resolve().is_relative_to(ROOT):
        raise ValueError(f"Release input is outside the workspace: {candidate}")
    return candidate


def release_files() -> dict[str, Path]:
    manifest_path = checked_file(ROOT, MANIFEST.relative_to(ROOT).as_posix())
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    if set(manifest) != {"plugin", "runtime", "documentation"} or manifest["plugin"] != PLUGIN:
        raise ValueError("Unexpected release manifest schema")
    result: dict[str, Path] = {}
    for group, base in (("runtime", SOURCE), ("documentation", ROOT)):
        entries = manifest[group]
        if not isinstance(entries, list) or not all(isinstance(item, str) for item in entries):
            raise ValueError(f"Invalid manifest group: {group}")
        for relative in entries:
            safe_relative(relative)
            if relative in result:
                raise ValueError(f"Duplicate release input: {relative}")
            result[relative] = checked_file(base, relative)
    required = {"plugin.json", "SpeoReportPerformance.php", "README.md", "LICENSE", "NOTICE", "CHANGELOG.md"}
    if not required.issubset(result):
        raise ValueError("Required package files are missing from the manifest")
    for relative, path in result.items():
        if any(part in {"tests", "tools", "vendor", ".git", "fixtures", "lab"}
               for part in PurePosixPath(relative).parts):
            raise ValueError(f"Development or private path in release: {relative}")
        validate_public_content(relative, path.read_bytes())
    return dict(sorted(result.items()))


def validate_public_content(relative: str, data: bytes) -> None:
    for pattern in PRIVATE_PATTERNS:
        if re.search(pattern, data, re.I):
            raise ValueError(f"Private deployment data or key material in {relative}")


def source_payload(files: dict[str, Path]) -> dict[str, bytes]:
    from export_development import development_payload

    payload = {relative: path.read_bytes() for relative, path in files.items()}
    for relative, data in development_payload().items():
        safe_relative(relative)
        if relative in payload:
            raise ValueError(f"Duplicate source export file: {relative}")
        validate_public_content(relative, data)
        payload[relative] = data
    return dict(sorted(payload.items()))


def metadata(files: dict[str, Path]) -> dict:
    value = json.loads(files["plugin.json"].read_text(encoding="utf-8"))
    required = {"name", "version", "description", "keywords", "license", "homepage", "authors", "require"}
    if not isinstance(value, dict) or not required.issubset(value):
        raise ValueError("Required Marketplace metadata is missing")
    if value["name"] != PLUGIN or not re.fullmatch(r"[A-Z][A-Za-z0-9]{0,59}", value["name"]):
        raise ValueError("Invalid plugin name")
    version = value["version"]
    if not isinstance(version, str) or not re.fullmatch(r"(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?", version):
        raise ValueError("Invalid semantic release version")
    if not isinstance(value["description"], str) or not 1 <= len(value["description"]) <= 150:
        raise ValueError("Plugin description must contain 1–150 characters")
    keywords = value["keywords"]
    if not isinstance(keywords, list) or not keywords or not all(isinstance(item, str) and re.fullmatch(r"[A-Za-z0-9.-]+", item) for item in keywords):
        raise ValueError("Invalid Marketplace keywords")
    if value["license"] != "GPL-3.0+":
        raise ValueError("Expected the declared GPL-3.0+ license identifier")
    homepage = urlparse(value["homepage"])
    if homepage.scheme != "https" or not homepage.hostname or homepage.username or homepage.password:
        raise ValueError("Expected a public HTTPS homepage without credentials")
    if not isinstance(value["authors"], list) or not value["authors"] or not all(isinstance(author, dict) and author.get("name") for author in value["authors"]):
        raise ValueError("At least one named author is required")
    requirements = value["require"]
    if not isinstance(requirements, dict) or set(requirements) != {"matomo", "php"}:
        raise ValueError("Expected Matomo and PHP requirements")
    if not re.search(r"(?:^|,)\s*>=?\d", requirements["matomo"]) or not re.search(r"(?:^|,)\s*<=?\d", requirements["matomo"]):
        raise ValueError("Matomo requires explicit lower and upper compatibility bounds")
    if value.get("wordpress-compatible") is not False or value.get("onpremise-compatible") is not True:
        raise ValueError("Expected the tested On-Premise deployment declaration")
    if "## Description\n" not in files["README.md"].read_text(encoding="utf-8"):
        raise ValueError("README.md needs the Marketplace Description section")
    if f"## {version} " not in files["CHANGELOG.md"].read_text(encoding="utf-8"):
        raise ValueError("The changelog does not contain the current release")
    return value


def validate_archive(archive_path: Path, files: dict[str, Path]) -> None:
    """Require an exact allowlist match, with no links or foreign payloads."""
    if archive_path.is_symlink():
        raise ValueError("The release archive must not be a symlink")
    expected = {f"{PLUGIN}/{relative}": path for relative, path in files.items()}
    with zipfile.ZipFile(archive_path) as archive:
        entries = archive.infolist()
        names = [entry.filename for entry in entries]
        if len(names) != len(set(names)) or set(names) != set(expected):
            raise ValueError("ZIP file names do not match the release allowlist")
        for entry in entries:
            safe_relative(entry.filename)
            if stat.S_ISLNK(entry.external_attr >> 16) or entry.is_dir():
                raise ValueError(f"Unexpected link or directory entry: {entry.filename}")
            if archive.read(entry) != expected[entry.filename].read_bytes():
                raise ValueError(f"ZIP payload differs from the reviewed input: {entry.filename}")


def validate_export(export_path: Path, payload: dict[str, bytes]) -> None:
    if export_path.is_symlink():
        raise ValueError("The source export must not be a symlink")
    actual = set()
    for path in export_path.rglob("*"):
        if path.is_symlink():
            raise ValueError(f"Source export contains a symlink: {path}")
        if path.is_file():
            actual.add(path.relative_to(export_path).as_posix())
    if actual != set(payload):
        raise ValueError("Source export files do not match the release allowlist")
    for relative, data in payload.items():
        if checked_file(export_path, relative).read_bytes() != data:
            raise ValueError(f"Source export payload differs: {relative}")
