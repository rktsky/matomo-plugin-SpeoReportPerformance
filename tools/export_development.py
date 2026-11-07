"""Explicit developer payload for the standalone plugin-root public repository."""
from pathlib import Path

from release_package import checked_file

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT if (ROOT / "plugin.json").is_file() else ROOT / "src" / "SpeoReportPerformance"

DEVELOPMENT_FILES = (
    "composer.json", "composer.lock", "phpstan.neon", "phpcs.xml.dist", ".gitignore",
    ".github/workflows/quality.yml", "tests/bootstrap.php",
    "tests/run.php", "tests/lint.php", "tests/analysis-bootstrap.php",
    "tests/download-matomo.py", "tools/build-release.py", "tools/validate-release.py",
    "tools/validate-marketplace.py", "tools/test-marketplace.py", "tools/marketplace_package.py",
    "tools/release_package.py", "tools/release-files.json", "tools/export_development.py",
    "MARKETPLACE.md", "SPONSORS.md",
)
FIXTURES = (
    "native-api-source-contract.php", "ui-numeric-page-cache.php", "ui-base-preloader.php",
    "public-prepared-flat.php", "native-api-json.php", "root-memory-contract-review.php",
    "future-version-contract.php", "warm-request-key.php", "warmup.php",
    "configuration.php", "warmup-process.php", "ui-pending-requests.php",
    "fixtures/FuturePagePerformanceMetric.php",
)


def development_payload() -> dict[str, bytes]:
    """Return only public tooling and active synthetic fixtures, never reports."""
    payload = {}
    standalone = SOURCE == ROOT
    for name in DEVELOPMENT_FILES:
        data = checked_file(ROOT, name).read_bytes()
        if not standalone and name == "phpstan.neon":
            data = data.replace(b"src/SpeoReportPerformance/tests", b"tests/Fixtures")
            data = data.replace(b"src/SpeoReportPerformance/autoload-for-private-tests.php", b"autoload-for-private-tests.php")
            data = data.replace(b"src/SpeoReportPerformance", b".")
            # The standalone source root also contains developer/vendor directories.
            data = data.replace(
                b"    bootstrapFiles:",
                b"            - vendor (?)\n            - dist (?)\n            - .matomo (?)\n            - lab (?)\n    bootstrapFiles:",
            )
        elif not standalone and name == "phpcs.xml.dist":
            data = data.replace(b"<file>src/SpeoReportPerformance</file>", b"<file>.</file>")
            data = data.replace(b"*/src/SpeoReportPerformance/tests/*", b"*/tests/Fixtures/*")
        payload[name] = data
    fixture_root = ROOT / "tests" / "Fixtures" if standalone else SOURCE / "tests"
    for name in FIXTURES:
        data = checked_file(fixture_root, name).read_bytes()
        if not standalone:
            # Only synthetic developer fixtures are transformed; runtime is byte-for-byte.
            data = data.replace(b"dirname(__DIR__)", b"SPEO_PLUGIN_ROOT")
            data = data.replace(
                b"dirname(__DIR__, 3) . '/tests/bootstrap.php'",
                b"dirname(__DIR__) . '/bootstrap.php'",
            )
        payload["tests/Fixtures/" + name] = data
    return payload
