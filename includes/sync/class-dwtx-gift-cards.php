<?php
/**
 * Gift cards and store credit (Demfilz Gift Cards and Store Credit for WooCommerce).
 *
 * A gift card is a payment, not a discount. An order paid partly with one keeps its full item,
 * shipping and tax lines, and its total is lower by the amount taken from the card. In Xero:
 *  - a gift card being sold posts to the gift card liability account, not to income;
 *  - an amount paid by gift card or store credit posts as a negative line against the same
 *    account, so revenue and GST stay whole and the invoice total matches what was charged.
 *
 * The order data is read directly, so orders keep syncing correctly even if the gift card
 * plugin is deactivated later.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads gift card sales and gift card payments from an order.
 */
class Dwtx_Gift_Cards {

	/**
	 * Gift card and store credit amounts that paid part of an order.
	 *
	 * Only amounts taken from a card and not given back count: the same amounts that come off
	 * the order total.
	 *
	 * @param WC_Order $order Order.
	 * @return array[] Each: type (gift|credit), label, amount (positive, 2 decimals).
	 */
	public static function payments( $order ) {
		$out     = array();
		$entries = $order->get_meta( '_dgcw_applied', true );
		foreach ( is_array( $entries ) ? $entries : array() as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['debited'] ) || ! empty( $entry['returned'] ) ) {
				continue;
			}
			$amount = round( (float) ( isset( $entry['amount'] ) ? $entry['amount'] : 0 ), 2 );
			if ( $amount <= 0 ) {
				continue;
			}
			$type = ( isset( $entry['type'] ) && 'credit' === $entry['type'] ) ? 'credit' : 'gift';
			$tail = isset( $entry['tail'] ) ? (string) $entry['tail'] : '';
			if ( 'credit' === $type ) {
				$label = __( 'Paid by store credit', 'demfilz-sync-for-xero' );
			} elseif ( '' !== $tail ) {
				/* translators: %s: last characters of the gift card code. */
				$label = sprintf( __( 'Paid by gift card ending %s', 'demfilz-sync-for-xero' ), $tail );
			} else {
				$label = __( 'Paid by gift card', 'demfilz-sync-for-xero' );
			}
			$out[] = array(
				'type'   => $type,
				'label'  => $label,
				'amount' => $amount,
			);
		}

		/**
		 * Filter the gift card and store credit payments on an order (lets another gift card
		 * plugin report its own).
		 *
		 * @param array[]  $out   Each: type, label, amount (positive).
		 * @param WC_Order $order Order.
		 */
		return (array) apply_filters( 'dwtx_gift_card_payments', $out, $order );
	}

	/**
	 * Whether an order line is a gift card being sold.
	 *
	 * @param WC_Order_Item $item Order line.
	 * @return bool
	 */
	public static function is_gift_card_sale( $item ) {
		$sold = $item instanceof WC_Order_Item_Product && is_array( $item->get_meta( '_dgcw_data', true ) );

		/**
		 * Filter whether an order line is a gift card sale (posted to the gift card account).
		 *
		 * @param bool          $sold Whether the line sells a gift card.
		 * @param WC_Order_Item $item Order line.
		 */
		return (bool) apply_filters( 'dwtx_is_gift_card_sale', $sold, $item );
	}

	/**
	 * What a gift card line owes the customer: the value of the cards sold. A card sold below
	 * its value (on sale, or with a coupon) still owes the full value; the difference is a
	 * discount. Never less than the line total.
	 *
	 * @param WC_Order_Item_Product $item       Order line that sells a gift card.
	 * @param float                 $line_total Line total before tax.
	 * @return float
	 */
	public static function face_value( $item, $line_total ) {
		$data  = $item->get_meta( '_dgcw_data', true );
		$qty   = max( 1, (float) $item->get_quantity() );
		$value = ( is_array( $data ) && ! empty( $data['value'] ) ) ? (float) $data['value'] : (float) $item->get_subtotal() / $qty;
		$face  = round( $value * $qty, 2 );
		return $face > $line_total ? $face : round( (float) $line_total, 2 );
	}

	/**
	 * Whether an order sells a gift card or was paid partly by gift card or store credit.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function order_uses_gift_cards( $order ) {
		if ( self::payments( $order ) ) {
			return true;
		}
		foreach ( $order->get_items() as $item ) {
			if ( self::is_gift_card_sale( $item ) ) {
				return true;
			}
		}
		return false;
	}
}
