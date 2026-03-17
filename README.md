# Thumbnail Remover and Size Manager

**Contributors:** mehdiraized  
**Tags:** thumbnails, media management, image optimization, cleanup, regenerate thumbnails  
**Requires at least:** 5.0  
**Tested up to:** 6.9  
**Stable tag:** 2.0.0  
**Requires PHP:** 7.4  
**License:** GPLv2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Safely analyze, preview, trash, restore, regenerate, and manage WordPress thumbnails and image sizes.

Landing page: [https://mehdiraized.github.io/thumbnail-remover/](https://mehdiraized.github.io/thumbnail-remover/)

WordPress.org: [https://wordpress.org/plugins/thumbnail-remover/](https://wordpress.org/plugins/thumbnail-remover/)

![Thumbnail Remover Banner](assets/banner-1544x500.png)

![Thumbnail Manager Library Analysis](assets/screenshots/screenshot-1.png)

## Overview

Thumbnail Remover and Size Manager 2.0 turns the plugin into a safer media maintenance workflow for WordPress sites.

Instead of removing thumbnails with a single irreversible step, v2 adds:

- Dry-run preview before cleanup
- Trash and Restore workflow
- Batch processing with progress
- Unused media detection
- Orphan thumbnail detection
- Missing-size regeneration
- Per-size analytics
- Existing image-size disable controls
- Zip backups for uploads

## Main Features

### Dry Run / Preview

Preview cleanup before acting. The plugin shows:

- How many files match your filters
- How much storage will be recovered
- Which sizes are involved
- How many orphan thumbnails were detected

### Trash / Restore

Matching thumbnails are moved into plugin Trash instead of being deleted permanently. You can restore a trash batch later from the same admin screen.

### Batch Processing

Large scans, cleanup jobs, and regeneration jobs run in batches and report progress in the interface to reduce timeout problems on larger sites.

### Unused Media Detection

The analysis screen flags attachments that appear unused based on:

- Featured image relationships
- Parent attachment relationships
- Post/page content references
- Stored post meta / common builder content references

These results should be reviewed manually before any broader media cleanup decisions.

### Orphan Thumbnail Detection

The plugin detects on-disk thumbnail files that are no longer tracked in WordPress attachment metadata.

### Regenerate Missing Sizes

If you keep or re-enable image sizes, you can regenerate missing derivatives in batch mode without regenerating everything manually.

### Per-Size Analytics

See per-size analytics including:

- File count
- Total storage used
- Missing size count
- Orphan count
- Last seen attachment date

## Installation

1. Upload the plugin files to `/wp-content/plugins/thumbnail-remover`, or install it through the WordPress plugins screen.
2. Activate the plugin.
3. Open `Tools > Thumbnail Manager`.
4. Run a full analysis before your first cleanup.

## Recommended Workflow

1. Run **Full Analysis**
2. Review per-size analytics, orphan counts, and probably unused media
3. Create a backup zip if needed
4. Use **Preview Cleanup**
5. Move matching thumbnails to **Trash**
6. Restore a batch if you need to reverse the cleanup
7. Regenerate missing sizes after re-enabling any image sizes

## FAQ

### Does the plugin permanently delete thumbnails?

No. Version 2 moves matching thumbnail files into plugin Trash first, so you can restore a cleanup batch later if needed.

### What does Preview Cleanup show before I remove anything?

Preview Cleanup runs a dry run and shows how many files match your current filters, which sizes are included, how much storage can be recovered, and how many orphan thumbnails were found.

### What are orphan thumbnails?

Orphan thumbnails are resized image files that still exist on disk but are no longer tracked in WordPress attachment metadata. The plugin can detect them during analysis and include them in preview and cleanup results.

### How does unused media detection work?

The plugin checks whether attachments appear to be used in featured images, parent attachment relationships, post or page content, and stored builder or meta content. These results are meant as "probably unused" signals and should still be reviewed manually.

### Can I disable image sizes for future uploads without touching old files?

Yes. You can disable selected registered image sizes for future uploads. This only stops new thumbnails from being generated. Existing files remain untouched until you preview and move them to Trash yourself.

### Can I regenerate missing sizes after re-enabling a size?

Yes. The regeneration tool can rebuild missing image sizes in batches, which is useful after re-enabling sizes or cleaning up incomplete media libraries.

### Is the plugin safe for larger media libraries?

It is designed to be safer on larger sites than one-click cleanup tools. Analysis, cleanup, and regeneration run in batches and show progress in the admin UI to reduce timeout issues.

### Can I back up uploads before cleanup?

Yes. You can create a zip backup for all uploads or only a selected year/month uploads folder before making cleanup changes.

### Can I restore only one cleanup batch?

Yes. Each cleanup run is stored as its own Trash batch, so you can restore a specific batch without undoing every previous cleanup action.

## Screenshots

### Library Analysis

![Library Analysis](assets/screenshots/screenshot-1.png)

### Preview and Move Thumbnails to Trash

![Preview and Move Thumbnails to Trash](assets/screenshots/screenshot-2.png)

### Trash and Restore

![Trash and Restore](assets/screenshots/screenshot-3.png)

### Regenerate Missing Sizes

![Regenerate Missing Sizes](assets/screenshots/screenshot-4.png)

## Changelog

### 2.0.0

- Added dry-run preview before cleanup
- Added Trash and Restore workflow
- Added batch processing with progress for analysis, cleanup, and regeneration
- Added orphan thumbnail detection
- Added probably unused media detection
- Added missing-size regeneration
- Added per-size analytics dashboard
- Refreshed the admin UI and plugin descriptions

### 1.1.5

- Fixed a fatal error when disabling thumbnail sizes on PHP 8.x
- Hardened settings and AJAX input handling for string-based values
- Updated compatibility metadata for the latest WordPress release

## Support

If the plugin saves you time, you can support future development here:

[Buy Me a Coffee](https://www.buymeacoffee.com/mehdiraized)
