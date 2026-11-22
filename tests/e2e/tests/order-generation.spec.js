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

	test( 'debug logging records the order without dumping cart, checkout or payment data', async () => {
		const logs = "glob( trailingslashit( WC_LOG_DIR ) . 'happy-order-generator-*.log' )";
		await wpEval( `foreach ( ${ logs } as $f ) { unlink( $f ); }` );

		const productId = await productIdBySlug( 'hog-e2e-product' );
		await setSettings( { orders_per_hour: 6, products: [ String( productId ) ], enable_debug: 'yes' } );
		await runGeneratorBatch( 1 );
		await setSettings( { orders_per_hour: 6, products: [ String( productId ) ] } );

		const log = await wpEval( `$out = ''; foreach ( ${ logs } as $f ) { $out .= file_get_contents( $f ); } echo $out;` );
		expect( log ).toMatch( /Order ID \d+ created for customer ID \d+ paid with bacs/ );
		for ( const dump of [ 'Payment data prepared', 'Add to cart response', 'Checkout response', 'payment_methods', 'billing_address' ] ) {
			expect( log, dump ).not.toContain( dump );
		}
	} );

	test( 'generation raised no PHP notices, warnings or errors', async () => {
		const issues = pluginPhpIssues( await readDebugLog() );
		expect( issues, issues.join( '\n' ) ).toEqual( [] );
	} );
} );

test.describe( 'Payment method choice', () => {
	/**
	 * Calls Generator::get_payment_method() with Stripe supported and available,
	 * without needing the Stripe plugin or keys.
	 */
	async function chosenMethods( containsSubscription, runs ) {
		return JSON.parse(
			await wpEval( `
				add_filter( 'order_generator_supported_gateways', function ( $gateways ) { $gateways[] = 'stripe'; return $gateways; } );
				$generator = new Happy_Order_Generator\\Generator();
				$set = function ( $name, $value ) use ( $generator ) {
					$property = new ReflectionProperty( $generator, $name );
					$property->setAccessible( true );
					$property->setValue( $generator, $value );
				};
				$set( 'order_contains_subscription', ${ containsSubscription ? 'true' : 'false' } );
				$set( 'available_payment_methods', array( 'bacs', 'stripe' ) );
				$methods = array();
				for ( $i = 0; $i < ${ Number( runs ) }; $i++ ) { $methods[] = $generator->get_payment_method(); }
				echo wp_json_encode( array_values( array_unique( $methods ) ) );
			` )
		);
	}

	test( 'subscription orders use Stripe when it is supported and available', async () => {
		expect( await chosenMethods( true, 20 ) ).toEqual( [ 'stripe' ] );
	} );

	test( 'other orders can use any supported gateway', async () => {
		// 40 picks from two gateways: both turn up except with odds of about 1 in 500 billion.
		expect( ( await chosenMethods( false, 40 ) ).sort() ).toEqual( [ 'bacs', 'stripe' ] );
	} );

	test( 'no method is chosen when no supported gateway is available', async () => {
		const method = await wpEval( `
			$generator = new Happy_Order_Generator\\Generator();
			$property  = new ReflectionProperty( $generator, 'available_payment_methods' );
			$property->setAccessible( true );
			$property->setValue( $generator, array( 'cheque' ) );
			echo wp_json_encode( $generator->get_payment_method() );
		` );
		expect( JSON.parse( method ) ).toBe( '' );
	} );

	test( 'a subscription order with no supported gateway is skipped without an error', async () => {
		const active = await wpEval( "echo class_exists( 'WC_Subscriptions' ) ? 'yes' : 'no';" );
		test.skip( active !== 'yes', 'Woo Subscriptions is not active' );

		// Without Stripe, Woo Subscriptions hides BACS for subscriptions unless manual renewals are accepted.
		const subscriptionId = Number(
			await wpEval( `
				update_option( 'woocommerce_subscriptions_accept_manual_renewals', 'no' );
				$p = get_page_by_path( 'hog-e2e-subscription', OBJECT, 'product' );
				$x = $p ? wc_get_product( $p->ID ) : new WC_Product_Subscription();
				$x->update_meta_data( '_subscription_price', '5' ); $x->update_meta_data( '_subscription_period', 'month' ); $x->update_meta_data( '_subscription_period_interval', '1' );
				$x->set_name( 'hog-e2e-subscription' ); $x->set_slug( 'hog-e2e-subscription' ); $x->set_regular_price( '5' );
				$x->set_virtual( true ); $x->set_status( 'publish' );
				echo $x->save();
			` )
		);
		await setSettings( { orders_per_hour: 6, products: [ String( subscriptionId ) ] } );
		await clearDebugLog();

		const before = await generatedOrderCount();
		await runGeneratorBatch( 1 );
		expect( await generatedOrderCount() ).toBe( before );

		const issues = pluginPhpIssues( await readDebugLog() );
		expect( issues, issues.join( '\n' ) ).toEqual( [] );
	} );
} );

test.describe( 'Random product pool', () => {
	const fixtures = [
		{ slug: 'hog-e2e-draft', status: 'draft', stock: 'instock' },
		{ slug: 'hog-e2e-private', status: 'private', stock: 'instock' },
		{ slug: 'hog-e2e-out-of-stock', status: 'publish', stock: 'outofstock' },
	];

	test.beforeAll( async () => {
		for ( const { slug, status, stock } of fixtures ) {
			await wpEval( `
				$p = get_posts( array( 'name' => '${ slug }', 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => 1 ) );
				$x = $p ? wc_get_product( $p[0]->ID ) : new WC_Product_Simple();
				$x->set_name( '${ slug }' ); $x->set_slug( '${ slug }' ); $x->set_regular_price( '5' ); $x->set_virtual( true );
				$x->set_status( '${ status }' ); $x->set_stock_status( '${ stock }' );
				$x->save();
			` );
		}
	} );

	test.afterAll( async () => {
		for ( const { slug } of fixtures ) {
			await wpEval( `foreach ( get_posts( array( 'name' => '${ slug }', 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1 ) ) as $p ) { wc_get_product( $p->ID )->delete( true ); }` );
		}
		await setSettings();
	} );

	test( 'leaves out unpublished and out-of-stock products', async () => {
		await setSettings( { orders_per_hour: 6, products: [], min_order_products: 3, max_order_products: 3 } );

		const picked = JSON.parse(
			await wpEval( `
				$seen = array();
				for ( $i = 0; $i < 60; $i++ ) {
					foreach ( ( new Happy_Order_Generator\\Product() )->get_random_product_ids() as $id ) { $seen[ $id ] = get_post_field( 'post_name', $id ); }
				}
				echo wp_json_encode( array_values( $seen ) );
			` )
		);
		expect( picked ).toContain( 'hog-e2e-product' );
		for ( const { slug } of fixtures ) {
			expect( picked ).not.toContain( slug );
		}
	} );
} );
