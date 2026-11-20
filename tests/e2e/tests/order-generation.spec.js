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

	test( 'generation raised no PHP notices, warnings or errors', async () => {
		const issues = pluginPhpIssues( await readDebugLog() );
		expect( issues, issues.join( '\n' ) ).toEqual( [] );
	} );
} );
