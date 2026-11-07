# Security and report data

Cached page reports may contain personal data in labels, page URLs and custom values. Protect the Matomo cache backend and its backups to the same standard as the reports themselves. Expiration limits retention; clearing this plugin's cache does not remove copies in unrelated backups.

Every foreground cache read requires the current Matomo site's view permission and report-retention decision. A base is shared only within its site and report context; finished pages are separated by login. Cached values contain validated primitive JSON rather than serialized PHP objects. Size, structure and decompression limits reject unexpected input.

The revision check follows Matomo's normal archive publication and invalidation markers. Database administrators who change archive blobs directly must clear the Matomo cache afterwards. Cache services must not be writable by untrusted clients: primitive validation is not a replacement for controlling access to the backend.

Warmup manifests contain neutral report targets and expiry information, without logins, sessions or tokens. Their directory must be private, outside the document root and accessible only to the Matomo operating-system account. Configure the same protected path for web and console processes. Diagnostics avoid report parameters and identifiers; inspect any additional logs before sharing them.

The plugin sends no telemetry and does not call an external reporting service. Its normal operation requires no new outbound network access beyond Matomo's configured database and cache services.

## Reporting a concern

Contact the maintainer privately through the current Rocketsky contact channel linked from <a href="https://www.rocketsky.ch/" target="_blank" rel="noopener noreferrer">the company website</a>. Provide the plugin, Matomo and PHP versions, a minimal synthetic reproduction and the affected integration boundary. Do not attach real reports, tokens, Redis contents or credentials to a public issue.

Use the <a href="https://github.com/rktsky/matomo-plugin-SpeoReportPerformance/issues" target="_blank" rel="noopener noreferrer">public issue tracker</a> for non-sensitive bugs only. Security concerns and identifying report data belong in the private maintainer contact channel above.
