# ZAPARKUJ — WordPress Plugin для оплаты парковки

> **Версия:** 0.5.0  
> **WordPress:** 5.6+  
> **Лицензия:** GPLv2 or later

## 📋 Описание

**Zaparkuj** — WordPress плагин для оплаты парковки с геолокацией, интерактивными картами и интеграцией **Barion**. Поддерживает определение парковочных зон через GeoJSON полигоны, автоматический расчёт стоимости по тарифам и отправку квитанций на email. **Версия 0.5.0** включает полную систему управления заказами с базой данных и админ-панелью.

### Основные возможности

✅ **Платёжная система Barion**
- Локальная платформа для SK/CZ/HU рынка
- Меньше комиссий (0.9% + 0.10€ vs 1.4% + 0.25€)
- Барион Wallet для моментальных платежей
- Простой redirect flow без сложной интеграции

✅ **База данных и история**
- Автоматическое создание таблиц при активации
- Полная история транзакций
- Отслеживание активных парковок
- Поиск и фильтрация заказов
- Админ-панель с пагинацией

✅ **Геолокация и карты**
- Автоматическое определение местоположения пользователя
- Поддержка Google Maps и OpenStreetMap (Leaflet)
- Поиск адресов (Nominatim) при использовании OSM
- Ручной выбор точки на карте
- Визуализация парковочных зон с подсветкой

✅ **Парковочные зоны (GeoJSON)**
- Полигоны с поддержкой holes (вырезов)
- Точный алгоритм point-in-polygon
- Настраиваемая толерантность определения зоны (метры)
- Fallback на круговые зоны

✅ **Гибкая система тарифов**
- Модель ценообразования: `base_30` (цена за 30 минут) + `daily_cap` (дневной лимит)
- Поддержка резидентских зон без лимита
- Автоматический расчёт для многодневной парковки
- JSON-конфигурация тарифов

---

## 🛠 Технический стек

### Backend
- **PHP** 7.4+ (WordPress)
- **WordPress REST API** для эндпоинтов
- **Barion PHP SDK** для платежей
- **MySQL** для хранения транзакций и парковок
- `wp_mail()` для отправки квитанций

### Frontend
- **JavaScript** (vanilla)
- **Bootstrap 5.3.3** для UI
- **Leaflet 1.9.4** для OSM карт
- **Leaflet Control Geocoder** для поиска адресов
- Redirect flow для Barion (без сложных элементов)

### Данные
- **GeoJSON** для геометрии зон
- **JSON** для конфигурации тарифов

---

## 📂 Структура проекта

```
ZAPARKUJ/plugin/
│
├── AGENTS.md                              # Правила для AI (язык, стиль)
├── README.md                              # Этот файл
├── homepage.html                          # Шаблон главной страницы
├── zones-drawer.html                      # Инструмент для рисования зон
├── zones.for_plugin.remapped.geojson      # GeoJSON с полигонами зон
├── tariffs-kosice-remapped.json           # Пример тарифов (Кошице)
│
└── zaparkuj-wp/
    ├── zaparkuj-wp.php                    # Главный файл плагина
    ├── readme.txt                         # WordPress readme
    │
    ├── assets/
    │   ├── css/
    │   │   └── style.css                  # Стили плагина
    │   └── js/
    │       ├── bands.js                   # Утилиты для зон
    │       ├── geo.js                     # Геолокация, карты, определение зон
    │       └── checkout-barion.js         # Redirect flow Barion
    │
    ├── includes/
    │   ├── barion-gateway.php             # Интеграция с Barion
    │   ├── easypark-integration.php       # Каркас интеграции EasyPark
    │   └── mail-sender.php                # Отправка email-квитанций
    │
    └── templates/
        ├── email.html                     # Шаблон email (HTML)
        └── email.html.php                 # Шаблон email (PHP)
```

---

## 🚀 Установка

### 1. Установка плагина

```bash
# Скопируйте папку плагина в WordPress
cp -r zaparkuj-wp /path/to/wordpress/wp-content/plugins/
```

### 2. Установка Barion SDK

```bash
cd /path/to/wordpress/wp-content/plugins/zaparkuj-wp
composer require barion/barion-web-php
```

Или скачайте вручную с https://github.com/barion/barion-web-php

### 3. Активация

1. Войдите в админ-панель WordPress
2. Перейдите в **Плагины → Установленные**
3. Найдите **Zaparkuj (WP) – Parking Payments**
4. Нажмите **Активировать**

✅ **Автоматически создадутся таблицы:**
- `wp_zp_transactions` — история платежей
- `wp_zp_parkings` — активные парковки

### 4. Настройка Barion

1. Зарегистрируйтесь на https://www.barion.com/sk/
2. Создайте Business аккаунт
3. Получите POSKey:
   - Test: https://secure.test.barion.com/ → My stores
   - Prod: https://secure.barion.com/ → My stores
4. В WordPress: **Zaparkuj → Nastavenia**
5. Заполните:

| Параметр | Описание |
|----------|----------|
| **POSKey** | Ключ из Barion Dashboard |
| **Prostredie** | Test (для тестирования) / Produkcia (live) |
| **Payee Email** | Email вашего Barion аккаунта |
| **GeoJSON URL** | URL к файлу zones.geojson |
| **Map Provider** | `osm` (рекомендуется) или `google` |
| **Tariffs JSON** | JSON с тарифами |

6. Нажмите **Uložiť nastavenia**

---

## 📝 Использование

### Shortcode

Добавьте на любую страницу WordPress:

```
[zaparkuj]
```

Это создаст полный интерфейс для:
- Определения местоположения
- Выбора зоны парковки
- Выбора длительности
- Ввода данных (SPZ, email)
- Оплаты через Barion

---

## ⚙️ Конфигурация

### Формат тарифов (JSON)

```json
{
  "version": "0.4.5",
  "currency": "EUR",
  "discounts": {
    "app_pct": 10,
    "bonus_card_pct": 25
  },
  "zones": [
    {
      "id": "A1",
      "label": "Tarifné pásmo A1",
      "base_30": 1.5,
      "daily_cap": 24.0
    },
    {
      "id": "N",
      "label": "Tarifné pásmo N",
      "base_30": 0.3,
      "daily_cap": 3.0
    }
  ]
}
```

### Параметры зоны

| Поле | Тип | Описание |
|------|-----|----------|
| `id` | string | Идентификатор зоны (A1, A2, BN, N, B) |
| `label` | string | Название зоны (словацкий) |
| `base_30` | number | Цена за 30 минут (EUR) |
| `daily_cap` | number\|null | Максимум за день (null = без лимита) |

### Алгоритм расчёта

```javascript
// Полные дни
fullDays = floor(minutes / 1440)
// Остаток минут
remMin = minutes % 1440
// Блоки по 30 минут
blocks = ceil(remMin / 30)

// Стоимость остатка дня
partDay = min(blocks × base_30, daily_cap)

// Итого
total = (fullDays × daily_cap) + partDay
```

**Пример:** 3 часа в зоне A1 (base_30=1.5€, cap=24€)
- 3 часа = 180 минут = 6 блоков × 1.5€ = **9.00€**

---

## 🧪 Режимы тестирования

### Test карта Barion
```
Číslo: 5559 0574 4061 2346
Expirácia: 12/28
CVC: 123
3D Secure heslo: bws
```

### Test flow
1. Откройте страницу с `[zaparkuj]`
2. Заполните ŠPZ, email, выберите длительность
3. Нажмите "Zaplatiť"
4. Вас перенаправит на Barion test
5. Введите тестовую карту (указана выше)
6. Завершите 3DS аутентификацию
7. Вас вернёт на сайт с сообщением об успехе
8. Проверьте **Zaparkuj → Objednávky** в админке
9. Проверьте email с квитанцией

### Test Mail
```
https://yoursite.com/page/?test_mail=1
```
Отправляет тестовое письмо-квитанцию (только для админов)

---

## 📊 Админ-панель

### Zaparkuj → Objednávky

**История всех платежей** с функциями:
- 🔍 **Поиск** по ŠPZ, email, номеру заказа
- 🔄 **Фильтр** по статусу (pending/completed/failed)
- 📄 **Пагинация** (20 записей на страницу)
- 📅 **Сортировка** по дате (новые сверху)

**Отображаемые данные:**
- ID транзакции
- Дата создания
- ŠPZ (гос. номер)
- Email
- Зона парковки
- Длительность (минуты)
- Сумма (EUR)
- Статус (цветной badge)
- Barion Payment ID

### Zaparkuj → Nastavenia

Настройки:
- **Barion** (POSKey, Environment, Payee Email)
- **Карты** (GeoJSON URL, Map Provider, Tolerance)
- **Тарифы** (JSON конфигурация)

---

## 🗄️ Структура базы данных

### wp_zp_transactions
Хранит все транзакции:

| Поле | Тип | Описание |
|------|-----|----------|
| id | BIGINT | Уникальный ID |
| barion_payment_id | VARCHAR(255) | ID платежа в Barion |
| order_number | VARCHAR(100) | Номер заказа (ZP-timestamp-hash) |
| spz | VARCHAR(20) | Гос. номер автомобиля |
| email | VARCHAR(255) | Email для квитанции |
| zone_id | VARCHAR(10) | ID зоны (A1, A2, BN, N, B) |
| lat, lng | DECIMAL(10,7) | Координаты парковки |
| minutes | INT | Длительность парковки |
| amount_cents | INT | Сумма в центах |
| status | VARCHAR(20) | pending/completed/failed |
| created_at | DATETIME | Дата создания |
| paid_at | DATETIME | Дата оплаты |

### wp_zp_parkings
Активные парковки:

| Поле | Тип | Описание |
|------|-----|----------|
| id | BIGINT | Уникальный ID |
| transaction_id | BIGINT | ID транзакции |
| spz | VARCHAR(20) | Гос. номер |
| zone_id | VARCHAR(10) | Зона парковки |
| started_at | DATETIME | Начало парковки |
| expires_at | DATETIME | Окончание парковки |
| extended_minutes | INT | Продлено минут |
| is_active | TINYINT(1) | Активна ли (1/0) |

**SQL примеры:**

```sql
-- Активные парковки сейчас
SELECT spz, zone_id, expires_at,
       TIMESTAMPDIFF(MINUTE, NOW(), expires_at) as minutes_left
FROM wp_zp_parkings
WHERE is_active = 1 AND expires_at > NOW();

-- Статистика по зонам
SELECT zone_id, COUNT(*) as total, SUM(amount_cents)/100 as revenue_eur
FROM wp_zp_transactions
WHERE status = 'completed'
GROUP BY zone_id;
```

---

## 🌍 Локализация

| Язык | Применение |
|------|-----------|
| **Словацкий** | UI текст сайта ("Zapni určovanie polohy", "Vybrať bod na mape") |
| **Русский** | Внутренняя документация, комментарии AI |
| **Английский** | Код, переменные, комментарии в коде |

---

## 🔌 REST API Endpoints

| Endpoint | Метод | Описание |
|----------|-------|----------|
| `/wp-json/zaparkuj/v1/barion-prepare` | POST | Создание Barion платежа |
| `/wp-json/zaparkuj/v1/barion-ipn` | POST | IPN от Barion |

**Пример запроса:**
```javascript
const response = await fetch('/wp-json/zaparkuj/v1/barion-prepare', {
  method: 'POST',
  headers: {'Content-Type': 'application/json'},
  body: JSON.stringify({
    spz: 'ABC123',
    email: 'user@example.com',
    zone_id: 'A1',
    minutes: 120,
    lat: 48.7205,
    lng: 21.2575,
    amount_cents: 600 // будет пересчитано на сервере
  })
});

const data = await response.json();
// { success: true, paymentId: "...", redirectUrl: "https://api.barion.com/..." }

// Redirect на Barion
window.location.href = data.redirectUrl;
```

---

## 📧 Email-квитанции

После успешной оплаты автоматически отправляется email с:
- Номером SPZ (гос. номер)
- Парковочной зоной
- Длительностью (минуты)
- Суммой оплаты (EUR)
- Координатами парковки
- Ссылкой на карту OpenStreetMap

Шаблон: [templates/email.html.php](zaparkuj-wp-0_4_5/zaparkuj-wp-0_4_5/templates/email.html.php)

---

## 🐛 Отладка

### Проверка GeoJSON
```javascript
// В консоли браузера
console.log(window.ZP.geojsonData);
```

### Проверка конфига
```javascript
console.log(window.ZP);
```

### Логи WordPress
```php
// В wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Логи: `wp-content/debug.log`

---

## 💳 Преимущества Barion

| Параметр | Barion |
|---|---|
| **Домашние карты (SK/CZ/HU)** | **0.9% + 0.10€** |
| **EU карты** | 1.5% + 0.10€ |
| **Barion Wallet** | **0.5%** |
| **Интеграция** | Простая redirect flow |
| **Локальная поддержка** | Словацкий jazyk |
| **PSD2/3DS** | Podporované |

**Пример комиссии:**  
Платёж 10€ с локальной картой через Barion:
- 10€ × 0.9% + 0.10€ = **0.19€** (1.9%)

---

## 🆕 Что нового в v0.5.0

### ✅ Миграция на Barion
- Полная миграция на Barion
- Меньше комиссий для локального рынка
- Простая redirect интеграция

### ✅ База данных
- Автоматическое создание таблиц
- История всех транзакций
- Отслеживание активных парковок

### ✅ Админ-панель
- Новое меню "Zaparkuj" в WordPress
- Страница истории заказов с поиском и фильтрами
- Страница настроек Barion

### ✅ Улучшения
- Защита от двойного списания (idempotency)
- Валидация на серверной стороне
- Детальное логирование
- IPN для надёжной обработки платежей

### ❌ Удалено
- Stub-режим (больше не нужен, есть Barion test)
- Mock geolocation (используйте browser dev tools)

---

## 📋 Roadmap

### Фаза 2 (1-2 месяца)
- [ ] Cron для проверки окончания парковки
- [ ] Email-уведомления за 15 минут до окончания
- [ ] API для продления парковки
- [ ] Экспорт истории в CSV
- [ ] Графики и аналитика

### Фаза 3 (3-6 месяцев)
- [ ] Мобильное приложение (React Native)
- [ ] Push-уведомления
- [ ] Интеграция с городской системой контроля
- [ ] Подписки для резидентов
- [ ] QR-коды для быстрой оплаты

---

## 🐛 Troubleshooting

### "Barion nie je nakonfigurovaný"
→ Nastavte POSKey v **Zaparkuj → Nastavenia**

### "Class 'BarionClient' not found"
→ Установите SDK: `composer require barion/barion-web-php`

### Таблицы не создались
→ Деактивируйте и активируйте плагин снова

### Email не приходит
→ Установите SMTP плагин (WP Mail SMTP, Post SMTP)

### Ошибки 500
→ Включите `WP_DEBUG` и проверьте `wp-content/debug.log`

```php
// В wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

---

## 📞 Поддержка

**Barion Support:**
- Email: support@barion.com
- Tel (SK): +421 2 XXX XXX XX
- Docs: https://docs.barion.com/

**WordPress:**
- Enable debug logging
- Check `wp-content/debug.log`
- Contact: support@zaparkuj.sk

---

## 📄 Лицензия

GPLv2 or later  
https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

---

## 👨‍💻 Автор

**Zaparkuj Team**  
Версия 0.5.0 (январь 2026)

**Основные изменения:**
- Barion интеграция
- База данных для транзакций и парковок
- Админ-панель с историей заказов
- Production-ready для SK/CZ/HU рынка
