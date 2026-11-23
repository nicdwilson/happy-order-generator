=== Happy Order Generator ===
Contributors: nicw
Tags: woocommerce, orders, testing, staging, test data
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.0
License: GPLv3
License URI: http://www.gnu.org/licenses/gpl-3.0

Automatically generates a steady stream of test orders on WooCommerce test and staging sites.

== Description ==

Happy Order Generator is a set-and-forget order generator for WooCommerce test sites. It uses Action Scheduler and the Store API cart and checkout endpoints to create a chosen number of orders per hour, building a realistic dataset over time. It is not a stress tester or bulk order generator.

* Pays orders with BACS, or with WooCommerce Stripe when Stripe is in test mode. Stripe in live mode is ignored.
* Supports simple, variable, subscription and variable subscription products. Subscriptions need WooCommerce Subscriptions. With Stripe in test mode, subscriptions are left in a valid, renewable state with a saved payment method.
* Supports WooCommerce Product Bundles (tested with 8.7). Each bundle gets a random configuration that respects its required and optional items, quantity ranges, variations and min/max item limits. Bundles that can't be bought are left out of the order.
* Creates test customers with FakerPHP, or reuses customers it created earlier.
* HPOS compatible. Adds no database tables.

= Safety =

* Only runs on sites whose environment type (`WP_ENVIRONMENT_TYPE`) is `local`, `development` or `staging`. Sites that don't set it count as production, so no orders are generated there.
* While orders are being generated, administrators and shop managers see a warning on admin screens, with the current rate.
* Generation can be paused from the settings page without deactivating the plugin.
* WooCommerce emails for generated customers go to one address (`hog-customer@` your domain by default, or one you choose) or aren't sent at all. Emails for real customers and store staff aren't affected.
* Generated orders have the `_happy_order_generator_order` meta set to `1`. Generated customers have the `_happy_order_generator_customer` meta set to `1` and the "Generated customer (Happy Order Generator)" role, which has no capabilities. Real customers are never used for generated orders.

== Installation ==

1. Install and activate WooCommerce.
2. Upload the plugin to `wp-content/plugins/happy-order-generator`, run `composer install --no-dev` in that folder, and activate it.
3. Make sure `WP_ENVIRONMENT_TYPE` is set to `local`, `development` or `staging` in `wp-config.php`.
4. Go to WooCommerce > Settings > Order Generator and set Orders per Hour to 1 or more.

Orders are created by Action Scheduler, so the site needs working WP-Cron or a real cron job running Action Scheduler.

== Frequently Asked Questions ==

= Why aren't any orders being created? =

Check that the site's environment type is `local`, `development` or `staging`, that Enable order generation is ticked, and that Orders per Hour is 1 or more. The settings page explains which of these is stopping generation.

= How do I remove the generated orders? =

Every generated order has the `_happy_order_generator_order` meta set to `1`. Use it to find and delete them.

= Can I run it on a production site? =

No. It creates real orders and customers, so it refuses to run in production. Developers can change the allowed environment types with the `hog_allowed_environment_types` filter.

== Changelog ==

See [changelog.txt](https://github.com/nicdwilson/happy-order-generator/blob/main/changelog.txt) for the full changelog.
