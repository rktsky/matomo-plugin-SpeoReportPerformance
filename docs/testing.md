# Developer validation

Use PHP 8.4 or 8.5, Composer 2, Python 3.10+ and an **original extracted Matomo 5 distribution** with its `vendor` directory. No Matomo installation, database, Redis server or customer configuration is needed. Developer dependencies belong to this repository; none are shipped in the plugin ZIP.

```sh
composer install
python3 tests/download-matomo.py 5.14.2 /tmp/speo-matomo
export MATOMO_ROOT=/tmp/speo-matomo/matomo
composer lint
composer style
composer analyse
```

`composer test` runs every active fixture in a separate PHP process because their API, access, cache and console doubles intentionally have the same class names as native services. Fixtures use real upstream `DataTable`, filters, rendering and console classes, with a new empty Matomo user directory for each process. They never load Matomo's application bootstrap, connect to a database, request a customer report or use customer Redis data.

Tests require both `SPEO_TEST_ISOLATED=1` and operating-system network isolation. The flag alone does not bypass the check. On Linux, run:

```sh
sudo --preserve-env=MATOMO_ROOT env SPEO_TEST_ISOLATED=1 unshare --net -- composer test
```

For an existing PHP developer container with the distribution and dependencies already available, use `docker run --network none`, mount the Matomo directory read-only and set `MATOMO_ROOT` and `SPEO_TEST_ISOLATED=1`. The guard accepts an offline container only when its only network interface is loopback. Dependencies and Matomo must be downloaded before disabling networking. On macOS, use such a Linux container; the fixtures deliberately do not run unisolated on the host.

The runner prints the private output directory and writes a summary, fixture stdout/stderr and detailed JSON reports there. Set `SPEO_TEST_OUTPUT` to choose another private directory. Generated user/runtime state and reports are never written into plugin source. Keep original Matomo sources read-only when running the tests.

## What is checked

- Flat/tree conversion against native Matomo filters, typed primitive values, metadata, summaries and sparse row identifiers.
- Native JSON/cache round trips, malformed-cache rejection before native allocation and callback rejection.
- UI eligibility, request construction, pagination, sorting, cache isolation, metrics and ownership of tables.
- Cheap constructor staging, exact dispatch binding of identical views, unused temporary preference views, parameter restoration and one native performance-metric definition set at dispatch.
- Warm request key equivalence and warmup helper/console handling with backend and process doubles.
- Public API signatures and callback contracts against the **actual distribution loaded by this run**.
- Synthetic future-version arguments check that request policy has no exact-version gate. These cases do not claim to test other installed Matomo releases.

The fixtures are contract tests rather than end-to-end dashboard tests. Access/database/Redis doubles cannot establish that a production installation is configured correctly, or measure a live report's latency. Test the release on a representative staging installation before rollout.

## Maintained compatibility checks

Validation on 7 November 2026 exercised all thirteen fixture processes in both development layouts with original Matomo 5.14.2 and PHP 8.4.27. Current GitHub executions and logs are available in the <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/actions/workflows/quality.yml" target="_blank" rel="noopener noreferrer">Plugin quality workflow</a>. Its matrix results must be read independently of this local preparation baseline.

GitHub Actions downloads upstream Matomo unchanged and runs the contracts with PHP 8.4 and 8.5, both on the minimum release 5.14.2 and the newest stable 5.x release discovered from the <a href="https://builds.matomo.org/" target="_blank" rel="noopener noreferrer">official build index</a>. Weekly runs catch later Matomo minor/patch releases without exact-version pins. When those two selections resolve to the same release, the matrix still makes that overlap explicit; it does not imply that two releases were exercised. A future major release needs its own compatibility review.

PHPStan checks all distributed runtime classes at level 1, including undefined symbols, unknown methods and possibly undefined variables, with upstream types autoloaded from `MATOMO_ROOT`. There is no blanket error ignore or generated baseline. PSR-12 errors are enforced for runtime and developer tooling; its soft line-length warnings remain advisory. Executable contract fixture scripts retain their compact test layout. `composer check` combines lint, style, analysis and the isolated contract suite. CI also validates the release archive and source export.

The native fixture approach is independent of Matomo's own integration-test database setup. See the <a href="https://developer.matomo.org/guides/tests-php" target="_blank" rel="noopener noreferrer">Matomo PHP testing guide</a> for upstream test categories and infrastructure.
