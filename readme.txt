=== Thumbnail Remover and Size Manager ===
Contributors: mehdiraized
Donate link: https://www.buymeacoffee.com/mehdiraized
Tags: thumbnails, media management, image optimization, cleanup, regenerate thumbnails
Requires at least: 5.0
Tested up to: 6.9
Stable tag: 2.2.3
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Safely analyze, preview, trash, restore, schedule, regenerate, report on, and manage WordPress thumbnails and image sizes.

== Description ==

Thumbnail Remover and Size Manager 2.2 is a safer and more complete media-maintenance workflow for WordPress.

Instead of deleting thumbnails blindly, the plugin now helps you:

* Preview cleanup results before removing files
* Move thumbnails to plugin Trash instead of deleting permanently
* Create a zip backup of the exact matching thumbnails before moving them to Trash
* Restore trashed thumbnails later if needed
* Process large libraries in batches with visible progress
* Detect orphan thumbnails left behind on disk
* Detect probably unused media items across post content, featured images, and common builder data
* Regenerate missing image sizes in batches
* Review per-size analytics including file counts, storage usage, missing sizes, and orphan counts
* Review recent activity logs and reporting summaries for cleanup, restore, backup, and regeneration workflows
* Disable selected image sizes for future uploads
* Create zip backups for all uploads or a specific year/month folder
* Schedule recurring cleanup runs with configurable frequency, size scope, and folder scope

This release is built for site owners, developers, agencies, and anyone trying to reduce thumbnail bloat without risking accidental data loss.

== Features ==

* Dry run / preview before cleanup
* Trash and Restore workflow for safer deletion
* Optional zip backup before moving matching thumbnails to Trash
* Batch processing with real progress for scan, cleanup, and regeneration
* Unused media detection
* Orphan thumbnail detection
* Regenerate missing sizes
* Per-size analytics dashboard
* Reporting and recent activity logs
* Advanced filters for image format, usage status, and orphan-only cleanup
* Image size disable controls for future uploads
* Media backup export to zip
* Scheduled cleanup powered by WP-Cron

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/thumbnail-remover` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the `Plugins` screen in WordPress.
3. Go to `Tools > Thumbnail Manager`.
4. Run a full analysis before your first cleanup so you can review thumbnail usage, orphan counts, and unused media signals.
5. Configure scheduled cleanup if you want recurring maintenance.

== Frequently Asked Questions ==

= Does the plugin permanently delete thumbnails? =
Not during cleanup. Version 2 moves matching thumbnail files into plugin Trash first so they can be restored later. To free disk space permanently, use `Delete Permanently` for one trash batch or `Empty Trash` for all batches in the Trash and Restore table.

= What does Preview do? =
Preview performs a dry run and shows how many files match your current selection, which sizes are involved, how much space can be recovered, and how many orphan thumbnails were found.

= What are orphan thumbnails? =
Orphan thumbnails are files such as `image-300x300.jpg` that still exist on disk but are no longer tracked in WordPress attachment metadata.

= How does unused media detection work? =
The plugin scans image attachments and checks for usage in featured images, parent attachments, post content, and stored builder/meta content. The results are best treated as “probably unused” so you can review them manually.

= Can I disable image sizes for future uploads without deleting old files? =
Yes. The size-disable settings prevent selected image sizes from being generated for future uploads only. Existing thumbnails are not removed unless you explicitly preview and move them to Trash.

= Can I regenerate missing sizes after enabling a size again? =
Yes. Use the `Regenerate Missing Sizes` section to rebuild missing image sizes in batches.

= Can I limit cleanup to only orphan thumbnails or probably unused media? =
Yes. The advanced filters can narrow analysis, cleanup, and regeneration jobs by image format, attachment usage status, and whether the thumbnail files are metadata-tracked or orphaned.

= Is the plugin suitable for larger media libraries? =
Yes. Analysis, cleanup, and regeneration are processed in batches with visible progress to reduce timeout problems on larger WordPress sites.

= Can I back up my files before cleanup? =
Yes. You can create a zip backup for all uploads or for a selected year/month folder, and cleanup can also create a zip backup of the exact matching thumbnails before they are moved to plugin Trash.

= Can I restore only one cleanup batch instead of everything? =
Yes. Each cleanup run creates its own Trash batch, so you can restore a specific batch independently.

= How do I empty the plugin Trash? =
Use `Empty Trash` in the Trash and Restore table to remove every batch, or use `Delete Permanently` for a single batch. This deletes the stored trash files and any backup stored with those batches, and it cannot be undone.

= Does scheduled cleanup delete files permanently? =
No. Scheduled cleanup uses the same plugin Trash flow as manual cleanup, so matching thumbnails can still be restored later.

= What is included in Reporting and Logs? =
The reporting section shows recent analysis, preview, cleanup, restore, regeneration, backup, and scheduled cleanup activity along with summary totals for recovered storage and other media operations.

= Does the plugin detect thumbnails that exist on disk but not in metadata? =
Yes. That is exactly what orphan thumbnail detection is for. These files are included in analysis and preview results so you can decide whether to move them to Trash.

= Should I trust unused media results as a final delete list? =
No. The plugin marks items as probably unused based on several WordPress relationships and content checks, but you should still review those results manually before broader media cleanup decisions.

== Screenshots ==

1. Library analysis with per-size analytics and unused media results
2. Dry run preview before moving thumbnails to Trash
3. Trash and Restore workflow
4. Batch regeneration of missing image sizes

== Changelog ==

= Unreleased =
* Added permanent delete actions for individual Trash batches and for emptying all plugin Trash.
* Added optional zip backups for matching thumbnails before they are moved to plugin Trash
* Stored cleanup backup links with Trash batches so previous cleanup backups remain downloadable from the Trash and Restore table
* Added advanced filters for image format, attachment usage, and orphan-only versus tracked cleanup
* Reused the new filters across analysis, preview cleanup, and regeneration workflows
* Updated the admin UI and documentation to explain the extra filtering controls

= 2.2.0 =
* Added a Reporting and Logs section with recent activity history
* Added summary cards for recovered storage, restored files, regenerated sizes, and backup runs
* Logged manual operations and scheduled cleanup events in a bounded admin-visible activity history

= 2.1.0 =
* Added WP-Cron scheduled cleanup with daily, weekly, and monthly frequency options
* Added scheduled cleanup scope controls for image sizes and upload folders
* Added scheduled cleanup status, next-run visibility, and last-result summaries in the admin UI
* Kept scheduled cleanup on the same Trash and Restore workflow as manual cleanup

= 2.0.0 =
* Added dry-run preview before cleanup
* Added Trash and Restore workflow for thumbnail removal
* Added batch processing with progress for analysis, cleanup, and regeneration
* Added orphan thumbnail detection
* Added probably unused media detection
* Added missing-size regeneration workflow
* Added per-size analytics dashboard
* Refreshed admin UI for the new v2 workflow
* Updated plugin description and documentation

= 1.1.5 =
* Fixed a fatal error when disabling thumbnail sizes on PHP 8.x
* Hardened settings and AJAX input handling for string-based values
* Updated compatibility metadata for the latest WordPress release

= 1.1.4 =
* Added new feature: Media file backup functionality
* Improved user interface for backup options
* Bug fixes and performance improvements

== Upgrade Notice ==

= 2.2.0 =
This update adds persistent reporting and recent activity logs for media operations in the admin screen.

= 2.1.0 =
This update adds WP-Cron scheduled cleanup with configurable scope while keeping the existing Trash and Restore safety workflow.

= 2.0.0 =
This major update introduces preview before cleanup, Trash and Restore, batch processing, unused media detection, orphan thumbnail detection, missing-size regeneration, and per-size analytics.
