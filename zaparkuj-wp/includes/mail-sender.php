<?php
if (!defined('ABSPATH')) exit;

if (!function_exists('zp_get_i18n')) {
  require_once plugin_dir_path(__FILE__) . 'i18n.php';
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
  $lang = $data['lang'] ?? (function_exists('zp_get_lang') ? zp_get_lang() : 'sk');
  $i18n = function_exists('zp_get_i18n') ? zp_get_i18n($lang) : [];
  $subject = $i18n['email_subject'] ?? 'Potvrdenie platby parkovania';
  $body = zp_render_email_template([
    'lang' => $lang,
    't_title' => $i18n['email_title'] ?? 'Potvrdenie platby',
    't_banner_title' => $i18n['email_banner_title'] ?? 'Potvrdenie o platbe parkovania',
    't_intro' => $i18n['email_intro'] ?? 'Ďakujeme za Vašu platbu. Tu sú detaily Vášho parkovania:',
    't_label_spz' => $i18n['email_label_spz'] ?? 'ŠPZ:',
    't_label_zone' => $i18n['email_label_zone'] ?? 'Zóna:',
    't_label_duration' => $i18n['email_label_duration'] ?? 'Trvanie:',
    't_label_amount' => $i18n['email_label_amount'] ?? 'Suma:',
    't_label_paid_at' => $i18n['email_label_paid_at'] ?? 'Čas platby:',
    't_minutes_unit' => $i18n['email_minutes_unit'] ?? 'min',
    't_footer_note' => $i18n['email_footer_note'] ?? 'Tento e-mail slúži ako potvrdenie o úhrade.',
    't_thanks' => $i18n['email_thanks'] ?? 'Ďakujeme, že parkujete s nami 🚗',
    't_logo_alt' => $i18n['email_logo_alt'] ?? 'Parking',
    't_copyright' => $i18n['email_copyright'] ?? '© 2025 pkosice.sk – Všetky práva vyhradené',
    'spz'        => $data['spz'] ?? '',
    'zone_id'    => $data['zone_id'] ?? '',
    'minutes'    => $data['minutes'] ?? '',
    'amount_eur' => isset($data['amount_cents']) ? number_format($data['amount_cents']/100, 2, '.', '') : '',
    'paid_at'    => current_time('mysql'),
    'lat'        => isset($data['lat']) ? $data['lat'] : '',
    'lng'        => isset($data['lng']) ? $data['lng'] : '',
  ]);
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
