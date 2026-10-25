<?php
/**
 * Encrypt-at-rest helper for OAuth tokens and licence secrets.
 *
 * Uses libsodium when available, falling back to OpenSSL AES-256-GCM. The key is
 * derived from WordPress's own salts so no extra secret needs storing.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Symmetric encryption for sensitive option values.
 */
class Dwtx_Crypto {

	/**
	 * Derive a 32-byte key from WP salts.
	 *
	 * @return string Raw 32-byte key.
	 */
	private static function key() {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . 'dwtx';
		return hash( 'sha256', $material, true );
	}

	/**
	 * Encrypt a string. Returns base64 of nonce|ciphertext, prefixed by scheme.
	 *
	 * @param string $plaintext Value to encrypt.
	 * @return string
	 */
	public static function encrypt( $plaintext ) {
		$plaintext = (string) $plaintext;
		if ( '' === $plaintext ) {
			return '';
		}

		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plaintext, $nonce, self::key() );
			return 'sb:' . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		if ( function_exists( 'openssl_encrypt' ) ) {
			$iv     = random_bytes( 12 );
			$tag    = '';
			$cipher = openssl_encrypt( $plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
			return 'og:' . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Last resort: do not store plaintext silently.
		return '';
	}

	/**
	 * Decrypt a value produced by encrypt().
	 *
	 * @param string $ciphertext Stored value.
	 * @return string Empty string on failure.
	 */
	public static function decrypt( $ciphertext ) {
		$ciphertext = (string) $ciphertext;
		if ( '' === $ciphertext ) {
			return '';
		}

		$scheme = substr( $ciphertext, 0, 3 );
		$raw    = base64_decode( substr( $ciphertext, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return '';
		}

		if ( 'sb:' === $scheme && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$plain  = sodium_crypto_secretbox_open( $cipher, $nonce, self::key() );
			return false === $plain ? '' : $plain;
		}

		if ( 'og:' === $scheme && function_exists( 'openssl_decrypt' ) ) {
			$iv     = substr( $raw, 0, 12 );
			$tag    = substr( $raw, 12, 16 );
			$cipher = substr( $raw, 28 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
			return false === $plain ? '' : $plain;
		}

		return '';
	}
}
