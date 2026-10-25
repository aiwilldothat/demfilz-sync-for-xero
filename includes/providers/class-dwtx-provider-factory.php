<?php
/**
 * Builds the configured provider adapter.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Factory + accessor for the active provider.
 */
class Dwtx_Provider_Factory {

	/**
	 * Instantiate the adapter for the connected organisation (Xero tenant).
	 *
	 * @return Dwtx_Provider|WP_Error
	 */
	public static function make() {
		if ( ! Dwtx_OAuth::is_connected() ) {
			return new WP_Error( 'dwtx_not_connected', __( 'Connect to Xero first.', 'demfilz-sync-for-xero' ) );
		}

		$org       = get_option( 'dwtx_organisation', array() );
		$tenant_id = isset( $org['id'] ) ? $org['id'] : '';

		return new Dwtx_Provider_Xero( $tenant_id );
	}
}
