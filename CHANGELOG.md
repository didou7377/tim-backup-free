# Changelog

All notable changes to TIM Backup are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-08-21

### Changed

- Replaced the inline database restore confirmation with a dedicated guided
  assistant showing real server-side progress.
- Database restore now journals progress outside the database, imports rows in
  bounded resumable batches, retains old tables until cleanup, and creates a
  database-only safety backup before activation.
- Database exports are split into independently hashed 4 MiB chunks so archive
  preparation and verification remain bounded during restore.
- Restore recovery now uses a database-independent journal key, an idempotent
  reserved safety-backup ID, and a filesystem maintenance marker that pauses
  normal traffic until cleanup completes.

## [0.1.0] - 2026-08-20

### Added

- Manual full and database-only backup creation.
- Signed archive manifest and SHA-256 verification.
- Private storage outside the public document root with a three-backup rotation.
- Authenticated, freshly verified download and explicitly confirmed deletion.
- Confirmed database restore with a current safety backup and staged table
  replacement; full-file restore remains intentionally disabled.
- Fixed weekly full-backup schedule.
- Failure-only administrator email.
- Tabbed, responsive WordPress administration interface.
- English source strings and German localization.
- WordPress.org and development documentation.
- Reproducible syntax, Playground, retention, translation and Plugin Check tests.
