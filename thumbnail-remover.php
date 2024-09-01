<?php
/*
Plugin Name: Thumbnail Remover and Size Manager
Plugin URI: https://wordpress.org/plugins/thumbnail-remover
Description: Removes existing thumbnails, disables thumbnail generation, and manages thumbnail sizes
Short Description: Manage and remove WordPress thumbnails easily.
Version: 1.1.2
Author: Mehdi Rezaei
Author URI: https://mehd.ir
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Text Domain: thumbnail-remover
Domain Path: /languages

// Detailed Description:
This plugin is designed to help WordPress users manage their media library more efficiently by removing unnecessary thumbnail images and preventing new thumbnails from being generated. It also provides a simple interface to manage thumbnail sizes, giving you greater control over your site's media files.

// Features:
- Remove existing thumbnails
- Disable automatic thumbnail generation
- Manage and customize thumbnail sizes
- Lightweight and easy to use
- Compatible with the latest WordPress version

// Installation:
1. Upload the plugin files to the `/wp-content/plugins/thumbnail-remover` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Navigate to the plugin settings page to configure your options.

// Usage:
After activating the plugin, go to the settings page to remove existing thumbnails and disable future thumbnail generation. Customize the thumbnail sizes as needed.

// Support and Feedback:
For support, please visit our [support page](https://mehd.ir). We appreciate your feedback and suggestions for improving the plugin.

// Donate:
If you find this plugin useful, please consider supporting its development by [buying me a coffee](https://www.buymeacoffee.com/mehdiraized). Your support helps cover the costs of maintaining and improving the plugin, ensuring it remains free and accessible for everyone. Thank you!
*/

if (!defined('ABSPATH'))
	exit; // Exit if accessed directly

function tr_enqueue_styles($hook)
{
	if ('tools_page_thumbnail-manager' !== $hook) {
		return;
	}
	wp_enqueue_style(
		'thumbnail-manager-style',
		plugin_dir_url(__FILE__) . 'assets/css/style.css',
		array(),
		filemtime(plugin_dir_path(__FILE__) . 'assets/css/style.css')
	);
}
add_action('admin_enqueue_scripts', 'tr_enqueue_styles');

// Load plugin text domain for translations
function tr_load_textdomain()
{
	load_plugin_textdomain('thumbnail-remover', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'tr_load_textdomain');

// Add error logging function
function tr_log_error($message)
{
	error_log("Thumbnail Remover Error: " . $message);
}

// Get all registered image sizes
function tr_get_all_image_sizes()
{
	global $_wp_additional_image_sizes;
	$sizes = array();

	foreach (get_intermediate_image_sizes() as $_size) {
		if (in_array($_size, array('thumbnail', 'medium', 'medium_large', 'large'))) {
			$sizes[$_size]['width'] = get_option("{$_size}_size_w");
			$sizes[$_size]['height'] = get_option("{$_size}_size_h");
			$sizes[$_size]['crop'] = (bool) get_option("{$_size}_crop");
		} elseif (isset($_wp_additional_image_sizes[$_size])) {
			$sizes[$_size] = array(
				'width' => $_wp_additional_image_sizes[$_size]['width'],
				'height' => $_wp_additional_image_sizes[$_size]['height'],
				'crop' => $_wp_additional_image_sizes[$_size]['crop']
			);
		}
	}

	return $sizes;
}

// Function to get all thumbnail sizes from files with count
function tr_get_all_thumbnail_sizes_with_count()
{
	try {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];

		if (!is_dir($base_dir)) {
			throw new Exception("Upload directory does not exist: $base_dir");
		}

		$files = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator($base_dir),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		$sizes = array();

		foreach ($files as $file) {
			if (!$file->isDir() && tr_is_thumbnail($file)) {
				$filename = $file->getFilename();
				if (preg_match('/-(\d+)x(\d+)\.(jpg|jpeg|png|gif)$/', $filename, $matches)) {
					$size = $matches[1] . 'x' . $matches[2];
					if (!isset($sizes[$size])) {
						$sizes[$size] = 0;
					}
					$sizes[$size]++;
				}
			}
		}

		ksort($sizes);
		return $sizes;
	} catch (Exception $e) {
		tr_log_error("Error in get_all_thumbnail_sizes_with_count: " . $e->getMessage());
		return array();
	}
}

// Function to get all year/month folders with image count
function tr_get_upload_folders_with_count()
{
	try {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];

		if (!is_dir($base_dir)) {
			throw new Exception("Upload directory does not exist: $base_dir");
		}

		$folders = array();

		$years = scandir($base_dir);
		foreach ($years as $year) {
			if (is_numeric($year) && strlen($year) == 4) {
				$year_path = $base_dir . '/' . $year;
				if (is_dir($year_path)) {
					$months = scandir($year_path);
					foreach ($months as $month) {
						if (is_numeric($month) && strlen($month) == 2) {
							$folder = $year . '/' . $month;
							$folder_path = $base_dir . '/' . $folder;
							$count = count(
								array_filter(
									glob($folder_path . '/*.*'),
									function ($file) {
										return tr_is_thumbnail(new SplFileInfo($file));
									}
								)
							);
							if ($count > 0) {
								$folders[$folder] = $count;
							}
						}
					}
				}
			}
		}

		krsort($folders);
		return $folders;
	} catch (Exception $e) {
		tr_log_error("Error in get_upload_folders_with_count: " . $e->getMessage());
		return array();
	}
}

// Function to check if a file is a WordPress-generated thumbnail
function tr_is_thumbnail($file)
{
	return preg_match('/-\d+x\d+\.(jpg|jpeg|png|gif)$/', $file->getFilename());
}

// Remove existing thumbnails
function tr_remove_existing_thumbnails($selected_sizes, $selected_folders)
{
	try {
		$upload_dir = wp_upload_dir();
		$base_dir = $upload_dir['basedir'];

		$count = 0;
		$total_size = 0;

		foreach ($selected_folders as $folder) {
			$folder_path = $base_dir . '/' . $folder;
			if (!is_dir($folder_path)) {
				throw new Exception("Folder does not exist: $folder_path");
			}

			$files = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($folder_path),
				RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ($files as $file) {
				if (!$file->isDir() && tr_is_thumbnail($file)) {
					$filename = $file->getFilename();
					if (empty($selected_sizes) || tr_any_size_matches($filename, $selected_sizes)) {
						$file_size = $file->getSize();
						wp_delete_file($file->getRealPath());
						$count++;
						$total_size += $file_size;
					}
				}
			}
		}

		return array('count' => $count, 'size' => $total_size);
	} catch (Exception $e) {
		tr_log_error("Error in remove_existing_thumbnails: " . $e->getMessage());
		throw $e;
	}
}

// Disable specific thumbnail sizes
function tr_disable_specific_image_sizes($sizes_to_disable)
{
	if (!empty($sizes_to_disable)) {
		add_filter('intermediate_image_sizes_advanced', function ($sizes) use ($sizes_to_disable) {
			foreach ($sizes_to_disable as $size) {
				unset($sizes[$size]);
			}
			return $sizes;
		});
	}
}

// Add admin menu
function tr_admin_menu()
{
	add_management_page(
		__('Thumbnail Manager', 'thumbnail-remover'),
		__('Thumbnail Manager', 'thumbnail-remover'),
		'manage_options',
		'thumbnail-manager',
		'tr_admin_page'
	);
}
add_action('admin_menu', 'tr_admin_menu');

// Enqueue necessary scripts
function tr_enqueue_scripts($hook)
{
	if ('tools_page_thumbnail-manager' !== $hook) {
		return;
	}
	wp_enqueue_script('jquery');

	wp_enqueue_script(
		'thumbnail-manager-script',
		plugin_dir_url(__FILE__) . 'assets/js/script.js',
		array('jquery'),
		filemtime(plugin_dir_path(__FILE__) . 'assets/js/script.js'),
		true
	);
	wp_localize_script(
		'thumbnail-manager-script',
		'thumbnailManager',
		array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('thumbnail-manager-nonce'),
			'confirm_message' => __('Are you sure you want to remove the selected thumbnails? This action cannot be undone.', 'thumbnail-remover')
		)
	);
}
add_action('admin_enqueue_scripts', 'tr_enqueue_scripts');

// AJAX handler for removing thumbnails
function tr_remove_thumbnails_ajax()
{
	try {
		check_ajax_referer('thumbnail-manager-nonce', 'nonce');

		if (!current_user_can('manage_options')) {
			throw new Exception(__('Unauthorized access', 'thumbnail-remover'));
		}

		$selected_sizes = isset($_POST['sizes']) ? array_map('sanitize_text_field', wp_unslash($_POST['sizes'])) : array();
		$selected_folders = isset($_POST['folders']) ? array_map('sanitize_text_field', wp_unslash($_POST['folders'])) : array();

		if (empty($selected_sizes) && empty($selected_folders)) {
			throw new Exception(__('Please select at least one size or one folder', 'thumbnail-remover'));
		}

		// If no sizes are selected, we'll remove all thumbnail sizes
		if (empty($selected_sizes)) {
			$selected_sizes = array();  // This will match all thumbnail sizes in remove_existing_thumbnails()
		}

		// If no folders are selected, we'll process all folders
		if (empty($selected_folders)) {
			$upload_dir = wp_upload_dir();
			$base_dir = $upload_dir['basedir'];
			$selected_folders = array_filter(scandir($base_dir), function ($item) use ($base_dir) {
				return is_dir($base_dir . '/' . $item) && !in_array($item, array('.', '..'));
			});
		}

		$result = tr_remove_existing_thumbnails($selected_sizes, $selected_folders);

		$message = sprintf(
			/* translators: %1$d: number of thumbnails removed, %2$s: total size freed */
			__('Successfully removed %1$d thumbnails, freeing up %2$s of space.', 'thumbnail-remover'),
			$result['count'],
			size_format($result['size'])
		);

		wp_send_json_success(
			array(
				'removed_count' => $result['count'],
				'total_size' => size_format($result['size']),
				'message' => $message
			)
		);
	} catch (Exception $e) {
		tr_log_error("Error in remove_thumbnails_ajax: " . $e->getMessage());
		wp_send_json_error(array('message' => $e->getMessage()));
	}
}
add_action('wp_ajax_remove_thumbnails', 'tr_remove_thumbnails_ajax');

// Helper function to check if filename matches any of the selected sizes
function tr_any_size_matches($filename, $selected_sizes)
{
	foreach ($selected_sizes as $size) {
		if (strpos($filename, '-' . $size . '.') !== false) {
			return true;
		}
	}
	return false;
}

// Admin page
function tr_admin_page()
{
	// Verify nonce
	if (isset($_POST['thumbnail_manager_nonce'])) {
		$nonce = array_map('sanitize_text_field', wp_unslash($_POST['thumbnail_manager_nonce']));
		if (wp_verify_nonce($nonce, 'thumbnail_manager_action')) {
			if (isset($_POST['disable_sizes'])) {
				$sizes_to_disable = isset($_POST['disable']) ? array_map('sanitize_text_field', wp_unslash($_POST['disable'])) : array();
				update_option('disabled_image_sizes', $sizes_to_disable);
				echo '<div class="updated"><p>' . esc_html__('Image sizes have been updated. The selected sizes will not be generated for future uploads.', 'thumbnail-remover') . '</p></div>';
			}
		}
	}

	$file_sizes = tr_get_all_thumbnail_sizes_with_count();
	$registered_sizes = tr_get_all_image_sizes();
	$folders = tr_get_upload_folders_with_count();
	$disabled_sizes = get_option('disabled_image_sizes', array());
	?>
	<div class="wrap">
		<h1><?php esc_html_e('Thumbnail Manager', 'thumbnail-remover'); ?></h1>
		<div class="wrt-admin">
			<div class="wrt-box">
				<h2><?php esc_html_e('Manage Thumbnail Sizes', 'thumbnail-remover'); ?></h2>
				<form method="post">
					<?php wp_nonce_field('thumbnail_manager_action', 'thumbnail_manager_nonce'); ?>
					<h3><?php esc_html_e('Select Thumbnail Sizes to Disable:', 'thumbnail-remover'); ?></h3>
					<ul class="wrt-list wrt-left">
						<?php foreach ($registered_sizes as $size => $details): ?>
							<li>
								<label>
									<input type="checkbox" name="disable[]" value="<?php echo esc_attr($size); ?>" <?php checked(in_array($size, $disabled_sizes)); ?>>
									<?php echo esc_html($size . ' (' . $details['width'] . 'x' . $details['height'] . ')'); ?>
								</label>
							</li>
						<?php endforeach; ?>
					</ul>

					<p><strong><?php esc_html_e('Note:', 'thumbnail-remover'); ?></strong>
						<?php esc_html_e('Disabling sizes here will prevent WordPress from generating these thumbnail sizes for future uploads. It will not affect existing thumbnails.', 'thumbnail-remover'); ?>
					</p>
					<input type="submit" name="disable_sizes" class="button button-primary"
						value="<?php esc_attr_e('Save Changes', 'thumbnail-remover'); ?>">
				</form>
			</div>
			<div class="wrt-box">
				<h2><?php esc_html_e('Remove Existing Thumbnails', 'thumbnail-remover'); ?></h2>
				<form id="remove-thumbnails-form" method="post">
					<?php wp_nonce_field('thumbnail-manager-nonce', 'thumbnail_manager_nonce'); ?>
					<h3>
						<?php esc_html_e('Select Thumbnail Sizes to Remove:', 'thumbnail-remover'); ?>
						<?php if (count($file_sizes) > 0): ?>
							<button class="button" id="select-all-sizes"
								type="button"><?php esc_html_e('Select All', 'thumbnail-remover'); ?></button>
						<?php endif; ?>
					</h3>
					<ul class="wrt-list wrt-list-4" id="list_sizes">
						<?php if (count($file_sizes) > 0):
							foreach ($file_sizes as $size => $count):
								if ($count > 0): ?>
									<li>
										<label>
											<input type="checkbox" name="sizes[]" value="<?php echo esc_attr($size); ?>">
											<?php echo esc_html($size . ' (' . $count . ' ' . _n('image', 'images', $count, 'thumbnail-remover') . ')'); ?>
										</label>
									</li>
								<?php endif;
							endforeach;
						else:
							?>
							<li>
								<?php esc_html_e('No thumbnail sizes found.', 'thumbnail-remover'); ?>
							</li>
						<?php endif; ?>
					</ul>

					<h3><?php esc_html_e('Select Folders to Process:', 'thumbnail-remover'); ?>
						<?php if (count($folders) > 0): ?>
							<button class="button" id="select-all-folders"
								type="button"><?php esc_html_e('Select All', 'thumbnail-remover'); ?></button>
						<?php endif; ?>
					</h3>
					<ul class="wrt-list wrt-list-4" id="list_folders">
						<?php if (count($file_sizes) > 0):
							foreach ($folders as $folder => $count):
								if ($count > 0): ?>
									<li>
										<label>
											<input type="checkbox" name="folders[]" value="<?php echo esc_attr($folder); ?>">
											<?php echo esc_html($folder . ' (' . $count . ' ' . _n('image', 'images', $count, 'thumbnail-remover') . ')'); ?>
										</label>
									</li>
								<?php endif;
							endforeach;
						else:
							?>
							<li>
								<?php esc_html_e('No folders found.', 'thumbnail-remover'); ?>
							</li>
						<?php endif; ?>
					</ul>

					<p><strong><?php esc_html_e('Warning:', 'thumbnail-remover'); ?></strong>
						<?php esc_html_e('This action cannot be undone. Please backup your files before proceeding.', 'thumbnail-remover'); ?>
					</p>
					<input type="submit" name="remove_thumbnails" class="button button-primary" <?php if (count($file_sizes) === 0 || count($folders) === 0) {
						echo 'disabled=""';
					} ?>
						value="<?php esc_attr_e('Remove Selected Thumbnails', 'thumbnail-remover'); ?>">
				</form>

				<div id="progress-bar" style="display: none; margin-top: 20px; background: #f1f1f1; border: 1px solid #ccc;">
					<div id="progress" style="width: 0%; height: 20px; background-color: #0073aa; transition: width 0.5s;"></div>
				</div>
				<div id="progress-text" style="display: none; margin-top: 10px; font-weight: bold;"></div>
				<div id="result-message" style="display: none; margin-top: 10px; padding: 10px; border-radius: 4px;"></div>
			</div>
			<div class="wrt-box">
				<h2><?php esc_html_e('Support Us', 'thumbnail-remover'); ?></h2>
				<p>
					<?php esc_html_e('Thank you for using thumbnail-remover! This plugin is a labor of love, designed to help you streamline your WordPress experience by removing unwanted thumbnails. If you find it useful, please consider supporting its development.', 'thumbnail-remover'); ?>
				</p>
				<p>
					<?php esc_html_e('Your support helps cover the costs of maintaining and improving the plugin, ensuring it remains free and accessible for everyone. Every little bit helps and is greatly appreciated!', 'thumbnail-remover'); ?>
				</p>
				<a href="https://www.buymeacoffee.com/mehdiraized" target="_blank">
					<img src="<?php echo esc_url(plugins_url('/assets/img/bmc-button.png', __FILE__)); ?>" alt="Buy Me A Coffee"
						style="height: 60px !important;width: 217px !important;">
				</a>
				<p><?php esc_html_e('Thank you for your generosity and support!', 'thumbnail-remover'); ?></p>
			</div>
		</div>
		<?php
}

// Apply the thumbnail size settings
$disabled_sizes = get_option('disabled_image_sizes', array());
tr_disable_specific_image_sizes($disabled_sizes);
