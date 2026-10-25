<?php
/**
 * Contract every accounting platform adapter must implement.
 *
 * The rest of the plugin talks only to this interface, so adding/altering a platform
 * never touches the sync or admin code. (Currently Xero; the abstraction keeps the
 * door open for additional platforms.)
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Accounting provider adapter contract.
 */
interface Dwtx_Provider {

	/**
	 * Machine key for this platform ('xero').
	 *
	 * @return string
	 */
	public function key();

	/**
	 * List the organisations (Xero tenants) available to the connected user.
	 *
	 * @return array|WP_Error Array of [ 'id' => string, 'name' => string, 'uri' => string ].
	 */
	public function list_organisations();

	/**
	 * Find or create a contact for an order.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string|WP_Error Xero ContactID.
	 */
	public function upsert_customer( $order );

	/**
	 * Create (or update) a sale (invoice/quote) for an order.
	 *
	 * @param WC_Order $order        WooCommerce order.
	 * @param string   $customer_uid Xero ContactID.
	 * @param string   $existing_uid Existing sale id if re-syncing, else ''.
	 * @return string|WP_Error Xero InvoiceID/QuoteID.
	 */
	public function upsert_sale( $order, $customer_uid, $existing_uid = '' );

	/**
	 * Read stock-on-hand for an item identified by SKU (Item Code).
	 *
	 * @param string $sku Product SKU.
	 * @return float|WP_Error Quantity on hand.
	 */
	public function get_stock_by_sku( $sku );

	/**
	 * Read stock-on-hand for every tracked item in a single call, keyed by Item Code (SKU).
	 *
	 * @return array|WP_Error Map of Code => quantity on hand.
	 */
	public function get_stock_map();

	/**
	 * List tax rates (for mapping UI).
	 *
	 * @return array|WP_Error
	 */
	public function list_tax_codes();

	/**
	 * List income/revenue accounts (for mapping UI).
	 *
	 * @return array|WP_Error
	 */
	public function list_accounts();

	/**
	 * Generic Xero GET (public entry point for extensions).
	 *
	 * @param string $endpoint Resource path (e.g. '/Contacts').
	 * @param array  $query    Query args.
	 * @return array|WP_Error Decoded body.
	 */
	public function api_get( $endpoint, $query = array() );

	/**
	 * Generic Xero POST (public entry point for extensions).
	 *
	 * @param string $endpoint Resource path (e.g. '/Invoices').
	 * @param array  $payload  Entity payload (wrapped).
	 * @return array|WP_Error Decoded body.
	 */
	public function api_post( $endpoint, array $payload );
}
