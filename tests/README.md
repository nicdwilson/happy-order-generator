# Testing (QIT-equivalent)

Happy Order Generator is tested to the same standard as WooCommerce's
[Quality Insights Toolkit (QIT)](https://qit.woo.com/docs/).

QIT only runs against extensions registered in the Woo Marketplace, and this
plugin isn't registered (`qit run:activation happy-order-generator` →
*Could not find Woo Extension with slug happy-order-generator*). So the same
checks run here with the same underlying tools, locally and in GitHub Actions
(`.github/workflows/qit.yml`). The E2E tests are already laid out as a QIT
test package, so once the plugin is registered they can run under QIT as they are.

## What maps to what

| QIT test | Here | Command |
|---|---|---|
| Validation | `tests/qit/validate.php`: readme.txt, required headers, HPOS / Cart-Checkout declarations | `composer qit:validate` |
| Code Compatibility | PHPCompatibilityWP 10.x (the line QIT uses), PHP 8.1+ | `composer qit:compat` |
| PHPStan | Level 2 (QIT CLI default), WordPress + WooCommerce stubs, stubs for Stripe/Subscriptions | `composer qit:phpstan` |
| Security | PHPCS `WordPress.Security` + `WordPress.DB` sniffs, `composer audit` | `composer qit:security`, `composer qit:audit` |
| Activation | `tests/e2e/tests/activation.spec.js`: activate from Plugins screen, load admin pages, guest Store API checkout (COD), deactivate, no PHP notices from the plugin | see E2E below |
| Custom E2E | `tests/e2e/tests/*.spec.js`: enable/disable toggle, real order generation via the Store API, generated customers (meta, no-capability role, real customers never reused), the running notice (rate, admins and shop managers, dismissed per login), and the production block | see E2E below |

Not covered: QIT's Security test also runs SemGrep, WPScan and gitleaks, and
its Activation test runs a hook-resilience check. There's no Malware or
Plugin Check equivalent (Plugin Check is optional for the Marketplace).

All static checks: `composer qit:static`.

## E2E tests

`tests/e2e/` is a QIT test package (`qit-test.json`, Playwright, CTRF results
in `results/ctrf.json`). Locally it runs against [wp-env](https://www.npmjs.com/package/@wordpress/env)
(Docker required) at http://localhost:8890 (admin / password):

```sh
composer install            # the plugin needs vendor/ at runtime
cd tests/e2e
npm ci
npx playwright install chromium
npm run env:start           # starts WordPress + WooCommerce, runs bootstrap/setup.sh
npx playwright test         # or: npm run test:ui
npm run env:stop
```

`bootstrap/wp-env-mu-plugins/` is wp-env-only glue, mounted by `.wp-env.json`.
Inside the wp-env containers the site URL (`localhost:8890`) isn't the web
server, so the plugin's Store API loopback requests are rerouted to the
WordPress container. It is never shipped and never used under QIT.

### Product Bundles tests

`tests/bundles.spec.js` covers required and optional items, quantity ranges
(including no maximum), variable bundled items, bundle min/max item limits
and out-of-stock items, subscriptions inside bundles, and the settings notice
for bundles selected while Product Bundles is inactive. It needs Product
Bundles, a licensed extension, so it skips when Product Bundles isn't active
(as in CI), except for the inactive-Product-Bundles notice test, which always
runs. The subscription test also needs Woo Subscriptions; mount it the same way. To run it locally, mount
your copy with a `tests/e2e/.wp-env.override.json` file (gitignored):

```json
{
	"plugins": [
		"https://downloads.wordpress.org/plugin/woocommerce.latest-stable.zip",
		"../..",
		"/path/to/woocommerce-product-bundles"
	]
}
```

Then restart with `npm run env:start`.

### Known failures

There are none at the moment. When a test exposes a bug that isn't fixed
yet, mark it with `test.fail( true, 'Known bug: …' )` and list it here.
Playwright counts a marked test as passing while it fails, and reports it
as soon as the bug is fixed, so the marker can be removed.

Unverified: CTRF's summary counts expected failures under `failed`, even
though each test's status is `passed`. If QIT reads the summary, runs with
marked tests would show as failed.

### Running under QIT (once registered)

```sh
qit run:e2e happy-order-generator --test-package=./tests/e2e
qit package:publish ./tests/e2e
```

## Current baseline (main)

- Validation: **warning**: missing `Requires PHP` and `Requires at least` headers, no readme.txt.
- Code Compatibility: pass.
- PHPStan: pass against `tests/qit/phpstan-baseline.neon` (40 existing errors, mostly unchecked `WP_User|false`, plus the Logger `WP_Error` method bug). New errors fail.
- Security: pass (0 errors). The CI job blocks merges.
- Audit: pass.
