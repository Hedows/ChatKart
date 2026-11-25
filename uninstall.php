<?php
/**
 * Uninstall: remove plugin settings, the statistics table and page options. WooCommerce orders are never deleted.
 *
 * @package ChatKart
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Remove everything for the current site.
 */
function chatkart_uninstall_site() {
	global $wpdb;
	foreach ( array( 'chatkart_settings', 'chatkart_db_version', 'chatkart_db_lock' ) as $opt ) {
		delete_option( $opt );
	}
	delete_post_meta_by_key( '_chatkart_override' );
	delete_post_meta_by_key( '_chatkart_float' );
	delete_post_meta_by_key( '_chatkart_phone' );
	delete_post_meta_by_key( '_chatkart_message' );
	wp_clear_scheduled_hook( 'chatkart_purge' );
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}chatkart_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
		switch_to_blog( $site_id );
		chatkart_uninstall_site();
		restore_current_blog();
	}
} else {
	chatkart_uninstall_site();
}
