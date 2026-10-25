/* global jQuery, dwtxAdmin */
( function ( $ ) {
	'use strict';

	function run( $btn, action, dataKey, dataVal ) {
		var $msg = $btn.siblings( '.dwtx-msg' );
		var orig = $btn.text();
		$btn.prop( 'disabled', true );
		$msg.text( dwtxAdmin.syncing ).removeClass( 'dwtx-ok dwtx-bad' );

		var payload = { action: action, nonce: dwtxAdmin.nonce };
		payload[ dataKey ] = dataVal;

		$.post( dwtxAdmin.ajaxUrl, payload )
			.done( function ( res ) {
				if ( res && res.success ) {
					$msg.text( res.data.message ).addClass( 'dwtx-ok' );
				} else {
					$msg.text( ( res && res.data && res.data.message ) || 'Error' ).addClass( 'dwtx-bad' );
				}
			} )
			.fail( function () {
				$msg.text( 'Request failed' ).addClass( 'dwtx-bad' );
			} )
			.always( function () {
				$btn.prop( 'disabled', false ).text( orig );
			} );
	}

	$( document ).on( 'click', '.dwtx-push-order', function ( e ) {
		e.preventDefault();
		run( $( this ), 'dwtx_push_order', 'order_id', $( this ).data( 'order' ) );
	} );

	$( document ).on( 'click', '.dwtx-pull-stock', function ( e ) {
		e.preventDefault();
		run( $( this ), 'dwtx_pull_stock', 'product_id', $( this ).data( 'product' ) );
	} );

	// Bulk "sync all stock" — loops through the catalogue in batches so it works
	// without WP-Cron and stays within the Xero rate limit.
	$( document ).on( 'click', '.dwtx-sync-all', function ( e ) {
		e.preventDefault();
		var $btn = $( this );
		var $msg = $btn.siblings( '.dwtx-msg' );
		var orig = $btn.text();
		var tot = { updated: 0, skipped: 0, errors: 0, total: 0 };

		$btn.prop( 'disabled', true );
		$msg.removeClass( 'dwtx-ok dwtx-bad' ).text( dwtxAdmin.syncing );

		function step( offset ) {
			$.post( dwtxAdmin.ajaxUrl, { action: 'dwtx_sync_all_stock', nonce: dwtxAdmin.nonce, offset: offset } )
				.done( function ( res ) {
					if ( ! res || ! res.success ) {
						$msg.text( ( res && res.data && res.data.message ) || 'Error' ).addClass( 'dwtx-bad' );
						$btn.prop( 'disabled', false ).text( orig );
						return;
					}
					var d = res.data;
					if ( d.total ) {
						tot.total = d.total;
					}
					tot.updated += d.updated;
					tot.skipped += d.skipped;
					tot.errors += d.errors;

					if ( d.done ) {
						var summary = 'Done. Updated ' + tot.updated + ', ' + tot.skipped + ' without a SKU';
						if ( tot.errors ) {
							summary += ', ' + tot.errors + ' not found in Xero';
						}
						$msg.text( summary + '.' ).addClass( 'dwtx-ok' );
						$btn.prop( 'disabled', false ).text( orig );
					} else {
						if ( tot.total ) {
							$msg.text( dwtxAdmin.syncing + ' ' + Math.min( d.next_offset, tot.total ) + ' / ' + tot.total );
						}
						// Brief pause between batches to keep the browser responsive (stock is
						// matched from a single Xero fetch, so no per-product API calls here).
						setTimeout( function () {
							step( d.next_offset );
						}, 300 );
					}
				} )
				.fail( function () {
					$msg.text( 'Request failed' ).addClass( 'dwtx-bad' );
					$btn.prop( 'disabled', false ).text( orig );
				} );
		}

		step( 0 );
	} );

	// Keep the hidden organisation name in sync with the select.
	$( document ).on( 'change', '#dwtx_org_select', function () {
		var $sel = $( this ).find( ':selected' );
		$( '#dwtx_org_name' ).val( $sel.data( 'name' ) || '' );
	} );
}( jQuery ) );
