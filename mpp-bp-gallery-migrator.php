<?php

/**
 * Plugin Name: MediaPress BP Gallery Migrator
 * Version: 1.0.0-alpha
 * Author: BuddyDev
 * Plugin URI: https://buddydev.com/suppport/forums/
 */

/**
 * Load the plugin files.
 */
function mpp_load_gallery_migrator() {
	$path = plugin_dir_path( __FILE__ );

	require_once $path . 'admin/mpp-bpg-admin.php';
	require_once $path . 'admin/mpp-bpg-ajax.php';
	require_once $path . 'core/class-mpp-bp-migrator.php';
}
add_action( 'mpp_loaded', 'mpp_load_gallery_migrator' );
