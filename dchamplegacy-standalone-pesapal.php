<?php
/**
 * Plugin Name:       Dchamplegacy Standalone Payments for PesaPal
 * Plugin URI:        https://dchamplegacy.com/pesapal-standalone
 * Description:       Standalone payment integration with the PesaPal API (not affiliated with PesaPal). No WooCommerce required. Admin UI, IPN registration, payment shortcode, and transaction logs.
 * Version:           1.4.10
 * Author:            Dchamp Legacy
 * Author URI:        https://dchamplegacy.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dchamplegacy-standalone-pesapal
 * Domain Path:       /languages
 *
 * @package Dchamplegacy_Standalone_Pesapal
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DCSLPS_VERSION', '1.4.10' );
define( 'DCSLPS_PLUGIN_FILE', __FILE__ );

/**
 * PesaPal API helper (robust endpoints). Not affiliated with PesaPal Ltd.
 * Uses the WordPress HTTP API (wp_remote_get / wp_remote_post); no raw cURL in plugin code.
 */
class DCSLPS_Pesapal_Api_Helper {
	private $candidates = array(
		'live'    => array(
			'https://pay.pesapal.com/pesapalv3',
			'https://pay.pesapal.com/v3',
			'https://pay.pesapal.com',
		),
		'sandbox' => array(
			'https://cybqa.pesapal.com/pesapalv3',
			'https://cybqa.pesapal.com/v3',
			'https://cybqa.pesapal.com',
		),
	);

	public $url;
	public $mode;

	public function __construct( $api = 'demo' ) {
		$this->mode = ( 'live' === $api ) ? 'live' : 'sandbox';
		$this->url  = null;
	}

	public function getAccessToken( $consumer_key, $consumer_secret ) {
		$payload  = wp_json_encode( array( 'consumer_key' => $consumer_key, 'consumer_secret' => $consumer_secret ) );
		$attempts = array();

		foreach ( $this->candidates[ $this->mode ] as $base ) {
			$endpoint = rtrim( $base, '/' ) . '/api/Auth/RequestToken';
			$res      = $this->http_request( $endpoint, array( 'Content-Type: application/json', 'Accept: application/json' ), $payload );
			$attempts[] = array(
				'endpoint'  => $endpoint,
				'http_code' => ( $res->http_code ?? null ),
				'raw'       => ( $res->raw ?? wp_json_encode( $res ) ),
			);
			if ( is_wp_error( $res ) ) {
				continue;
			}
			if ( is_object( $res ) && ! empty( $res->token ) ) {
				$this->url = rtrim( $base, '/' );
				update_option( 'dcslps_last_token_response', wp_json_encode( array( 'success_endpoint' => $endpoint, 'response' => $res ), JSON_PRETTY_PRINT ) );
				return $res->token;
			}
		}

		update_option( 'dcslps_last_token_response', wp_json_encode( $attempts, JSON_PRETTY_PRINT ) );
		return new WP_Error( 'auth_failed', 'No valid token response from the payment provider. See dcslps_last_token_response option for details.' );
	}

	public function generateNotificationId( $callback, $access_token ) {
		$bases    = $this->buildBasesList();
		$attempts = array();
		foreach ( $bases as $base ) {
			$endpoint = rtrim( $base, '/' ) . '/api/URLSetup/RegisterIPN';
			$headers  = array( 'accept: application/json', 'content-type: application/json', 'authorization: Bearer ' . $access_token );
			$body     = wp_json_encode( array( 'url' => $callback, 'ipn_notification_type' => 'POST' ) );
			$res      = $this->http_request( $endpoint, $headers, $body );
			$attempts[] = array(
				'endpoint'  => $endpoint,
				'http_code' => ( $res->http_code ?? null ),
				'raw'       => ( $res->raw ?? wp_json_encode( $res ) ),
			);
			if ( is_wp_error( $res ) ) {
				continue;
			}
			if ( is_object( $res ) ) {
				$this->url = rtrim( $base, '/' );
				update_option( 'dcslps_last_register_attempts', wp_json_encode( $attempts, JSON_PRETTY_PRINT ) );
				$id = $res->ipn_id ?? $res->notification_id ?? $res->notificationId ?? null;
				if ( $id ) {
					return $id;
				}
				return new WP_Error( 'ipn_failed', 'RegisterIPN returned no notification id: ' . wp_json_encode( $res ) );
			}
		}
		update_option( 'dcslps_last_register_attempts', wp_json_encode( $attempts, JSON_PRETTY_PRINT ) );
		return new WP_Error( 'ipn_failed', 'No valid response while registering IPN. See dcslps_last_register_attempts option.' );
	}

	public function submitOrder( $access_token, $payload_assoc ) {
		$bases    = $this->buildBasesList();
		$attempts = array();
		foreach ( $bases as $base ) {
			$endpoint = rtrim( $base, '/' ) . '/api/Transactions/SubmitOrderRequest';
			$headers  = array( 'accept: application/json', 'content-type: application/json', 'authorization: Bearer ' . $access_token );
			$body     = wp_json_encode( $payload_assoc );
			$res      = $this->http_request( $endpoint, $headers, $body );
			$attempts[] = array(
				'endpoint'  => $endpoint,
				'http_code' => ( $res->http_code ?? null ),
				'raw'       => ( $res->raw ?? wp_json_encode( $res ) ),
			);
			if ( is_wp_error( $res ) ) {
				continue;
			}
			if ( isset( $res->http_code ) && intval( $res->http_code ) !== 200 ) {
				continue;
			}
			if ( is_object( $res ) ) {
				$this->url = rtrim( $base, '/' );
				update_option( 'dcslps_last_submit_attempts', wp_json_encode( $attempts, JSON_PRETTY_PRINT ) );
				return $res;
			}
		}
		update_option( 'dcslps_last_submit_attempts', wp_json_encode( $attempts, JSON_PRETTY_PRINT ) );
		return new WP_Error( 'submit_failed', 'No valid response from SubmitOrderRequest. See dcslps_last_submit_attempts option for details.' );
	}

	public function getTransactionStatus( $access_token, $order_tracking_id = '', $merchant_reference = '' ) {
		$bases    = $this->buildBasesList();
		$attempts = array();

		foreach ( $bases as $base ) {
			$qs       = '?orderTrackingId=' . rawurlencode( $order_tracking_id );
			if ( ! empty( $merchant_reference ) ) {
				$qs .= '&merchantReference=' . rawurlencode( $merchant_reference );
			}
			$endpoint = rtrim( $base, '/' ) . '/api/Transactions/GetTransactionStatus' . $qs;
			$headers  = array( 'accept: application/json', 'content-type: application/json', 'authorization: Bearer ' . $access_token );
			$res      = $this->http_request( $endpoint, $headers, null );

			$attempts[] = array(
				'endpoint'  => $endpoint,
				'http_code' => ( $res->http_code ?? null ),
				'raw'       => ( $res->raw ?? wp_json_encode( $res ) ),
			);

			if ( is_wp_error( $res ) ) {
				continue;
			}
			if ( isset( $res->http_code ) && intval( $res->http_code ) !== 200 ) {
				continue;
			}
			if ( is_object( $res ) ) {
				$this->url = rtrim( $base, '/' );
				update_option( 'dcslps_last_status_attempts', wp_json_encode( $attempts, JSON_PRETTY_PRINT ) );
				return $res;
			}
		}

		update_option( 'dcslps_last_status_attempts', wp_json_encode( $attempts, JSON_PRETTY_PRINT ) );
		return new WP_Error( 'status_failed', 'No valid response from GetTransactionStatus. See dcslps_last_status_attempts option for details.' );
	}

	private function buildBasesList() {
		$bases = array();
		if ( ! empty( $this->url ) ) {
			$bases[] = rtrim( $this->url, '/' );
		}
		foreach ( $this->candidates[ $this->mode ] as $c ) {
			if ( ! in_array( $c, $bases, true ) ) {
				$bases[] = $c;
			}
		}
		return $bases;
	}

	/**
	 * HTTP request via WordPress HTTP API (POST when body set, otherwise GET).
	 *
	 * @param string       $endpoint URL.
	 * @param array<int,string> $headers Lines like "Name: value".
	 * @param string|null  $body     JSON body for POST; null/empty for GET.
	 * @return object|WP_Error Decoded JSON object, or object with raw/http_code, or WP_Error.
	 */
	private function http_request( $endpoint, $headers, $body = null ) {
		$args = array(
			'timeout'     => 45,
			'redirection' => 5,
			'sslverify'   => true,
			'headers'     => $this->normalize_request_headers( $headers ),
		);

		$do_post = ( null !== $body && '' !== $body );
		if ( $do_post ) {
			$args['body'] = $body;
			$response     = wp_remote_post( $endpoint, $args );
		} else {
			$response = wp_remote_get( $endpoint, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$raw_body  = wp_remote_retrieve_body( $response );

		$decoded = json_decode( $raw_body );
		if ( is_null( $decoded ) ) {
			return (object) array( 'raw' => $raw_body, 'http_code' => $http_code );
		}
		if ( is_object( $decoded ) ) {
			$decoded->http_code = $http_code;
		}
		return $decoded;
	}

	/**
	 * @param array<int,string> $headers Lines like "Content-Type: application/json".
	 * @return array<string,string>
	 */
	private function normalize_request_headers( $headers ) {
		$out = array();
		foreach ( $headers as $line ) {
			$parts = explode( ':', $line, 2 );
			if ( count( $parts ) === 2 ) {
				$out[ trim( $parts[0] ) ] = trim( $parts[1] );
			}
		}
		return $out;
	}
}

/**
 * Main plugin.
 */
class DCSLPS_Standalone_Plugin {
	private $settings_group = 'dcslps_settings_group';
	private $option_name    = 'dcslps_standalone_options';
	private $table;

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'dcslps_transactions';

		add_action( 'plugins_loaded', array( $this, 'maybe_upgrade_schema' ), 1 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'init', array( $this, 'maybe_handle_callback_or_ipn' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_shortcode( 'dcslps_payment_form', array( $this, 'shortcode_payment_form' ) );
		register_activation_hook( DCSLPS_PLUGIN_FILE, array( $this, 'activate' ) );
	}

	public function admin_menu() {
		add_menu_page(
			__( 'Dchamp Legacy Standalone PesaPal Payments', 'dchamplegacy-standalone-pesapal' ),
			__( 'PesaPal', 'dchamplegacy-standalone-pesapal' ),
			'manage_options',
			'dcslps-standalone',
			array( $this, 'settings_page' ),
			'dashicons-money-alt',
			56
		);
		remove_submenu_page( 'dcslps-standalone', 'dcslps-standalone' );
		add_submenu_page(
			'dcslps-standalone',
			__( 'Dchamp Legacy Standalone PesaPal Payments', 'dchamplegacy-standalone-pesapal' ),
			__( 'Settings', 'dchamplegacy-standalone-pesapal' ),
			'manage_options',
			'dcslps-standalone',
			array( $this, 'settings_page' )
		);
		add_submenu_page(
			'dcslps-standalone',
			__( 'Transactions', 'dchamplegacy-standalone-pesapal' ),
			__( 'Transactions', 'dchamplegacy-standalone-pesapal' ),
			'manage_options',
			'dcslps-transactions',
			array( $this, 'render_transactions_page' )
		);
	}

	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( 'toplevel_page_dcslps-standalone' !== $hook_suffix ) {
			return;
		}
		wp_register_script( 'dcslps-admin-ipn', false, array(), DCSLPS_VERSION, true );
		wp_enqueue_script( 'dcslps-admin-ipn' );
		wp_add_inline_script(
			'dcslps-admin-ipn',
			'(function(){
      var btn = document.getElementById("dcslps-register-ipn-btn");
      var result = document.getElementById("dcslps-register-result");
      if (!btn || !result) return;
      btn.addEventListener("click", function(){
        result.textContent = "Registering...";
        btn.disabled = true;
        fetch(' . wp_json_encode( esc_url_raw( rest_url( 'dcslps/v1/register-ipn' ) ) ) . ', {
          method: "POST",
          credentials: "same-origin",
          headers: { "X-WP-Nonce": ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ', "Content-Type": "application/json" },
          body: JSON.stringify({})
        }).then(function(r){ return r.json(); })
        .then(function(json){
          btn.disabled = false;
          if (json.success) {
            result.innerHTML = "<span style=\"color:green\">Registered: " + (json.data.notification_id || "") + "</span>";
            var input = document.getElementById("dcslps_notification_id_input");
            if (input) input.value = json.data.notification_id || "";
          } else {
            result.innerHTML = "<span style=\"color:#b94a48\">Error: " + (json.data && json.data.message ? json.data.message : "Unknown") + "</span>";
            if (json.data && json.data.debug) {
              result.innerHTML += " <em>Debug saved in settings</em>";
            }
          }
        }).catch(function(err){
          btn.disabled = false;
          result.innerHTML = "<span style=\"color:#b94a48\">Request error: "+ err +"</span>";
        });
      });
    })();'
		);
	}

	public function register_settings() {
		register_setting(
			$this->settings_group,
			$this->option_name,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => array(
					'consumer_key'     => '',
					'consumer_secret'  => '',
					'mode'             => 'sandbox',
					'default_currency' => 'KES',
					'notification_id'  => '',
					'success_page'     => 0,
					'failure_page'     => 0,
				),
			)
		);
		add_settings_section( 'dcslps_main', __( 'Payment gateway settings', 'dchamplegacy-standalone-pesapal' ), null, 'dcslps_settings' );

		add_settings_field( 'consumer_key', __( 'Consumer Key', 'dchamplegacy-standalone-pesapal' ), array( $this, 'field_key' ), 'dcslps_settings', 'dcslps_main' );
		add_settings_field( 'consumer_secret', __( 'Consumer Secret', 'dchamplegacy-standalone-pesapal' ), array( $this, 'field_secret' ), 'dcslps_settings', 'dcslps_main' );
		add_settings_field( 'mode', __( 'Mode', 'dchamplegacy-standalone-pesapal' ), array( $this, 'field_mode' ), 'dcslps_settings', 'dcslps_main' );
		add_settings_field( 'default_currency', __( 'Default Currency', 'dchamplegacy-standalone-pesapal' ), array( $this, 'field_currency' ), 'dcslps_settings', 'dcslps_main' );
		add_settings_field( 'notification_id', __( 'Registered Notification ID', 'dchamplegacy-standalone-pesapal' ), array( $this, 'field_notification_id' ), 'dcslps_settings', 'dcslps_main' );
		add_settings_field( 'success_page', __( 'Payment Success Page', 'dchamplegacy-standalone-pesapal' ), array( $this, 'field_success_page' ), 'dcslps_settings', 'dcslps_main' );
		add_settings_field( 'failure_page', __( 'Payment Failed Page', 'dchamplegacy-standalone-pesapal' ), array( $this, 'field_failed_page' ), 'dcslps_settings', 'dcslps_main' );
		add_settings_field( 'ipn_register', __( 'IPN Register', 'dchamplegacy-standalone-pesapal' ), array( $this, 'field_ipn_register_button' ), 'dcslps_settings', 'dcslps_main' );
	}

	/**
	 * Sanitize settings array from options.php. Consumer secret is not passed through sanitize_text_field()
	 * so valid secret characters (e.g. from encoding) are preserved.
	 *
	 * @param mixed $in Raw option value.
	 * @return array<string, mixed>
	 */
	public function sanitize_options( $in ) {
		if ( ! is_array( $in ) ) {
			$in = array();
		}
		$o = get_option( $this->option_name, array() );
		if ( ! is_array( $o ) ) {
			$o = array();
		}

		$o['consumer_key'] = sanitize_text_field( $in['consumer_key'] ?? $o['consumer_key'] ?? '' );

		if ( array_key_exists( 'consumer_secret', $in ) ) {
			$o['consumer_secret'] = $this->sanitize_consumer_secret_value( $in['consumer_secret'] );
		} else {
			$o['consumer_secret'] = isset( $o['consumer_secret'] ) && is_string( $o['consumer_secret'] ) ? $o['consumer_secret'] : '';
		}

		$mode = $in['mode'] ?? $o['mode'] ?? 'sandbox';
		$o['mode'] = in_array( $mode, array( 'sandbox', 'live' ), true ) ? $mode : 'sandbox';

		$o['default_currency'] = sanitize_text_field( $in['default_currency'] ?? ( $o['default_currency'] ?? 'KES' ) );
		$o['notification_id']  = sanitize_text_field( $in['notification_id'] ?? $o['notification_id'] ?? '' );
		$o['success_page']     = absint( $in['success_page'] ?? $o['success_page'] ?? 0 );
		$o['failure_page']     = absint( $in['failure_page'] ?? $o['failure_page'] ?? 0 );
		return $o;
	}

	/**
	 * Trim and unslash only — do not use sanitize_text_field() on API secrets.
	 *
	 * @param mixed $value Submitted secret.
	 * @return string
	 */
	private function sanitize_consumer_secret_value( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return trim( wp_unslash( $value ) );
	}

	public function field_key() {
		$opts = get_option( $this->option_name );
		printf( '<input type="text" name="%1$s[consumer_key]" value="%2$s" style="width:70%%" />', esc_attr( $this->option_name ), esc_attr( $opts['consumer_key'] ?? '' ) );
	}

	public function field_secret() {
		$opts = get_option( $this->option_name );
		printf( '<input type="password" name="%1$s[consumer_secret]" value="%2$s" style="width:70%%" />', esc_attr( $this->option_name ), esc_attr( $opts['consumer_secret'] ?? '' ) );
	}

	public function field_mode() {
		$opts = get_option( $this->option_name );
		$mode = $opts['mode'] ?? 'sandbox';
		echo '<select name="' . esc_attr( $this->option_name ) . '[mode]">';
		echo '<option value="sandbox" ' . selected( $mode, 'sandbox', false ) . '>Sandbox</option>';
		echo '<option value="live" ' . selected( $mode, 'live', false ) . '>Live</option>';
		echo '</select>';
	}

	private function get_supported_currencies() {
		return array(
			'KES' => 'KES - Kenyan Shilling',
			'UGX' => 'UGX - Ugandan Shilling',
			'TZS' => 'TZS - Tanzanian Shilling',
			'RWF' => 'RWF - Rwandan Franc',
			'ZMW' => 'ZMW - Zambian Kwacha',
			'ZAR' => 'ZAR - South African Rand',
			'GHS' => 'GHS - Ghanaian Cedi',
			'NGN' => 'NGN - Nigerian Naira',
			'USD' => 'USD - US Dollar',
			'EUR' => 'EUR - Euro',
			'GBP' => 'GBP - British Pound',
			'AUD' => 'AUD - Australian Dollar',
			'CAD' => 'CAD - Canadian Dollar',
			'JPY' => 'JPY - Japanese Yen',
			'CHF' => 'CHF - Swiss Franc',
			'SEK' => 'SEK - Swedish Krona',
			'NOK' => 'NOK - Norwegian Krone',
			'DKK' => 'DKK - Danish Krone',
			'INR' => 'INR - Indian Rupee',
		);
	}

	public function field_currency() {
		$opts    = get_option( $this->option_name );
		$default = $opts['default_currency'] ?? 'KES';
		$currs   = $this->get_supported_currencies();
		echo '<select name="' . esc_attr( $this->option_name ) . '[default_currency]">';
		foreach ( $currs as $code => $label ) {
			printf(
				'<option value="%1$s" %2$s>%3$s</option>',
				esc_attr( $code ),
				selected( $default, $code, false ),
				esc_html( $label )
			);
		}
		echo '</select> &nbsp; <em>' . esc_html__( 'Select default currency', 'dchamplegacy-standalone-pesapal' ) . '</em>';
	}

	public function field_notification_id() {
		$opts = get_option( $this->option_name );
		printf( '<input readonly type="text" id="dcslps_notification_id_input" name="%1$s[notification_id]" value="%2$s" style="width:70%%" />', esc_attr( $this->option_name ), esc_attr( $opts['notification_id'] ?? '' ) );
	}

	public function field_success_page() {
		$opts = get_option( $this->option_name );
		$val  = $opts['success_page'] ?? 0;
		$html = wp_dropdown_pages(
			array(
				'name'              => $this->option_name . '[success_page]',
				'show_option_none'  => __( '— Select page —', 'dchamplegacy-standalone-pesapal' ),
				'selected'          => $val,
				'option_none_value' => 0,
				'echo'              => false,
			)
		);
		echo wp_kses(
			$html,
			array(
				'select' => array(
					'name'     => true,
					'id'       => true,
					'class'    => true,
					'disabled' => true,
				),
				'option' => array(
					'value'    => true,
					'class'    => true,
					'selected' => true,
				),
			)
		);
		echo ' &nbsp; <em>' . esc_html__( 'Page to redirect users to after successful payment', 'dchamplegacy-standalone-pesapal' ) . '</em>';
	}

	public function field_failed_page() {
		$opts = get_option( $this->option_name );
		$val  = $opts['failure_page'] ?? 0;
		$html = wp_dropdown_pages(
			array(
				'name'              => $this->option_name . '[failure_page]',
				'show_option_none'  => __( '— Select page —', 'dchamplegacy-standalone-pesapal' ),
				'selected'          => $val,
				'option_none_value' => 0,
				'echo'              => false,
			)
		);
		echo wp_kses(
			$html,
			array(
				'select' => array(
					'name'     => true,
					'id'       => true,
					'class'    => true,
					'disabled' => true,
				),
				'option' => array(
					'value'    => true,
					'class'    => true,
					'selected' => true,
				),
			)
		);
		echo ' &nbsp; <em>' . esc_html__( 'Page to redirect users to after failed payment', 'dchamplegacy-standalone-pesapal' ) . '</em>';
	}

	public function field_ipn_register_button() {
		echo '<p>' . esc_html__( 'Register your IPN (callback) URL with the payment provider. Click the button below to register and store the returned notification_id.', 'dchamplegacy-standalone-pesapal' ) . '</p>';
		echo '<button type="button" class="button button-primary" id="dcslps-register-ipn-btn">' . esc_html__( 'Register IPN URL', 'dchamplegacy-standalone-pesapal' ) . '</button>';
		echo '&nbsp;<span id="dcslps-register-result" style="margin-left:12px;"></span>';
		echo '<div style="margin-top:8px;color:#666;font-size:13px;">' . esc_html__( 'Last token response (debug): option', 'dchamplegacy-standalone-pesapal' ) . ' <code>dcslps_last_token_response</code></div>';
	}

	public function settings_page() {
		?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Dchamp Legacy Standalone PesaPal Payments', 'dchamplegacy-standalone-pesapal' ); ?></h1>
		<p class="description"><?php echo esc_html__( 'This plugin is not affiliated with or endorsed by PesaPal.', 'dchamplegacy-standalone-pesapal' ); ?></p>
		<form method="post" action="options.php">
			<?php settings_fields( $this->settings_group ); ?>
			<?php do_settings_sections( 'dcslps_settings' ); ?>
			<?php submit_button(); ?>
		</form>

		<h2><?php echo esc_html__( 'Shortcode', 'dchamplegacy-standalone-pesapal' ); ?></h2>
		<p><?php echo esc_html__( 'Use', 'dchamplegacy-standalone-pesapal' ); ?> <code>[dcslps_payment_form]</code> <?php echo esc_html__( 'to insert the payment form. Attributes:', 'dchamplegacy-standalone-pesapal' ); ?> <code>amount</code>, <code>currency</code>, <code>description</code>.</p>

		<h2><?php echo esc_html__( 'Callback & IPN', 'dchamplegacy-standalone-pesapal' ); ?></h2>
		<p><?php echo esc_html__( 'Callback URL:', 'dchamplegacy-standalone-pesapal' ); ?> <code><?php echo esc_html( $this->get_callback_url() ); ?></code></p>
		<p><?php echo esc_html__( 'IPN URL (to register):', 'dchamplegacy-standalone-pesapal' ); ?> <code><?php echo esc_html( $this->get_ipn_url() ); ?></code></p>

		<h2><?php echo esc_html__( 'Debug info', 'dchamplegacy-standalone-pesapal' ); ?></h2>
		<p><?php echo esc_html__( 'Last token responses are stored in option', 'dchamplegacy-standalone-pesapal' ); ?> <code>dcslps_last_token_response</code>.</p>
		<pre style="background:#fff;border:1px solid #eee;padding:10px;"><?php echo esc_html( get_option( 'dcslps_last_token_response', '(none)' ) ); ?></pre>

		<h2><?php echo esc_html__( 'Last Submit attempts', 'dchamplegacy-standalone-pesapal' ); ?></h2>
		<pre style="background:#fff;border:1px solid #eee;padding:10px;"><?php echo esc_html( get_option( 'dcslps_last_submit_attempts', '(none)' ) ); ?></pre>

		<h2><?php echo esc_html__( 'Last Status attempts', 'dchamplegacy-standalone-pesapal' ); ?></h2>
		<pre style="background:#fff;border:1px solid #eee;padding:10px;"><?php echo esc_html( get_option( 'dcslps_last_status_attempts', '(none)' ) ); ?></pre>

		<h2><?php echo esc_html__( 'Last IPN error (if any)', 'dchamplegacy-standalone-pesapal' ); ?></h2>
		<pre style="background:#fff;border:1px solid #eee;padding:10px;"><?php echo esc_html( get_option( 'dcslps_last_ipn_error', '(none)' ) ); ?></pre>
	</div>
		<?php
	}

	public function activate() {
		$this->migrate_legacy_data();
		$this->create_transactions_table();
	}

	public function maybe_upgrade_schema() {
		$this->migrate_legacy_data();
		$this->create_transactions_table();
	}

	private function create_transactions_table() {
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE IF NOT EXISTS {$this->table} (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      merchant_reference VARCHAR(150) NOT NULL,
      order_tracking_id VARCHAR(150) DEFAULT NULL,
      amount DECIMAL(14,2) DEFAULT NULL,
      currency VARCHAR(10) DEFAULT NULL,
      status VARCHAR(80) DEFAULT NULL,
      payer_email VARCHAR(200) DEFAULT NULL,
      return_url TEXT DEFAULT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) $charset_collate;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $this->table is always {$wpdb->prefix}dcslps_transactions.
		$col = $wpdb->get_row( "SHOW COLUMNS FROM {$this->table} LIKE 'return_url'" );
		if ( empty( $col ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Same fixed plugin table as above.
			$wpdb->query( "ALTER TABLE {$this->table} ADD COLUMN return_url TEXT DEFAULT NULL" );
		}
	}

	private function migrate_legacy_data() {
		$old_opt = get_option( 'psp_standalone_options' );
		if ( false !== $old_opt && false === get_option( 'dcslps_standalone_options' ) ) {
			add_option( 'dcslps_standalone_options', $old_opt, '', 'no' );
		}

		$legacy_debug = array(
			'psp_last_token_response'    => 'dcslps_last_token_response',
			'psp_last_register_attempts' => 'dcslps_last_register_attempts',
			'psp_last_submit_attempts'   => 'dcslps_last_submit_attempts',
			'psp_last_status_attempts'   => 'dcslps_last_status_attempts',
			'psp_last_ipn_error'         => 'dcslps_last_ipn_error',
		);
		foreach ( $legacy_debug as $from => $to ) {
			if ( false === get_option( $to ) ) {
				$val = get_option( $from );
				if ( false !== $val ) {
					add_option( $to, $val, '', 'no' );
				}
			}
		}

		global $wpdb;
		$old_table  = $wpdb->prefix . 'psp_transactions';
		$new_table  = $this->table;
		$old_exists = ( $old_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $old_table ) ) );
		$new_exists = ( $new_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $new_table ) ) );
		if ( $old_exists && ! $new_exists && $old_table === $wpdb->prefix . 'psp_transactions' && $new_table === $wpdb->prefix . 'dcslps_transactions' ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- One-time migration; table names verified against wpdb->prefix.
			$wpdb->query( "RENAME TABLE `{$wpdb->prefix}psp_transactions` TO `{$wpdb->prefix}dcslps_transactions`" );
		}
	}

	private function get_options() {
		return get_option( $this->option_name, array( 'mode' => 'sandbox', 'default_currency' => 'KES' ) );
	}

	public function get_callback_url() {
		return home_url( '/?dcslps_callback=1' );
	}

	public function get_ipn_url() {
		return home_url( '/?dcslps_ipn=1' );
	}

	public function register_rest_routes() {
		$routes = array(
			array( 'dcslps/v1', '/submit', 'rest_submit_payment' ),
			array( 'dcslps/v1', '/register-ipn', 'rest_register_ipn' ),
			array( 'psp/v1', '/submit', 'rest_submit_payment' ),
			array( 'psp/v1', '/register-ipn', 'rest_register_ipn' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				$r[0],
				$r[1],
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $r[2] ),
					'permission_callback' => function () use ( $r ) {
						if ( '/register-ipn' === $r[1] ) {
							return current_user_can( 'manage_options' );
						}
						return true;
					},
				)
			);
		}
	}

	public function rest_register_ipn( $request ) {
		$opts = $this->get_options();
		if ( empty( $opts['consumer_key'] ) || empty( $opts['consumer_secret'] ) ) {
			return rest_ensure_response( array( 'success' => false, 'data' => array( 'message' => __( 'Consumer key & secret must be set in plugin settings.', 'dchamplegacy-standalone-pesapal' ) ) ) );
		}

		$helper = new DCSLPS_Pesapal_Api_Helper( 'live' === $opts['mode'] ? 'live' : 'sandbox' );
		$token  = $helper->getAccessToken( $opts['consumer_key'], $opts['consumer_secret'] );
		if ( is_wp_error( $token ) ) {
			update_option( 'dcslps_last_ipn_error', 'Auth error: ' . $token->get_error_message() );
			$debug = get_option( 'dcslps_last_token_response', '' );
			return rest_ensure_response( array( 'success' => false, 'data' => array( 'message' => 'Auth error: ' . $token->get_error_message(), 'debug' => $debug ) ) );
		}

		$notif = $helper->generateNotificationId( $this->get_ipn_url(), $token );
		if ( is_wp_error( $notif ) ) {
			update_option( 'dcslps_last_ipn_error', $notif->get_error_message() );
			return rest_ensure_response( array( 'success' => false, 'data' => array( 'message' => $notif->get_error_message() ) ) );
		}

		$opts['notification_id'] = sanitize_text_field( $notif );
		update_option( $this->option_name, $opts );
		update_option( 'dcslps_last_ipn_error', '(none)' );
		return rest_ensure_response( array( 'success' => true, 'data' => array( 'notification_id' => $opts['notification_id'] ) ) );
	}

	public function rest_submit_payment( $request ) {
		global $wpdb;
		$params = $request->get_params();
		$amount = floatval( $params['dcslps_amount'] ?? $params['ps_amount'] ?? 0 );

		$submitted_currency = sanitize_text_field( $params['dcslps_currency'] ?? $params['ps_currency'] ?? '' );
		$allowed            = array_keys( $this->get_supported_currencies() );
		$default_currency   = $this->get_options()['default_currency'] ?? 'KES';
		$currency           = in_array( $submitted_currency, $allowed, true ) ? $submitted_currency : $default_currency;

		$email      = sanitize_email( $params['dcslps_email'] ?? $params['ps_email'] ?? '' );
		$fname      = sanitize_text_field( $params['dcslps_firstname'] ?? $params['ps_firstname'] ?? '' );
		$lname      = sanitize_text_field( $params['dcslps_lastname'] ?? $params['ps_lastname'] ?? '' );
		$desc       = sanitize_text_field( $params['dcslps_description'] ?? $params['ps_description'] ?? 'Payment' );
		$return_url = esc_url_raw( $params['dcslps_return_url'] ?? $params['ps_return_url'] ?? '' );

		if ( $amount <= 0 ) {
			return rest_ensure_response( array( 'success' => false, 'data' => array( 'message' => __( 'Invalid amount', 'dchamplegacy-standalone-pesapal' ) ) ) );
		}
		$opts = $this->get_options();
		if ( empty( $opts['notification_id'] ) ) {
			return rest_ensure_response( array( 'success' => false, 'data' => array( 'message' => __( 'IPN notification_id not set. Register it in plugin settings.', 'dchamplegacy-standalone-pesapal' ) ) ) );
		}

		$helper = new DCSLPS_Pesapal_Api_Helper( 'live' === $opts['mode'] ? 'live' : 'sandbox' );
		$token  = $helper->getAccessToken( $opts['consumer_key'], $opts['consumer_secret'] );
		if ( is_wp_error( $token ) ) {
			$debug = get_option( 'dcslps_last_token_response', '' );
			return rest_ensure_response( array( 'success' => false, 'data' => array( 'message' => 'Auth error: ' . $token->get_error_message(), 'debug' => $debug ) ) );
		}

		$merchant_ref = 'DCSLPS-' . time() . '-' . wp_generate_password( 6, false, false );
		$wpdb->insert(
			$this->table,
			array(
				'merchant_reference' => $merchant_ref,
				'order_tracking_id'  => null,
				'amount'             => $amount,
				'currency'           => $currency,
				'status'             => 'pending_submission',
				'payer_email'        => $email,
				'return_url'         => $return_url,
			)
		);
		$insert_id = $wpdb->insert_id;

		$body = array(
			'id'               => $merchant_ref,
			'currency'         => $currency,
			'amount'           => round( $amount, 2 ),
			'description'      => $desc,
			'callback_url'     => $this->get_callback_url(),
			'cancellation_url' => $this->get_callback_url() . '&cancelled=1',
			'notification_id'  => $opts['notification_id'],
			'billing_address'  => array(
				'email_address' => $email,
				'first_name'    => $fname,
				'last_name'     => $lname,
			),
		);

		$res = $helper->submitOrder( $token, $body );

		if ( is_wp_error( $res ) ) {
			$wpdb->update( $this->table, array( 'status' => 'failed_submission', 'updated_at' => current_time( 'mysql', 1 ) ), array( 'id' => $insert_id ), array( '%s', '%s' ), array( '%d' ) );
			return rest_ensure_response( array( 'success' => false, 'data' => array( 'message' => $res->get_error_message(), 'debug' => get_option( 'dcslps_last_submit_attempts', '(none)' ) ) ) );
		}

		$redirect      = $res->redirect_url ?? ( $res->redirectURL ?? null );
		$order_tracking = $res->order_tracking_id ?? ( $res->orderTrackingId ?? null );

		$wpdb->update(
			$this->table,
			array(
				'order_tracking_id' => $order_tracking,
				'status'            => 'created',
				'updated_at'        => current_time( 'mysql', 1 ),
			),
			array( 'id' => $insert_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( ! $redirect ) {
			$wpdb->update( $this->table, array( 'status' => 'no_redirect' ), array( 'id' => $insert_id ), array( '%s' ), array( '%d' ) );
			return rest_ensure_response( array( 'success' => false, 'data' => array( 'message' => 'No redirect_url in response: ' . wp_json_encode( $res ) ) ) );
		}

		return rest_ensure_response( array( 'success' => true, 'data' => array( 'redirect_url' => $redirect ) ) );
	}

	private function is_successful_status( $statusObj ) {
		if ( is_object( $statusObj ) ) {
			$s = strtolower( (string) ( $statusObj->status ?? $statusObj->payment_status ?? $statusObj->payment_status_description ?? '' ) );
			if ( in_array( $s, array( 'completed', 'paid', 'success', 'settled' ), true ) ) {
				return true;
			}
			if ( ! empty( $statusObj->transactions ) && is_array( $statusObj->transactions ) ) {
				foreach ( $statusObj->transactions as $t ) {
					if ( ! empty( $t->status ) && in_array( strtolower( $t->status ), array( 'completed', 'paid', 'success', 'settled' ), true ) ) {
						return true;
					}
				}
			}
			if ( ! empty( $statusObj->payment_status_description ) && in_array( strtolower( $statusObj->payment_status_description ), array( 'completed', 'paid', 'success', 'settled' ), true ) ) {
				return true;
			}
		} elseif ( is_array( $statusObj ) ) {
			$s = strtolower( (string) ( $statusObj['status'] ?? $statusObj['payment_status'] ?? $statusObj['payment_status_description'] ?? '' ) );
			if ( in_array( $s, array( 'completed', 'paid', 'success', 'settled' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	public function maybe_handle_callback_or_ipn() {
		// Payment gateway redirects and IPN requests cannot send WordPress nonces; inputs are sanitized below.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

		global $wpdb;

		$is_legacy_callback = isset( $_GET['psp_callback'] );
		$is_new_callback    = isset( $_GET['dcslps_callback'] );
		if ( $is_legacy_callback || $is_new_callback ) {
			$order_tracking_id = sanitize_text_field( wp_unslash( $_GET['OrderTrackingId'] ?? $_GET['OrderTrackingID'] ?? '' ) );
			$merchant_ref      = sanitize_text_field( wp_unslash( $_GET['OrderMerchantReference'] ?? '' ) );

			$opts   = $this->get_options();
			$helper = new DCSLPS_Pesapal_Api_Helper( 'live' === $opts['mode'] ? 'live' : 'sandbox' );
			$token  = $helper->getAccessToken( $opts['consumer_key'], $opts['consumer_secret'] );
			$status = is_wp_error( $token ) ? $token : $helper->getTransactionStatus( $token, $order_tracking_id, $merchant_ref );

			if ( ! empty( $merchant_ref ) && ! is_wp_error( $status ) ) {
				$wpdb->update(
					$this->table,
					array(
						'order_tracking_id' => $order_tracking_id,
						'status'            => is_object( $status ) ? ( $status->status ?? wp_json_encode( $status ) ) : wp_json_encode( $status ),
						'updated_at'        => current_time( 'mysql', 1 ),
					),
					array( 'merchant_reference' => $merchant_ref ),
					array( '%s', '%s', '%s' ),
					array( '%s' )
				);
			}

			$target_url = '';
			$success    = ( ! is_wp_error( $status ) ) && $this->is_successful_status( $status );
			$opts       = $this->get_options();
			if ( $success && ! empty( $opts['success_page'] ) ) {
				$target_url = get_permalink( $opts['success_page'] );
			} elseif ( ! $success && ! empty( $opts['failure_page'] ) ) {
				$target_url = get_permalink( $opts['failure_page'] );
			} else {
				if ( ! empty( $merchant_ref ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Plugin table is {$wpdb->prefix}dcslps_transactions only; merchant ref is prepared.
					$row = $wpdb->get_row( $wpdb->prepare( "SELECT return_url FROM {$this->table} WHERE merchant_reference = %s LIMIT 1", $merchant_ref ), ARRAY_A );
					if ( ! empty( $row['return_url'] ) ) {
						$target_url = $row['return_url'];
					}
				}
			}

			if ( empty( $target_url ) ) {
				status_header( 200 );
				echo '<!doctype html><html><head><meta charset="utf-8"><title>Payment callback</title><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:Arial,Helvetica,sans-serif;padding:20px;">';
				echo '<h2>Payment callback</h2>';
				echo '<p><strong>Merchant Reference:</strong> ' . esc_html( $merchant_ref ) . '</p>';
				echo '<p><strong>OrderTrackingId:</strong> ' . esc_html( $order_tracking_id ) . '</p>';
				echo '<pre>' . esc_html( is_wp_error( $status ) ? $status->get_error_message() : wp_json_encode( $status ) ) . '</pre>';
				echo '</body></html>';
				// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
				exit;
			}

			$msg = '';
			if ( is_wp_error( $status ) ) {
				$msg = $status->get_error_message();
			} elseif ( is_object( $status ) && ! empty( $status->message ) ) {
				$msg = (string) $status->message;
			} elseif ( is_object( $status ) ) {
				$msg = wp_json_encode( $status );
			} else {
				$msg = is_array( $status ) ? wp_json_encode( $status ) : (string) $status;
			}
			$msg = wp_strip_all_tags( $msg );

			$sep          = ( false === strpos( $target_url, '?' ) ) ? '?' : '&';
			$status_param = $success ? 'success' : 'failure';
			$redirect_to  = $target_url . $sep . 'dcslps_status=' . rawurlencode( $status_param ) . '&dcslps_msg=' . rawurlencode( $msg ) . '&dcslps_merchant=' . rawurlencode( $merchant_ref ) . '&dcslps_tracking=' . rawurlencode( $order_tracking_id );

			wp_safe_redirect( $redirect_to );
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
			exit;
		}

		$is_ipn = isset( $_GET['dcslps_ipn'] ) || isset( $_GET['psp_ipn'] ) || ! empty( $_POST['OrderNotificationType'] ) || ! empty( $_GET['OrderNotificationType'] );
		if ( $is_ipn ) {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
			$data   = 'POST' === $method ? wp_unslash( $_POST ) : wp_unslash( $_GET );
			$order_tracking_id = sanitize_text_field( $data['OrderTrackingId'] ?? '' );
			$merchant_ref      = sanitize_text_field( $data['OrderMerchantReference'] ?? '' );
			$opts              = $this->get_options();
			$helper            = new DCSLPS_Pesapal_Api_Helper( 'live' === $opts['mode'] ? 'live' : 'sandbox' );
			$token             = $helper->getAccessToken( $opts['consumer_key'], $opts['consumer_secret'] );
			$status            = is_wp_error( $token ) ? $token : $helper->getTransactionStatus( $token, $order_tracking_id, $merchant_ref );

			$wpdb->update(
				$this->table,
				array(
					'order_tracking_id' => $order_tracking_id,
					'status'            => is_object( $status ) ? ( $status->status ?? wp_json_encode( $status ) ) : wp_json_encode( $status ),
					'updated_at'        => current_time( 'mysql', 1 ),
				),
				array( 'merchant_reference' => $merchant_ref ),
				array( '%s', '%s', '%s' ),
				array( '%s' )
			);

			header( 'HTTP/1.1 200 OK' );
			echo 'OK';
			// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
			exit;
		}

		// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
	}

	private function get_payment_form_inline_css() {
		return '.dcslps-form { max-width:640px; width:100%; margin:18px auto; background:var(--dcslps-bg,#fff); border:1px solid var(--dcslps-border,#e6e6e6); padding:18px; border-radius:10px; box-shadow:0 6px 18px rgba(0,0,0,0.04); box-sizing:border-box; }
    .dcslps-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; align-items:start; }
    .dcslps-full { grid-column: 1 / -1; }
    .dcslps-label { display:block; font-size:13px; color:var(--dcslps-label,#333); margin-bottom:6px; font-weight:600; }
    .dcslps-input, .dcslps-select { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #d9d9d9; box-sizing:border-box; font-size:15px; background:var(--dcslps-input-bg,#fff); color:inherit; }
    .dcslps-button { display:inline-block; padding:12px 18px; border-radius:10px; border:none; font-size:16px; cursor:pointer; background:var(--dcslps-primary,#241a2f); color:#fff; box-shadow:0 6px 14px rgba(36,26,47,0.12); transition:transform .08s ease, opacity .12s ease; }
    .dcslps-button:active { transform:translateY(1px); }
    .dcslps-note { font-size:13px; color:#666; margin-left:12px; line-height:1.6; }
    .dcslps-row { display:flex; gap:12px; align-items:center; margin-top:8px; }
    @media (max-width:780px) {
      .dcslps-grid { grid-template-columns: 1fr; }
      .dcslps-row { flex-direction:column; align-items:stretch; }
      .dcslps-note { margin-left:0; margin-top:8px; }
      .dcslps-button { width:100%; padding:14px; font-size:17px; }
    }
    .dcslps-icons { display:flex; justify-content:center; margin-top:18px; }
    .dcslps-icons img { max-width:280px; width:100%; height:auto; opacity:0.95; display:block; }
    .dcslps-alert { padding:10px 12px; border-radius:8px; font-size:14px; margin-top:12px; }
    .dcslps-success { background:#e6ffef; border:1px solid #b8f1d1; color:#065f35; }
    .dcslps-fail { background:#fff2f2; border:1px solid #f1b3b3; color:#8a1d1d; }
    input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }';
	}

	public function shortcode_payment_form( $atts = array() ) {
		wp_register_style( 'dcslps-payment-form', false, array(), DCSLPS_VERSION );
		wp_enqueue_style( 'dcslps-payment-form' );
		wp_add_inline_style( 'dcslps-payment-form', $this->get_payment_form_inline_css() );

		wp_register_script( 'dcslps-payment-form', false, array(), DCSLPS_VERSION, true );
		wp_enqueue_script( 'dcslps-payment-form' );
		wp_add_inline_script(
			'dcslps-payment-form',
			'function dcslpsSubmitPaymentForm(form) {
      var msg = form.querySelector(".dcslps-msg");
      if (!msg) return false;
      msg.textContent = "";
      var data = new FormData(form);
      data.append("dcslps_return_url", window.location.href);
      fetch(' . wp_json_encode( esc_url_raw( rest_url( 'dcslps/v1/submit' ) ) ) . ', {
        method:"POST",
        credentials:"same-origin",
        headers: {"X-WP-Nonce": ' . wp_json_encode( wp_create_nonce( 'wp_rest' ) ) . ' },
        body: data
      }).then(function(r){ return r.json(); })
      .then(function(json){
        if (json.success && json.data && json.data.redirect_url) {
          window.location.href = json.data.redirect_url;
        } else {
          if (json.data && json.data.debug) {
            msg.textContent = "Auth / Submit error. Debug info saved in plugin settings. " + (json.data.message || "");
          } else {
            msg.textContent = (json.data && json.data.message) ? json.data.message : "Unable to initiate payment";
          }
        }
      }).catch(function(err){
        msg.textContent = "Request error: " + err;
      });
      return false;
    }
    (function(){
      function getQueryParams() {
        var p = {};
        location.search.replace(/^\?/, "").split("&").forEach(function(kv){
          if (!kv) return;
          var parts = kv.split("=");
          p[decodeURIComponent(parts[0])] = parts[1] ? decodeURIComponent(parts[1].replace(/\+/g," ")) : "";
        });
        return p;
      }
      var params = getQueryParams();
      var st = params.dcslps_status || params.psp_status;
      if (st) {
        var form = document.querySelector(".dcslps-form");
        if (!form) return;
        var message = params.dcslps_msg || params.psp_msg;
        message = message ? message : (st === "success" ? "Payment successful. Thank you." : "Payment failed.");
        message = message.replace(/<\/?[^>]+(>|$)/g, "");
        var div = document.createElement("div");
        div.className = "dcslps-alert " + (st === "success" ? "dcslps-success" : "dcslps-fail");
        div.textContent = message;
        form.insertBefore(div, form.firstChild);
        if (window.history && window.history.replaceState) {
          var url = window.location.protocol + "//" + window.location.host + window.location.pathname;
          window.history.replaceState({}, document.title, url);
        }
      }
    })();',
			'after'
		);

		$atts = shortcode_atts(
			array(
				'amount'      => '',
				'currency'    => $this->get_options()['default_currency'] ?? 'KES',
				'description' => 'Payment',
			),
			$atts,
			'dcslps_payment_form'
		);

		ob_start();
		$form_id = 'dcslps-form-' . sanitize_html_class( uniqid( '', true ) );
		?>
	<form class="dcslps-form" id="<?php echo esc_attr( $form_id ); ?>" onsubmit="return dcslpsSubmitPaymentForm(this);" autocomplete="on" novalidate>
		<div class="dcslps-grid">
		<div>
			<label class="dcslps-label"><?php
			/* translators: %s: currency code (e.g. KES). */
			echo esc_html( sprintf( __( 'Amount (%s)', 'dchamplegacy-standalone-pesapal' ), $atts['currency'] ) );
			?></label>
			<input class="dcslps-input" type="number" step="0.01" name="dcslps_amount" required value="<?php echo esc_attr( $atts['amount'] ); ?>" placeholder="0.00">
		</div>

		<div>
			<label class="dcslps-label"><?php echo esc_html__( 'Currency', 'dchamplegacy-standalone-pesapal' ); ?></label>
			<select class="dcslps-select" name="dcslps_currency" required aria-label="<?php echo esc_attr__( 'Currency', 'dchamplegacy-standalone-pesapal' ); ?>">
			<?php
				$currs   = $this->get_supported_currencies();
				$current = $atts['currency'];
			foreach ( $currs as $code => $label ) {
				printf( '<option value="%1$s" %2$s>%3$s</option>', esc_attr( $code ), selected( $current, $code, false ), esc_html( $label ) );
			}
			?>
			</select>
		</div>

		<div>
			<label class="dcslps-label"><?php echo esc_html__( 'Email', 'dchamplegacy-standalone-pesapal' ); ?></label>
			<input class="dcslps-input" type="email" name="dcslps_email" required placeholder="you@example.com">
		</div>

		<div>
			<label class="dcslps-label"><?php echo esc_html__( 'Phone (optional)', 'dchamplegacy-standalone-pesapal' ); ?></label>
			<input class="dcslps-input" type="text" name="dcslps_phone" placeholder="+2547xxxxxxxx">
		</div>

		<div>
			<label class="dcslps-label"><?php echo esc_html__( 'First name', 'dchamplegacy-standalone-pesapal' ); ?></label>
			<input class="dcslps-input" type="text" name="dcslps_firstname" placeholder="<?php echo esc_attr__( 'First name', 'dchamplegacy-standalone-pesapal' ); ?>">
		</div>

		<div>
			<label class="dcslps-label"><?php echo esc_html__( 'Last name', 'dchamplegacy-standalone-pesapal' ); ?></label>
			<input class="dcslps-input" type="text" name="dcslps_lastname" placeholder="<?php echo esc_attr__( 'Last name', 'dchamplegacy-standalone-pesapal' ); ?>">
		</div>

		<div class="dcslps-full">
			<div class="dcslps-row">
			<button class="dcslps-button" type="submit"><?php echo esc_html__( 'Pay now', 'dchamplegacy-standalone-pesapal' ); ?></button>
			<div class="dcslps-note dcslps-msg" aria-live="polite"></div>
			</div>
		</div>

		<div class="dcslps-full dcslps-icons">
			<img src="<?php echo esc_url( plugins_url( 'Payments.png', DCSLPS_PLUGIN_FILE ) ); ?>" alt="<?php echo esc_attr__( 'Supported payment methods', 'dchamplegacy-standalone-pesapal' ); ?>">
		</div>

		</div>

		<input type="hidden" name="dcslps_description" value="<?php echo esc_attr( $atts['description'] ); ?>">
	</form>
		<?php
		return ob_get_clean();
	}

	public function render_transactions_page() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Log table name is fixed (wpdb_prefix + dcslps_transactions); static ORDER/LIMIT.
		$rows = $wpdb->get_results( "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT 500", ARRAY_A );
		echo '<div class="wrap"><h1>' . esc_html__( 'Payment transactions', 'dchamplegacy-standalone-pesapal' ) . '</h1>';
		echo '<table class="widefat"><thead><tr><th>ID</th><th>Merchant Ref</th><th>Tracking Id</th><th>Amount</th><th>Currency</th><th>Status</th><th>Payer</th><th>Return URL</th><th>Created</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( $r['id'] ) . '</td>';
			echo '<td>' . esc_html( $r['merchant_reference'] ) . '</td>';
			echo '<td>' . esc_html( $r['order_tracking_id'] ) . '</td>';
			echo '<td>' . esc_html( $r['amount'] ) . '</td>';
			echo '<td>' . esc_html( $r['currency'] ) . '</td>';
			echo '<td>' . esc_html( $r['status'] ) . '</td>';
			echo '<td>' . esc_html( $r['payer_email'] ) . '</td>';
			echo '<td style="max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' . esc_html( $r['return_url'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $r['created_at'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}
}

new DCSLPS_Standalone_Plugin();
