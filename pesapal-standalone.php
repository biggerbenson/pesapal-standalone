<?php
/**
 * Plugin Name: PesaPal Standalone Integrator
 * Plugin URI: https://dchamplegacy.com/pesapal-standalone
 * Description: Standalone PesaPal payment integration (no WooCommerce). Admin UI, IPN registration, shortcode [pesapal_payment_form], transaction logging. Mobile-responsive form and robust endpoint detection.
 * Version: 1.4.5
 * Author: Dchamp Legacy
 * Author URI: https://dchamplegacy.com
 * License: GPLv2 or later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ----------------------- PesaPal helper (robust endpoints) ----------------------- */
class PSP_PesapalV30Helper {
  private $candidates = array(
    'live' => array(
      'https://pay.pesapal.com/pesapalv3',
      'https://pay.pesapal.com/v3',
      'https://pay.pesapal.com'
    ),
    'sandbox' => array(
      'https://cybqa.pesapal.com/pesapalv3',
      'https://cybqa.pesapal.com/v3',
      'https://cybqa.pesapal.com'
    )
  );

  public $url; // chosen working base
  public $mode;

  public function __construct($api = "demo") {
    $this->mode = ($api === 'live') ? 'live' : 'sandbox';
    $this->url = null;
  }

  public function getAccessToken($consumer_key, $consumer_secret) {
    $payload = json_encode(array('consumer_key'=>$consumer_key,'consumer_secret'=>$consumer_secret));
    $attempts = array();

    foreach ($this->candidates[$this->mode] as $base) {
      $endpoint = rtrim($base,'/') . '/api/Auth/RequestToken';
      $res = $this->curlRequest($endpoint, array('Content-Type: application/json','Accept: application/json'), $payload);
      $attempts[] = array('endpoint'=>$endpoint,'http_code'=>($res->http_code ?? null),'raw'=>($res->raw ?? json_encode($res)));
      if (is_wp_error($res)) continue;
      if (is_object($res) && !empty($res->token)) {
        $this->url = rtrim($base, '/');
        update_option('psp_last_token_response', json_encode(array('success_endpoint'=>$endpoint,'response'=>$res), JSON_PRETTY_PRINT));
        return $res->token;
      }
    }

    update_option('psp_last_token_response', json_encode($attempts, JSON_PRETTY_PRINT));
    return new WP_Error('auth_failed','No valid token response from PesaPal. See psp_last_token_response option for details.');
  }

  public function generateNotificationId($callback, $access_token) {
    $bases = $this->buildBasesList();
    $attempts = array();
    foreach ($bases as $base) {
      $endpoint = rtrim($base,'/') . '/api/URLSetup/RegisterIPN';
      $headers = array('accept: application/json','content-type: application/json','authorization: Bearer ' . $access_token);
      $body = json_encode(array('url' => $callback, 'ipn_notification_type' => 'POST'));
      $res = $this->curlRequest($endpoint, $headers, $body);
      $attempts[] = array('endpoint'=>$endpoint,'http_code'=>($res->http_code ?? null),'raw'=>($res->raw ?? json_encode($res)));
      if (is_wp_error($res)) continue;
      if (is_object($res)) {
        $this->url = rtrim($base,'/');
        update_option('psp_last_register_attempts', json_encode($attempts, JSON_PRETTY_PRINT));
        $id = $res->ipn_id ?? $res->notification_id ?? $res->notificationId ?? null;
        if ($id) return $id;
        return new WP_Error('ipn_failed','RegisterIPN returned no notification id: '.json_encode($res));
      }
    }
    update_option('psp_last_register_attempts', json_encode($attempts, JSON_PRETTY_PRINT));
    return new WP_Error('ipn_failed','No valid response while registering IPN. See psp_last_register_attempts option.');
  }

  public function submitOrder($access_token, $payload_assoc) {
    $bases = $this->buildBasesList();
    $attempts = array();
    foreach ($bases as $base) {
      $endpoint = rtrim($base,'/') . '/api/Transactions/SubmitOrderRequest';
      $headers = array('accept: application/json','content-type: application/json','authorization: Bearer ' . $access_token);
      $body = json_encode($payload_assoc);
      $res = $this->curlRequest($endpoint, $headers, $body);
      $attempts[] = array('endpoint'=>$endpoint,'http_code'=>($res->http_code ?? null),'raw'=>($res->raw ?? json_encode($res)));
      if (is_wp_error($res)) continue;
      if (isset($res->http_code) && intval($res->http_code) !== 200) continue;
      if (is_object($res)) {
        $this->url = rtrim($base,'/');
        update_option('psp_last_submit_attempts', json_encode($attempts, JSON_PRETTY_PRINT));
        return $res;
      }
    }
    update_option('psp_last_submit_attempts', json_encode($attempts, JSON_PRETTY_PRINT));
    return new WP_Error('submit_failed','No valid response from SubmitOrderRequest. See psp_last_submit_attempts option for details.');
  }

  /**
   * IMPORTANT: GetTransactionStatus is a GET request with query params
   */
  public function getTransactionStatus($access_token, $orderTrackingId = '', $merchantReference = '') {
    $bases = $this->buildBasesList();
    $attempts = array();

    foreach ($bases as $base) {
      // Build query string properly (orderTrackingId required)
      $qs = '?orderTrackingId=' . urlencode($orderTrackingId);
      if (!empty($merchantReference)) $qs .= '&merchantReference=' . urlencode($merchantReference);
      $endpoint = rtrim($base, '/') . '/api/Transactions/GetTransactionStatus' . $qs;

      // For GET we send no body, but include Authorization header
      $headers = array('accept: application/json','content-type: application/json','authorization: Bearer ' . $access_token);

      // curlRequest will perform GET if body is null
      $res = $this->curlRequest($endpoint, $headers, null);

      $attempts[] = array('endpoint'=>$endpoint,'http_code'=>($res->http_code ?? null),'raw'=>($res->raw ?? json_encode($res)));

      if (is_wp_error($res)) continue;

      // require http_code 200 if present
      if (isset($res->http_code) && intval($res->http_code) !== 200) continue;

      if (is_object($res)) {
        $this->url = rtrim($base, '/');
        update_option('psp_last_status_attempts', json_encode($attempts, JSON_PRETTY_PRINT));
        return $res;
      }
    }

    update_option('psp_last_status_attempts', json_encode($attempts, JSON_PRETTY_PRINT));
    return new WP_Error('status_failed','No valid response from GetTransactionStatus. See psp_last_status_attempts option for details.');
  }

  private function buildBasesList() {
    $bases = array();
    if (!empty($this->url)) $bases[] = rtrim($this->url, '/');
    foreach ($this->candidates[$this->mode] as $c) {
      if (!in_array($c, $bases)) $bases[] = $c;
    }
    return $bases;
  }

  private function curlRequest($endpoint, $headers, $body = null) {
    if (!function_exists('curl_init')) {
      return new WP_Error('curl_missing','PHP cURL extension required but not available on this server.');
    }
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    if (!empty($body)) {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
      $err = curl_error($ch);
      curl_close($ch);
      return new WP_Error('curl_error', $err);
    }
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($response);
    if (is_null($decoded)) {
      return (object) array('raw' => $response, 'http_code' => $http_code);
    }
    if (is_object($decoded)) $decoded->http_code = $http_code;
    return $decoded;
  }
}

/* ----------------------- Main plugin ----------------------- */
class PSP_Standalone_Plugin {
  private $option_name = 'psp_standalone_options';
  private $table;

  public function __construct() {
    global $wpdb;
    $this->table = $wpdb->prefix . 'psp_transactions';

    add_action('admin_menu', array($this,'admin_menu'));
    add_action('admin_init', array($this,'register_settings'));
    add_action('init', array($this,'maybe_handle_callback_or_ipn'));
    add_action('rest_api_init', array($this,'register_rest_routes'));
    add_shortcode('pesapal_payment_form', array($this,'shortcode_payment_form'));
    register_activation_hook(__FILE__, array($this,'activate'));
  }

  public function admin_menu() {
    add_menu_page('PesaPal Standalone', 'PesaPal Standalone', 'manage_options', 'psp-standalone', array($this,'settings_page'), 'dashicons-money-alt', 56);
    add_submenu_page('psp-standalone','Transactions','Transactions','manage_options','psp-transactions', array($this,'render_transactions_page'));
  }

  public function register_settings() {
    register_setting($this->option_name, $this->option_name, array($this,'sanitize_options'));
    add_settings_section('psp_main', 'PesaPal Settings', null, 'psp_settings');

    add_settings_field('consumer_key','Consumer Key', array($this,'field_key'), 'psp_settings', 'psp_main');
    add_settings_field('consumer_secret','Consumer Secret', array($this,'field_secret'), 'psp_settings', 'psp_main');
    add_settings_field('mode','Mode', array($this,'field_mode'), 'psp_settings', 'psp_main');
    add_settings_field('default_currency','Default Currency', array($this,'field_currency'), 'psp_settings', 'psp_main');
    add_settings_field('notification_id','Registered Notification ID', array($this,'field_notification_id'), 'psp_settings', 'psp_main');
    add_settings_field('success_page','Payment Success Page', array($this,'field_success_page'), 'psp_settings', 'psp_main');
    add_settings_field('failure_page','Payment Failed Page', array($this,'field_failed_page'), 'psp_settings', 'psp_main');
    add_settings_field('ipn_register','IPN Register', array($this,'field_ipn_register_button'), 'psp_settings', 'psp_main');
  }

  public function sanitize_options($in) {
    $o = get_option($this->option_name, array());
    $o['consumer_key'] = sanitize_text_field($in['consumer_key'] ?? $o['consumer_key'] ?? '');
    $o['consumer_secret'] = sanitize_text_field($in['consumer_secret'] ?? $o['consumer_secret'] ?? '');
    $o['mode'] = in_array($in['mode'] ?? 'sandbox', array('sandbox','live')) ? $in['mode'] : 'sandbox';
    $o['default_currency'] = sanitize_text_field($in['default_currency'] ?? ($o['default_currency'] ?? 'KES'));
    $o['notification_id'] = sanitize_text_field($in['notification_id'] ?? $o['notification_id'] ?? '');
    $o['success_page'] = absint($in['success_page'] ?? $o['success_page'] ?? 0);
    $o['failure_page'] = absint($in['failure_page'] ?? $o['failure_page'] ?? 0);
    return $o;
  }

  public function field_key() {
    $opts = get_option($this->option_name);
    printf('<input type="text" name="%1$s[consumer_key]" value="%2$s" style="width:70%%" />', esc_attr($this->option_name), esc_attr($opts['consumer_key'] ?? ''));
  }
  public function field_secret() {
    $opts = get_option($this->option_name);
    printf('<input type="password" name="%1$s[consumer_secret]" value="%2$s" style="width:70%%" />', esc_attr($this->option_name), esc_attr($opts['consumer_secret'] ?? ''));
  }
  public function field_mode() {
    $opts = get_option($this->option_name);
    $mode = $opts['mode'] ?? 'sandbox';
    echo '<select name="'.esc_attr($this->option_name).'[mode]">';
    echo '<option value="sandbox" '.selected($mode,'sandbox',false).'>Sandbox</option>';
    echo '<option value="live" '.selected($mode,'live',false).'>Live</option>';
    echo '</select>';
  }

  private function get_supported_currencies() {
    return array(
        'KES'=>'KES - Kenyan Shilling','UGX'=>'UGX - Ugandan Shilling','TZS'=>'TZS - Tanzanian Shilling',
        'RWF'=>'RWF - Rwandan Franc','ZMW'=>'ZMW - Zambian Kwacha','ZAR'=>'ZAR - South African Rand',
        'GHS'=>'GHS - Ghanaian Cedi','NGN'=>'NGN - Nigerian Naira','USD'=>'USD - US Dollar',
        'EUR'=>'EUR - Euro','GBP'=>'GBP - British Pound','AUD'=>'AUD - Australian Dollar',
        'CAD'=>'CAD - Canadian Dollar','JPY'=>'JPY - Japanese Yen','CHF'=>'CHF - Swiss Franc',
        'SEK'=>'SEK - Swedish Krona','NOK'=>'NOK - Norwegian Krone','DKK'=>'DKK - Danish Krone',
        'INR'=>'INR - Indian Rupee'
    );
  }

  public function field_currency() {
    $opts = get_option($this->option_name);
    $default = $opts['default_currency'] ?? 'KES';
    $currs = $this->get_supported_currencies();
    echo '<select name="'.esc_attr($this->option_name).'[default_currency]">';
    foreach ($currs as $code => $label) {
        printf('<option value="%1$s" %2$s>%3$s</option>',
            esc_attr($code),
            selected($default, $code, false),
            esc_html($label)
        );
    }
    echo '</select> &nbsp; <em>Select default currency</em>';
  }

  public function field_notification_id() {
    $opts = get_option($this->option_name);
    printf('<input readonly type="text" id="psp_notification_id_input" name="%1$s[notification_id]" value="%2$s" style="width:70%%" />', esc_attr($this->option_name), esc_attr($opts['notification_id'] ?? ''));
  }

  public function field_success_page() {
    $opts = get_option($this->option_name);
    $val = $opts['success_page'] ?? 0;
    wp_dropdown_pages(array(
      'name' => $this->option_name . '[success_page]',
      'show_option_none' => '— Select page —',
      'selected' => $val,
      'option_none_value' => 0
    ));
    echo ' &nbsp; <em>Page to redirect users to after successful payment</em>';
  }

  public function field_failed_page() {
    $opts = get_option($this->option_name);
    $val = $opts['failure_page'] ?? 0;
    wp_dropdown_pages(array(
      'name' => $this->option_name . '[failure_page]',
      'show_option_none' => '— Select page —',
      'selected' => $val,
      'option_none_value' => 0
    ));
    echo ' &nbsp; <em>Page to redirect users to after failed payment</em>';
  }

  public function field_ipn_register_button() {
    echo '<p>Register your IPN (callback) URL with PesaPal. Click the button below to register and store the returned <code>notification_id</code>.</p>';
    echo '<button type="button" class="button button-primary" id="psp-register-ipn-btn">Register IPN URL</button>';
    echo '&nbsp;<span id="psp-register-result" style="margin-left:12px;"></span>';
    echo '<div style="margin-top:8px;color:#666;font-size:13px;">Last token response (debug): option <code>psp_last_token_response</code></div>';
    ?>
    <script>
    (function(){
      const btn = document.getElementById('psp-register-ipn-btn');
      const result = document.getElementById('psp-register-result');
      btn.addEventListener('click', function(){
        result.textContent = 'Registering...';
        btn.disabled = true;
        fetch('<?php echo esc_url( rest_url('psp/v1/register-ipn') ); ?>', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>', 'Content-Type': 'application/json' },
          body: JSON.stringify({})
        }).then(function(r){ return r.json(); })
        .then(function(json){
          btn.disabled = false;
          if (json.success) {
            result.innerHTML = '<span style="color:green">Registered: ' + (json.data.notification_id || '') + '</span>';
            var input = document.getElementById('psp_notification_id_input');
            if (input) input.value = json.data.notification_id || '';
          } else {
            result.innerHTML = '<span style="color:#b94a48">Error: ' + (json.data && json.data.message ? json.data.message : 'Unknown') + '</span>';
            if (json.data && json.data.debug) {
              result.innerHTML += ' <em>Debug saved in settings</em>';
            }
          }
        }).catch(function(err){
          btn.disabled = false;
          result.innerHTML = '<span style="color:#b94a48">Request error: '+ err +'</span>';
        });
      });
    })();
    </script>
    <?php
  }

  public function settings_page() {
    ?>
    <div class="wrap">
      <h1>PesaPal Standalone</h1>
      <form method="post" action="options.php">
        <?php settings_fields($this->option_name); do_settings_sections('psp_settings'); submit_button(); ?>
      </form>

      <h2>Shortcode</h2>
      <p>Use <code>[pesapal_payment_form]</code> to insert the payment form. Attributes: <code>amount</code>, <code>currency</code>, <code>description</code>.</p>

      <h2>Callback & IPN</h2>
      <p>Callback URL: <code><?php echo esc_html($this->get_callback_url()); ?></code></p>
      <p>IPN URL (to register): <code><?php echo esc_html($this->get_ipn_url()); ?></code></p>

      <h2>Debug info</h2>
      <p>Last token responses are stored in option <code>psp_last_token_response</code>. If register fails, copy its content and share it for debugging.</p>
      <pre style="background:#fff;border:1px solid #eee;padding:10px;"><?php echo esc_html( get_option('psp_last_token_response','(none)') ); ?></pre>

      <h2>Last Submit attempts</h2>
      <pre style="background:#fff;border:1px solid #eee;padding:10px;"><?php echo esc_html( get_option('psp_last_submit_attempts','(none)') ); ?></pre>

      <h2>Last Status attempts</h2>
      <pre style="background:#fff;border:1px solid #eee;padding:10px;"><?php echo esc_html( get_option('psp_last_status_attempts','(none)') ); ?></pre>

      <h2>Last IPN error (if any)</h2>
      <pre style="background:#fff;border:1px solid #eee;padding:10px;"><?php echo esc_html( get_option('psp_last_ipn_error','(none)') ); ?></pre>
    </div>
    <?php
  }

  public function activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$this->table} (
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
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    global $wpdb;
    $col = $wpdb->get_row("SHOW COLUMNS FROM {$this->table} LIKE 'return_url'");
    if (empty($col)) {
      $wpdb->query("ALTER TABLE {$this->table} ADD COLUMN return_url TEXT DEFAULT NULL");
    }
  }

  private function get_options() {
    return get_option($this->option_name, array('mode'=>'sandbox','default_currency'=>'KES'));
  }
  public function get_callback_url() {
    return home_url('/?psp_callback=1');
  }
  public function get_ipn_url() {
    return home_url('/?psp_ipn=1');
  }

  public function register_rest_routes() {
    register_rest_route('psp/v1', '/submit', array('methods'=>'POST','callback'=>array($this,'rest_submit_payment'),'permission_callback'=>function(){ return true; }));
    register_rest_route('psp/v1', '/register-ipn', array('methods'=>'POST','callback'=>array($this,'rest_register_ipn'),'permission_callback'=>function(){ return current_user_can('manage_options'); }));
  }

  public function rest_register_ipn($request) {
    $opts = $this->get_options();
    if (empty($opts['consumer_key']) || empty($opts['consumer_secret'])) {
      return rest_ensure_response(array('success'=>false,'data'=>array('message'=>'Consumer key & secret must be set in plugin settings.')));
    }

    $helper = new PSP_PesapalV30Helper($opts['mode'] === 'live' ? 'live' : 'sandbox');
    $token = $helper->getAccessToken($opts['consumer_key'], $opts['consumer_secret']);
    if (is_wp_error($token)) {
      update_option('psp_last_ipn_error', 'Auth error: '.$token->get_error_message());
      $debug = get_option('psp_last_token_response','');
      return rest_ensure_response(array('success'=>false,'data'=>array('message'=>'Auth error: '.$token->get_error_message(),'debug'=>$debug)));
    }

    $notif = $helper->generateNotificationId($this->get_ipn_url(), $token);
    if (is_wp_error($notif)) {
      update_option('psp_last_ipn_error', $notif->get_error_message());
      return rest_ensure_response(array('success'=>false,'data'=>array('message'=> $notif->get_error_message())));
    }

    $opts['notification_id'] = sanitize_text_field($notif);
    update_option($this->option_name, $opts);
    update_option('psp_last_ipn_error', '(none)');
    return rest_ensure_response(array('success'=>true,'data'=>array('notification_id'=>$opts['notification_id'])));
  }

  public function rest_submit_payment($request) {
    global $wpdb;
    $params = $request->get_params();
    $amount = floatval($params['ps_amount'] ?? 0);

    // currency validation
    $submitted_currency = sanitize_text_field($params['ps_currency'] ?? '');
    $allowed = array_keys($this->get_supported_currencies());
    $default_currency = $this->get_options()['default_currency'] ?? 'KES';
    $currency = in_array($submitted_currency, $allowed) ? $submitted_currency : $default_currency;

    $email = sanitize_email($params['ps_email'] ?? '');
    $fname = sanitize_text_field($params['ps_firstname'] ?? '');
    $lname = sanitize_text_field($params['ps_lastname'] ?? '');
    $desc = sanitize_text_field($params['ps_description'] ?? 'Payment');
    $return_url = esc_url_raw( $params['ps_return_url'] ?? '' );

    if ($amount <= 0) return rest_ensure_response(array('success'=>false,'data'=>array('message'=>'Invalid amount')));
    $opts = $this->get_options();
    if (empty($opts['notification_id'])) {
      return rest_ensure_response(array('success'=>false,'data'=>array('message'=>'IPN notification_id not set. Register it in plugin settings.')));
    }

    $helper = new PSP_PesapalV30Helper($opts['mode'] === 'live' ? 'live' : 'sandbox');
    $token = $helper->getAccessToken($opts['consumer_key'], $opts['consumer_secret']);
    if (is_wp_error($token)) {
      $debug = get_option('psp_last_token_response','');
      return rest_ensure_response(array('success'=>false,'data'=>array('message'=>'Auth error: '.$token->get_error_message(),'debug'=>$debug)));
    }

    // create merchant reference and insert a pending row so admin sees it
    $merchant_ref = 'PSP-' . time() . '-' . wp_generate_password(6,false,false);
    $wpdb->insert($this->table, array(
      'merchant_reference' => $merchant_ref,
      'order_tracking_id' => null,
      'amount' => $amount,
      'currency' => $currency,
      'status' => 'pending_submission',
      'payer_email' => $email,
      'return_url' => $return_url,
    ));
    $insert_id = $wpdb->insert_id;

    $body = array(
      'id' => $merchant_ref,
      'currency' => $currency,
      'amount' => round($amount,2),
      'description' => $desc,
      'callback_url' => $this->get_callback_url(),
      'cancellation_url' => $this->get_callback_url() . '&cancelled=1',
      'notification_id' => $opts['notification_id'],
      'billing_address' => array('email_address'=>$email,'first_name'=>$fname,'last_name'=>$lname)
    );

    $res = $helper->submitOrder($token, $body);

    if (is_wp_error($res)) {
      $wpdb->update($this->table, array('status' => 'failed_submission', 'updated_at' => current_time('mysql',1)), array('id' => $insert_id), array('%s','%s'), array('%d'));
      return rest_ensure_response(array('success'=>false,'data'=>array('message'=>$res->get_error_message(),'debug'=>get_option('psp_last_submit_attempts','(none)'))));
    }

    $redirect = $res->redirect_url ?? ($res->redirectURL ?? null);
    $orderTracking = $res->order_tracking_id ?? ($res->orderTrackingId ?? null);

    $wpdb->update($this->table, array(
      'order_tracking_id' => $orderTracking,
      'status' => 'created',
      'updated_at' => current_time('mysql',1)
    ), array('id' => $insert_id), array('%s','%s','%s'), array('%d'));

    if (!$redirect) {
      $wpdb->update($this->table, array('status'=>'no_redirect'), array('id'=>$insert_id), array('%s'), array('%d'));
      return rest_ensure_response(array('success'=>false,'data'=>array('message'=>'No redirect_url in response: '.json_encode($res))));
    }

    return rest_ensure_response(array('success'=>true,'data'=>array('redirect_url'=>$redirect)));
  }

  private function is_successful_status($statusObj) {
    if (is_object($statusObj)) {
      $s = strtolower((string)($statusObj->status ?? $statusObj->payment_status ?? $statusObj->payment_status_description ?? ''));
      if (in_array($s, array('completed','paid','success','settled'))) return true;
      if (!empty($statusObj->transactions) && is_array($statusObj->transactions)) {
        foreach ($statusObj->transactions as $t) {
          if (!empty($t->status) && in_array(strtolower($t->status), array('completed','paid','success','settled'))) return true;
        }
      }
      // sometimes API returns payment_status_description
      if (!empty($statusObj->payment_status_description) && in_array(strtolower($statusObj->payment_status_description), array('completed','paid','success','settled'))) return true;
    } elseif (is_array($statusObj)) {
      $s = strtolower((string)($statusObj['status'] ?? $statusObj['payment_status'] ?? $statusObj['payment_status_description'] ?? ''));
      if (in_array($s, array('completed','paid','success','settled'))) return true;
    }
    return false;
  }

  public function maybe_handle_callback_or_ipn() {
    global $wpdb;

    if (isset($_GET['psp_callback'])) {
      $orderTrackingId = sanitize_text_field($_GET['OrderTrackingId'] ?? $_GET['OrderTrackingID'] ?? '');
      $merchantRef = sanitize_text_field($_GET['OrderMerchantReference'] ?? '');

      $opts = $this->get_options();
      $helper = new PSP_PesapalV30Helper($opts['mode'] === 'live' ? 'live' : 'sandbox');
      $token = $helper->getAccessToken($opts['consumer_key'], $opts['consumer_secret']);
      $status = is_wp_error($token) ? $token : $helper->getTransactionStatus($token, $orderTrackingId, $merchantRef);

      if (!empty($merchantRef) && !is_wp_error($status)) {
        $wpdb->update($this->table, array(
          'order_tracking_id' => $orderTrackingId,
          'status' => is_object($status) ? ($status->status ?? json_encode($status)) : json_encode($status),
          'updated_at' => current_time('mysql',1)
        ), array('merchant_reference' => $merchantRef), array('%s','%s','%s'), array('%s'));
      }

      $target_url = '';
      $success = (!is_wp_error($status)) && $this->is_successful_status($status);
      $opts = $this->get_options();
      if ($success && !empty($opts['success_page'])) {
        $target_url = get_permalink($opts['success_page']);
      } elseif (!$success && !empty($opts['failure_page'])) {
        $target_url = get_permalink($opts['failure_page']);
      } else {
        if (!empty($merchantRef)) {
          $row = $wpdb->get_row($wpdb->prepare("SELECT return_url FROM {$this->table} WHERE merchant_reference = %s LIMIT 1", $merchantRef), ARRAY_A);
          if (!empty($row['return_url'])) $target_url = $row['return_url'];
        }
      }

      if (empty($target_url)) {
        status_header(200);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Payment callback</title><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="font-family:Arial,Helvetica,sans-serif;padding:20px;">';
        echo '<h2>Payment callback</h2>';
        echo '<p><strong>Merchant Reference:</strong> '.esc_html($merchantRef).'</p>';
        echo '<p><strong>OrderTrackingId:</strong> '.esc_html($orderTrackingId).'</p>';
        echo '<pre>'.esc_html(is_wp_error($status) ? $status->get_error_message() : json_encode($status)).'</pre>';
        echo '</body></html>'; exit;
      }

      $msg = '';
      if (is_wp_error($status)) {
        $msg = $status->get_error_message();
      } elseif (is_object($status) && !empty($status->message)) {
        $msg = (string)$status->message;
      } elseif (is_object($status)) {
        $msg = json_encode($status);
      } else {
        $msg = is_array($status) ? json_encode($status) : (string)$status;
      }
      $msg = wp_strip_all_tags($msg);

      $sep = (strpos($target_url, '?') === false) ? '?' : '&';
      $status_param = $success ? 'success' : 'failure';
      $redirect_to = $target_url . $sep . 'psp_status=' . rawurlencode($status_param) . '&psp_msg=' . rawurlencode($msg) . '&psp_merchant=' . rawurlencode($merchantRef) . '&psp_tracking=' . rawurlencode($orderTrackingId);

      wp_safe_redirect($redirect_to);
      exit;
    }

    if (isset($_GET['psp_ipn']) || !empty($_POST['OrderNotificationType']) || !empty($_GET['OrderNotificationType'])) {
      $method = $_SERVER['REQUEST_METHOD'];
      $data = $method === 'POST' ? $_POST : $_GET;
      $orderTrackingId = sanitize_text_field($data['OrderTrackingId'] ?? '');
      $merchantRef = sanitize_text_field($data['OrderMerchantReference'] ?? '');
      $opts = $this->get_options();
      $helper = new PSP_PesapalV30Helper($opts['mode'] === 'live' ? 'live' : 'sandbox');
      $token = $helper->getAccessToken($opts['consumer_key'], $opts['consumer_secret']);
      $status = is_wp_error($token) ? $token : $helper->getTransactionStatus($token, $orderTrackingId, $merchantRef);

      global $wpdb;
      $wpdb->update($this->table, array(
        'order_tracking_id' => $orderTrackingId,
        'status' => is_object($status) ? ($status->status ?? json_encode($status)) : json_encode($status),
        'updated_at' => current_time('mysql',1)
      ), array('merchant_reference' => $merchantRef), array('%s','%s','%s'), array('%s'));

      header("HTTP/1.1 200 OK"); echo 'OK'; exit;
    }
  }

  public function shortcode_payment_form($atts=array()) {
    $atts = shortcode_atts(array('amount'=>'','currency'=>$this->get_options()['default_currency'] ?? 'KES','description'=>'Payment'), $atts, 'pesapal_payment_form');
    ob_start();
    ?>
    <style>
    .psp-form { max-width:640px; width:100%; margin:18px auto; background:var(--psp-bg,#fff); border:1px solid var(--psp-border,#e6e6e6); padding:18px; border-radius:10px; box-shadow:0 6px 18px rgba(0,0,0,0.04); box-sizing:border-box; }
    .psp-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; align-items:start; }
    .psp-full { grid-column: 1 / -1; }
    .psp-label { display:block; font-size:13px; color:var(--psp-label,#333); margin-bottom:6px; font-weight:600; }
    .psp-input, .psp-select { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #d9d9d9; box-sizing:border-box; font-size:15px; background:var(--psp-input-bg,#fff); color:inherit; }
    .psp-button { display:inline-block; padding:12px 18px; border-radius:10px; border:none; font-size:16px; cursor:pointer; background:var(--psp-primary,#241a2f); color:#fff; box-shadow:0 6px 14px rgba(36,26,47,0.12); transition:transform .08s ease, opacity .12s ease; }
    .psp-button:active { transform:translateY(1px); }
    .psp-note { font-size:13px; color:#666; margin-left:12px; line-height:1.6; }
    .psp-row { display:flex; gap:12px; align-items:center; margin-top:8px; }
    @media (max-width:780px) {
      .psp-grid { grid-template-columns: 1fr; }
      .psp-row { flex-direction:column; align-items:stretch; }
      .psp-note { margin-left:0; margin-top:8px; }
      .psp-button { width:100%; padding:14px; font-size:17px; }
    }
    .psp-icons { display:flex; justify-content:center; margin-top:18px; }
    .psp-icons img { max-width:280px; width:100%; height:auto; opacity:0.95; display:block; }
    .psp-alert { padding:10px 12px; border-radius:8px; font-size:14px; margin-top:12px; }
    .psp-success { background:#e6ffef; border:1px solid #b8f1d1; color:#065f35; }
    .psp-fail { background:#fff2f2; border:1px solid #f1b3b3; color:#8a1d1d; }
    input[type=number]::-webkit-outer-spin-button, input[type=number]::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    </style>

    <form class="psp-form" id="psp-form-<?php echo esc_attr(uniqid()); ?>" onsubmit="return pspSubmitForm(this);" autocomplete="on" novalidate>
      <div class="psp-grid">
        <div>
          <label class="psp-label">Amount (<?php echo esc_html($atts['currency']); ?>)</label>
          <input class="psp-input" type="number" step="0.01" name="ps_amount" required value="<?php echo esc_attr($atts['amount']); ?>" placeholder="0.00">
        </div>

        <div>
          <label class="psp-label">Currency</label>
          <select class="psp-select" name="ps_currency" required aria-label="Currency">
            <?php
              $currs = $this->get_supported_currencies();
              $current = esc_attr($atts['currency']);
              foreach ($currs as $code => $label) {
                printf('<option value="%1$s" %2$s>%3$s</option>', esc_attr($code), selected($current, $code, false), esc_html($label));
              }
            ?>
          </select>
        </div>

        <div>
          <label class="psp-label">Email</label>
          <input class="psp-input" type="email" name="ps_email" required placeholder="you@example.com">
        </div>

        <div>
          <label class="psp-label">Phone (optional)</label>
          <input class="psp-input" type="text" name="ps_phone" placeholder="+2547xxxxxxxx">
        </div>

        <div>
          <label class="psp-label">First name</label>
          <input class="psp-input" type="text" name="ps_firstname" placeholder="First name">
        </div>

        <div>
          <label class="psp-label">Last name</label>
          <input class="psp-input" type="text" name="ps_lastname" placeholder="Last name">
        </div>

        <div class="psp-full">
          <div class="psp-row">
            <button class="psp-button" type="submit">Pay with PesaPal</button>
            <div class="psp-note" id="psp-msg" aria-live="polite"></div>
          </div>
        </div>

        <div class="psp-full psp-icons">
          <img src="<?php echo esc_url( plugins_url('Payments.png', __FILE__) ); ?>" alt="Supported payment methods">
        </div>

      </div>

      <input type="hidden" name="ps_description" value="<?php echo esc_attr($atts['description']); ?>">
    </form>

    <script>
    function pspSubmitForm(form) {
      var msg = document.getElementById('psp-msg');
      msg.textContent = '';
      var data = new FormData(form);
      data.append('ps_return_url', window.location.href);
      fetch('<?php echo esc_url(rest_url('psp/v1/submit')); ?>', {
        method:'POST',
        credentials:'same-origin',
        headers: {'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' },
        body: data
      }).then(function(r){ return r.json(); })
      .then(function(json){
        if (json.success && json.data && json.data.redirect_url) {
          window.location.href = json.data.redirect_url;
        } else {
          if (json.data && json.data.debug) {
            msg.textContent = 'Auth / Submit error. Debug info saved in plugin settings. ' + (json.data.message || '');
          } else {
            msg.textContent = (json.data && json.data.message) ? json.data.message : 'Unable to initiate payment';
          }
        }
      }).catch(function(err){
        msg.textContent = 'Request error: ' + err;
      });
      return false;
    }

    (function(){
      function getQueryParams() {
        var p = {};
        location.search.replace(/^\?/, '').split('&').forEach(function(kv){
          if (!kv) return;
          var parts = kv.split('=');
          p[decodeURIComponent(parts[0])] = parts[1] ? decodeURIComponent(parts[1].replace(/\+/g,' ')) : '';
        });
        return p;
      }
      var params = getQueryParams();
      if (params['psp_status']) {
        var form = document.querySelector('.psp-form');
        if (!form) return;
        var status = params['psp_status'];
        var message = params['psp_msg'] ? params['psp_msg'] : (status === 'success' ? 'Payment successful. Thank you.' : 'Payment failed.');
        message = message.replace(/<\/?[^>]+(>|$)/g, "");
        var div = document.createElement('div');
        div.className = 'psp-alert ' + (status === 'success' ? 'psp-success' : 'psp-fail');
        div.textContent = message;
        form.insertBefore(div, form.firstChild);
        if (window.history && window.history.replaceState) {
          var url = window.location.protocol + '//' + window.location.host + window.location.pathname;
          window.history.replaceState({}, document.title, url);
        }
      }
    })();
    </script>
    <?php
    return ob_get_clean();
  }

  public function render_transactions_page() {
    global $wpdb;
    $rows = $wpdb->get_results("SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT 500", ARRAY_A);
    echo '<div class="wrap"><h1>PesaPal Transactions</h1>';
    echo '<table class="widefat"><thead><tr><th>ID</th><th>Merchant Ref</th><th>Tracking Id</th><th>Amount</th><th>Currency</th><th>Status</th><th>Payer</th><th>Return URL</th><th>Created</th></tr></thead><tbody>';
    foreach($rows as $r) {
      echo '<tr>';
      echo '<td>'.esc_html($r['id']).'</td>';
      echo '<td>'.esc_html($r['merchant_reference']).'</td>';
      echo '<td>'.esc_html($r['order_tracking_id']).'</td>';
      echo '<td>'.esc_html($r['amount']).'</td>';
      echo '<td>'.esc_html($r['currency']).'</td>';
      echo '<td>'.esc_html($r['status']).'</td>';
      echo '<td>'.esc_html($r['payer_email']).'</td>';
      echo '<td style="max-width:240px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">'.esc_html($r['return_url'] ?? '') .'</td>';
      echo '<td>'.esc_html($r['created_at']).'</td>';
      echo '</tr>';
    }
    echo '</tbody></table></div>';
  }
}

new PSP_Standalone_Plugin();
