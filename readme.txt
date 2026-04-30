=== Dchamplegacy Standalone Payments for PesaPal ===
Contributors: biggerbenson, dchamp-legacy
Tags: payments, gateway, shortcode, ipn, uganda
Requires at least: 5.0
Tested up to: 6.9.4
Stable tag: 1.4.11
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept PesaPal payments in WordPress without WooCommerce: hosted checkout, IPN registration, status callbacks, and admin transaction logs.

== Description ==

This plugin lets WordPress sites accept payments through the PesaPal payment platform without WooCommerce. It is an independent integration and is not affiliated with, endorsed by, or sponsored by PesaPal Ltd.

Features:

* Standalone gateway integration
* Shortcode payment form
* IPN registration
* Transaction logs in admin
* Sandbox / Live mode
* Success / Failed redirect pages
* Works without WooCommerce

Recommended setup:

* Install directly from WordPress.org (Plugins -> Add New -> search for "Dchamplegacy Standalone Payments for PesaPal") for automatic update checks.
* Keep the plugin in the default WordPress.org slug path:
  `dchamplegacy-standalone-pesapal/dchamplegacy-standalone-pesapal.php`
  so WordPress can match and apply updates automatically.

Shortcode:

[dcslps_payment_form]

You must enter your PesaPal Consumer Key and Secret in settings (credentials are yours; this plugin is third-party software).

Plugin URL:
https://dchamplegacy.com/plugins/pesapal-standalone/

Author:
https://dchamplegacy.com/

== External services ==

This plugin connects to the PesaPal payment API (operated by PesaPal Limited) so your site can obtain access tokens, register an Instant Payment Notification (IPN) URL, create payment orders, retrieve transaction status, and send payers to PesaPal-hosted checkout to complete payment. This plugin is not affiliated with PesaPal; use of their service is subject to their policies.

Where requests are sent:

* Live mode: https://pay.pesapal.com (API paths such as /api/Auth/RequestToken, /api/URLSetup/RegisterIPN, /api/Transactions/SubmitOrderRequest, /api/Transactions/GetTransactionStatus).
* Sandbox / demo mode: https://cybqa.pesapal.com (same API path pattern).

What data is sent and when:

* RequestToken (POST, JSON): Whenever the plugin needs an API access token—for example when a payer submits the payment form, when an administrator registers the IPN from WordPress, when the payer returns via the plugin callback URL, or when handling an IPN and checking status. The request includes the Consumer Key and Consumer Secret you configure in the plugin (merchant API credentials from PesaPal).
* RegisterIPN (POST, JSON): When you register the IPN from the WordPress admin. Sends your site's IPN URL on your WordPress domain and the notification delivery type (POST) so PesaPal can call that URL for payment events.
* SubmitOrderRequest (POST, JSON): When a payer successfully initiates a payment from the shortcode form. Sends order fields including merchant reference, currency, amount, description, your site's callback_url and cancellation_url, the notification_id returned when the IPN was registered, and billing_address with email_address, first_name, and last_name from the form. The payer is then redirected to PesaPal; any further data collected at checkout is processed under PesaPal's own terms and privacy policy.
* GetTransactionStatus (GET): When confirming a transaction after callback or during IPN handling. Sends orderTrackingId and optionally merchantReference as URL query parameters, with a Bearer access token in the Authorization header.

Links (verify before each release):

* PesaPal terms and conditions: https://www.pesapal.com/terms-and-conditions
* PesaPal privacy policy: https://www.pesapal.com/privacy-policy

== Installation ==

1. Install from WordPress admin:
   Plugins -> Add New -> search for "Dchamplegacy Standalone Payments for PesaPal" -> Install -> Activate.
2. Open PesaPal -> Settings (screen title: Dchamp Legacy Standalone PesaPal Payments).
3. Enter your PesaPal Consumer Key and Consumer Secret.
4. Choose mode (Sandbox for testing, Live for production).
5. Click IPN Register to register the generated IPN URL with PesaPal.
6. Add shortcode [dcslps_payment_form] to a page/post where you want to collect payments.
7. Complete one sandbox test payment and confirm it appears under PesaPal -> Transactions.

Manual ZIP installation (advanced):

* If you install manually, keep the plugin folder as `dchamplegacy-standalone-pesapal`.
* If the folder/file slug differs, WordPress.org auto-updates may not be detected.

== Screenshots ==

1. Plugin settings page (API credentials, mode, notification ID, and IPN register button).
2. Frontend shortcode payment form.
3. Admin transactions list with status tracking.

== Frequently Asked Questions ==

= Does this require WooCommerce? =
No. This plugin works standalone.

= Is this an official PesaPal plugin? =
No. It is developed by Dchamp Legacy / Dchamplegacy. PesaPal is a trademark of its respective owner.

= Does it support sandbox? =
Yes.

= I used the old [pesapal_payment_form] shortcode; what now? =
That tag is no longer registered (WordPress.org prefix rules). Replace it with [dcslps_payment_form] on every page or widget.

= Can I export transactions? =
Transactions are listed under PesaPal → Transactions in the admin menu. This release does not ship a CSV export in the plugin code.

= How do I make sure automatic updates work from WordPress.org? =
Install from WordPress.org and keep the default plugin slug:
`dchamplegacy-standalone-pesapal/dchamplegacy-standalone-pesapal.php`.

= Which shortcode should I use? =
Use [dcslps_payment_form]. The old [pesapal_payment_form] shortcode is no longer registered.

== Changelog ==

= 1.4.11 =
* Improved readme tabs (Description, Installation, Screenshots, FAQ) for clearer onboarding.
* Added guidance for reliable WordPress.org auto-updates (correct plugin slug/path).
* Plugin now shows an admin warning if installed under a non-standard folder/file name that can block wp.org updates.

= 1.4.10 =
* Readme: added `External services` section documenting PesaPal API usage, data sent, endpoints, and links to PesaPal terms and privacy policy (WordPress.org guideline).

= 1.4.9 =
* Removed hidden `languages/.gitkeep` (automated scan rejects dotfiles). Added `languages/index.php` placeholder so the Domain Path folder remains valid.

= 1.4.8 =
* WordPress HTTP API only (no cURL in plugin code); class docblock states this for reviewers.
* register_setting: dedicated option group, type array, explicit sanitize_callback and defaults.
* Consumer Secret sanitized with trim/wp_unslash only (not sanitize_text_field).
* Removed unprefixed [pesapal_payment_form] shortcode; use [dcslps_payment_form] only.

= 1.4.7 =
* Plugin Check: WordPress HTTP API instead of cURL; wp_dropdown_pages output passed through wp_kses; translators comment; languages folder; readme tag/length fixes; nonce PHPCS exceptions for gateway callbacks; DDL/query PHPCS notes; removed redundant load_plugin_textdomain for wordpress.org.

= 1.4.6 =
* Renamed plugin for WordPress.org trademark guidelines (distinctive name; PesaPal referenced with clear non-affiliation).
* Text domain: dchamplegacy-standalone-pesapal
* Prefixed options, REST routes, database table, menu slugs, and CSS classes (dcslps_ / DCSLPS_).
* Enqueued admin and front-end scripts/styles per WordPress standards (no raw script/style tags in reviewed locations).
* Migration from previous option keys and transaction table for existing installs.
* Legacy REST namespace psp/v1 and callback query vars kept for backward compatibility where safe.

= 1.4.5 =
* Prior release under the previous plugin name.

== Upgrade Notice ==

= 1.4.11 =
Improved onboarding docs and added an in-plugin notice when installation path may prevent WordPress.org auto-updates.

= 1.4.10 =
Documentation only: external services disclosure in readme.

= 1.4.9 =
Packaging fix for wordpress.org automated scan (no hidden files under languages/).

= 1.4.8 =
Review: HTTP API, register_setting/sanitization, prefixed shortcode only. Replace [pesapal_payment_form] if you still use it.

= 1.4.7 =
Plugin Check and coding-standards alignment (HTTP API, i18n, readme).

= 1.4.6 =
WordPress.org review compliance: new plugin name and slug reservation required. Re-register your IPN URL if you use the new callback/IPN query parameters.
