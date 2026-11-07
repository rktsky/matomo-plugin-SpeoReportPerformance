# Frequently asked questions

## Which reports can it speed up?

The accelerated path handles one site's flat monthly page URL report (`Actions.getPageUrls`, `period=month`, `flat=1`), without a segment and with a requested limit of 1–1000 rows. Other report shapes, full exports and unsupported extension combinations use Matomo's normal processing. The plugin does not truncate report data to make a request faster.

## Is the first request faster too?

A new archive revision needs a new report base. A new user, page or search may also need presentation work before it can be reused. Optional <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/blob/main/docs/installation.md#optional-warmup" target="_blank" rel="noopener noreferrer">warmup</a> rebuilds recently used neutral bases; it does not precompute every request. Measure the same report before and after caching rather than expecting a fixed loading time.

## Do I have to install Redis?

The plugin uses Matomo's configured lazy cache backend and has no Redis credentials of its own. Redis is recommended when several PHP processes or application nodes need to reuse cached reports. Configure and protect it through Matomo; the plugin does not install or tune a cache server. See <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/blob/main/docs/configuration.md#cache-backend" target="_blank" rel="noopener noreferrer">configuration</a>.

## Are permissions and report data protected?

The current user's site permission and Matomo's retention decision are checked before a foreground cache read. Finished pages are separated by login; revision checks prevent reuse after normal archive publication or invalidation. Protect cache storage and backups as report data. The plugin sends no telemetry. See <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/blob/main/docs/security.md" target="_blank" rel="noopener noreferrer">security and report data</a>.

## Can I stop normal Matomo archiving?

No. The plugin reuses archived reports and needs Matomo's normal archive schedule. Its optional warmup command complements that schedule. It does not replace archiving, rewrite archives or migrate the database.

## Does it modify Matomo or require a particular deployment type?

No core or vendor file is changed. The supported deployment is Matomo On-Premise 5.14.2 or later within Matomo 5, with PHP 8.4 or later and Matomo's required extensions plus `zlib`. Matomo for WordPress and Matomo 6 are not declared supported. Remaining native implementation dependencies are disclosed in <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/blob/main/docs/compatibility.md" target="_blank" rel="noopener noreferrer">compatibility</a>; this is not a claim of complete independence from Matomo internals.

## How do I report an issue?

Use the <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/issues" target="_blank" rel="noopener noreferrer">GitHub issue tracker</a> with synthetic data and the affected Matomo, PHP and plugin versions. Follow the <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/blob/main/docs/security.md#reporting-a-concern" target="_blank" rel="noopener noreferrer">private security reporting instructions</a> for security concerns or identifying report data. Never upload customer reports, tokens or cache contents to a public issue.
