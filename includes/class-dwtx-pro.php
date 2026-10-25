<?php
/**
 * Pro feature gate.
 *
 * The free plugin ships NO premium or licensing code — it only defines this
 * extension point. It returns false by default; the separate Pro add-on plugin
 * hooks the `dwtx_pro_can` filter and decides (based on its own licence) whether
 * a Pro feature is unlocked.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Central authority for "is a Pro feature allowed right now".
 */
class Dwtx_Pro {

	/**
	 * Whether the Pro add-on plugin is installed and active.
	 *
	 * @return bool
	 */
	public static function addon_installed() {
		return defined( 'DWTX_PRO_VERSION' );
	}

	/**
	 * Whether a given Pro feature is available.
	 *
	 * Always false in the free plugin. The separate Pro add-on hooks the
	 * `dwtx_pro_can` filter and returns true when its own licence is active.
	 *
	 * @param string $feature Feature key (informational).
	 * @return bool
	 */
	public static function can( $feature = '' ) {
		/**
		 * Filter whether a Pro feature is unlocked. The Pro add-on returns true
		 * here when its licence is active; the free plugin never does.
		 *
		 * @param bool   $allowed Whether the feature is allowed (default false).
		 * @param string $feature Feature key.
		 */
		return (bool) apply_filters( 'dwtx_pro_can', false, $feature );
	}
}
