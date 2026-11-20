/**
 * End to end: the scheduled callback creates a real order through the
 * Store API cart and checkout, using BACS and a virtual product.
 */
const { test, expect } = require( '@playwright/test' );
const {
	setSettings,
	generatedOrderCount,
	runGeneratorBatch,
	productIdBySlug,
	clearDebugLog,
	readDebugLog,
	pluginPhpIssues,
	clearScheduledActions,
	wpEval,
} = require( './utils/wp' );

/** Status and attribution of the most recent generated order. */
async function latestGeneratedOrder() {
	return JSON.parse(
		await wpEval(
			"$o = wc_get_orders( array( 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'meta_key' => '_happy_order_generator_order', 'meta_value' => 1 ) )[0];" +
				"echo wp_json_encode( array( 'status' => $o->get_status(), 'source_type' => (string) $o->get_meta( '_wc_order_attribution_source_type' ), 'device_type' => (string) $o->get_meta( '_wc_order_attribution_device_type' ) ) );"
		)
	);
}

// Tests share one site and run in file order (workers: 1). A failure does not
// skip the rest, matching how QIT reports every activation step.

test.describe( 'Order generation', () => {
	test.beforeAll( async () => {
		const productId = await productIdBySlug( 'hog-e2e-product' );
		await setSettings( { enabled: 'yes', orders_per_hour: 6, products: [ String( productId ) ] } );
		await clearScheduledActions();
		await clearDebugLog();
	} );

	test( 'a scheduled batch creates one tagged order', async () => {
		const before = await generatedOrderCount();
		await runGeneratorBatch( 1 );
		expect( await generatedOrderCount() ).toBe( before + 1 );

		const order = JSON.parse(
			await wpEval(
				"$o = wc_get_orders( array( 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'meta_key' => '_happy_order_generator_order', 'meta_value' => 1 ) )[0];" +
					"echo wp_json_encode( array( 'status' => $o->get_status(), 'payment' => $o->get_payment_method(), 'ip' => $o->get_customer_ip_address(), 'items' => count( $o->get_items() ) ) );"
			)
		);
		expect( order.payment ).toBe( 'bacs' );
		expect( order.items ).toBeGreaterThan( 0 );
		expect( order.ip ).not.toBe( '' );
		expect( [ 'processing', 'completed' ] ).toContain( order.status );
	} );

	test( 'uses the saved order status percentages', async () => {
		const productId = await productIdBySlug( 'hog-e2e-product' );
		await setSettings( { orders_per_hour: 6, products: [ String( productId ) ], order_completed_pct: 100, order_processing_pct: 0, order_failed_pct: 0 } );

		await runGeneratorBatch( 1 );

		expect( ( await latestGeneratedOrder() ).status ).toBe( 'completed' );
	} );

	test( 'adds order attribution data only when the setting is on', async () => {
		const productId = await productIdBySlug( 'hog-e2e-product' );

		await setSettings( { orders_per_hour: 6, products: [ String( productId ) ], order_attribution: 'no' } );
		await runGeneratorBatch( 1 );
		expect( ( await latestGeneratedOrder() ).source_type ).toBe( '' );

		await setSettings( { orders_per_hour: 6, products: [ String( productId ) ], order_attribution: 'yes' } );
		await runGeneratorBatch( 1 );
		const order = await latestGeneratedOrder();
		expect( [ 'organic', 'referral', 'utm', 'typein', 'admin' ] ).toContain( order.source_type );
		expect( [ 'Desktop', 'Mobile', 'Tablet' ] ).toContain( order.device_type );
	} );

	test( 'generation raised no PHP notices, warnings or errors', async () => {
		const issues = pluginPhpIssues( await readDebugLog() );
		expect( issues, issues.join( '\n' ) ).toEqual( [] );
	} );
} );
