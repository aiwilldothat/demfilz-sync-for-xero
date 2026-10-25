<?php
/**
 * Sync logging to the custom dwtx_log table.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Records and queries sync attempts.
 */
class Dwtx_Logger {

	/**
	 * Fully-qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dwtx_log';
	}

	/**
	 * Write a log row.
	 *
	 * Accepts: object_type (order|customer|product), object_id, direction (push|pull),
	 * status (success|error|pending|skipped), xero_id, message and context.
	 *
	 * @param array $args Log row fields, as described above.
	 * @return int Inserted row id.
	 */
	public static function add( array $args ) {
		global $wpdb;

		$defaults = array(
			'object_type' => 'order',
			'object_id'   => 0,
			'direction'   => 'push',
			'status'      => 'pending',
			'xero_id'     => '',
			'message'     => '',
			'context'     => '',
		);
		$args     = wp_parse_args( $args, $defaults );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at'  => current_time( 'mysql' ),
				'object_type' => substr( (string) $args['object_type'], 0, 20 ),
				'object_id'   => (int) $args['object_id'],
				'direction'   => substr( (string) $args['direction'], 0, 20 ),
				'status'      => substr( (string) $args['status'], 0, 20 ),
				'xero_id'     => substr( (string) $args['xero_id'], 0, 64 ),
				'message'     => (string) $args['message'],
				'context'     => is_scalar( $args['context'] ) ? (string) $args['context'] : wp_json_encode( $args['context'] ),
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch recent log rows.
	 *
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array
	 */
	public static function recent( $limit = 50, $offset = 0 ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d OFFSET %d',
				$table,
				$limit,
				$offset
			)
		);
	}

	/**
	 * Latest status for a given object.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object id.
	 * @return object|null
	 */
	public static function latest_for( $object_type, $object_id ) {
		global $wpdb;
		$table = self::table();

		return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM %i WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT 1',
				$table,
				$object_type,
				$object_id
			)
		);
	}
}
