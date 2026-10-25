<?php
/**
 * Xero OAuth 2.0 connection handling: authorize, token exchange, refresh, storage.
 *
 * Implements the Xero "Web app" Authorization Code flow (confidential client with a
 * client secret). After connecting, the available organisations (tenants) are read
 * from the Xero connections endpoint and one is selected as the active organisation.
 *
 * Endpoints (Xero developer portal -> OAuth 2.0):
 *   authorize: https://login.xero.com/identity/connect/authorize
 *   token:     https://identity.xero.com/connect/token
 *   connections (tenants): https://api.xero.com/connections
 *
 * Notes:
 *  - Access tokens last ~30 minutes; refresh tokens last 60 days and ROTATE on each
 *    refresh (the new refresh token must be stored, which store_token_response does).
 *  - offline_access scope is required to receive a refresh token.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Owns the OAuth dance and the encrypted token store.
 */
class Dwtx_OAuth {

	const AUTHORIZE_URL = 'https://login.xero.com/identity/connect/authorize';
	const TOKEN_URL     = 'https://identity.xero.com/connect/token';
	// Xero's new granular scopes (mandatory for apps created from 2 Mar 2026; also accepted by
	// older apps). accounting.invoices = sales invoices, accounting.contacts = contacts,
	// accounting.settings = accounts / tax rates / items (stock). offline_access = refresh token.
	const SCOPE         = 'offline_access accounting.contacts accounting.settings accounting.invoices';
	const TOKENS_OPTION = 'dwtx_tokens';
	const CREDS_OPTION  = 'dwtx_app_creds';

	/**
	 * Store the developer-app credentials (client id/secret) the user pastes in.
	 *
	 * @param string $client_id     Xero app Client ID.
	 * @param string $client_secret Xero app Client Secret.
	 * @return void
	 */
	public static function save_app_credentials( $client_id, $client_secret ) {
		update_option(
			self::CREDS_OPTION,
			array(
				'client_id'     => sanitize_text_field( $client_id ),
				'client_secret' => Dwtx_Crypto::encrypt( $client_secret ),
			),
			false
		);
	}

	/**
	 * Get the configured client id.
	 *
	 * @return string
	 */
	public static function client_id() {
		$creds = get_option( self::CREDS_OPTION, array() );
		return isset( $creds['client_id'] ) ? $creds['client_id'] : '';
	}

	/**
	 * Get the decrypted client secret.
	 *
	 * @return string
	 */
	public static function client_secret() {
		$creds = get_option( self::CREDS_OPTION, array() );
		return isset( $creds['client_secret'] ) ? Dwtx_Crypto::decrypt( $creds['client_secret'] ) : '';
	}

	/**
	 * The redirect URI registered with Xero (admin-ajax style callback).
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin.php?page=dwtx&dwtx_action=oauth_callback' );
	}

	/**
	 * Build the Xero consent URL.
	 *
	 * @return string
	 */
	public static function authorize_url() {
		$state = wp_create_nonce( 'dwtx_oauth' );
		set_transient( 'dwtx_oauth_state', $state, 15 * MINUTE_IN_SECONDS );

		return add_query_arg(
			array(
				'response_type' => 'code',
				'client_id'     => rawurlencode( self::client_id() ),
				'redirect_uri'  => rawurlencode( self::redirect_uri() ),
				'scope'         => rawurlencode( self::SCOPE ),
				'state'         => rawurlencode( $state ),
			),
			self::AUTHORIZE_URL
		);
	}

	/**
	 * Basic-auth header for the token endpoint (client_id:client_secret).
	 *
	 * @return string
	 */
	private static function basic_auth() {
		return 'Basic ' . base64_encode( self::client_id() . ':' . self::client_secret() ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Exchange an authorization code for tokens and store them.
	 *
	 * @param string $code Authorization code from the Xero callback.
	 * @return true|WP_Error
	 */
	public static function exchange_code( $code ) {
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => self::basic_auth(),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'   => 'authorization_code',
					'code'         => $code,
					'redirect_uri' => self::redirect_uri(),
				),
			)
		);

		return self::store_token_response( $response );
	}

	/**
	 * Refresh the access token using the stored refresh token.
	 *
	 * @return true|WP_Error
	 */
	public static function refresh() {
		$tokens  = self::tokens();
		$refresh = isset( $tokens['refresh_token_enc'] ) ? Dwtx_Crypto::decrypt( $tokens['refresh_token_enc'] ) : '';
		if ( '' === $refresh ) {
			return new WP_Error( 'dwtx_no_refresh', __( 'No Xero refresh token stored. Please reconnect.', 'demfilz-sync-for-xero' ) );
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => self::basic_auth(),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => array(
					'grant_type'    => 'refresh_token',
					'refresh_token' => $refresh,
				),
			)
		);

		return self::store_token_response( $response );
	}

	/**
	 * Parse a token endpoint response and persist tokens (encrypted).
	 *
	 * @param array|WP_Error $response wp_remote_post result.
	 * @return true|WP_Error
	 */
	private static function store_token_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body['access_token'] ) ) {
			$msg = isset( $body['error_description'] ) ? $body['error_description'] : ( isset( $body['error'] ) ? $body['error'] : __( 'Xero token request failed.', 'demfilz-sync-for-xero' ) );
			return new WP_Error( 'dwtx_token_failed', $msg, array( 'status' => $code ) );
		}

		$existing = self::tokens();
		// Xero rotates the refresh token on every refresh; keep the latest, fall back to the previous.
		$refresh = ! empty( $body['refresh_token'] )
			? $body['refresh_token']
			: ( isset( $existing['refresh_token_enc'] ) ? Dwtx_Crypto::decrypt( $existing['refresh_token_enc'] ) : '' );
		$expires = time() + (int) ( isset( $body['expires_in'] ) ? $body['expires_in'] : 1800 ) - 60;

		update_option(
			self::TOKENS_OPTION,
			array(
				'access_token_enc'  => Dwtx_Crypto::encrypt( $body['access_token'] ),
				'refresh_token_enc' => Dwtx_Crypto::encrypt( $refresh ),
				'expires_at'        => $expires,
				'scope'             => isset( $body['scope'] ) ? $body['scope'] : '',
			),
			false
		);

		self::schedule_refresh( $expires );
		return true;
	}

	/**
	 * Raw stored token option (encrypted fields).
	 *
	 * @return array
	 */
	public static function tokens() {
		$t = get_option( self::TOKENS_OPTION, array() );
		return is_array( $t ) ? $t : array();
	}

	/**
	 * Whether we currently hold a (possibly expired) connection.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$t = self::tokens();
		return ! empty( $t['refresh_token_enc'] );
	}

	/**
	 * Get a valid bearer access token, refreshing if expired.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$t = self::tokens();
		if ( empty( $t['access_token_enc'] ) ) {
			return new WP_Error( 'dwtx_not_connected', __( 'Not connected to Xero.', 'demfilz-sync-for-xero' ) );
		}

		if ( empty( $t['expires_at'] ) || time() >= (int) $t['expires_at'] ) {
			$refreshed = self::refresh();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$t = self::tokens();
		}

		return Dwtx_Crypto::decrypt( $t['access_token_enc'] );
	}

	/**
	 * Schedule a background refresh just before expiry.
	 *
	 * @param int $expires_at Unix timestamp.
	 * @return void
	 */
	private static function schedule_refresh( $expires_at ) {
		wp_clear_scheduled_hook( 'dwtx_token_refresh' );
		$when = max( time() + 60, (int) $expires_at - 120 );
		wp_schedule_single_event( $when, 'dwtx_token_refresh' );
	}

	/**
	 * Disconnect: wipe tokens and the selected organisation.
	 *
	 * @return void
	 */
	public static function disconnect() {
		delete_option( self::TOKENS_OPTION );
		delete_option( 'dwtx_organisation' );
		wp_clear_scheduled_hook( 'dwtx_token_refresh' );
	}
}
