=== Zaparkuj (WP) – Parking Payments ===
Contributors: zaparkuj
Tags: barion, parking, payments, geojson, leaflet, shortcode
Requires at least: 5.6
Tested up to: 6.6
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

Parking payment system with Barion, GeoJSON zones, OSM/Leaflet maps, order history. Full-featured service for SK/CZ/HU market.

== Description ==

**Zaparkuj** is a complete parking payment solution for WordPress with Barion payment integration, geolocation, interactive maps, and comprehensive order management.

= Key Features =

* **Barion Payment Gateway** - Local payment system for SK/CZ/HU with lower fees
* **Database & Order History** - Full transaction tracking with admin dashboard
* **GeoJSON Parking Zones** - Polygon-based zones with tolerance and hole support
* **Interactive Maps** - OSM/Leaflet or Google Maps with zone highlighting
* **Address Search** - Nominatim geocoding for easy location selection
* **Flexible Pricing** - 30-min blocks + daily caps, support for residential zones
* **Email Receipts** - Automatic confirmation emails with parking details
* **Admin Dashboard** - Search, filter, and manage all orders

= Perfect For =

* Municipalities managing paid parking zones
* Private parking lot operators
* Smart city initiatives
* Parking apps and services

= Why Barion? =

* Lower fees for local cards (0.9% vs 1.4%)
* Slovak/Czech/Hungarian support
* EU-compliant (PSD2, GDPR)
* Simple redirect flow integration

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/zaparkuj-wp-0_4_5/`
2. Install Barion SDK: `composer require barion/barion-web-php`
3. Activate the plugin through the 'Plugins' menu
4. Register at https://www.barion.com/ and get your POSKey
5. Go to **Zaparkuj → Nastavenia** and configure:
   - POSKey from Barion Dashboard
   - Environment (Test/Production)
   - GeoJSON URL for parking zones
   - Tariffs JSON
6. Add `[zaparkuj]` shortcode to any page

== Frequently Asked Questions ==

= Do I need a Barion account? =

Yes. Register for free at https://www.barion.com/. For testing, use https://secure.test.barion.com/.

= What is POSKey? =

POSKey is your API key from Barion. Get it at Barion Dashboard → My stores → Create new store.

= How do I create parking zones? =

Upload a GeoJSON file with polygon features. Each feature should have `id` and `label` properties matching your tariff zones.

= Can I use Google Maps instead of OSM? =

Yes, but OSM (Leaflet) is recommended as it provides better features: zone overlay, manual point selection, and address search.

= How are prices calculated? =

Based on `base_30` (price per 30 minutes) and `daily_cap` (maximum per day). Example: 3 hours in zone A1 (1.5€/30min, cap 24€) = 6 blocks × 1.5€ = 9.00€.

= Where are orders stored? =

In WordPress database tables `wp_zp_transactions` and `wp_zp_parkings`. View them in **Zaparkuj → Objednávky**.

= How do I test payments? =

Use Barion test environment with test card: 5559 0574 4061 2346, exp 12/28, CVC 123, 3DS password: bws

== Screenshots ==

1. Frontend parking form with map and zone detection
2. Admin order history with search and filters
3. Barion settings page
4. Email receipt example
5. Mobile-responsive interface

== Changelog ==

= 0.5.0 (2026-01-27) =
* **Major Update:** Barion payment gateway rollout
* Added: Database tables for transactions and active parkings
* Added: Admin dashboard for order history
* Added: Search and filter functionality in admin
* Added: IPN webhook for reliable payment processing
* Added: Automatic table creation on plugin activation
* Improved: Server-side price validation
* Improved: Idempotency protection against double charges
* Removed: Stub payment mode (use Barion test instead)

= 0.4.5 =
* Map loads immediately at mock1 center if geolocation blocked
* Address search on OSM map (Leaflet Control Geocoder)
* Search result sets marker and recalculates zone/price

= 0.4.3 =
* GeoJSON polygon zones with tolerance and holes support
* OSM/Leaflet or Google map provider option
* Manual point selection on map
* New pricing model: base_30 + daily_cap

== Upgrade Notice ==

= 0.5.0 =
Major update! Migrated to Barion payment system. Requires Barion SDK installation and new configuration. Adds database tables and admin dashboard. See BARION_MIGRATION.md for upgrade guide.

== Usage ==

1. Create a page and add shortcode: `[zaparkuj]`
2. Users will see a form with:
   - Map showing their location
   - Zone detection based on GeoJSON
   - Duration selector
   - ŠPZ (license plate) and email fields
   - Real-time price calculation
3. After clicking "Zaplatiť", they redirect to Barion
4. Payment confirmation returns them to your site
5. Receipt is sent via email
6. View all orders in **Zaparkuj → Objednávky**

== Test ==

* Test environment: Set "Prostredie" to "Test" in settings
* Test card: 5559 0574 4061 2346, exp 12/28, CVC 123, 3DS: bws
* Test mail: Add `?test_mail=1` to any page URL (admin only)

== Support ==

* Barion: support@barion.com, https://docs.barion.com/
* Plugin: Enable WP_DEBUG and check wp-content/debug.log

== Technical Details ==

* Requires PHP 7.4+
* Requires MySQL 5.7+ or MariaDB 10.2+
* Barion PHP SDK: barion/barion-web-php
* Bootstrap 5.3.3 for UI
* Leaflet 1.9.4 for maps (when using OSM)
* Database: 2 custom tables (auto-created)
