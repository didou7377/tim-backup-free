# Changelog

All notable changes to TIM Backup are documented here. The project follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
