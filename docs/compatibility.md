# Compatibility

## Declared and tested support

The release metadata declares Matomo `>=5.14.2,<6.0.0` and PHP `>=8.4.0`. Matomo On-Premise is the supported deployment type. Matomo for WordPress is not validated and is explicitly excluded by the metadata.

| Runtime | Evidence |
| --- | --- |
| Matomo 5.14.2, PHP 8.4.27 | Validated baseline for native report and UI parity. |
| Other Matomo 5 minor/patch releases | Covered by the declared range; no blanket claim of executed tests. |
| Matomo 6 or another future major | Not declared supported until its real runtime matrix has passed. |
| PHP releases other than the baseline | Must satisfy Matomo's own requirements; consult the executed validation results and configured CI matrix in [testing](testing.md). |

There is no exact PHP or Matomo patch-number allowlist. A minor/patch update creates a new cache namespace and rebuilds values. It does not automatically switch an eligible report to native processing because its version string changed. Capability checks concern actual methods, data shapes and conflicting extensions.

The upper major bound is a distribution declaration required by the Marketplace. It expresses the validated major line; it is not a runtime patch-version gate. Expanding the bound requires testing that major's real installation and reviewing its documented API changes. Synthetic future-version arguments test request policy only; they do not test another installed release.

## Documented integration points

Matomo's compatibility promise covers reporting APIs, named events and PHP classes or methods marked `@api`. Public visibility alone does not provide that promise, and a method marked `@ignore` is excluded even when its containing class is public API. See <a href="https://developer.matomo.org/guides/apis" target="_blank" rel="noopener noreferrer">Matomo's API policy</a>.

The plugin already uses the following documented interfaces:

- Reporting API entry points, including `Actions.getPageUrls` and the SitesManager methods; `API\Request::process()`, request-array construction and its root/nested-request checks. The <a href="https://developer.matomo.org/api-reference/Piwik/API/Request" target="_blank" rel="noopener noreferrer">Request reference</a> explicitly lists those request-scope checks.
- `Archive::build()`, `getDataTable()`, `getParams()` and `forceFetchingWithoutLaunchingArchiving()`. These appear in the <a href="https://developer.matomo.org/api-reference/Piwik/Archive" target="_blank" rel="noopener noreferrer">Archive reference</a>; they must not be confused with the private archive selector or undocumented methods on returned parameter objects.
- The API interception/end events, `Request.shouldDisablePostProcessing`, `ViewDataTable.configure.end`, `Visualization.beforeRender` and `Piwik::postEvent()`. `NativeEndHooks` uses the registered event instead of calling a named PagePerformance callback. See the <a href="https://developer.matomo.org/api-reference/events" target="_blank" rel="noopener noreferrer">event reference</a>.
- Public DataTable/Row methods for rows, metadata, totals, queues, cloning, sorting and ArrayAccess; public view setters, configuration properties and `request_parameters_to_modify`. These are documented by the <a href="https://developer.matomo.org/api-reference/Piwik/DataTable" target="_blank" rel="noopener noreferrer">DataTable</a>, <a href="https://developer.matomo.org/api-reference/Piwik/DataTable/Row" target="_blank" rel="noopener noreferrer">Row</a>, <a href="https://developer.matomo.org/api-reference/Piwik/ViewDataTable/Config" target="_blank" rel="noopener noreferrer">view configuration</a> and <a href="https://developer.matomo.org/api-reference/Piwik/ViewDataTable/RequestConfig" target="_blank" rel="noopener noreferrer">request configuration</a> references.
- Native permission checks through `Piwik::checkUserHasViewAccess()` and the documented `Access::doAsSuperUser()` callback scope. The latter is confined to local CLI warmup and deferred publication from an already-authorized CLI scope; web cache access still checks the current user's permission. See the <a href="https://developer.matomo.org/api-reference/Piwik/Access" target="_blank" rel="noopener noreferrer">Access reference</a>.

## Direct replacements in 1.2.2

`RevisionContext` reads the documented `[General] datatable_archiving_maximum_rows_actions_flat` setting through `Config` instead of calling `Actions\ArchivingHelper::isFlatArchivingEnabled()`. Its site fingerprint uses the public reporting method `SitesManager.getSiteFromId` instead of the explicitly ignored `Site::getSite()` getter. The authorized-CLI publication check uses the documented `Piwik::hasUserSuperUserAccess()` instead of `Access::getInstance()->hasSuperUserAccess()`. Configuration defaults continue to come from Matomo; no original configuration or package file is changed. See the <a href="https://developer.matomo.org/api-reference/Piwik/Config" target="_blank" rel="noopener noreferrer">Config reference</a> and <a href="https://developer.matomo.org/api-reference/api" target="_blank" rel="noopener noreferrer">reporting API documentation</a>.

These replacements remove direct helper calls. They do not make the complete cache and native-processing integration independent of Matomo internals.

## Remaining implementation dependencies

The audit checks both the official documentation and the annotations in the actual Matomo 5.14.2 source. The following dependencies remain in the runtime:

| Boundary | Current dependency | Replacement status |
| --- | --- | --- |
| Native post-processing | `NativeProcessingAdapter` calls `DataTablePostProcessor::process()` and `applyTotalsCalculator()`. Neither is a stable plugin API. | `process()` can be redesigned through a one-shot documented interception of a nested public `API\Request`; a private proof reproduced the native output. The current runtime has not migrated to that composition. No equivalent totals-only boundary has been established for `applyTotalsCalculator()`. |
| Archiving and retention decisions | `ArchiveProcessor\Rules::isArchivingEnabledFor()`, `PrivacyManager::getPurgeDataSettings()` / `shouldReportBePurged()`, and `Actions\ArchivingHelper::reloadConfig()` in warmup. | No equivalent public contract was established that preserves the same archiving authorization, retention decision and request-local configuration reset. These checks remain; they must not be replaced with a cache TTL or a weaker permission check. |
| Archive parameter details | `Archive::getParams()` is documented, but the returned `Archive\Parameters` getters `getPeriods()`, `getIdSites()` and `getSegment()` are not documented plugin API. | Rebuilding equivalent context from public request/period APIs needs a separate change, including timezone, empty-segment and date-normalization comparisons. |
| Report description | `Report::getDimension()`, `getRecursiveLabelSeparator()` and `getActionToLoadSubTables()` are marked `@ignore`. | The public dimension methods can cover the narrowly supported stock report, as explained below. No public foreign-report separator getter was found. The protected documented property is intended for report subclasses, not access through reflection or a bound closure. |
| Table lifecycle | `DataTable\Manager` lookup, latest-ID and deletion methods are not public plugin API. | Track owned table objects and use their documented lifecycle instead of querying the manager. This requires an ownership refactor across readers, snapshots and cleanup. |
| Loaded-plugin inspection | `Plugin\Manager::getPluginsLoadedAndActivated()` supplies the real objects used to inspect hooks and fingerprint active plugin versions/classes. | The documented `loadAllPluginsAndGetTheirInfo()` loads additional plugins and returns metadata; it does not provide the same active-object/hook contract. It is not a drop-in replacement. |
| Service access | Static `Piwik\Cache::getLazyCache()` / `getTransientCache()` and `StaticContainer::get()` are not stable API entry points. | Constructor injection is the documented route for cache and translator services. A service refactor must respect which components Matomo actually constructs through DI. |
| Actions aggregation rules | `Actions\Metrics::getColumnsAggregationOperation()` supplies part of the cache environment fingerprint. | The public DataTable aggregation-metadata contract does not establish an identical complete configuration fingerprint. Do not omit that dependency without proving equivalent invalidation. |
| Native data/UI protocols | Exact stock `HtmlTable` / PageUrls classes, PagePerformance metric provenance, processing flags such as `processed_metrics_computed`, and the recognized queued-filter boundary. | Public DataTable methods do not guarantee these implementation-specific meanings or native phase order. Broader interfaces alone would also admit custom implementations with different behavior. Native parity tests remain necessary. |
| Archive revision protocol | `ArchiveRevisionProvider` performs a bound, read-only query over the documented numeric archive schema and interprets publication/invalidation markers. | This is direct storage/protocol coupling, not a private selector call. No public immutable report-revision token was established. Root `archived_date` or expiry alone cannot prove that newer partial child archives are unchanged. |

The <a href="https://developer.matomo.org/guides/database-schema" target="_blank" rel="noopener noreferrer">database schema</a> and <a href="https://developer.matomo.org/guides/archiving" target="_blank" rel="noopener noreferrer">archive-querying guide</a> describe storage and partial archives. Documenting the schema does not make every marker interpretation a stable high-level revision API. Revision checks still run before accepting a cache hit and before publishing a value. Direct out-of-band blob edits require an explicit cache clear.

## Refactors with documented building blocks

A public composition can replace the direct full post-processing call: enter a one-shot scope holding an input table, call `API\Request` for the same `Actions.getPageUrls` report with original-table output, and supply that table through the documented `API.Request.intercept` event. Native Request then owns post-processing under its public contract. This is a real use of the documented interception contract, not a new endpoint that simply calls the same internal helper.

A private, read-only experiment on Matomo 5.14.2 reproduced a native JSON response byte for byte with one nested request and one input interception. Both a native hierarchy and the current prepared flat input reproduced the native JSON response; the latter stayed within the configured 2 GiB worker limit. A complete runtime migration still needs the full comparison matrix for typed table metadata, raw parameter presence, filters, formatting, callback replay, totals and permission checks. Native API end hooks run for the child request too; known PagePerformance definitions must not accumulate, and unknown non-idempotent observers cannot be assumed safe. The release runtime therefore retains its direct processing adapter.

This does not establish a public totals-only operation. A scratch API request also applies later generic, queued and formatting stages; flags can propagate into the root reload triggered by the mere presence of `idSubtable`. Extracting its totals cannot be declared equivalent without proving those request-specific cases.

For the stock PageUrls report, the documented `Report::getNthLevelTableDimension(0)` returns the root dimension because that report declares itself as its subtable report. `getSubtableDimension()` and `getNthLevelTableDimension()` cover its deeper levels. This can remove the ignored dimension getters and the general fallback in the owned flattener after scoped tests. It is not equivalent for an arbitrary report without a subtable. See the <a href="https://developer.matomo.org/api-reference/Piwik/Plugin/Report" target="_blank" rel="noopener noreferrer">Report reference</a>.

`DataTable::__destruct()` is documented as cleaning up table memory. An owned-reference design can use that lifecycle without calling `DataTable\Manager` directly. It must first detach rows shared with the committed result, prove ownership of the entire original graph and avoid destroying borrowed totals or other tables. The existing ID-based ownership proof cannot simply be removed, and broad range deletion must not be replaced with an untracked sweep.

The official <a href="https://developer.matomo.org/guides/caching-in-matomo" target="_blank" rel="noopener noreferrer">caching guide</a> demonstrates injection of `Matomo\Cache\Lazy`; the <a href="https://developer.matomo.org/guides/dependency-injection" target="_blank" rel="noopener noreferrer">DI guide</a> covers translator and other constructor dependencies. The <a href="https://developer.matomo.org/changelog" target="_blank" rel="noopener noreferrer">platform changelog</a> explicitly states that `StaticContainer` is no longer considered API. These are viable foundations for a service refactor, not justification for wrapping the same service locator in another static helper. `CacheId::pluginAware()` alone also omits the plugin-version and hook details currently used by this cache.

## Correctness and extension boundaries

A replacement must preserve complete rows, totals, metadata, callback order, permission and retention checks, archive freshness and memory cleanup. Merely wrapping an internal helper in an API facade, reading private fields or copying the same internal processing pipeline does not establish a documented contract for that operation. Original Matomo/vendor files and runtime class registries remain unchanged.

Report callbacks and additional metrics can change the resulting table. New plugin combinations need native comparisons, including complete metadata and HTML. Preserve ordinary API hooks; do not suppress callbacks to force a cache hit. Unsupported shapes, conflicting hooks or unrepresentable data continue through native processing. That request-specific correctness decision is separate from rejecting an entire release merely because its version number changed.
