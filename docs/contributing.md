# Contributing

Keep changes within the plugin and documented installation configuration. Do not patch Matomo, vendor files, PHP packages or the runtime class registry. Add an adapter at an explicit boundary when a stable public API is unavailable, explain the dependency and verify it against Matomo's actual implementation.

Use English identifiers, comments and documentation. Comments should explain why an invariant exists, especially permissions, revision checks, serialization limits, ownership of tables and callback ordering. Avoid restating each line of code.

Before changing the cache path, add a synthetic regression case and compare the complete result with native Matomo processing. Test denied access, changed archive markers, unsupported shapes and callback extensions. Use generated data for committed fixtures. Real customer reports, labels, URLs, HTML, logins and tokens stay outside this repository. Follow [testing](testing.md).

Configuration changes need documented defaults and bounds. A new cache representation must change its schema/implementation namespace. Do not broaden eligibility or drop totals to obtain a faster benchmark.

Build and validate the release with Python 3.10 or later after tests pass:

```sh
python3 tools/build-release.py
python3 tools/validate-release.py
```

Review `tools/release-files.json` whenever a runtime component is added or removed. Development dependencies and test output must stay outside the installable package. Preserve the packaged `LICENSE` and `NOTICE` when modifying adapted code.

This repository uses Matomo's plugin-root layout and includes its active synthetic tests, development configuration and CI workflow. Fork the repository, work on a separate branch and submit a pull request with the relevant validation results. Keep the installable ZIP limited to the files in the release manifest.

Before publishing a release tag, build and validate its package and review the native comparison results. Matomo Marketplace registration requires its own listing and webhook configuration; use the actual public repository and current support channel in its metadata.
