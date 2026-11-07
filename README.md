# SpeoReportPerformance

<a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/actions/workflows/quality.yml" target="_blank" rel="noopener noreferrer">Plugin quality workflow</a>

## Description

SpeoReportPerformance reduces repeated work when opening large, flat monthly page URL reports in Matomo On-Premise. It caches a neutral flat report base and selected report pages, then lets Matomo calculate the current presentation.

The accelerated path applies to a single-site `Actions.getPageUrls` request with `period=month`, `flat=1`, no segment and a requested limit of 1–1000 rows. Other reports, unsupported request shapes and full exports use Matomo's normal processing. The plugin does not shorten a report to meet a time target.

Access is checked on every request. Cache entries follow the archive's publication and invalidation markers; user-specific pages are separated by login. No Matomo core or vendor file is changed.

The plugin is used in production at Speo. It requires Matomo On-Premise 5.14.2 or later within Matomo 5 and PHP 8.4 or later. Redis can share cached work between PHP processes through Matomo's existing cache backend.

## Requirements

- Matomo On-Premise 5.14.2 or later within Matomo 5.
- PHP 8.4 or later, with the extensions required by Matomo and `zlib`.
- A working Matomo lazy cache backend. Redis is recommended for sharing cached work between PHP processes.
- The database user must be able to read Matomo's numeric archive tables, as in a normal Matomo installation.

Matomo 5.14.2 with PHP 8.4.27 is the validated baseline. The compatibility declaration does not claim that every later release has been tested. See [compatibility](docs/compatibility.md) for the tested matrix and adapter boundaries.

## Installation

Install the `SpeoReportPerformance` directory under Matomo's `plugins/` directory, then activate it through Matomo's plugin administration or `console plugin:activate SpeoReportPerformance` as the Matomo operating-system user. See [installation](docs/installation.md) for cache, warmup and removal instructions.

The first eligible request for a new archive revision builds the base. Optional background warmup can rebuild recently used bases after archiving. A first request for a new user, page or search may still need more work. Runtime depends on report size, server resources and the other installed plugins.

## Documentation

- [Installation and removal](docs/installation.md)
- [Frequently asked questions](docs/faq.md)
- [Configuration and diagnostics](docs/configuration.md)
- [Architecture and cache lifecycle](docs/architecture.md)
- [Compatibility](docs/compatibility.md)
- [Testing](docs/testing.md)
- [Contributing](docs/contributing.md)
- [Security and report data](docs/security.md)

## Source and support

The public source repository is <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance" target="_blank" rel="noopener noreferrer">rktsky/matomo-plugin-SpeoReportPerformance</a>. Report reproducible bugs with synthetic data in the <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/issues" target="_blank" rel="noopener noreferrer">issue tracker</a>. Follow [security guidance](docs/security.md) for private reports.

## License

GNU General Public License, version 3 or later. See [LICENSE](LICENSE) and the upstream attribution in [NOTICE](NOTICE).

Created by <a href="https://www.rocketsky.ch/" target="_blank" rel="noopener noreferrer">Rocketsky GmbH</a>.

## Development

The source distribution includes synthetic contract fixtures, Composer development tools and a CI workflow. Start with [testing](docs/testing.md) and [contributing](docs/contributing.md). No development dependencies or fixtures are included in the installable ZIP.
