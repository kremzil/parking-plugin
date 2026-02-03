# 🚀 Быстрый старт Zaparkuj v0.5.0

## За 10 минут от нуля до работающего сервиса

### ✅ Checklist

- [ ] WordPress 5.6+ установлен
- [ ] PHP 7.4+ на сервере
- [ ] MySQL/MariaDB база данных
- [ ] Composer установлен (опционально)
- [ ] Barion аккаунт (бесплатная регистрация)

---

## Шаг 1: Установка плагина (2 мин)

```bash
# Перейдите в папку плагинов WordPress
cd /path/to/wordpress/wp-content/plugins/

# Скопируйте плагин
cp -r /path/to/zaparkuj-wp-0_4_5/zaparkuj-wp-0_4_5 ./

# Установите Barion SDK
cd zaparkuj-wp-0_4_5
composer require barion/barion-web-php
```

**Без composer:**
1. Скачайте https://github.com/barion/barion-web-php/archive/refs/heads/master.zip
2. Распакуйте в `plugins/zaparkuj-wp-0_4_5/vendor/barion/`

---

## Шаг 2: Активация (1 мин)

1. Войдите в **WordPress Admin**
2. **Плагины → Установленные**
3. Найдите **Zaparkuj (WP) – Parking Payments v0.5.0**
4. Нажмите **Активировать**

✅ Автоматически создадутся таблицы:
- `wp_zp_transactions`
- `wp_zp_parkings`

---

## Шаг 3: Регистрация в Barion (3 мин)

### Test аккаунт (для разработки):

1. Перейдите на https://secure.test.barion.com/
2. **Sign up** → создайте аккаунт
3. Подтвердите email
4. **My stores** → **Create new store**
5. Название: "Zaparkuj Test"
6. **Скопируйте POSKey** (длинная строка)

### Prod аккаунт (для production):

1. Перейдите на https://secure.barion.com/
2. То же самое, но на prod домене
3. Заполните Business информацию
4. Пройдите верификацию

---

## Шаг 4: Настройка плагина (2 мин)

1. **Zaparkuj → Nastavenia**
2. Заполните:

```
POSKey: [вставьте ваш POSKey из Barion]
Prostredie: Test
Payee Email: [email вашего Barion аккаунта]
```

3. **GeoJSON URL:**
   - Загрузите `zones.for_plugin.remapped.geojson` в Media Library
   - Скопируйте URL
   - Вставьте в поле

4. **Map Provider:** выберите `OpenStreetMap (Leaflet)`

5. **Tariffs JSON:** вставьте (пример для Кошице):

```json
{
  "version": "0.5.0",
  "currency": "EUR",
  "zones": [
    {
      "id": "A1",
      "label": "Tarifné pásmo A1",
      "base_30": 1.5,
      "daily_cap": 24.0
    },
    {
      "id": "A2",
      "label": "Tarifné pásmo A2",
      "base_30": 1.0,
      "daily_cap": 16.0
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

6. **Uložiť nastavenia**

---

## Шаг 5: Создание страницы (1 мин)

1. **Stránky → Vytvoriť novú**
2. Название: "Parkovanie"
3. В редакторе переключитесь в HTML/блок код
4. Вставьте shortcode:

```
[zaparkuj]
```

5. **Publikovať**

---

## Шаг 6: Тест (1 мин)

1. Откройте созданную страницу
2. Разрешите браузеру доступ к геолокации
3. Заполните форму:
   - **ŠPZ:** ABC123
   - **Email:** your@email.com
   - **Trvanie:** 60 minút
4. Нажмите **Zaplatiť**
5. На Barion тестовой странице введите:

```
Karta: 5559 0574 4061 2346
Exp: 12/28
CVC: 123
3D Secure heslo: bws
```

6. Завершите оплату
7. Вас вернёт на сайт с сообщением "✅ Platba úspešná!"
8. Проверьте **Zaparkuj → Objednávky** — появится запись
9. Проверьте email — придёт квитанция

---

## 🎉 Готово!

Теперь у вас полностью рабочий сервис парковки с:
- ✅ Приёмом платежей через Barion
- ✅ Историей всех заказов
- ✅ Email-квитанциями
- ✅ Админ-панелью

---

## 📊 Проверка работы

### База данных

```sql
-- Проверить созданные таблицы
SHOW TABLES LIKE 'wp_zp_%';

-- Посмотреть тестовую транзакцию
SELECT * FROM wp_zp_transactions ORDER BY created_at DESC LIMIT 1;

-- Активные парковки
SELECT * FROM wp_zp_parkings WHERE is_active = 1;
```

### Debug

Если что-то не работает:

```php
// В wp-config.php добавьте:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Логи: `wp-content/debug.log`

---

## 🔧 Переход на Production

Когда всё протестировано:

1. **Barion:** создайте prod store, получите prod POSKey
2. **Настройки:**
   - POSKey: [prod key]
   - Prostredie: **Produkcia**
3. **Тест:** сделайте реальный платёж на минимальную сумму
4. **Запуск:** готово! 🚀

---

## 📞 Нужна помощь?

- Документация: [README.md](README.md)
- Миграция со Stripe: [BARION_MIGRATION.md](BARION_MIGRATION.md)
- Что нового: [CHANGELOG_v0.5.0.md](CHANGELOG_v0.5.0.md)
- Barion Docs: https://docs.barion.com/

**Всё работает?** Отлично! Можете начинать принимать платежи! 💰
