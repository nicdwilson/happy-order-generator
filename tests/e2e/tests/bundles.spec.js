/**
 * Product Bundles: generated orders can contain bundles.
 *
 * Product Bundles is a licensed extension, so these tests skip when it
 * isn't active. To run them locally, mount it with .wp-env.override.json
 * (see tests/README.md).
 */
const { test, expect } = require( '@playwright/test' );
const { wpEval, setSettings, generatedOrderCount, runGeneratorBatch, clearScheduledActions } = require( './utils/wp' );

/**
 * Creates a published bundle of virtual products, replacing any earlier one.
 *
 * @param {string} slug  Bundle slug.
 * @param {Array}  items Bundled items: { slug, optional, min, max }.
 * @return {Promise<number>} Bundle product ID.
 */
async function ensureBundle( slug, items ) {
	const php = `
		// Recreate each run so fixture changes always apply.
		$existing = get_page_by_path( '${ slug }', OBJECT, 'product' );
		if ( $existing ) { wc_get_product( $existing->ID )->delete( true ); }
		$simple = function ( $slug ) {
			$p = get_page_by_path( $slug, OBJECT, 'product' );
			if ( $p ) { return $p->ID; }
			$x = new WC_Product_Simple();
			$x->set_name( $slug ); $x->set_slug( $slug ); $x->set_regular_price( '5' );
			$x->set_virtual( true ); $x->set_status( 'publish' );
			return $x->save();
		};
		$data = array();
		foreach ( json_decode( '${ JSON.stringify( items ) }', true ) as $item ) {
			$data[] = array(
				'product_id' => $simple( $item['slug'] ),
				'meta_data'  => array(
					'optional'         => $item['optional'] ? 'yes' : 'no',
					'quantity_min'     => $item['min'],
					'quantity_max'     => $item['max'],
					'quantity_default' => $item['min'],
				),
			);
		}
		$bundle = new WC_Product_Bundle();
		$bundle->set_name( '${ slug }' ); $bundle->set_slug( '${ slug }' );
		$bundle->set_regular_price( '20' ); $bundle->set_virtual( true ); $bundle->set_status( 'publish' );
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
				"$lines = array( 'containers' => array(), 'bundled' => array() );" +
				"foreach ( $o->get_items() as $item ) {" +
				"  if ( wc_pb_is_bundle_container_order_item( $item ) ) { $lines['containers'][] = $item->get_product_id(); }" +
				"  elseif ( wc_pb_is_bundled_order_item( $item ) ) { $lines['bundled'][] = array( 'slug' => get_post_field( 'post_name', $item->get_product_id() ), 'qty' => $item->get_quantity() ); }" +
				"}" +
				"echo wp_json_encode( $lines );"
		)
	);
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
} );
