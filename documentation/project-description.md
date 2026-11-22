# Happy Order Generator — Project Description

## Overview

Happy Order Generator is a WooCommerce extension that automatically generates realistic test orders on development and staging sites. It is a **set-and-forget** tool: once an "orders per hour" value is configured, it uses the Action Scheduler to generate a steady stream of orders indefinitely, building a realistic, growing dataset over time.

It is deliberately **not** a stress-tester or bulk order generator. The practical ceiling is around 60–180 orders per hour; for bulk generation, Smooth Order Generator is the recommended alternative.

### Key characteristics

- Creates orders through the **WooCommerce Store API** (`/wp-json/wc/store/v1` — `cart`, `cart/add-item`, `batch`, and `checkout` endpoints), so orders follow the same code path as a real shopper, not direct database writes.
- Generates fake customers with **FakerPHP**, with configurable locales, naming conventions, and email conventions.
- Supports **simple, variable, subscription, variable subscription** products (subscriptions require Woo Subscriptions), and — on the current branch — **Product Bundles**.
- Pays via **BACS** out of the box, and via the **WooCommerce Stripe gateway when it is in test mode** (live-mode Stripe is always ignored). Stripe-paid subscriptions are left in a valid, renewable state with a saved payment method.
- **HPOS compatible**, adds **no database tables**, and tags every generated order with the meta field `_happy_order_generator_order = 1` plus an order note, so generated data can be identified and purged.

## Requirements

| Requirement | Minimum version |
|---|---|
| PHP | 8.1 |
| WordPress | 6.2 |
| WooCommerce | 6.7 |

Optional integrations: WooCommerce Stripe Gateway (test mode), Woo Subscriptions, WooCommerce Product Bundles.

Dependencies are managed via Composer (FakerPHP is the main library, loaded from `vendor/autoload.php`).

## How an order is generated

The full pipeline for each order:

1. **Scheduling** — `Cron_Jobs` (`includes/class-cron-jobs.php`) registers a recurring Action Scheduler action (`create_happy_orders`). The interval and batch size are derived from the "orders per hour" setting: under 60/hour the interval stretches out; over 60/hour each run creates a batch.
2. **Orchestration** — `Generator::generate_order()` (`includes/class-generator.php`) runs one order end to end.
3. **Customer** — `Customer` (`includes/class-customer.php`) either picks a random existing customer or creates a new one with FakerPHP data (locale-aware addresses, names, emails), depending on settings.
4. **Product selection** — `Product` (`includes/class-product.php`) picks a random number of products (between the configured min/max) either from the admin-selected product list or randomly from the whole catalogue, and formats them as Store API cart payloads. Variable products get a random variation (resolving "Any" attributes to a random term); bundle products get a generated `bundle_configuration` covering each bundled item, with optional items included at random.
5. **Cart** — `Order_Builder` (`includes/class-order-builder.php`) fetches a Store API nonce, then `Cart_Handler` (`includes/class-cart-handler.php`) adds the items to a cart. Regular products go through a single Store API `/batch` request; bundles are sent as individual `/cart/add-item` requests because the WP core batch endpoint mangles the nested `bundle_configuration` payload.
6. **Payment method** — chosen from the gateways available for that cart. BACS is always supported; `Gateway_Integration_Stripe` (`includes/class-gateway-integration-stripe.php`) adds Stripe via the `order_generator_supported_gateways` filter only when the Stripe plugin is active, available, and in test mode. Carts containing a subscription prefer Stripe so renewals work (avoiding manual-renewal clutter from BACS).
7. **Checkout** — `Order_Builder::do_checkout()` POSTs the customer's billing/shipping details (and Stripe payment data when applicable) to the Store API `/checkout` endpoint. The main plugin file hooks `woocommerce_store_api_checkout_update_customer_from_request` to log the generated customer in just before checkout processes.
8. **Final status** — each order is independently assigned completed / processing / failed using the configured percentage weights (`Generator::get_final_status()`). BACS orders that should succeed are marked paid and moved to their target status. Failed Stripe orders are simulated using Stripe's documented failing test cards, intercepted via `woocommerce_rest_checkout_process_payment_with_context`. Downloadable orders always complete.
9. **Tagging** — the order gets a fake customer IP, an "Order created by Order Generator" note, and the `_happy_order_generator_order` meta flag.

## Code structure

```
woocommerce-order-generator.php          Bootstrap: singleton, hooks, version checks,
                                         HPOS compatibility declaration, settings accessor
includes/
  class-generator.php                    Per-order orchestration (the pipeline above)
  class-cron-jobs.php                    Action Scheduler setup and batch runner
  class-customer.php                     Fake customer creation/selection (FakerPHP)
  class-product.php                      Product selection + Store API cart payload
                                         formatting, incl. bundle configurations
  class-order-builder.php                Store API order creation (nonce + checkout)
  class-cart-handler.php                 Cart operations: bundle vs regular routing,
                                         batch requests, cart state
  class-http-client.php                  Reusable Store API HTTP client: nonce auth,
                                         cookies, SSL bypass, retries w/ backoff
  class-gateway-integration-stripe.php   Stripe test-mode integration, failure simulation
  class-logger.php                       WC_Logger wrapper (source: happy-order-generator)
                                         with info/warning/error levels and context
  class-installer.php                    Activation/deactivation stubs
  admin/
    class-wc-settings-order-generator.php  WooCommerce > Settings > Order Generator tab
```

## Settings

Found at **WooCommerce → Settings → Order Generator**, stored in the `happy_order_generator_settings` option. Nothing runs until *Orders per hour* is set.

- **Orders per hour** — generation rate (hard-capped at 500, filterable; practical limits much lower).
- **Products** — restrict generation to specific products, or leave empty for the whole catalogue.
- **Min / Max order products** — cart size range per order.
- **Create user accounts** — create new customers (mixed with existing) or only reuse existing ones.
- **Bypass SSL verify** — for local sites with self-signed certificates; applied only to the plugin's own Store API requests.
- **New customer locales** — billing countries for generated customers (useful for shipping tests).
- **Customer naming / email conventions** — fully fake data, or clearly-labelled test data (e.g. `Test Customer ID: 000`, `TestCustomer.id.000@…`).
- **Completed / Processing / Failed percentages** — target status distribution; auto-balanced to sum to 100 on save.
- **Enable logging** — writes detailed logs via `WC_Logger` (WooCommerce → Status → Logs, source `happy-order-generator`).

## Extension points (hooks and filters)

- `order_generator_supported_gateways` (filter) — register additional payment gateways (this is how Stripe support is added).
- `hog_payment_method` / `hog_subscription_payment_method` (filters) — override the chosen payment method.
- `hog_get_cart_products` (filter) — modify the product payload before it is added to the cart.
- `order_generator_order_processed` / `order_generator_order_failed` (actions) — fire after checkout with the order and options.
- `order_generator_max_orders_per_hour` (filter) — change the 500/hour cap.

## Current development status (June 2026)

Active branch: **`add-product-bundles-support`** — adding support for WooCommerce Product Bundles. The branch is 3 commits ahead of origin with further uncommitted work:

- **New (untracked) classes**: `class-http-client.php` and `class-cart-handler.php`. The Store API plumbing that used to live entirely inside `Order_Builder` has been refactored out: `HTTP_Client` centralises nonce auth, cookie persistence, SSL bypass, and retry-with-exponential-backoff; `Cart_Handler` owns cart operations and routes bundles to single requests vs. regular products to batch requests.
- **Modified**: `class-order-builder.php` (rewritten around the two new classes), `class-product.php` (bundle detection via `WC_Product_Bundle` and `bundle_configuration` generation), `class-logger.php` (structured logging with levels and context), `class-generator.php` (extra diagnostic logging).

### Known rough edges / TODOs

- Variable products **inside** bundles: variation selection for bundled variable items exists in `Product::generate_bundle_configuration()` but is currently commented out.
- `Generator::__construct()` reads the option `wc_order_generator_settings`, while settings are saved to `happy_order_generator_settings` (the key `Order_Generator::get_settings()` reads). `Cron_Jobs` also writes its computed batch size/interval back to `wc_order_generator_settings`. This inconsistency means `Generator->settings` (used for the status percentages) may not reflect the saved admin settings.
- `Cron_Jobs::setup_action_scheduler()` contains an assignment (`=`) where a comparison (`==`) is intended in the interval check, so the "leave the current action alone" short-circuit doesn't behave as written.
- `Installer` activation/deactivation hooks are stubs; the scheduled action is not cleaned up on deactivation.
- Recent commit history is heavily focused on debugging order-creation issues in the bundle flow, so the branch should be treated as work in progress, not release-ready.

## Maintenance notes

- License: GPLv3. Text domain: `happy-order-generator`.
- To purge generated data, query orders by meta `_happy_order_generator_order = 1`.
- Logging output goes to WooCommerce logs under the source `happy-order-generator` (only when "Enable logging" is checked).