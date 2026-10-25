<?php
/**
 * Sync tab: explains manual (free) vs automatic (Pro) and exposes Pro upsell.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

$dwtx_pro = Dwtx_Pro::can();
?>

<?php if ( Dwtx_OAuth::is_connected() ) : ?>
	<h3><?php esc_html_e( 'Manual stock sync', 'demfilz-sync-for-xero' ); ?></h3>
	<p><?php esc_html_e( 'Pull the current stock-on-hand from Xero for every published product that has a SKU, in one go. Products are matched to Xero by their SKU, so any product whose SKU does not match its item code in Xero is skipped.', 'demfilz-sync-for-xero' ); ?></p>
	<p>
		<button type="button" class="button button-primary dwtx-sync-all"><?php esc_html_e( 'Sync all stock from Xero now', 'demfilz-sync-for-xero' ); ?></button>
		<span class="dwtx-msg"></span>
	</p>
	<p class="description"><?php esc_html_e( 'Keep this tab open until it finishes. Large catalogues are processed in small batches.', 'demfilz-sync-for-xero' ); ?></p>
	<hr />
<?php endif; ?>

<h3><?php esc_html_e( 'How syncing works', 'demfilz-sync-for-xero' ); ?></h3>
<table class="widefat striped dwtx-feature-table">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Feature', 'demfilz-sync-for-xero' ); ?></th>
			<th><?php esc_html_e( 'Free', 'demfilz-sync-for-xero' ); ?></th>
			<th><?php esc_html_e( 'Pro', 'demfilz-sync-for-xero' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<tr><td><?php esc_html_e( 'Push an order to Xero (button on the order)', 'demfilz-sync-for-xero' ); ?></td><td>✔</td><td>✔</td></tr>
		<tr><td><?php esc_html_e( 'Sync customer/contact on push', 'demfilz-sync-for-xero' ); ?></td><td>✔</td><td>✔</td></tr>
		<tr><td><?php esc_html_e( 'Pull Xero stock for a product (button)', 'demfilz-sync-for-xero' ); ?></td><td>✔</td><td>✔</td></tr>
		<tr><td><?php esc_html_e( 'Automatic order sync on status change', 'demfilz-sync-for-xero' ); ?></td><td>—</td><td>✔</td></tr>
		<tr><td><?php esc_html_e( 'Bulk / historical order backfill', 'demfilz-sync-for-xero' ); ?></td><td><?php esc_html_e( 'Last 5', 'demfilz-sync-for-xero' ); ?></td><td><?php esc_html_e( 'Unlimited', 'demfilz-sync-for-xero' ); ?></td></tr>
		<tr><td><?php esc_html_e( 'Scheduled inventory sync (all products)', 'demfilz-sync-for-xero' ); ?></td><td>—</td><td>✔</td></tr>
		<tr><td><?php esc_html_e( 'Refunds / credit notes to Xero', 'demfilz-sync-for-xero' ); ?></td><td>—</td><td>✔</td></tr>
		<tr><td><?php esc_html_e( 'Auto-retry failed syncs + email alerts', 'demfilz-sync-for-xero' ); ?></td><td>—</td><td>✔</td></tr>
		<tr><td><?php esc_html_e( 'Supplier POs → Xero bills (with Demfilz Auto Order)', 'demfilz-sync-for-xero' ); ?></td><td>—</td><td>✔</td></tr>
	</tbody>
</table>

<?php
/**
 * Pro renders its own sync controls here (auto-sync triggers, schedules, bulk).
 *
 * @param bool $dwtx_pro Whether Pro is active.
 */
do_action( 'dwtx_sync_tab_after', $dwtx_pro );
?>

<?php if ( ! $dwtx_pro ) : ?>
	<div class="dwtx-upsell">
		<h3><?php esc_html_e( 'Upgrade to Pro — A$19.95/month', 'demfilz-sync-for-xero' ); ?></h3>
		<p><?php esc_html_e( 'Switch on automatic order sync, scheduled inventory, bulk backfill, refunds and auto-retry. Cancel anytime.', 'demfilz-sync-for-xero' ); ?></p>
		<a class="button button-primary" href="https://demfilz.com/demfilz-woo-to-xero" target="_blank" rel="noopener"><?php esc_html_e( 'Learn about Pro', 'demfilz-sync-for-xero' ); ?></a>
	</div>
<?php endif; ?>
