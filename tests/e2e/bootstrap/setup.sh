#!/bin/bash
#
# Per-package setup. Runs with WP-CLI available, inside the QIT container or
# the wp-env CLI container (`npm run env:start`). Safe to re-run.
#
set -euo pipefail

wp plugin activate woocommerce

# Pretty permalinks, so /wp-json/ routes resolve.
wp rewrite structure "/%postname%/" --hard

# Store basics so Store API checkout works without onboarding.
wp option update woocommerce_coming_soon no
wp option update woocommerce_store_address "1 Test Street"
wp option update woocommerce_store_city "San Francisco"
wp option update woocommerce_default_country "US:CA"
wp option update woocommerce_store_postcode "94110"
wp option update woocommerce_currency "USD"

# Payment methods: BACS is what the generator uses; COD mirrors QIT's activation test.
wp option update woocommerce_bacs_settings '{"enabled":"yes","title":"Direct bank transfer"}' --format=json
wp option update woocommerce_cod_settings '{"enabled":"yes","title":"Cash on delivery"}' --format=json

# One virtual simple product, so orders need no shipping method.
wp eval '
if ( ! get_page_by_path( "hog-e2e-product", OBJECT, "product" ) ) {
	$p = new WC_Product_Simple();
	$p->set_name( "HOG E2E Product" );
	$p->set_slug( "hog-e2e-product" );
	$p->set_regular_price( "10" );
	$p->set_virtual( true );
	$p->set_status( "publish" );
	$p->save();
}
'

# Keep Action Scheduler from running queued actions in the background (WP-Cron
# or the async request runner on admin page loads). The generator's action is
# scheduled to start immediately, so a background run would race the tests.
# Specs run the callback themselves when they need it.
MU_DIR="$(wp eval 'echo WPMU_PLUGIN_DIR;')"
mkdir -p "$MU_DIR"
cat > "$MU_DIR/hog-e2e-no-background-queue.php" <<'PHP'
<?php
/**
 * Plugin Name: HOG E2E – no background Action Scheduler runs
 * Description: Test environment only. Written by tests/e2e/bootstrap/setup.sh.
 */
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false' );
add_filter( 'action_scheduler_queue_runner_concurrent_batches', '__return_zero' );
PHP

# Plugin starts active with no settings; each spec sets what it needs.
wp plugin activate happy-order-generator
wp option delete happy_order_generator_settings || true
