<?php

if (!defined('ABSPATH')) exit;

class ZP_EasyPark_Integration {
  const DB_VERSION = '1.0.0';
  const OPT_SMARTHUB_ENABLED = 'zp_easypark_smart_hub_enabled';
  const OPT_SMARTHUB_ENV = 'zp_easypark_smart_hub_env';
  const OPT_SMARTHUB_URL = 'zp_easypark_smart_hub_url';
  const OPT_SMARTHUB_USERNAME = 'zp_easypark_smart_hub_username';
  const OPT_SMARTHUB_PASSWORD = 'zp_easypark_smart_hub_password';
  const DEFAULT_SMARTHUB_URL = 'https://kosice-staging.parkinghub.net/rest/resources/';

  const OPT_PERMIT_ENABLED = 'zp_easypark_permit_enabled';
  const OPT_PERMIT_ENV = 'zp_easypark_permit_env';
  const OPT_PERMIT_URL = 'zp_easypark_permit_url';
  const OPT_PERMIT_REFRESH_TOKEN = 'zp_easypark_permit_refresh_token';
  const DEFAULT_PERMIT_URL = 'https://external-gw-staging.easyparksystem.net/api';

  const OPT_AREA_MAPPING = 'zp_easypark_area_mapping_json';

  public static function init(){
    add_action('init', [__CLASS__, 'maybe_upgrade_schema']);
    add_action('zp_payment_completed', [__CLASS__, 'handle_payment_completed'], 10, 3);
  }

  public static function create_tables(){
    global $wpdb;
    $table = self::get_sync_table_name();
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
      id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
      integration VARCHAR(32) NOT NULL,
      object_type VARCHAR(32) NOT NULL DEFAULT 'parking',
      object_key VARCHAR(191) NOT NULL,
      transaction_id BIGINT(20) UNSIGNED DEFAULT NULL,
      external_id VARCHAR(191) DEFAULT NULL,
      status VARCHAR(32) NOT NULL DEFAULT 'pending',
      attempt_count INT NOT NULL DEFAULT 0,
      last_error TEXT DEFAULT NULL,
      request_payload LONGTEXT DEFAULT NULL,
      response_payload LONGTEXT DEFAULT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      synced_at DATETIME DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY integration_object_key (integration, object_key),
      KEY transaction_id (transaction_id),
      KEY status (status)
    ) $charset_collate;";
    dbDelta($sql);
    update_option('zp_easypark_sync_db_version', self::DB_VERSION);
  }

  public static function maybe_upgrade_schema(){
    if (get_option('zp_easypark_sync_db_version', '') === self::DB_VERSION) return;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    self::create_tables();
  }

  public static function register_settings($group){
    register_setting($group, self::OPT_SMARTHUB_ENABLED);
    register_setting($group, self::OPT_SMARTHUB_ENV);
    register_setting($group, self::OPT_SMARTHUB_URL);
    register_setting($group, self::OPT_SMARTHUB_USERNAME);
    register_setting($group, self::OPT_SMARTHUB_PASSWORD);
    register_setting($group, self::OPT_PERMIT_ENABLED);
    register_setting($group, self::OPT_PERMIT_ENV);
    register_setting($group, self::OPT_PERMIT_URL);
    register_setting($group, self::OPT_PERMIT_REFRESH_TOKEN);
    register_setting($group, self::OPT_AREA_MAPPING);
  }

  public static function render_settings_fields(){ ?>
    <h2>EasyPark integrácie</h2>
    <p class="description">Nastavenie pripojenia Permit HUB a Smart HUB. Reálne volania sa nespúšťajú, kým integrácie výslovne nepovolíte a nenastavíte prístupy.</p>

    <h3>Smart HUB import parkovaní</h3>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_SMARTHUB_ENABLED); ?>">Zapnúť Smart HUB</label></th>
        <td>
          <label><input name="<?php echo esc_attr(self::OPT_SMARTHUB_ENABLED); ?>" id="<?php echo esc_attr(self::OPT_SMARTHUB_ENABLED); ?>" type="checkbox" value="1" <?php checked((bool) get_option(self::OPT_SMARTHUB_ENABLED, false)); ?>> Po úspešnej platbe pripraviť export parkovania do EasyPark Smart HUB</label>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_SMARTHUB_ENV); ?>">Prostredie</label></th>
        <td>
          <?php $env = get_option(self::OPT_SMARTHUB_ENV, 'staging'); ?>
          <select name="<?php echo esc_attr(self::OPT_SMARTHUB_ENV); ?>" id="<?php echo esc_attr(self::OPT_SMARTHUB_ENV); ?>">
            <option value="staging" <?php selected($env, 'staging'); ?>>Staging</option>
            <option value="production" <?php selected($env, 'production'); ?>>Production</option>
          </select>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_SMARTHUB_URL); ?>">Endpoint URL</label></th>
        <td>
          <input name="<?php echo esc_attr(self::OPT_SMARTHUB_URL); ?>" id="<?php echo esc_attr(self::OPT_SMARTHUB_URL); ?>" type="url" class="regular-text code" value="<?php echo esc_attr(get_option(self::OPT_SMARTHUB_URL, self::DEFAULT_SMARTHUB_URL)); ?>">
          <p class="description">Môžete zadať base URL <code><?php echo esc_html(self::DEFAULT_SMARTHUB_URL); ?></code> alebo celý import endpoint.</p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_SMARTHUB_USERNAME); ?>">Basic Auth používateľ</label></th>
        <td><input name="<?php echo esc_attr(self::OPT_SMARTHUB_USERNAME); ?>" id="<?php echo esc_attr(self::OPT_SMARTHUB_USERNAME); ?>" type="text" class="regular-text" value="<?php echo esc_attr(get_option(self::OPT_SMARTHUB_USERNAME, '')); ?>"></td>
      </tr>
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_SMARTHUB_PASSWORD); ?>">Basic Auth heslo</label></th>
        <td><input name="<?php echo esc_attr(self::OPT_SMARTHUB_PASSWORD); ?>" id="<?php echo esc_attr(self::OPT_SMARTHUB_PASSWORD); ?>" type="password" class="regular-text" value="<?php echo esc_attr(get_option(self::OPT_SMARTHUB_PASSWORD, '')); ?>"></td>
      </tr>
    </table>

    <h3>Permit HUB lookup podľa ŠPZ</h3>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_PERMIT_ENABLED); ?>">Zapnúť Permit HUB</label></th>
        <td>
          <label><input name="<?php echo esc_attr(self::OPT_PERMIT_ENABLED); ?>" id="<?php echo esc_attr(self::OPT_PERMIT_ENABLED); ?>" type="checkbox" value="1" <?php checked((bool) get_option(self::OPT_PERMIT_ENABLED, false)); ?>> Povoliť verifikáciu permitov podľa ŠPZ</label>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_PERMIT_ENV); ?>">Prostredie</label></th>
        <td>
          <?php $permit_env = get_option(self::OPT_PERMIT_ENV, 'staging'); ?>
          <select name="<?php echo esc_attr(self::OPT_PERMIT_ENV); ?>" id="<?php echo esc_attr(self::OPT_PERMIT_ENV); ?>">
            <option value="staging" <?php selected($permit_env, 'staging'); ?>>Staging</option>
            <option value="production" <?php selected($permit_env, 'production'); ?>>Production</option>
          </select>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_PERMIT_URL); ?>">Base API URL</label></th>
        <td>
          <input name="<?php echo esc_attr(self::OPT_PERMIT_URL); ?>" id="<?php echo esc_attr(self::OPT_PERMIT_URL); ?>" type="url" class="regular-text code" value="<?php echo esc_attr(get_option(self::OPT_PERMIT_URL, self::DEFAULT_PERMIT_URL)); ?>">
          <p class="description">Napr. <code><?php echo esc_html(self::DEFAULT_PERMIT_URL); ?></code></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_PERMIT_REFRESH_TOKEN); ?>">Refresh token</label></th>
        <td>
          <input name="<?php echo esc_attr(self::OPT_PERMIT_REFRESH_TOKEN); ?>" id="<?php echo esc_attr(self::OPT_PERMIT_REFRESH_TOKEN); ?>" type="password" class="regular-text" value="<?php echo esc_attr(get_option(self::OPT_PERMIT_REFRESH_TOKEN, '')); ?>">
          <p class="description">Použije sa pre JWT login a lookup <code>/permit/license-plate/{licenseplate}</code>.</p>
        </td>
      </tr>
    </table>

    <h3>Mapovanie zón do EasyPark</h3>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="<?php echo esc_attr(self::OPT_AREA_MAPPING); ?>">Area mapping JSON</label></th>
        <td>
          <textarea name="<?php echo esc_attr(self::OPT_AREA_MAPPING); ?>" id="<?php echo esc_attr(self::OPT_AREA_MAPPING); ?>" class="large-text code" rows="12"><?php echo esc_textarea(get_option(self::OPT_AREA_MAPPING, self::get_default_area_mapping_json())); ?></textarea>
          <p class="description">Sem doplníte budúce mapovanie lokálnych zón na <code>areaNo</code> a <code>parkingareaNo</code> z EasyPark.</p>
        </td>
      </tr>
    </table>
  <?php }

  public static function handle_payment_completed($order_data, $payment_id, $transaction_id){
    if (!self::is_smart_hub_enabled()) return;

    $payload = self::build_smart_hub_payload($order_data, $payment_id, $transaction_id);
    $ready = self::is_smart_hub_ready() && !empty($payload['areaNo']) && !empty($payload['licenseNumber']);
    $status = $ready ? 'queued' : 'awaiting_config';
    $error = $ready ? null : self::build_smart_hub_readiness_error($payload);

    self::upsert_sync_item([
      'integration' => 'smart_hub_parking',
      'object_type' => 'parking',
      'object_key' => $payload['externalParkingId'],
      'transaction_id' => intval($transaction_id),
      'external_id' => $payload['externalParkingId'],
      'status' => $status,
      'attempt_count' => 0,
      'last_error' => $error,
      'request_payload' => wp_json_encode($payload),
      'response_payload' => null,
      'synced_at' => null,
    ]);

    if (!$ready) return;

    $result = self::send_smart_hub_parking($payload);
    self::upsert_sync_item([
      'integration' => 'smart_hub_parking',
      'object_type' => 'parking',
      'object_key' => $payload['externalParkingId'],
      'transaction_id' => intval($transaction_id),
      'external_id' => $payload['externalParkingId'],
      'status' => $result['success'] ? 'synced' : 'failed',
      'attempt_count' => 1,
      'last_error' => $result['success'] ? null : ($result['error'] ?? 'Smart HUB request failed'),
      'request_payload' => wp_json_encode($payload),
      'response_payload' => isset($result['response']) ? wp_json_encode($result['response']) : null,
      'synced_at' => $result['success'] ? current_time('mysql') : null,
    ]);
  }

  public static function send_smart_hub_parking($payload){
    $url = self::get_smart_hub_import_url();
    $username = (string) get_option(self::OPT_SMARTHUB_USERNAME, '');
    $password = (string) get_option(self::OPT_SMARTHUB_PASSWORD, '');
    if (!$url || !$username || !$password) {
      return ['success' => false, 'error' => 'Smart HUB credentials are incomplete'];
    }

    $response = wp_remote_post($url, [
      'timeout' => 20,
      'headers' => [
        'Authorization' => 'Basic ' . base64_encode($username . ':' . $password),
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode($payload),
    ]);

    if (is_wp_error($response)) {
      return ['success' => false, 'error' => $response->get_error_message()];
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $body = (string) wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    $status = is_array($json) && isset($json['status']) ? (string) $json['status'] : '';
    $ok = ($code >= 200 && $code < 300);
    if ($status && strtoupper($status) !== 'SUCCESS_OK') {
      $ok = false;
    }

    return [
      'success' => $ok,
      'error' => $ok ? null : ('Smart HUB HTTP ' . $code . ($status ? ' status ' . $status : '')),
      'response' => [
        'http_code' => $code,
        'status' => $status ?: null,
        'body' => $json ?: $body,
      ],
    ];
  }

  public static function lookup_permit_by_plate($license_plate){
    $plate = self::normalize_license_plate($license_plate);
    if (!$plate) {
      return new WP_Error('invalid_plate', 'Invalid license plate');
    }
    if (!self::is_permit_lookup_enabled()) {
      return new WP_Error('permit_lookup_disabled', 'Permit HUB lookup disabled');
    }
    if (!self::is_permit_lookup_ready()) {
      return new WP_Error('permit_lookup_not_ready', 'Permit HUB lookup is not configured');
    }

    $token_result = self::get_permit_id_token();
    if (is_wp_error($token_result)) {
      return $token_result;
    }

    $url = self::get_permit_license_plate_url($plate);
    $response = wp_remote_get($url, [
      'timeout' => 20,
      'headers' => [
        'Content-Type' => 'application/json',
        'X-Authorization' => 'Bearer ' . $token_result['idToken'],
      ],
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $body = trim((string) wp_remote_retrieve_body($response));
    $json = $body !== '' ? json_decode($body, true) : null;
    $valid_json = $body === '' || json_last_error() === JSON_ERROR_NONE;
    $success = $code >= 200 && $code < 300 && $valid_json;

    return [
      'success' => $success,
      'found' => is_array($json) && !empty($json),
      'licensePlate' => $plate,
      'http_code' => $code,
      'error' => $success ? null : ('Permit HUB HTTP ' . $code . ($valid_json ? '' : ' invalid JSON response')),
      'response' => [
        'http_code' => $code,
        'body' => $valid_json ? $json : $body,
      ],
    ];
  }

  public static function run_permit_lookup_test($license_plate){
    $plate = self::normalize_license_plate($license_plate);
    if (!$plate) {
      return new WP_Error('invalid_plate', 'Invalid license plate');
    }

    $request_payload = [
      'licensePlate' => $plate,
      'endpoint' => self::get_permit_license_plate_url($plate),
    ];

    $result = self::lookup_permit_by_plate($plate);
    if (is_wp_error($result)) {
      self::upsert_sync_item([
        'integration' => 'permit_hub_lookup',
        'object_type' => 'permit',
        'object_key' => $plate,
        'transaction_id' => null,
        'external_id' => $plate,
        'status' => 'failed',
        'attempt_count' => 1,
        'last_error' => $result->get_error_message(),
        'request_payload' => wp_json_encode($request_payload),
        'response_payload' => null,
        'synced_at' => null,
      ]);
      return $result;
    }

    self::upsert_sync_item([
      'integration' => 'permit_hub_lookup',
      'object_type' => 'permit',
      'object_key' => $plate,
      'transaction_id' => null,
      'external_id' => $plate,
      'status' => $result['success'] ? 'synced' : 'failed',
      'attempt_count' => 1,
      'last_error' => $result['success'] ? null : ($result['error'] ?? 'Permit HUB lookup failed'),
      'request_payload' => wp_json_encode($request_payload),
      'response_payload' => wp_json_encode($result),
      'synced_at' => $result['success'] ? current_time('mysql') : null,
    ]);

    return $result;
  }

  public static function normalize_license_plate($plate){
    $plate = strtoupper(trim((string) $plate));
    return preg_replace('/[^A-Z0-9]/', '', $plate);
  }

  public static function is_smart_hub_enabled(){
    return (bool) get_option(self::OPT_SMARTHUB_ENABLED, false);
  }

  public static function is_smart_hub_ready(){
    if (!self::is_smart_hub_enabled()) return false;
    return (bool) (get_option(self::OPT_SMARTHUB_URL, self::DEFAULT_SMARTHUB_URL) && get_option(self::OPT_SMARTHUB_USERNAME, '') && get_option(self::OPT_SMARTHUB_PASSWORD, ''));
  }

  private static function get_smart_hub_import_url(){
    $url = trim((string) get_option(self::OPT_SMARTHUB_URL, self::DEFAULT_SMARTHUB_URL));
    if (!$url) return '';
    $url = rtrim($url, '/');
    if (preg_match('~/external-api/parkings/import$~', $url)) return $url;
    if (preg_match('~/rest/resources$~', $url)) return $url . '/external-api/parkings/import';
    return $url;
  }

  public static function is_permit_lookup_enabled(){
    return (bool) get_option(self::OPT_PERMIT_ENABLED, false);
  }

  public static function is_permit_lookup_ready(){
    if (!self::is_permit_lookup_enabled()) return false;
    return (bool) (get_option(self::OPT_PERMIT_URL, self::DEFAULT_PERMIT_URL) && get_option(self::OPT_PERMIT_REFRESH_TOKEN, ''));
  }

  private static function get_permit_id_token(){
    $refresh_token = trim((string) get_option(self::OPT_PERMIT_REFRESH_TOKEN, ''));
    if (!$refresh_token) {
      return new WP_Error('permit_refresh_token_missing', 'Permit HUB refresh token is missing');
    }

    $response = wp_remote_post(self::get_permit_base_url() . '/get-jwt', [
      'timeout' => 20,
      'headers' => [
        'Content-Type' => 'application/json',
      ],
      'body' => wp_json_encode([
        'refreshToken' => $refresh_token,
      ]),
    ]);

    if (is_wp_error($response)) {
      return $response;
    }

    $code = intval(wp_remote_retrieve_response_code($response));
    $body = (string) wp_remote_retrieve_body($response);
    $json = json_decode($body, true);
    if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['idToken'])) {
      return new WP_Error('permit_jwt_failed', 'Permit HUB JWT request failed with HTTP ' . $code);
    }

    return [
      'idToken' => (string) $json['idToken'],
      'validUntilDate' => isset($json['validUntilDate']) ? (string) $json['validUntilDate'] : null,
    ];
  }

  private static function get_permit_base_url(){
    return rtrim(trim((string) get_option(self::OPT_PERMIT_URL, self::DEFAULT_PERMIT_URL)), '/');
  }

  private static function get_permit_license_plate_url($plate){
    return self::get_permit_base_url() . '/permit/license-plate/' . rawurlencode(self::normalize_license_plate($plate));
  }

  public static function build_smart_hub_payload($order_data, $payment_id, $transaction_id){
    $zone_id = strtoupper(trim((string) ($order_data['zone_id'] ?? '')));
    $mapping = self::get_zone_mapping($zone_id);
    $currency = 'EUR';
    $amount_cents = intval($order_data['amount_cents'] ?? 0);
    $start_at = $order_data['started_at'] ?? ($order_data['paid_at'] ?? ($order_data['created_at'] ?? current_time('mysql')));
    $end_at = $order_data['expires_at'] ?? $start_at;
    $external_id = !empty($order_data['order_number']) ? (string) $order_data['order_number'] : ('ZP-' . intval($transaction_id ?: 0) . '-' . sanitize_key((string) $payment_id));

    $payload = [
      'externalParkingId' => $external_id,
      'areaNo' => isset($mapping['smart_hub_area_no']) ? intval($mapping['smart_hub_area_no']) : null,
      'areaCountryCode' => !empty($mapping['area_country_code']) ? strtoupper((string) $mapping['area_country_code']) : 'SK',
      'startDate' => self::mysql_to_iso8601_utc($start_at),
      'endDate' => self::mysql_to_iso8601_utc($end_at),
      'licenseNumber' => self::normalize_license_plate($order_data['spz'] ?? ''),
      'subType' => !empty($mapping['sub_type']) ? (string) $mapping['sub_type'] : 'NORMAL_TIME',
      'parkingFeeInclusiveVAT' => round($amount_cents / 100, 2),
      'currency' => $currency,
    ];

    if (!empty($mapping['car_country_code'])) {
      $payload['carCountryCode'] = strtoupper((string) $mapping['car_country_code']);
    }
    if (isset($mapping['vat_pct']) && is_numeric($mapping['vat_pct'])) {
      $vat_pct = max(0.0, floatval($mapping['vat_pct']));
      $inclusive = $amount_cents / 100;
      $exclusive = $vat_pct > 0 ? round($inclusive / (1 + ($vat_pct / 100)), 2) : $inclusive;
      $payload['parkingFeeExclusiveVAT'] = $exclusive;
      $payload['parkingFeeVAT'] = round($inclusive - $exclusive, 2);
    }
    if (isset($order_data['lat']) && $order_data['lat'] !== null && $order_data['lat'] !== '') {
      $payload['latitude'] = round(floatval($order_data['lat']), 6);
    }
    if (isset($order_data['lng']) && $order_data['lng'] !== null && $order_data['lng'] !== '') {
      $payload['longitude'] = round(floatval($order_data['lng']), 6);
    }
    if (!empty($mapping['parking_spot_number'])) {
      $payload['parkingSpotNumber'] = (string) $mapping['parking_spot_number'];
    }
    if (!empty($mapping['vehicle_type'])) {
      $payload['vehicleType'] = (string) $mapping['vehicle_type'];
    }
    if (!empty($mapping['fuel_type'])) {
      $payload['fuelType'] = (string) $mapping['fuel_type'];
    }

    return $payload;
  }

  private static function build_smart_hub_readiness_error($payload){
    $missing = [];
    if (!get_option(self::OPT_SMARTHUB_URL, self::DEFAULT_SMARTHUB_URL)) $missing[] = 'endpoint URL';
    if (!get_option(self::OPT_SMARTHUB_USERNAME, '')) $missing[] = 'Basic Auth username';
    if (!get_option(self::OPT_SMARTHUB_PASSWORD, '')) $missing[] = 'Basic Auth password';
    if (empty($payload['areaNo'])) $missing[] = 'area mapping for zone';
    if (empty($payload['licenseNumber'])) $missing[] = 'normalized license plate';
    if (!$missing) return null;
    return 'EasyPark Smart HUB is not ready: missing ' . implode(', ', $missing);
  }

  private static function get_zone_mapping($zone_id){
    $mapping = self::get_area_mapping();
    $zone_id = strtoupper(trim((string) $zone_id));
    return isset($mapping[$zone_id]) && is_array($mapping[$zone_id]) ? $mapping[$zone_id] : [];
  }

  private static function get_area_mapping(){
    $raw = get_option(self::OPT_AREA_MAPPING, self::get_default_area_mapping_json());
    $json = json_decode((string) $raw, true);
    return is_array($json) ? $json : [];
  }

  private static function get_default_area_mapping_json(){
    return wp_json_encode([
      'A1' => [
        'smart_hub_area_no' => 1,
        'permit_parkingarea_no' => 1,
        'bonus_card_area_no' => 11,
        'area_country_code' => 'SK',
        'car_country_code' => 'SK',
        'sub_type' => 'NORMAL_TIME',
      ],
      'A2' => [
        'smart_hub_area_no' => 2,
        'permit_parkingarea_no' => 2,
        'bonus_card_area_no' => 12,
        'area_country_code' => 'SK',
        'car_country_code' => 'SK',
        'sub_type' => 'NORMAL_TIME',
      ],
      'BN' => [
        'smart_hub_area_no' => 3,
        'permit_parkingarea_no' => 3,
        'bonus_card_area_no' => 13,
        'area_country_code' => 'SK',
        'car_country_code' => 'SK',
        'sub_type' => 'NORMAL_TIME',
      ],
      'N' => [
        'smart_hub_area_no' => 4,
        'permit_parkingarea_no' => 4,
        'bonus_card_area_no' => 14,
        'area_country_code' => 'SK',
        'car_country_code' => 'SK',
        'sub_type' => 'NORMAL_TIME',
      ],
      'B' => [
        'smart_hub_area_no' => 5,
        'permit_parkingarea_no' => 5,
        'bonus_card_area_no' => 15,
        'area_country_code' => 'SK',
        'car_country_code' => 'SK',
        'sub_type' => 'NORMAL_TIME',
      ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }

  private static function mysql_to_iso8601_utc($value){
    try {
      $dt = new DateTimeImmutable((string) $value, wp_timezone());
      return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    } catch (Exception $e) {
      return gmdate('Y-m-d\TH:i:s\Z');
    }
  }

  private static function get_sync_table_name(){
    global $wpdb;
    return $wpdb->prefix . 'zp_easypark_sync';
  }

  public static function render_sync_page(){
    if (!current_user_can('manage_options')) {
      wp_die(esc_html__('You do not have permission to access this page.'));
    }

    global $wpdb;
    $table = self::get_sync_table_name();
    $status_filter = isset($_GET['status']) ? sanitize_text_field(wp_unslash($_GET['status'])) : '';
    $allowed_statuses = ['synced', 'failed', 'queued', 'awaiting_config', 'pending'];
    if ($status_filter && !in_array($status_filter, $allowed_statuses, true)) {
      $status_filter = '';
    }

    $permit_lookup_notice = null;
    if (
      isset($_POST['zp_easypark_action']) &&
      sanitize_key(wp_unslash($_POST['zp_easypark_action'])) === 'permit_lookup_test'
    ) {
      check_admin_referer('zp_permit_lookup_test');
      $plate = isset($_POST['license_plate']) ? sanitize_text_field(wp_unslash($_POST['license_plate'])) : '';
      $lookup_result = self::run_permit_lookup_test($plate);
      if (is_wp_error($lookup_result)) {
        $permit_lookup_notice = [
          'class' => 'notice notice-error',
          'message' => 'Permit HUB lookup zlyhal: ' . $lookup_result->get_error_message(),
        ];
      } else {
        $permit_lookup_notice = [
          'class' => $lookup_result['success'] ? 'notice notice-success' : 'notice notice-error',
          'message' => $lookup_result['success']
            ? ('Permit HUB lookup dokončený pre ŠPZ ' . $lookup_result['licensePlate'] . '. Výsledok: ' . ($lookup_result['found'] ? 'permit nájdený' : 'permit nenájdený') . '.')
            : ('Permit HUB lookup zlyhal: ' . ($lookup_result['error'] ?? 'unknown error')),
        ];
      }
    }

    $where_sql = '1=1';
    if ($status_filter) {
      $where_sql = $wpdb->prepare('status = %s', $status_filter);
    }

    $items = $wpdb->get_results(
      "SELECT * FROM $table WHERE $where_sql ORDER BY updated_at DESC, id DESC LIMIT 50"
    );
    ?>
    <div class="wrap">
      <h1>EasyPark Sync</h1>
      <p class="description">Posledné odpovede zo Smart HUB / Permit HUB integrácií. Citlivé údaje Basic Auth sa sem neukladajú.</p>

      <?php if ($permit_lookup_notice): ?>
        <div class="<?php echo esc_attr($permit_lookup_notice['class']); ?>"><p><?php echo esc_html($permit_lookup_notice['message']); ?></p></div>
      <?php endif; ?>

      <h2>Permit HUB test podľa ŠPZ</h2>
      <form method="post" style="margin: 16px 0;">
        <?php wp_nonce_field('zp_permit_lookup_test'); ?>
        <input type="hidden" name="zp_easypark_action" value="permit_lookup_test">
        <label for="zp-permit-license-plate" class="screen-reader-text">ŠPZ</label>
        <input id="zp-permit-license-plate" name="license_plate" type="text" class="regular-text" value="BK777KE" placeholder="BK777KE">
        <button type="submit" class="button button-primary">Overiť Permit HUB</button>
        <p class="description">Použije sa endpoint <code>/permit/license-plate/{licenseplate}</code>. Refresh token ani idToken sa do logu neukladajú.</p>
      </form>

      <h2>Log integrácií</h2>
      <form method="get" style="margin: 16px 0;">
        <input type="hidden" name="page" value="zaparkuj-wp-easypark-sync">
        <select name="status">
          <option value="">Všetky stavy</option>
          <option value="synced" <?php selected($status_filter, 'synced'); ?>>Synced</option>
          <option value="failed" <?php selected($status_filter, 'failed'); ?>>Failed</option>
          <option value="queued" <?php selected($status_filter, 'queued'); ?>>Queued</option>
          <option value="awaiting_config" <?php selected($status_filter, 'awaiting_config'); ?>>Awaiting config</option>
          <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
        </select>
        <button type="submit" class="button">Filtrovať</button>
        <?php if ($status_filter): ?>
          <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=zaparkuj-wp-easypark-sync')); ?>">Resetovať</a>
        <?php endif; ?>
      </form>

      <table class="wp-list-table widefat fixed striped">
        <thead>
          <tr>
            <th>ID</th>
            <th>Dátum</th>
            <th>Integrácia</th>
            <th>Objekt</th>
            <th>Objednávka</th>
            <th>Stav</th>
            <th>Pokusy</th>
            <th>Chyba</th>
            <th>Odpoveď servera</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($items)): ?>
            <tr><td colspan="9">Žiadne záznamy</td></tr>
          <?php else: ?>
            <?php foreach ($items as $item): ?>
              <tr>
                <td><?php echo esc_html($item->id); ?></td>
                <td>
                  <strong><?php echo esc_html($item->updated_at); ?></strong><br>
                  <small>Created: <?php echo esc_html($item->created_at); ?></small><br>
                  <small>Synced: <?php echo esc_html($item->synced_at ?: '-'); ?></small>
                </td>
                <td><?php echo esc_html($item->integration); ?></td>
                <td>
                  <strong><?php echo esc_html($item->object_type); ?></strong><br>
                  <small><?php echo esc_html($item->object_key); ?></small>
                </td>
                <td><?php echo esc_html($item->transaction_id ?: '-'); ?></td>
                <td><?php echo wp_kses_post(self::render_status_badge($item->status)); ?></td>
                <td><?php echo esc_html($item->attempt_count); ?></td>
                <td><?php echo esc_html($item->last_error ?: '-'); ?></td>
                <td>
                  <details>
                    <summary>Response</summary>
                    <pre style="max-height:220px;overflow:auto;white-space:pre-wrap;"><?php echo esc_html(self::format_payload_for_display($item->response_payload)); ?></pre>
                  </details>
                  <details>
                    <summary>Request</summary>
                    <pre style="max-height:220px;overflow:auto;white-space:pre-wrap;"><?php echo esc_html(self::format_payload_for_display($item->request_payload)); ?></pre>
                  </details>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
  }

  private static function render_status_badge($status){
    $colors = [
      'synced' => '#28a745',
      'failed' => '#dc3545',
      'queued' => '#0d6efd',
      'awaiting_config' => '#ffc107',
      'pending' => '#6c757d',
    ];
    $color = $colors[$status] ?? '#6c757d';
    $text_color = $status === 'awaiting_config' ? '#000' : '#fff';
    return sprintf(
      '<span style="display:inline-block;background:%s;color:%s;padding:3px 8px;border-radius:3px;font-weight:600;">%s</span>',
      esc_attr($color),
      esc_attr($text_color),
      esc_html($status)
    );
  }

  private static function format_payload_for_display($payload){
    if (!$payload) return '-';
    $decoded = json_decode((string) $payload, true);
    if (json_last_error() === JSON_ERROR_NONE) {
      return wp_json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    return (string) $payload;
  }

  private static function upsert_sync_item($data){
    global $wpdb;
    $table = self::get_sync_table_name();
    $now = current_time('mysql');
    $existing = $wpdb->get_row($wpdb->prepare(
      "SELECT id FROM $table WHERE integration = %s AND object_key = %s LIMIT 1",
      $data['integration'],
      $data['object_key']
    ), ARRAY_A);

    $row = [
      'integration' => $data['integration'],
      'object_type' => $data['object_type'],
      'object_key' => $data['object_key'],
      'transaction_id' => $data['transaction_id'],
      'external_id' => $data['external_id'],
      'status' => $data['status'],
      'attempt_count' => intval($data['attempt_count']),
      'last_error' => $data['last_error'],
      'request_payload' => $data['request_payload'],
      'response_payload' => $data['response_payload'],
      'updated_at' => $now,
      'synced_at' => $data['synced_at'],
    ];

    if ($existing && isset($existing['id'])) {
      $wpdb->update($table, $row, ['id' => intval($existing['id'])]);
      return intval($existing['id']);
    }

    $row['created_at'] = $now;
    $wpdb->insert($table, $row);
    return intval($wpdb->insert_id);
  }
}
