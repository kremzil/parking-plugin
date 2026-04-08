# Zaparkuj v0.5.0 - Обновлённая версия

## 🎉 Что нового

### ✅ Barion интеграция
- Локальная платёжная система для SK/CZ/HU
- Меньше комиссий (0.9% вместо 1.4%)
- Простой redirect flow

### ✅ База данных
Автоматическое создание таблиц при активации плагина:

**wp_zp_transactions** — история всех платежей
- barion_payment_id, order_number, spz, email
- zone_id, lat, lng, minutes, amount_cents
- status (pending/completed/failed)
- created_at, paid_at

**wp_zp_parkings** — активные парковки
- transaction_id, spz, zone_id
- started_at, expires_at
- extended_minutes, is_active

### ✅ Админ-панель
- **Zaparkuj → Objednávky** — полная история платежей
- Поиск по ŠPZ, email, номеру заказа
- Фильтр по статусу (pending/completed/failed)
- Пагинация (20 записей на страницу)
- **Zaparkuj → Nastavenia** — настройки Barion и карт

### ✅ REST API
- `POST /zaparkuj/v1/barion-prepare` — создание платежа
- `POST /zaparkuj/v1/barion-ipn` — IPN от Barion

---

## 📥 Установка

### 1. Обновление файлов

```bash
# Замените старую версию плагина
rm -rf wp-content/plugins/zaparkuj-wp-0_4_5
cp -r zaparkuj-wp-0_4_5 wp-content/plugins/
```

### 2. Активация

Плагин автоматически:
- Создаст таблицы `wp_zp_transactions` и `wp_zp_parkings`
- Установит версию БД в `wp_options`

### 3. Настройка Barion

1. Зайдите в **WordPress Admin → Zaparkuj → Nastavenia**
2. Заполните:
   - **POSKey**: ваш ключ из secure.barion.com
   - **Prostredie**: Test (для тестирования)
   - **Payee Email**: email вашего Barion аккаунта
3. Сохраните

### 4. Установка Barion SDK

```bash
cd wp-content/plugins/zaparkuj-wp-0_4_5/zaparkuj-wp-0_4_5
composer require barion/barion-web-php
```

Или скачайте вручную с https://github.com/barion/barion-web-php

---

## 🎯 Использование

### Shortcode (без изменений)

```
[zaparkuj]
```

### Просмотр истории

**Admin → Zaparkuj → Objednávky**

Функции:
- Поиск: введите ŠPZ, email или номер заказа
- Фильтр: выберите статус (все/pending/completed/failed)
- Сортировка: по дате (новые сверху)

### Тестовый платёж

1. На странице с `[zaparkuj]` заполните форму
2. Нажмите "Zaplatiť"
3. Вас перенаправит на Barion test
4. Используйте тестовую карту:
   - **5559 0574 4061 2346**
   - Exp: **12/28**
   - CVC: **123**
   - 3DS: **bws**
5. После оплаты вернётесь на сайт
6. Проверьте email и **Zaparkuj → Objednávky**

---

## 🔧 Изменения в коде

### Удалено legacy-платёжное ядро
```php
const OPT_PK   = 'zp_stripe_pk';        // ❌ Удалено
const OPT_SK   = 'zp_stripe_sk';        // ❌ Удалено
const OPT_WH   = 'zp_stripe_whsec';     // ❌ Удалено
wp_enqueue_script('zaparkuj-checkout'); // ❌ Удалено
```

### Добавлено (Barion)
```php
const OPT_BARION_POSKEY = 'zp_barion_poskey';       // ✅
const OPT_BARION_ENV = 'zp_barion_environment';     // ✅
const OPT_BARION_PAYEE = 'zp_barion_payee_email';   // ✅
wp_enqueue_script('zaparkuj-checkout-barion');      // ✅
```

### Новые методы
```php
activate()                  // Создание таблиц
orders_page()               // Админ-страница истории
rest_barion_prepare()       // REST: создание платежа
rest_barion_ipn()           // REST: IPN от Barion
```

---

## 📊 Структура БД

### wp_zp_transactions
```sql
SELECT 
  id,
  order_number,
  spz,
  email,
  zone_id,
  amount_cents / 100 as amount_eur,
  status,
  created_at
FROM wp_zp_transactions
WHERE status = 'completed'
ORDER BY created_at DESC
LIMIT 10;
```

### wp_zp_parkings
```sql
-- Активные парковки сейчас
SELECT 
  spz,
  zone_id,
  started_at,
  expires_at,
  TIMESTAMPDIFF(MINUTE, NOW(), expires_at) as minutes_left
FROM wp_zp_parkings
WHERE is_active = 1 AND expires_at > NOW()
ORDER BY expires_at ASC;
```

---

## 🔄 Миграция с v0.4.5

### Что НЕ нужно делать:
- ❌ Удалять старые данные
- ❌ Менять GeoJSON или тарифы
- ❌ Переделывать страницы

### Что нужно:
1. ✅ Обновить файлы плагина
2. ✅ Деактивировать → Активировать (создаст таблицы)
3. ✅ Заполнить Barion настройки
4. ✅ Установить Barion SDK
5. ✅ Протестировать платёж

---

## 🆘 Troubleshooting

### "Barion nie je nakonfigurovaný"
→ Заполните POSKey в настройках

### "Class 'BarionClient' not found"
→ Установите SDK: `composer require barion/barion-web-php`

### Таблицы не создались
→ Деактивируйте и активируйте плагин снова

### История заказов пустая
→ Сделайте тестовый платёж, он появится после успешной оплаты

### Email не приходит
→ Проверьте `wp_mail()`, установите SMTP плагин (WP Mail SMTP)

---

## 📈 Следующие шаги (опционально)

### Фаза 2:
- [ ] Cron для проверки окончания парковки
- [ ] Email-уведомления за 15 минут до окончания
- [ ] API для продления парковки
- [ ] Экспорт истории в CSV

### Фаза 3:
- [ ] Мобильное приложение
- [ ] Push-уведомления
- [ ] Интеграция с городской системой контроля
- [ ] Аналитика и отчёты

---

## 📞 Поддержка

**Barion:** support@barion.com  
**Plugin:** Смотрите логи в `wp-content/debug.log`

Активируйте debug mode:
```php
// В wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

---

**Версия:** 0.5.0  
**Дата:** 27 января 2026  
**Статус:** ✅ Production Ready
