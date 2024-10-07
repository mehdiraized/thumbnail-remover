<?php
/*
Plugin Name: Thumbnail Remover and Size Manager
Plugin URI: https://github.com/mehdiraized/thumbnail-remover/
Description: Removes existing thumbnails, disables thumbnail generation, and manages thumbnail sizes
Short Description: Manage and remove WordPress thumbnails easily.
Version: 1.1.4
Author: Mehdi Rezaei
Author URI: https://mehd.ir
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: thumbnail-remover
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly

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


// Enqueue necessary scripts
function trpl_enqueue_scripts( $hook ) {
	if ( 'tools_page_thumbnail-manager' !== $hook ) {
		return;
	}
	wp_enqueue_script( 'jquery' );

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
			'confirm_message' => __( 'Are you sure you want to remove the selected thumbnails? This action cannot be undone.', 'thumbnail-remover' ),
			// 'availableDates' => json_encode( trpl_get_available_dates() )
			'availableDates' => trpl_get_available_dates()
		)
	);
}
add_action( 'admin_enqueue_scripts', 'trpl_enqueue_scripts' );

// Load plugin text domain for translations
function trpl_load_textdomain() {
	load_plugin_textdomain( 'thumbnail-remover', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'plugins_loaded', 'trpl_load_textdomain' );

// Add error logging function
// function trpl_log_error($message)
// {
// 	error_log("Thumbnail Remover Error: " . $message);
// }

// Get all registered image sizes
function trpl_get_all_image_sizes() {
	global $_wp_additional_image_sizes;
	$sizes = array();

	foreach ( get_intermediate_image_sizes() as $_size ) {
		if ( in_array( $_size, array( 'thumbnail', 'medium', 'medium_large', 'large' ) ) ) {
			$sizes[ $_size ]['width'] = get_option( "{$_size}_size_w" );
			$sizes[ $_size ]['height'] = get_option( "{$_size}_size_h" );
			$sizes[ $_size ]['crop'] = (bool) get_option( "{$_size}_crop" );
		} elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
			$sizes[ $_size ] = array(
				'width' => $_wp_additional_image_sizes[ $_size ]['width'],
				'height' => $_wp_additional_image_sizes[ $_size ]['height'],
				'crop' => $_wp_additional_image_sizes[ $_size ]['crop']
			);
		}
	}

	return $sizes;
}

// Function to get all thumbnail sizes from files with count
function trpl_get_all_thumbnail_sizes_with_count() {
	try {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			throw new Exception( "Upload directory does not exist: $base_dir" );
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		$sizes = array();

		foreach ( $files as $file ) {
			if ( ! $file->isDir() && trpl_is_thumbnail( $file ) ) {
				$filename = $file->getFilename();
				if ( preg_match( '/-(\d+)x(\d+)\.(jpg|jpeg|png|gif)$/', $filename, $matches ) ) {
					$size = $matches[1] . 'x' . $matches[2];
					if ( ! isset( $sizes[ $size ] ) ) {
						$sizes[ $size ] = 0;
					}
					$sizes[ $size ]++;
				}
			}
		}

		ksort( $sizes );
		return $sizes;
	} catch (Exception $e) {
		// trpl_log_error("Error in get_all_thumbnail_sizes_with_count: " . $e->getMessage());
		return array();
	}
}

// Function to get all year/month folders with image count
function trpl_get_upload_folders_with_count() {
	try {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];

		if ( ! is_dir( $base_dir ) ) {
			throw new Exception( "Upload directory does not exist: $base_dir" );
		}

		$folders = array();

		$years = scandir( $base_dir );
		foreach ( $years as $year ) {
			if ( is_numeric( $year ) && strlen( $year ) == 4 ) {
				$year_path = $base_dir . '/' . $year;
				if ( is_dir( $year_path ) ) {
					$months = scandir( $year_path );
					foreach ( $months as $month ) {
						if ( is_numeric( $month ) && strlen( $month ) == 2 ) {
							$folder = $year . '/' . $month;
							$folder_path = $base_dir . '/' . $folder;
							$count = count(
								array_filter(
									glob( $folder_path . '/*.*' ),
									function ($file) {
										return trpl_is_thumbnail( new SplFileInfo( $file ) );
									}
								)
							);
							if ( $count > 0 ) {
								$folders[ $folder ] = $count;
							}
						}
					}
				}
			}
		}

		krsort( $folders );
		return $folders;
	} catch (Exception $e) {
		// trpl_log_error("Error in get_upload_folders_with_count: " . $e->getMessage());
		return array();
	}
}

// Function to check if a file is a WordPress-generated thumbnail
function trpl_is_thumbnail( $file ) {
	return preg_match( '/-\d+x\d+\.(jpg|jpeg|png|gif)$/', $file->getFilename() );
}

// Remove existing thumbnails
function trpl_remove_existing_thumbnails( $selected_sizes, $selected_folders ) {
	try {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];

		$count = 0;
		$total_size = 0;

		foreach ( $selected_folders as $folder ) {
			$folder_path = $base_dir . '/' . $folder;
			if ( ! is_dir( $folder_path ) ) {
				throw new Exception( "Folder does not exist: $folder_path" );
			}

			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $folder_path ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $files as $file ) {
				if ( ! $file->isDir() && trpl_is_thumbnail( $file ) ) {
					$filename = $file->getFilename();
					if ( empty( $selected_sizes ) || trpl_any_size_matches( $filename, $selected_sizes ) ) {
						$file_size = $file->getSize();
						wp_delete_file( $file->getRealPath() );
						$count++;
						$total_size += $file_size;
					}
				}
			}
		}

		return array( 'count' => $count, 'size' => $total_size );
	} catch (Exception $e) {
		// trpl_log_error("Error in remove_existing_thumbnails: " . $e->getMessage());
		throw $e;
	}
}

// Disable specific thumbnail sizes
function trpl_disable_specific_image_sizes( $sizes_to_disable ) {
	if ( ! empty( $sizes_to_disable ) ) {
		add_filter( 'intermediate_image_sizes_advanced', function ($sizes) use ($sizes_to_disable) {
			foreach ( $sizes_to_disable as $size ) {
				unset( $sizes[ $size ] );
			}
			return $sizes;
		} );
	}
}

// Add admin menu
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

// AJAX handler for removing thumbnails
function trpl_remove_thumbnails_ajax() {
	try {
		check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			throw new Exception( __( 'Unauthorized access', 'thumbnail-remover' ) );
		}

		$selected_sizes = isset( $_POST['sizes'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['sizes'] ) ) : array();
		$selected_folders = isset( $_POST['folders'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['folders'] ) ) : array();

		if ( empty( $selected_sizes ) && empty( $selected_folders ) ) {
			throw new Exception( __( 'Please select at least one size or one folder', 'thumbnail-remover' ) );
		}

		// If no sizes are selected, we'll remove all thumbnail sizes
		if ( empty( $selected_sizes ) ) {
			$selected_sizes = array();  // This will match all thumbnail sizes in remove_existing_thumbnails()
		}

		// If no folders are selected, we'll process all folders
		if ( empty( $selected_folders ) ) {
			$upload_dir = wp_upload_dir();
			$base_dir = $upload_dir['basedir'];
			$selected_folders = array_filter( scandir( $base_dir ), function ($item) use ($base_dir) {
				return is_dir( $base_dir . '/' . $item ) && ! in_array( $item, array( '.', '..' ) );
			} );
		}

		$result = trpl_remove_existing_thumbnails( $selected_sizes, $selected_folders );

		$message = sprintf(
			/* translators: %1$d: number of thumbnails removed, %2$s: total size freed */
			__( 'Successfully removed %1$d thumbnails, freeing up %2$s of space.', 'thumbnail-remover' ),
			$result['count'],
			size_format( $result['size'] )
		);

		wp_send_json_success(
			array(
				'removed_count' => $result['count'],
				'total_size' => size_format( $result['size'] ),
				'message' => $message
			)
		);
	} catch (Exception $e) {
		// trpl_log_error("Error in remove_thumbnails_ajax: " . $e->getMessage());
		wp_send_json_error( array( 'message' => $e->getMessage() ) );
	}
}
add_action( 'wp_ajax_remove_thumbnails', 'trpl_remove_thumbnails_ajax' );

// Helper function to check if filename matches any of the selected sizes
function trpl_any_size_matches( $filename, $selected_sizes ) {
	foreach ( $selected_sizes as $size ) {
		if ( strpos( $filename, '-' . $size . '.' ) !== false ) {
			return true;
		}
	}
	return false;
}

// Admin page
function trpl_admin_page() {
	// Verify nonce
	if ( isset( $_POST['thumbnail_manager_nonce'] ) ) {
		$nonce = array_map( 'sanitize_text_field', wp_unslash( $_POST['thumbnail_manager_nonce'] ) );
		if ( wp_verify_nonce( $nonce, 'thumbnail_manager_action' ) ) {
			if ( isset( $_POST['disable_sizes'] ) ) {
				$sizes_to_disable = isset( $_POST['disable'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['disable'] ) ) : array();
				update_option( 'trpl_disabled_image_sizes', $sizes_to_disable );
				echo '<div class="updated"><p>' . esc_html__( 'Image sizes have been updated. The selected sizes will not be generated for future uploads.', 'thumbnail-remover' ) . '</p></div>';
			}
		}
	}

	$file_sizes = trpl_get_all_thumbnail_sizes_with_count();
	$registered_sizes = trpl_get_all_image_sizes();
	$folders = trpl_get_upload_folders_with_count();
	$disabled_sizes = get_option( 'trpl_disabled_image_sizes', array() );


	$available_dates = trpl_get_available_dates();

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Thumbnail Manager', 'thumbnail-remover' ); ?></h1>
		<div class="wrt-admin">
			<div class="wrt-box">
				<h2><?php esc_html_e( 'Manage Thumbnail Sizes', 'thumbnail-remover' ); ?></h2>
				<form method="post">
					<?php wp_nonce_field( 'thumbnail-manager-nonce', 'thumbnail_manager_nonce' ); ?>
					<h3><?php esc_html_e( 'Select Thumbnail Sizes to Disable:', 'thumbnail-remover' ); ?></h3>
					<ul class="wrt-list wrt-left">
						<?php foreach ( $registered_sizes as $size => $details ) : ?>
							<li>
								<label>
									<input type="checkbox" name="disable[]" value="<?php echo esc_attr( $size ); ?>" <?php checked( in_array( $size, $disabled_sizes ) ); ?>>
									<?php echo esc_html( $size . ' (' . $details['width'] . 'x' . $details['height'] . ')' ); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>

					<p><strong><?php esc_html_e( 'Note:', 'thumbnail-remover' ); ?></strong>
						<?php esc_html_e( 'Disabling sizes here will prevent WordPress from generating these thumbnail sizes for future uploads. It will not affect existing thumbnails.', 'thumbnail-remover' ); ?>
					</p>
					<input type="submit" name="disable_sizes" class="button button-primary"
						value="<?php esc_attr_e( 'Save Changes', 'thumbnail-remover' ); ?>">
				</form>
			</div>
			<div class="wrt-box">
				<h2><?php esc_html_e( 'Remove Existing Thumbnails', 'thumbnail-remover' ); ?></h2>
				<form id="remove-thumbnails-form" method="post">
					<?php wp_nonce_field( 'thumbnail-manager-nonce', 'thumbnail_manager_nonce' ); ?>
					<h3>
						<?php esc_html_e( 'Select Thumbnail Sizes to Remove:', 'thumbnail-remover' ); ?>
						<?php if ( count( $file_sizes ) > 0 ) : ?>
							<button class="button" id="select-all-sizes"
								type="button"><?php esc_html_e( 'Select All', 'thumbnail-remover' ); ?></button>
						<?php endif; ?>
					</h3>
					<ul class="wrt-list wrt-list-4" id="list_sizes">
						<?php if ( count( $file_sizes ) > 0 ) :
							foreach ( $file_sizes as $size => $count ) :
								if ( $count > 0 ) : ?>
									<li>
										<label>
											<input type="checkbox" name="sizes[]" value="<?php echo esc_attr( $size ); ?>">
											<?php echo esc_html( $size . ' (' . $count . ' ' . _n( 'image', 'images', $count, 'thumbnail-remover' ) . ')' ); ?>
										</label>
									</li>
								<?php endif;
							endforeach;
						else :
							?>
							<li>
								<?php esc_html_e( 'No thumbnail sizes found.', 'thumbnail-remover' ); ?>
							</li>
						<?php endif; ?>
					</ul>

					<h3><?php esc_html_e( 'Select Folders to Process:', 'thumbnail-remover' ); ?>
						<?php if ( count( $folders ) > 0 ) : ?>
							<button class="button" id="select-all-folders"
								type="button"><?php esc_html_e( 'Select All', 'thumbnail-remover' ); ?></button>
						<?php endif; ?>
					</h3>
					<ul class="wrt-list wrt-list-4" id="list_folders">
						<?php if ( count( $file_sizes ) > 0 ) :
							foreach ( $folders as $folder => $count ) :
								if ( $count > 0 ) : ?>
									<li>
										<label>
											<input type="checkbox" name="folders[]" value="<?php echo esc_attr( $folder ); ?>">
											<?php echo esc_html( $folder . ' (' . $count . ' ' . _n( 'image', 'images', $count, 'thumbnail-remover' ) . ')' ); ?>
										</label>
									</li>
								<?php endif;
							endforeach;
						else :
							?>
							<li>
								<?php esc_html_e( 'No folders found.', 'thumbnail-remover' ); ?>
							</li>
						<?php endif; ?>
					</ul>

					<p><strong><?php esc_html_e( 'Warning:', 'thumbnail-remover' ); ?></strong>
						<?php esc_html_e( 'This action cannot be undone. Please backup your files before proceeding.', 'thumbnail-remover' ); ?>
					</p>
					<input type="submit" name="remove_thumbnails" class="button button-primary" <?php if ( count( $file_sizes ) === 0 || count( $folders ) === 0 ) {
						echo 'disabled=""';
					} ?>
						value="<?php esc_attr_e( 'Remove Selected Thumbnails', 'thumbnail-remover' ); ?>">
				</form>

				<div id="progress-bar" style="display: none; margin-top: 20px; background: #f1f1f1; border: 1px solid #ccc;">
					<div id="progress" style="width: 0%; height: 20px; background-color: #0073aa; transition: width 0.5s;"></div>
				</div>
				<div id="progress-text" style="display: none; margin-top: 10px; font-weight: bold;"></div>
				<div id="result-message" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px;"></div>
			</div>
			<div class="wrt-flex">
				<div class="wrt-box">
					<h2><?php esc_html_e( 'Image Optimizer', 'thumbnail-remover' ); ?>
						(<?php esc_attr_e( 'Coming soon', 'thumbnail-remover' ); ?>)</h2>
					<form id="image-optimizer-form" method="post">
						<?php wp_nonce_field( 'thumbnail-manager-nonce', 'thumbnail_manager_nonce' ); ?>
						<label for="optimization-level">
							<?php esc_html_e( 'Optimization Level (0-100):', 'thumbnail-remover' ); ?>
							<input type="number" id="optimization-level" name="optimization_level" min="0" max="100" value="85">
						</label>
						<input type="submit" disabled class="button button-primary"
							value="<?php esc_attr_e( 'Optimize Images', 'thumbnail-remover' ); ?>">
					</form>
					<div id="optimization-progress" style="display:none;">
						<progress id="optimization-progress-bar" value="0" max="100"></progress>
						<span id="optimization-progress-text">0%</span>
					</div>
					<div id="optimization-result"></div>
				</div>
				<div class="wrt-box">
					<h2><?php esc_html_e( 'Backup Images', 'thumbnail-remover' ); ?></h2>
					<div class="wrt-flex">
						<div>
							<form id="backup-images-form" method="post">
								<?php wp_nonce_field( 'thumbnail-manager-nonce', 'thumbnail_manager_nonce' ); ?>
								<p>
									<label>
										<input type="radio" name="backup_type" value="all" checked>
										<?php esc_html_e( 'Backup all images', 'thumbnail-remover' ); ?>
									</label>
								</p>
								<p>
									<label>
										<input type="radio" name="backup_type" value="date">
										<?php esc_html_e( 'Backup images from specific date:', 'thumbnail-remover' ); ?>
									</label>
								</p>
								<p>
									<select name="backup_year" id="backup_year" disabled>
										<option value=""><?php esc_html_e( 'Select Year', 'thumbnail-remover' ); ?></option>
										<?php
										foreach ( $available_dates as $year => $months ) {
											echo '<option value="' . esc_attr( $year ) . '">' . esc_html( $year ) . '</option>';
										}
										?>
									</select>
									<select name="backup_month" id="backup_month" disabled>
										<option value=""><?php esc_html_e( 'Select Month', 'thumbnail-remover' ); ?></option>
									</select>
								</p>
								<input type="submit" class="button button-secondary"
									value="<?php esc_attr_e( 'Create Backup', 'thumbnail-remover' ); ?>">
							</form>
						</div>
						<div>
							<div id="backup-progress" style="display:none;">
								<progress id="backup-progress-bar" value="0" max="100"></progress>
								<span id="backup-progress-text">0%</span>
							</div>
							<div id="backup-result"></div>
						</div>
					</div>
				</div>
			</div>
			<div class="wrt-box">
				<h2><?php esc_html_e( 'Support Us', 'thumbnail-remover' ); ?></h2>
				<p>
					<?php esc_html_e( 'Thank you for using thumbnail-remover! This plugin is a labor of love, designed to help you streamline your WordPress experience by removing unwanted thumbnails. If you find it useful, please consider supporting its development.', 'thumbnail-remover' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'Your support helps cover the costs of maintaining and improving the plugin, ensuring it remains free and accessible for everyone. Every little bit helps and is greatly appreciated!', 'thumbnail-remover' ); ?>
				</p>
				<a href="https://www.buymeacoffee.com/mehdiraized" target="_blank">
					<img src="<?php echo esc_url( plugins_url( '/assets/img/bmc-button.png', __FILE__ ) ); ?>" alt="Buy Me A Coffee"
						style="height: 60px !important;width: 217px !important;">
				</a>
				<p><?php esc_html_e( 'Thank you for your generosity and support!', 'thumbnail-remover' ); ?></p>
			</div>
		</div>
		<?php
}

// Apply the thumbnail size settings
$trpl_disabled_sizes = get_option( 'trpl_disabled_image_sizes', array() );
trpl_disable_specific_image_sizes( $trpl_disabled_sizes );


// AJAX handler for image optimization
function trpl_optimize_images_ajax() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'thumbnail-remover' ) ) );
	}

	$optimization_level = isset( $_POST['optimization_level'] ) ? intval( $_POST['optimization_level'] ) : 85;
	$optimization_level = max( 0, min( 100, $optimization_level ) );

	$upload_dir = wp_upload_dir();
	$images = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $upload_dir['basedir'] )
	);

	$total_images = iterator_count( $images );
	$optimized_count = 0;

	foreach ( $images as $image ) {
		if ( in_array( $image->getExtension(), array( 'jpg', 'jpeg', 'png' ) ) ) {
			$optimized = trpl_optimize_image( $image->getRealPath(), $optimization_level );
			if ( $optimized ) {
				$optimized_count++;
			}
		}
		$progress = ( $optimized_count / $total_images ) * 100;
		wp_send_json_success( array( 'progress' => $progress, 'optimized' => $optimized_count ) );
	}

	wp_send_json_success( array( 'message' => sprintf( __( 'Optimized %d images', 'thumbnail-remover' ), $optimized_count ) ) );
}
add_action( 'wp_ajax_optimize_images', 'trpl_optimize_images_ajax' );

// Function to optimize a single image
function trpl_optimize_image( $image_path, $quality ) {
	$image_editor = wp_get_image_editor( $image_path );
	if ( ! is_wp_error( $image_editor ) ) {
		$image_editor->set_quality( $quality );
		$result = $image_editor->save( $image_path );
		return ! is_wp_error( $result );
	}
	return false;
}

function trpl_get_available_dates() {
	$upload_dir = wp_upload_dir();
	$base_dir = $upload_dir['basedir'];
	$available_dates = array();

	$years = array_filter( scandir( $base_dir ), function ($item) use ($base_dir) {
		return is_dir( $base_dir . '/' . $item ) && is_numeric( $item ) && strlen( $item ) === 4;
	} );

	foreach ( $years as $year ) {
		$months = array_filter( scandir( $base_dir . '/' . $year ), function ($item) use ($base_dir, $year) {
			return is_dir( $base_dir . '/' . $year . '/' . $item ) && is_numeric( $item ) && strlen( $item ) === 2;
		} );
		if ( ! empty( $months ) ) {
			$available_dates[ $year ] = $months;
		}
	}

	return $available_dates;
}

function trpl_backup_images_ajax() {

	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'thumbnail-remover' ) ) );
		return;
	}

	$upload_dir = wp_upload_dir();
	$base_dir = $upload_dir['basedir'];

	$backup_type = isset( $_POST['backup_type'] ) ? sanitize_text_field( $_POST['backup_type'] ) : 'all';
	$backup_year = isset( $_POST['backup_year'] ) ? sanitize_text_field( $_POST['backup_year'] ) : '';
	$backup_month = isset( $_POST['backup_month'] ) ? sanitize_text_field( $_POST['backup_month'] ) : '';

	if ( $backup_type === 'date' && ( ! $backup_year || ! $backup_month ) ) {
		wp_send_json_error( array( 'message' => __( 'Please select both year and month for date-specific backup.', 'thumbnail-remover' ) ) );
		return;
	}

	$source_dir = $base_dir;
	if ( $backup_type === 'date' ) {
		$source_dir .= "/$backup_year/$backup_month";
	}

	if ( ! is_dir( $source_dir ) ) {
		wp_send_json_error( array( 'message' => __( 'Selected directory does not exist.', 'thumbnail-remover' ) ) );
		return;
	}

	$zip_file = trpl_create_zip_backup( $source_dir, $backup_type, $backup_year, $backup_month );

	if ( $zip_file ) {
		$download_url = str_replace( $base_dir, $upload_dir['baseurl'], $zip_file );
		wp_send_json_success( array(
			'message' => __( 'Backup created successfully', 'thumbnail-remover' ),
			'download_url' => $download_url,
			'progress' => 100,
			'completed' => true
		) );
	} else {
		wp_send_json_error( array( 'message' => __( 'Failed to create zip file', 'thumbnail-remover' ) ) );
	}
}
add_action( 'wp_ajax_backup_images', 'trpl_backup_images_ajax' );

function trpl_get_images_to_backup( $base_dir, $backup_type, $backup_year, $backup_month ) {
	$images = array();

	if ( $backup_type === 'all' ) {
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
	} else {
		$specific_dir = $base_dir . '/' . $backup_year . '/' . $backup_month;
		if ( ! is_dir( $specific_dir ) ) {
			return $images;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $specific_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);
	}

	foreach ( $iterator as $file ) {
		if ( in_array( strtolower( $file->getExtension() ), array( 'jpg', 'jpeg', 'png', 'gif' ) ) ) {
			$images[] = $file->getPathname();
		}
	}

	return $images;
}


function trpl_create_unique_filename( $dir, $filename ) {
	$info = pathinfo( $filename );
	$base_name = $info['filename'];
	$extension = isset( $info['extension'] ) ? '.' . $info['extension'] : '';
	$counter = 1;

	while ( file_exists( $dir . '/' . $filename ) ) {
		$filename = $base_name . '_' . $counter . $extension;
		$counter++;
	}

	return $filename;
}

function trpl_check_server_environment() {
	$environment_info = array();

	// Check PHP version
	$environment_info['php_version'] = phpversion();

	// Check WordPress version
	global $wp_version;
	$environment_info['wp_version'] = $wp_version;

	// Check if ZipArchive is available
	$environment_info['zip_archive_available'] = class_exists( 'ZipArchive' );

	// Check max execution time
	$environment_info['max_execution_time'] = ini_get( 'max_execution_time' );

	// Check memory limit
	$environment_info['memory_limit'] = ini_get( 'memory_limit' );

	// Check if we're in WP_DEBUG mode
	$environment_info['wp_debug'] = defined( 'WP_DEBUG' ) && WP_DEBUG;

	// Check for active plugins that might interfere
	$active_plugins = get_option( 'active_plugins' );
	$security_plugins = array( 'wordfence', 'all-in-one-wp-security-and-firewall', 'better-wp-security' );
	$environment_info['security_plugins'] = array_intersect( $security_plugins, $active_plugins );

	// Add more detailed checks
	$upload_dir = wp_upload_dir();
	$environment_info['upload_dir_writable'] = wp_is_writable( $upload_dir['basedir'] );
	$environment_info['upload_dir_permissions'] = substr( sprintf( '%o', fileperms( $upload_dir['basedir'] ) ), -4 );
	$environment_info['upload_dir_owner'] = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( fileowner( $upload_dir['basedir'] ) )['name'] : 'N/A';
	$environment_info['php_user'] = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( posix_geteuid() )['name'] : 'N/A';

	return $environment_info;
}

function trpl_get_directory_info( $dir ) {
	return array(
		'exists' => is_dir( $dir ),
		'writable' => is_writable( $dir ),
		'permissions' => substr( sprintf( '%o', fileperms( $dir ) ), -4 ),
		'owner' => function_exists( 'posix_getpwuid' ) ? posix_getpwuid( fileowner( $dir ) )['name'] : 'N/A',
	);
}

function trpl_extended_diagnostics_ajax() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'thumbnail-remover' ) ) );
	}

	$diagnostics = array(
		'php_info' => phpinfo( INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES ),
		'wordpress_constants' => array(
			'ABSPATH' => ABSPATH,
			'WP_CONTENT_DIR' => WP_CONTENT_DIR,
			'WP_PLUGIN_DIR' => WP_PLUGIN_DIR,
		),
		'server_software' => $_SERVER['SERVER_SOFTWARE'],
		'request_uri' => $_SERVER['REQUEST_URI'],
	);

	wp_send_json_success( $diagnostics );
}
add_action( 'wp_ajax_extended_diagnostics', 'trpl_extended_diagnostics_ajax' );

function trpl_get_server_info_ajax() {
	check_ajax_referer( 'thumbnail-manager-nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Unauthorized access', 'thumbnail-remover' ) ) );
	}

	$server_info = trpl_check_server_environment();

	// Add WordPress upload directory information
	$upload_dir = wp_upload_dir();
	$server_info['upload_dir_info'] = trpl_get_directory_info( $upload_dir['basedir'] );

	wp_send_json_success( $server_info );
}
add_action( 'wp_ajax_get_server_info', 'trpl_get_server_info_ajax' );

// Function to create zip backup
function trpl_create_zip_backup( $source_dir, $backup_type, $backup_year = '', $backup_month = '' ) {
	$upload_dir = wp_upload_dir();
	$zip_filename = 'image-backup-' . ( $backup_type === 'all' ? 'all' : "$backup_year-$backup_month" ) . '-' . date( 'Y-m-d-H-i-s' ) . '.zip';
	$zip_file = $upload_dir['basedir'] . '/' . $zip_filename;

	if ( class_exists( 'ZipArchive' ) ) {
		$zip = new ZipArchive();
		if ( $zip->open( $zip_file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === TRUE ) {
			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $source_dir ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			$base_path = $backup_type === 'all' ? $source_dir : dirname( $source_dir );

			foreach ( $files as $file ) {
				if ( ! $file->isDir() ) {
					$filePath = $file->getRealPath();
					$relativePath = substr( $filePath, strlen( $base_path ) + 1 );
					$zip->addFile( $filePath, $relativePath );
				}
			}
			$zip->close();
			return $zip_file;
		}
	} else {
		// Fallback to PclZip if ZipArchive is not available
		require_once( ABSPATH . 'wp-admin/includes/class-pclzip.php' );
		$archive = new PclZip( $zip_file );
		$result = $archive->create( $source_dir, PCLZIP_OPT_REMOVE_PATH, $backup_type === 'all' ? $source_dir : dirname( $source_dir ) );
		if ( $result !== 0 ) {
			return $zip_file;
		}
	}

	return false;
}

// Helper function to remove directory recursively
function trpl_remove_directory( $dir ) {
	if ( is_dir( $dir ) ) {
		$objects = scandir( $dir );
		foreach ( $objects as $object ) {
			if ( $object != "." && $object != ".." ) {
				if ( is_dir( $dir . DIRECTORY_SEPARATOR . $object ) ) {
					trpl_remove_directory( $dir . DIRECTORY_SEPARATOR . $object );
				} else {
					unlink( $dir . DIRECTORY_SEPARATOR . $object );
				}
			}
		}
		rmdir( $dir );
	}
}