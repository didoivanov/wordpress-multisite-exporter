# Changelog

## 0.1.1

- Missing theme: choose a replacement from the themes installed on the target network, per import or for all imports at once ("Start all").
- Fix: imports failed with "Call to undefined function PHPMailer\PHPMailer\mail()" on hosts where PHP's mail() is disabled. No e-mails are sent while importing.
- Fix: Retry after a failed site creation reuses the site the import created instead of failing with "domain already exists".

## 0.1.0

- First release.
- Export single sites (or many in a queue) from a multisite network to `.tar` packages: tables, users, media and a manifest.
- Import from a URL (server-to-server download in chunks), a chunked browser upload, or a file already on the server.
- Compatibility check before import: theme, plugins, versions, domain conflicts and disk space.
- Find/replace that is safe for serialized, JSON and URL-encoded data; detects a site's earlier addresses (e.g. install subdomains).
- Users matched by e-mail and created with their original password hashes; content authors remapped.
- Resumable, time-boxed steps (no PHP timeouts); pause, resume and retry.
- Updates from GitHub Releases inside wp-admin.
