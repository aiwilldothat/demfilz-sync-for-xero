<?php
/**
 * Mapping tab: sale type, invoice status, default income account, tax types.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

if ( ! Dwtx_OAuth::is_connected() ) {
	echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Connect to Xero first to load accounts and tax rates.', 'demfilz-sync-for-xero' ) . '</p></div>';
	return;
}

$dwtx_org = get_option( 'dwtx_organisation', array() );
if ( empty( $dwtx_org['id'] ) ) {
	$dwtx_conn_url = admin_url( 'admin.php?page=dwtx&tab=connection' );
	echo '<div class="notice notice-warning inline"><p>'
		. esc_html__( 'No organisation selected yet, so accounts and tax rates cannot load.', 'demfilz-sync-for-xero' )
		. ' <a href="' . esc_url( $dwtx_conn_url ) . '">'
		. esc_html__( 'Choose and save your organisation on the Connection tab', 'demfilz-sync-for-xero' )
		. '</a>.</p></div>';
	return;
}

$dwtx_provider = Dwtx_Provider_Factory::make();
$dwtx_accounts = array();
$dwtx_taxes    = array();
$dwtx_gift_acs = array();
$dwtx_load_err = '';
if ( is_wp_error( $dwtx_provider ) ) {
	$dwtx_load_err = $dwtx_provider->get_error_message();
} else {
	$dwtx_accounts = $dwtx_provider->list_accounts();
	$dwtx_taxes    = $dwtx_provider->list_tax_codes();
	$dwtx_gift_acs = $dwtx_provider->list_liability_accounts();
	$dwtx_gift_acs = is_wp_error( $dwtx_gift_acs ) ? array() : $dwtx_gift_acs;
	if ( is_wp_error( $dwtx_accounts ) ) {
		$dwtx_load_err = $dwtx_accounts->get_error_message();
		$dwtx_accounts = array();
	}
	if ( is_wp_error( $dwtx_taxes ) ) {
		$dwtx_load_err = '' !== $dwtx_load_err ? $dwtx_load_err : $dwtx_taxes->get_error_message();
		$dwtx_taxes    = array();
	}
}

if ( '' !== $dwtx_load_err ) {
	echo '<div class="notice notice-error inline"><p><strong>'
		. esc_html__( 'Could not load your Xero accounts and tax rates:', 'demfilz-sync-for-xero' )
		. '</strong> ' . esc_html( $dwtx_load_err ) . '<br>'
		. esc_html__( 'If this mentions a rate limit, wait a few minutes and reload. Otherwise reconnect on the Connection tab.', 'demfilz-sync-for-xero' )
		. '</p></div>';
} elseif ( empty( $dwtx_accounts ) && empty( $dwtx_taxes ) ) {
	echo '<div class="notice notice-warning inline"><p>'
		. esc_html__( 'No accounts or tax rates came back from Xero. Reconnect on the Connection tab, then reload this page.', 'demfilz-sync-for-xero' )
		. '</p></div>';
}

$dwtx_sale_type = Dwtx_Settings::get( 'sale_type', 'Invoice' );
$dwtx_status    = Dwtx_Settings::get( 'invoice_status', 'DRAFT' );
$dwtx_inc       = Dwtx_Settings::get( 'default_income_acct' );
$dwtx_ship_acct = Dwtx_Settings::get( 'freight_income_acct' );
$dwtx_tax       = Dwtx_Settings::get( 'default_tax_code' );
$dwtx_freight   = Dwtx_Settings::get( 'freight_tax_code' );
$dwtx_gift_acct = Dwtx_Settings::get( 'gift_card_acct' );
$dwtx_gift_tax  = Dwtx_Settings::get( 'gift_card_tax_code' );

if ( ! function_exists( 'dwtx_render_select' ) ) :
	/**
	 * Render a select populated from a value=>label map.
	 *
	 * @param string $name     Field name.
	 * @param array  $options  value => label.
	 * @param string $selected Selected value.
	 * @return void
	 */
	function dwtx_render_select( $name, $options, $selected ) {
		echo '<select name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html__( '— Select —', 'demfilz-sync-for-xero' ) . '</option>';
		foreach ( $options as $dwtx_value => $dwtx_label ) {
			echo '<option value="' . esc_attr( $dwtx_value ) . '" ' . selected( $selected, $dwtx_value, false ) . '>' . esc_html( $dwtx_label ) . '</option>';
		}
		echo '</select>';
	}
endif;
?>

<form method="post">
	<?php wp_nonce_field( 'dwtx_mapping', 'dwtx_nonce' ); ?>
	<input type="hidden" name="dwtx_form" value="mapping" />
	<table class="form-table" role="presentation">
		<tr>
			<th><?php esc_html_e( 'Post orders to Xero as', 'demfilz-sync-for-xero' ); ?></th>
			<td>
				<select name="sale_type">
					<?php foreach ( array( 'Invoice', 'Quote' ) as $dwtx_st ) : ?>
						<option value="<?php echo esc_attr( $dwtx_st ); ?>" <?php selected( $dwtx_sale_type, $dwtx_st ); ?>><?php echo esc_html( $dwtx_st ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Invoice status', 'demfilz-sync-for-xero' ); ?></th>
			<td>
				<select name="invoice_status">
					<?php foreach ( array( 'DRAFT', 'SUBMITTED', 'AUTHORISED' ) as $dwtx_iv ) : ?>
						<option value="<?php echo esc_attr( $dwtx_iv ); ?>" <?php selected( $dwtx_status, $dwtx_iv ); ?>><?php echo esc_html( $dwtx_iv ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Only applies when posting as an Invoice. Quotes are created as DRAFT.', 'demfilz-sync-for-xero' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Default income account', 'demfilz-sync-for-xero' ); ?></th>
			<td><?php dwtx_render_select( 'default_income_acct', $dwtx_accounts, $dwtx_inc ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Shipping account', 'demfilz-sync-for-xero' ); ?></th>
			<td>
				<?php dwtx_render_select( 'freight_income_acct', $dwtx_accounts, $dwtx_ship_acct ); ?>
				<p class="description"><?php esc_html_e( 'Account the shipping line posts to. Leave blank to use the income account.', 'demfilz-sync-for-xero' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Default tax rate', 'demfilz-sync-for-xero' ); ?></th>
			<td><?php dwtx_render_select( 'default_tax_code', $dwtx_taxes, $dwtx_tax ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Shipping tax rate', 'demfilz-sync-for-xero' ); ?></th>
			<td><?php dwtx_render_select( 'freight_tax_code', $dwtx_taxes, $dwtx_freight ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Gift card account', 'demfilz-sync-for-xero' ); ?></th>
			<td>
				<?php dwtx_render_select( 'gift_card_acct', $dwtx_gift_acs, $dwtx_gift_acct ); ?>
				<p class="description"><?php esc_html_e( 'Only needed if you sell gift cards or accept gift cards or store credit (Demfilz Gift Cards and Store Credit). Choose a liability account, for example "Gift card liability". Gift cards sold post to it instead of income, and amounts paid by gift card or store credit come out of it as a negative line, so your revenue and GST stay complete and the invoice total matches what the customer was charged.', 'demfilz-sync-for-xero' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Gift card tax rate', 'demfilz-sync-for-xero' ); ?></th>
			<td>
				<?php dwtx_render_select( 'gift_card_tax_code', $dwtx_taxes, $dwtx_gift_tax ); ?>
				<p class="description"><?php esc_html_e( 'Choose a rate with no tax, such as BAS Excluded. Leave blank to use the account default.', 'demfilz-sync-for-xero' ); ?></p>
			</td>
		</tr>
	</table>
	<p class="description"><?php esc_html_e( 'Free tier maps all lines to one tax rate. Pro adds per-product / per-tax-class rules. Leave a tax rate blank to let Xero use the account default.', 'demfilz-sync-for-xero' ); ?></p>
	<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Save mapping', 'demfilz-sync-for-xero' ); ?></button></p>
</form>
