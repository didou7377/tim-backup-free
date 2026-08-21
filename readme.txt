=== TIM Backup ===
Contributors: didou7377
Tags: backup, restore, database, woocommerce, security
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create, verify, download, and manage secure local WordPress backups without an account or external service.

== Description ==

TIM Backup provides straightforward local backups for a single-site WordPress
installation. It can create a full backup of the database and regular files below
the WordPress root, or a database-only backup.

Every completed archive is verified before it appears in the backup list. TIM
Backup records a SHA-256 hash for every payload file and protects the manifest
with a signature tied to the current WordPress installation.

= Included in TIM Backup Free =

* Manual full and database-only backups.
* One fixed weekly full-backup schedule through WordPress Cron.
* Up to three plugin-managed local archives.
* Authenticated downloads.
* Verified database-only restore after explicit administrator confirmation and
  creation of a current full safety backup.
* Failure-only email notifications to the WordPress administration address.
* Support for WooCommerce tables, including HPOS tables that use the current site
  database prefix.
* Backup reports with type, date, size, duration, and verification status.

= Privacy =

TIM Backup Free does not require registration, track usage, or contact an external
service. Archives stay on the WordPress server until an administrator downloads
or deletes them.

= Security model =

Backup archives use cryptographically random filenames and are stored outside the
public document root. Downloads and state-changing actions require an
administrator capability check and request nonce.

Local integrity checks cannot protect against an attacker who has fully
compromised the server and obtained the WordPress secret keys. Keep independent
off-site copies of important backups.

= Important limitations =

* Version 0.1.0 supports single-site WordPress only.
* WordPress Cron runs when the site receives traffic.
* Full-file restore is intentionally unavailable in version 0.1.0 until its
  staging, rollback, and crash-recovery path has dedicated integration coverage.
* Full backups intentionally exclude `wp-config.php`, `.env*`, `.git`, and `.svn`
  content so that configuration secrets and repository internals are not copied
  into the archive.
* Sites using database tables with foreign-key constraints require a manual
  restore workflow in version 0.1.0.
* For deterministic, consistent database exports, prefixed objects must be
  InnoDB base tables with primary keys. Views and non-transactional tables are
  rejected with a clear failure notice.
* Large sites may exceed hosting execution-time, disk-space, or memory limits.

== Installation ==

1. Upload the `tim-backup` directory to `/wp-content/plugins/`, or install the
   plugin through the WordPress Plugins screen.
2. Activate TIM Backup.
3. Open **TIM Backup** in the WordPress sidebar.
4. Review the **System** tab.
5. Create and download the first verified backup.

== Frequently Asked Questions ==

= Where are backups stored? =

TIM Backup stores managed archives outside the public document root. If the
automatically selected parent directory is not writable, define
`TIM_BACKUP_STORAGE_DIR` in `wp-config.php` with an absolute writable path that is
not web-accessible. Archives are served only through an authenticated WordPress
administrator action.

= Why are only three backups retained? =

The Free version manages a simple three-archive local rotation. After a fourth
backup completes and passes verification, the oldest managed archive is deleted.
Downloaded copies are not controlled by the plugin.

= Does it support WooCommerce HPOS? =

Yes. TIM Backup includes every table beginning with the active site's WordPress
database prefix. This includes WooCommerce HPOS order tables on a normal
single-site installation.

= Does it send successful-backup emails? =

No. Version 0.1.0 sends email only when backup creation fails.

= Does it upload my data anywhere? =

No. TIM Backup Free has no external storage or telemetry integration.

= What happens when I uninstall the plugin? =

TIM Backup deliberately retains local backup archives and their index during
uninstallation to prevent accidental data loss. Download and delete archives
before uninstalling if you do not want to retain them.

== Changelog ==

= 0.1.0 =

* Initial development release.
* Added local full and database-only backups.
* Added signed manifests and SHA-256 payload verification.
* Added authenticated download, confirmed deletion, and database restore with an
  automatic safety backup.
* Added fixed weekly backup scheduling and three-archive rotation.
* Added an accessible tabbed administration interface.
* Added English source strings and German translations.

== Upgrade Notice ==

= 0.1.0 =

Initial development release.
