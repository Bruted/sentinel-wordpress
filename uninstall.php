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

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( (array) $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		delete_option( $redeyed_sentinel_option_key );
		restore_current_blog();
	}
} else {
	delete_option( $redeyed_sentinel_option_key );
}
