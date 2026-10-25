<?php
/**
 * Maps WooCommerce tax to Xero tax types.
 *
 * Free tier: a single default tax type + a freight tax type (from settings, which
 * store the Xero TaxType chosen in the mapping UI). Pro overrides per-class via
 * the 'dwtx_tax_code_uid_for_item' filter.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the Xero TaxType for an order line.
 */
class Dwtx_Tax_Mapper {

	/**
	 * Tax type for a specific order line item.
	 *
	 * @param WC_Order              $order Order.
	 * @param WC_Order_Item_Product $item  Line item.
	 * @return string Xero TaxType.
	 */
	public function code_uid_for_item( $order, $item ) {
		$default = Dwtx_Settings::get( 'default_tax_code' );

		/**
		 * Allow Pro (or site code) to choose a tax type per item/class.
		 *
		 * @param string                $default Default Xero TaxType.
		 * @param WC_Order_Item_Product $item    Line item.
		 * @param WC_Order              $order   Order.
		 */
		return (string) apply_filters( 'dwtx_tax_code_uid_for_item', $default, $item, $order );
	}

	/**
	 * Tax type for the freight/shipping line.
	 *
	 * @return string
	 */
	public function freight_code_uid() {
		return (string) apply_filters( 'dwtx_freight_tax_code_uid', Dwtx_Settings::get( 'freight_tax_code' ) );
	}
}
