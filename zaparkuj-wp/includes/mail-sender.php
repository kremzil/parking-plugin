<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('zp_get_i18n')) {
  require_once plugin_dir_path(__FILE__) . 'i18n.php';
}

function zp_email_fmt_date($mysql_dt) {
  $dt = trim((string)$mysql_dt);
  if ($dt === '') return '';
  $parts = explode(' ', $dt, 2);
  $d = $parts[0] ?? '';
  $dd = explode('-', $d);
  if (count($dd) !== 3) return $d;
  return $dd[2] . '.' . $dd[1] . '.' . $dd[0];
}

function zp_email_fmt_time($mysql_dt) {
  $dt = trim((string)$mysql_dt);
  if ($dt === '') return '';
  $parts = explode(' ', $dt, 2);
  $t = $parts[1] ?? '';
  return $t ? substr($t, 0, 5) : '';
}

function zp_email_fmt_duration($minutes, $lang = 'sk') {
  $m = intval($minutes);
  if ($m <= 0) return '';
  if ($m < 60) return $m . ' ' . (zp_get_i18n($lang)['email_minutes_unit'] ?? 'min');
  $h = intdiv($m, 60);
  $rm = $m % 60;
  if ($rm === 0) return $h . ' hodín';
  return $h . 'h ' . $rm . 'min';
}

function zp_email_replace_year($text, $paid_at = '') {
  $s = (string)$text;
  if ($s === '') return $s;
  $year = '';
  $ts = $paid_at ? strtotime($paid_at) : false;
  if ($ts) $year = date('Y', $ts);
  if ($year === '') $year = date('Y');
  return str_replace('{{year}}', $year, $s);
}

function zp_email_get_tariffs_array() {
  // Prefer plugin method if available, fall back to raw option.
  if (class_exists('Zaparkuj_WP_045') && method_exists('Zaparkuj_WP_045', 'get_tariffs_array')) {
    return Zaparkuj_WP_045::get_tariffs_array();
  }
  $json = get_option('zp_tariffs_json', '');
  if (!$json) return ['zones' => []];
  $arr = json_decode($json, true);
  return is_array($arr) ? $arr : ['zones' => []];
}

function zp_email_find_zone_cfg($zone_id) {
  $zones = zp_email_get_tariffs_array()['zones'] ?? [];
  $want = strtoupper(trim((string)$zone_id));
  foreach ($zones as $z) {
    $id = isset($z['id']) ? strtoupper(trim((string)$z['id'])) : '';
    if ($id && $id === $want) return $z;
  }
  return null;
}

function zp_email_zone_label($zone_id) {
  $z = zp_email_find_zone_cfg($zone_id);
  if ($z && isset($z['label']) && $z['label']) return (string)$z['label'];
  return strtoupper(trim((string)$zone_id));
}

function zp_email_zone_rate_per_hour($zone_id) {
  $z = zp_email_find_zone_cfg($zone_id);
  if (!$z) return null;
  if (isset($z['base_30']) && is_numeric($z['base_30'])) return floatval($z['base_30']) * 2.0;
  if (isset($z['rate_per_hour']) && is_numeric($z['rate_per_hour'])) return floatval($z['rate_per_hour']);
  if (isset($z['rate_per_min']) && is_numeric($z['rate_per_min'])) return floatval($z['rate_per_min']) * 60.0;
  return null;
}

function zp_build_receipt_template_vars($data) {
  $lang = $data['lang'] ?? (function_exists('zp_get_lang') ? zp_get_lang() : 'sk');
  $i18n = function_exists('zp_get_i18n') ? zp_get_i18n($lang) : [];

  $zone_id = $data['zone_id'] ?? '';
  $paid_at = isset($data['paid_at']) && $data['paid_at'] ? (string)$data['paid_at'] : current_time('mysql');
  $start_at = isset($data['started_at']) && $data['started_at'] ? (string)$data['started_at'] : $paid_at;
  $end_at = isset($data['expires_at']) && $data['expires_at'] ? (string)$data['expires_at'] : '';
  $minutes = isset($data['minutes']) ? intval($data['minutes']) : 0;
  if (!$end_at && $start_at && $minutes > 0) {
    $end_at = date('Y-m-d H:i:s', strtotime($start_at) + ($minutes * 60));
  }

  $transaction_id = '';
  if (!empty($data['order_number'])) $transaction_id = (string)$data['order_number'];
  elseif (!empty($data['payment_id'])) $transaction_id = (string)$data['payment_id'];

  $rate_per_hour = zp_email_zone_rate_per_hour($zone_id);
  $rate_per_hour_str = ($rate_per_hour !== null) ? number_format($rate_per_hour, 2, '.', '') . ' €' : '';
  $copyright_raw = $i18n['email_footer'] ?? ($i18n['email_copyright'] ?? '© {{year}} parkovne.sk');
  $copyright = zp_email_replace_year($copyright_raw, $paid_at);

  return [
    'lang' => $lang,

    // Copy / labels
    't_title' => $i18n['email_title'] ?? 'Potvrdenie platby',
    't_banner_title' => $i18n['email_banner_title'] ?? 'Potvrdenie o platbe parkovania',
    't_greeting' => $i18n['email_greeting'] ?? 'Dobrý deň',
    't_thank_you' => $i18n['email_thank_you'] ?? 'Ďakujeme za vašu platbu!',
    't_confirmation_text' => $i18n['email_confirmation_text'] ?? ($i18n['email_intro'] ?? ''),
    't_parking_details' => $i18n['email_parking_details'] ?? 'Detaily parkovania',
    't_payment_details' => $i18n['email_payment_details'] ?? 'Detaily platby',
    't_parking_period' => $i18n['email_parking_period'] ?? 'Obdobie parkovania',
    't_from' => $i18n['email_from'] ?? 'Od',
    't_to' => $i18n['email_to'] ?? 'Do',
    't_transaction_id' => $i18n['email_transaction_id'] ?? 'ID transakcie',
    't_price_per_hour' => $i18n['email_price_per_hour'] ?? 'Cena za hodinu',
    't_total_paid' => $i18n['email_total_paid'] ?? 'Celkom zaplatené',
    't_qr_label' => $i18n['email_qr_label'] ?? 'QR kód parkovacieho lístka',
    't_zone_number' => $i18n['email_zone_number'] ?? 'Číslo zóny',
    't_label_spz' => $i18n['email_label_spz'] ?? 'ŠPZ:',
    't_label_zone' => $i18n['email_label_zone'] ?? 'Zóna:',
    't_label_duration' => $i18n['email_label_duration'] ?? 'Trvanie:',
    't_label_amount' => $i18n['email_label_amount'] ?? 'Suma:',
    't_label_paid_at' => $i18n['email_label_paid_at'] ?? 'Čas platby:',
    't_footer_note' => $i18n['email_footer_note'] ?? 'Tento e-mail slúži ako potvrdenie o úhrade.',
    't_thanks' => $i18n['email_thanks'] ?? 'Ďakujeme, že parkujete s nami',
    't_logo_alt' => $i18n['email_logo_alt'] ?? 'Parking',
    't_copyright' => $copyright,
    't_important_info' => $i18n['email_important_info'] ?? 'Dôležité informácie',
    't_info1' => $i18n['email_info1'] ?? '',
    't_info2' => $i18n['email_info2'] ?? '',
    't_info3' => $i18n['email_info3'] ?? '',
    't_info4' => $i18n['email_info4'] ?? '',
    't_need_help' => $i18n['email_need_help'] ?? 'Potrebujete pomoc?',
    't_contact_us' => $i18n['email_contact_us'] ?? 'Ak máte akékoľvek otázky, kontaktujte nás na',

    // Data
    'spz' => $data['spz'] ?? '',
    'zone_id' => $zone_id,
    'zone_label' => zp_email_zone_label($zone_id),
    'transaction_id' => $transaction_id,
    'start_date' => zp_email_fmt_date($start_at),
    'start_time' => zp_email_fmt_time($start_at),
    'end_date' => zp_email_fmt_date($end_at),
    'end_time' => zp_email_fmt_time($end_at),
    'duration_text' => zp_email_fmt_duration($minutes, $lang),
    'price_per_hour' => $rate_per_hour_str,
    'amount_eur' => isset($data['amount_cents']) ? number_format(((int)$data['amount_cents'])/100, 2, '.', '') : '',
    'paid_at' => $paid_at,

    // Support
    'support_email' => 'support@parkovne.sk',
  ];
}

function zp_mail_headers() {
  $from = 'noreply@' . wp_parse_url(home_url(), PHP_URL_HOST);
  return [
    'Content-Type: text/html; charset=UTF-8',
    'From: Zaparkuj <' . $from . '>'
  ];
}

function zp_render_email_template($vars = []){
  $tpl = plugin_dir_path(__FILE__) . '../templates/email.html.php';
  $html = file_exists($tpl) ? file_get_contents($tpl) : '';
  if (!$html) return '';
  foreach ($vars as $k => $v) {
    $html = str_replace('{{'.$k.'}}', esc_html($v), $v === null ? '' : $html);
  }
  foreach ($vars as $k => $v) {
    $html = str_replace('{{'.$k.'}}', esc_html($v), $html);
  }
  return $html;
}

function zp_send_receipt($email, $data){
  $vars = zp_build_receipt_template_vars($data);
  $lang = $vars['lang'] ?? (function_exists('zp_get_lang') ? zp_get_lang() : 'sk');
  $i18n = function_exists('zp_get_i18n') ? zp_get_i18n($lang) : [];
  $subject = $i18n['email_subject'] ?? 'Potvrdenie platby parkovania';
  $body = zp_render_email_template($vars);
  if (!$body) {
    $label_spz = $i18n['email_label_spz'] ?? 'ŠPZ:';
    $label_zone = $i18n['email_label_zone'] ?? 'Zóna:';
    $label_duration = $i18n['email_label_duration'] ?? 'Trvanie:';
    $label_amount = $i18n['email_label_amount'] ?? 'Suma:';
    $label_paid_at = $i18n['email_label_paid_at'] ?? 'Čas platby:';
    $unit_min = $i18n['email_minutes_unit'] ?? 'min';
    $label_map = $i18n['label_map'] ?? 'Mapa';
    $body = sprintf(
      "%s %s\n%s %s\n%s %s %s\n%s %s €\n%s %s\n%s: https://www.openstreetmap.org/?mlat=%s&mlon=%s#map=18/%s/%s",
      $label_spz, $data['spz'] ?? '-',
      $label_zone, $data['zone_id'] ?? '-',
      $label_duration, $data['minutes'] ?? '-', $unit_min,
      $label_amount, isset($data['amount_cents']) ? number_format($data['amount_cents']/100, 2, '.', '') : '-',
      $label_paid_at, current_time('mysql'),
      $label_map,
      isset($data['lat']) ? $data['lat'] : '', isset($data['lng']) ? $data['lng'] : '',
      isset($data['lat']) ? $data['lat'] : '', isset($data['lng']) ? $data['lng'] : ''
    );
    $headers = ['Content-Type: text/plain; charset=UTF-8','From: Zaparkuj <noreply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>'];
  } else {
    $headers = zp_mail_headers();
  }
  $ok = wp_mail($email, $subject, $body, $headers);
  if (!$ok) {
    error_log('[Zaparkuj] wp_mail failed for ' . $email . ' (' . $subject . ')');
  }
  return $ok;
}

add_action('wp_mail_failed', function(WP_Error $e){
  error_log('[Zaparkuj] wp_mail_failed: ' . implode(' | ', $e->get_error_messages()));
});
