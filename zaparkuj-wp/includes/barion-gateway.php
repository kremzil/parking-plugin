<?php
/**
 * Barion Payment Gateway Integration
 * Handles payment flow: Prepare → Redirect → Callback → IPN
 */

if (!defined('ABSPATH')) exit;

// Подключение Barion SDK
$barion_lib_path = __DIR__ . '/../vendor/barion/library/';

if (file_exists($barion_lib_path . 'autoload.php')) {
  require_once $barion_lib_path . 'autoload.php';
  
  // Подключаем BarionClient отдельно (он не в namespace)
  if (file_exists($barion_lib_path . 'BarionClient.php')) {
    require_once $barion_lib_path . 'BarionClient.php';
  }
} elseif (file_exists(__DIR__ . '/../vendor/autoload.php')) {
  require_once __DIR__ . '/../vendor/autoload.php';
}

// Проверка загрузки
if (!class_exists('\Barion\BarionClient')) {
  error_log('ZP Barion Error: BarionClient не загружен. Путь: ' . $barion_lib_path);
}

// Use-директивы для удобства
use Barion\BarionClient;
use Barion\Models\Payment\PreparePaymentRequestModel;
use Barion\Models\Payment\PaymentTransactionModel;
use Barion\Models\Common\ItemModel;
use Barion\Enumerations\PaymentType;
use Barion\Enumerations\FundingSourceType;
use Barion\Enumerations\Currency;
use Barion\Enumerations\UILocale;
use Barion\Enumerations\BarionEnvironment;
use Barion\Models\Payment\PaymentStateRequestModel;

class ZP_Barion_Gateway {
  
  const OPT_POSKEY = 'zp_barion_poskey';
  const OPT_ENV = 'zp_barion_environment'; // test or prod
  const OPT_PAYEE = 'zp_barion_payee_email';
  
  private static $barion = null;
  
  /**
   * Инициализация Barion клиента
   */
  public static function get_client() {
    if (self::$barion !== null) return self::$barion;
    
    $poskey = get_option(self::OPT_POSKEY, '');
    $env = get_option(self::OPT_ENV, 'test');
    
    if (!$poskey) {
      throw new Exception('Barion POSKey nie je nastavený');
    }
    
    $barionEnv = ($env === 'prod') 
      ? BarionEnvironment::Prod 
      : BarionEnvironment::Test;
    
    self::$barion = new BarionClient($poskey, 2, $barionEnv);
    return self::$barion;
  }
  
  /**
   * Создание платежа
   * 
   * @param array $data {
   *   spz, email, zone_id, minutes, lat, lng, amount_cents
   * }
   * @return array {paymentId, redirectUrl, status}
   */
  public static function prepare_payment($data) {
    try {
      $client = self::get_client();
      $payee_email = get_option(self::OPT_PAYEE, get_bloginfo('admin_email'));
      
      // Создание уникального OrderNumber
      $order_number = 'ZP-' . time() . '-' . substr(md5($data['spz'] . $data['email']), 0, 6);
      
      // Сохранение данных заказа в transient (временное хранилище)
      $lang = isset($data['lang']) ? strtolower(trim((string)$data['lang'])) : 'sk';
      if (!in_array($lang, ['sk','en','pl','hu','sv','zh'], true)) $lang = 'sk';

      $order_data = [
        'spz' => $data['spz'],
        'email' => $data['email'],
        'zone_id' => $data['zone_id'],
        'requested_minutes' => isset($data['requested_minutes']) ? intval($data['requested_minutes']) : intval($data['minutes']),
        'effective_minutes' => isset($data['effective_minutes']) ? intval($data['effective_minutes']) : intval($data['minutes']),
        'minutes' => isset($data['effective_minutes']) ? intval($data['effective_minutes']) : intval($data['minutes']),
        'lat' => isset($data['lat']) ? $data['lat'] : null,
        'lng' => isset($data['lng']) ? $data['lng'] : null,
        'amount_cents' => $data['amount_cents'],
        'adjustment_reason' => isset($data['adjustment_reason']) ? (string)$data['adjustment_reason'] : null,
        'created_at' => current_time('mysql'),
        'lang' => $lang
      ];
      set_transient('zp_order_' . $order_number, $order_data, 3600); // 1 час
      
      // Подготовка данных для Barion
      $trans = new PaymentTransactionModel();
      $trans->POSTransactionId = $order_number . '-T1';
      $trans->Payee = $payee_email;
      $trans->Total = $data['amount_cents'] / 100; // В EUR
      $trans->Comment = sprintf(
        'Parkovanie %s, Zóna %s, %d min',
        $data['spz'],
        $data['zone_id'],
        $data['minutes']
      );
      
      $item = new ItemModel();
      $item->Name = sprintf('Parkovanie - Zóna %s', $data['zone_id']);
      $item->Description = sprintf('%d minút - %s', $data['minutes'], $data['spz']);
      $item->Quantity = 1;
      $item->Unit = 'ks';
      $item->UnitPrice = $data['amount_cents'] / 100;
      $item->ItemTotal = $data['amount_cents'] / 100;
      $item->SKU = 'PARKING-' . $data['zone_id'];
      
      $trans->Items = [$item];
      
      // Создание платёжного запроса
      $pmt = new PreparePaymentRequestModel();
      $pmt->GuestCheckout = true; // Разрешить без регистрации
      $pmt->PaymentType = PaymentType::Immediate;
      $pmt->FundingSources = [FundingSourceType::All];
      $pmt->PaymentRequestId = $order_number;
      $pmt->OrderNumber = $order_number;
      // Pre-fill customer email in Barion checkout form.
      $pmt->PayerHint = isset($data['email']) ? trim((string)$data['email']) : null;
      $pmt->Currency = Currency::EUR;
      $pmt->Transactions = [$trans];
      $pmt->Locale = UILocale::SK; // Словацкий интерфейс
      
      // URL для редиректа после оплаты
      $pmt->RedirectUrl = add_query_arg(
        ['zp_barion_callback' => '1', 'lang' => $lang],
        home_url('/')
      );
      $pmt->CallbackUrl = rest_url('zaparkuj/v1/barion-ipn');
      
      // Отправка запроса
      $result = $client->PreparePayment($pmt);
      
      // Логирование ответа для отладки
      error_log('=== BARION PREPARE PAYMENT RESPONSE ===');
      error_log('PaymentId: ' . (isset($result->PaymentId) ? $result->PaymentId : 'NOT SET'));
      error_log('PaymentRedirectUrl: ' . (isset($result->PaymentRedirectUrl) ? $result->PaymentRedirectUrl : 'NOT SET'));
      error_log('GatewayUrl: ' . (isset($result->GatewayUrl) ? $result->GatewayUrl : 'NOT SET'));
      $status_value = (isset($result->Status) && $result->Status instanceof \BackedEnum)
        ? $result->Status->value
        : (isset($result->Status) ? $result->Status : 'NOT SET');
      error_log('Status: ' . $status_value);
      error_log('Full result: ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
      
      if (!$result) {
        throw new Exception('Barion nevrátil žiadnu odpoveď');
      }
      
      // Проверка ошибок от Barion
      if (!empty($result->Errors) && is_array($result->Errors)) {
        $error_details = [];
        foreach ($result->Errors as $e) {
          $error_details[] = sprintf(
            '[%s] %s: %s',
            isset($e->ErrorCode) ? $e->ErrorCode : 'N/A',
            isset($e->Title) ? $e->Title : 'N/A',
            isset($e->Description) ? $e->Description : ''
          );
        }
        $error_msg = implode(' | ', $error_details);
        error_log('Barion API errors: ' . $error_msg);
        throw new Exception('Barion API chyba: ' . $error_msg);
      }
      
      if (!property_exists($result, 'PaymentId') || empty($result->PaymentId)) {
        error_log('Barion response missing PaymentId');
        throw new Exception('Barion nevrátil PaymentId');
      }
      
      // Сохранение PaymentId для последующей проверки
      update_option('zp_barion_last_payment_' . $order_number, $result->PaymentId);
      
      $redirect_url = null;
      if (property_exists($result, 'PaymentRedirectUrl') && !empty($result->PaymentRedirectUrl)) {
        $redirect_url = $result->PaymentRedirectUrl;
      } elseif (property_exists($result, 'GatewayUrl') && !empty($result->GatewayUrl)) {
        $redirect_url = $result->GatewayUrl;
      }

      // Проверка наличия redirect URL
      if (!$redirect_url) {
        error_log('Barion response missing redirect URL. PaymentId: ' . $result->PaymentId);
        throw new Exception('Barion nevrátil redirect URL (PaymentId: ' . $result->PaymentId . ')');
      }

      // Persist order data (pending) so we can still finalize + send email even if transient expires.
      // This also makes the success flow robust if Barion callback arrives late or IPN is delayed.
      try {
        global $wpdb;
        $table_transactions = $wpdb->prefix . 'zp_transactions';
        $existing_tx = $wpdb->get_row($wpdb->prepare(
          "SELECT id FROM $table_transactions WHERE order_number = %s LIMIT 1",
          $order_number
        ), ARRAY_A);

        $row = [
          'barion_payment_id' => $result->PaymentId,
          'order_number' => $order_number,
          'spz' => $order_data['spz'],
          'email' => $order_data['email'],
          'zone_id' => $order_data['zone_id'],
          'lat' => $order_data['lat'],
          'lng' => $order_data['lng'],
          'minutes' => $order_data['minutes'],
          'amount_cents' => $order_data['amount_cents'],
          'status' => 'pending',
          'created_at' => $order_data['created_at'],
          'paid_at' => null,
        ];

        if ($existing_tx && isset($existing_tx['id'])) {
          $wpdb->update($table_transactions, $row, ['id' => intval($existing_tx['id'])]);
        } else {
          // If insert fails (e.g., unique key), handle_successful_payment will recover by updating by payment id.
          $wpdb->insert($table_transactions, $row);
        }
      } catch (Exception $e) {
        error_log('ZP: Failed to persist pending transaction for ' . $order_number . ': ' . $e->getMessage());
      }
      
      return [
        'success' => true,
        'paymentId' => $result->PaymentId,
        'redirectUrl' => $redirect_url,
        'orderNumber' => $order_number,
        'status' => ($result->Status instanceof \BackedEnum) ? $result->Status->value : $result->Status
      ];
      
    } catch (Exception $e) {
      error_log('ZP Barion prepare error: ' . $e->getMessage());
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }
  
  /**
   * Проверка статуса платежа
   */
  public static function get_payment_state($payment_id) {
    try {
      $client = self::get_client();
      
      $state = $client->GetPaymentState($payment_id);
      
      if (!$state) {
        throw new Exception('Failed to get payment state');
      }
      
      return [
        'success' => true,
        'status' => ($state->Status instanceof \BackedEnum) ? $state->Status->value : $state->Status,
        'paymentId' => $state->PaymentId,
        'orderNumber' => $state->OrderNumber ?? null,
        'total' => $state->Total ?? 0,
        'currency' => $state->Currency ?? 'EUR',
        'completedAt' => $state->CompletedAt ?? null
      ];
      
    } catch (Exception $e) {
      error_log('ZP Barion get state error: ' . $e->getMessage());
      return [
        'success' => false,
        'error' => $e->getMessage()
      ];
    }
  }
  
  /**
   * Обработка успешного платежа
   */
  public static function handle_successful_payment($payment_id, $order_number) {
    global $wpdb;
    $table_transactions = $wpdb->prefix . 'zp_transactions';
    $table_parkings = $wpdb->prefix . 'zp_parkings';

    // Get order data from transient first (fast path), fall back to DB (pending row) if transient expired.
    $order_data = get_transient('zp_order_' . $order_number);
    if (!$order_data || !is_array($order_data)) {
      $order_data = $wpdb->get_row($wpdb->prepare(
        "SELECT spz, email, zone_id, lat, lng, minutes, amount_cents, created_at, paid_at FROM $table_transactions WHERE order_number = %s OR barion_payment_id = %s ORDER BY id DESC LIMIT 1",
        $order_number,
        $payment_id
      ), ARRAY_A);
    }
    if (!$order_data || !is_array($order_data)) {
      error_log('ZP: Order data not found for ' . $order_number . ' (payment ' . $payment_id . ')');
      return false;
    }

    // Find existing transaction row for this payment/order.
    $existing = $wpdb->get_row($wpdb->prepare(
      "SELECT * FROM $table_transactions WHERE barion_payment_id = %s OR order_number = %s ORDER BY id DESC LIMIT 1",
      $payment_id,
      $order_number
    ), ARRAY_A);

    $was_completed = (is_array($existing) && (($existing['status'] ?? '') === 'completed'));
    if ($was_completed) {
      // Already finalized; don't create another parking or spam another email.
      return true;
    }

    $paid_at = current_time('mysql');

    // Upsert transaction as completed.
    $tx_row = [
      'barion_payment_id' => $payment_id,
      'order_number' => $order_number,
      'spz' => $order_data['spz'] ?? '',
      'email' => $order_data['email'] ?? '',
      'zone_id' => $order_data['zone_id'] ?? '',
      'lat' => isset($order_data['lat']) ? $order_data['lat'] : null,
      'lng' => isset($order_data['lng']) ? $order_data['lng'] : null,
      'minutes' => intval($order_data['minutes'] ?? 0),
      'amount_cents' => intval($order_data['amount_cents'] ?? 0),
      'status' => 'completed',
      'created_at' => $order_data['created_at'] ?? $paid_at,
      'paid_at' => $paid_at,
    ];

    if (is_array($existing) && isset($existing['id'])) {
      $wpdb->update($table_transactions, $tx_row, ['id' => intval($existing['id'])]);
      $transaction_id = intval($existing['id']);
    } else {
      $wpdb->insert($table_transactions, $tx_row);
      $transaction_id = intval($wpdb->insert_id);
    }
    
    // Создать активную парковку
    if ($transaction_id) {
      $started_at = current_time('mysql');
      $start_ts = current_time('timestamp', true);
      $effective_minutes = intval($order_data['minutes'] ?? 0);
      $expires_ts = Zaparkuj_WP_045::compute_effective_parking_end_ts(
        $order_data['zone_id'] ?? '',
        $effective_minutes,
        $start_ts,
        $order_data['spz'] ?? ''
      );
      if ($expires_ts <= $start_ts) $expires_ts = $start_ts + ($effective_minutes * 60);
      $expires_at = wp_date('Y-m-d H:i:s', $expires_ts);

      $has_parking = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM $table_parkings WHERE transaction_id = %d LIMIT 1",
        $transaction_id
      ));
      if (!$has_parking) {
        $wpdb->insert($table_parkings, [
          'transaction_id' => $transaction_id,
          'spz' => $order_data['spz'],
          'zone_id' => $order_data['zone_id'],
          'started_at' => $started_at,
          'expires_at' => $expires_at,
          'extended_minutes' => 0,
          'is_active' => 1
        ], [
          '%d', '%s', '%s', '%s', '%s', '%d', '%d'
        ]);
      }
    }
    
    // Отправить квитанцию
    if (function_exists('zp_send_receipt')) {
      zp_send_receipt($order_data['email'], array_merge($order_data, [
        'payment_id' => $payment_id,
        'order_number' => $order_number,
        'paid_at' => $paid_at,
        'started_at' => isset($started_at) ? $started_at : null,
        'expires_at' => isset($expires_at) ? $expires_at : null,
      ]));
    }
    
    // Очистить transient
    delete_transient('zp_order_' . $order_number);
    
    do_action('zp_payment_completed', $order_data, $payment_id, $transaction_id);
    
    return true;
  }
  
  /**
   * Callback после возврата с Barion
   */
  public static function handle_redirect_callback() {
    if (!isset($_GET['zp_barion_callback']) || $_GET['zp_barion_callback'] !== '1') {
      return;
    }
    
    $payment_id = isset($_GET['paymentId']) ? sanitize_text_field($_GET['paymentId']) : null;
    $lang = isset($_GET['lang']) ? strtolower(sanitize_text_field($_GET['lang'])) : '';
    if (!in_array($lang, ['sk','en','pl','hu','sv','zh'], true)) $lang = '';
    
    if (!$payment_id) {
      wp_die('Neplatný paymentId');
    }
    
    // Проверить статус платежа
    $state = self::get_payment_state($payment_id);
    
    if (!$state['success']) {
      $args = ['zp_payment' => 'error'];
      if ($lang) $args['lang'] = $lang;
      wp_redirect(add_query_arg($args, home_url('/')));
      exit;
    }
    
    if ($state['status'] === 'Succeeded') {
      self::handle_successful_payment($payment_id, $state['orderNumber']);
      $args = ['zp_payment' => 'success', 'pid' => $payment_id];
      if ($lang) $args['lang'] = $lang;
      wp_redirect(add_query_arg($args, home_url('/')));
    } else {
      $args = ['zp_payment' => 'failed'];
      if ($lang) $args['lang'] = $lang;
      wp_redirect(add_query_arg($args, home_url('/')));
    }
    exit;
  }
  
  /**
   * IPN (Instant Payment Notification) от Barion
   * Более надёжный способ получения уведомлений о платеже
   */
  public static function handle_ipn($request) {
    $payment_id = $request->get_param('PaymentId');
    
    if (!$payment_id) {
      return new WP_Error('no_payment_id', 'PaymentId missing', ['status' => 400]);
    }
    
    // Проверить статус
    $state = self::get_payment_state($payment_id);
    
    if ($state['success'] && $state['status'] === 'Succeeded') {
      self::handle_successful_payment($payment_id, $state['orderNumber']);
    }
    
    return new WP_REST_Response(['received' => true], 200);
  }
}

// Регистрация callback при загрузке WordPress
add_action('template_redirect', ['ZP_Barion_Gateway', 'handle_redirect_callback']);
