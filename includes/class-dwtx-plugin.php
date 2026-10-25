<?php
/**
 * Main plugin controller: wires hooks for admin, order meta box, AJAX and cron.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton plugin controller.
 */
class Dwtx_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Dwtx_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get/boot the singleton.
	 *
	 * @return Dwtx_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: register hooks.
	 */
	private function __construct() {
		// Run any pending DB upgrade.
		if ( get_option( 'dwtx_db_version' ) !== Dwtx_Installer::DB_VERSION ) {
			Dwtx_Installer::install_tables();
		}

		// Translations load automatically for plugins hosted on WordPress.org
		// (since WP 4.6), so no manual text-domain registration is needed here.

		// Admin UI.
		if ( is_admin() ) {
			new Dwtx_Admin();
			new Dwtx_Order_Metabox();
		}

		// Background token refresh.
		add_action( 'dwtx_token_refresh', array( 'Dwtx_OAuth', 'refresh' ) );

		// Manual single-order push via order action (works free).
		add_action( 'wp_ajax_dwtx_push_order', array( $this, 'ajax_push_order' ) );
		add_action( 'wp_ajax_dwtx_pull_stock', array( $this, 'ajax_pull_stock' ) );
		add_action( 'wp_ajax_dwtx_sync_all_stock', array( $this, 'ajax_sync_all_stock' ) );

		// Settings link on the plugins screen.
		add_filter( 'plugin_action_links_' . DWTX_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add a Settings link to the plugin row.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=dwtx' );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'demfilz-sync-for-xero' ) . '</a>' );
		return $links;
	}

	/**
	 * AJAX: push a single order to Xero (free, manual).
	 *
	 * @return void
	 */
	public function ajax_push_order() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'demfilz-sync-for-xero' ) ), 403 );
		}
		check_ajax_referer( 'dwtx_admin', 'nonce' );

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$result   = ( new Dwtx_Order_Sync() )->push( $order_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array( 'message' => __( 'Order synced to Xero.', 'demfilz-sync-for-xero' ) ) );
	}

	/**
	 * AJAX: pull stock for a single product (free, manual).
	 *
	 * @return void
	 */
	public function ajax_pull_stock() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'demfilz-sync-for-xero' ) ), 403 );
		}
		check_ajax_referer( 'dwtx_admin', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$result     = ( new Dwtx_Inventory_Sync() )->pull_product( $product_id );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				/* translators: %s: stock quantity. */
				'message' => sprintf( __( 'Stock updated from Xero: %s', 'demfilz-sync-for-xero' ), $result ),
				'stock'   => $result,
			)
		);
	}

	/**
	 * AJAX: pull stock from Xero for all SKU'd products, one batch at a time (free, manual).
	 *
	 * The browser calls this repeatedly with an increasing offset so a large
	 * catalogue is processed in small chunks that stay within the Xero rate
	 * limit and the PHP execution time, and it does not depend on WP-Cron.
	 *
	 * @return void
	 */
	public function ajax_sync_all_stock() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'demfilz-sync-for-xero' ) ), 403 );
		}
		check_ajax_referer( 'dwtx_admin', 'nonce' );

		$offset  = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;
		$batch   = 50; // Local DB updates only (no Xero call per product), so larger batches are fine.
		$map_key = 'dwtx_stock_map';
		$total   = 0;

		if ( 0 === $offset ) {
			// Fetch the whole stock-on-hand map from Xero once, then reuse it for every batch.
			$provider = Dwtx_Provider_Factory::make();
			if ( is_wp_error( $provider ) ) {
				wp_send_json_error( array( 'message' => $provider->get_error_message() ) );
			}
			$map = $provider->get_stock_map();
			if ( is_wp_error( $map ) ) {
				wp_send_json_error( array( 'message' => $map->get_error_message() ) );
			}
			set_transient( $map_key, $map, 15 * MINUTE_IN_SECONDS );

			$all   = wc_get_products(
				array(
					'status' => 'publish',
					'limit'  => -1,
					'return' => 'ids',
				)
			);
			$total = count( $all );
		} else {
			$map = get_transient( $map_key );
			if ( false === $map ) {
				wp_send_json_error( array( 'message' => __( 'The stock list expired. Please start the sync again.', 'demfilz-sync-for-xero' ) ) );
			}
		}

		$ids = wc_get_products(
			array(
				'status'  => 'publish',
				'limit'   => $batch,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);

		$result    = ( new Dwtx_Inventory_Sync() )->pull_bulk( $ids, $map );
		$processed = count( $ids );
		$done      = ( $processed < $batch );
		if ( $done ) {
			delete_transient( $map_key );
		}

		wp_send_json_success(
			array(
				'processed'   => $processed,
				'next_offset' => $offset + $processed,
				'done'        => $done,
				'total'       => $total,
				'updated'     => $result['updated'],
				'skipped'     => $result['skipped'],
				'errors'      => $result['missing'],
			)
		);
	}
}
