# Configuration

Add installation-specific settings to Matomo's `config/config.ini.php` in a `[SpeoReportPerformance]` section. Do not edit the plugin's PHP files or Matomo's `global.ini.php`.

All settings are optional. The defaults are:

```ini
[SpeoReportPerformance]
cache_ttl = 86400
warmup_enabled = 1
warmup_memory_limit = "2G"
warmup_max_jobs = 3
warmup_max_seconds = 120
warmup_max_targets = 20
warmup_target_max_age = 604800
warmup_record_interval = 300
warmup_storage_path = ""
```

| Setting | Meaning and accepted values |
| --- | --- |
| `cache_ttl` | Cache retention in seconds, 60–604800. Archive revision checks determine freshness independently. |
| `warmup_enabled` | `1` records and processes neutral targets; `0` disables optional warmup. |
| `warmup_memory_limit` | Per-child PHP memory limit, 64–65536 MiB, written with `M` or `G` suffix. |
| `warmup_max_jobs` | Maximum sequential rebuild attempts per run, 1–20. Already warm or unsuitable targets do not consume a rebuild attempt. |
| `warmup_max_seconds` | Overall worker time budget in seconds, 1–900. |
| `warmup_max_targets` | Maximum recent targets inspected per run, 1–100. |
| `warmup_target_max_age` | Expiry after the last eligible foreground use, in seconds, 3600–2592000. |
| `warmup_record_interval` | Minimum interval between manifest refreshes for a target, in seconds, 1–3600. |
| `warmup_storage_path` | Empty for automatic private storage, or an absolute path outside Matomo's document root. |

Invalid numeric limits and memory settings use their defaults and produce fixed diagnostic reason codes. Invalid `warmup_enabled` values disable warmup. Invalid storage settings prevent warmup; ordinary report handling remains available.

## Cache backend

The plugin uses Matomo's configured lazy cache backend. It has no separate Redis endpoint or password setting. Configure and protect that backend through Matomo's normal cache configuration. A shared Redis cache is useful when reports run in several PHP processes or application nodes. Cache entries contain report data and need the same access controls and backup policy as Matomo reports.

## Private warmup storage

The automatic location is an installation-specific directory below the operating system's temporary directory, named `matomo-speo-report-performance-` plus an installation hash. It is outside the Matomo document root. Directories use mode `0700`; files use mode `0600`. Run web and warmup processes as the same operating-system user.

Temporary storage can be cleaned by the operating system. Services using systemd `PrivateTmp` or containers may have different temporary namespaces. In those cases, set one persistent private `warmup_storage_path` visible to both web and console workers. The path must be absolute, outside the resolved document root and have no symlink as its final component. Existing directories must be owned by the Matomo process user and inaccessible to other users. Diagnostics reject unsafe permissions.

The target manifest contains only neutral warmup request parameters and expiry information. Do not place it in a publicly served directory or share it between unrelated Matomo installations.

## Diagnostics

Run from the Matomo directory as its operating-system user:

```sh
php console speo:reports-diagnose
```

This local administrator command inspects capabilities, validated configuration, private-storage status and current-process counters. It does not fetch reports or print site identifiers, target identifiers, logins, authentication tokens or storage paths. Its counters describe only that console process; they are not a persistent performance dashboard.

Matomo's optional system checks also report whether background warmup has usable storage, file locking and process support. A warmup warning does not disable ordinary report requests.

## Measuring a change

Measure the same report, archive revision, login, filters and requested row count. Separate the first build from repeated requests, and distinguish server processing time from full browser loading time. Compare the complete report with native processing, including totals and presentation. Changing a limit or enabling Redis alone does not establish an end-user speedup.
