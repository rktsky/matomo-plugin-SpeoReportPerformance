#!/usr/bin/env python3
"""Regression checks for Marketplace exclusion mistakes and README asset policy."""

import unittest

from marketplace_package import marketplace_archive_files, validate_marketplace_payload, validate_readme_policy


class MarketplacePackageTest(unittest.TestCase):
    def test_documented_defaults_apply_only_at_the_plugin_root(self) -> None:
        payload = {name: b"" for name in (
            "README.md", "plugin.json", "LICENSE", "docs/tests/example.md",
            ".github/workflows/quality.yml", "tests/fixture.php", "Test/fixture.php",
            "screenshots/example.png", ".gitattributes", ".gitignore", "tools/build.py",
        )}
        info = {"archive": {"exclude": ["/tools"]}}
        expected = {"README.md", "plugin.json", "LICENSE", "docs/tests/example.md"}
        self.assertEqual(expected, validate_marketplace_payload(payload, info, expected))

    def test_missing_development_exclusion_is_rejected(self) -> None:
        payload = {"README.md": b"", "plugin.json": b"{}", "tools/server.py": b"private tool"}
        with self.assertRaisesRegex(ValueError, "extra=.*tools/server.py"):
            validate_marketplace_payload(payload, {}, {"README.md", "plugin.json"})

    def test_excluding_a_required_runtime_file_is_rejected(self) -> None:
        payload = {"README.md": b"", "plugin.json": b"{}", "LICENSE": b"license"}
        with self.assertRaisesRegex(ValueError, "missing=.*LICENSE"):
            validate_marketplace_payload(payload, {"archive": {"exclude": ["/LICENSE"]}}, set(payload))

    def test_unsafe_or_wildcard_exclusion_paths_are_rejected(self) -> None:
        for exclusion in ("tools", "/tools/*", "/tools/?", "/tools/[ab]", "/../private", "/", "//tools", "/tools\\file"):
            with self.subTest(exclusion=exclusion), self.assertRaises(ValueError):
                marketplace_archive_files({}, {"archive": {"exclude": [exclusion]}})
        for exclusions in (None, "tools", [123]):
            with self.subTest(exclusions=exclusions), self.assertRaises(ValueError):
                marketplace_archive_files({}, {"archive": {"exclude": exclusions}})

    def test_remote_images_scripts_and_sponsored_links_are_rejected(self) -> None:
        for readme in (
            '<img src="https://example.org/badge.svg">',
            '![badge](https://example.org/badge.svg)',
            '![badge](<https://example.org/badge.svg>)',
            '![badge][quality]\n[quality]: https://example.org/badge.svg',
            '![quality]\n[quality]: //example.org/badge.svg',
            '<img src="//example.org/badge.svg">',
            '<script src="https://example.org/script.js"></script>',
            '<a href="https://example.org" rel="noopener SPONSORED">Link</a>',
            '[Sponsored by Example](https://example.org)',
        ):
            with self.subTest(readme=readme), self.assertRaises(ValueError):
                validate_readme_policy(readme, set())

    def test_bundled_images_and_ordinary_external_links_are_allowed(self) -> None:
        readme = ('![example](screenshots/example.png)\n'
                  '<img src="screenshots/example.png">\n'
                  '![screen][local]\n[local]: screenshots/example.png\n'
                  '<a href="https://example.org" target="_blank" rel="noopener noreferrer">Support</a>')
        validate_readme_policy(readme, {"screenshots/example.png"})
        with self.assertRaisesRegex(ValueError, "absent"):
            validate_readme_policy('![missing](screenshots/missing.png)', set())


if __name__ == "__main__":
    unittest.main()
