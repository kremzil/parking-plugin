# Migrácia zo Stripe na Barion

Tento dokument popisuje kroky pre prechod z platobnej brány Stripe na Barion.

## 📋 Prečo Barion?

- ✅ Lokálna podpora pre SK/CZ/HU trh
- ✅ Nižšie poplatky pre lokálne karty
- ✅ Slovenský zákaznícky servis
- ✅ EU compliance (PSD2, GDPR)
- ✅ Barion Wallet integrácia

---

## 🚀 Kroky migrácie

### 1. Registrácia v Barion

1. Prejdite na https://www.barion.com/sk/
2. Kliknite na "Zaregistrovať sa"
3. Vytvorte **Business účet** (nie Personal)
4. Vyplňte firemné údaje
5. Overte email a telefón

### 2. Získanie POSKey (API kľúč)

**Test prostredie:**
1. Prihláste sa na https://secure.test.barion.com/
2. Menu → **My stores** → **Create new store**
3. Názov: "Zaparkuj Test"
4. Skopírujte **POSKey**

**Produkčné prostredie:**
1. Prihláste sa na https://secure.barion.com/
2. Menu → **My stores** → **Create new store**
3. Názov: "Zaparkuj"
4. **Dôležité**: Aktivujte "Payment gateway" funkciu
5. Skopírujte **POSKey**

### 3. Inštalácia Barion PHP SDK

**Pomocou Composer:**
```bash
cd /path/to/wordpress/wp-content/plugins/zaparkuj-wp-0_4_5
composer require barion/barion-web-php
```

**Manuálne:**
```bash
cd /path/to/wordpress/wp-content/plugins/zaparkuj-wp-0_4_5
wget https://github.com/barion/barion-web-php/archive/refs/heads/master.zip
unzip master.zip
mv barion-web-php-master/library ./barion-sdk
```

### 4. Aktualizácia pluginu

**A. Pridať Barion súbory**

Skopírujte nové súbory do pluginu:
- `includes/barion-gateway.php` - hlavná logika Barion
- `includes/rest-barion.php` - REST API endpointy
- `assets/js/checkout-barion.js` - frontend

**B. Upraviť zaparkuj-wp.php**

Pridajte do metódy `init()`:
```php
// Podpora pre Barion
require_once plugin_dir_path(__FILE__) . 'includes/barion-gateway.php';
require_once plugin_dir_path(__FILE__) . 'includes/rest-barion.php';
```

Pridajte do `register_rest()`:
```php
self::register_rest_barion();
```

Pridajte do `register_settings()`:
```php
// Barion nastavenia
register_setting('zaparkuj-settings', ZP_Barion_Gateway::OPT_POSKEY);
register_setting('zaparkuj-settings', ZP_Barion_Gateway::OPT_ENV);
register_setting('zaparkuj-settings', ZP_Barion_Gateway::OPT_PAYEE);
```

**C. Upraviť admin panel**

V metóde `admin_settings_page()` pridajte sekciu:
```php
<h3>Barion nastavenia</h3>
<table class="form-table">
  <tr>
    <th>POSKey</th>
    <td>
      <input type="text" name="<?= ZP_Barion_Gateway::OPT_POSKEY ?>" 
             value="<?= esc_attr(get_option(ZP_Barion_Gateway::OPT_POSKEY, '')) ?>" 
             class="regular-text" />
      <p class="description">Získajte na secure.barion.com → My stores</p>
    </td>
  </tr>
  <tr>
    <th>Prostredie</th>
    <td>
      <select name="<?= ZP_Barion_Gateway::OPT_ENV ?>">
        <option value="test" <?= selected(get_option(ZP_Barion_Gateway::OPT_ENV, 'test'), 'test') ?>>Test</option>
        <option value="prod" <?= selected(get_option(ZP_Barion_Gateway::OPT_ENV, 'test'), 'prod') ?>>Produkcia</option>
      </select>
    </td>
  </tr>
  <tr>
    <th>Payee Email</th>
    <td>
      <input type="email" name="<?= ZP_Barion_Gateway::OPT_PAYEE ?>" 
             value="<?= esc_attr(get_option(ZP_Barion_Gateway::OPT_PAYEE, get_bloginfo('admin_email'))) ?>" 
             class="regular-text" />
      <p class="description">Email účtu, na ktorý prídu platby</p>
    </td>
  </tr>
</table>
```

**D. Zmeniť JS v shortcode**

V metóde `shortcode()` nahraďte:
```php
// Bolo:
wp_enqueue_script('zaparkuj-checkout');

// Nové:
wp_enqueue_script('zaparkuj-checkout-barion', 
  plugin_dir_url(__FILE__) . 'assets/js/checkout-barion.js', 
  [], '0.5.0', true);
```

Aktualizujte konfiguráciu:
```php
$cfg = [
  // Už nie je potrebné publishableKey
  'rest' => [
    'barion_prepare' => esc_url_raw(rest_url('zaparkuj/v1/barion-prepare')),
    'barion_ipn' => esc_url_raw(rest_url('zaparkuj/v1/barion-ipn')),
  ],
  // ... ostatné ako predtým
];
```

### 5. Vytvorenie tabuľky v databáze (voliteľné, ale odporúčané)

```sql
CREATE TABLE IF NOT EXISTS wp_zp_transactions (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  barion_payment_id VARCHAR(255) UNIQUE,
  order_number VARCHAR(100),
  spz VARCHAR(20),
  email VARCHAR(255),
  zone_id VARCHAR(10),
  lat DECIMAL(10,7),
  lng DECIMAL(10,7),
  minutes INT,
  amount_cents INT,
  status VARCHAR(20),
  created_at DATETIME,
  paid_at DATETIME,
  INDEX idx_spz (spz),
  INDEX idx_status (status),
  INDEX idx_order (order_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

Pridajte do pluginu aktivačný hook:
```php
register_activation_hook(__FILE__, function() {
  global $wpdb;
  $table = $wpdb->prefix . 'zp_transactions';
  $charset = $wpdb->get_charset_collate();
  
  $sql = "CREATE TABLE IF NOT EXISTS $table (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    barion_payment_id VARCHAR(255) UNIQUE,
    order_number VARCHAR(100),
    spz VARCHAR(20),
    email VARCHAR(255),
    zone_id VARCHAR(10),
    lat DECIMAL(10,7),
    lng DECIMAL(10,7),
    minutes INT,
    amount_cents INT,
    status VARCHAR(20),
    created_at DATETIME,
    paid_at DATETIME,
    INDEX idx_spz (spz),
    INDEX idx_status (status),
    INDEX idx_order (order_number)
  ) $charset;";
  
  require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
  dbDelta($sql);
});
```

### 6. Konfigurácia v WordPress admin

1. Prihláste sa do WordPress admin
2. **Nastavenia → Zaparkuj**
3. Vyplňte:
   - **POSKey**: váš kľúč z Barion
   - **Prostredie**: Test (pre testovanie) / Produkcia (naživo)
   - **Payee Email**: email vášho Barion účtu
4. Kliknite **Uložiť zmeny**

### 7. Testovanie

**Test karty pre Barion test prostredie:**
- Číslo: `5559 0574 4061 2346`
- Expirácia: `12/28`
- CVC: `123`
- 3D Secure heslo: `bws`

**Test flow:**
1. Otvorte stránku s `[zaparkuj]` shortcode
2. Vyplňte ŠPZ, email, zvoľte trvanie
3. Kliknite "Zaplatiť"
4. Presmie na Barion test stránku
5. Zadajte testovaciu kartu
6. Dokončite platbu
7. Vrátite sa na stránku
8. Skontrolujte potvrdzovaciu správu
9. Skontrolujte email

### 8. Spustenie na produkciu

1. Zmeňte **Prostredie** na **Produkcia**
2. Zadajte produkčný **POSKey**
3. Overťte, že **Payee Email** je správny
4. Uložte zmeny
5. Otestujte reálnou platbou (malá suma)

---

## 🔧 Rozdiely v používaní

### Stripe (predtým)
```javascript
// Frontend: Stripe Elements embedding
const elements = stripe.elements();
const card = elements.create('card');
card.mount('#card-element');
```

### Barion (teraz)
```javascript
// Frontend: Redirect flow
const result = await createBarionPayment();
window.location.href = result.redirectUrl; // → Barion stránka
```

### Stripe (webhook)
```php
$event = \Stripe\Webhook::constructEvent($payload, $sig, $secret);
```

### Barion (IPN)
```php
$state = ZP_Barion_Gateway::get_payment_state($paymentId);
if ($state['status'] === 'Succeeded') { /* OK */ }
```

---

## ⚠️ Migračné poznámky

### Čo sa mení:
- ❌ Odstránené: Stripe SDK, publishableKey, Payment Elements
- ✅ Pridané: Barion SDK, POSKey, redirect flow
- ✅ Pridané: Order number tracking
- ✅ Pridané: Barion Wallet podpora

### Čo zostáva rovnaké:
- ✅ Geo.js (mapová logika)
- ✅ Tarify a cenová kalkulácia
- ✅ Email template
- ✅ GeoJSON zóny
- ✅ Admin panel (pridané sekcie)

### Backward compatibility:
- Existujúce Stripe transakcie zostávajú v histórii
- Môžete spustiť oba systémy paralelne (prepínač v admin)
- Pri migrácii nenastane downtime

---

## 📊 Porovnanie poplatkov

| | Stripe | Barion |
|---|---|---|
| Domáce karty (SK/CZ/HU) | 1.4% + 0.25€ | **0.9% + 0.10€** |
| EU karty | 1.4% + 0.25€ | 1.5% + 0.10€ |
| Barion Wallet | - | **0.5%** |
| Mesačný poplatok | 0€ | 0€ |
| Vratky | 0€ | 0€ |

**Príklad:** Platba 10€
- Stripe: 10€ × 1.4% + 0.25€ = **0.39€** (3.9%)
- Barion: 10€ × 0.9% + 0.10€ = **0.19€** (1.9%)
- **Úspora: 0.20€ na transakciu (51%!)**

---

## 🆘 Troubleshooting

### "Barion POSKey nie je nastavený"
→ Nastavenia → Zaparkuj → POSKey vyplniť

### "Barion PHP library nie je nainštalovaná"
→ Spustiť `composer require barion/barion-web-php`

### "Payment failed"
→ Skontrolovať logy: WP Debug log alebo Barion Dashboard → History

### "Email nedošiel"
→ Skontrolovať wp_mail() konfiguráciu, SMTP plugin

### "Redirect loop"
→ Vyčistiť cache (WP Super Cache, W3 Total Cache)

---

## 📞 Podpora

**Barion Support (SK):**
- Email: support@barion.com
- Tel: +421 2 123 456 78
- Docs: https://docs.barion.com/

**Plugin Support:**
- GitHub Issues
- Email: support@zaparkuj.sk

---

## ✅ Checklist pred spustením

- [ ] Barion účet vytvorený a overený
- [ ] POSKey získaný (test aj prod)
- [ ] Barion SDK nainštalovaný
- [ ] Súbory pridané do pluginu
- [ ] Databázová tabuľka vytvorená
- [ ] Admin nastavenia vyplnené
- [ ] Test platba úspešne dokončená
- [ ] Email potvrdenie doručené
- [ ] IPN webhook funkčný
- [ ] Produkčný POSKey nastavený
- [ ] Reálna platba otestovaná

---

**Dátum migrácie:** ___________________  
**Migráciu vykonal:** ___________________  
**Schválil:** ___________________
