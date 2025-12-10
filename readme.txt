
=== PesaPal Standalone ===
Contributors: dchamp-legacy, developer
Tags: payments, pesapal, payments-gateway, shortcode, ipn
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A standalone PesaPal payment integration plugin for WordPress — works without WooCommerce.
Includes shortcode [pesapal_payment_form] for embedding a payment form, IPN registration, admin transactions list, CSV export, and popup checkout flow.

== Description ==
This plugin provides a simple standalone integration with PesaPal (no WooCommerce dependency).
Use the settings page to enter Consumer Key/Secret and register IPN. Insert the payment form with the shortcode:
  [pesapal_payment_form]

== Installation ==
1. Upload the 'pesapal-standalone' folder to the '/wp-content/plugins/' directory.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Go to PesaPal Standalone → Settings and enter your Consumer Key and Consumer Secret, select mode (sandbox/live), and register IPN.
4. Create or select pages for Payment Success and Payment Failed in the settings.
5. Use the shortcode on any page to display the payment form.

== Changelog ==
= 1.0.0 =
* Initial packaged release.

== Frequently Asked Questions ==
= Where do I put Payments.png? =
The package includes an assets/ folder with Payments.png and logo; they are used by the plugin. You may replace them with your own images.
