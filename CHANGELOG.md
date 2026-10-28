# Changelog

## 0.1.0

- First release.
- Export single sites (or many in a queue) from a multisite network to `.tar` packages: tables, users, media and a manifest.
- Import from a URL (server-to-server download in chunks), a chunked browser upload, or a file already on the server.
- Compatibility check before import: theme, plugins, versions, domain conflicts and disk space.
- Find/replace that is safe for serialized, JSON and URL-encoded data; detects a site's earlier addresses (e.g. install subdomains).
- Users matched by e-mail and created with their original password hashes; content authors remapped.
- Resumable, time-boxed steps (no PHP timeouts); pause, resume and retry.
- Updates from GitHub Releases inside wp-admin.
