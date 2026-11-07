#!/usr/bin/env python3
"""Fetch an original release for fixtures; never install or patch Matomo."""
from __future__ import annotations

import argparse
import hashlib
import re
import urllib.request
import zipfile
from pathlib import Path

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("version", help="Exact official release, or latest5 for the newest stable 5.x release")
parser.add_argument("destination", type=Path)
args = parser.parse_args()
version = args.version
if version == "latest5":
    with urllib.request.urlopen("https://builds.matomo.org/", timeout=60) as response:
        index = response.read().decode("utf-8")
    versions = set(re.findall(r'href="matomo-(5\.[0-9]+\.[0-9]+)\.zip"', index))
    if not versions:
        raise SystemExit("Official Matomo build index contains no stable 5.x release")
    version = max(versions, key=lambda value: tuple(map(int, value.split("."))))
if not re.fullmatch(r"5\.[0-9]+\.[0-9]+", version):
    raise SystemExit("Only stable supported Matomo 5 releases are accepted")
args.destination.mkdir(parents=True, exist_ok=False)
archive = args.destination / f"matomo-{version}.zip"
url = f"https://builds.matomo.org/matomo-{version}.zip"
with urllib.request.urlopen(url, timeout=120) as response, archive.open("wb") as target:
    while block := response.read(1024 * 1024):
        target.write(block)
with zipfile.ZipFile(archive) as source:
    for member in source.infolist():
        path = Path(member.filename)
        if path.is_absolute() or ".." in path.parts or not path.parts:
            raise SystemExit("Unexpected archive member")
    source.extractall(args.destination)
root = args.destination / "matomo"
if not (root / "vendor/autoload.php").is_file():
    raise SystemExit("Distribution does not contain Matomo's original vendor autoloader")
digest = hashlib.sha256(archive.read_bytes()).hexdigest()
(args.destination / "distribution.txt").write_text(f"version={version}\nurl={url}\nsha256={digest}\n")
print(root.resolve())
