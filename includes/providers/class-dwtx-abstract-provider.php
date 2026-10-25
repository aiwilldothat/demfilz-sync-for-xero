<?php
/**
 * Shared HTTP plumbing for accounting provider adapters (Xero).
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base adapter: builds authenticated requests and parses Xero responses.
 *
 * Xero's Accounting API lives under https://api.xero.com/api.xro/2.0 and every
 * resource call carries the selected organisation in the `Xero-tenant-id` header.
 * The connections endpoint (which lists tenants) is the one call that omits it.
 */
abstract class Dwtx_Abstract_Provider implements Dwtx_Provider {

	/**
	 * Accounting resource API base URL (no trailing slash).
	 *
	 * @var string
	 */
	protected $base = 'https://api.xero.com/api.xro/2.0';

	/**
	 * Connections endpoint (lists the tenants the user authorised).
	 *
	 * @var string
	 */
	protected $connections_url = 'https://api.xero.com/connections';

	/**
	 * Selected Xero tenant (organisation) id.
	 *
	 * @var string
	 */
	protected $tenant_id = '';

	/**
	 * Constructor.
	 *
	 * @param string $tenant_id Xero tenant id (optional when only listing organisations).
	 */
	public function __construct( $tenant_id = '' ) {
		$this->tenant_id = $tenant_id;
	}

	/**
	 * Common Xero request headers.
	 *
	 * @param bool $with_tenant Whether to attach the Xero-tenant-id header (omit when listing tenants).
	 * @return array|WP_Error
	 */
	protected function headers( $with_tenant = true ) {
		$token = Dwtx_OAuth::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
		);

		if ( $with_tenant && $this->tenant_id ) {
			$headers['Xero-tenant-id'] = $this->tenant_id;
		}

		return $headers;
	}

	/**
	 * Perform an authenticated GET.
	 *
	 * @param string $url        Absolute URL.
	 * @param array  $query_args Query parameters.
	 * @param bool   $with_tenant Whether to send the tenant header.
	 * @return array|WP_Error Decoded body.
	 */
	protected function get( $url, $query_args = array(), $with_tenant = true ) {
		return $this->request( 'GET', $url, null, $query_args, $with_tenant );
	}

	/**
	 * Perform an authenticated POST. Xero returns the created/updated resource in
	 * the response body by default (no extra flag needed).
	 *
	 * @param string $url  Absolute URL.
	 * @param array  $body Payload.
	 * @return array|WP_Error
	 */
	protected function post( $url, $body ) {
		return $this->request( 'POST', $url, $body );
	}

	/**
	 * Core request helper.
	 *
	 * @param string     $method      HTTP method.
	 * @param string     $url         Absolute URL.
	 * @param array|null $body        Payload for write methods.
	 * @param array      $query_args  Query parameters.
	 * @param bool       $with_tenant Whether to send the tenant header.
	 * @return array|WP_Error
	 */
	protected function request( $method, $url, $body = null, $query_args = array(), $with_tenant = true ) {
		$headers = $this->headers( $with_tenant );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		if ( ! empty( $query_args ) ) {
			$url = add_query_arg( $query_args, $url );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 45,
			'headers' => $headers,
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		// Xero rate-limits to 60 calls/minute per organisation and replies 429 with a
		// Retry-After header. Wait and retry a couple of times so a brief limit does not
		// fail the sync. Waits are bounded, and larger in background (cron) context than
		// interactive so a manual click never hangs for long.
		$max_wait = function_exists( 'wp_doing_cron' ) && wp_doing_cron() ? 60 : 10;
		$attempts = 0;
		do {
			$response = wp_remote_request( $url, $args );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( 429 !== $status || $attempts >= 2 ) {
				break;
			}
			$wait = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			if ( $wait <= 0 ) {
				$wait = 5;
			}
			if ( $wait > $max_wait ) {
				break;
			}
			++$attempts;
			sleep( $wait ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration, WordPressVIPMinimum.Performance.LowExpiryCacheTime.sleep -- Deliberate rate-limit backoff, bounded above.
		} while ( true );

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = wp_remote_retrieve_body( $response );
		$data   = '' !== $raw ? json_decode( $raw, true ) : array();

		if ( 429 === $status ) {
			$retry = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$msg   = $retry > 0
				/* translators: %d: number of seconds to wait. */
				? sprintf( __( 'Xero is rate-limiting requests (429). Please wait %d seconds and try again.', 'demfilz-sync-for-xero' ), $retry )
				: __( 'Xero is rate-limiting requests (429). Please wait a minute and try again.', 'demfilz-sync-for-xero' );
			return new WP_Error(
				'dwtx_api_rate_limit',
				$msg,
				array(
					'status'      => 429,
					'retry_after' => $retry,
				)
			);
		}

		if ( $status < 200 || $status >= 300 ) {
			$detail = '';
			if ( isset( $data['Elements'][0]['ValidationErrors'][0]['Message'] ) ) {
				$detail = $data['Elements'][0]['ValidationErrors'][0]['Message'];
			} elseif ( isset( $data['Message'] ) ) {
				$detail = $data['Message'];
			} elseif ( isset( $data['detail'] ) ) {
				$detail = $data['detail'];
			}
			/* translators: 1: HTTP status code, 2: Xero error detail. */
			$msg = sprintf( __( 'Xero API error (%1$d): %2$s', 'demfilz-sync-for-xero' ), $status, $detail );
			return new WP_Error(
				'dwtx_api_error',
				$msg,
				array(
					'status' => $status,
					'body'   => $raw,
				)
			);
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Build a resource URL under the Accounting API base.
	 *
	 * @param string $path Resource path beginning with '/' (e.g. '/Invoices').
	 * @return string
	 */
	protected function api_url( $path ) {
		return untrailingslashit( $this->base ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * Generic Xero GET (public entry point for extensions).
	 *
	 * @param string $endpoint Resource path (e.g. '/Contacts').
	 * @param array  $query    Query args (e.g. array( 'where' => '...' )).
	 * @return array|WP_Error Decoded body.
	 */
	public function api_get( $endpoint, $query = array() ) {
		return $this->get( $this->api_url( $endpoint ), $query );
	}

	/**
	 * Generic Xero POST (public entry point for extensions).
	 *
	 * @param string $endpoint Resource path (e.g. '/Invoices').
	 * @param array  $payload  Entity payload (already wrapped, e.g. array( 'Invoices' => array( ... ) )).
	 * @return array|WP_Error Decoded body.
	 */
	public function api_post( $endpoint, array $payload ) {
		return $this->post( $this->api_url( $endpoint ), $payload );
	}
}
