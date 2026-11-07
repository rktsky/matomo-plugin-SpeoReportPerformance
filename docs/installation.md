# Installation

## Before installation

Use Matomo On-Premise 5.14.2 or later within Matomo 5, PHP 8.4 or later and `zlib`. The plugin has no separate Composer runtime dependencies and does not install or tune Redis, PHP, MariaDB or the web server.

Configure a Matomo lazy cache backend before evaluating performance. The plugin uses Matomo's existing cache service; it does not create a separate Redis connection or require Redis credentials in its own configuration. A shared backend such as Redis lets web and console processes reuse the same cached bases. Retain Matomo's normal archive schedule.

Back up local configuration and the existing plugin directory when updating. Check the [compatibility notes](compatibility.md) and validate the target Matomo release in staging when it differs from the tested baseline.

## Install and activate

Extract the release so that the main class is at `plugins/SpeoReportPerformance/SpeoReportPerformance.php`. Keep the plugin files readable by the Matomo operating-system user. Install no development harnesses or production report fixtures on the public web path.

From the Matomo directory, as the same operating-system user that owns Matomo:

```sh
php console plugin:activate SpeoReportPerformance
php console speo:reports-diagnose
```

An administrator can also activate the plugin in Matomo's plugin administration. No schema migration or archive rewrite is performed by this plugin.

Open an eligible monthly page URL report. The first request creates a base; repeat the same request to measure reuse. Compare complete results, totals and permissions with the native report before relying on a new installation.

## Optional warmup

The warmup command rebuilds recently used neutral bases. It does not precompute every user's page or search. Run it with the same PHP executable, operating-system user and Matomo configuration as web requests:

```sh
php console speo:reports-warm
```

For scheduled execution, use absolute installation and PHP paths and your scheduler's non-overlap mechanism. A typical interval is once per minute, or after the existing archive job. Keep the archive job in place. The plugin has its own worker lock and bounded job/runtime limits; see [configuration](configuration.md).

Warmup stores a local target manifest and lock files in private storage outside the document root. Web and console workers must see the same storage location and be allowed to access it. On several application nodes, configure a protected shared path or run warmup separately for each node's recorded targets. The report cache can still be shared through Matomo's cache backend.

## Update

Replace only the plugin directory with a complete new release, preserving installation configuration outside that directory. Existing cache entries expire normally; implementation and runtime fingerprints separate incompatible entries. Run diagnostics and repeat the native comparison after the update.

## Deactivate and remove

```sh
php console plugin:deactivate SpeoReportPerformance
```

Deactivation restores Matomo's normal request processing. Remove only the scheduler entry added for this plugin. The main Matomo archive job remains necessary. After deactivation, remove the plugin directory and its private warmup directory if the plugin is no longer needed. Do not delete the shared Redis database or unrelated Matomo cache keys.
