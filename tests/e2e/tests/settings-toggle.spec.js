/**
 * Enable/Disable toggle on WooCommerce > Settings > Order Generator.
 */
const { test, expect } = require( '@playwright/test' );
const {
	clearDebugLog,
	readDebugLog,
	pluginPhpIssues,
	setSettings,
	setRawSettings,
	getSettings,
	pendingActionCount,
	clearScheduledActions,
	generatedOrderCount,
	runGeneratorBatch,
	loginAsAdmin,
	DEFAULT_SETTINGS,
	SETTINGS_URL,
} = require( './utils/wp' );

// Tests share one site and run in file order (workers: 1). A failure does not
// skip the rest, matching how QIT reports every activation step.

const PAUSED = 'Order generation is paused';

test.describe( 'Enable/Disable toggle', () => {
	test.beforeAll( async () => {
		await clearDebugLog();
	} );

	test.beforeEach( async () => {
		await clearScheduledActions();
	} );

	test( 'is the first setting and is on for sites upgrading without it', async ( { page } ) => {
		// Settings saved before the toggle existed have no `enabled` key.
		const { enabled, ...legacy } = DEFAULT_SETTINGS;
		await setRawSettings( legacy );

		await loginAsAdmin( page );
		await page.goto( SETTINGS_URL );

		const firstField = page.locator( 'form#mainform table.form-table' ).first().locator( 'input, select' ).first();
		await expect( firstField ).toHaveAttribute( 'id', 'hog_enabled' );
		await expect( page.locator( '#hog_enabled' ) ).toBeChecked();
		await expect( page.locator( '#mainform' ) ).not.toContainText( PAUSED );
		await expect( page.locator( '#mainform' ) ).not.toContainText( 'todo' );

		// Loading the page initialises the scheduler, which should be running.
		expect( await pendingActionCount() ).toBeGreaterThan( 0 );
	} );

	test( 'unticking it pauses generation and keeps other settings', async ( { page } ) => {
		await setSettings( { enabled: 'yes', orders_per_hour: 6 } );

		await loginAsAdmin( page );
		await page.goto( SETTINGS_URL );
		await page.locator( '#hog_enabled' ).uncheck();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expect( page.locator( '#mainform' ) ).toContainText( PAUSED );
		await expect( page.locator( '#hog_enabled' ) ).not.toBeChecked();

		const saved = await getSettings();
		expect( saved.enabled ).toBe( 'no' );
		expect( Number( saved.orders_per_hour ) ).toBe( 6 );
		expect( await pendingActionCount() ).toBe( 0 );
	} );

	test( 'ticking it again resumes generation', async ( { page } ) => {
		await setSettings( { enabled: 'no', orders_per_hour: 6 } );

		await loginAsAdmin( page );
		await page.goto( SETTINGS_URL );
		await expect( page.locator( '#mainform' ) ).toContainText( PAUSED );
		await page.locator( '#hog_enabled' ).check();
		await page.getByRole( 'button', { name: 'Save changes' } ).click();

		await expect( page.locator( '#mainform' ) ).not.toContainText( PAUSED );
		expect( ( await getSettings() ).enabled ).toBe( 'yes' );
		expect( await pendingActionCount() ).toBeGreaterThan( 0 );
	} );

	test( 'an action that runs while paused creates no orders', async () => {
		await setSettings( { enabled: 'no' } );
		const before = await generatedOrderCount();
		await runGeneratorBatch( 1 );
		expect( await generatedOrderCount() ).toBe( before );
	} );

	test( 'saving settings raised no PHP notices, warnings or errors', async () => {
		const issues = pluginPhpIssues( await readDebugLog() );
		expect( issues, issues.join( '\n' ) ).toEqual( [] );
	} );
} );
