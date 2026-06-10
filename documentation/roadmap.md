# Happy Order Generator — Release Roadmap

Goal: take the `add-product-bundles-support` work to a clean, tested release, bring the codebase up to WordPress/WooCommerce standards, and scope a WooPayments gateway integration that mirrors the existing Stripe integration.

Suggested release target: **v1.1.0** (bundles + refactor + fixes), with the WooPayments integration as **v1.2.0**.

---

## Branching & release workflow

Decided June 2026:

- `main` is the **stable/release** branch. `dev` is the **integration** branch (currently identical to `main`).
- Each Phase 1 bug that exists on `dev`/`main` gets its own `fix/<slug>` branch **off `dev`**, fixed in its own PR, reviewed, and merged to `dev`.
- When a release is ready (end of Phase 4/5), a single release PR merges `dev` → `main`, which is then tagged (`v1.1.0`).
- `add-product-bundles-support` is **parked**: no further work on it until the bundles integration resumes (Phase 2). When it resumes, rebase it onto `dev` so it picks up the merged bug fixes — expect a manual merge in `class-logger.php` (rewritten on the branch) and `class-generator.php`.
- Bugs that only exist in the branch's new code (1.2, 1.6, branch-only parts of 1.8) are fixed **on the bundles branch** when work resumes, not via `dev` fix branches.

(For reference: WooCommerce core itself uses trunk-based development — short-lived branches PR'd straight to `trunk`, with release branches cut per version. Either model is acceptable; the dev/main split above adds a staging buffer which suits a plugin with manual QA phases. The non-negotiables are the same in both: one concern per PR, CI green before merge, protected release branch.)

## Phase 0 — Branch hygiene

- [x] Commit the untracked `includes/class-http-client.php` and `includes/class-cart-handler.php` plus the pending modifications so the refactor is captured as a coherent commit. *(Done: `de5530c` on `add-product-bundles-support`, marked WIP.)*
- [ ] **Deferred to Phase 2 resumption:** strip or downgrade the temporary diagnostic logging added while debugging (e.g. `print_r` dumps of full cart/checkout responses in `class-generator.php` and `class-order-builder.php`). Keep structured `Logger::info/warning/error` calls; drop the noisy step-by-step traces.
- [ ] **Deferred to Phase 2 resumption:** rebase onto `dev` and squash the "debugging" commits (`e74a9dc`, etc.) into meaningful history before PR.

## Phase 1 — Bug fixes

Ordered roughly by severity. Each item should land with a unit test where practical (see Phase 4).

**Where each bug lives** (verified against `main`):

| Bug | Exists on `dev`/`main`? | Fix via |
|---|---|---|
| 1.1 Settings key mismatch | Yes (pre-existing) | `fix/settings-option-key` off `dev` |
| 1.2 Wrong `bundled_item_id` | No — branch-only code | Bundles branch, on resumption |
| 1.3 `log_customer_in()` reads `$_POST` | Yes (pre-existing) | `fix/store-api-customer-login` off `dev` |
| 1.4 Logger `WP_Error` fatal + unguarded setting | Yes (pre-existing; branch has a rewritten Logger with the same `get_all_error_messages()` bug — fix both) | `fix/logger-wp-error` off `dev`, re-apply on branch rebase |
| 1.5 Scheduler `=` vs `==` | Yes (pre-existing) | `fix/scheduler-interval-check` off `dev` |
| 1.6 `HTTP_Client` typed properties | No — branch-only code (file doesn't exist on `dev`) | Bundles branch, on resumption |
| 1.7 `subscription_variation` not detected | Yes (pre-existing) | `fix/subscription-variation-detection` off `dev` |
| 1.8 Smaller items | Mixed — text domain, dead `check()`/`admin_notices()` are on `dev`; `Order_Builder` leftovers are branch-only | Split accordingly |

### 1.1 Settings option key mismatch (high — wrong runtime config)
- `Generator::__construct()` (`includes/class-generator.php:63`) reads `wc_order_generator_settings`, but the settings page saves to `happy_order_generator_settings` (`includes/admin/class-wc-settings-order-generator.php:310`). The status-percentage settings the Generator uses are therefore stale or empty.
- `Cron_Jobs::setup_action_scheduler()` also writes its computed `batch_size`/`interval` back to `wc_order_generator_settings` (`includes/class-cron-jobs.php:86`) while reading from `Order_Generator::get_settings()`.
- **Fix:** all reads go through `Order_Generator::get_settings()`; store scheduler state (batch size / interval) in its own option (e.g. `hog_scheduler_state`) instead of mixing it into user settings. Delete the orphaned `wc_order_generator_settings` option on upgrade.

### 1.2 Bundle configuration uses the wrong ID (high — likely root cause of bundle checkout failures)
- `Product::generate_bundle_configuration()` sets `bundled_item_id` to `$bundled_item->get_product_id()` (`includes/class-product.php:342`). The Product Bundles Store API expects the **bundled item ID** (the key/row ID, i.e. `$bundled_item->get_id()` / the `$bundled_item_id` array key), not the product ID.
- **Fix:** use the bundled item ID; verify the payload shape against the Product Bundles Store API documentation/source. This is the first thing to test once fixed — it plausibly explains the order-creation failures the recent debug commits were chasing.

### 1.3 `log_customer_in()` reads `$_POST` for a JSON request (high — customer never logged in)
- `woocommerce-order-generator.php:133` reads `$_POST['billing_address']['email']`, but Store API checkout requests are JSON bodies, so `$_POST` is empty (and the code would notice-fail on the missing index).
- **Fix:** use the `$request` parameter provided by the `woocommerce_store_api_checkout_update_customer_from_request` hook: `$request['billing_address']['email']` (with isset guard).

### 1.4 Logger fatals when logging a `WP_Error` (medium)
- `Logger::log()` calls `$message->get_all_error_messages()` (`includes/class-logger.php:48`) — that method does not exist on `WP_Error` (it's `get_error_messages()`), so logging an error object fatals exactly when something has already gone wrong.
- Also `includes/class-logger.php:37` reads `$settings['enable_debug']` without an `isset()` guard — warnings on fresh installs where settings were never saved.
- **Fix:** correct the method name, guard the setting with a default of `'no'`.

### 1.5 Assignment instead of comparison in scheduler check (medium)
- `includes/class-cron-jobs.php:73`: `$this->settings['interval'] = $interval_in_seconds` inside the `if` condition should be `==`/`===`. As written, the "leave the existing scheduled action alone" short-circuit misbehaves and the action is rescheduled more often than intended.

### 1.6 `HTTP_Client` typed-property pitfalls (medium)
- `private string $nonce` is read in `build_request_args()` (`includes/class-http-client.php:152`) before initialization if `authenticate()` was never called → `Error: typed property accessed before initialization`.
- `clear_authentication()` assigns `null` to the non-nullable `string $nonce` → `TypeError`.
- **Fix:** declare `private ?string $nonce = null`.

### 1.7 Subscription detection misses variable-subscription carts (low)
- `Generator::check_cart_for_subscription()` (`includes/class-generator.php:239`) checks product types `subscription` and `variable-subscription`, but cart items for variable subscriptions carry the **variation** ID, whose type is `subscription_variation`. Such carts can be routed to BACS, creating manual renewals.
- **Fix:** include `subscription_variation`, and guard against `wc_get_product()` returning `false`.

### 1.8 Smaller items (low)
- `includes/class-product.php:333`: duplicated assignment `$include_item = $include_item = rand( 0, 1 );`.
- `Generator::generate_order()` constructs `Order_Builder` which can throw (nonce failure) — wrap in try/catch and return `false` cleanly instead of letting the exception bubble to Action Scheduler.
- `Order_Builder` retains unused `$cookies` property and leftover state from before the refactor.
- `Order_Generator::check()` and `admin_notices()` exist but are never invoked — wire up the version checks on `plugins_loaded` + `admin_notices`, or remove them.
- Wrong text domain in `woocommerce-order-generator.php:160` (`'Happy Order Generator'` instead of `'happy-order-generator'`).

## Phase 2 — Finalize the Product Bundles integration

### History — why the cart setup was split (recorded June 2026)

The original cart code added all products through the Store API `/batch` endpoint. During bundle development we found that the bundle metadata (`bundle_configuration`) was not reaching Product Bundles in a readable format: bundles need the payload submitted as JSON, which Product Bundles handles correctly on a direct request, but when the same request goes through `/batch`, core WordPress's batch handling (`WP_REST_Server::serve_batch_request_v1()`) re-encodes the nested structure as array-style POST parameters, which Product Bundles cannot parse. This is what led to the split cart setup on the `add-product-bundles-support` branch — bundles via individual `/cart/add-item` POSTs, regular products still via `/batch` — which is admittedly messier than the original single-batch design. When resuming this phase, either keep the split (and document it as a WP-core limitation), simplify to single requests for *everything* (at the cost of one HTTP round trip per item), or investigate whether the batch handling has since been fixed upstream.

### Tasks

- [ ] Land fix 1.2 (correct `bundled_item_id`) and verify a simple bundle reaches checkout end-to-end via the Store API.
- [ ] Re-enable the commented-out **variable products inside bundles** block in `Product::generate_bundle_configuration()` (`includes/class-product.php:358-386`); confirm the expected keys (`variation_id`, `attributes` with `name`/`option`) against the Product Bundles Store API contract, and reuse the existing "Any attribute" fallback logic from the variation handling above it.
- [ ] Respect bundle constraints when generating configurations: minimum/maximum bundle size, required (non-optional) items, and out-of-stock bundled items — currently random optional selection can produce invalid configurations that the API will reject.
- [ ] Decide and document behaviour when Product Bundles is **not** active: `bundle` type is already removed from `$product_types` (`includes/class-product.php:81-84`); add a settings-page notice if the admin has explicitly selected a bundle product that can't be used.
- [ ] Clean up `Cart_Handler` logging and confirm the single-request-vs-batch routing comment block reflects the final behaviour.
- [ ] Add bundle products to the test fixtures (Phase 4) including: simple bundle, bundle with optional items, bundle with variable items, bundle with min/max quantities.
- [ ] Open the PR to `main` once e2e passes with bundles in the random product pool.

## Phase 3 — Code standards & hardening

### Tooling
- [ ] Add **PHPCS** with the `WooCommerce-Core` (or `WordPress` + WooCommerce) ruleset; `composer lint` / `composer lint:fix` scripts; fix violations (escaping, sanitization, yoda conditions, `wp_rand()` instead of `rand()`/`mt_rand()`, i18n).
- [ ] Add **PHPStan** (level 5–6) with `php-stubs/wordpress-stubs` and `php-stubs/woocommerce-stubs`; this would have caught 1.4, 1.6, and the `WP_Error` method typo class of bugs.
- [ ] Add a basic **GitHub Actions** workflow: lint + static analysis + unit tests on PHP 8.1/8.2/8.3.

### Structure & consistency
- [ ] Introduce a small `Settings` class with typed getters and defaults (`get_orders_per_hour(): int`, `is_debug_enabled(): bool`, …) so the scattered `isset()` checks disappear and the option key lives in one place (fixes the class of bug in 1.1 permanently).
- [ ] Resolve the singleton inconsistency: `Product`, `Customer`, `Generator` define `instance()` but are also instantiated with `new` (and `class-product.php:398` calls `Product::instance()` at file load for no clear reason). Pick plain instantiation (preferred — these are per-order objects) and delete the singleton scaffolding.
- [ ] Replace the remaining `todo` markers: the Stripe-specific block in `Order_Builder::do_checkout()` (`includes/class-order-builder.php:226-269`) should become a filter (e.g. `hog_checkout_payment_data`) that gateway integrations hook — this is also the seam the WooPayments integration needs (Phase 6).
- [ ] Reconsider `sleep( 5 )` between orders in `Cron_Jobs::create_orders()` — it blocks an Action Scheduler worker for the whole batch. Prefer scheduling N single actions per interval instead of one batch action with sleeps.

### Plugin housekeeping
- [ ] Update the plugin header: real version number (`1.1.0`), `Requires at least`, `Requires PHP`, `Requires Plugins: woocommerce`, current `Tested up to` / `WC tested up to` values (header still says WP 6.2 / WC 7.7).
- [ ] Implement `Installer::deactivate_plugin()` → `as_unschedule_all_actions( 'create_happy_orders' )`; add `uninstall.php` to delete both options and (optionally, behind a constant) generated test data.
- [ ] Add `readme.txt` with description, FAQ (including the `_happy_order_generator_order` meta cleanup tip), and changelog.
- [ ] Document all public hooks/filters in `documentation/` (the list in `project-description.md` is the starting point); decide on one prefix (`hog_` vs `order_generator_`) and deprecate the other consistently.

## Phase 4 — Testing

### Unit / integration tests (PHPUnit)
- [ ] Set up `wp-env` + the WP test suite (`WP_UnitTestCase`) with WooCommerce loaded; add WooCommerce's helper factories for products/orders.
- Priority targets (pure logic, no HTTP):
  - [ ] `Product::get_cart_products()` — simple, variable (including "Any" attribute resolution), and bundle payload shapes.
  - [ ] `Product::generate_bundle_configuration()` — required vs optional items, quantity min/max, variable bundled items (needs Product Bundles in the test env or a thin wrapper interface that can be mocked).
  - [ ] `Generator::get_final_status()` — distribution respects configured percentages (seedable randomness: wrap `wp_rand()` so tests can inject values).
  - [ ] `WC_Settings_Order_Generator_Settings::save()` — percentage auto-balancing to 100, min/max product clamping, orders-per-hour cap.
  - [ ] `Cron_Jobs` interval and batch-size math (1/hour → 3600s, 60/hour → 60s batch 1, 120/hour → batch 2).
  - [ ] `Logger` — WP_Error handling, debug gating, context serialization.
- [ ] For `HTTP_Client` / `Cart_Handler` / `Order_Builder`: intercept requests with the `pre_http_request` filter to return canned Store API responses — test retry/backoff, nonce expiry (404 path), bundle-vs-batch routing, and checkout error mapping without a live loopback.

### End-to-end tests
- [ ] `wp-env` (or WordPress Playground blueprint) provisioning: WooCommerce + sample products + Product Bundles + the plugin, with settings pre-seeded.
- [ ] Driver: Playwright for admin-facing checks, WP-CLI for triggering generation deterministically (`wp action-scheduler run --hooks=create_happy_orders` or invoking the hook directly) instead of waiting on real cron.
- Scenarios:
  - [ ] Settings page saves and round-trips every field.
  - [ ] With orders-per-hour set, a scheduled action exists; running it creates an order with `_happy_order_generator_order = 1`, an order note, and a fake customer IP.
  - [ ] Generated orders land in the configured status mix (smoke check, not exact distribution).
  - [ ] Bundle product order contains the parent bundle line item plus bundled child line items.
  - [ ] Variable product order has correct variation attributes.
  - [ ] New-customer mode creates users with the configured naming/email convention and locale.
  - [ ] Subscriptions (with Woo Subscriptions + Stripe test mode): subscription is active with a saved payment token. *Requires Stripe test keys as CI secrets — mark as a nightly/optional job rather than per-PR.*
- [ ] CI: per-PR job runs lint + unit + non-gateway e2e; nightly job runs the gateway e2e suite.

## Phase 5 — Release

- [ ] Version bump to `1.1.0`, changelog, tag, build a distributable zip (exclude `.idea`, `documentation/`, tests, dev deps via `.distignore`).
- [ ] Manual QA matrix: PHP 8.1/8.3 × WC latest/L-2 × HPOS on/off × (Subscriptions, Product Bundles, Stripe) present/absent.
- [ ] Run the pre-release review skills: code review, upgrade-safety check (option key migration from 1.1 matters here), and a final code-health pass.

---

## Phase 6 — WooPayments gateway integration (v1.2.0)

Mirror the architecture of `Gateway_Integration_Stripe`: a self-contained class that opts the gateway in via filters, supplies checkout `payment_data`, simulates failures with test cards, and leaves subscriptions renewable.

### 6.0 Prerequisite refactor
- [ ] Extract an `Abstract_Gateway_Integration` (or interface) from the Stripe class capturing the contract: `add_gateway_support( array ): array`, `get_payment_data( $user_id, $final_status ): array`, failure-handling hooks. Move the Stripe-specific `payment_data` block out of `Order_Builder::do_checkout()` behind a `hog_checkout_payment_data` filter (Phase 3 item) so each integration owns its own checkout payload.

### 6.1 Detection and gating (mirrors `add_stripe_support()`)
- [ ] Hook `order_generator_supported_gateways`; add `woocommerce_payments` only when **all** of:
  - WooPayments plugin active (`class_exists( 'WC_Payments' )` and `is_plugin_active( 'woocommerce-payments/woocommerce-payments.php' )`),
  - the gateway is in WooCommerce's available gateways list,
  - the account is connected, and
  - **test mode is enabled** (`WC_Payments::mode()->is_test()`); never generate against a live account — same hard rule as Stripe.

### 6.2 API access (the key difference from Stripe — needs a spike first)
The Stripe integration talks to Stripe's API directly via `WC_Stripe_API::request()` with raw test card numbers. WooPayments proxies all API traffic through Automattic's platform (`WC_Payments_API_Client`), and **raw card numbers cannot be tokenized server-side** — tokenization normally happens client-side.

- [ ] **Spike (timeboxed):** confirm what `WC_Payments_API_Client` exposes for server-side use in test mode. Expected approach:
  - Customer: reuse `WC_Payments_Customer_Service` (`create_customer_for_user()` / `get_customer_id_by_user_id()`), which stores the customer ID in user meta (test-mode key is separate from live).
  - Payment method: in test mode, use Stripe's **predefined test payment-method tokens** (`pm_card_visa` for success; `pm_card_chargeDeclined`, `pm_card_chargeDeclinedInsufficientFunds`, etc. for failures) attached to the customer, instead of creating payment methods from raw card numbers. This replaces the Stripe class's `$test_card_fails` array of card numbers with a map of failing `pm_` tokens.
  - Intent: `create_and_confirm_intention()` (or the request-class equivalent in current WooPayments) with `metadata` marking the order as generated, mirroring `setup_payment_intent()`.
- [ ] Document spike findings in `documentation/woopayments-integration.md` before implementation; if server-side attachment of test tokens is blocked by the proxy, fall back to driving the Store API checkout with `payment_data` only and letting WooPayments' own checkout processing create the intent.

### 6.3 Checkout payload (mirrors the Stripe `payment_data` block)
- [ ] Provide Store API `payment_data` entries for the WooPayments gateway — at minimum `wcpay-payment-method` (the `pm_…` ID) plus the save-payment-method flag (`wc-woocommerce_payments-new-payment-method => true`) so subscriptions get a saved method. Verify exact keys against WooPayments' `WC_Payments_Checkout`/Store API handling for the pinned WooPayments version, and pin a minimum supported WooPayments version in the integration's gating check.

### 6.4 Failure simulation (mirrors `do_failure_check()` / `do_checkout_fail()`)
- [ ] Intercept `woocommerce_rest_checkout_process_payment_with_context` when `final_status !== 'paid'`, set the payment result to pending, then on `order_generator_order_failed` confirm an intent with a declining test token and write the gateway error code/message into an order note — same UX as the Stripe failure notes.

### 6.5 Subscriptions
- [ ] On successful subscription orders, save a `WC_Payment_Token_CC` with `gateway_id = 'woocommerce_payments'` and the test payment method details, and ensure the WooPayments customer/payment-method meta is on the subscription so renewals work (mirrors `pay_generated_order()`'s token block). Cover both Woo Subscriptions and WooPayments' built-in subscriptions, or explicitly scope to Woo Subscriptions for v1.2.0.

### 6.6 Selection logic and tests
- [ ] Update `Generator::get_payment_method()` so subscription-containing carts can prefer either Stripe or WooPayments (currently hardcoded to prefer `stripe`); make the preference filterable.
- [ ] Unit tests against a mocked API client; e2e nightly job with a connected test-mode WooPayments dev account (provisioning this sandbox account is a task in itself — flag it early).

### Risks / unknowns
- WooPayments' server-side API surface is not a stable public contract; releases can rename request classes. Mitigate by pinning a minimum version and feature-detecting before opting in.
- Test-token availability through the WCPay proxy is the main feasibility question — hence the spike before committing to the v1.2.0 scope.

---

## Suggested sequencing

| Milestone | Contents | Size |
|---|---|---|
| 1 | Phase 0 + Phase 1 (bugs) | S–M |
| 2 | Phase 2 (bundles complete, PR merged) | M |
| 3 | Phase 3 (standards, tooling) + Phase 4 unit tests | M–L |
| 4 | Phase 4 e2e + CI, then Phase 5 release v1.1.0 | M |
| 5 | Phase 6 spike → WooPayments integration → v1.2.0 | L |