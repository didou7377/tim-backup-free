# TIM Backup Free – Project Context

## Vision

TIM Backup is the first plugin in the TIM plugin family. It provides a secure,
clear and dependable WordPress backup experience without requiring an account or
an external service.

## Current release

- Version: `0.1.0`
- Status: initial MVP
- Distribution: WordPress.org
- Repository: `didou7377/tim-backup-free`
- License: GPL-2.0-or-later
- Text domain / WordPress.org slug: `tim-backup`

## MVP scope

- Manual full backups of the database and WordPress files.
- Manual database-only backups.
- A fixed weekly full-backup schedule using WP-Cron.
- At most three plugin-managed local backup archives.
- Authenticated archive downloads and deletion.
- One-click database restore after explicit confirmation and a current full
  safety backup.
- Archive integrity and authenticity verification before restore.
- Failure emails to the WordPress administration address.
- WooCommerce compatibility, including HPOS tables, by backing up all tables
  belonging to the current WordPress database prefix.
- Reports containing status, type, size, duration and creation time.

Full file backups always exclude `wp-config.php`, `.env*`, `.git`, `.svn`, and
the plugin's storage directory. These are mandatory security exclusions and are
not the configurable exclusions reserved for Pro.

## Explicit non-goals for Free

- External storage destinations.
- User-defined schedules, sources, exclusions or retention policies.
- Incremental or real-time backups.
- Multisite support.
- Migration between URLs or domains.
- Remote management, telemetry or account registration.

## Architecture

- `tim-backup.php`: minimal bootstrap and plugin metadata.
- `includes/class-tim-backup-plugin.php`: lifecycle and dependency wiring.
- `includes/class-tim-backup-storage.php`: private storage outside the public
  document root, locking, index management and retention.
- `includes/class-tim-backup-backup-service.php`: backup creation and verification.
- `includes/class-tim-backup-restore-service.php`: validated restore operations.
- `includes/class-tim-backup-admin.php`: one admin menu with tabbed pages.
- `assets/`: admin-only styles and scripts.
- `languages/`: translation template and bundled German translation.

Business logic must remain outside templates and bootstrap files. Pro must extend
Free through documented WordPress hooks and must never require premium code to be
downloaded by the WordPress.org-hosted plugin.

## Security requirements

- Never use `eval`, dynamic PHP execution or remotely supplied executable code.
- Every state-changing request requires `manage_options` and a valid nonce.
- Treat filenames, archive entries, database identifiers and request data as
  untrusted.
- Never extract ZIP archives with `extractTo`; validate every entry and destination.
- Use cryptographically random archive names.
- Sign manifests with HMAC-SHA-256 and verify all recorded SHA-256 hashes.
- Do not expose backup paths through public URLs.
- Refuse concurrent backups and restores through atomic locks.
- Never follow symbolic links while collecting site files.
- Do not log credentials, salts, database contents or customer data.
- Do not claim that software can be completely vulnerability-free.

Local integrity protection detects accidental damage and unauthorized archive
changes where the site secret remains protected. It cannot defend against an
attacker who has fully compromised the server and its WordPress secrets.

## WordPress.org compliance

- The Free plugin is complete and useful without payment.
- No trial, feature expiration, tracking, telemetry or unsolicited external calls.
- Source strings are written in English and are translation-ready.
- Admin notices are contextual, limited and dismissible where appropriate.
- All bundled code and assets are GPL-compatible.
- `readme.txt`, changelog, screenshots and tested versions must be updated for
  every release.
- Run Plugin Check and WordPress Coding Standards before submission.

## Internationalization

- English is the source language.
- All user-facing strings use WordPress translation functions.
- German is bundled as `de_DE`.
- Translators receive context for ambiguous strings.
- JavaScript-visible text is passed from PHP instead of being hard-coded.

## Versioning and releases

Semantic Versioning is used:

- Patch: compatible fixes.
- Minor: compatible features.
- Major: breaking changes or archive-format changes.

Before every release:

1. Update the plugin header, `TIM_BACKUP_VERSION`, `readme.txt`, `README.md` and
   `CHANGELOG.md`.
2. Regenerate translations.
3. Run syntax checks, coding standards, tests and Plugin Check.
4. Test backup, verification, download, retention and restore on a clean site.
5. Test current WordPress, WooCommerce, HPOS and supported PHP versions.
6. Tag the exact stable version.

## Archive format v1

The ZIP contains:

- `tim-backup/manifest.json`
- `tim-backup/database/schema.json`
- `tim-backup/database/data/*.jsonl`
- `tim-backup/files/...` for full backups

The manifest records hashes for every payload entry and is signed. Archive-format
changes require explicit backwards-compatibility handling.

## Status log

### 0.1.0

- Project and repository structure established.
- Implemented manual full and database-only backups.
- Implemented private storage outside the document root, signed manifests,
  complete archive-entry matching, SHA-256 verification and three-archive
  rotation.
- Implemented authenticated verified download, confirmed deletion and confirmed
  database restore.
- Security review hardening added OS-level shared/exclusive locks, fresh
  verification before download, ZIP special-file rejection, InnoDB consistent
  snapshots, deterministic primary-key ordering, view rejection and archive
  hashes in metadata.
- Restore creates a protected full safety backup before changing current data and
  preserves the live backup index across database replacement.
- Implemented fixed weekly backups and failure-only administrator email.
- Implemented a responsive single-menu, tabbed administration interface.
- Added complete English source strings and bundled German PO/MO translations.
- Added WordPress.org readme, security policy, changelog, coding standards,
  distribution exclusions and GitHub Actions quality checks.
- WordPress 7.1 / PHP 8.1 Playground smoke test passes for activation, database
  backup, verification, protected retention, deletion, translations and official
  Plugin Check.

## Known verification gaps before public release

- Destructive database backup and staged restore passed against MariaDB 11.4 in
  GitHub Actions.
- Full-file restore is disabled until a staged, journaled and crash-recoverable
  rollback mechanism has dedicated integration coverage.
- Full backups need tests on large sites and constrained shared hosting.
- WooCommerce and HPOS backup/restore require dedicated integration fixtures.
- WordPress Coding Standards fixes from the first CI run are pending verification.
- The administration interface needs browser, keyboard and screen-reader review.
- WordPress.org assets and screenshots have not been prepared.

## Next milestones

- Resolve all security-review findings.
- Add WooCommerce HPOS integration coverage.
- Test against large databases and constrained shared hosting.
- Submit to WordPress.org only after all release checks pass without blockers.
- Design Pro as a separate private repository after Free is stable.
