<?php
/**
 * REST API endpoints для Barion интеграции
 * Добавьте этот код в zaparkuj-wp.php или подключите как отдельный файл
 */

// В классе Zaparkuj_WP_045 добавить в метод register_rest():

public static function register_rest_barion(){
  // Подключение Barion gateway
  require_once plugin_dir_path(__FILE__) . 'includes/barion-gateway.php';
  
  // Endpoint для создания платежа
  register_rest_route('zaparkuj/v1', '/barion-prepare', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => [__CLASS__, 'rest_barion_prepare'],
  ]);
  
  // IPN endpoint для уведомлений от Barion
  register_rest_route('zaparkuj/v1', '/barion-ipn', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => ['ZP_Barion_Gateway', 'handle_ipn'],
  ]);
}

public static function rest_barion_prepare(WP_REST_Request $r){
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
  $amount_cents = isset($p['amount_cents']) ? intval($p['amount_cents']) : 0;
  
  // Валидация
  if (!$spz || strlen($spz) < 2) {
    return new WP_Error('invalid_spz', 'ŠPZ je povinná', ['status'=>422]);
  }
  if (!$email || !is_email($email)) {
    return new WP_Error('invalid_email', 'Email je povinný', ['status'=>422]);
  }
  if (!$zone_id) {
    return new WP_Error('invalid_zone', 'Zóna nebola určená', ['status'=>422]);
  }
  if (!$minutes || $minutes < 1) {
    return new WP_Error('invalid_minutes', 'Trvanie musí byť aspoň 1 minúta', ['status'=>422]);
  }
  
  // Пересчитать цену на серверной стороне (защита от подделки)
  $amount_cents = self::calc_price_cents($zone_id, $minutes);
  
  if ($amount_cents < 50) {
    return new WP_Error('amount_too_low', 'Minimálna suma je 0.50 €', ['status'=>422]);
  }
  
  // Проверка настройки Barion
  $poskey = get_option(ZP_Barion_Gateway::OPT_POSKEY, '');
  if (!$poskey) {
    return new WP_Error('no_barion_config', 'Barion nie je nakonfigurovaný', ['status'=>500]);
  }
  
  try {
    // Создание платежа через Barion Gateway
    require_once plugin_dir_path(__FILE__) . 'includes/barion-gateway.php';
    
    $result = ZP_Barion_Gateway::prepare_payment([
      'spz' => $spz,
      'email' => $email,
      'zone_id' => $zone_id,
      'minutes' => $minutes,
      'lat' => $lat,
      'lng' => $lng,
      'amount_cents' => $amount_cents
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
