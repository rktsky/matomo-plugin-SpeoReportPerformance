"""Offline checks for the documented Matomo Marketplace archive and README rules.

These checks model the public distribution contract, not the Marketplace service.
They deliberately do not contact Matomo or replace its manual plugin review.
"""

from __future__ import annotations

from html.parser import HTMLParser
import re
from urllib.parse import unquote, urlsplit

from release_package import safe_relative


# Matomo documents these exclusions at the plugin root. Nested directories with
# the same names remain part of a package unless explicitly excluded as well.
# https://developer.matomo.org/guides/distributing-your-plugin
DEFAULT_EXCLUDES = frozenset({
    ".github", "tests", "Test", "screenshots", ".gitattributes", ".gitignore",
})


def marketplace_archive_files(payload: dict[str, bytes], info: dict) -> set[str]:
    """Project a public source tree through Matomo's documented archive excludes."""
    archive = info.get("archive", {})
    if not isinstance(archive, dict):
        raise ValueError("Marketplace archive options must be an object")
    custom = archive.get("exclude", [])
    if not isinstance(custom, list) or not all(isinstance(item, str) for item in custom):
        raise ValueError("Marketplace archive.exclude must be a list of paths")
    exclusions = set(DEFAULT_EXCLUDES)
    for item in custom:
        if not item.startswith("/") or any(character in item for character in "*?[]"):
            raise ValueError(f"Marketplace exclusions require literal rooted paths: {item!r}")
        exclusions.add(safe_relative(item[1:]))
    for relative in payload:
        safe_relative(relative)
    return {
        relative for relative in payload
        if not any(relative == item or relative.startswith(item + "/") for item in exclusions)
    }


class ReadmePolicyParser(HTMLParser):
    """Collect renderable HTML image references and reject explicit sponsorship."""

    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.images: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        if tag == "script":
            raise ValueError("Marketplace README must not contain script elements")
        if tag == "img":
            source = attributes.get("src")
            if not source:
                raise ValueError("Marketplace README images need a local source")
            if attributes.get("srcset"):
                raise ValueError("Use a single local README image source instead of srcset")
            self.images.append(source)
        if tag == "a" and "sponsored" in (attributes.get("rel") or "").lower().split():
            raise ValueError("Marketplace README must not contain sponsored links")

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)


def validate_readme_policy(readme: str, source_paths: set[str]) -> None:
    """Keep rendered README images local and reject scripts/sponsored link markers.

    The policy gate recognises HTML images and Markdown inline/reference images.
    Sponsorship without an explicit marker still requires editorial review.
    """
    parser = ReadmePolicyParser()
    parser.feed(readme)
    parser.close()
    images = list(parser.images)
    inline = re.compile(r"!\[[^\]]*\]\(\s*(?:<([^>]+)>|([^\s)]+))", re.I)
    images.extend(match.group(1) or match.group(2) for match in inline.finditer(readme))
    definitions = {
        match.group(1).strip().casefold(): match.group(2) or match.group(3)
        for match in re.finditer(r"(?m)^\s*\[([^\]]+)\]:\s*(?:<([^>]+)>|(\S+))", readme)
    }
    for match in re.finditer(r"!\[([^\]]*)\](?:\[([^\]]*)\])?(?!\()", readme):
        reference = (match.group(2) or match.group(1)).strip().casefold()
        if reference in definitions:
            images.append(definitions[reference])
    if re.search(r"\[(?:[^\]]*\b(?:sponsored|affiliate)\b[^\]]*)\]\(", readme, re.I):
        raise ValueError("Marketplace README must not contain sponsored links")
    for source in images:
        parsed = urlsplit(source)
        if parsed.scheme or parsed.netloc or not parsed.path:
            raise ValueError(f"Marketplace README images must be bundled locally: {source!r}")
        relative = safe_relative(unquote(parsed.path))
        if relative not in source_paths:
            raise ValueError(f"Marketplace README image is absent from the public source: {relative}")


def validate_marketplace_payload(payload: dict[str, bytes], info: dict,
                                 release_paths: set[str]) -> set[str]:
    """Require Marketplace delivery to contain exactly the reviewed release files."""
    retained = marketplace_archive_files(payload, info)
    extra = sorted(retained - release_paths)
    missing = sorted(release_paths - retained)
    if extra or missing:
        raise ValueError(f"Marketplace archive differs from the release allowlist; extra={extra}, missing={missing}")
    validate_readme_policy(payload["README.md"].decode("utf-8"), set(payload))
    return retained
