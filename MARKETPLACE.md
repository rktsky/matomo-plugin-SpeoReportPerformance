# Marketplace publication preparation

Candidate: **SpeoReportPerformance 1.2.3**. Review date: **7 November 2026**.

The candidate is prepared for submission, not approved or listed by Matomo. No Marketplace submission is triggered by this document or by the local package build. The repository's webhook settings contained no configured repository webhook when inspected on the review date.

## Source and package

- Repository: <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance" target="_blank" rel="noopener noreferrer">rktsky/matomo-plugin-SpeoReportPerformance</a>.
- Candidate tag when submitting: `v1.2.3`, created from the reviewed and passing candidate commit. The manifest version is `1.2.3`.
- Build: `python3 tools/build-release.py`.
- Verify: `python3 tools/validate-release.py` and `python3 tools/validate-marketplace.py`.
- Installable output: `dist/SpeoReportPerformance-1.2.3.zip` and its `.zip.sha256` sidecar.

The build also produces a standalone source export. The Marketplace builds from the GitHub tag rather than from the attached ZIP. Its documented default exclusions and `plugin.json`'s `archive.exclude` must yield exactly the reviewed release allowlist. The additional validator checks that parity, including repository-only files. Original Matomo packages are not modified.

The published `v1.2.2` tag and assets remain unchanged. They predate the repository transfer and still contain the old homepage URLs. Do not move that tag or replace its assets to publish this candidate.

## Audit outcome

| Area | Candidate evidence |
| --- | --- |
| Identity and metadata | Root manifest, valid plugin name and version, short description, keywords, author, homepage and explicit runtime requirements. The public name lookup returned no existing plugin on the review date; Matomo decides final availability. |
| Licence and source | GPL-3.0-or-later text, readable PHP source and retained upstream attribution in `NOTICE`. No bundled runtime dependency copies. |
| Listing and help | English Description, installation guidance, FAQ, changelog, documentation index and support links. No external badge image in the README. |
| Report data | No plugin telemetry or external reporting endpoint. Bound read-only archive queries, foreground permission/retention checks and bounded primitive cache payloads. Cache and warmup storage responsibilities are documented. |
| Integration limits | Native implementation dependencies remain disclosed in `docs/compatibility.md`. The candidate does not claim exclusive use of stable public APIs or support for Matomo 6. |
| Package | Explicit runtime/documentation allowlist; development tools, tests and repository acknowledgements excluded from installable archives. |
| Validation | Existing isolated native contract suites and PHP 8.4/8.5 CI matrix, plus release and Marketplace payload validation. Consult the actual CI run for its result and resolved Matomo versions. |

The actual Matomo 5.14.2 dependency checker was used to check the declared `>=5.14.2,<6.0.0` range. It accepts 5.14.2 and a synthetic later 5.x version and rejects 5.14.1 and synthetic Matomo 6 alpha, beta, RC and stable versions. This is a constraint-parser check, not a claim that those other Matomo installations were tested.

`SPONSORS.md` records the Rocketsky GmbH sponsorship with its website link. It stays in GitHub and the source export and is excluded from installable archives. Sponsored links are absent from the README and Marketplace-rendered text. Production use at Speo is stated in the Description. No customer report screenshot or private operational fixture is published; screenshots are optional for this plugin, which retains Matomo's native report UI.

## Submission steps, when publication is requested

These steps follow the <a href="https://developer.matomo.org/guides/distributing-your-plugin" target="_blank" rel="noopener noreferrer">official Matomo publishing guide</a>:

1. Confirm that the candidate commit's CI has passed and that the intended version is still unpublished.
2. In the GitHub repository's **Settings → Webhooks**, add the payload URL `https://plugins.matomo.org/postreceive-hook`, select the push event and activate it when ready to submit. JSON is a supported content type. Use HTTPS with certificate verification.
3. Create the `v1.2.3` tag/release from that reviewed commit after the webhook is active. Do not tag before configuring the hook and assume it will be discovered later.
4. Check the webhook delivery and Matomo's response. A tag starts the first-plugin review; it does not guarantee acceptance or immediate listing.

Tag names may have a `v` prefix: the Marketplace reads the version from `plugin.json`. No separate Marketplace developer account is required by the documented workflow. No registration, submission message, active hook delivery or new release tag is part of this preparation.

Matomo's manual review, final name availability and acceptance remain external decisions. If publication is rejected, retain the response and address its specific findings before a new version is submitted.
