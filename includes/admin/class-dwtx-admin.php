<?php
/**
 * Admin screen: menu, tab router, form/OAuth handling, and tab views.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders and processes the WooCommerce -> Xero settings page.
 */
class Dwtx_Admin {

	/**
	 * Constructor: hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Register the admin menu under WooCommerce.
	 *
	 * @return void
	 */
	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Xero Sync', 'demfilz-sync-for-xero' ),
			__( 'Xero Sync', 'demfilz-sync-for-xero' ),
			'manage_woocommerce',
			'dwtx',
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue admin CSS/JS only on our screen and order/product screens.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( $hook ) {
		$screens = array( 'woocommerce_page_dwtx', 'shop_order', 'woocommerce_page_wc-orders', 'product' );
		$screen  = get_current_screen();
		$is_ours = ( false !== strpos( (string) $hook, 'dwtx' ) ) || ( $screen && in_array( $screen->id, $screens, true ) );
		if ( ! $is_ours ) {
			return;
		}

		wp_enqueue_style( 'dwtx-admin', DWTX_URL . 'assets/css/admin.css', array(), DWTX_VERSION );
		wp_enqueue_script( 'dwtx-admin', DWTX_URL . 'assets/js/admin.js', array( 'jquery' ), DWTX_VERSION, true );
		wp_localize_script(
			'dwtx-admin',
			'dwtxAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'dwtx_admin' ),
				'syncing' => __( 'Syncing…', 'demfilz-sync-for-xero' ),
			)
		);
	}

	/**
	 * The active tab slug.
	 *
	 * @return string
	 */
	private function current_tab() {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connection'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, array( 'connection', 'mapping', 'sync', 'logs' ), true ) ? $tab : 'connection';
	}

	/**
	 * Process POST forms and the OAuth callback (admin_init).
	 *
	 * @return void
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || 'dwtx' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// OAuth callback from Xero.
		if ( isset( $_GET['dwtx_action'] ) && 'oauth_callback' === $_GET['dwtx_action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->handle_oauth_callback();
			return;
		}

		if ( empty( $_POST['dwtx_nonce'] ) ) {
			return;
		}
		$action = isset( $_POST['dwtx_form'] ) ? sanitize_key( wp_unslash( $_POST['dwtx_form'] ) ) : '';
		check_admin_referer( 'dwtx_' . $action, 'dwtx_nonce' );

		switch ( $action ) {
			case 'credentials':
				$this->save_credentials();
				break;
			case 'organisation':
				$this->save_organisation();
				break;
			case 'mapping':
				$this->save_mapping();
				break;
			case 'disconnect':
				Dwtx_OAuth::disconnect();
				$this->notice( __( 'Disconnected from Xero.', 'demfilz-sync-for-xero' ) );
				break;
		}
	}

	/**
	 * Save Xero app credentials and (if requested) start OAuth.
	 *
	 * @return void
	 */
	private function save_credentials() {
		check_admin_referer( 'dwtx_credentials', 'dwtx_nonce' );
		$cid = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
		$cs  = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';
		if ( $cid && $cs ) {
			Dwtx_OAuth::save_app_credentials( $cid, $cs );
		}

		if ( isset( $_POST['connect'] ) ) {
			// The OAuth consent page is on Xero's domain; allow the redirect off-site.
			add_filter( 'allowed_redirect_hosts', array( $this, 'allow_xero_host' ) );
			wp_safe_redirect( Dwtx_OAuth::authorize_url() );
			exit;
		}
		$this->notice( __( 'Xero app credentials saved.', 'demfilz-sync-for-xero' ) );
	}

	/**
	 * Whitelist Xero's OAuth host so wp_safe_redirect() will send the user there.
	 *
	 * @param array $hosts Allowed redirect hosts.
	 * @return array
	 */
	public function allow_xero_host( $hosts ) {
		$hosts[] = 'login.xero.com';
		return $hosts;
	}

	/**
	 * Handle the redirect back from Xero after consent.
	 *
	 * @return void
	 */
	private function handle_oauth_callback() {
		// The OAuth callback is a GET redirect from Xero; the `state` value below is
		// itself the CSRF check (compared to a stored transient), so there is no nonce.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$state    = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$expected = get_transient( 'dwtx_oauth_state' );
		if ( ! $state || $state !== $expected ) {
			$this->notice( __( 'OAuth state mismatch. Please try connecting again.', 'demfilz-sync-for-xero' ), 'error' );
			return;
		}
		delete_transient( 'dwtx_oauth_state' );

		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $code ) {
			$this->notice( __( 'Xero did not return an authorization code.', 'demfilz-sync-for-xero' ), 'error' );
			return;
		}

		$result = Dwtx_OAuth::exchange_code( $code );
		if ( is_wp_error( $result ) ) {
			$this->notice( $result->get_error_message(), 'error' );
			return;
		}
		$this->notice( __( 'Connected to Xero. Now choose your organisation.', 'demfilz-sync-for-xero' ) );
		wp_safe_redirect( admin_url( 'admin.php?page=dwtx&tab=connection' ) );
		exit;
	}

	/**
	 * Save the selected organisation (Xero tenant).
	 *
	 * @return void
	 */
	private function save_organisation() {
		check_admin_referer( 'dwtx_organisation', 'dwtx_nonce' );
		$org_id   = isset( $_POST['org_id'] ) ? sanitize_text_field( wp_unslash( $_POST['org_id'] ) ) : '';
		$org_name = isset( $_POST['org_name'] ) ? sanitize_text_field( wp_unslash( $_POST['org_name'] ) ) : '';
		update_option(
			'dwtx_organisation',
			array(
				'id'   => $org_id,
				'name' => $org_name,
			)
		);
		$this->notice( __( 'Organisation saved.', 'demfilz-sync-for-xero' ) );
	}

	/**
	 * Save tax/account mapping.
	 *
	 * @return void
	 */
	private function save_mapping() {
		check_admin_referer( 'dwtx_mapping', 'dwtx_nonce' );
		$status = isset( $_POST['invoice_status'] ) ? sanitize_text_field( wp_unslash( $_POST['invoice_status'] ) ) : 'DRAFT';
		$sale   = isset( $_POST['sale_type'] ) ? sanitize_text_field( wp_unslash( $_POST['sale_type'] ) ) : 'Invoice';

		Dwtx_Settings::update(
			array(
				'default_income_acct' => isset( $_POST['default_income_acct'] ) ? sanitize_text_field( wp_unslash( $_POST['default_income_acct'] ) ) : '',
				'freight_income_acct' => isset( $_POST['freight_income_acct'] ) ? sanitize_text_field( wp_unslash( $_POST['freight_income_acct'] ) ) : '',
				'default_tax_code'    => isset( $_POST['default_tax_code'] ) ? sanitize_text_field( wp_unslash( $_POST['default_tax_code'] ) ) : '',
				'freight_tax_code'    => isset( $_POST['freight_tax_code'] ) ? sanitize_text_field( wp_unslash( $_POST['freight_tax_code'] ) ) : '',
				'gift_card_acct'      => isset( $_POST['gift_card_acct'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_acct'] ) ) : '',
				'gift_card_tax_code'  => isset( $_POST['gift_card_tax_code'] ) ? sanitize_text_field( wp_unslash( $_POST['gift_card_tax_code'] ) ) : '',
				'sale_type'           => in_array( $sale, array( 'Invoice', 'Quote' ), true ) ? $sale : 'Invoice',
				'invoice_status'      => in_array( $status, array( 'DRAFT', 'SUBMITTED', 'AUTHORISED' ), true ) ? $status : 'DRAFT',
			)
		);
		$this->notice( __( 'Mapping saved.', 'demfilz-sync-for-xero' ) );
	}

	/**
	 * Queue an admin notice.
	 *
	 * @param string $message Message.
	 * @param string $type    success|error.
	 * @return void
	 */
	private function notice( $message, $type = 'success' ) {
		add_settings_error( 'dwtx', 'dwtx', $message, 'error' === $type ? 'error' : 'updated' );
		set_transient( 'settings_errors', get_settings_errors(), 30 );
	}

	/**
	 * Render the settings page shell + active tab.
	 *
	 * @return void
	 */
	public function render() {
		$tab  = $this->current_tab();
		$tabs = array(
			'connection' => __( 'Connection', 'demfilz-sync-for-xero' ),
			'mapping'    => __( 'Mapping', 'demfilz-sync-for-xero' ),
			'sync'       => __( 'Sync', 'demfilz-sync-for-xero' ),
			'logs'       => __( 'Logs', 'demfilz-sync-for-xero' ),
		);
		?>
		<div class="wrap dwtx-wrap">
			<h1><?php esc_html_e( 'Demfilz Sync for WooCommerce to Xero', 'demfilz-sync-for-xero' ); ?></h1>
			<?php settings_errors( 'dwtx' ); ?>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=dwtx&tab=' . $slug ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>
			<div class="dwtx-tab">
				<?php
				$view = DWTX_DIR . 'includes/admin/views/' . $tab . '.php';
				if ( file_exists( $view ) ) {
					include $view;
				}
				?>
			</div>
		</div>
		<?php
	}
}
