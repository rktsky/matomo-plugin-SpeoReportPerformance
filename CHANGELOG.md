# Changelog

## 1.2.3 — 2026-11-07

- Prepare Marketplace metadata, support links and an English FAQ for the public repository under rktsky.
- Replace the external README badge with a workflow link and record production use at Speo.
- Keep the Rocketsky GmbH sponsorship acknowledgement in the repository only, outside the Marketplace listing and installable package.
- Verify that Marketplace archive exclusions produce the same reviewed payload as the release allowlist.

## 1.2.2 — 2026-11-07

- Read the documented flat Actions archiving configuration directly instead of using the internal Actions helper.
- Obtain site context through the public SitesManager reporting API instead of the ignored Site cache accessor.
- Check deferred CLI publication authorization through the public Piwik superuser helper.
- Expand the compatibility audit, including a verified public Request/interception composition for a future post-processing refactor and the remaining totals, retention and revision boundaries.

## 1.2.1 — 2026-11-07

- Fix excessive memory use while loading saved report preferences by deferring UI base loading until the actual report API dispatch. Temporary views no longer trigger expensive archive/cache work during configuration.
- Bind pending work to the exact view with request-local weak state. Restore the original request parameters before cache key construction and presentation; the processing marker is not retained in cache keys or rendered UI configuration.
- Add regression coverage for temporary views, pending request ownership, marker restoration and saved-preference request construction.

## 1.2.0 — 2026-11-07

- Prepare an installable plugin package with English documentation, Marketplace metadata and preserved GPL attribution.
- Make cache lifetime and background warmup limits configurable without changing plugin or Matomo files.
- Add local diagnostics and a portable background warmup process with private storage outside the document root.
- Isolate compatibility-sensitive integration, document the validated Matomo baseline and retain native processing for unsupported requests.
- Add reproducible development checks and deterministic release packaging with an explicit file allowlist.

## 1.1.0 — 2026-11-07

- Replace patch-version gates with capability and data-shape checks; runtime changes create a fresh cache namespace.
- Use the native Actions API for archive loading and decoding.
- Preserve native sorting, totals, report presentation and permission checks in cached API and UI pages.

## 1.0.0 — 2026-11-07

- Add revision-bound caching for eligible flat monthly page URL reports.
- Add bounded primitive report snapshots and optional warmup of recently used report bases.

Earlier development archives were private prototypes and are not supported releases.
