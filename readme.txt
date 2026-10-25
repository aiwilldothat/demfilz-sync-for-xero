=== Demfilz Sync for Xero ===
Contributors: demfilz
Tags: woocommerce, xero, accounting, invoices, inventory
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.2.9
Requires Plugins: woocommerce
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Send WooCommerce orders, customers and stock to Xero via the official Xero Accounting API. Free manual sync; Pro adds automation.

== Description ==

Demfilz Sync for Xero connects your WooCommerce store to your Xero organisation so you stop re-keying sales. It posts orders as Xero invoices (or quotes), matches or creates the contact, and can read stock-on-hand back from Xero.

**Free features**

* One-click OAuth 2.0 connection to your Xero organisation
* Push a WooCommerce order to Xero as an invoice or quote with a button on the order screen
* Create or match the contact in Xero automatically
* Map a default income account and GST tax rates
* Pull current stock-on-hand from Xero for a product (button on the product screen)
* Sync log of every attempt

**Pro features (A$19.95/month)**

* Automatic order sync when an order reaches a chosen status
* Bulk / historical order backfill
* Scheduled inventory sync across all products
* Refunds / credit notes to Xero
* Per-product / per-tax-class tax mapping
* Auto-retry of failed syncs with email alerts
* Multiple stores / organisations
* Supplier purchases: when the Demfilz Auto Order plugin emails a supplier a purchase order, post a matching bill (at cost) to Xero

Pro is delivered as a separate add-on plugin after subscribing at https://demfilz.com/woo-to-xero

== External services ==

This plugin connects to external services. It sends data only when you explicitly connect and sync.

1. **Xero API** (https://login.xero.com, https://identity.xero.com and https://api.xero.com)
   * Purpose: authenticate (OAuth 2.0) and read/write your accounting data.
   * Data sent: your store's order details (customer name, billing address, email, phone, line items, totals, tax), product SKUs, and your Xero developer-app credentials/tokens.
   * When: when you connect, push an order, or pull stock.
   * Terms: https://www.xero.com/au/legal/terms — Privacy: https://www.xero.com/au/legal/privacy

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. Go to **WooCommerce → Xero Sync**.
3. Create a "Web app" in the Xero developer portal and paste the Client ID and Client Secret, adding the redirect URI shown on the Connection tab.
4. Click **Save & Connect to Xero**, approve access, then choose your organisation.
5. Set your default income account and tax rates on the Mapping tab.
6. Open any order and click **Sync to Xero**.

== Frequently Asked Questions ==

= Does it post orders as invoices or quotes? =
Either. Choose how to post orders on the Mapping tab, and pick the invoice status (Draft, Submitted or Authorised).

= Is the free version time-limited? =
No. Manual order, contact and stock sync are free forever. Pro adds automation and scale.

= Where is my data stored? =
OAuth tokens are stored encrypted in your WordPress database. Order data is only sent to Xero when you sync.

= Does it handle gift cards and store credit? =
Yes, with Demfilz Gift Cards and Store Credit for WooCommerce. A gift card is a payment, not a discount, so on the Mapping tab choose a gift card account (a liability account such as "Gift card liability") and a tax rate with no tax, such as BAS Excluded. Gift cards sold post to that account instead of income. When an order is paid partly by gift card or store credit, the invoice keeps every product and shipping line with its GST, and the amount paid by card is a negative line against the gift card account, so the invoice total matches what the customer paid at checkout. Until the account is chosen, orders that use gift cards are not synced and the log says why, so nothing is posted to income by mistake.

== Screenshots ==

1. The Connection tab — connect to your Xero organisation with OAuth 2.0 and pick the organisation.
2. Mapping — post orders as an invoice or quote and pick your income account and GST tax rates.
3. The Xero Sync box on an order, with the one-click Sync to Xero button.
4. The sync log of every push.

== Changelog ==

= 1.2.9 =
* New: gift card and store credit support for Demfilz Gift Cards and Store Credit for WooCommerce. Gift cards sold post to a gift card liability account instead of income, and amounts paid by gift card or store credit post as a negative line against that account, so revenue and GST stay complete and the invoice total matches the order total. Choose the account on the Mapping tab. Developers can report another gift card plugin's payments with the dwtx_gift_card_payments filter.
* A gift card sold below its value (on sale or with a coupon) posts its full value to the gift card account and the difference as a discount on income, so the account always holds what customers can spend.

= 1.2.8 =
* Stock sync now reads the whole stock-on-hand list from Xero in a single request and matches products locally, instead of one request per product. This keeps a full catalogue sync (and the Pro scheduled sync) well within Xero's API rate limits. The "Sync all stock" button uses this too and is much faster.

= 1.2.7 =
* The Mapping tab now explains why the account and tax-rate dropdowns are empty (for example a Xero rate limit, or a connection that needs refreshing) instead of just showing no options.

= 1.2.6 =
* The customer's country is now sent to Xero as its full name (e.g. "United States") instead of the two-letter code. Filter dwtx_country_name to override.

= 1.2.5 =
* Improved handling of Xero's rate limit (HTTP 429): the sync now waits for the period Xero asks for and retries, and shows a clear "please wait and try again" message instead of a raw error.
* The "Sync all stock from Xero now" run is now paced so it stays under Xero's 60-calls-per-minute limit.

= 1.2.4 =
* New: the product SKU is now sent as the Xero line item code, so invoice lines link to the matching Xero item.
* New: a separate Shipping account on the Mapping tab, so shipping can post to its own Xero account (e.g. 210) instead of the income account.
* New: the delivery (shipping) address is now sent to the Xero contact as well as the billing address, and existing contacts are updated so their address is filled in.
* Fix: sales tax collected on an order now carries through to Xero as a tax amount on each line, so tax (or a tariff) is preserved and included in the invoice total even on a no-GST tax rate such as "BAS Excluded". Standard GST lines are unaffected.
* WooCommerce fee/surcharge lines are now included on the Xero invoice.

= 1.2.3 =
* New: a "Sync all stock from Xero now" button on the Sync tab pulls stock-on-hand for every published product that has a SKU, in one pass, matched to Xero by SKU. Runs on demand in small batches, so it works even if scheduled tasks are not firing on your host.

= 1.2.2 =
* Fix: invoices and quotes now post in the same currency as the WooCommerce order, rather than always using your Xero organisation's base currency. Multi-currency must be enabled in Xero for any currency other than your base currency.

= 1.2.1 =
* Fix: invoices now always include a due date, so posting an order as a Submitted or Authorised invoice no longer fails with "The document DueDate field must be specified". The due date defaults to the invoice date, and can be adjusted with the dwtx_invoice_due_days filter for payment terms.

= 1.2.0 =
* Compliance: the free plugin no longer contains any premium or licensing code. All Pro gating, licence handling and supplier-bill sync now live entirely in the separate Pro add-on.
* The free plugin exposes small, generic Xero API helpers (get/post) that the Pro add-on builds on; no Pro-only endpoints ship in the free plugin.
* No functional change for free users.

= 1.1.0 =
* Pro: post a Xero bill (at cost) when the Demfilz Auto Order plugin emails a supplier purchase order.
* Added Xero supplier, bill (ACCPAY) and expense-account API support (used by Pro).
* Fix: "Save & Connect to Xero" now correctly redirects to the Xero consent screen.
* Pro subscriptions are now validated through the Demfilz billing service, and "Get Pro" links to live checkout.

= 1.0.0 =
* Initial release: OAuth 2.0 connection, manual order push (invoice or quote), contact sync, manual stock pull, mapping, logs, Pro licence framework.
