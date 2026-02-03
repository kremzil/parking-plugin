<?php
/**
 * Plugin Name: Zaparkuj (WP) – Parking Payments
 * Description: Payments with Barion, GeoJSON polygons, OSM/Leaflet maps, order history. Pricing (base_30 + daily caps). Full-featured parking service.
 * Version: 0.5.0
 * Author: Zaparkuj Team
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/i18n.php';

class Zaparkuj_WP_045 {
  const SLUG = 'zaparkuj-wp';
  const VERSION = '0.5.0';
  
  // Barion настройки (заменяют Stripe)
  const OPT_BARION_POSKEY = 'zp_barion_poskey';
  const OPT_BARION_ENV = 'zp_barion_environment';
  const OPT_BARION_PAYEE = 'zp_barion_payee_email';
  
  // Общие настройки
  const OPT_TAR  = 'zp_tariffs_json';
  const OPT_GJ   = 'zp_geojson_url';
  const OPT_MAP  = 'zp_map_provider';
  const OPT_TOL  = 'zp_geojson_tol_m';
  const OPT_CIRC = 'zp_enable_circles';

  public static function init(){
    // Подключение Barion Gateway
    require_once plugin_dir_path(__FILE__) . 'includes/barion-gateway.php';
    
    add_shortcode('zaparkuj', [__CLASS__,'shortcode']);
    add_action('wp_enqueue_scripts', [__CLASS__,'register_assets']);
    add_action('rest_api_init', [__CLASS__,'register_rest']);
    add_action('admin_menu', [__CLASS__, 'admin_menu']);
    add_action('admin_init', [__CLASS__, 'register_settings']);
    add_action('init', [__CLASS__, 'maybe_send_test_mail']);
  }
  
  /**
   * Создание таблиц при активации плагина
   */
  public static function activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    // Таблица транзакций
    $table_transactions = $wpdb->prefix . 'zp_transactions';
    $sql1 = "CREATE TABLE IF NOT EXISTS $table_transactions (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      barion_payment_id VARCHAR(255) DEFAULT NULL,
      order_number VARCHAR(100) NOT NULL,
      spz VARCHAR(20) NOT NULL,
      email VARCHAR(255) NOT NULL,
      zone_id VARCHAR(10) NOT NULL,
      lat DECIMAL(10,7) DEFAULT NULL,
      lng DECIMAL(10,7) DEFAULT NULL,
      minutes INT NOT NULL,
      amount_cents INT NOT NULL,
      status VARCHAR(20) DEFAULT 'pending',
      created_at DATETIME NOT NULL,
      paid_at DATETIME DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY barion_payment_id (barion_payment_id),
      KEY order_number (order_number),
      KEY spz (spz),
      KEY status (status),
      KEY email (email)
    ) $charset_collate;";
    
    // Таблица активных парковок
    $table_parkings = $wpdb->prefix . 'zp_parkings';
    $sql2 = "CREATE TABLE IF NOT EXISTS $table_parkings (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      transaction_id BIGINT(20) UNSIGNED NOT NULL,
      spz VARCHAR(20) NOT NULL,
      zone_id VARCHAR(10) NOT NULL,
      started_at DATETIME NOT NULL,
      expires_at DATETIME NOT NULL,
      extended_minutes INT DEFAULT 0,
      is_active TINYINT(1) DEFAULT 1,
      PRIMARY KEY (id),
      KEY transaction_id (transaction_id),
      KEY spz_active (spz, is_active),
      KEY expires_at (expires_at),
      KEY is_active (is_active)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql1);
    dbDelta($sql2);
    
    // Установить версию БД
    update_option('zp_db_version', self::VERSION);
  }

  public static function register_assets(){
    $base = plugin_dir_url(__FILE__);
    $v = self::VERSION;
    wp_register_style('zaparkuj-style', $base . 'assets/css/style.css', [], $v);
    wp_register_script('zaparkuj-bands', $base . 'assets/js/bands.js', [], $v, true);
    wp_register_script('zaparkuj-geo', $base . 'assets/js/geo.js', [], $v, true);
    wp_register_script('zaparkuj-checkout-barion', $base . 'assets/js/checkout-barion.js', [], $v, true);
  }

  public static function shortcode($atts = []){
    wp_enqueue_style('zaparkuj-style');
    wp_enqueue_script('zaparkuj-bands');
    wp_enqueue_script('zaparkuj-geo');
    wp_enqueue_script('zaparkuj-checkout-barion');

    $tariffs_arr = self::get_tariffs_array();
    $gj   = get_option(self::OPT_GJ, '');
    $map  = get_option(self::OPT_MAP, 'osm');
    $tolm = floatval(get_option(self::OPT_TOL, 25));
    $circ = !!get_option(self::OPT_CIRC, false);

    $cfg = [
      'rest' => [
        'barion_prepare' => esc_url_raw(rest_url('zaparkuj/v1/barion-prepare')),
        'barion_ipn' => esc_url_raw(rest_url('zaparkuj/v1/barion-ipn')),
      ],
      'geojson_url' => $gj,
      'tariffs' => $tariffs_arr,
      'map_provider' => ($map === 'osm' ? 'osm' : 'google'),
      'geojson_tolerance_m' => $tolm,
      'enable_circles' => $circ ? true : false,
      'default_center' => ['lat'=>48.7205,'lng'=>21.2575],
      'lang' => zp_get_lang(),
      'i18n' => zp_get_i18n(),
    ];

    ob_start(); ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

    <?php if ($map === 'osm') : ?>
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
      <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" crossorigin="anonymous">
      <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js" crossorigin="anonymous"></script>
      <style>#map-frame { height: 320px; border-radius: 10px; overflow: hidden; background: #f5f5f5; }</style>
    <?php else: ?>
      <style>#map-frame { height: 320px; border-radius: 10px; overflow: hidden; background: #f5f5f5; }</style>
    <?php endif; ?>

    <script type="application/json" id="zp-config"><?php echo wp_json_encode($cfg); ?></script>

    <!-- Geo Modal -->
    <div class="modal fade" id="geoModal" tabindex="-1" aria-hidden="true" style="background: rgba(0,0,0,0.7);">
      <div class="modal-dialog modal-dialog-centered"><div class="modal-content text-center">
        <div class="modal-header"><h5 class="modal-title"><?php echo esc_html(zp_t('modal_title')); ?></h5></div>
        <div class="modal-body">
          <p><?php echo esc_html(zp_t('modal_body')); ?></p>

          <div class="d-grid gap-2 mt-3">
            <button type="button" id="zp-modal-pick" class="btn btn-outline-secondary">
              <?php echo esc_html(zp_t('modal_pick')); ?>
            </button>
          </div>

          <p id="geo-wait-msg" class="text-muted mt-3" style="display:none"><?php echo esc_html(zp_t('modal_wait')); ?></p>
        </div>

      </div></div>
    </div>

    <div class="container" style="max-width:720px">
      <div style="height:72px;display:flex;align-items:center;justify-content:space-between;margin:12px 0;position:relative;width:100%;">
        <img src="https://www.pkosice.sk/wp-content/uploads/2025/08/pkosice-LOGO-03.png" alt="pkosice" style="height:72px;width:auto;">
        <div id="zp-lang-switcher" data-fixed="1" style="display:flex;align-items:center;gap:8px;margin-left:auto;position:static;">
          <select id="zp-lang" class="form-select form-select-sm" style="width:auto">
            <option value="sk"><?php echo esc_html(zp_t('lang_sk')); ?></option>
            <option value="en"><?php echo esc_html(zp_t('lang_en')); ?></option>
            <option value="pl"><?php echo esc_html(zp_t('lang_pl')); ?></option>
            <option value="hu"><?php echo esc_html(zp_t('lang_hu')); ?></option>
            <option value="sv"><?php echo esc_html(zp_t('lang_sv')); ?></option>
            <option value="zh"><?php echo esc_html(zp_t('lang_zh')); ?></option>
          </select>
        </div>
      </div>
      <div class="Content" style="margin-bottom: 60px;">
      <div id="zone-result" class="alert alert-info"><?php echo esc_html(zp_t('zone_detecting')); ?></div>
        <div>
          <label class="form-label"><?php echo esc_html(zp_t('label_map')); ?></label>
          <div id="map-frame"></div>
          <div class="d-flex gap-2 mt-2">
            <button type="button" id="zp-pick" class="btn btn-outline-secondary btn-sm"><?php echo esc_html(zp_t('button_pick_map')); ?></button>
          </div>
          <div class="form-text" id="zp-ll-help"></div>
          <?php if ($map !== 'osm'): ?><p class="text-muted small mt-1"><?php echo esc_html(zp_t('tip_osm')); ?></p><?php endif; ?>
        </div>
        
      <form id="zp-form" class="d-grid gap-3 mt-4">
        <input type="hidden" id="zp-lat">
        <input type="hidden" id="zp-lng">
        <input type="hidden" id="band">

        <div>
          <label class="form-label" for="zp-spz"><?php echo esc_html(zp_t('label_spz')); ?></label>
          <input id="zp-spz" class="form-control" placeholder="<?php echo esc_attr(zp_t('placeholder_spz')); ?>" required>
        </div>

        <div>
          <label class="form-label" for="duration"><?php echo esc_html(zp_t('label_duration')); ?></label>
          <select id="duration" class="form-select">
            <option value="30"><?php echo esc_html(zp_t('duration_30')); ?></option>
            <option value="60" selected><?php echo esc_html(zp_t('duration_60')); ?></option>
            <option value="120"><?php echo esc_html(zp_t('duration_120')); ?></option>
            <option value="480"><?php echo esc_html(zp_t('duration_480')); ?></option>
            <option value="1440"><?php echo esc_html(zp_t('duration_1440')); ?></option>
          </select>
        </div>

        <div>
          <label class="form-label" for="zp-email"><?php echo esc_html(zp_t('label_email')); ?></label>
          <input id="zp-email" type="email" class="form-control" placeholder="<?php echo esc_attr(zp_t('placeholder_email')); ?>" required>
        </div>



        <div id="zp-payment-element" class="mt-3"></div>

        <div class="d-flex justify-content-between align-items-center">
          <div><strong><?php echo esc_html(zp_t('price_label')); ?></strong> <span id="zp-price">—</span></div>
        </div>
        <div class="form-text"><?php echo esc_html(zp_t('payment_note')); ?></div>

        <button id="zp-pay" type="submit" class="btn btn-primary btn-lg"><?php echo esc_html(zp_t('pay_button')); ?></button>
      </form>
    </div>
    <?php
    return ob_get_clean();
  }

  public static function get_tariffs_array(){
    $json = get_option(self::OPT_TAR, '');
    if (!$json) {
      return [
        'zones' => [
          ['id'=>'A1','label'=>'A1','base_30'=>1.5,'daily_cap'=>24.0],
          ['id'=>'A2','label'=>'A2','base_30'=>1.0,'daily_cap'=>16.0],
          ['id'=>'BN','label'=>'BN','base_30'=>0.5,'daily_cap'=>6.0],
          ['id'=>'N','label'=>'N','base_30'=>0.3,'daily_cap'=>3.0],
          ['id'=>'B','label'=>'B','base_30'=>0.5,'daily_cap'=>null],
        ]
      ];
    }
    $arr = json_decode($json, true);
    return is_array($arr) ? $arr : ['zones'=>[]];
  }

  public static function calc_price_cents($zone_id, $minutes){
    $zones = self::get_tariffs_array()['zones'];
    $z = null;
    foreach($zones as $zz){
      if (strtoupper($zz['id']) === strtoupper($zone_id)) { $z = $zz; break; }
    }
    if (!$z || !$minutes) return 0;
    if (isset($z['base_30'])) {
      $base = floatval($z['base_30']);
      $cap  = isset($z['daily_cap']) && $z['daily_cap'] !== null ? floatval($z['daily_cap']) : null;
      $fullDays = intdiv(intval($minutes), 1440);
      $remMin   = intval($minutes) % 1440;
      $blocks   = (int)ceil($remMin / 30);
      $partDay  = $blocks * $base;
      if ($cap !== null) $partDay = min($partDay, $cap);
      $total = $fullDays * ($cap !== null ? $cap : (48 * $base)) + $partDay;
      return (int)round($total * 100);
    }
    $minc = max(0, isset($z['min_charge_minutes']) ? intval($z['min_charge_minutes']) : 0);
    $step = max(1, isset($z['billing_increment']) ? intval($z['billing_increment']) : 15);
    $m    = max($minc, (int)ceil(intval($minutes)/$step)*$step);
    $rate_per_min = isset($z['rate_per_min']) ? floatval($z['rate_per_min']) : ( isset($z['rate_per_hour']) ? (floatval($z['rate_per_hour'])/60.0) : 0.0 );
    return (int)round($m * $rate_per_min * 100);
  }

  public static function register_rest(){
    // Barion endpoints
    register_rest_route('zaparkuj/v1', '/barion-prepare', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [__CLASS__, 'rest_barion_prepare'],
    ]);
    register_rest_route('zaparkuj/v1', '/barion-ipn', [
      'methods'  => 'POST',
      'permission_callback' => '__return_true',
      'callback' => [__CLASS__, 'rest_barion_ipn'],
    ]);
    register_rest_route('zaparkuj/v1', '/debug', [
      'methods'=>'GET',
      'permission_callback' => function(){ return current_user_can('manage_options'); },
      'callback' => function(){
        return [
          'has_pk' => !!get_option(self::OPT_PK, ''),
          'has_sk' => !!get_option(self::OPT_SK, ''),
          'has_whsec' => !!get_option(self::OPT_WH, ''),
          'geojson_url' => get_option(self::OPT_GJ, ''),
          'map_provider' => get_option(self::OPT_MAP, 'google'),
          'tol_m' => get_option(self::OPT_TOL, 25),
          'enable_circles' => !!get_option(self::OPT_CIRC, false),
          'tariffs_preview' => substr(get_option(self::OPT_TAR, ''), 0, 2000)
        ];
      }
    ]);
  }

  public static function rest_session(WP_REST_Request $r){
    $p = json_decode($r->get_body(), true);
    if (!is_array($p)) return new WP_Error('bad_json', 'Bad JSON body', ['status'=>400]);
    $spz     = trim(strtoupper($p['spz'] ?? ''));
    $email   = trim($p['email'] ?? '');
    $zone_id = strtoupper(trim($p['zone_id'] ?? ''));
    $minutes = intval($p['minutes'] ?? 0);
    $lat     = isset($p['lat']) ? floatval($p['lat']) : null;
    $lng     = isset($p['lng']) ? floatval($p['lng']) : null;
    $lang    = isset($p['lang']) ? strtolower(sanitize_text_field($p['lang'])) : zp_get_lang();
    if (!in_array($lang, ['sk','en','pl','hu','sv','zh'], true)) $lang = zp_get_lang();
    if (!$spz || !$email || !$zone_id || !$minutes) return new WP_Error('missing_params','Missing required fields', ['status'=>422]);
    $amount_cents = self::calc_price_cents($zone_id, $minutes);
    if ($amount_cents < 50) return new WP_Error('amount_too_low','Amount too low', ['status'=>422]);
    $sk = get_option(self::OPT_SK, '');
    if (!$sk) return new WP_Error('no_sk', 'Stripe secret key missing', ['status'=>500]);

    $body = [
      'amount' => $amount_cents,
      'currency' => 'eur',
      'automatic_payment_methods[enabled]' => 'true',
      'receipt_email' => $email,
      'metadata[spz]' => $spz,
      'metadata[zone_id]' => $zone_id,
      'metadata[minutes]' => $minutes,
      'metadata[lat]' => $lat,
      'metadata[lng]' => $lng,
    ];
    $res = wp_remote_post('https://api.stripe.com/v1/payment_intents', [
      'timeout' => 45,
      'headers' => ['Authorization' => 'Bearer ' . $sk],
      'body'    => $body,
    ]);
    if (is_wp_error($res)) {
      error_log('[Zaparkuj] PI create error: ' . $res->get_error_message());
      return new WP_Error('stripe_error', 'Stripe request failed', ['status'=>500]);
    }
    $code = wp_remote_retrieve_response_code($res);
    $json = json_decode(wp_remote_retrieve_body($res), true);
    if ($code < 200 || $code >= 300 || !isset($json['client_secret'])) {
      error_log('[Zaparkuj] PI bad response: ' . wp_remote_retrieve_body($res));
      return new WP_Error('stripe_bad_response', 'Stripe bad response', ['status'=>500]);
    }
    return ['client_secret' => $json['client_secret'],'amount_cents'  => $amount_cents];
  }

  public static function rest_stub_mail(WP_REST_Request $r){
    $p = json_decode($r->get_body(), true);
    if (!is_array($p)) return new WP_Error('bad_json', 'Bad JSON body', ['status'=>400]);
    $email   = sanitize_email($p['email'] ?? '');
    if (!$email) return new WP_Error('bad_email', 'Invalid email', ['status'=>422]);
    $spz     = trim(strtoupper($p['spz'] ?? 'TEST123'));
    $zone_id = strtoupper(trim($p['zone_id'] ?? 'A2'));
    $minutes = intval($p['minutes'] ?? 60);
    $lat     = isset($p['lat']) ? floatval($p['lat']) : null;
    $lng     = isset($p['lng']) ? floatval($p['lng']) : null;
    $lang    = isset($p['lang']) ? strtolower(sanitize_text_field($p['lang'])) : zp_get_lang();
    if (!in_array($lang, ['sk','en','pl','hu','sv','zh'], true)) $lang = zp_get_lang();
    $amount_cents = self::calc_price_cents($zone_id, $minutes);
    if ($amount_cents <= 0) $amount_cents = 200;
    require_once plugin_dir_path(__FILE__) . 'includes/mail-sender.php';
    $ok = zp_send_receipt($email, compact('spz','zone_id','minutes','amount_cents','lat','lng','lang'));
    if (!$ok) return new WP_Error('mail_failed','Mail send failed', ['status'=>500]);
    return ['ok'=>true];
  }

  public static function rest_webhook(WP_REST_Request $r){
    $sig = isset($_SERVER['HTTP_STRIPE_SIGNATURE']) ? $_SERVER['HTTP_STRIPE_SIGNATURE'] : '';
    $body = $r->get_body();
    error_log('[Zaparkuj] webhook hit: ' . substr($body,0,400));
    $whsec = get_option(self::OPT_WH, '');
    if ($whsec && !self::verify_stripe_signature($whsec, $sig, $body)) {
      return new WP_Error('bad_sig','Invalid Stripe signature', ['status'=>400]);
    } elseif(!$whsec) {
      error_log('[Zaparkuj] webhook without signing secret – accepting for dev');
    }
    $event = json_decode($body, true);
    if (!is_array($event) || empty($event['type'])) return new WP_Error('bad_event','Invalid event JSON', ['status'=>400]);
    error_log('[Zaparkuj] webhook verified type=' . $event['type']);
    if ($event['type'] === 'payment_intent.succeeded') {
      $pi = $event['data']['object'];
      $email = $pi['receipt_email'] ?? ($pi['charges']['data'][0]['billing_details']['email'] ?? '');
      $spz = $pi['metadata']['spz'] ?? '';
      $zone_id = $pi['metadata']['zone_id'] ?? '';
      $minutes = isset($pi['metadata']['minutes']) ? intval($pi['metadata']['minutes']) : 0;
      $amount_cents = isset($pi['amount']) ? intval($pi['amount']) : 0;
      $lat = isset($pi['metadata']['lat']) ? $pi['metadata']['lat'] : null;
      $lng = isset($pi['metadata']['lng']) ? $pi['metadata']['lng'] : null;
      if ($email) {
        require_once plugin_dir_path(__FILE__) . 'includes/mail-sender.php';
        zp_send_receipt($email, compact('spz','zone_id','minutes','amount_cents','lat','lng'));
      } else {
        error_log('[Zaparkuj] webhook: no email in PI to send receipt');
      }
    }
    return ['ok'=>true];
  }

  private static function parse_sig_header($sig_header){
    $parts = [];
    foreach (explode(',', $sig_header) as $kv) {
      $kv = trim($kv);
      if (strpos($kv, '=') === false) continue;
      list($k,$v) = explode('=', $kv, 2);
      $parts[$k] = $v;
    }
    return $parts;
  }
  private static function verify_stripe_signature($secret, $sig_header, $payload){
    if (!$sig_header) return false;
    $p = self::parse_sig_header($sig_header);
    if (empty($p['t']) || empty($p['v1'])) return false;
    $signed_payload = $p['t'] . '.' . $payload;
    $computed = hash_hmac('sha256', $signed_payload, $secret);
    if (function_exists('hash_equals')) return hash_equals($computed, $p['v1']);
    return $computed === $p['v1'];
  }

  public static function admin_menu(){
    add_menu_page(
      'Zaparkuj',
      'Zaparkuj',
      'manage_options',
      self::SLUG,
      [__CLASS__, 'orders_page'],
      'dashicons-car',
      30
    );
    add_submenu_page(
      self::SLUG,
      'Objednávky',
      'Objednávky',
      'manage_options',
      self::SLUG,
      [__CLASS__, 'orders_page']
    );
    add_submenu_page(
      self::SLUG,
      'Nastavenia',
      'Nastavenia',
      'manage_options',
      self::SLUG . '-settings',
      [__CLASS__, 'settings_page']
    );
  }
  public static function register_settings(){
    $group = self::SLUG . '-settings';
    register_setting($group, self::OPT_BARION_POSKEY);
    register_setting($group, self::OPT_BARION_ENV);
    register_setting($group, self::OPT_BARION_PAYEE);
    register_setting($group, self::OPT_TAR);
    register_setting($group, self::OPT_GJ);
    register_setting($group, self::OPT_MAP);
    register_setting($group, self::OPT_TOL);
    register_setting($group, self::OPT_CIRC);
  }
  public static function settings_page(){ ?>
    <div class="wrap">
      <h1>Zaparkuj – Nastavenia (v<?php echo self::VERSION; ?>)</h1>
      <form method="post" action="options.php">
        <?php settings_fields(self::SLUG . '-settings'); ?>
        
        <h2>Barion Platobná brána</h2>
        <table class="form-table" role="presentation">
          <tr><th scope="row"><label for="<?php echo esc_attr(self::OPT_BARION_POSKEY); ?>">POSKey *</label></th>
            <td>
              <input name="<?php echo esc_attr(self::OPT_BARION_POSKEY); ?>" id="<?php echo esc_attr(self::OPT_BARION_POSKEY); ?>" type="text" class="regular-text" value="<?php echo esc_attr(get_option(self::OPT_BARION_POSKEY,'')); ?>">
              <p class="description">Získajte na <a href="https://secure.barion.com/" target="_blank">secure.barion.com</a> → My stores → Create new store</p>
            </td>
          </tr>
          <tr><th scope="row"><label for="<?php echo esc_attr(self::OPT_BARION_ENV); ?>">Prostredie</label></th>
            <td>
              <select name="<?php echo esc_attr(self::OPT_BARION_ENV); ?>" id="<?php echo esc_attr(self::OPT_BARION_ENV); ?>">
                <?php $env = get_option(self::OPT_BARION_ENV, 'test'); ?>
                <option value="test" <?php selected($env, 'test'); ?>>Test (api.test.barion.com)</option>
                <option value="prod" <?php selected($env, 'prod'); ?>>Produkcia (api.barion.com)</option>
              </select>
              <p class="description">Použite Test pre vývoj a testovanie</p>
            </td>
          </tr>
          <tr><th scope="row"><label for="<?php echo esc_attr(self::OPT_BARION_PAYEE); ?>">Payee Email</label></th>
            <td>
              <input name="<?php echo esc_attr(self::OPT_BARION_PAYEE); ?>" id="<?php echo esc_attr(self::OPT_BARION_PAYEE); ?>" type="email" class="regular-text" value="<?php echo esc_attr(get_option(self::OPT_BARION_PAYEE, get_bloginfo('admin_email'))); ?>">
              <p class="description">Email Barion účtu, na ktorý prídu platby</p>
            </td>
          </tr>
        </table>
        
        <h2>Mapa a zóny</h2>
        <table class="form-table" role="presentation">
          <tr><th scope="row"><label for="<?php echo esc_attr(self::OPT_GJ); ?>">GeoJSON URL</label></th>
            <td><input name="<?php echo esc_attr(self::OPT_GJ); ?>" id="<?php echo esc_attr(self::OPT_GJ); ?>" type="url" class="regular-text" value="<?php echo esc_attr(get_option(self::OPT_GJ,'')); ?>"><p class="description">Upload <code>zones.geojson</code> and paste URL.</p></td></tr>
          <tr><th scope="row"><label for="<?php echo esc_attr(self::OPT_MAP); ?>">Map provider</label></th>
            <td>
              <select name="<?php echo esc_attr(self::OPT_MAP); ?>" id="<?php echo esc_attr(self::OPT_MAP); ?>">
                <?php $val = get_option(self::OPT_MAP, 'google'); ?>
                <option value="google" <?php selected($val, 'google'); ?>>Google (iframe)</option>
                <option value="osm" <?php selected($val, 'osm'); ?>>OpenStreetMap (Leaflet)</option>
              </select>
              <p class="description">OSM: karta sa načíta hneď (mock1), je dostupné hľadanie adresy a ručný výber bodu.</p>
            </td></tr>
          <tr><th scope="row"><label for="<?php echo esc_attr(self::OPT_TOL); ?>">GeoJSON tolerance (m)</label></th>
            <td><input name="<?php echo esc_attr(self::OPT_TOL); ?>" id="<?php echo esc_attr(self::OPT_TOL); ?>" type="number" step="1" min="0" class="small-text" value="<?php echo esc_attr(get_option(self::OPT_TOL, 25)); ?>">
              <p class="description">Допуск к границе полигона.</p>
            </td></tr>
          <tr><th scope="row"><label for="<?php echo esc_attr(self::OPT_CIRC); ?>">Enable circle fallback</label></th>
            <td><label><input name="<?php echo esc_attr(self::OPT_CIRC); ?>" id="<?php echo esc_attr(self::OPT_CIRC); ?>" type="checkbox" value="1" <?php checked(!!get_option(self::OPT_CIRC,false)); ?>> Использовать круги как запасной вариант</label></td></tr>
          <tr><th scope="row"><label for="<?php echo esc_attr(self::OPT_TAR); ?>">Tariffs JSON</label></th>
            <td><textarea name="<?php echo esc_attr(self::OPT_TAR); ?>" id="<?php echo esc_attr(self::OPT_TAR); ?>" class="large-text code" rows="10"><?php echo esc_textarea(get_option(self::OPT_TAR,'')); ?></textarea>
            <p class="description">Example: {"zones":[{"id":"A2","base_30":1.0,"daily_cap":16}]}</p></td></tr>
        </table>
        <?php submit_button(); ?>
      </form>
        </table>
        <?php submit_button('Uložiť nastavenia'); ?>
      </form>
      
      <hr>
      <h2>IPN Callback URL</h2>
      <p>Nastavte v Barion Dashboard:</p>
      <code><?php echo esc_html(rest_url('zaparkuj/v1/barion-ipn')); ?></code>
      
      <hr>
      <h2>Testovacia karta</h2>
      <p>Pre test prostredie Barion:</p>
      <ul>
        <li>Číslo: <code>5559 0574 4061 2346</code></li>
        <li>Expirácia: <code>12/28</code></li>
        <li>CVC: <code>123</code></li>
        <li>3D Secure heslo: <code>bws</code></li>
      </ul>
    </div>
  <?php }
  
  /**
   * Stránka histórie objednávok
   */
  public static function orders_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'zp_transactions';
    
    // Filtrovanie
    $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
    
    // Pagination
    $per_page = 20;
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($page - 1) * $per_page;
    
    // Query
    $where = ['1=1'];
    if ($status_filter) {
      $where[] = $wpdb->prepare("status = %s", $status_filter);
    }
    if ($search) {
      $where[] = $wpdb->prepare("(spz LIKE %s OR email LIKE %s OR order_number LIKE %s)", 
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%',
        '%' . $wpdb->esc_like($search) . '%'
      );
    }
    $where_sql = implode(' AND ', $where);
    
    $total = $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE $where_sql");
    $orders = $wpdb->get_results(
      "SELECT * FROM $table WHERE $where_sql ORDER BY created_at DESC LIMIT $offset, $per_page"
    );
    
    $total_pages = ceil($total / $per_page);
    
    ?>
    <div class="wrap">
      <h1>Zaparkuj - Objednávky</h1>
      
      <div style="margin: 20px 0;">
        <form method="get">
          <input type="hidden" name="page" value="<?php echo esc_attr(self::SLUG); ?>">
          <input type="text" name="s" value="<?php echo esc_attr($search); ?>" placeholder="Hľadať ŠPZ, email, číslo objednávky...">
          <select name="status">
            <option value="">Všetky stavy</option>
            <option value="pending" <?php selected($status_filter, 'pending'); ?>>Čakajúca</option>
            <option value="completed" <?php selected($status_filter, 'completed'); ?>>Zaplatená</option>
            <option value="failed" <?php selected($status_filter, 'failed'); ?>>Zlyhala</option>
          </select>
          <button type="submit" class="button">Filtrovať</button>
          <?php if ($search || $status_filter): ?>
            <a href="<?php echo admin_url('admin.php?page=' . self::SLUG); ?>" class="button">Resetovať</a>
          <?php endif; ?>
        </form>
      </div>
      
      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Dátum</th>
            <th>ŠPZ</th>
            <th>Email</th>
            <th>Zóna</th>
            <th>Trvanie</th>
            <th>Suma</th>
            <th>Stav</th>
            <th>Barion ID</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($orders)): ?>
            <tr><td colspan="9">Žiadne objednávky</td></tr>
          <?php else: ?>
            <?php foreach ($orders as $order): ?>
              <tr>
                <td><?php echo esc_html($order->id); ?></td>
                <td><?php echo esc_html($order->created_at); ?></td>
                <td><strong><?php echo esc_html($order->spz); ?></strong></td>
                <td><?php echo esc_html($order->email); ?></td>
                <td><?php echo esc_html($order->zone_id); ?></td>
                <td><?php echo esc_html($order->minutes); ?> min</td>
                <td><strong><?php echo number_format($order->amount_cents / 100, 2); ?> €</strong></td>
                <td>
                  <?php
                  $badges = [
                    'pending' => '<span style="background:#ffc107;padding:3px 8px;border-radius:3px;color:#000;">Čakajúca</span>',
                    'completed' => '<span style="background:#28a745;padding:3px 8px;border-radius:3px;color:#fff;">Zaplatená</span>',
                    'failed' => '<span style="background:#dc3545;padding:3px 8px;border-radius:3px;color:#fff;">Zlyhala</span>',
                  ];
                  echo $badges[$order->status] ?? esc_html($order->status);
                  ?>
                </td>
                <td>
                  <small><?php echo $order->barion_payment_id ? esc_html(substr($order->barion_payment_id, 0, 20)) . '...' : '-'; ?></small>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
      
      <?php if ($total_pages > 1): ?>
        <div class="tablenav">
          <div class="tablenav-pages">
            <?php
            echo paginate_links([
              'base' => add_query_arg('paged', '%#%'),
              'format' => '',
              'prev_text' => '&laquo;',
              'next_text' => '&raquo;',
              'total' => $total_pages,
              'current' => $page
            ]);
            ?>
          </div>
        </div>
      <?php endif; ?>
      
      <p style="margin-top: 20px;">
        <strong>Celkom:</strong> <?php echo $total; ?> objednávok
      </p>
    </div>
    <?php
  }
  
  /**
   * REST endpoint pre prípravu Barion platby
   */
  public static function rest_barion_prepare(WP_REST_Request $r) {
    $p = json_decode($r->get_body(), true);
    if (!is_array($p)) {
      return new WP_Error('bad_json', 'Bad JSON body', ['status'=>400]);
    }
    
    $spz     = trim(strtoupper($p['spz'] ?? ''));
    $email   = trim($p['email'] ?? '');
    $zone_id = strtoupper(trim($p['zone_id'] ?? ''));
    $minutes = intval($p['minutes'] ?? 0);
    $lat     = isset($p['lat']) ? floatval($p['lat']) : null;
    $lng     = isset($p['lng']) ? floatval($p['lng']) : null;
    
    // Validácia
    if (!$spz || strlen($spz) < 2) {
      return new WP_Error('invalid_spz', zp_t('err_spz_required'), ['status'=>422]);
    }
    if (!$email || !is_email($email)) {
      return new WP_Error('invalid_email', zp_t('err_email_required'), ['status'=>422]);
    }
    if (!$zone_id) {
      return new WP_Error('invalid_zone', zp_t('err_zone_required'), ['status'=>422]);
    }
    if (!$minutes || $minutes < 1) {
      return new WP_Error('invalid_minutes', zp_t('err_minutes_required'), ['status'=>422]);
    }
    
    // Prepočítať cenu na serveri (bezpečnosť)
    $amount_cents = self::calc_price_cents($zone_id, $minutes);
    
    if ($amount_cents < 50) {
      return new WP_Error('amount_too_low', zp_t('err_amount_min'), ['status'=>422]);
    }
    
    // Kontrola Barion nastavení
    $poskey = get_option(self::OPT_BARION_POSKEY, '');
    if (!$poskey) {
      return new WP_Error('no_barion_config', zp_t('err_barion_not_configured'), ['status'=>500]);
    }
    
    try {
      $result = ZP_Barion_Gateway::prepare_payment([
        'spz' => $spz,
        'email' => $email,
        'zone_id' => $zone_id,
        'minutes' => $minutes,
        'lat' => $lat,
        'lng' => $lng,
        'amount_cents' => $amount_cents,
        'lang' => $lang
      ]);
      
      if (!$result['success']) {
        return new WP_Error('barion_error', $result['error'], ['status'=>500]);
      }
      
      return new WP_REST_Response([
        'success' => true,
        'paymentId' => $result['paymentId'],
        'redirectUrl' => $result['redirectUrl'],
        'orderNumber' => $result['orderNumber'],
        'amount_eur' => $amount_cents / 100
      ], 200);
      
    } catch (Exception $e) {
      error_log('ZP Barion REST error: ' . $e->getMessage());
      return new WP_Error('server_error', $e->getMessage(), ['status'=>500]);
    }
  }
  
  /**
   * REST endpoint pre Barion IPN
   */
  public static function rest_barion_ipn(WP_REST_Request $r) {
    $payment_id = $r->get_param('PaymentId');
    
    if (!$payment_id) {
      return new WP_Error('no_payment_id', 'PaymentId missing', ['status' => 400]);
    }
    
    try {
      $state = ZP_Barion_Gateway::get_payment_state($payment_id);
      
      if ($state['success'] && $state['status'] === 'Succeeded') {
        ZP_Barion_Gateway::handle_successful_payment($payment_id, $state['orderNumber']);
      }
      
      return new WP_REST_Response(['received' => true], 200);
    } catch (Exception $e) {
      error_log('ZP IPN error: ' . $e->getMessage());
      return new WP_REST_Response(['received' => true], 200); // Vždy vrátiť 200 pre IPN
    }
  }

  public static function maybe_send_test_mail(){
    if (!is_user_logged_in() || !current_user_can('manage_options')) return;
    if (!isset($_GET['test_mail']) || $_GET['test_mail'] !== '1') return;
    require_once plugin_dir_path(__FILE__) . 'includes/mail-sender.php';
    $email = wp_get_current_user()->user_email;
    if (!$email) $email = get_option('admin_email');
    $ok = zp_send_receipt($email, ['spz'=>'TEST123','zone_id'=>'A2','minutes'=>60,'amount_cents'=>200,'lat'=>48.7205,'lng'=>21.2575]);
    if (defined('WP_CLI') && WP_CLI) { WP_CLI::log($ok ? 'Test mail sent.' : 'Test mail failed.'); }
    else { wp_die($ok ? 'Test mail sent. Check your inbox.' : 'Test mail failed. See error log.'); }
  }
}

// Aktivačný hook
register_activation_hook(__FILE__, ['Zaparkuj_WP_045', 'activate']);

// Inicializácia
require_once plugin_dir_path(__FILE__) . 'includes/mail-sender.php';
Zaparkuj_WP_045::init();
