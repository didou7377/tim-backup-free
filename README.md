# TIM Backup

TIM Backup is a security-focused local backup plugin for single-site WordPress.
Version `0.2.0` creates full or database-only archives, verifies them, retains up
to three local copies, and provides authenticated downloads plus staged
database-only restore through a guided, resumable assistant. Restore progress is
journaled outside the database and row imports continue in bounded batches.
Full-file restore remains disabled until it has a crash-safe rollback
implementation.

## Requirements

- WordPress 6.5 or newer
- PHP 8.1 or newer
- PHP ZIP extension
- Writable private directory outside the public document root
- Single-site WordPress

## Development

English is the source language. Every user-facing string must use WordPress
internationalization functions with the `tim-backup-free` text domain. German
translations are bundled under `languages/`.

Install development dependencies:

```bash
composer install
npm ci
```

Run the project checks:

```bash
composer check
npm test
```

The npm test suite parses every production PHP file, boots the plugin on the
current WordPress release in WordPress Playground, creates and verifies database
backups, rejects injected archive entries, tests protected three-archive
rotation, validates German localization, and runs the official Plugin Check
plugin. GitHub Actions additionally performs a destructive database restore test
against MariaDB in an ephemeral WordPress installation, recreating the restore
services between batches to exercise journal-based continuation.

The complete product scope, architecture, security requirements, release process,
and current status are maintained in [`PROJECT.md`](PROJECT.md).

## Security

Do not publish suspected vulnerabilities in a public issue. Follow
[`SECURITY.md`](SECURITY.md) to report them privately.

TIM Backup signs archive manifests and verifies SHA-256 payload hashes. This
detects corruption and archive modification while the site's WordPress secrets
remain protected. It cannot guarantee protection after a complete server
compromise.

## WordPress.org

`readme.txt` is the canonical WordPress.org directory description. Keep its stable
tag, compatibility metadata, changelog, and screenshots synchronized with every
release.

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
