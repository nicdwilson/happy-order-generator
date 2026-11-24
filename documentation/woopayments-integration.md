# WooPayments integration and integrations folder: plan

Status: plan, not started. Checked against WooPayments 11.3.0 and WooCommerce 11.3
(the copies on order-generator.local). Replaces the Phase 6 outline in `roadmap.md`.

Target release: **1.3.0** (1.2.0 was used for the FakerPHP update).

## Summary

1. Move the integrations into `includes/integrations/`, with one folder and
   namespace for payment gateways and one for other plugins:
   - Stripe moves to the payment gateways folder. There is no PayPal
     integration in the plugin; Stripe is the only gateway integration.
   - Product Bundles moves to the plugins folder.
2. Give gateway integrations a shared base class and a filter for checkout
   payment data, so `Order_Builder` and `Generator` no longer name Stripe.
3. Add a WooPayments integration on that base. It's much smaller than the
   Stripe one, because WooPayments does the payment itself during the Store
   API checkout: we only hand it a Stripe test payment method ID.

Steps 1 and 2 are refactors with no change in behaviour, and the existing E2E
suite covers them. Each step is its own PR into `dev`.

## Part 1: folder layout and namespaces

```
includes/
  integrations/
    payment-gateways/                      Happy_Order_Generator\Integrations\Payment_Gateways
      abstract-class-payment-gateway.php   Payment_Gateway (base class)
      class-stripe.php                     Stripe (was Gateway_Integration_Stripe)
      class-woopayments.php                WooPayments (new)
    plugins/                               Happy_Order_Generator\Integrations\Plugins
      class-product-bundles.php            Product_Bundles (moved out of Product, Generator, Cart_Handler, settings)
    class-integrations.php                 Integrations: loads each integration on plugins_loaded
```

- **Autoloading:** composer's classmap already scans `includes/` recursively,
  so moving files only needs `composer dump-autoload -o` (classmap-authoritative).
  The `psr-4` entry doesn't match the WordPress-style file names and can stay or go.
- **Loading:** `Integrations::load()` replaces the Stripe line in
  `Order_Generator::__construct()`. It keeps a list of integration classes,
  and instantiates each one whose `is_available()` passes (plugin active, classes present).
  It also has a `hog_integrations` filter, so other plugins can add their own.
- **Old class name:** keep `class_alias( Stripe::class, 'Happy_Order_Generator\Gateway_Integration_Stripe' )`
  for one release in case anything outside the plugin references it, then remove it.
- **Hooks:** no public hook names change (`order_generator_supported_gateways`,
  `hog_payment_method`, `hog_subscription_payment_method`, `hog_get_cart_products`,
  `order_generator_order_failed`, `order_generator_order_processed`).
- **Static checks:** update the `tests/qit/phpstan-baseline.neon` paths for the moved Stripe file.

### Product Bundles extraction

All of the bundle code moves into `Integrations\Plugins\Product_Bundles`. The
core classes get generic seams it hooks into:

| Now | Seam in core | Product_Bundles hooks it to |
|---|---|---|
| `Product::$product_types` includes `bundle`; `has_bundle_support()` / `add_bundle_support_if_available()` | `hog_supported_product_types` filter | add `bundle` |
| `case 'bundle'` in `Product::get_cart_products()`; `generate_bundle_configuration()`, `get_bundled_item_candidate()`, `choose_bundled_variation()`, `fit_bundle_size()` | `hog_cart_item` filter (`$cart_item, $product`), where returning `null` skips the product | add `bundle_configuration` or skip an unbuyable bundle |
| `Cart_Handler::is_bundle_item()` / `add_bundle_to_cart()` (single request, not `/batch`) | cart items may carry `extra` add-to-cart params and `single_request => true` | set both for bundles |
| Bundle part of `Generator::check_cart_for_subscription()` | `hog_cart_item_products` filter: the products a cart item contains | add the bundled items' products |
| `get_unusable_bundle_names()` notice in the settings class | `hog_settings_notices` filter | the "Product Bundles isn't active" notice. That notice must still show when Product Bundles is **inactive**, so this part is always loaded; only the cart hooks need Product Bundles active. |

The existing `bundles.spec.js` tests are the safety net for this move. They
need Product Bundles mounted locally, so run them with the override file before merging.

## Part 2: gateway base class

`Payment_Gateway` (abstract) holds what every gateway integration does:

- `abstract public function get_gateway_id(): string`
- `abstract protected function is_ready(): bool`: plugin active, gateway
  available and **in test mode**. It's the same hard rule for every gateway: never a live account.
- `public function add_support( array $gateways ): array`: hooked to
  `order_generator_supported_gateways`, adds the ID when `is_ready()`.
- `public function supports_subscriptions(): bool`
- `abstract public function get_checkout_payment_data( array $options, \WP_User $user ): array`:
  the Store API `payment_data` entries.
- `public function prepare( int $user_id, string $final_status ): array`: anything to set up before
  checkout, which Stripe uses for its customer and payment method. The default does nothing.
- `public function after_checkout( \WC_Order $order, array $options ): void`: hooked to the generator's
  order actions. The default does nothing.

Core changes:

- `Order_Builder::do_checkout()`: the `if ( 'stripe' === … )` block becomes
  `$body['payment_data'] = apply_filters( 'hog_checkout_payment_data', array(), $options, $user );`.
  This is the roadmap's Phase 3 `todo`.
- `Generator::generate_order()`: the `if ( 'stripe' === … )` block becomes
  `$options['payment_data'] = apply_filters( 'hog_prepare_payment_data', array(), $options['payment_method'], $customer->get_id(), $status );`.
- `Generator::get_payment_method()`: subscription carts prefer any supported, available gateway whose
  integration supports subscriptions, picked at random, instead of `stripe` only.
  `hog_subscription_payment_method` still filters the choice.
- **Order status follows WooCommerce core, not the generator.** The generator only decides whether
  an order is paid or fails. When the payment succeeds, core's `payment_complete()` sets the status
  from the order's contents and the store's settings:
  - `completed` when nothing in the order needs processing (all items virtual and downloadable);
  - `processing` otherwise, for example when an item needs shipping.

  Other plugins hooked into that flow get their say too, so a site can be tested under its own
  conditions. Every gateway works this way:
  - **Stripe and WooPayments:** the gateway calls `payment_complete()` itself, so the generator
    leaves the status alone.
  - **BACS:** the generator stands in for the payment arriving. It calls `$order->payment_complete()`
    and stops there.
  - **Fix:** today BACS orders are then forced to the picked status with
    `update_status( $status )`. Remove that override. Failed orders still go to `failed`.
- **Open question: the status percentages.** The settings have separate Completed, Processing and
  Failed percentages, and `Generator::get_final_status()` picks one of the three for each order.
  Under the rule above, the store decides between completed and processing, so only the paid/failed
  split applies. Two options:
  - replace the three settings with one "Failed orders (%)" setting, carrying the old failed value
    over on upgrade;
  - or keep the fields and describe them as a target that the store's settings can override.

  The first is clearer.

## Part 3: WooPayments integration

### What WooPayments does on its own

During a Store API checkout, WooCommerce copies `payment_data` into `$_POST` (`StoreApi\Legacy`,
priority 999 on `woocommerce_rest_checkout_process_payment_with_context`) and calls
`WC_Payment_Gateway_WCPay::process_payment()`. That method:

- **Payment method:** reads it from `wcpay-payment-method`
  (`Payment_Information::get_payment_method_from_request()`).
- **Customer:** creates or reuses the WooPayments customer itself, so there's no equivalent of Stripe's
  `create_stripe_customer()`.
- **Intent:** creates and confirms it with `Create_And_Confirm_Intention`, which accepts any ID matching
  `pm_\w+`.
- **Saving the card:** saves the payment method as a token when
  `wc-woocommerce_payments-new-payment-method` is set. It always saves for subscriptions
  (`maybe_prepare_subscription_payment_information()`), so renewals work with no token code of our own.
  The Stripe class creates its token by hand.
- **Declines:** on a decline it fails the order and writes its own order note.

So the integration doesn't call the WooPayments API at all. That avoids the main risk the roadmap flagged,
that WooPayments' internal API classes aren't a stable contract.

### Payment methods: Stripe's test tokens

Raw card numbers can't be sent server-side through WooPayments. Stripe's predefined test payment methods
can, because they're ordinary `pm_` IDs in test mode:

- **Success:** `pm_card_visa`, `pm_card_mastercard`, `pm_card_amex`, picked at random so the orders
  have different brands.
- **Failure** (status `failed`): `pm_card_chargeDeclined`, `pm_card_chargeDeclinedInsufficientFunds`,
  `pm_card_chargeDeclinedExpiredCard`, `pm_card_chargeDeclinedIncorrectCvc`,
  `pm_card_chargeDeclinedProcessingError`, `pm_card_chargeDeclinedLostCard`,
  `pm_card_chargeDeclinedStolenCard`.
- **Avoid:** tokens that trigger 3D Secure or fraud blocks, such as `pm_card_threeDSecure2Required` or
  `pm_card_radarBlock`. A 3DS challenge needs a browser and would leave the order pending.

Failures are real declines, so WooPayments' own failure note is on the order. That replaces the Stripe
class's intercept-and-fail flow (`do_failure_check()` / `do_checkout_fail()`).

### Gating (`is_ready()`)

All of these must hold:

- `class_exists( 'WC_Payments' )` and `is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' )`.
- `woocommerce_payments` is in `WC()->payment_gateways->get_available_payment_gateways()`.
  This also covers the account being connected and the card method being enabled.
- `WC_Payments::mode()->is_test()`.
- A minimum WooPayments version, to be pinned during the spike.

WooPayments switches to test mode by itself when `WP_ENVIRONMENT_TYPE` is `development` or `staging`
(`WCPay\Core\Mode`). It doesn't do that for `local`. On a local site, test mode comes from the settings
or a sandbox account.

### Checkout payload (`get_checkout_payment_data()`)

| Key | Value |
|---|---|
| `wcpay-payment-method` | a success or failure token, from the final status |
| `wc-woocommerce_payments-new-payment-method` | `true` when the cart has a subscription, otherwise random, so some customers have saved cards |
| `wcpay-fraud-prevention-token` | see below |

### Card-testing protection

When the account is eligible for card-testing protection, `process_payment()` requires
`wcpay-fraud-prevention-token` to match a token stored in the checkout's WooCommerce session. A shopper
gets it from the checkout page. The generator never loads that page.

The generator's checkout requests are already identified: `Order_Generator::log_customer_in()` validates
the single-use `X-HOG-Checkout-Token` earlier in the same request. Plan:

- `log_customer_in()` records that the current request is a verified generator checkout, for example in
  `Order_Generator::is_generator_checkout()`.
- The WooPayments integration hooks `woocommerce_rest_checkout_process_payment_with_context` before
  priority 999. When it's a generator checkout for `woocommerce_payments`, it adds
  `Fraud_Prevention_Service::get_instance()->get_token()` to the context's payment data.

That supplies the token only for the generator's own authenticated checkouts, in test mode, on
non-production sites. A real shopper's checkout is untouched. The same flag would let the Stripe
integration drop its "has a `final_status` key" check in `do_failure_check()`.

### Orders that fail at payment

A declined Store API checkout responds with HTTP 400, but the body is still the order, including its
`order_id` and `payment_result`. `HTTP_Client` returns the body for any status, so `do_checkout()` gets
the order and `generate_order()` labels it as generated. That's what we want for failed orders.

Confirm this in the spike. If the order ID isn't returned, record it server-side during the generator
checkout. The checkout token already gives us the request to key it on.

### Rate limiter

Declines bump WooPayments' `Session_Rate_Limiter`, which counts per WooCommerce session. Each generated
order has its own cart session, so it shouldn't trip. The spike should still run a batch with a high
failure percentage to check.

### Out of scope for 1.3.0

- Other WooPayments methods: SEPA, Bancontact, Klarna, and the like.
- Express checkout and WooPay.
- WooPayments' built-in subscriptions (Stripe Billing, used when Woo Subscriptions isn't active).
  Woo Subscriptions only.
- Multi-currency: orders use the store currency.

## Test account

There's no connected WooPayments account on order-generator.local or the Studio sites. WooPayments
11.3.0 is installed on order-generator.local but inactive. The spike needs a **WooPayments sandbox
("test drive") account**. It's created from Payments > Settings in test mode and needs no business
details or bank account. It does need a Jetpack/WordPress.com connection, which means the site needs a
public URL: either a tunnel to the Local site or a hosted staging site.

The test-mode payment methods above need nothing else: no card numbers, keys or secrets go in the
plugin or the repo.

**CI:** WooPayments is free on WordPress.org, so wp-env can install it.
- What CI can cover without a connection: the gating. With WooPayments inactive, or not connected, it
  isn't offered and orders still use BACS or Stripe.
- Real payment tests need the sandbox account and a public URL. They can't run in CI, so they run
  locally and skip when the account isn't connected, as the bundle tests skip without Product Bundles.

## Steps

1. **Spike (timeboxed, about half a day), on a sandbox-connected site:** a hand-built Store API checkout
   with `wcpay-payment-method = pm_card_visa`. Confirm:
   - the order is paid;
   - `pm_card_chargeDeclined` gives a failed order with an `order_id` in the 400 response;
   - the fraud-prevention token injection works;
   - a subscription saves a token and renews (`wp action-scheduler run` on the renewal).

   Write the results here and pin the minimum version.
2. **PR: integrations folder.** Move Stripe to `Integrations\Payment_Gateways\Stripe` and add
   `Integrations::load()` and the class alias. No behaviour change.
3. **PR: Product Bundles extraction.** Add the core seams and move the bundle code into
   `Integrations\Plugins\Product_Bundles`. Run `bundles.spec.js` locally with Product Bundles mounted.
4. **PR: gateway base class.** Add `Payment_Gateway`, the `hog_checkout_payment_data` and
   `hog_prepare_payment_data` filters, the generic subscription preference, and order status left to core (BACS override removed).
   Port Stripe onto the base.
5. **PR: WooPayments integration.** Add gating, payload, tokens and the fraud-token injection.
   - E2E in CI: not offered while inactive or not connected.
   - E2E run locally against the sandbox: success, failure, subscription.
   - Changelog and readme entries.
6. **Release 1.3.0** through the usual `dev` → `main` flow.

## Risks

- **Test tokens through the WooPayments server.** The tokens pass WooPayments' client-side ID check.
  Whether its server accepts them for a connected sandbox account is the main thing the spike has to
  confirm. If it doesn't, the fallback is to create a payment method with Stripe.js-equivalent requests
  using the account's test publishable key. That's more work and possibly blocked by Stripe's
  raw-card-data rules.
- **Card-testing protection** may change between WooPayments releases. The integration should check
  that `Fraud_Prevention_Service` exists and log clearly when a checkout is refused with
  `fraud_prevention_enabled`.
- **`WC_Payments::mode()` is internal API.** It has been stable since WooPayments 5.0
  (`Mode::AVAILABLE_SINCE`). Keep it behind the version check.
