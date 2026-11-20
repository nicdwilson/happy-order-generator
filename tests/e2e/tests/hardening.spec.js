/**
 * Hardening: generated customers are marked and isolated, store staff are
 * told while generation is running, and nothing runs in production.
 */
const { test, expect } = require( '@playwright/test' );
const {
	wp,
	wpEval,
	setSettings,
	generatedOrderCount,
	runGeneratorBatch,
	productIdBySlug,
	pendingActionCount,
	clearScheduledActions,
	login,
	loginAsAdmin,
	SETTINGS_URL,
} = require( './utils/wp' );

const RUNNING_NOTICE = '.hog-running-notice';
const PRODUCTION_MU_PLUGIN = 'hog-e2e-production.php';

/** The customer on the most recent generated order. */
async function latestGeneratedCustomer() {
	return JSON.parse(
		await wpEval(
			"$o = wc_get_orders( array( 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'meta_key' => '_happy_order_generator_order', 'meta_value' => 1 ) )[0];" +
				"$u = get_userdata( $o->get_customer_id() );" +
				"echo wp_json_encode( array( 'id' => $o->get_customer_id(), 'roles' => $u ? array_values( $u->roles ) : array(), 'marked' => get_user_meta( $o->get_customer_id(), '_happy_order_generator_customer', true ) ) );"
		)
	);
}

/** Create a user if missing; returns its ID. */
async function ensureUser( login, role ) {
	const existing = await wpEval( `$u = get_user_by( 'login', '${ login }' ); echo $u ? $u->ID : 0;` );
	if ( Number( existing ) ) {
		return Number( existing );
	}
	return Number( await wp( [ 'user', 'create', login, `${ login }@example.com`, `--role=${ role }`, '--user_pass=password', '--porcelain' ] ) );
}

test.describe( 'Generated customers', () => {
	test.beforeAll( async () => {
		const productId = await productIdBySlug( 'hog-e2e-product' );
		await setSettings( { enabled: 'yes', orders_per_hour: 6, create_users: true, products: [ String( productId ) ] } );
		await clearScheduledActions();
	} );

	test( 'are marked, have the no-capability role, and own their orders', async () => {
		await runGeneratorBatch( 1 );
		const customer = await latestGeneratedCustomer();

		expect( customer.id ).toBeGreaterThan( 0 );
		expect( customer.marked ).toBe( '1' );
		expect( customer.roles ).toEqual( [ 'hog_generated_customer' ] );
		expect( await wpEval( "echo count( get_role( 'hog_generated_customer' )->capabilities );" ) ).toBe( '0' );
	} );

	test( 'real customers are never reused for generated orders', async () => {
		const shopperId = await ensureUser( 'hog-e2e-shopper', 'customer' );
		const before = await generatedOrderCount();

		await runGeneratorBatch( 4 );

		expect( await generatedOrderCount() ).toBe( before + 4 );
		const shopperOrders = await wpEval( `echo count( wc_get_orders( array( 'limit' => -1, 'return' => 'ids', 'customer_id' => ${ shopperId } ) ) );` );
		expect( shopperOrders ).toBe( '0' );
	} );
} );

test.describe( 'Running notice', () => {
	test.beforeAll( async () => {
		await setSettings( { enabled: 'yes', orders_per_hour: 5 } );
		await clearScheduledActions();
		await wp( [ 'user', 'meta', 'delete', '1', '_hog_running_notice_dismissed' ] ).catch( () => {} );
	} );

	test( 'shows the rate to administrators on the dashboard', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/' );
		expect( await pendingActionCount() ).toBe( 1 );
		await expect( page.locator( RUNNING_NOTICE ) ).toContainText( 'at 5 orders an hour' );
	} );

	test( 'stays dismissed for the rest of the login, and returns on the next login', async ( { browser } ) => {
		const first = await browser.newContext();
		const page = await first.newPage();
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/' );

		const dismissed = page.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) && r.request().method() === 'POST' );
		await page.locator( `${ RUNNING_NOTICE } .notice-dismiss` ).click();
		expect( ( await dismissed ).ok() ).toBe( true );

		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
		await expect( page.locator( RUNNING_NOTICE ) ).toHaveCount( 0 );
		await page.goto( SETTINGS_URL );
		await expect( page.locator( RUNNING_NOTICE ) ).toHaveCount( 0 );
		await first.close();

		const second = await browser.newContext();
		const again = await second.newPage();
		await loginAsAdmin( again );
		await again.goto( '/wp-admin/' );
		await expect( again.locator( RUNNING_NOTICE ) ).toBeVisible();
		await second.close();
	} );

	test( 'is shown to shop managers', async ( { page } ) => {
		await ensureUser( 'hog-e2e-manager', 'shop_manager' );
		await login( page, 'hog-e2e-manager', 'password' );
		await page.goto( '/wp-admin/' );
		await expect( page.locator( RUNNING_NOTICE ) ).toContainText( 'at 5 orders an hour' );
	} );

	test( 'is hidden while generation is paused', async ( { page } ) => {
		await setSettings( { enabled: 'no', orders_per_hour: 5 } );
		await loginAsAdmin( page );
		await page.goto( '/wp-admin/' );
		await expect( page.locator( '#wpbody-content' ) ).toBeVisible();
		await expect( page.locator( RUNNING_NOTICE ) ).toHaveCount( 0 );
	} );
} );

test.describe( 'Production sites', () => {
	test.beforeAll( async () => {
		const productId = await productIdBySlug( 'hog-e2e-product' );
		await setSettings( { enabled: 'yes', orders_per_hour: 6, products: [ String( productId ) ] } );
		// Simulate production without restarting the site: allow no environment types.
		await wpEval(
			`file_put_contents( WPMU_PLUGIN_DIR . '/${ PRODUCTION_MU_PLUGIN }', "<?php add_filter( 'hog_allowed_environment_types', '__return_empty_array' );" );`
		);
	} );

	test.afterAll( async () => {
		await wpEval( `@unlink( WPMU_PLUGIN_DIR . '/${ PRODUCTION_MU_PLUGIN }' );` );
	} );

	test( 'settings page explains generation is blocked, and nothing is scheduled', async ( { page } ) => {
		await loginAsAdmin( page );
		await page.goto( SETTINGS_URL );
		await expect( page.getByText( 'Order generation is blocked' ) ).toBeVisible();
		await expect( page.locator( RUNNING_NOTICE ) ).toHaveCount( 0 );
		expect( await pendingActionCount() ).toBe( 0 );
	} );

	test( 'a queued batch creates no orders', async () => {
		const before = await generatedOrderCount();
		await runGeneratorBatch( 1 );
		expect( await generatedOrderCount() ).toBe( before );
	} );
} );
