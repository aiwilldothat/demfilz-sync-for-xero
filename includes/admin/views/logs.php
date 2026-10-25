<?php
/**
 * Logs tab: recent sync attempts.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

$dwtx_rows = Dwtx_Logger::recent( 100 );
?>

<h3><?php esc_html_e( 'Recent sync activity', 'demfilz-sync-for-xero' ); ?></h3>
<table class="widefat striped">
	<thead>
		<tr>
			<th><?php esc_html_e( 'When', 'demfilz-sync-for-xero' ); ?></th>
			<th><?php esc_html_e( 'Type', 'demfilz-sync-for-xero' ); ?></th>
			<th><?php esc_html_e( 'Object', 'demfilz-sync-for-xero' ); ?></th>
			<th><?php esc_html_e( 'Direction', 'demfilz-sync-for-xero' ); ?></th>
			<th><?php esc_html_e( 'Status', 'demfilz-sync-for-xero' ); ?></th>
			<th><?php esc_html_e( 'Message', 'demfilz-sync-for-xero' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php if ( empty( $dwtx_rows ) ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No sync activity yet.', 'demfilz-sync-for-xero' ); ?></td></tr>
		<?php else : ?>
			<?php foreach ( $dwtx_rows as $dwtx_r ) : ?>
				<tr>
					<td><?php echo esc_html( $dwtx_r->created_at ); ?></td>
					<td><?php echo esc_html( $dwtx_r->object_type ); ?></td>
					<td>#<?php echo esc_html( $dwtx_r->object_id ); ?></td>
					<td><?php echo esc_html( $dwtx_r->direction ); ?></td>
					<td>
						<span class="dwtx-pill <?php echo 'success' === $dwtx_r->status ? 'dwtx-ok' : ( 'error' === $dwtx_r->status ? 'dwtx-bad' : 'dwtx-warn' ); ?>">
							<?php echo esc_html( $dwtx_r->status ); ?>
						</span>
					</td>
					<td><?php echo esc_html( $dwtx_r->message ); ?></td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
	</tbody>
</table>
