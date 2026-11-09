# Report-copy lifecycle and finite production-admission candidate

This is a review candidate, tested only with synthetic data in an isolated original Matomo installation. No productive SQL approval, destructive setting, cache deletion, importer deactivation or installed production package changed during this work. `activeErasureVersion` remains0. A source candidate and a valid production operating receipt are separate facts.

## Exact components

SpeoControl0.6.6 adds `ActiveReportCopies` and `ActiveErasureAdmission`. SpeoReportPerformance1.3.0 adds `CopyLifecycle`; its original32 PHP files derive from reviewed source74e8c37. Original Matomo5.14.2, PHP8.4.27 and MariaDB10.11.19 are the actual tested engine. The fixture uses original Redis7.4.12 with PHPRedis6.3.0; production's physical Redis store is separately observed below.

Own public events carry version1 readiness and exact-property eviction DTOs. Readiness binds actual native cache backend types, root, host, prefix, private lock directory, original cache configuration, own runtime source and the exact historical-copy baseline digest. It does not confuse CLI's development-array backend with the configured Redis/File chain. Missing/old/inactive known Performance code or an unverified baseline prevents erasure admission.

Each new completed-page, neutral-base or numeric-page snapshot is indexed through the original nonautoload Option API before native `Cache::save`. The index contains only exact own logical keys and positive native property IDs, at most20,000 keys/4MiB. Capacity or corruption declines optional cache publication; it never drops ownership metadata to make room. A repeated identical key/property returns under its existing index lease without an Option write/readback. The actual native query profiler confirms zero Option DML for that repeat. Ordinary cache loads and warmup hints do not scan the full index.

Publication and neutral warmup recording hold the own shared `copy-writers.lock`. Exact-property erasure obtains its exclusive side while the original Control account/site/native EX leases remain held. It writes a permanent per-property own Option seal before evicting snapshots, then removes only matching warmup targets under the existing manifest lock. Unrelated targets, including expired entries, are preserved. Index membership survives uncertain effects and is removed only after original cache absence checks. The same original cancellation/operation resumes the journal.

The original Chained/File backend can skip deleting an expired file because `contains()` is already false. Therefore the exact owned key is overwritten with an empty live value, deleted through the same original native cache, then independently checked absent. This is not a global cache flush, arbitrary key deletion or payload decoder. Redis OOM is a real tested failure: completion remains pending and the physical-property seal remains effective. After actual cache recovery the same original operation completes.

`ReportCache::saveBaseAfterResponse` can run after the ordinary API end event. The native test first proves a real delayed PHP-shutdown publication succeeds, using an original monthly archive and the existing canonical flat-base adapter. A second already-prepared publisher is delayed across actual erasure; it cannot recreate the sealed report afterwards.

## Existing UI pipeline remains closed

`NativeSourceGuard.php` is byte-identical to74e8c37. SpeoControl's existing additional API end handlers continue to decline the optional snapshot pipeline, exactly as on production. This release does not newly enable an unreviewed cache read path, broaden user history or claim new UI acceleration. The real original `ViewDataTable\Factory::build()` renders an unrelated report and refuses the erased property through the original API/property guard. A direct canonical-base publication test is not described as an automatically enabled UI snapshot pipeline.

The manifest/filter unit suites still use explicitly labelled own publication doubles, as they do not bootstrap native storage. Those suites are regression coverage for their own isolated responsibilities; they are not the positive lifecycle proof. Actual storage, archive production, independent readers, publisher race and recovery use the original application and engines.

## Historical unindexed copies

An empty new index does not prove historical snapshots absent. `CopyLifecycle::applyReviewedLegacyBaseline()` requires CLI/superuser, actual Control EX and own publisher/index EX, current backend/source hashes, a complete finite separately reviewed scan, drained old publishers, a review reference and an observation at most600 seconds old. It accepts only the existing own revision/base/numeric key grammar. At most20,000 exact keys are overwritten empty, deleted and checked absent through original `Cache::getLazyCache()`; no GET of the old content and no global flush is used.

Only after all supplied keys are absent is the source/store-bound baseline written and read back. Existing key membership and permanent seals survive. Five real native negative cases—foreign key, wrong source, incomplete scan, undrained old publisher and stale scan—refuse before touching the two positive synthetic legacy copies. A separate original process observes both absent after success and an unrelated original cache value survives.

Production was re-observed on9November2026 at16:27–16:33UTC: native development is false; the actual DI backend is Chained(ArrayCache,Redis), Redis Unix socket `/run/speo-cache/redis.sock`, database14. A read-only namespace scan found exactly two own snapshot keys with remaining TTLs near77,000 seconds; it fetched no report payload. Native Redis `save` is empty and `appendonly=no`; no Redis snapshot is enabled by that configuration. This supersedes the earlier unsuccessful TCP6379 observation, which was not the configured transport.

The complete logical/physical-key/TTL mapping remains private. Its reviewed inventory SHA-256 is `9b0232f4cfa6e166ba719b7ce7c29b514a7de93ad60846d57559996c4c70d0e1`. It is an observation, not an evergreen two-key assumption. The actual cutover must drain the old publishers, freshly repeat the same complete own namespace inventory and bind its then-current digest before exact cleanup. No productive cache operation was run here.

## Default-closed productive CLI review path

The original native console command is `speocontrol:active-erasure`, read-only by default. Its historical `-isolated` alias remains compatible for isolated tests and explicitly refuses the productive mode. A private intent names exactly account/site/cancellation/full-policy hash/operation UUID; no wildcard, inferred site or retry date is accepted.

`active_erasure_mode=production-v1` does not authorize deletion. Each phase also needs `active_erasure_production_file`, an existing local regular mode0600 owner-matching file outside the webroot. The independently supplied receipt must bind:

- exact installed runtime/original manifest/host/plugin inventory and actual copy source/backend/baseline;
- a nonempty unique finite list of the exact current ended property scopes, including immutable cancellation, native metadata/source history, full entitlement, current LegalHold status and operation UUID;
- explicit productive archive, captured-Bot and three raw-count SQL scope strings, distinct from all isolated approvals;
- original direct/Bulk/owned worker/API/CLI archive exclusion, drained original purgers and drained/deactivated legacy importer;
- an actual reviewed original-source inventory digest and completed source-bound backup/restore receipt with archive/receipt hashes and table count;
- an explicit approval UUID/reference/time, expiry no more than24hours later, analytics-only data class and exclusion of financial records.

GoogleAnalyticsImporter or QueuedTracking still activated closes this path. Missing, expired or changed evidence closes admission before a permanent seal. Legal holds are also checked directly against the actual native-property registry before and throughout effects; an elapsed review date never releases a hold. The positive native fixture uses explicitly synthetic operator/recovery evidence to test exact binding, not to fabricate a real backup or productive user approval.

Original30-Zurich-calendar-day export rights must already be closed. Existing native visit deletion, the captured observed Bot set, bounded exact archive statements and three independent raw-family counts retain their previous proven scope. A real original orphan still stops completion; no new orphan DELETE was added. Financial records, accepted legal documents, shared native users, backups and other sites stay outside this eraser.

A permanent physical seal continues to block native report/recording/aggregation paths even when the destructive mode is switched off. Do not change the installation identity/role or downgrade away these guards after a seal. A failed or expired receipt permits no new destructive phase; it does not release already sealed data. The API capability remains0 pending final independent production review; no scheduler or remote API starts this command.

## Actual verification boundaries

The new private original installation uses its own database/prefix, original installer, separate original archive producer and original DataTables. All11,887 files from the official original Matomo ZIP compare byte-for-byte unchanged. Own test archiver files stay outside release packages.

Native proof logs and exact final package hashes are listed in the final review receipt. Completed runs include the real original49-case admission/copy/UI/Redis-OOM/shutdown journey,84 full native raw/Bot/archive/batch/recovery contracts,12 actual original orphan refusal cases and8 original legacy-key cleanup checks. The separate ServiceEnd suite passed46 HTTP/MariaDB contracts with Performance loaded; it is a distinct suite, not a substitute for the original UI check. All13 Performance native-class regression fixtures pass with their explicitly documented original-service doubles. The original package, not a test replacement for its archive loader or deletion cascade, performs the positive engine work.

The actual production transition still requires the separate concrete user's SQL approval, the real source/drain/configuration readback and a finite private operator receipt. No result here declares production retention enabled.

## Frozen final review artifacts

The actual final suites were run after materializing these exact ZIP bytes into the private original installation:

| Package | Files | SHA-256 |
| --- | ---: | --- |
| SpeoControl0.6.6 | 70 | `c2694fc91237eef0f8e308ee638ef2cc9e90fea8bcf7b427c537876749ccc042` |
| SpeoReportPerformance1.3.0 | 50 | `063361aef26da1762d0ca7f9a7a72d03f12edc4c8c2efbd03f1a6403fd0d9c7e` |

All packaged files match the actually exercised owned trees. The final49/84/12 native suites completed successfully in9.820/12.126/3.719seconds respectively on this isolated fixture; these are fixture execution times, not production throughput benchmarks. The independent baseline command passed8 native checks. All13 storage-free/native-class Performance regression fixtures passed; the300 pure/SQLite Control checks are explicitly separate from InnoDB/native-engine proof. Changed Performance sources pass PSR-12 without errors and the native PHP8.4.27 parser accepts both distributed packages. Package/Marketplace validators and six package-boundary tests pass.

The [sanitised final receipt](https://github.com/rktsky/matomoplugin.speocontrol/blob/review/active-erasure-0.6.6/docs/evidence/report-copy-erasure-final-20261109.json) includes actual package/source equality, original11,887-file equality and hashes of private native logs. No copied customer database, credential, raw report or private inventory is in the repository. These candidates are uninstalled on production; current installed0.6.5 and native global2day state remain unchanged.
