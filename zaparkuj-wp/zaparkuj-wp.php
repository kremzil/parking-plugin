<?php
/**
 * Plugin Name: Zaparkuj (WP) – Parking Payments
 * Description: Payments with Barion, GeoJSON polygons, OSM/Leaflet maps, order history. Pricing (base_30 + daily caps). Full-featured parking service.
 * Version: 0.5.0
 * Author: Zaparkuj Team
 */

if (!defined('ABSPATH')) exit;

require_once plugin_dir_path(__FILE__) . 'includes/i18n.php';
require_once plugin_dir_path(__FILE__) . 'includes/easypark-integration.php';

class Zaparkuj_WP_045 {
  const SLUG = 'zaparkuj-wp';
  const VERSION = '0.5.0';
  
  // Barion настройки
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
    ZP_EasyPark_Integration::init();
    
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
    ZP_EasyPark_Integration::create_tables();
    
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
    $lang = zp_get_lang();
    $gj   = get_option(self::OPT_GJ, '');
    $map  = get_option(self::OPT_MAP, 'osm');
    $tolm = floatval(get_option(self::OPT_TOL, 25));
    $circ = !!get_option(self::OPT_CIRC, false);

    // Payment success data for the "success" screen (when returning from Barion redirect).
    $payment_success = null;
    $email_preview_html = null;
    if (isset($_GET['zp_payment']) && $_GET['zp_payment'] === 'success' && !empty($_GET['pid'])) {
      global $wpdb;
      $pid = sanitize_text_field($_GET['pid']);
      $table_transactions = $wpdb->prefix . 'zp_transactions';
      $table_parkings = $wpdb->prefix . 'zp_parkings';

      $tx = $wpdb->get_row(
        $wpdb->prepare("SELECT * FROM $table_transactions WHERE barion_payment_id = %s LIMIT 1", $pid),
        ARRAY_A
      );
      if (is_array($tx) && ($tx['status'] ?? '') === 'completed') {
        $pk = $wpdb->get_row(
          $wpdb->prepare("SELECT * FROM $table_parkings WHERE transaction_id = %d ORDER BY id DESC LIMIT 1", intval($tx['id'])),
          ARRAY_A
        );

        $zone_label = $tx['zone_id'] ?? '';
        $zone_cfg = null;
        $zones = is_array($tariffs_arr) && isset($tariffs_arr['zones']) && is_array($tariffs_arr['zones']) ? $tariffs_arr['zones'] : [];
        foreach ($zones as $z) {
          $zid = isset($z['id']) ? strtoupper(trim((string)$z['id'])) : '';
          if ($zid && strtoupper((string)($tx['zone_id'] ?? '')) === $zid) {
            $zone_label = $z['label'] ?? $z['id'];
            $zone_cfg = $z;
            break;
          }
        }

        $zone_id = isset($tx['zone_id']) ? strtoupper((string)$tx['zone_id']) : '';
        if ($zone_id) {
          $zone_key = 'zone_name_' . strtolower($zone_id);
          $zone_i18n = zp_t($zone_key, $lang);
          if ($zone_i18n !== $zone_key) $zone_label = $zone_i18n;
        }

        $payment_success = [
          'paymentId' => $tx['barion_payment_id'] ?? $pid,
          'orderNumber' => $tx['order_number'] ?? null,
          'transactionDbId' => isset($tx['id']) ? intval($tx['id']) : null,
          'spz' => $tx['spz'] ?? '',
          'email' => $tx['email'] ?? '',
          'zone_id' => $tx['zone_id'] ?? '',
          'zone_label' => $zone_label,
          'minutes' => isset($tx['minutes']) ? intval($tx['minutes']) : null,
          'amount_cents' => isset($tx['amount_cents']) ? intval($tx['amount_cents']) : null,
          'paid_at' => $tx['paid_at'] ?? null,
          'started_at' => is_array($pk) ? ($pk['started_at'] ?? null) : null,
          'expires_at' => is_array($pk) ? ($pk['expires_at'] ?? null) : null,
          'zone_cfg' => $zone_cfg,
        ];

        if (function_exists('zp_render_email_template') && function_exists('zp_build_receipt_template_vars')) {
          $lang = $lang ?: zp_get_lang();
          $email_preview_html = zp_render_email_template(zp_build_receipt_template_vars([
            'lang' => $lang,
            'spz' => $tx['spz'] ?? '',
            'zone_id' => $tx['zone_id'] ?? '',
            'minutes' => $tx['minutes'] ?? '',
            'amount_cents' => $tx['amount_cents'] ?? 0,
            'paid_at' => $tx['paid_at'] ?? current_time('mysql'),
            'payment_id' => $tx['barion_payment_id'] ?? $pid,
            'order_number' => $tx['order_number'] ?? '',
            'started_at' => is_array($pk) ? ($pk['started_at'] ?? null) : null,
            'expires_at' => is_array($pk) ? ($pk['expires_at'] ?? null) : null,
            'lat' => $tx['lat'] ?? '',
            'lng' => $tx['lng'] ?? '',
          ]));
        }
      }
    }

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
      'lang' => $lang,
      'i18n' => zp_get_i18n(),
      'payment_success' => $payment_success,
      'email_preview_html' => $email_preview_html,
    ];

    ob_start(); ?>
    <?php if ($map === 'osm') : ?>
      <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="anonymous">
      <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin="anonymous"></script>
      <link rel="stylesheet" href="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.css" crossorigin="anonymous">
      <script src="https://unpkg.com/leaflet-control-geocoder/dist/Control.Geocoder.js" crossorigin="anonymous"></script>
    <?php endif; ?>

    <script type="application/json" id="zp-config"><?php echo wp_json_encode($cfg); ?></script>

    <!-- Geo Modal -->
    <div class="zp-modal" id="geoModal" aria-hidden="true">
      <div class="zp-modal-panel">
        <div class="zp-modal-head">
          <strong><?php echo esc_html(zp_t('modal_title')); ?></strong>
          <button type="button" class="zp-modal-close" id="zp-geo-close"><?php echo esc_html(zp_t('close')); ?></button>
        </div>
        <div class="zp-card-inner">
          <p style="margin:0;color:#475467;font-size:13px;line-height:1.5;"><?php echo esc_html(zp_t('modal_body')); ?></p>
          <div style="margin-top:12px;display:flex;gap:10px;">
            <button type="button" id="zp-modal-pick" class="zp-btn"><?php echo esc_html(zp_t('modal_pick')); ?></button>
          </div>
          <p id="geo-wait-msg" style="display:none;margin-top:12px;color:#667085;font-size:12px;"><?php echo esc_html(zp_t('modal_wait')); ?></p>
        </div>
      </div>
    </div>

    <!-- Email preview modal -->
    <div class="zp-modal" id="zp-email-modal" aria-hidden="true">
      <div class="zp-modal-panel">
        <div class="zp-modal-head">
          <strong><?php echo esc_html(zp_t('email_title')); ?></strong>
          <button type="button" class="zp-modal-close" id="zp-email-close"><?php echo esc_html(zp_t('close')); ?></button>
        </div>
        <div class="zp-modal-body">
          <iframe id="zp-email-iframe" title="<?php echo esc_attr(zp_t('email_title')); ?>"></iframe>
        </div>
      </div>
    </div>

    <div class="zp-root" id="zp-app">
      <div class="zp-shell">
        <div class="zp-top">
          <div class="zp-brand">
            <div class="zp-mark" aria-hidden="true">
              <div class="zp-mark-bg">
                <div class="zp-mark-circle">P</div>
              </div>
              <div class="zp-mark-dot"></div>
            </div>
            <div class="zp-brand-title">
              <strong>parkovne<span class="zp-accent">.sk</span></strong>
              <span>inteligentné parkovanie</span>
            </div>
          </div>
          <div id="zp-lang-switcher" data-fixed="1" class="zp-lang">
            <select id="zp-lang">
              <option value="sk"><?php echo esc_html(zp_t('lang_sk')); ?></option>
              <option value="en"><?php echo esc_html(zp_t('lang_en')); ?></option>
              <option value="pl"><?php echo esc_html(zp_t('lang_pl')); ?></option>
              <option value="hu"><?php echo esc_html(zp_t('lang_hu')); ?></option>
              <option value="sv"><?php echo esc_html(zp_t('lang_sv')); ?></option>
              <option value="zh"><?php echo esc_html(zp_t('lang_zh')); ?></option>
            </select>
          </div>
        </div>

        <div class="zp-actions">
          <button type="button" id="zp-detect" class="zp-btn"><?php echo esc_html(zp_t('detect_location')); ?></button>
          <button type="button" id="zp-pick" class="zp-btn"><?php echo esc_html(zp_t('select_on_map')); ?></button>
        </div>

        <div id="zone-result" class="zp-banner"><?php echo esc_html(zp_t('zone_detecting')); ?></div>

        <div class="zp-map">
          <div id="map-frame"></div>
        </div>

        <div class="zp-zone">
          <select id="zp-zone-select" aria-label="<?php echo esc_attr(zp_t('select_zone_from_list')); ?>">
            <option value=""><?php echo esc_html(zp_t('select_zone_from_list')); ?></option>
            <?php
              $zones = isset($tariffs_arr['zones']) && is_array($tariffs_arr['zones']) ? $tariffs_arr['zones'] : [];
              foreach ($zones as $z) {
                $id = isset($z['id']) ? strtoupper(trim((string)$z['id'])) : '';
                if (!$id) continue;
                $label = isset($z['label']) && $z['label'] ? (string)$z['label'] : $id;
                $zone_key = 'zone_name_' . strtolower($id);
                $zone_i18n = zp_t($zone_key, $lang);
                if ($zone_i18n !== $zone_key) $label = $zone_i18n;
                printf('<option value="%s">%s</option>', esc_attr($id), esc_html($label));
              }
            ?>
          </select>
        </div>

        <div class="zp-card" id="zp-main-card">
          <div class="zp-card-header">
            <div class="zp-card-title">
              <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M5 16l-1 0a2 2 0 01-2-2v-2.5A2.5 2.5 0 014.5 9H6l1-2.5A3 3 0 0110 4h4a3 3 0 013 2.5L18 9h1.5A2.5 2.5 0 0122 11.5V14a2 2 0 01-2 2h-1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                <path d="M7 16a2 2 0 104 0m6 0a2 2 0 104 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
              </svg>
              <span id="zp-zone-title">—</span>
            </div>
            <div class="zp-card-meta">
              <span class="zp-meta-item">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M12 21s7-4.5 7-11a7 7 0 10-14 0c0 6.5 7 11 7 11z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  <path d="M12 10.5a2 2 0 100-4 2 2 0 000 4z" fill="currentColor" opacity="0.25"/>
                </svg>
                <span id="zp-zone-meta"><?php echo esc_html(zp_t('zone_label_default')); ?>: —</span>
              </span>
              <span class="zp-meta-item">
                <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                  <path d="M4 10h16M4 14h16" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  <path d="M7 6h6a4 4 0 010 8H7a4 4 0 010-8z" stroke="currentColor" stroke-width="2"/>
                </svg>
                <span id="zp-zone-rate">—</span>
              </span>
            </div>
            <div id="zp-zone-rules" class="zp-zone-rules"></div>
          </div>
          <div class="zp-card-inner">
            <form id="zp-form" class="zp-form">
              <input type="hidden" id="zp-lat">
              <input type="hidden" id="zp-lng">
              <input type="hidden" id="band">
              <input type="hidden" id="duration" value="60">

              <div class="zp-field">
                <div class="zp-label">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M5 16l-1 0a2 2 0 01-2-2v-2.5A2.5 2.5 0 014.5 9H6l1-2.5A3 3 0 0110 4h4a3 3 0 013 2.5L18 9h1.5A2.5 2.5 0 0122 11.5V14a2 2 0 01-2 2h-1" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M7 16a2 2 0 104 0m6 0a2 2 0 104 0" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                  <label for="zp-spz"><?php echo esc_html(zp_t('label_spz')); ?></label>
                </div>
                <input id="zp-spz" class="zp-input" placeholder="<?php echo esc_attr(zp_t('placeholder_spz')); ?>" required>
              </div>

              <div class="zp-field">
                <div class="zp-label">
                  <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M4 4h16v16H4V4z" stroke="currentColor" stroke-width="2"/>
                    <path d="M7 9h10M7 13h10M7 17h6" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                  </svg>
                  <label for="zp-email"><?php echo esc_html(zp_t('label_email')); ?></label>
                </div>
                <input id="zp-email" type="email" class="zp-input" placeholder="<?php echo esc_attr(zp_t('placeholder_email')); ?>" required>
              </div>

              <div class="zp-field">
                <div class="zp-tabs-row">
                  <div class="zp-label" style="margin:0;">
                    <svg viewBox="0 0 24 24" fill="none" aria-hidden="true">
                      <path d="M12 7v5l3 2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                      <path d="M12 21a9 9 0 100-18 9 9 0 000 18z" stroke="currentColor" stroke-width="2"/>
                    </svg>
                    <span><?php echo esc_html(zp_t('label_duration')); ?></span>
                  </div>
                  <button type="button" class="zp-link" id="zp-custom-time"><?php echo esc_html(zp_t('custom_time')); ?></button>
                </div>
                <div class="zp-tabs" id="zp-duration-tabs">
                  <button type="button" class="zp-tab" data-min="30"><?php echo esc_html(zp_t('duration_30')); ?></button>
                  <button type="button" class="zp-tab is-active" data-min="60"><?php echo esc_html(zp_t('duration_60')); ?></button>
                  <button type="button" class="zp-tab" data-min="120"><?php echo esc_html(zp_t('duration_120')); ?></button>
                  <button type="button" class="zp-tab" data-min="240"><?php echo esc_html(zp_t('duration_240')); ?></button>
                  <button type="button" class="zp-tab" data-min="1440"><?php echo esc_html(zp_t('duration_1440')); ?></button>
                </div>
                <div id="zp-duration-custom" style="display:none;margin-top:10px;">
                  <div class="zp-summary" style="background:rgba(3,2,19,0.06);">
                    <div style="text-align:center;">
                      <strong id="zp-duration-display" style="font-size:20px;color:var(--zp-primary);"><?php echo esc_html(zp_t('duration_60')); ?></strong>
                    </div>
                  </div>
                  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-top:10px;">
                    <button type="button" class="zp-btn" data-add="30"><?php echo esc_html(zp_t('add_30m')); ?></button>
                    <button type="button" class="zp-btn" data-add="60"><?php echo esc_html(zp_t('add_1h')); ?></button>
                    <button type="button" class="zp-btn" data-add="120"><?php echo esc_html(zp_t('add_2h')); ?></button>
                    <button type="button" class="zp-btn" data-add="240"><?php echo esc_html(zp_t('add_4h')); ?></button>
                  </div>
                  <div style="margin-top:8px;">
                    <button type="button" class="zp-btn" data-add="-30" style="width:100%;"><?php echo esc_html(zp_t('sub_30m')); ?></button>
                  </div>
                </div>
              </div>
              <div id="zp-duration-note" class="zp-banner is-warn" style="display:none;"></div>

              <div class="zp-summary" id="zp-summary" style="display:none;">
                <div class="zp-summary-row">
                  <span><?php echo esc_html(zp_t('price_per_hour_label')); ?></span>
                  <strong id="zp-sum-hour">—</strong>
                </div>
                <div class="zp-summary-row">
                  <span><?php echo esc_html(zp_t('label_duration')); ?></span>
                  <strong id="zp-sum-duration">—</strong>
                </div>
                <div class="zp-divider"></div>
                <div class="zp-total">
                  <strong><?php echo esc_html(zp_t('total_price_label')); ?></strong>
                  <span id="zp-sum-total">—</span>
                </div>
              </div>

              <button id="zp-pay" type="submit" class="zp-pay" disabled><?php echo esc_html(str_replace('{{price}}', '0.00', zp_t('pay_button_price'))); ?></button>
            </form>
          </div>
        </div>

        <div style="display:none" id="zp-ll-help"></div>
      </div>
    </div>
    <div class="zp-success" id="zp-success-screen">
      <div class="zp-shell">
        <div class="zp-top" style="justify-content:center;">
          <div class="zp-brand" style="justify-content:center;">
            <div class="zp-mark" aria-hidden="true">
              <div class="zp-mark-bg">
                <div class="zp-mark-circle">P</div>
              </div>
              <div class="zp-mark-dot"></div>
            </div>
            <div class="zp-brand-title">
              <strong>parkovne<span class="zp-accent">.sk</span></strong>
              <span>inteligentné parkovanie</span>
            </div>
          </div>
        </div>

        <div>
          <div class="zp-success-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none">
              <path d="M20 7L10 17l-5-5" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
          </div>
          <h1 id="zp-success-title"><?php echo esc_html(zp_t('success_title')); ?></h1>
          <p id="zp-success-msg"></p>
        </div>

        <div class="zp-card">
          <div class="zp-card-inner" style="display:flex;flex-direction:column;gap:12px;">
            <div style="background:#fff;border:2px solid #e5e7eb;border-radius:12px;padding:14px;display:flex;justify-content:center;">
              <div style="width:160px;height:160px;border-radius:12px;background:linear-gradient(135deg,#f3f4f6,#e5e7eb);display:flex;align-items:center;justify-content:center;">
                <div style="text-align:center;padding:0 12px;">
                  <div id="zp-qr-id" style="font-size:12px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;color:#4b5563;word-break:break-all;"></div>
                  <div style="margin-top:8px;font-size:12px;color:#6b7280;"><?php echo esc_html(zp_t('scan_qr_label')); ?></div>
                </div>
              </div>
            </div>

            <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:10px 12px;">
              <div style="font-size:12px;color:#2563eb;font-weight:700;"><?php echo esc_html(zp_t('transaction_id_label')); ?></div>
              <div id="zp-txn-id" style="margin-top:4px;font-size:13px;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;color:#1e3a8a;word-break:break-all;"></div>
            </div>

            <div style="display:flex;flex-direction:column;gap:10px;">
              <div style="display:flex;gap:10px;align-items:flex-start;">
                <div style="width:22px;flex:0 0 22px;color:#475467;">🚗</div>
                <div style="flex:1;">
                  <div style="font-size:12px;color:#667085;"><?php echo esc_html(zp_t('label_spz')); ?></div>
                  <div id="zp-success-spz" style="font-size:16px;font-weight:800;color:#101828;"></div>
                </div>
              </div>

              <div style="display:flex;gap:10px;align-items:flex-start;">
                <div style="width:22px;flex:0 0 22px;color:#475467;">📍</div>
                <div style="flex:1;">
                  <div style="font-size:12px;color:#667085;"><?php echo esc_html(zp_t('parking_zone_label')); ?></div>
                  <div id="zp-success-zone" style="font-size:15px;font-weight:700;color:#101828;"></div>
                  <div id="zp-success-zone-id" style="font-size:12px;color:#475467;"></div>
                </div>
              </div>

              <div style="display:flex;gap:10px;align-items:flex-start;">
                <div style="width:22px;flex:0 0 22px;color:#475467;">📅</div>
                <div style="flex:1;">
                  <div style="font-size:12px;color:#667085;"><?php echo esc_html(zp_t('parking_period_label')); ?></div>
                  <div id="zp-success-date" style="font-size:15px;font-weight:700;color:#101828;"></div>
                  <div id="zp-success-time" style="font-size:13px;color:#344054;"></div>
                </div>
              </div>

              <div style="display:flex;gap:10px;align-items:flex-start;">
                <div style="width:22px;flex:0 0 22px;color:#475467;">🕒</div>
                <div style="flex:1;">
                  <div style="font-size:12px;color:#667085;"><?php echo esc_html(zp_t('label_duration')); ?></div>
                  <div id="zp-success-duration" style="font-size:15px;font-weight:700;color:#101828;"></div>
                </div>
              </div>

              <div style="display:flex;gap:10px;align-items:flex-start;">
                <div style="width:22px;flex:0 0 22px;color:#475467;">✉️</div>
                <div style="flex:1;">
                  <div style="font-size:12px;color:#667085;"><?php echo esc_html(zp_t('label_email')); ?></div>
                  <div id="zp-success-email" style="font-size:13px;color:#101828;"></div>
                </div>
              </div>
            </div>

            <div style="background:linear-gradient(90deg,#eff6ff,#eef2ff);border:1px solid #bfdbfe;border-radius:12px;padding:12px;">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-size:13px;color:#344054;"><?php echo esc_html(zp_t('total_paid_label')); ?></span>
                <span id="zp-success-total" style="font-size:22px;font-weight:900;color:#1d4ed8;"></span>
              </div>
            </div>
          </div>
        </div>

        <div class="zp-actions-col">
          <button type="button" class="zp-btn-secondary" id="zp-view-email"><?php echo esc_html(zp_t('view_email_confirmation')); ?></button>
          <button type="button" class="zp-btn-secondary" id="zp-download"><?php echo esc_html(zp_t('download_receipt')); ?></button>
          <button type="button" class="zp-btn-secondary" id="zp-share"><?php echo esc_html(zp_t('share_receipt')); ?></button>
          <button type="button" class="zp-pay" id="zp-new-parking"><?php echo esc_html(zp_t('new_parking')); ?></button>
        </div>

        <div class="zp-footer-note" id="zp-success-valid"></div>
      </div>
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

  private static function wp_tz(){
    if (function_exists('wp_timezone')) return wp_timezone();
    $tz = get_option('timezone_string', '');
    if ($tz) return new DateTimeZone($tz);
    $offset = (float)get_option('gmt_offset', 0);
    $hours = (int)$offset;
    $minutes = (int)round(abs($offset - $hours) * 60);
    $sign = $offset >= 0 ? '+' : '-';
    return new DateTimeZone(sprintf('%s%02d:%02d', $sign, abs($hours), $minutes));
  }

  private static function dt_from_ts($ts){
    return (new DateTimeImmutable('@' . intval($ts)))->setTimezone(self::wp_tz());
  }

  private static function find_zone_cfg($zone_id){
    $zones = self::get_tariffs_array()['zones'];
    foreach($zones as $zz){
      if (strtoupper((string)($zz['id'] ?? '')) === strtoupper((string)$zone_id)) return $zz;
    }
    return null;
  }

  public static function get_zone_schedule_rules($zone_id = null){
    $rules = [
      'A1' => ['days' => [1,2,3,4,5,6], 'start' => '00:00', 'end' => '24:00', 'max_minutes_per_day' => null],
      'A2' => ['days' => [1,2,3,4,5,6], 'start' => '00:00', 'end' => '24:00', 'max_minutes_per_day' => null],
      'BN' => ['days' => [1,2,3,4,5],   'start' => '07:30', 'end' => '18:00', 'max_minutes_per_day' => null],
      'N'  => ['days' => [1,2,3,4,5],   'start' => '07:30', 'end' => '18:00', 'max_minutes_per_day' => null],
      'B'  => ['days' => [1,2,3,4,5,6,7], 'start' => '07:30', 'end' => '16:00', 'max_minutes_per_day' => 240],
    ];
    if ($zone_id === null) return $rules;
    $id = strtoupper(trim((string)$zone_id));
    return $rules[$id] ?? null;
  }

  private static function zone_windows_for_day($zone_id, DateTimeImmutable $day_start){
    $rule = self::get_zone_schedule_rules($zone_id);
    if (!$rule || !isset($rule['days']) || !is_array($rule['days'])) return [];

    $dow = intval($day_start->format('N')); // 1=Mon ... 7=Sun
    if (!in_array($dow, $rule['days'], true)) return [];

    list($sh, $sm) = array_map('intval', explode(':', (string)$rule['start']));
    $window_start = $day_start->setTime($sh, $sm, 0);

    $end_raw = (string)$rule['end'];
    if ($end_raw === '24:00') {
      $window_end = $day_start->modify('+1 day')->setTime(0, 0, 0);
    } else {
      list($eh, $em) = array_map('intval', explode(':', $end_raw));
      $window_end = $day_start->setTime($eh, $em, 0);
    }
    if ($window_end <= $window_start) return [];

    return [[
      $window_start->getTimestamp(),
      $window_end->getTimestamp()
    ]];
  }

  private static function compute_validity_end_ts($zone_id, $start_ts){
    $start = self::dt_from_ts($start_ts);
    $plus24 = $start->modify('+24 hours');

    $next_day_start = $start->setTime(0, 0, 0)->modify('+1 day');
    $next_day_windows = self::zone_windows_for_day($zone_id, $next_day_start);

    // If the whole next day is free of charging, daily ticket is valid for 48h.
    if (empty($next_day_windows)) {
      return $start->modify('+48 hours')->getTimestamp();
    }

    $next_day_last_end = 0;
    foreach ($next_day_windows as $w) {
      if (isset($w[1]) && intval($w[1]) > $next_day_last_end) $next_day_last_end = intval($w[1]);
    }
    if ($next_day_last_end <= 0) return $plus24->getTimestamp();

    return min($plus24->getTimestamp(), $next_day_last_end);
  }

  private static function get_b_history_minutes_by_day($spz, $from_ts, $to_ts){
    $spz = strtoupper(trim((string)$spz));
    if (!$spz) return [];

    global $wpdb;
    $table = $wpdb->prefix . 'zp_transactions';

    $from = self::dt_from_ts($from_ts)->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $to = self::dt_from_ts($to_ts)->setTime(0, 0, 0)->modify('+1 day')->format('Y-m-d H:i:s');

    $sql = $wpdb->prepare(
      "SELECT DATE(COALESCE(paid_at, created_at)) AS d, SUM(minutes) AS mins
       FROM $table
       WHERE status = %s
         AND UPPER(zone_id) = %s
         AND UPPER(spz) = %s
         AND COALESCE(paid_at, created_at) >= %s
         AND COALESCE(paid_at, created_at) < %s
       GROUP BY DATE(COALESCE(paid_at, created_at))",
      'completed',
      'B',
      $spz,
      $from,
      $to
    );
    $rows = $wpdb->get_results($sql, ARRAY_A);
    if (!is_array($rows)) return [];

    $out = [];
    foreach ($rows as $row) {
      $d = isset($row['d']) ? (string)$row['d'] : '';
      if (!$d) continue;
      $out[$d] = intval($row['mins'] ?? 0);
    }
    return $out;
  }

  private static function calc_price_cents_for_daily_breakdown($zone_cfg, $daily_paid_minutes){
    if (!is_array($zone_cfg)) return 0;
    if (!is_array($daily_paid_minutes) || !$daily_paid_minutes) return 0;

    if (isset($zone_cfg['base_30'])) {
      $base = floatval($zone_cfg['base_30']);
      $cap  = isset($zone_cfg['daily_cap']) && $zone_cfg['daily_cap'] !== null ? floatval($zone_cfg['daily_cap']) : null;
      $total = 0.0;
      foreach ($daily_paid_minutes as $mins) {
        $m = max(0, intval($mins));
        if ($m < 1) continue;
        $blocks = (int)ceil($m / 30);
        $day_total = $blocks * $base;
        if ($cap !== null) $day_total = min($day_total, $cap);
        $total += $day_total;
      }
      return (int)round($total * 100);
    }

    $sum_minutes = 0;
    foreach ($daily_paid_minutes as $mins) $sum_minutes += max(0, intval($mins));
    if ($sum_minutes < 1) return 0;
    $minc = max(0, isset($zone_cfg['min_charge_minutes']) ? intval($zone_cfg['min_charge_minutes']) : 0);
    $step = max(1, isset($zone_cfg['billing_increment']) ? intval($zone_cfg['billing_increment']) : 15);
    $m    = max($minc, (int)ceil($sum_minutes / $step) * $step);
    $rate_per_min = isset($zone_cfg['rate_per_min']) ? floatval($zone_cfg['rate_per_min']) : ( isset($zone_cfg['rate_per_hour']) ? (floatval($zone_cfg['rate_per_hour'])/60.0) : 0.0 );
    return (int)round($m * $rate_per_min * 100);
  }

  public static function build_paid_quote($zone_id, $requested_paid_minutes, $spz = '', $start_ts = null, $lang = null, $opts = []){
    $zone_id = strtoupper(trim((string)$zone_id));
    $requested = max(0, intval($requested_paid_minutes));
    $start_ts = is_numeric($start_ts) ? intval($start_ts) : current_time('timestamp', true);
    $lang = $lang ?: zp_get_lang();
    if (!in_array($lang, ['sk','en','pl','hu','sv','zh'], true)) $lang = 'sk';

    $zone_cfg = self::find_zone_cfg($zone_id);
    if (!$zone_cfg || $requested < 1) {
      return [
        'requested_minutes' => $requested,
        'effective_minutes' => 0,
        'amount_cents' => 0,
        'daily_breakdown' => [],
        'adjustment_reason' => $requested < 1 ? 'invalid_minutes' : 'invalid_zone',
        'adjustment_text' => $requested < 1 ? zp_t('err_minutes_required', $lang) : zp_t('err_zone_required', $lang),
        'start_ts' => $start_ts,
        'validity_end_ts' => $start_ts,
        'coverage_end_ts' => $start_ts,
      ];
    }

    $validity_end_ts = self::compute_validity_end_ts($zone_id, $start_ts);
    if ($validity_end_ts <= $start_ts) {
      return [
        'requested_minutes' => $requested,
        'effective_minutes' => 0,
        'amount_cents' => 0,
        'daily_breakdown' => [],
        'adjustment_reason' => 'no_chargeable_minutes',
        'adjustment_text' => zp_t('err_no_chargeable_minutes', $lang),
        'start_ts' => $start_ts,
        'validity_end_ts' => $validity_end_ts,
        'coverage_end_ts' => $start_ts,
      ];
    }

    $include_b_history = !isset($opts['include_b_history']) || !!$opts['include_b_history'];
    $b_history = [];
    if ($zone_id === 'B' && $include_b_history) {
      $b_history = self::get_b_history_minutes_by_day($spz, $start_ts, $validity_end_ts);
    }

    $remaining = $requested;
    $coverage_end_ts = $start_ts;
    $daily_paid = [];
    $charge_window_seen = false;
    $b_limit_hit = false;
    $cursor_day = self::dt_from_ts($start_ts)->setTime(0, 0, 0);

    while ($remaining > 0 && $cursor_day->getTimestamp() < $validity_end_ts) {
      $date_key = $cursor_day->format('Y-m-d');
      $windows = self::zone_windows_for_day($zone_id, $cursor_day);
      if (!empty($windows)) $charge_window_seen = true;

      $day_remaining = PHP_INT_MAX;
      if ($zone_id === 'B') {
        $history_mins = intval($b_history[$date_key] ?? 0);
        $already_allocated = intval($daily_paid[$date_key] ?? 0);
        $day_remaining = max(0, 240 - $history_mins - $already_allocated);
        if ($day_remaining <= 0 && !empty($windows)) $b_limit_hit = true;
      }

      foreach ($windows as $w) {
        if ($remaining <= 0) break;
        if ($day_remaining <= 0) {
          if ($zone_id === 'B') $b_limit_hit = true;
          break;
        }
        $seg_start = max($start_ts, intval($w[0] ?? 0));
        $seg_end = min($validity_end_ts, intval($w[1] ?? 0));
        if ($seg_end <= $seg_start) continue;

        $available = (int)floor(($seg_end - $seg_start) / 60);
        if ($available < 1) continue;

        $take = min($available, $remaining, $day_remaining);
        if ($take < 1) continue;

        $daily_paid[$date_key] = intval($daily_paid[$date_key] ?? 0) + $take;
        $remaining -= $take;
        $day_remaining -= $take;
        $coverage_end_ts = max($coverage_end_ts, $seg_start + ($take * 60));
      }

      $cursor_day = $cursor_day->modify('+1 day');
    }

    $effective = $requested - $remaining;
    $adjustment_reason = null;
    if ($effective <= 0) {
      $adjustment_reason = $b_limit_hit ? 'zone_b_daily_limit' : 'no_chargeable_minutes';
      $coverage_end_ts = $start_ts;
    } elseif ($effective < $requested) {
      if ($b_limit_hit) $adjustment_reason = 'zone_b_daily_limit';
      else $adjustment_reason = $charge_window_seen ? 'validity_window_limit' : 'outside_paid_window';
    }

    $reason_to_text = [
      'zone_b_daily_limit' => zp_t('adjustment_zone_b_daily_limit', $lang),
      'validity_window_limit' => zp_t('adjustment_validity_window_limit', $lang),
      'outside_paid_window' => zp_t('adjustment_outside_paid_window', $lang),
      'no_chargeable_minutes' => zp_t('err_no_chargeable_minutes', $lang),
      'invalid_minutes' => zp_t('err_minutes_required', $lang),
      'invalid_zone' => zp_t('err_zone_required', $lang),
    ];

    ksort($daily_paid);
    $daily_breakdown = [];
    foreach ($daily_paid as $date => $mins) {
      $row = ['date' => $date, 'minutes' => intval($mins)];
      if ($zone_id === 'B') $row['history_minutes'] = intval($b_history[$date] ?? 0);
      $daily_breakdown[] = $row;
    }

    return [
      'requested_minutes' => $requested,
      'effective_minutes' => max(0, $effective),
      'amount_cents' => self::calc_price_cents_for_daily_breakdown($zone_cfg, $daily_paid),
      'daily_breakdown' => $daily_breakdown,
      'adjustment_reason' => $adjustment_reason,
      'adjustment_text' => $adjustment_reason && isset($reason_to_text[$adjustment_reason]) ? $reason_to_text[$adjustment_reason] : null,
      'start_ts' => $start_ts,
      'validity_end_ts' => $validity_end_ts,
      'coverage_end_ts' => $coverage_end_ts,
    ];
  }

  public static function compute_effective_parking_end_ts($zone_id, $paid_minutes, $start_ts, $spz = ''){
    $minutes = max(0, intval($paid_minutes));
    $start_ts = intval($start_ts);
    if ($minutes < 1 || $start_ts < 1) return $start_ts;
    $quote = self::build_paid_quote($zone_id, $minutes, $spz, $start_ts, null, ['include_b_history' => false]);
    return intval($quote['coverage_end_ts'] ?? $start_ts);
  }

  public static function calc_price_cents($zone_id, $minutes){
    $z = self::find_zone_cfg($zone_id);
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
          'has_barion_poskey' => !!get_option(self::OPT_BARION_POSKEY, ''),
          'barion_environment' => get_option(self::OPT_BARION_ENV, 'test'),
          'has_barion_payee' => !!get_option(self::OPT_BARION_PAYEE, ''),
          'geojson_url' => get_option(self::OPT_GJ, ''),
          'map_provider' => get_option(self::OPT_MAP, 'google'),
          'tol_m' => get_option(self::OPT_TOL, 25),
          'enable_circles' => !!get_option(self::OPT_CIRC, false),
          'tariffs_preview' => substr(get_option(self::OPT_TAR, ''), 0, 2000)
        ];
      }
    ]);
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
    add_submenu_page(
      self::SLUG,
      'EasyPark Sync',
      'EasyPark Sync',
      'manage_options',
      self::SLUG . '-easypark-sync',
      ['ZP_EasyPark_Integration', 'render_sync_page']
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
    ZP_EasyPark_Integration::register_settings($group);
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
        
        <?php ZP_EasyPark_Integration::render_settings_fields(); ?>

        <?php submit_button(); ?>
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
    $lang    = isset($p['lang']) ? strtolower(sanitize_text_field($p['lang'])) : zp_get_lang();
    if (!in_array($lang, ['sk','en','pl','hu','sv','zh'], true)) $lang = zp_get_lang();
    
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

    // Server-side authoritative quote: requested minutes are treated as target paid minutes.
    $quote = self::build_paid_quote($zone_id, $minutes, $spz, current_time('timestamp', true), $lang, ['include_b_history' => true]);
    $effective_minutes = intval($quote['effective_minutes'] ?? 0);
    if ($effective_minutes < 1) {
      return new WP_Error('no_chargeable_minutes', zp_t('err_no_chargeable_minutes', $lang), ['status'=>422]);
    }
    $amount_cents = intval($quote['amount_cents'] ?? 0);

    if ($amount_cents < 50) {
      return new WP_Error('amount_too_low', zp_t('err_amount_min', $lang), ['status'=>422]);
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
        'minutes' => $effective_minutes,
        'lat' => $lat,
        'lng' => $lng,
        'amount_cents' => $amount_cents,
        'requested_minutes' => $minutes,
        'effective_minutes' => $effective_minutes,
        'adjustment_reason' => $quote['adjustment_reason'] ?? null,
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
        'amount_eur' => $amount_cents / 100,
        'requested_minutes' => $minutes,
        'effective_minutes' => $effective_minutes,
        'adjustment_reason' => $quote['adjustment_reason'] ?? null,
        'adjustment_text' => $quote['adjustment_text'] ?? null
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
