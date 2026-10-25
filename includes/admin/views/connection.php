<?php
/**
 * Connection tab: Xero app credentials, OAuth, organisation selection.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

$dwtx_connected = Dwtx_OAuth::is_connected();
$dwtx_org       = get_option( 'dwtx_organisation', array() );
$dwtx_org_saved = ! empty( $dwtx_org['id'] );
$dwtx_redirect  = Dwtx_OAuth::redirect_uri();
?>

<div class="dwtx-status">
	<strong><?php esc_html_e( 'Status:', 'demfilz-sync-for-xero' ); ?></strong>
	<?php if ( $dwtx_connected ) : ?>
		<span class="dwtx-pill dwtx-ok"><?php esc_html_e( 'Connected to Xero', 'demfilz-sync-for-xero' ); ?></span>
		<?php if ( $dwtx_org_saved ) : ?>
			<span class="dwtx-pill"><?php echo esc_html( $dwtx_org['name'] ); ?></span>
		<?php else : ?>
			<span class="dwtx-pill dwtx-warn"><?php esc_html_e( 'Organisation not selected', 'demfilz-sync-for-xero' ); ?></span>
		<?php endif; ?>
	<?php else : ?>
		<span class="dwtx-pill dwtx-warn"><?php esc_html_e( 'Not connected', 'demfilz-sync-for-xero' ); ?></span>
	<?php endif; ?>
</div>

<h3><?php esc_html_e( '1. Xero developer app', 'demfilz-sync-for-xero' ); ?></h3>
<p class="description">
	<?php esc_html_e( 'Create a "Web app" at the Xero developer portal (developer.xero.com) to get a Client ID and Client Secret. Add the redirect URI below to your app.', 'demfilz-sync-for-xero' ); ?>
</p>
<p><code><?php echo esc_html( $dwtx_redirect ); ?></code></p>

<form method="post">
	<?php wp_nonce_field( 'dwtx_credentials', 'dwtx_nonce' ); ?>
	<input type="hidden" name="dwtx_form" value="credentials" />
	<table class="form-table" role="presentation">
		<tr>
			<th><label for="dwtx_cid"><?php esc_html_e( 'Client ID', 'demfilz-sync-for-xero' ); ?></label></th>
			<td><input type="text" id="dwtx_cid" name="client_id" class="regular-text" value="<?php echo esc_attr( Dwtx_OAuth::client_id() ); ?>" autocomplete="off" /></td>
		</tr>
		<tr>
			<th><label for="dwtx_cs"><?php esc_html_e( 'Client Secret', 'demfilz-sync-for-xero' ); ?></label></th>
			<td>
				<input type="password" id="dwtx_cs" name="client_secret" class="regular-text" value="" autocomplete="off" placeholder="<?php echo Dwtx_OAuth::client_secret() ? '••••••••' : ''; ?>" />
				<p class="description"><?php esc_html_e( 'Stored encrypted. Leave blank to keep the existing secret.', 'demfilz-sync-for-xero' ); ?></p>
			</td>
		</tr>
	</table>
	<p>
		<button type="submit" class="button"><?php esc_html_e( 'Save', 'demfilz-sync-for-xero' ); ?></button>
		<button type="submit" name="connect" value="1" class="button button-primary"><?php esc_html_e( 'Save & Connect to Xero', 'demfilz-sync-for-xero' ); ?></button>
	</p>
</form>

<?php if ( $dwtx_connected ) : ?>
	<hr />
	<h3><?php esc_html_e( '2. Organisation', 'demfilz-sync-for-xero' ); ?></h3>
	<?php
	$dwtx_provider = Dwtx_Provider_Factory::make();
	$dwtx_orgs     = is_wp_error( $dwtx_provider ) ? $dwtx_provider : $dwtx_provider->list_organisations();
	?>
	<?php if ( is_wp_error( $dwtx_orgs ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $dwtx_orgs->get_error_message() ); ?></p></div>
	<?php elseif ( empty( $dwtx_orgs ) ) : ?>
		<div class="notice notice-warning inline"><p><?php esc_html_e( 'No Xero organisations were returned for this connection.', 'demfilz-sync-for-xero' ); ?></p></div>
	<?php else : ?>
		<?php if ( $dwtx_org_saved ) : ?>
			<div class="notice notice-success inline"><p>
				<?php
				/* translators: %s: Xero organisation name. */
				echo esc_html( sprintf( __( 'Using organisation: %s. Choose a different one below and save if needed.', 'demfilz-sync-for-xero' ), $dwtx_org['name'] ) );
				?>
			</p></div>
		<?php else : ?>
			<div class="notice notice-warning inline"><p>
				<strong><?php esc_html_e( 'Almost there —', 'demfilz-sync-for-xero' ); ?></strong>
				<?php esc_html_e( 'you are connected, but you still need to choose your organisation below and click Save organisation. Mapping and syncing will not work until you do.', 'demfilz-sync-for-xero' ); ?>
			</p></div>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'dwtx_organisation', 'dwtx_nonce' ); ?>
			<input type="hidden" name="dwtx_form" value="organisation" />
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'Select organisation', 'demfilz-sync-for-xero' ); ?></th>
					<td>
						<select name="org_id" id="dwtx_org_select">
							<?php if ( ! $dwtx_org_saved ) : ?>
								<option value="" data-name="" selected><?php esc_html_e( '— Select your organisation —', 'demfilz-sync-for-xero' ); ?></option>
							<?php endif; ?>
							<?php foreach ( $dwtx_orgs as $dwtx_o ) : ?>
								<option value="<?php echo esc_attr( $dwtx_o['id'] ); ?>" data-name="<?php echo esc_attr( $dwtx_o['name'] ); ?>" <?php selected( isset( $dwtx_org['id'] ) ? $dwtx_org['id'] : '', $dwtx_o['id'] ); ?>>
									<?php echo esc_html( $dwtx_o['name'] ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<input type="hidden" name="org_name" id="dwtx_org_name" value="<?php echo esc_attr( isset( $dwtx_org['name'] ) ? $dwtx_org['name'] : '' ); ?>" />
						<p class="description"><?php esc_html_e( 'Even if a name is shown, you must click Save organisation to store it.', 'demfilz-sync-for-xero' ); ?></p>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php echo $dwtx_org_saved ? esc_html__( 'Save organisation', 'demfilz-sync-for-xero' ) : esc_html__( 'Save organisation to finish', 'demfilz-sync-for-xero' ); ?></button></p>
		</form>
	<?php endif; ?>

	<hr />
	<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect from Xero?', 'demfilz-sync-for-xero' ) ); ?>');">
		<?php wp_nonce_field( 'dwtx_disconnect', 'dwtx_nonce' ); ?>
		<input type="hidden" name="dwtx_form" value="disconnect" />
		<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Disconnect', 'demfilz-sync-for-xero' ); ?></button>
	</form>
<?php endif; ?>
