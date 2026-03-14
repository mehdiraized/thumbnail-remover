<?php
/*
Plugin Name: Thumbnail Remover and Size Manager
Plugin URI: https://github.com/mehdiraized/thumbnail-remover/
Description: Analyze, preview, trash, restore, regenerate, and manage WordPress thumbnails and image sizes from one screen.
Short Description: Safely manage WordPress thumbnails with preview, trash, restore, analytics, orphan cleanup, unused media detection, and regeneration.
Version: 2.0.0
Author: Mehdi Rezaei
Author URI: https://mehd.ir
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: thumbnail-remover
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TRPL_VERSION', '2.0.0' );
define( 'TRPL_DISABLED_SIZES_OPTION', 'trpl_disabled_image_sizes' );
define( 'TRPL_JOBS_OPTION', 'trpl_jobs' );
define( 'TRPL_TRASH_DIRNAME', 'trpl-trash' );

function trpl_enqueue_styles( $hook ) {
	if ( 'tools_page_thumbnail-manager' !== $hook ) {
		return;
	}

	wp_enqueue_style(
		'thumbnail-manager-style',
		plugin_dir_url( __FILE__ ) . 'assets/css/style.css',
		array(),
		filemtime( plugin_dir_path( __FILE__ ) . 'assets/css/style.css' )
	);
}
add_action( 'admin_enqueue_scripts', 'trpl_enqueue_styles' );

function trpl_enqueue_scripts( $hook ) {
	if ( 'tools_page_thumbnail-manager' !== $hook ) {
		return;
	}

	wp_enqueue_script(
		'thumbnail-manager-script',
		plugin_dir_url( __FILE__ ) . 'assets/js/script.js',
		array( 'jquery' ),
		filemtime( plugin_dir_path( __FILE__ ) . 'assets/js/script.js' ),
		true
	);

	wp_localize_script(
		'thumbnail-manager-script',
		'thumbnailManager',
		array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'thumbnail-manager-nonce' ),
			'availableDates' => trpl_get_available_dates(),
			'i18n' => array(
				'processing' => __( 'Processing...', 'thumbnail-remover' ),
				'error' => __( 'An error occurred. Please try again.', 'thumbnail-remover' ),
				'previewEmpty' => __( 'No matching thumbnails were found for the current selection.', 'thumbnail-remover' ),
				'confirmTrash' => __( 'Selected thumbnails will be moved to Trash so they can be restored later. Continue?', 'thumbnail-remover' ),
				'confirmRestore' => __( 'Restore this trash batch?', 'thumbnail-remover' ),
				'confirmRegenerate' => __( 'Regenerate missing image sizes for the selected attachments?', 'thumbnail-remover' ),
				'selectOneFolder' => __( 'Please select at least one folder.', 'thumbnail-remover' ),
				'selectYearMonth' => __( 'Please select both year and month for date-specific backup.', 'thumbnail-remover' ),
			),
		)
	);
}
add_action( 'admin_enqueue_scripts', 'trpl_enqueue_scripts' );

function trpl_load_textdomain() {
	load_plugin_textdomain( 'thumbnail-remover', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'trpl_load_textdomain' );

function trpl_admin_menu() {
	add_management_page(
		__( 'Thumbnail Manager', 'thumbnail-remover' ),
		__( 'Thumbnail Manager', 'thumbnail-remover' ),
		'manage_options',
		'thumbnail-manager',
		'trpl_admin_page'
	);
}
add_action( 'admin_menu', 'trpl_admin_menu' );

function trpl_admin_notice( $message, $type = 'updated' ) {
	printf( '<div class="%1$s notice"><p>%2$s</p></div>', esc_attr( $type ), wp_kses_post( $message ) );
}

function trpl_require_manage_options() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error(
			array(
				'message' => __( 'Unauthorized access.', 'thumbnail-remover' ),
			)
		);
	}
}

function trpl_get_upload_dir() {
	return wp_upload_dir();
}

function trpl_get_upload_base_dir() {
	$upload_dir = trpl_get_upload_dir();
	return trailingslashit( $upload_dir['basedir'] );
}

function trpl_get_upload_base_url() {
	$upload_dir = trpl_get_upload_dir();
	return trailingslashit( $upload_dir['baseurl'] );
}

function trpl_get_trash_base_dir() {
	return trpl_get_upload_base_dir() . TRPL_TRASH_DIRNAME . '/';
}

function trpl_get_all_image_sizes() {
	global $_wp_additional_image_sizes;

	$sizes = array();

	foreach ( get_intermediate_image_sizes() as $_size ) {
		if ( in_array( $_size, array( 'thumbnail', 'medium', 'medium_large', 'large' ), true ) ) {
			$sizes[ $_size ] = array(
				'width' => (int) get_option( "{$_size}_size_w" ),
				'height' => (int) get_option( "{$_size}_size_h" ),
				'crop' => (bool) get_option( "{$_size}_crop" ),
			);
		} elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
			$sizes[ $_size ] = array(
				'width' => (int) $_wp_additional_image_sizes[ $_size ]['width'],
				'height' => (int) $_wp_additional_image_sizes[ $_size ]['height'],
				'crop' => ! empty( $_wp_additional_image_sizes[ $_size ]['crop'] ),
			);
		}
	}

	ksort( $sizes );

	return $sizes;
}

function trpl_get_size_dimension_lookup() {
	$lookup = array();

	foreach ( trpl_get_all_image_sizes() as $size_name => $details ) {
		$key = $details['width'] . 'x' . $details['height'];
		if ( '0x0' !== $key ) {
			if ( ! isset( $lookup[ $key ] ) ) {
				$lookup[ $key ] = array();
			}
			$lookup[ $key ][] = $size_name;
		}
	}

	return $lookup;
}

function trpl_is_supported_image_path( $path ) {
	return (bool) preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', (string) $path );
}

function trpl_is_thumbnail_filename( $filename ) {
	return (bool) preg_match( '/-\d+x\d+\.(jpe?g|png|gif|webp|avif)$/i', $filename );
}

function trpl_normalize_text_list( $values, $split_string = false ) {
	if ( empty( $values ) ) {
		return array();
	}

	if ( is_string( $values ) ) {
		$values = $split_string ? explode( ',', $values ) : array( $values );
	} elseif ( ! is_array( $values ) ) {
		$values = (array) $values;
	}

	$values = array_map( 'sanitize_text_field', wp_unslash( $values ) );
	$values = array_map( 'trim', $values );

	return array_values( array_unique( array_filter( $values, 'strlen' ) ) );
}

function trpl_normalize_disabled_sizes( $sizes ) {
	return trpl_normalize_text_list( $sizes, true );
}

function trpl_relative_path_to_folder( $relative_path ) {
	$parts = explode( '/', ltrim( $relative_path, '/' ) );

	if ( count( $parts ) >= 3 && preg_match( '/^\d{4}$/', $parts[0] ) && preg_match( '/^\d{2}$/', $parts[1] ) ) {
		return $parts[0] . '/' . $parts[1];
	}

	return __( 'Unorganized', 'thumbnail-remover' );
}

function trpl_get_upload_folders_with_count() {
	$attachments = trpl_get_image_attachment_ids();
	$folders = array();

	foreach ( $attachments as $attachment_id ) {
		$relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! $relative_path ) {
			continue;
		}

		$folder = trpl_relative_path_to_folder( $relative_path );
		if ( ! isset( $folders[ $folder ] ) ) {
			$folders[ $folder ] = 0;
		}
		$folders[ $folder ]++;
	}

	krsort( $folders );

	return $folders;
}

function trpl_get_available_dates() {
	$folders = trpl_get_upload_folders_with_count();
	$available_dates = array();

	foreach ( array_keys( $folders ) as $folder ) {
		if ( preg_match( '/^(\d{4})\/(\d{2})$/', $folder, $matches ) ) {
			if ( ! isset( $available_dates[ $matches[1] ] ) ) {
				$available_dates[ $matches[1] ] = array();
			}
			$available_dates[ $matches[1] ][] = $matches[2];
		}
	}

	return $available_dates;
}

function trpl_get_image_attachment_ids() {
	$attachments = get_posts(
		array(
			'post_type' => 'attachment',
			'post_mime_type' => 'image',
			'post_status' => 'inherit',
			'fields' => 'ids',
			'posts_per_page' => -1,
			'orderby' => 'ID',
			'order' => 'ASC',
			'no_found_rows' => true,
		)
	);

	return array_map( 'intval', $attachments );
}

function trpl_attachment_matches_folders( $attachment_id, $selected_folders ) {
	if ( empty( $selected_folders ) ) {
		return true;
	}

	$relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );
	if ( ! $relative_path ) {
		return false;
	}

	$folder = trpl_relative_path_to_folder( $relative_path );

	return in_array( $folder, $selected_folders, true );
}

function trpl_get_attachment_files( $attachment_id ) {
	$records = array();
	$relative_path = get_post_meta( $attachment_id, '_wp_attached_file', true );

	if ( empty( $relative_path ) ) {
		return $records;
	}

	$relative_path = ltrim( $relative_path, '/' );
	$base_dir = trpl_get_upload_base_dir();
	$original_path = $base_dir . $relative_path;
	$metadata = wp_get_attachment_metadata( $attachment_id );
	$metadata_sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
	$registered_sizes = trpl_get_all_image_sizes();
	$dimension_lookup = trpl_get_size_dimension_lookup();
	$directory = trailingslashit( dirname( $original_path ) );
	$stem = pathinfo( wp_basename( $original_path ), PATHINFO_FILENAME );
	$metadata_filenames = array();

	foreach ( $metadata_sizes as $size_name => $size_data ) {
		if ( empty( $size_data['file'] ) ) {
			continue;
		}

		$metadata_filenames[] = $size_data['file'];
		$size_path = $directory . $size_data['file'];
		$records[] = array(
			'attachment_id' => $attachment_id,
			'size_name' => $size_name,
			'size_label' => isset( $registered_sizes[ $size_name ] ) ? $size_name : sprintf( '%s (%s)', $size_name, __( 'legacy', 'thumbnail-remover' ) ),
			'path' => $size_path,
			'relative_path' => ltrim( str_replace( $base_dir, '', $size_path ), '/' ),
			'bytes' => file_exists( $size_path ) ? (int) filesize( $size_path ) : 0,
			'is_orphan' => false,
			'source' => 'metadata',
		);
	}

	if ( is_dir( $directory ) ) {
		$matches = glob( $directory . $stem . '-*x*.*' );
		if ( is_array( $matches ) ) {
			foreach ( $matches as $candidate ) {
				$basename = wp_basename( $candidate );

				if ( ! trpl_is_thumbnail_filename( $basename ) ) {
					continue;
				}

				if ( in_array( $basename, $metadata_filenames, true ) ) {
					continue;
				}

				if ( ! preg_match( '/-(\d+)x(\d+)\.(jpe?g|png|gif|webp|avif)$/i', $basename, $size_match ) ) {
					continue;
				}

				$dimension_key = $size_match[1] . 'x' . $size_match[2];
				$matching_sizes = isset( $dimension_lookup[ $dimension_key ] ) ? $dimension_lookup[ $dimension_key ] : array();

				$records[] = array(
					'attachment_id' => $attachment_id,
					'size_name' => '',
					'size_label' => ! empty( $matching_sizes ) ? implode( ', ', $matching_sizes ) : $dimension_key,
					'path' => $candidate,
					'relative_path' => ltrim( str_replace( $base_dir, '', $candidate ), '/' ),
					'bytes' => file_exists( $candidate ) ? (int) filesize( $candidate ) : 0,
					'is_orphan' => true,
					'source' => 'filesystem',
				);
			}
		}
	}

	return $records;
}

function trpl_record_matches_selected_sizes( $record, $selected_sizes ) {
	if ( empty( $selected_sizes ) ) {
		return true;
	}

	if ( ! empty( $record['size_name'] ) && in_array( $record['size_name'], $selected_sizes, true ) ) {
		return true;
	}

	if ( preg_match( '/-(\d+x\d+)\./i', wp_basename( $record['path'] ), $matches ) ) {
		return in_array( $matches[1], $selected_sizes, true );
	}

	return false;
}

function trpl_build_removal_candidates( $selected_sizes, $selected_folders ) {
	$candidates = array();

	foreach ( trpl_get_image_attachment_ids() as $attachment_id ) {
		if ( ! trpl_attachment_matches_folders( $attachment_id, $selected_folders ) ) {
			continue;
		}

		foreach ( trpl_get_attachment_files( $attachment_id ) as $record ) {
			if ( trpl_record_matches_selected_sizes( $record, $selected_sizes ) && file_exists( $record['path'] ) ) {
				$candidates[] = $record;
			}
		}
	}

	return $candidates;
}

function trpl_build_regeneration_attachment_ids( $selected_folders ) {
	$attachment_ids = array();

	foreach ( trpl_get_image_attachment_ids() as $attachment_id ) {
		if ( trpl_attachment_matches_folders( $attachment_id, $selected_folders ) ) {
			$attachment_ids[] = $attachment_id;
		}
	}

	return $attachment_ids;
}

function trpl_is_attachment_used( $attachment_id ) {
	global $wpdb;

	$attachment = get_post( $attachment_id );
	if ( ! $attachment ) {
		return false;
	}

	if ( ! empty( $attachment->post_parent ) && 'trash' !== get_post_status( $attachment->post_parent ) ) {
		return true;
	}

	$featured_usage = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s",
			(string) $attachment_id
		)
	);

	if ( $featured_usage > 0 ) {
		return true;
	}

	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	$url = wp_get_attachment_url( $attachment_id );
	$needles = array_filter(
		array(
			$file ? wp_basename( $file ) : '',
			$url ? wp_basename( $url ) : '',
		)
	);

	foreach ( array_unique( $needles ) as $needle ) {
		$like = '%' . $wpdb->esc_like( $needle ) . '%';

		$content_usage = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type != 'attachment' AND post_status NOT IN ('trash', 'auto-draft') AND post_content LIKE %s",
				$like
			)
		);

		if ( $content_usage > 0 ) {
			return true;
		}

		$builder_usage = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type != 'attachment' AND p.post_status NOT IN ('trash', 'auto-draft') AND pm.meta_value LIKE %s",
				$like
			)
		);

		if ( $builder_usage > 0 ) {
			return true;
		}
	}

	return false;
}

function trpl_build_unused_media_entry( $attachment_id ) {
	$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
	$path = $file ? trpl_get_upload_base_dir() . ltrim( $file, '/' ) : '';
	$post = get_post( $attachment_id );

	return array(
		'attachment_id' => $attachment_id,
		'title' => $post ? $post->post_title : '',
		'edit_link' => get_edit_post_link( $attachment_id, '' ),
		'url' => wp_get_attachment_url( $attachment_id ),
		'relative_path' => $file,
		'bytes' => $path && file_exists( $path ) ? (int) filesize( $path ) : 0,
		'date' => $post ? mysql2date( get_option( 'date_format' ), $post->post_date ) : '',
	);
}

function trpl_init_size_analytics() {
	$analytics = array();

	foreach ( trpl_get_all_image_sizes() as $size_name => $details ) {
		$analytics[ $size_name ] = array(
			'label' => $size_name,
			'dimensions' => $details['width'] . 'x' . $details['height'],
			'count' => 0,
			'bytes' => 0,
			'last_seen' => '',
			'missing' => 0,
			'orphans' => 0,
		);
	}

	return $analytics;
}

function trpl_start_job( $type, $payload ) {
	$jobs = get_option( TRPL_JOBS_OPTION, array() );
	$job_id = uniqid( 'trpl_', true );
	$jobs[ $job_id ] = array_merge(
		array(
			'id' => $job_id,
			'type' => $type,
			'created_at' => time(),
			'processed' => 0,
			'total' => 0,
		),
		$payload
	);
	update_option( TRPL_JOBS_OPTION, $jobs, false );

	return $jobs[ $job_id ];
}

function trpl_get_job( $job_id ) {
	$jobs = get_option( TRPL_JOBS_OPTION, array() );
	return isset( $jobs[ $job_id ] ) ? $jobs[ $job_id ] : null;
}

function trpl_save_job( $job ) {
	$jobs = get_option( TRPL_JOBS_OPTION, array() );
	$jobs[ $job['id'] ] = $job;
	update_option( TRPL_JOBS_OPTION, $jobs, false );
}

function trpl_delete_job( $job_id ) {
	$jobs = get_option( TRPL_JOBS_OPTION, array() );
	unset( $jobs[ $job_id ] );
	update_option( TRPL_JOBS_OPTION, $jobs, false );
}

function trpl_calculate_progress( $processed, $total ) {
	if ( $total <= 0 ) {
		return 100;
	}

	return min( 100, round( ( $processed / $total ) * 100, 2 ) );
}

function trpl_create_preview_summary( $candidates ) {
	$summary = array(
		'total_files' => count( $candidates ),
		'total_bytes' => 0,
		'orphans' => 0,
		'sizes' => array(),
	);

	foreach ( $candidates as $record ) {
		$summary['total_bytes'] += (int) $record['bytes'];

		if ( ! isset( $summary['sizes'][ $record['size_label'] ] ) ) {
			$summary['sizes'][ $record['size_label'] ] = array(
				'count' => 0,
				'bytes' => 0,
			);
		}

		$summary['sizes'][ $record['size_label'] ]['count']++;
		$summary['sizes'][ $record['size_label'] ]['bytes'] += (int) $record['bytes'];

		if ( ! empty( $record['is_orphan'] ) ) {
			$summary['orphans']++;
		}
	}

	uasort(
		$summary['sizes'],
		function ( $left, $right ) {
			return (int) $right['bytes'] <=> (int) $left['bytes'];
		}
	);

	return $summary;
}

function trpl_ensure_directory( $path ) {
	if ( ! file_exists( $path ) ) {
		wp_mkdir_p( $path );
	}
}

function trpl_create_trash_batch() {
	$batch_id = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
	$batch_dir = trpl_get_trash_base_dir() . $batch_id . '/';
	trpl_ensure_directory( $batch_dir . 'files/' );

	$manifest = array(
		'id' => $batch_id,
		'created_at' => time(),
		'restored_at' => 0,
		'status' => 'active',
		'items' => array(),
		'total_bytes' => 0,
	);
	trpl_write_trash_manifest( $batch_id, $manifest );

	return $batch_id;
}

function trpl_get_trash_manifest_path( $batch_id ) {
	return trpl_get_trash_base_dir() . $batch_id . '/manifest.json';
}

function trpl_write_trash_manifest( $batch_id, $manifest ) {
	trpl_ensure_directory( dirname( trpl_get_trash_manifest_path( $batch_id ) ) );
	file_put_contents( trpl_get_trash_manifest_path( $batch_id ), wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
}

function trpl_read_trash_manifest( $batch_id ) {
	$path = trpl_get_trash_manifest_path( $batch_id );
	if ( ! file_exists( $path ) ) {
		return null;
	}

	$manifest = json_decode( file_get_contents( $path ), true );
	return is_array( $manifest ) ? $manifest : null;
}

function trpl_move_file_to_trash( $batch_id, $record ) {
	$manifest = trpl_read_trash_manifest( $batch_id );
	if ( ! $manifest ) {
		return false;
	}

	$source_path = $record['path'];
	if ( ! file_exists( $source_path ) ) {
		return false;
	}

	$trash_path = trpl_get_trash_base_dir() . $batch_id . '/files/' . $record['relative_path'];
	trpl_ensure_directory( dirname( $trash_path ) );

	$moved = @rename( $source_path, $trash_path );
	if ( ! $moved ) {
		$moved = @copy( $source_path, $trash_path );
		if ( $moved ) {
			@unlink( $source_path );
		}
	}

	if ( ! $moved ) {
		return false;
	}

	$manifest['items'][] = array(
		'relative_path' => $record['relative_path'],
		'trash_path' => ltrim( str_replace( trpl_get_trash_base_dir() . $batch_id . '/files/', '', $trash_path ), '/' ),
		'attachment_id' => (int) $record['attachment_id'],
		'size_name' => $record['size_name'],
		'size_label' => $record['size_label'],
		'bytes' => (int) $record['bytes'],
		'is_orphan' => ! empty( $record['is_orphan'] ),
	);
	$manifest['total_bytes'] += (int) $record['bytes'];
	trpl_write_trash_manifest( $batch_id, $manifest );

	return true;
}

function trpl_get_trash_batches() {
	$trash_dir = trpl_get_trash_base_dir();
	if ( ! is_dir( $trash_dir ) ) {
		return array();
	}

	$batches = array();
	foreach ( glob( $trash_dir . '*/manifest.json' ) as $manifest_path ) {
		$manifest = json_decode( file_get_contents( $manifest_path ), true );
		if ( is_array( $manifest ) ) {
			$batches[] = $manifest;
		}
	}

	usort(
		$batches,
		function ( $left, $right ) {
			return (int) $right['created_at'] - (int) $left['created_at'];
		}
	);

	return $batches;
}

function trpl_restore_trash_batch( $batch_id ) {
	$manifest = trpl_read_trash_manifest( $batch_id );
	if ( ! $manifest || empty( $manifest['items'] ) ) {
		return new WP_Error( 'trpl_missing_manifest', __( 'Trash batch not found.', 'thumbnail-remover' ) );
	}

	$restored = 0;

	foreach ( $manifest['items'] as $item ) {
		$source = trpl_get_trash_base_dir() . $batch_id . '/files/' . $item['trash_path'];
		$destination = trpl_get_upload_base_dir() . $item['relative_path'];

		if ( ! file_exists( $source ) ) {
			continue;
		}

		trpl_ensure_directory( dirname( $destination ) );
		if ( @rename( $source, $destination ) || ( @copy( $source, $destination ) && @unlink( $source ) ) ) {
			$restored++;
		}
	}

	$manifest['status'] = 'restored';
	$manifest['restored_at'] = time();
	trpl_write_trash_manifest( $batch_id, $manifest );

	return array(
		'restored' => $restored,
		'message' => sprintf(
			/* translators: %d: restored files count. */
			__( 'Restored %d file(s) from trash.', 'thumbnail-remover' ),
			$restored
		),
	);
}

function trpl_filter_size_analytics( $analytics ) {
	foreach ( $analytics as $size_name => $row ) {
		if ( empty( $row['count'] ) && empty( $row['missing'] ) && empty( $row['orphans'] ) ) {
			unset( $analytics[ $size_name ] );
		}
	}

	return $analytics;
}

function trpl_create_analysis_job( $selected_folders ) {
	$attachment_ids = trpl_build_regeneration_attachment_ids( $selected_folders );

	return trpl_start_job(
		'analysis',
		array(
			'attachment_ids' => $attachment_ids,
			'selected_folders' => $selected_folders,
			'processed' => 0,
			'total' => count( $attachment_ids ),
			'summary' => array(
				'attachments' => 0,
				'thumbnail_files' => 0,
				'thumbnail_bytes' => 0,
				'orphans' => 0,
				'missing_sizes' => 0,
				'unused_media' => 0,
				'unused_media_bytes' => 0,
				'size_analytics' => trpl_init_size_analytics(),
				'unused_items' => array(),
			),
		)
	);
}

function trpl_process_analysis_job( &$job, $batch_size = 12 ) {
	$attachment_ids = $job['attachment_ids'];
	$summary = $job['summary'];
	$registered_sizes = trpl_get_all_image_sizes();
	$chunk = array_slice( $attachment_ids, $job['processed'], $batch_size );

	foreach ( $chunk as $attachment_id ) {
		$summary['attachments']++;
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$metadata_sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		$attachment_post = get_post( $attachment_id );
		$attachment_date = $attachment_post ? mysql2date( 'Y-m-d H:i:s', $attachment_post->post_date ) : '';

		foreach ( trpl_get_attachment_files( $attachment_id ) as $record ) {
			$summary['thumbnail_files']++;
			$summary['thumbnail_bytes'] += (int) $record['bytes'];

			if ( ! isset( $summary['size_analytics'][ $record['size_label'] ] ) ) {
				$summary['size_analytics'][ $record['size_label'] ] = array(
					'label' => $record['size_label'],
					'dimensions' => '',
					'count' => 0,
					'bytes' => 0,
					'last_seen' => '',
					'missing' => 0,
					'orphans' => 0,
				);
			}

			$summary['size_analytics'][ $record['size_label'] ]['count']++;
			$summary['size_analytics'][ $record['size_label'] ]['bytes'] += (int) $record['bytes'];
			$summary['size_analytics'][ $record['size_label'] ]['last_seen'] = $attachment_date;

			if ( ! empty( $record['is_orphan'] ) ) {
				$summary['orphans']++;
				$summary['size_analytics'][ $record['size_label'] ]['orphans']++;
			}
		}

		foreach ( array_keys( $registered_sizes ) as $size_name ) {
			if ( ! isset( $metadata_sizes[ $size_name ] ) ) {
				$summary['missing_sizes']++;
				if ( ! isset( $summary['size_analytics'][ $size_name ] ) ) {
					$summary['size_analytics'][ $size_name ] = array(
						'label' => $size_name,
						'dimensions' => '',
						'count' => 0,
						'bytes' => 0,
						'last_seen' => '',
						'missing' => 0,
						'orphans' => 0,
					);
				}
				$summary['size_analytics'][ $size_name ]['missing']++;
			}
		}

		if ( ! trpl_is_attachment_used( $attachment_id ) ) {
			$entry = trpl_build_unused_media_entry( $attachment_id );
			$summary['unused_media']++;
			$summary['unused_media_bytes'] += (int) $entry['bytes'];

			if ( count( $summary['unused_items'] ) < 25 ) {
				$summary['unused_items'][] = $entry;
			}
		}
	}

	$job['processed'] += count( $chunk );
	$job['summary'] = $summary;

	return $job['processed'] >= $job['total'];
}

function trpl_create_delete_job( $selected_sizes, $selected_folders ) {
	$candidates = trpl_build_removal_candidates( $selected_sizes, $selected_folders );
	$batch_id = trpl_create_trash_batch();

	return trpl_start_job(
		'delete',
		array(
			'items' => array_values( $candidates ),
			'processed' => 0,
			'total' => count( $candidates ),
			'selected_sizes' => $selected_sizes,
			'selected_folders' => $selected_folders,
			'trash_batch_id' => $batch_id,
			'result' => array(
				'moved' => 0,
				'bytes' => 0,
				'orphans' => 0,
			),
		)
	);
}

function trpl_process_delete_job( &$job, $batch_size = 40 ) {
	$chunk = array_slice( $job['items'], $job['processed'], $batch_size );

	foreach ( $chunk as $record ) {
		if ( trpl_move_file_to_trash( $job['trash_batch_id'], $record ) ) {
			$job['result']['moved']++;
			$job['result']['bytes'] += (int) $record['bytes'];
			if ( ! empty( $record['is_orphan'] ) ) {
				$job['result']['orphans']++;
			}
		}
	}

	$job['processed'] += count( $chunk );

	return $job['processed'] >= $job['total'];
}

function trpl_create_regenerate_job( $selected_sizes, $selected_folders ) {
	$attachment_ids = trpl_build_regeneration_attachment_ids( $selected_folders );

	return trpl_start_job(
		'regenerate',
		array(
			'attachment_ids' => $attachment_ids,
			'selected_sizes' => $selected_sizes,
			'selected_folders' => $selected_folders,
			'processed' => 0,
			'total' => count( $attachment_ids ),
			'result' => array(
				'attachments' => 0,
				'generated' => 0,
			),
		)
	);
}

function trpl_process_regenerate_job( &$job, $batch_size = 8 ) {
	$chunk = array_slice( $job['attachment_ids'], $job['processed'], $batch_size );
	$selected_sizes = $job['selected_sizes'];

	foreach ( $chunk as $attachment_id ) {
		$before = wp_get_attachment_metadata( $attachment_id );
		$before_sizes = isset( $before['sizes'] ) && is_array( $before['sizes'] ) ? array_keys( $before['sizes'] ) : array();

		$filter = null;
		if ( ! empty( $selected_sizes ) ) {
			$filter = function ( $sizes ) use ( $selected_sizes ) {
				return array_intersect_key( $sizes, array_flip( $selected_sizes ) );
			};
			add_filter( 'intermediate_image_sizes_advanced', $filter );
		}

		if ( function_exists( 'wp_update_image_subsizes' ) ) {
			wp_update_image_subsizes( $attachment_id );
		} else {
			$file = get_attached_file( $attachment_id );
			if ( $file && file_exists( $file ) ) {
				$metadata = wp_generate_attachment_metadata( $attachment_id, $file );
				if ( ! is_wp_error( $metadata ) && ! empty( $metadata ) ) {
					wp_update_attachment_metadata( $attachment_id, $metadata );
				}
			}
		}

		if ( $filter ) {
			remove_filter( 'intermediate_image_sizes_advanced', $filter );
		}

		$after = wp_get_attachment_metadata( $attachment_id );
		$after_sizes = isset( $after['sizes'] ) && is_array( $after['sizes'] ) ? array_keys( $after['sizes'] ) : array();
		$generated_now = array_diff( $after_sizes, $before_sizes );

		if ( ! empty( $selected_sizes ) ) {
			$generated_now = array_intersect( $generated_now, $selected_sizes );
		}

		$job['result']['attachments']++;
		$job['result']['generated'] += count( $generated_now );
	}

	$job['processed'] += count( $chunk );

	return $job['processed'] >= $job['total'];
}

function trpl_ajax_preview_delete() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$selected_sizes = isset( $_POST['sizes'] ) ? trpl_normalize_text_list( $_POST['sizes'] ) : array();
	$selected_folders = isset( $_POST['folders'] ) ? trpl_normalize_text_list( $_POST['folders'] ) : array();
	$candidates = trpl_build_removal_candidates( $selected_sizes, $selected_folders );
	$summary = trpl_create_preview_summary( $candidates );

	wp_send_json_success(
		array(
			'summary' => array(
				'total_files' => $summary['total_files'],
				'total_size' => size_format( $summary['total_bytes'] ),
				'total_bytes' => $summary['total_bytes'],
				'orphans' => $summary['orphans'],
				'sizes' => $summary['sizes'],
			),
		)
	);
}
add_action( 'wp_ajax_trpl_preview_delete', 'trpl_ajax_preview_delete' );

function trpl_ajax_start_analysis() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$selected_folders = isset( $_POST['folders'] ) ? trpl_normalize_text_list( $_POST['folders'] ) : array();
	$job = trpl_create_analysis_job( $selected_folders );

	wp_send_json_success(
		array(
			'job_id' => $job['id'],
			'total' => $job['total'],
		)
	);
}
add_action( 'wp_ajax_trpl_start_analysis', 'trpl_ajax_start_analysis' );

function trpl_ajax_process_analysis() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
	$job = trpl_get_job( $job_id );

	if ( ! $job || 'analysis' !== $job['type'] ) {
		wp_send_json_error( array( 'message' => __( 'Analysis job not found.', 'thumbnail-remover' ) ) );
	}

	$is_complete = trpl_process_analysis_job( $job );
	trpl_save_job( $job );

	$response = array(
		'progress' => trpl_calculate_progress( $job['processed'], $job['total'] ),
		'processed' => $job['processed'],
		'total' => $job['total'],
		'complete' => $is_complete,
	);

	if ( $is_complete ) {
		$summary = $job['summary'];
		$summary['size_analytics'] = trpl_filter_size_analytics( $summary['size_analytics'] );
		$response['summary'] = $summary;
		trpl_delete_job( $job_id );
	}

	wp_send_json_success( $response );
}
add_action( 'wp_ajax_trpl_process_analysis', 'trpl_ajax_process_analysis' );

function trpl_ajax_start_delete() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$selected_sizes = isset( $_POST['sizes'] ) ? trpl_normalize_text_list( $_POST['sizes'] ) : array();
	$selected_folders = isset( $_POST['folders'] ) ? trpl_normalize_text_list( $_POST['folders'] ) : array();
	$job = trpl_create_delete_job( $selected_sizes, $selected_folders );

	wp_send_json_success(
		array(
			'job_id' => $job['id'],
			'total' => $job['total'],
			'trash_batch_id' => $job['trash_batch_id'],
		)
	);
}
add_action( 'wp_ajax_trpl_start_delete', 'trpl_ajax_start_delete' );

function trpl_ajax_process_delete() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
	$job = trpl_get_job( $job_id );

	if ( ! $job || 'delete' !== $job['type'] ) {
		wp_send_json_error( array( 'message' => __( 'Delete job not found.', 'thumbnail-remover' ) ) );
	}

	$is_complete = trpl_process_delete_job( $job );
	trpl_save_job( $job );

	$response = array(
		'progress' => trpl_calculate_progress( $job['processed'], $job['total'] ),
		'processed' => $job['processed'],
		'total' => $job['total'],
		'complete' => $is_complete,
	);

	if ( $is_complete ) {
		$response['result'] = $job['result'];
		$response['trash_batch_id'] = $job['trash_batch_id'];
		trpl_delete_job( $job_id );
	}

	wp_send_json_success( $response );
}
add_action( 'wp_ajax_trpl_process_delete', 'trpl_ajax_process_delete' );

function trpl_ajax_restore_trash() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$batch_id = isset( $_POST['batch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['batch_id'] ) ) : '';
	$result = trpl_restore_trash_batch( $batch_id );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}

	wp_send_json_success( $result );
}
add_action( 'wp_ajax_trpl_restore_trash', 'trpl_ajax_restore_trash' );

function trpl_ajax_start_regenerate() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$selected_sizes = isset( $_POST['sizes'] ) ? trpl_normalize_text_list( $_POST['sizes'] ) : array();
	$selected_folders = isset( $_POST['folders'] ) ? trpl_normalize_text_list( $_POST['folders'] ) : array();
	$job = trpl_create_regenerate_job( $selected_sizes, $selected_folders );

	wp_send_json_success(
		array(
			'job_id' => $job['id'],
			'total' => $job['total'],
		)
	);
}
add_action( 'wp_ajax_trpl_start_regenerate', 'trpl_ajax_start_regenerate' );

function trpl_ajax_process_regenerate() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';
	$job = trpl_get_job( $job_id );

	if ( ! $job || 'regenerate' !== $job['type'] ) {
		wp_send_json_error( array( 'message' => __( 'Regeneration job not found.', 'thumbnail-remover' ) ) );
	}

	$is_complete = trpl_process_regenerate_job( $job );
	trpl_save_job( $job );

	$response = array(
		'progress' => trpl_calculate_progress( $job['processed'], $job['total'] ),
		'processed' => $job['processed'],
		'total' => $job['total'],
		'complete' => $is_complete,
	);

	if ( $is_complete ) {
		$response['result'] = $job['result'];
		trpl_delete_job( $job_id );
	}

	wp_send_json_success( $response );
}
add_action( 'wp_ajax_trpl_process_regenerate', 'trpl_ajax_process_regenerate' );

function trpl_backup_images_ajax() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );
	trpl_require_manage_options();

	$upload_dir = trpl_get_upload_dir();
	$base_dir = $upload_dir['basedir'];
	$backup_type = isset( $_POST['backup_type'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_type'] ) ) : 'all';
	$backup_year = isset( $_POST['backup_year'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_year'] ) ) : '';
	$backup_month = isset( $_POST['backup_month'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_month'] ) ) : '';

	if ( 'date' === $backup_type && ( ! $backup_year || ! $backup_month ) ) {
		wp_send_json_error( array( 'message' => __( 'Please select both year and month for date-specific backup.', 'thumbnail-remover' ) ) );
	}

	$source_dir = 'date' === $backup_type ? $base_dir . '/' . $backup_year . '/' . $backup_month : $base_dir;
	if ( ! is_dir( $source_dir ) ) {
		wp_send_json_error( array( 'message' => __( 'Selected directory does not exist.', 'thumbnail-remover' ) ) );
	}

	$zip_file = trpl_create_zip_backup( $source_dir, $backup_type, $backup_year, $backup_month );
	if ( ! $zip_file ) {
		wp_send_json_error( array( 'message' => __( 'Failed to create zip backup.', 'thumbnail-remover' ) ) );
	}

	wp_send_json_success(
		array(
			'message' => __( 'Backup created successfully.', 'thumbnail-remover' ),
			'download_url' => str_replace( $base_dir, $upload_dir['baseurl'], $zip_file ),
			'progress' => 100,
			'completed' => true,
		)
	);
}
add_action( 'wp_ajax_backup_images', 'trpl_backup_images_ajax' );

function trpl_create_zip_backup( $source_dir, $backup_type, $backup_year = '', $backup_month = '' ) {
	$upload_dir = trpl_get_upload_dir();
	$zip_filename = 'image-backup-' . ( 'all' === $backup_type ? 'all' : $backup_year . '-' . $backup_month ) . '-' . gmdate( 'Y-m-d-H-i-s' ) . '.zip';
	$zip_file = trailingslashit( $upload_dir['basedir'] ) . $zip_filename;

	if ( class_exists( 'ZipArchive' ) ) {
		$zip = new ZipArchive();
		if ( true === $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
			$base_path = 'all' === $backup_type ? $source_dir : dirname( $source_dir );

			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					continue;
				}

				$file_path = $file->getRealPath();
				if ( ! trpl_is_supported_image_path( $file_path ) ) {
					continue;
				}

				$relative_path = ltrim( str_replace( trailingslashit( $base_path ), '', $file_path ), '/' );
				$zip->addFile( $file_path, $relative_path );
			}
			$zip->close();
			return $zip_file;
		}
	} else {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
		$archive = new PclZip( $zip_file );
		$result = $archive->create( $source_dir, PCLZIP_OPT_REMOVE_PATH, 'all' === $backup_type ? $source_dir : dirname( $source_dir ) );
		if ( 0 !== $result ) {
			return $zip_file;
		}
	}

	return false;
}

function trpl_disable_specific_image_sizes( $sizes_to_disable ) {
	if ( empty( $sizes_to_disable ) ) {
		return;
	}

	add_filter(
		'intermediate_image_sizes_advanced',
		function ( $sizes ) use ( $sizes_to_disable ) {
			foreach ( $sizes_to_disable as $size ) {
				unset( $sizes[ $size ] );
			}
			return $sizes;
		}
	);
}

function trpl_render_checkbox_list( $items, $name, $current_values = array(), $format_callback = null ) {
	if ( empty( $items ) ) {
		printf( '<li>%s</li>', esc_html__( 'No items found.', 'thumbnail-remover' ) );
		return;
	}

	foreach ( $items as $value => $data ) {
		$label = is_callable( $format_callback ) ? call_user_func( $format_callback, $value, $data ) : $value;
		printf(
			'<li><label><input type="checkbox" name="%1$s[]" value="%2$s" %3$s> %4$s</label></li>',
			esc_attr( $name ),
			esc_attr( $value ),
			checked( in_array( $value, $current_values, true ), true, false ),
			wp_kses_post( $label )
		);
	}
}

function trpl_format_size_label( $size_name, $details ) {
	return esc_html( sprintf( '%1$s (%2$sx%3$s)', $size_name, $details['width'], $details['height'] ) );
}

function trpl_format_folder_label( $folder, $count ) {
	return esc_html( sprintf( '%1$s (%2$d %3$s)', $folder, $count, _n( 'attachment', 'attachments', $count, 'thumbnail-remover' ) ) );
}

function trpl_get_pro_feature_rows() {
	return array(
		array(
			'feature' => __( 'AI image generation for articles', 'thumbnail-remover' ),
			'description' => __( 'Create article and product visuals with AI prompts directly from WordPress workflows.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'AI regenerate and enhancement tools', 'thumbnail-remover' ),
			'description' => __( 'Upscale, retouch, reframe, or regenerate weak visuals with AI-assisted image pipelines.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'Image optimization and next-gen formats', 'thumbnail-remover' ),
			'description' => __( 'Compress, convert, and deliver WebP/AVIF variants with quality controls and fallback handling.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'CDN and storage offload', 'thumbnail-remover' ),
			'description' => __( 'Push media to a CDN or external object storage and keep image delivery separated from the origin server.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'Site-wide image health reports', 'thumbnail-remover' ),
			'description' => __( 'Audit valid and broken images inside posts, products, featured media, and media library records.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'SEO toolkit for posts and WooCommerce', 'thumbnail-remover' ),
			'description' => __( 'Improve image SEO with structured metadata guidance, product image checks, and discoverability recommendations for Google Images.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'AI alt text and descriptions', 'thumbnail-remover' ),
			'description' => __( 'Generate alt text and image descriptions automatically with editable SEO-friendly suggestions.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'Watermark manager', 'thumbnail-remover' ),
			'description' => __( 'Apply permanent SVG watermarks into image files with reusable watermark presets and placement controls.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'Skeleton placeholders and gradients', 'thumbnail-remover' ),
			'description' => __( 'Generate lightweight image placeholders and skeleton code snippets that show a gradient preview before the real image loads.', 'thumbnail-remover' ),
		),
		array(
			'feature' => __( 'Manager dashboards and activity logs', 'thumbnail-remover' ),
			'description' => __( 'Help site managers understand media activity, optimization results, and operational changes across the library.', 'thumbnail-remover' ),
		),
	);
}

function trpl_get_pro_plan_rows() {
	return array(
		array(
			'plan' => __( 'Starter Monthly', 'thumbnail-remover' ),
			'price' => 'EUR 6.90 / ' . __( 'month', 'thumbnail-remover' ),
			'audience' => __( 'For publishers and small sites that want AI image tools, format conversion, and reporting on a low entry price.', 'thumbnail-remover' ),
		),
		array(
			'plan' => __( 'Single Site', 'thumbnail-remover' ),
			'price' => 'EUR 79 / ' . __( 'year', 'thumbnail-remover' ),
			'audience' => __( 'For one production WordPress site that needs the full pro image workflow.', 'thumbnail-remover' ),
		),
		array(
			'plan' => __( 'Agency Unlimited', 'thumbnail-remover' ),
			'price' => 'EUR 699 / ' . __( 'year', 'thumbnail-remover' ),
			'audience' => __( 'For agencies or operators managing many client sites with unlimited installs and advanced media operations.', 'thumbnail-remover' ),
		),
	);
}

function trpl_get_admin_ad_slot_definitions() {
	return array(
		'sidebar_slot_1' => __( 'Sidebar Ad Slot 1', 'thumbnail-remover' ),
		'sidebar_slot_2' => __( 'Sidebar Ad Slot 2', 'thumbnail-remover' ),
		'sidebar_slot_3' => __( 'Sidebar Ad Slot 3', 'thumbnail-remover' ),
		'sidebar_slot_4' => __( 'Sidebar Ad Slot 4', 'thumbnail-remover' ),
	);
}

function trpl_render_admin_ad_unit_placeholder( $label ) {
	?>
	<div class="trpl-ad-unit">
		<div class="trpl-ad-placeholder">
			<span><?php esc_html_e( 'Ad slot ready', 'thumbnail-remover' ); ?></span>
			<small><?php esc_html_e( 'This placement is reserved for the plugin owner to connect Google AdSense later in code.', 'thumbnail-remover' ); ?></small>
		</div>
	</div>
	<?php
}

function trpl_admin_page() {
	if ( isset( $_POST['thumbnail_manager_nonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_POST['thumbnail_manager_nonce'] ) );
		if ( wp_verify_nonce( $nonce, 'thumbnail-manager-nonce' ) && isset( $_POST['disable_sizes'] ) ) {
			$sizes_to_disable = isset( $_POST['disable'] ) ? trpl_normalize_disabled_sizes( $_POST['disable'] ) : array();
			update_option( TRPL_DISABLED_SIZES_OPTION, $sizes_to_disable );
			trpl_admin_notice( __( 'Image size settings updated successfully.', 'thumbnail-remover' ) );
		}

	}

	$registered_sizes = trpl_get_all_image_sizes();
	$folders = trpl_get_upload_folders_with_count();
	$disabled_sizes = trpl_normalize_disabled_sizes( get_option( TRPL_DISABLED_SIZES_OPTION, array() ) );
	$available_dates = trpl_get_available_dates();
	$trash_batches = trpl_get_trash_batches();
	$ad_slot_definitions = trpl_get_admin_ad_slot_definitions();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Thumbnail Manager', 'thumbnail-remover' ); ?></h1>
		<p class="description"><?php esc_html_e( 'Version 2 adds safer cleanup with preview before deletion, Trash and Restore, batch processing, orphan detection, unused media detection, missing-size regeneration, and per-size analytics.', 'thumbnail-remover' ); ?></p>

		<div class="trpl-admin-layout">
			<div class="wrt-admin trpl-admin-main">
				<div class="wrt-box">
				<h2><?php esc_html_e( 'Library Analysis', 'thumbnail-remover' ); ?></h2>
				<p><?php esc_html_e( 'Run a batch analysis to see per-size analytics, orphan thumbnails, missing sizes, and probably unused media across your uploads.', 'thumbnail-remover' ); ?></p>
				<p>
					<button type="button" class="button button-primary" id="trpl-run-analysis"><?php esc_html_e( 'Run Full Analysis', 'thumbnail-remover' ); ?></button>
				</p>
				<div class="trpl-progress" id="trpl-analysis-progress" hidden>
					<div class="trpl-progress-bar"><span></span></div>
					<p class="trpl-progress-text">0%</p>
				</div>
				<div id="trpl-analysis-results" class="trpl-results"></div>
			</div>

				<div class="wrt-box">
				<h2><?php esc_html_e( 'Manage Thumbnail Sizes', 'thumbnail-remover' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'thumbnail-manager-nonce', 'thumbnail_manager_nonce' ); ?>
					<h3><?php esc_html_e( 'Select image sizes to disable for future uploads', 'thumbnail-remover' ); ?></h3>
					<ul class="wrt-list">
						<?php trpl_render_checkbox_list( $registered_sizes, 'disable', $disabled_sizes, 'trpl_format_size_label' ); ?>
					</ul>
					<p><strong><?php esc_html_e( 'Note:', 'thumbnail-remover' ); ?></strong> <?php esc_html_e( 'Disabling a size prevents future generation only. Existing files stay untouched until you move them to Trash below.', 'thumbnail-remover' ); ?></p>
					<p><input type="submit" name="disable_sizes" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'thumbnail-remover' ); ?>"></p>
				</form>
			</div>

				<div class="wrt-box">
				<h2><?php esc_html_e( 'Preview and Move Thumbnails to Trash', 'thumbnail-remover' ); ?></h2>
				<p><?php esc_html_e( 'Use Preview first to see how many files match, which sizes will be affected, how much storage will be recovered, and how many orphan thumbnails exist in the selection.', 'thumbnail-remover' ); ?></p>
				<form id="trpl-delete-form">
					<h3><?php esc_html_e( 'Select thumbnail sizes', 'thumbnail-remover' ); ?></h3>
					<ul class="wrt-list">
						<?php trpl_render_checkbox_list( $registered_sizes, 'sizes', array(), 'trpl_format_size_label' ); ?>
					</ul>

					<h3><?php esc_html_e( 'Select upload folders', 'thumbnail-remover' ); ?></h3>
					<ul class="wrt-list">
						<?php trpl_render_checkbox_list( $folders, 'folders', array(), 'trpl_format_folder_label' ); ?>
					</ul>

					<p class="trpl-action-row">
						<button type="button" class="button" id="trpl-preview-delete"><?php esc_html_e( 'Preview Cleanup', 'thumbnail-remover' ); ?></button>
						<button type="submit" class="button button-primary" id="trpl-start-delete"><?php esc_html_e( 'Move Matching Files to Trash', 'thumbnail-remover' ); ?></button>
					</p>
				</form>
				<div id="trpl-preview-results" class="trpl-results"></div>
				<div class="trpl-progress" id="trpl-delete-progress" hidden>
					<div class="trpl-progress-bar"><span></span></div>
					<p class="trpl-progress-text">0%</p>
				</div>
				<div id="trpl-delete-results" class="trpl-results"></div>
			</div>

				<div class="wrt-box">
				<h2><?php esc_html_e( 'Trash and Restore', 'thumbnail-remover' ); ?></h2>
				<p><?php esc_html_e( 'Removed thumbnails are moved into plugin Trash so you can restore them later if needed.', 'thumbnail-remover' ); ?></p>
				<div id="trpl-trash-results" class="trpl-results"></div>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Batch', 'thumbnail-remover' ); ?></th>
							<th><?php esc_html_e( 'Created', 'thumbnail-remover' ); ?></th>
							<th><?php esc_html_e( 'Files', 'thumbnail-remover' ); ?></th>
							<th><?php esc_html_e( 'Size', 'thumbnail-remover' ); ?></th>
							<th><?php esc_html_e( 'Status', 'thumbnail-remover' ); ?></th>
							<th><?php esc_html_e( 'Action', 'thumbnail-remover' ); ?></th>
						</tr>
					</thead>
					<tbody id="trpl-trash-table-body">
						<?php if ( empty( $trash_batches ) ) : ?>
							<tr><td colspan="6"><?php esc_html_e( 'Trash is empty.', 'thumbnail-remover' ); ?></td></tr>
						<?php else : ?>
							<?php foreach ( $trash_batches as $batch ) : ?>
								<tr data-batch-id="<?php echo esc_attr( $batch['id'] ); ?>">
									<td><?php echo esc_html( $batch['id'] ); ?></td>
									<td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $batch['created_at'] ) ); ?></td>
									<td><?php echo esc_html( count( $batch['items'] ) ); ?></td>
									<td><?php echo esc_html( size_format( (int) $batch['total_bytes'] ) ); ?></td>
									<td><?php echo esc_html( ucfirst( (string) $batch['status'] ) ); ?></td>
									<td>
										<?php if ( 'active' === $batch['status'] ) : ?>
											<button type="button" class="button trpl-restore-trash" data-batch-id="<?php echo esc_attr( $batch['id'] ); ?>"><?php esc_html_e( 'Restore', 'thumbnail-remover' ); ?></button>
										<?php else : ?>
											<?php esc_html_e( 'Already restored', 'thumbnail-remover' ); ?>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

				<div class="wrt-box">
				<h2><?php esc_html_e( 'Regenerate Missing Sizes', 'thumbnail-remover' ); ?></h2>
				<p><?php esc_html_e( 'After keeping or re-enabling image sizes, regenerate only what is missing in batch mode.', 'thumbnail-remover' ); ?></p>
				<form id="trpl-regenerate-form">
					<h3><?php esc_html_e( 'Select sizes to regenerate', 'thumbnail-remover' ); ?></h3>
					<ul class="wrt-list">
						<?php trpl_render_checkbox_list( $registered_sizes, 'regen_sizes', array(), 'trpl_format_size_label' ); ?>
					</ul>

					<h3><?php esc_html_e( 'Limit to folders', 'thumbnail-remover' ); ?></h3>
					<ul class="wrt-list">
						<?php trpl_render_checkbox_list( $folders, 'regen_folders', array(), 'trpl_format_folder_label' ); ?>
					</ul>
					<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Regenerate Missing Sizes', 'thumbnail-remover' ); ?></button></p>
				</form>
				<div class="trpl-progress" id="trpl-regenerate-progress" hidden>
					<div class="trpl-progress-bar"><span></span></div>
					<p class="trpl-progress-text">0%</p>
				</div>
				<div id="trpl-regenerate-results" class="trpl-results"></div>
			</div>

				<div class="wrt-box">
				<h2><?php esc_html_e( 'Backup Images', 'thumbnail-remover' ); ?></h2>
				<div class="wrt-flex">
					<div>
						<form id="backup-images-form">
							<p>
								<label><input type="radio" name="backup_type" value="all" checked> <?php esc_html_e( 'Backup all images', 'thumbnail-remover' ); ?></label>
							</p>
							<p>
								<label><input type="radio" name="backup_type" value="date"> <?php esc_html_e( 'Backup images from a specific date', 'thumbnail-remover' ); ?></label>
							</p>
							<p class="trpl-inline-fields">
								<select name="backup_year" id="backup_year" disabled>
									<option value=""><?php esc_html_e( 'Select Year', 'thumbnail-remover' ); ?></option>
									<?php foreach ( $available_dates as $year => $months ) : ?>
										<option value="<?php echo esc_attr( $year ); ?>"><?php echo esc_html( $year ); ?></option>
									<?php endforeach; ?>
								</select>
								<select name="backup_month" id="backup_month" disabled>
									<option value=""><?php esc_html_e( 'Select Month', 'thumbnail-remover' ); ?></option>
								</select>
							</p>
							<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Create Backup', 'thumbnail-remover' ); ?></button></p>
						</form>
					</div>
					<div>
						<div class="trpl-progress" id="backup-progress" hidden>
							<div class="trpl-progress-bar"><span></span></div>
							<p class="trpl-progress-text">0%</p>
						</div>
						<div id="backup-result" class="trpl-results"></div>
					</div>
				</div>
			</div>

				<div class="wrt-box trpl-pro-box">
				<h2><?php esc_html_e( 'Coming Soon: Thumbnail Advance Kit', 'thumbnail-remover' ); ?></h2>
				<p><?php esc_html_e( 'Thumbnail Advance Kit is the planned premium companion for broader image operations across WordPress. It is being positioned as a complete image growth and optimization toolkit, not just a thumbnail utility.', 'thumbnail-remover' ); ?></p>
				<p><?php esc_html_e( 'The section below is an internal-style preview for launch positioning and pricing. It is intentionally shown without outbound purchase links.', 'thumbnail-remover' ); ?></p>
				<p class="trpl-ad-free-note"><strong><?php esc_html_e( 'Pro is Ad Free.', 'thumbnail-remover' ); ?></strong> <?php esc_html_e( 'Premium users should not see any of these sidebar ad placements.', 'thumbnail-remover' ); ?></p>

				<div class="trpl-cards trpl-pro-cards">
					<div class="trpl-card">
						<strong><?php esc_html_e( 'AI + SEO', 'thumbnail-remover' ); ?></strong>
						<span><?php esc_html_e( 'AI image generation, AI alt text, AI-assisted regeneration, and richer image metadata workflows.', 'thumbnail-remover' ); ?></span>
					</div>
					<div class="trpl-card">
						<strong><?php esc_html_e( 'Delivery + Storage', 'thumbnail-remover' ); ?></strong>
						<span><?php esc_html_e( 'CDN integration, media offload, modern format delivery, watermarking, and placeholder generation.', 'thumbnail-remover' ); ?></span>
					</div>
					<div class="trpl-card">
						<strong><?php esc_html_e( 'Reports + Control', 'thumbnail-remover' ); ?></strong>
						<span><?php esc_html_e( 'Image health audits, manager dashboards, activity reporting, and WooCommerce/post image SEO guidance.', 'thumbnail-remover' ); ?></span>
					</div>
				</div>

				<h3><?php esc_html_e( 'Planned feature matrix', 'thumbnail-remover' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Capability', 'thumbnail-remover' ); ?></th>
							<th><?php esc_html_e( 'What it unlocks', 'thumbnail-remover' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( trpl_get_pro_feature_rows() as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $row['feature'] ); ?></strong></td>
								<td><?php echo esc_html( $row['description'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Suggested launch pricing', 'thumbnail-remover' ); ?></h3>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Plan', 'thumbnail-remover' ); ?></th>
							<th><?php esc_html_e( 'Price', 'thumbnail-remover' ); ?></th>
							<th><?php esc_html_e( 'Positioning', 'thumbnail-remover' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( trpl_get_pro_plan_rows() as $row ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $row['plan'] ); ?></strong></td>
								<td><?php echo esc_html( $row['price'] ); ?></td>
								<td><?php echo esc_html( $row['audience'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p class="description"><?php esc_html_e( 'Pricing is intentionally placed slightly below several established image optimization competitors to support a stronger launch offer while still leaving room for AI and CDN-related infrastructure costs.', 'thumbnail-remover' ); ?></p>
			</div>
			</div>

			<aside class="trpl-admin-sidebar">
				<div class="wrt-box trpl-donate-box">
					<h2><?php esc_html_e( 'Support Us', 'thumbnail-remover' ); ?></h2>
					<p><?php esc_html_e( 'Thumbnail Remover v2 now helps clean up thumbnails more safely with preview, restore, analytics, and regeneration workflows. If it saves you time, consider supporting future updates.', 'thumbnail-remover' ); ?></p>
					<p><a href="https://www.buymeacoffee.com/mehdiraized" target="_blank" rel="noopener noreferrer"><img src="<?php echo esc_url( plugins_url( '/assets/img/bmc-button.png', __FILE__ ) ); ?>" alt="<?php esc_attr_e( 'Buy Me A Coffee', 'thumbnail-remover' ); ?>" style="height:60px;width:217px;"></a></p>
				</div>

				<div class="wrt-box trpl-ad-preview-box">
					<div class="trpl-ad-stack">
						<?php foreach ( $ad_slot_definitions as $slot_key => $slot_label ) : ?>
							<?php trpl_render_admin_ad_unit_placeholder( $slot_label ); ?>
						<?php endforeach; ?>
					</div>
				</div>
			</aside>
		</div>
	</div>
	<?php
}

$trpl_disabled_sizes = trpl_normalize_disabled_sizes( get_option( TRPL_DISABLED_SIZES_OPTION, array() ) );
trpl_disable_specific_image_sizes( $trpl_disabled_sizes );
