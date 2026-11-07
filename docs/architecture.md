# Architecture

## Request path

The plugin uses Matomo API and visualization events to recognise its narrow report scope. Eligibility requires one site, a monthly period, `Actions.getPageUrls`, a flat report, no segment and a requested row limit between 1 and 1000. Unsupported inputs and full exports continue through native processing.

UI configuration is deliberately cheap. `ViewDataTable.configure.end` records weak, request-local pending state for the exact view through the documented <a href="https://developer.matomo.org/api-reference/Piwik/ViewDataTable/RequestConfig" target="_blank" rel="noopener noreferrer">request parameter override configuration</a>, `request_parameters_to_modify`. It does not load archive data or cached report bases. Matomo can create temporary views while resolving saved report preferences; a view that is discarded must not start report work.

The corresponding <a href="https://developer.matomo.org/api-reference/events" target="_blank" rel="noopener noreferrer"><code>API.Request.intercept</code> event</a> resolves the pending view only when Matomo's real UI Proxy request is dispatched. Original request parameters are restored before cache keys, warmup targets or output are built. The one-shot processing marker is not retained in cached data or rendered UI configuration. Only then can `UiBasePreloader::loadForDispatch` perform the base lookup and loading. No stack inspection or private factory-state detection is used.

On a cache miss, Matomo's Actions API reads and decodes its archives and applies its Actions/Goals processing. The plugin flattens the resulting table and stores a neutral base. It does not implement a raw archive decoder or bypass native API end events. Selected API pages and numeric UI pages can be reused on subsequent requests. Native presentation callbacks, current site percentages, labels and final HTML are applied for the current request.

The tables retain row identity, ordering, totals and metadata. Primitive snapshot guards reject unsupported objects, references, non-finite numbers, cycles and excessive depth, node count or byte size. Snapshots use JSON and bounded compression. A rejected snapshot causes normal processing rather than a shortened result.

## Cache lifecycle

Before a cache read, the current request must pass Matomo's site-view permission and report-retention checks. Neutral bases are shared only for the same site and relevant report context. Finished API and numeric UI pages are separated by login.

Keys incorporate the request context, language, relevant configuration, runtime/implementation fingerprint and archive revision. The revision adapter reads all matching publication/invalidation markers from the site's monthly numeric archive tables through a bound, read-only query. It checks the revision again before accepting a hit and before publishing a newly built value. A concurrent archive change rejects that attempt.

Expiration limits retained cache data. It is not the freshness mechanism: Matomo archiving and invalidation markers change the key immediately. Direct archive-blob changes outside Matomo's publication protocol require an explicit cache clear by the operator.

Cache data includes report content and must be protected accordingly. It contains no cached HTTP response, authentication token, session or PHP object serialization. The plugin does not send telemetry to Rocketsky or another external service.

## Background work

Eligible foreground requests record a neutral warmup target without a login or authentication token. The bounded background worker checks recent targets and rebuilds missing bases. Each rebuild runs in a separate PHP process. Target writes are coalesced and replaced atomically; the foreground request does not force a disk synchronization. A worker lock prevents concurrent warmup runs.

Warmup cannot remove every first-request cost. User-specific pagination and new searches still need their first selected-page calculation for a given archive revision.

## Integration boundaries

Public events, report APIs, configuration and DataTable extensions provide the integration points. The adapted flattening algorithm is kept in the plugin and attributed in the packaged `NOTICE`. Compatibility-sensitive access is isolated in small adapters and tested against the native Matomo pipeline. See [compatibility](compatibility.md) for the exact tested baseline and limits of Matomo's public API promise.

No original Matomo class, vendor dependency or server package is patched. The plugin does not modify the runtime class registry, write archives or change the database schema.
