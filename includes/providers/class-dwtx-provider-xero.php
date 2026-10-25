<?php
/**
 * Xero Accounting API adapter.
 *
 * Resource base: https://api.xero.com/api.xro/2.0
 * Conventions used here follow the Xero Accounting API:
 *  - Contacts are matched/created by Name (which Xero requires to be unique).
 *  - Sales post as Invoices of Type ACCREC (or as Quotes), with LineAmountTypes
 *    "Exclusive" so amounts are tax-exclusive and Xero computes the GST.
 *  - Invoice line items reference an account by Code (AccountCode) and a tax rate
 *    by TaxType — these are the values chosen on the Mapping tab.
 *  - Stock-on-hand is read from tracked Items, matched by Code == product SKU.
 *
 * @package Demfilz_Woo_To_Xero
 */

defined( 'ABSPATH' ) || exit;

/**
 * Xero platform adapter.
 */
class Dwtx_Provider_Xero extends Dwtx_Abstract_Provider {

	/**
	 * Platform key.
	 *
	 * @return string
	 */
	public function key() {
		return 'xero';
	}

	/**
	 * List organisations (Xero tenants) from the connections endpoint.
	 * This call must NOT send the Xero-tenant-id header.
	 *
	 * @return array|WP_Error
	 */
	public function list_organisations() {
		$data = $this->get( $this->connections_url, array(), false );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( (array) $data as $conn ) {
			if ( isset( $conn['tenantId'] ) && 'ORGANISATION' === ( isset( $conn['tenantType'] ) ? $conn['tenantType'] : 'ORGANISATION' ) ) {
				$out[] = array(
					'id'   => $conn['tenantId'],
					'name' => isset( $conn['tenantName'] ) ? $conn['tenantName'] : $conn['tenantId'],
					'uri'  => '',
				);
			}
		}
		return $out;
	}

	/**
	 * Escape a value for use inside a Xero `where` string literal.
	 *
	 * @param string $s Value.
	 * @return string
	 */
	protected function where_str( $s ) {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $s );
	}

	/**
	 * Resolve the display name to use for the customer/contact.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	protected function contact_name( $order ) {
		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( $order->get_billing_company() ) {
			$name = $order->get_billing_company();
		}
		return '' === $name ? 'Web Customer' : $name;
	}

	/**
	 * Find or create a contact by name. Returns the ContactID.
	 *
	 * @param WC_Order $order Order.
	 * @return string|WP_Error
	 */
	public function upsert_customer( $order ) {
		$name = $this->contact_name( $order );

		$contact = array(
			'Name'         => $name,
			'FirstName'    => $order->get_billing_first_name(),
			'LastName'     => $order->get_billing_last_name(),
			'EmailAddress' => $order->get_billing_email(),
			'Addresses'    => $this->contact_addresses( $order ),
		);

		$phone = $order->get_billing_phone();
		if ( $phone ) {
			$contact['Phones'] = array(
				array(
					'PhoneType'   => 'DEFAULT',
					'PhoneNumber' => $phone,
				),
			);
		}

		// If the contact already exists, update it in place by ContactID so details
		// like the address are filled in / kept current rather than left as they were.
		$where = 'Name=="' . $this->where_str( $name ) . '"';
		$found = $this->get( $this->api_url( '/Contacts' ), array( 'where' => $where ) );
		if ( ! is_wp_error( $found ) && ! empty( $found['Contacts'][0]['ContactID'] ) ) {
			$contact['ContactID'] = $found['Contacts'][0]['ContactID'];
		}

		$obj = $this->post( $this->api_url( '/Contacts' ), array( 'Contacts' => array( $contact ) ) );
		if ( is_wp_error( $obj ) ) {
			return $obj;
		}
		if ( ! empty( $obj['Contacts'][0]['ContactID'] ) ) {
			return $obj['Contacts'][0]['ContactID'];
		}
		return new WP_Error( 'dwtx_customer_uid', __( 'Created the Xero contact but could not resolve its ContactID.', 'demfilz-sync-for-xero' ) );
	}

	/**
	 * Build the contact's Xero addresses from the order.
	 *
	 * Xero prints the POBOX (postal) address on sales invoices, so the delivery
	 * (shipping) address goes there to match how the order should be shipped,
	 * falling back to the billing address when the order has no separate shipping
	 * address. The billing address is kept as the STREET address.
	 *
	 * @param WC_Order $order Order.
	 * @return array[]
	 */
	protected function contact_addresses( $order ) {
		$billing = array(
			'AddressLine1' => $order->get_billing_address_1(),
			'AddressLine2' => $order->get_billing_address_2(),
			'City'         => $order->get_billing_city(),
			'Region'       => $order->get_billing_state(),
			'PostalCode'   => $order->get_billing_postcode(),
			'Country'      => $this->country_name( $order->get_billing_country() ),
		);

		$has_shipping = ( '' !== $order->get_shipping_address_1() || '' !== $order->get_shipping_city() );
		$delivery     = $has_shipping ? array(
			'AddressLine1' => $order->get_shipping_address_1(),
			'AddressLine2' => $order->get_shipping_address_2(),
			'City'         => $order->get_shipping_city(),
			'Region'       => $order->get_shipping_state(),
			'PostalCode'   => $order->get_shipping_postcode(),
			'Country'      => $this->country_name( $order->get_shipping_country() ),
		) : $billing;

		$delivery['AddressType'] = 'POBOX';
		$billing['AddressType']  = 'STREET';

		return array( $delivery, $billing );
	}

	/**
	 * Expand a WooCommerce 2-letter country code to its full country name for Xero.
	 *
	 * Xero's contact Country field is free text, and a full name ("United States")
	 * reads better than the ISO code ("US"). WooCommerce labels some countries as
	 * "United States (US)", so the trailing code in brackets is removed.
	 *
	 * @param string $code ISO 3166-1 alpha-2 country code.
	 * @return string Full country name, or the original code if it cannot be resolved.
	 */
	protected function country_name( $code ) {
		$code = (string) $code;
		if ( '' === $code ) {
			return '';
		}
		$name = $code;
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			if ( isset( $countries[ $code ] ) ) {
				$name = trim( preg_replace( '/\s*\([^)]*\)\s*$/', '', $countries[ $code ] ) );
			}
		}
		/**
		 * Filter the country name sent to Xero (default: the full name). Return the
		 * code to send the ISO code instead.
		 *
		 * @param string $name Resolved country name.
		 * @param string $code Original ISO country code.
		 */
		return (string) apply_filters( 'dwtx_country_name', $name, $code );
	}

	/**
	 * Build invoice/quote line items (tax-exclusive amounts; Xero computes the GST).
	 *
	 * @param WC_Order $order Order.
	 * @return array
	 */
	protected function line_items( $order ) {
		$lines     = array();
		$tax_map   = new Dwtx_Tax_Mapper();
		$inc_acct  = Dwtx_Settings::get( 'default_income_acct' );
		$ship_acct = Dwtx_Settings::get( 'freight_income_acct' );
		$ship_acct = '' !== $ship_acct ? $ship_acct : $inc_acct;
		$gift_acct = Dwtx_Settings::get( 'gift_card_acct' );
		$gift_tax  = Dwtx_Settings::get( 'gift_card_tax_code' );

		foreach ( $order->get_items() as $item ) {
			$qty   = max( 1, (float) $item->get_quantity() );
			$total = round( (float) $order->get_line_total( $item, false ), 2 );
			$line  = array(
				'Description' => $item->get_name(),
				'Quantity'    => $qty,
				'UnitAmount'  => round( $total / $qty, 2 ),
			);

			// Send the product SKU as the Xero ItemCode so the line links to the matching
			// Xero item (the same Code used for stock sync). Filterable: return '' to omit
			// it, e.g. when SKUs are not set up as items in Xero.
			$product   = $item->get_product();
			$item_code = $product ? (string) $product->get_sku() : '';
			$item_code = (string) apply_filters( 'dwtx_line_item_code', $item_code, $item, $order );
			if ( '' !== $item_code ) {
				$line['ItemCode'] = $item_code;
			}

			// A gift card being sold is money held for the customer: it posts to the gift card
			// liability account at the value of the cards, not to income.
			$gift_sale = Dwtx_Gift_Cards::is_gift_card_sale( $item );
			$face      = $gift_sale ? Dwtx_Gift_Cards::face_value( $item, $total ) : $total;
			if ( $gift_sale ) {
				$line['UnitAmount'] = round( $face / $qty, 2 );
			}
			$acct = $gift_sale ? $gift_acct : $inc_acct;
			if ( '' !== $acct ) {
				$line['AccountCode'] = $acct;
			}
			$tax = $gift_sale ? $gift_tax : $tax_map->code_uid_for_item( $order, $item );
			if ( '' !== $tax ) {
				$line['TaxType'] = $tax;
				$this->apply_line_tax( $line, (float) $item->get_total_tax() );
			}
			$lines[] = $line;

			// A card sold below its value (on sale or with a coupon): the difference is a discount.
			if ( $face > $total ) {
				$line = array(
					/* translators: %s: product name. */
					'Description' => sprintf( __( 'Discount on %s', 'demfilz-sync-for-xero' ), $item->get_name() ),
					'Quantity'    => 1,
					'UnitAmount'  => -1 * round( $face - $total, 2 ),
					'TaxAmount'   => 0,
				);
				if ( '' !== $inc_acct ) {
					$line['AccountCode'] = $inc_acct;
				}
				if ( '' !== $gift_tax ) {
					$line['TaxType'] = $gift_tax;
				}
				$lines[] = $line;
			}
		}

		if ( (float) $order->get_shipping_total() > 0 ) {
			$line = array(
				'Description' => __( 'Shipping', 'demfilz-sync-for-xero' ),
				'Quantity'    => 1,
				'UnitAmount'  => round( (float) $order->get_shipping_total(), 2 ),
			);
			if ( '' !== $ship_acct ) {
				$line['AccountCode'] = $ship_acct;
			}
			$freight = $tax_map->freight_code_uid();
			if ( '' !== $freight ) {
				$line['TaxType'] = $freight;
				$this->apply_line_tax( $line, (float) $order->get_shipping_tax() );
			}
			$lines[] = $line;
		}

		// Fees / surcharges (e.g. a tariff added as a WooCommerce fee).
		foreach ( $order->get_fees() as $fee ) {
			$amount = round( (float) $fee->get_total(), 2 );
			if ( 0.0 === $amount ) {
				continue;
			}
			$line = array(
				'Description' => $fee->get_name() ? $fee->get_name() : __( 'Fee', 'demfilz-sync-for-xero' ),
				'Quantity'    => 1,
				'UnitAmount'  => $amount,
			);
			if ( '' !== $inc_acct ) {
				$line['AccountCode'] = $inc_acct;
			}
			$fee_tax_type = Dwtx_Settings::get( 'default_tax_code' );
			if ( '' !== $fee_tax_type ) {
				$line['TaxType'] = $fee_tax_type;
				$this->apply_line_tax( $line, (float) $fee->get_total_tax() );
			}
			$lines[] = $line;
		}

		// Amounts paid by gift card or store credit: a payment out of the gift card liability
		// account, so the sale keeps its full revenue and tax and the invoice total matches the
		// order total. TaxAmount 0 keeps the line out of GST whatever the account's default rate.
		foreach ( Dwtx_Gift_Cards::payments( $order ) as $payment ) {
			$line = array(
				'Description' => $payment['label'],
				'Quantity'    => 1,
				'UnitAmount'  => -1 * round( (float) $payment['amount'], 2 ),
				'TaxAmount'   => 0,
			);
			if ( '' !== $gift_acct ) {
				$line['AccountCode'] = $gift_acct;
			}
			if ( '' !== $gift_tax ) {
				$line['TaxType'] = $gift_tax;
			}
			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Stop a sync that would post gift card money as income because no gift card account is mapped.
	 *
	 * @param WC_Order $order Order.
	 * @return true|WP_Error
	 */
	protected function check_gift_card_mapping( $order ) {
		if ( '' === (string) Dwtx_Settings::get( 'gift_card_acct' ) && Dwtx_Gift_Cards::order_uses_gift_cards( $order ) ) {
			return new WP_Error( 'dwtx_no_gift_card_acct', __( 'This order sells a gift card or was paid partly by gift card or store credit. Choose a gift card account (a liability account) on the Mapping tab, then sync the order again.', 'demfilz-sync-for-xero' ) );
		}
		return true;
	}

	/**
	 * Carry the WooCommerce tax charged on a line through to Xero as an explicit
	 * tax amount.
	 *
	 * This preserves sales tax / tariff that the store collected even when the
	 * line's Xero tax rate computes no tax of its own (for example "BAS Excluded"
	 * on a store that is not registered for GST). Xero records the difference as
	 * an adjustment to tax and includes it in the invoice total. Nothing is added
	 * when no tax was charged, so standard GST lines are unaffected.
	 *
	 * @param array $line Line item passed by reference.
	 * @param float $tax  WooCommerce tax charged on the line.
	 * @return void
	 */
	protected function apply_line_tax( array &$line, $tax ) {
		$tax = round( (float) $tax, 2 );
		if ( $tax > 0 ) {
			$line['TaxAmount'] = $tax;
		}
	}

	/**
	 * Create or update a sale (Invoice or Quote) for the order.
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $customer_uid ContactID.
	 * @param string   $existing_uid Existing sale id.
	 * @return string|WP_Error
	 */
	public function upsert_sale( $order, $customer_uid, $existing_uid = '' ) {
		$sale_type = Dwtx_Settings::get( 'sale_type', 'Invoice' );
		$ts        = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		$date      = gmdate( 'Y-m-d', $ts );

		$mapped = $this->check_gift_card_mapping( $order );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}

		if ( 'Quote' === $sale_type ) {
			return $this->upsert_quote( $order, $customer_uid, $existing_uid, $date );
		}

		$status = Dwtx_Settings::get( 'invoice_status', 'DRAFT' );
		$status = in_array( $status, array( 'DRAFT', 'SUBMITTED', 'AUTHORISED' ), true ) ? $status : 'DRAFT';

		// Xero requires a DueDate once an invoice is Submitted or Authorised. Default it to the
		// invoice date; filter dwtx_invoice_due_days to add payment terms (in days).
		$due_days = max( 0, (int) apply_filters( 'dwtx_invoice_due_days', 0, $order ) );
		$due_date = gmdate( 'Y-m-d', $ts + ( $due_days * DAY_IN_SECONDS ) );

		$invoice = array(
			'Type'            => 'ACCREC',
			'Contact'         => array( 'ContactID' => $customer_uid ),
			'Date'            => $date,
			'DueDate'         => $due_date,
			'CurrencyCode'    => $order->get_currency(),
			'InvoiceNumber'   => (string) $order->get_order_number(),
			'Reference'       => sprintf(
				/* translators: %s: WooCommerce order number. */
				__( 'WooCommerce order #%s', 'demfilz-sync-for-xero' ),
				$order->get_order_number()
			),
			'Status'          => $status,
			'LineAmountTypes' => 'Exclusive',
			'LineItems'       => $this->line_items( $order ),
		);

		if ( $existing_uid ) {
			$invoice['InvoiceID'] = $existing_uid;
		}

		$obj = $this->post( $this->api_url( '/Invoices' ), array( 'Invoices' => array( $invoice ) ) );
		if ( is_wp_error( $obj ) ) {
			return $obj;
		}
		if ( ! empty( $obj['Invoices'][0]['InvoiceID'] ) ) {
			return $obj['Invoices'][0]['InvoiceID'];
		}
		return new WP_Error( 'dwtx_sale_uid', __( 'Created the Xero invoice but could not resolve its InvoiceID.', 'demfilz-sync-for-xero' ) );
	}

	/**
	 * Create or update a Quote for the order.
	 *
	 * @param WC_Order $order        Order.
	 * @param string   $customer_uid ContactID.
	 * @param string   $existing_uid Existing QuoteID.
	 * @param string   $date         ISO date.
	 * @return string|WP_Error
	 */
	protected function upsert_quote( $order, $customer_uid, $existing_uid, $date ) {
		$quote = array(
			'Contact'         => array( 'ContactID' => $customer_uid ),
			'Date'            => $date,
			'CurrencyCode'    => $order->get_currency(),
			'Reference'       => sprintf(
				/* translators: %s: WooCommerce order number. */
				__( 'WooCommerce order #%s', 'demfilz-sync-for-xero' ),
				$order->get_order_number()
			),
			'Status'          => 'DRAFT',
			'LineAmountTypes' => 'Exclusive',
			'LineItems'       => $this->line_items( $order ),
		);

		if ( $existing_uid ) {
			$quote['QuoteID'] = $existing_uid;
		}

		$obj = $this->post( $this->api_url( '/Quotes' ), array( 'Quotes' => array( $quote ) ) );
		if ( is_wp_error( $obj ) ) {
			return $obj;
		}
		if ( ! empty( $obj['Quotes'][0]['QuoteID'] ) ) {
			return $obj['Quotes'][0]['QuoteID'];
		}
		return new WP_Error( 'dwtx_sale_uid', __( 'Created the Xero quote but could not resolve its QuoteID.', 'demfilz-sync-for-xero' ) );
	}

	/**
	 * Stock on hand by SKU (Item Code == SKU; only tracked items have QuantityOnHand).
	 *
	 * @param string $sku SKU.
	 * @return float|WP_Error
	 */
	public function get_stock_by_sku( $sku ) {
		$where = 'Code=="' . $this->where_str( $sku ) . '"';
		$data  = $this->get( $this->api_url( '/Items' ), array( 'where' => $where ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['Items'][0] ) ) {
			return new WP_Error( 'dwtx_no_item', __( 'No matching Xero item for that SKU.', 'demfilz-sync-for-xero' ) );
		}
		return (float) ( isset( $data['Items'][0]['QuantityOnHand'] ) ? $data['Items'][0]['QuantityOnHand'] : 0 );
	}

	/**
	 * Stock on hand for every tracked item, in one call, keyed by Item Code (SKU).
	 *
	 * Only tracked (inventory) items carry QuantityOnHand, so untracked items are
	 * skipped. Using one /Items call for the whole catalogue keeps a full stock
	 * sync to a single Xero request instead of one per product.
	 *
	 * @return array|WP_Error Map of Code => quantity on hand.
	 */
	public function get_stock_map() {
		$data = $this->get( $this->api_url( '/Items' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$map = array();
		foreach ( (array) ( isset( $data['Items'] ) ? $data['Items'] : array() ) as $item ) {
			if ( empty( $item['Code'] ) || ! isset( $item['QuantityOnHand'] ) ) {
				continue;
			}
			$map[ (string) $item['Code'] ] = (float) $item['QuantityOnHand'];
		}
		return $map;
	}

	/**
	 * List tax rates (TaxType => "Name — rate%"). The TaxType is what goes on lines.
	 *
	 * @return array|WP_Error
	 */
	public function list_tax_codes() {
		$data = $this->get( $this->api_url( '/TaxRates' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( (array) ( isset( $data['TaxRates'] ) ? $data['TaxRates'] : array() ) as $t ) {
			if ( empty( $t['TaxType'] ) ) {
				continue;
			}
			if ( isset( $t['Status'] ) && 'ACTIVE' !== $t['Status'] ) {
				continue;
			}
			$rate                 = isset( $t['EffectiveRate'] ) ? rtrim( rtrim( (string) $t['EffectiveRate'], '0' ), '.' ) : '';
			$label                = isset( $t['Name'] ) ? $t['Name'] : $t['TaxType'];
			$out[ $t['TaxType'] ] = $label . ( '' !== $rate ? ' — ' . $rate . '%' : '' );
		}
		return $out;
	}

	/**
	 * List revenue accounts (Code => "Code Name"). The Code is what goes on lines.
	 *
	 * @return array|WP_Error
	 */
	public function list_accounts() {
		return $this->list_accounts_of_class( 'REVENUE' );
	}

	/**
	 * List liability accounts (Code => "Code Name"), for the gift card account.
	 *
	 * @return array|WP_Error
	 */
	public function list_liability_accounts() {
		return $this->list_accounts_of_class( 'LIABILITY' );
	}

	/**
	 * List the accounts of one class (Code => "Code Name").
	 *
	 * @param string $account_class Xero account class, e.g. REVENUE or LIABILITY.
	 * @return array|WP_Error
	 */
	protected function list_accounts_of_class( $account_class ) {
		$data = $this->get( $this->api_url( '/Accounts' ), array( 'where' => 'Class=="' . $this->where_str( $account_class ) . '"' ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$out = array();
		foreach ( (array) ( isset( $data['Accounts'] ) ? $data['Accounts'] : array() ) as $a ) {
			if ( empty( $a['Code'] ) ) {
				continue;
			}
			$label             = $a['Code'] . ( isset( $a['Name'] ) ? ' ' . $a['Name'] : '' );
			$out[ $a['Code'] ] = trim( $label );
		}
		return $out;
	}
}
