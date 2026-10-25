<?php
/**
 * Uninstall cleanup. Removes options and the log table.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$dwtx_options = array(
	'dwtx_settings',
	'dwtx_tokens',
	'dwtx_app_creds',
	'dwtx_organisation',
	'dwtx_db_version',
);
foreach ( $dwtx_options as $dwtx_opt ) {
	delete_option( $dwtx_opt );
}

// Drop the log table.
$dwtx_table = $wpdb->prefix . 'dwtx_log';
// One-off uninstall cleanup; the table identifier is escaped via the %i placeholder.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $dwtx_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange

wp_clear_scheduled_hook( 'dwtx_token_refresh' );
