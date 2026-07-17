<?php
/**
 * Uninstall routine for Redeyed Sentinel.
 *
 * Removes all options created by the plugin. Runs only when the plugin is
 * deleted through the WordPress admin.
 *
 * @package RedeyedSentinel
 */

// Exit if accessed directly or not during an uninstall.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$redeyed_sentinel_option_key = 'redeyed_sentinel_options';

/**
 * Remove the plugin's option and block-log table for the current site.
 */
function redeyed_sentinel_uninstall_site( $option_key ) {
	global $wpdb;
	delete_option( $option_key );
	$table = $wpdb->prefix . 'redeyed_sentinel_log';
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB
}

if ( is_multisite() ) {
	$redeyed_sentinel_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $redeyed_sentinel_site_ids as $redeyed_sentinel_site_id ) {
		switch_to_blog( (int) $redeyed_sentinel_site_id );
		redeyed_sentinel_uninstall_site( $redeyed_sentinel_option_key );
		restore_current_blog();
	}
} else {
	redeyed_sentinel_uninstall_site( $redeyed_sentinel_option_key );
}
