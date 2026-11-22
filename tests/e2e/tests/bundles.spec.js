/**
 * Product Bundles: generated orders can contain bundles.
 *
 * Product Bundles is a licensed extension, so these tests skip when it
 * isn't active. To run them locally, mount it with .wp-env.override.json
 * (see tests/README.md).
 */
const { test, expect } = require( '@playwright/test' );
const { wp, wpEval, setSettings, generatedOrderCount, runGeneratorBatch, clearScheduledActions, productIdBySlug, loginAsAdmin, SETTINGS_URL, clearDebugLog, readDebugLog, pluginPhpIssues } = require( './utils/wp' );

/**
 * Creates (or reuses) a virtual variable product with a custom Size attribute
 * (S, M) and a global hogcolor attribute (red, blue). Variations: size S with
 * any colour, and size M in red.
 *
 * @param {string} slug Product slug.
 * @return {Promise<number>} Product ID.
 */
async function ensureVariable( slug ) {
	const php = `
		$p = get_page_by_path( '${ slug }', OBJECT, 'product' );
		if ( $p ) { echo $p->ID; return; }
		if ( ! wc_attribute_taxonomy_id_by_name( 'hogcolor' ) ) {
			wc_create_attribute( array( 'name' => 'HOG Color', 'slug' => 'hogcolor' ) );
		}
		if ( ! taxonomy_exists( 'pa_hogcolor' ) ) { register_taxonomy( 'pa_hogcolor', 'product' ); }
		$terms = array();
		foreach ( array( 'red', 'blue' ) as $t ) {
			$term = term_exists( $t, 'pa_hogcolor' );
			$terms[] = (int) ( $term ? $term['term_id'] : wp_insert_term( ucfirst( $t ), 'pa_hogcolor', array( 'slug' => $t ) )['term_id'] );
		}
		$size = new WC_Product_Attribute();
		$size->set_name( 'Size' ); $size->set_options( array( 'S', 'M' ) ); $size->set_visible( true ); $size->set_variation( true );
		$color = new WC_Product_Attribute();
		$color->set_id( wc_attribute_taxonomy_id_by_name( 'hogcolor' ) ); $color->set_name( 'pa_hogcolor' );
		$color->set_options( $terms ); $color->set_visible( true ); $color->set_variation( true );
		$product = new WC_Product_Variable();
		$product->set_name( '${ slug }' ); $product->set_slug( '${ slug }' ); $product->set_status( 'publish' );
		$product->set_attributes( array( $size, $color ) );
		$id = $product->save();
		foreach ( array( array( 'size' => 'S', 'pa_hogcolor' => '' ), array( 'size' => 'M', 'pa_hogcolor' => 'red' ) ) as $attributes ) {
			$v = new WC_Product_Variation();
			$v->set_parent_id( $id ); $v->set_attributes( $attributes );
			$v->set_regular_price( '5' ); $v->set_virtual( true ); $v->set_status( 'publish' );
			$v->save();
		}
		WC_Product_Variable::sync( $id );
		echo $id;
	`;
	return Number( await wpEval( php ) );
}

/**
 * Creates a published bundle of virtual products, replacing any earlier one.
 *
 * @param {string} slug    Bundle slug.
 * @param {Array}  items   Bundled items: { slug, optional, min, max, outOfStock?, variable?, subscription? }.
 *                         A max of null means no maximum.
 * @param {Object} options Bundle item limits: { minSize?, maxSize? }.
 * @return {Promise<number>} Bundle product ID.
 */
async function ensureBundle( slug, items, options = {} ) {
	const ids = [];
	for ( const item of items ) {
		ids.push( item.variable ? await ensureVariable( item.slug ) : 0 );
	}
	const php = `
		// Recreate each run so fixture changes always apply.
		$existing = get_page_by_path( '${ slug }', OBJECT, 'product' );
		if ( $existing ) { wc_get_product( $existing->ID )->delete( true ); }
		$simple = function ( $slug, $out_of_stock, $subscription ) {
			$p = get_page_by_path( $slug, OBJECT, 'product' );
			$x = $p ? wc_get_product( $p->ID ) : ( $subscription ? new WC_Product_Subscription() : new WC_Product_Simple() );
			if ( $subscription ) { $x->update_meta_data( '_subscription_price', '5' ); $x->update_meta_data( '_subscription_period', 'month' ); $x->update_meta_data( '_subscription_period_interval', '1' ); }
			$x->set_name( $slug ); $x->set_slug( $slug ); $x->set_regular_price( '5' );
			$x->set_virtual( true ); $x->set_status( 'publish' );
			$x->set_stock_status( $out_of_stock ? 'outofstock' : 'instock' );
			return $x->save();
		};
		$ids  = json_decode( '${ JSON.stringify( ids ) }', true );
		$data = array();
		foreach ( json_decode( '${ JSON.stringify( items ) }', true ) as $i => $item ) {
			$data[] = array(
				'product_id' => $ids[ $i ] ? $ids[ $i ] : $simple( $item['slug'], ! empty( $item['outOfStock'] ), ! empty( $item['subscription'] ) ),
				'meta_data'  => array(
					'optional'         => $item['optional'] ? 'yes' : 'no',
					'quantity_min'     => $item['min'],
					'quantity_max'     => isset( $item['max'] ) ? $item['max'] : '',
					'quantity_default' => $item['min'],
				),
			);
		}
		$options = json_decode( '${ JSON.stringify( options ) }', true );
		$bundle  = new WC_Product_Bundle();
		$bundle->set_name( '${ slug }' ); $bundle->set_slug( '${ slug }' );
		$bundle->set_regular_price( '20' ); $bundle->set_virtual( true ); $bundle->set_status( 'publish' );
		if ( isset( $options['minSize'] ) ) { $bundle->set_min_bundle_size( $options['minSize'] ); }
		if ( isset( $options['maxSize'] ) ) { $bundle->set_max_bundle_size( $options['maxSize'] ); }
		$bundle->set_bundled_data_items( $data );
		echo $bundle->save();
	`;
	return Number( await wpEval( php ) );
}

/** Bundle structure of the most recent generated order. */
async function latestOrderBundleLines() {
	return JSON.parse(
		await wpEval(
			"$o = wc_get_orders( array( 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'meta_key' => '_happy_order_generator_order', 'meta_value' => 1 ) )[0];" +
				"$lines = array( 'containers' => array(), 'bundled' => array(), 'other' => array() );" +
				"foreach ( $o->get_items() as $item ) {" +
				"  $slug = get_post_field( 'post_name', $item->get_product_id() );" +
				"  if ( wc_pb_is_bundle_container_order_item( $item ) ) { $lines['containers'][] = $item->get_product_id(); }" +
				"  elseif ( wc_pb_is_bundled_order_item( $item ) ) {" +
				"    $line = array( 'slug' => $slug, 'qty' => $item->get_quantity() );" +
				"    if ( $item->get_variation_id() ) { $line['variation'] = array( 'parent' => wp_get_post_parent_id( $item->get_variation_id() ), 'size' => $item->get_meta( 'size' ), 'color' => $item->get_meta( 'pa_hogcolor' ) ); }" +
				"    $lines['bundled'][] = $line;" +
				"  } else { $lines['other'][] = $slug; }" +
				"}" +
				"echo wp_json_encode( $lines );"
		)
	);
}

/** Runs one generator batch and expects exactly one new generated order. */
async function generateOneOrder( label ) {
	const before = await generatedOrderCount();
	await runGeneratorBatch( 1 );
	expect( await generatedOrderCount(), label ).toBe( before + 1 );
	return latestOrderBundleLines();
}

test.describe( 'Product Bundles', () => {
	test.beforeAll( async () => {
		const active = await wpEval( "echo class_exists( 'WC_Product_Bundle' ) ? 'yes' : 'no';" );
		test.skip( active !== 'yes', 'Product Bundles is not active' );
		await clearScheduledActions();
	} );

	test( 'a bundle with a required item reaches checkout with its bundled item', async () => {
		const bundleId = await ensureBundle( 'hog-e2e-bundle-required', [ { slug: 'hog-e2e-bundled-a', optional: false, min: 1, max: 1 } ] );
		await setSettings( { orders_per_hour: 6, products: [ String( bundleId ) ] } );

		const before = await generatedOrderCount();
		await runGeneratorBatch( 1 );
		expect( await generatedOrderCount() ).toBe( before + 1 );

		const lines = await latestOrderBundleLines();
		expect( lines.containers ).toEqual( [ bundleId ] );
		expect( lines.bundled ).toEqual( [ { slug: 'hog-e2e-bundled-a', qty: 1 } ] );
	} );

	test( 'optional items and quantity ranges produce valid configurations', async () => {
		const bundleId = await ensureBundle( 'hog-e2e-bundle-optional', [
			{ slug: 'hog-e2e-bundled-a', optional: false, min: 1, max: 3 },
			{ slug: 'hog-e2e-bundled-b', optional: true, min: 1, max: 1 },
		] );
		// Guard against the fixture silently not applying.
		const items = JSON.parse( await wpEval( `$out = array(); foreach ( wc_get_product( ${ bundleId } )->get_bundled_items() as $i ) { $out[] = array( $i->is_optional(), (int) $i->get_quantity( 'max' ) ); } echo wp_json_encode( $out );` ) );
		expect( items ).toEqual( [ [ false, 3 ], [ true, 1 ] ] );

		await setSettings( { orders_per_hour: 6, products: [ String( bundleId ) ] } );

		// Several orders, so both choices for the optional item are likely to come up.
		for ( let i = 0; i < 4; i++ ) {
			const before = await generatedOrderCount();
			await runGeneratorBatch( 1 );
			expect( await generatedOrderCount(), `order ${ i + 1 }` ).toBe( before + 1 );

			const lines = await latestOrderBundleLines();
			expect( lines.containers ).toEqual( [ bundleId ] );

			const required = lines.bundled.find( ( line ) => line.slug === 'hog-e2e-bundled-a' );
			expect( required, 'the required item is always included' ).toBeTruthy();
			expect( required.qty ).toBeGreaterThanOrEqual( 1 );
			expect( required.qty ).toBeLessThanOrEqual( 3 );

			const optional = lines.bundled.filter( ( line ) => line.slug === 'hog-e2e-bundled-b' );
			expect( optional.length ).toBeLessThanOrEqual( 1 );
		}
	} );

	test( 'variable bundled items get an in-stock variation with every attribute set', async () => {
		const bundleId = await ensureBundle( 'hog-e2e-bundle-variable', [ { slug: 'hog-e2e-bundled-variable', optional: false, min: 1, max: 1, variable: true } ] );
		const variableId = await productIdBySlug( 'hog-e2e-bundled-variable' );
		await setSettings( { orders_per_hour: 6, products: [ String( bundleId ) ] } );

		// Several orders, so both variations are likely to come up.
		for ( let i = 0; i < 4; i++ ) {
			const lines = await generateOneOrder( `order ${ i + 1 }` );
			expect( lines.containers ).toEqual( [ bundleId ] );
			expect( lines.bundled ).toHaveLength( 1 );

			const { variation } = lines.bundled[ 0 ];
			expect( variation, 'the bundled line is a variation' ).toBeTruthy();
			expect( variation.parent ).toBe( variableId );
			// Size M is only sold in red; size S takes any colour.
			expect( [ 'S', 'M' ] ).toContain( variation.size );
			expect( variation.size === 'M' ? [ 'red' ] : [ 'red', 'blue' ] ).toContain( variation.color );
		}
	} );

	test( 'bundle min/max item limits are respected', async () => {
		const items = [ 'a', 'b', 'c' ].map( ( x ) => ( { slug: `hog-e2e-bundled-${ x }`, optional: true, min: 1, max: 2 } ) );
		const bundleId = await ensureBundle( 'hog-e2e-bundle-limits', items, { minSize: 3, maxSize: 4 } );
		await setSettings( { orders_per_hour: 6, products: [ String( bundleId ) ] } );

		for ( let i = 0; i < 4; i++ ) {
			const lines = await generateOneOrder( `order ${ i + 1 }` );
			expect( lines.containers ).toEqual( [ bundleId ] );
			const total = lines.bundled.reduce( ( sum, line ) => sum + line.qty, 0 );
			expect( total ).toBeGreaterThanOrEqual( 3 );
			expect( total ).toBeLessThanOrEqual( 4 );
		}
	} );

	test( 'items with no maximum quantity get a quantity from a small range', async () => {
		const bundleId = await ensureBundle( 'hog-e2e-bundle-unlimited', [ { slug: 'hog-e2e-bundled-a', optional: false, min: 1, max: null } ] );
		await setSettings( { orders_per_hour: 6, products: [ String( bundleId ) ] } );

		for ( let i = 0; i < 3; i++ ) {
			const lines = await generateOneOrder( `order ${ i + 1 }` );
			expect( lines.bundled ).toHaveLength( 1 );
			expect( lines.bundled[ 0 ].qty ).toBeGreaterThanOrEqual( 1 );
			expect( lines.bundled[ 0 ].qty ).toBeLessThanOrEqual( 3 );
		}
	} );

	test( 'out-of-stock optional items are left out', async () => {
		const bundleId = await ensureBundle( 'hog-e2e-bundle-oos-optional', [
			{ slug: 'hog-e2e-bundled-a', optional: false, min: 1, max: 1 },
			{ slug: 'hog-e2e-bundled-oos', optional: true, min: 1, max: 1, outOfStock: true },
		] );
		await setSettings( { orders_per_hour: 6, products: [ String( bundleId ) ] } );

		for ( let i = 0; i < 3; i++ ) {
			const lines = await generateOneOrder( `order ${ i + 1 }` );
			expect( lines.bundled.map( ( line ) => line.slug ) ).toEqual( [ 'hog-e2e-bundled-a' ] );
		}
	} );

	test( 'a bundle with an out-of-stock required item is skipped, and the rest of the order goes through', async () => {
		const bundleId = await ensureBundle( 'hog-e2e-bundle-oos-required', [ { slug: 'hog-e2e-bundled-oos', optional: false, min: 1, max: 1, outOfStock: true } ] );
		const productId = await productIdBySlug( 'hog-e2e-product' );
		await setSettings( { orders_per_hour: 6, min_order_products: 2, max_order_products: 2, products: [ String( bundleId ), String( productId ) ] } );

		const lines = await generateOneOrder( 'order' );
		expect( lines.containers ).toEqual( [] );
		expect( lines.other ).toEqual( [ 'hog-e2e-product' ] );
	} );

	test( 'a subscription inside a bundle is detected, so the order can use a gateway that renews it', async () => {
		const active = await wpEval( "echo class_exists( 'WC_Subscriptions' ) ? 'yes' : 'no';" );
		test.skip( active !== 'yes', 'Woo Subscriptions is not active' );

		const bundleId = await ensureBundle( 'hog-e2e-bundle-subscription', [
			{ slug: 'hog-e2e-bundled-a', optional: false, min: 1, max: 1 },
			{ slug: 'hog-e2e-bundled-subscription', optional: true, min: 1, max: 1, subscription: true },
		] );
		const detected = JSON.parse(
			await wpEval( `
				$items = array();
				foreach ( wc_get_product( ${ bundleId } )->get_bundled_items() as $id => $item ) {
					$items[ $item->product->get_slug() ] = $id;
				}
				$check = new ReflectionMethod( Happy_Order_Generator\\Generator::class, 'check_cart_for_subscription' );
				$check->setAccessible( true );
				$cart = function ( $slugs ) use ( $items ) {
					$rows = array();
					foreach ( $slugs as $slug ) { $rows[] = array( 'bundled_item_id' => $items[ $slug ], 'quantity' => 1 ); }
					return array( array( 'id' => ${ bundleId }, 'quantity' => 1, 'bundle_configuration' => $rows ) );
				};
				$generator = new Happy_Order_Generator\\Generator();
				echo wp_json_encode( array(
					'with'    => $check->invoke( $generator, $cart( array( 'hog-e2e-bundled-a', 'hog-e2e-bundled-subscription' ) ) ),
					'without' => $check->invoke( $generator, $cart( array( 'hog-e2e-bundled-a' ) ) ),
				) );
			` )
		);
		expect( detected ).toEqual( { with: true, without: false } );
	} );

	test( 'bundles picked from the random product pool reach checkout with their bundled items', async () => {
		// No products selected, so every fixture above is in the pool, including
		// bundles that can't be bought and subscriptions with no gateway to renew them.
		await setSettings( { orders_per_hour: 6, products: [] } );
		await clearDebugLog();

		const attempts = JSON.parse(
			await wpEval( `
				$picked = array();
				add_filter( 'hog_get_cart_products', function ( $cart, $products ) use ( &$picked ) {
					$picked = array_map( function ( $p ) { return $p->get_type(); }, $products );
					return $cart;
				}, 10, 2 );
				$latest = function () {
					$ids = wc_get_orders( array( 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'return' => 'ids', 'meta_key' => '_happy_order_generator_order', 'meta_value' => 1 ) );
					return $ids ? $ids[0] : 0;
				};
				$attempts = array();
				// Generate directly rather than through a batch, which waits 5 seconds per order.
				for ( $i = 0; $i < 20; $i++ ) {
					$picked  = array();
					$before  = $latest();
					$error   = '';
					try { ( new Happy_Order_Generator\\Generator() )->generate_order(); } catch ( Throwable $e ) { $error = $e->getMessage(); }
					$attempt = array( 'picked' => $picked, 'error' => $error, 'containers' => 0, 'bundled' => 0 );
					if ( $latest() !== $before ) {
						foreach ( wc_get_order( $latest() )->get_items() as $item ) {
							$attempt['containers'] += wc_pb_is_bundle_container_order_item( $item ) ? 1 : 0;
							$attempt['bundled']    += wc_pb_is_bundled_order_item( $item ) ? 1 : 0;
						}
					}
					$attempts[] = $attempt;
					if ( $attempt['containers'] > 0 ) { break; }
				}
				echo wp_json_encode( $attempts );
			` )
		);

		const summary = JSON.stringify( attempts );
		expect( attempts.filter( ( a ) => a.error ), summary ).toEqual( [] );
		const withBundle = attempts.find( ( a ) => a.containers > 0 );
		expect( withBundle, `no order with a bundle in ${ attempts.length } attempts: ${ summary }` ).toBeTruthy();
		expect( withBundle.picked ).toContain( 'bundle' );
		expect( withBundle.bundled ).toBeGreaterThanOrEqual( withBundle.containers );

		const issues = pluginPhpIssues( await readDebugLog() );
		expect( issues, issues.join( '\n' ) ).toEqual( [] );
	} );
} );

/**
 * Bundles selected in the settings while Product Bundles is inactive. Runs
 * with or without Product Bundles installed: it's deactivated for these tests
 * and reactivated afterwards.
 */
test.describe( 'Bundles without Product Bundles', () => {
	const PB = 'woocommerce-product-bundles';
	let wasActive = false;

	test.beforeAll( async () => {
		wasActive = ( await wpEval( "echo class_exists( 'WC_Product_Bundle' ) ? 'yes' : 'no';" ) ) === 'yes';
		if ( wasActive ) {
			await wp( [ 'plugin', 'deactivate', PB ] );
		}
	} );

	test.afterAll( async () => {
		if ( wasActive ) {
			await wp( [ 'plugin', 'activate', PB ] );
		}
	} );

	test( 'the settings page names selected bundles that can\'t be used', async ( { page } ) => {
		// A product stored as a bundle, as Product Bundles would have left it.
		const bundleId = Number(
			await wpEval( `
				$p = get_page_by_path( 'hog-e2e-orphan-bundle', OBJECT, 'product' );
				$id = $p ? $p->ID : wp_insert_post( array( 'post_type' => 'product', 'post_status' => 'publish', 'post_title' => 'HOG orphan bundle', 'post_name' => 'hog-e2e-orphan-bundle' ) );
				wp_set_object_terms( $id, 'bundle', 'product_type' );
				echo $id;
			` )
		);
		const productId = await productIdBySlug( 'hog-e2e-product' );

		await setSettings( { orders_per_hour: 6, products: [ String( bundleId ), String( productId ) ] } );
		await loginAsAdmin( page );
		await page.goto( SETTINGS_URL );
		await expect( page.getByText( "Product Bundles isn't active, so this selected product can't be added to generated orders: HOG orphan bundle" ) ).toBeVisible();

		await setSettings( { orders_per_hour: 6, products: [ String( productId ) ] } );
		await page.goto( SETTINGS_URL );
		await expect( page.getByText( "Product Bundles isn't active" ) ).toHaveCount( 0 );
	} );
} );
