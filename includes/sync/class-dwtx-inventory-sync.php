<?php
/**
 * Inventory read from Xero. Free tier: manual per-product pull. Pro: scheduled/all.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pull Xero stock-on-hand into WooCommerce.
 */
class Dwtx_Inventory_Sync {

	/**
	 * Pull stock for one product by its SKU and update Woo stock.
	 *
	 * @param int $product_id Product id.
	 * @return float|WP_Error New stock level on success.
	 */
	public function pull_product( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error( 'dwtx_no_product', __( 'Product not found.', 'demfilz-sync-for-xero' ) );
		}

		$sku = $product->get_sku();
		if ( ! $sku ) {
			return new WP_Error( 'dwtx_no_sku', __( 'Product has no SKU to match against Xero.', 'demfilz-sync-for-xero' ) );
		}

		$provider = Dwtx_Provider_Factory::make();
		if ( is_wp_error( $provider ) ) {
			return $provider;
		}

		$qty = $provider->get_stock_by_sku( $sku );
		if ( is_wp_error( $qty ) ) {
			Dwtx_Logger::add(
				array(
					'object_type' => 'product',
					'object_id'   => $product_id,
					'direction'   => 'pull',
					'status'      => 'error',
					'message'     => $qty->get_error_message(),
				)
			);
			return $qty;
		}

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $qty );
		$product->save();

		Dwtx_Logger::add(
			array(
				'object_type' => 'product',
				'object_id'   => $product_id,
				'direction'   => 'pull',
				'status'      => 'success',
				/* translators: %s: stock quantity. */
				'message'     => sprintf( __( 'Stock pulled from Xero: %s', 'demfilz-sync-for-xero' ), $qty ),
			)
		);

		return $qty;
	}

	/**
	 * Pull stock for many products using a single Xero call.
	 *
	 * Fetches the whole stock-on-hand map from Xero once, then updates each
	 * product locally by matching its SKU. This replaces one-API-call-per-product
	 * so a full catalogue sync costs a single Xero request.
	 *
	 * @param int[]|null $product_ids Product ids to update, or null for all published products.
	 * @param array|null $map         Pre-fetched Code => qty map (fetched here when null).
	 * @return array|WP_Error ['updated','skipped','missing'] on success, WP_Error if the Xero fetch fails.
	 */
	public function pull_bulk( $product_ids = null, $map = null ) {
		if ( null === $map ) {
			$provider = Dwtx_Provider_Factory::make();
			if ( is_wp_error( $provider ) ) {
				return $provider;
			}
			$map = $provider->get_stock_map();
			if ( is_wp_error( $map ) ) {
				return $map;
			}
		}

		if ( null === $product_ids ) {
			$product_ids = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => -1,
					'return' => 'ids',
				)
			);
		}

		$updated = 0;
		$skipped = 0;
		$missing = 0;
		foreach ( (array) $product_ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( ! $product ) {
				continue;
			}
			$sku = $product->get_sku();
			if ( '' === $sku ) {
				++$skipped;
				continue;
			}
			if ( ! array_key_exists( $sku, $map ) ) {
				++$missing;
				continue;
			}
			$product->set_manage_stock( true );
			$product->set_stock_quantity( $map[ $sku ] );
			$product->save();
			++$updated;
		}

		return array(
			'updated' => $updated,
			'skipped' => $skipped,
			'missing' => $missing,
		);
	}
}
