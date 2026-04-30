# Dchamplegacy Standalone Payments for PesaPal

Accept PesaPal payments in WordPress without WooCommerce: hosted checkout, IPN registration, callback/status handling, and admin transaction logs.

## Plugin Links

- WordPress.org URL: https://wordpress.org/plugins/dchamplegacy-standalone-pesapal/
- Author website plugin URL: https://dchamplegacy.com/plugins/pesapal-standalone/

## Key Features

- Standalone gateway integration (no WooCommerce required)
- Shortcode payment form: `[dcslps_payment_form]`
- IPN registration from the admin settings screen
- Sandbox and Live mode support
- Success/failed callback handling
- Admin transaction logs

## Installation (Recommended)

1. In WordPress admin, go to `Plugins -> Add New`.
2. Search for `Dchamplegacy Standalone Payments for PesaPal`.
3. Install and activate the plugin.
4. Open `PesaPal -> Settings`.
5. Enter your Consumer Key and Consumer Secret.
6. Choose mode (`Sandbox` for testing, `Live` for production).
7. Click `IPN Register`.
8. Add `[dcslps_payment_form]` to a page/post.

## Automatic Updates

For reliable WordPress.org updates, keep the plugin installed with this canonical basename:

`dchamplegacy-standalone-pesapal/dchamplegacy-standalone-pesapal.php`

If a custom ZIP install changes folder/file naming, update detection can fail.
