<?php
/**
 * Orchestrates pushing a WooCommerce order to Xero (contact + sale), idempotently.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Order -> Xero sale sync.
 */
class Dwtx_Order_Sync {

	const META_UID    = '_dwtx_xero_id';
	const META_STATUS = '_dwtx_sync_status';
	const META_TIME   = '_dwtx_synced_at';

	/**
	 * Push a single order to Xero.
	 *
	 * @param int|WC_Order $order Order or id.
	 * @return true|WP_Error
	 */
	public function push( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		if ( ! $order ) {
			return new WP_Error( 'dwtx_no_order', __( 'Order not found.', 'demfilz-sync-for-xero' ) );
		}

		$provider = Dwtx_Provider_Factory::make();
		if ( is_wp_error( $provider ) ) {
			$this->record( $order, 'error', '', $provider->get_error_message() );
			return $provider;
		}

		// 1. Customer/contact.
		$customer_uid = $provider->upsert_customer( $order );
		if ( is_wp_error( $customer_uid ) ) {
			$this->record( $order, 'error', '', $customer_uid->get_error_message() );
			return $customer_uid;
		}
		Dwtx_Logger::add(
			array(
				'object_type' => 'customer',
				'object_id'   => $order->get_id(),
				'status'      => 'success',
				'xero_id'     => $customer_uid,
				'message'     => __( 'Contact synced to Xero.', 'demfilz-sync-for-xero' ),
			)
		);

		// 2. Sale (idempotent: reuse stored id to update).
		$existing = (string) $order->get_meta( self::META_UID );
		$sale_uid = $provider->upsert_sale( $order, $customer_uid, $existing );
		if ( is_wp_error( $sale_uid ) ) {
			$this->record( $order, 'error', '', $sale_uid->get_error_message() );
			return $sale_uid;
		}

		$order->update_meta_data( self::META_UID, $sale_uid );
		$order->update_meta_data( self::META_STATUS, 'success' );
		$order->update_meta_data( self::META_TIME, current_time( 'mysql' ) );
		$order->save();

		$this->record( $order, 'success', $sale_uid, __( 'Order synced to Xero.', 'demfilz-sync-for-xero' ) );
		$order->add_order_note( __( 'Synced to Xero (Demfilz Sync for WooCommerce to Xero).', 'demfilz-sync-for-xero' ) );

		/**
		 * Fires after a successful order push (Pro uses this for refunds/follow-ups).
		 *
		 * @param WC_Order $order    Order.
		 * @param string   $sale_uid Xero InvoiceID/QuoteID.
		 */
		do_action( 'dwtx_order_pushed', $order, $sale_uid );

		return true;
	}

	/**
	 * Write order meta + a log row for a failed/errored attempt.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $status  Status.
	 * @param string   $uid     Xero id if any.
	 * @param string   $message Message.
	 * @return void
	 */
	private function record( $order, $status, $uid, $message ) {
		$order->update_meta_data( self::META_STATUS, $status );
		if ( $uid ) {
			$order->update_meta_data( self::META_UID, $uid );
		}
		$order->save();

		Dwtx_Logger::add(
			array(
				'object_type' => 'order',
				'object_id'   => $order->get_id(),
				'status'      => $status,
				'xero_id'     => $uid,
				'message'     => $message,
			)
		);
	}
}
