/**
 * WooCommerce emails for generated customers go to one address, or nowhere,
 * instead of the addresses on their accounts.
 *
 * Outgoing mail is recorded by the hog-e2e-mail-log mu-plugin (wp-env only),
 * so these tests skip without it.
 */
const { test, expect } = require( '@playwright/test' );
const { wpEval, setSettings, getSettings, runGeneratorBatch, clearScheduledActions, productIdBySlug, loginAsAdmin, SETTINGS_URL } = require( './utils/wp' );

/** Emails recorded since the log was last cleared: [{ to, subject }]. */
async function sentMail() {
	return JSON.parse( await wpEval( "echo wp_json_encode( get_option( 'hog_e2e_mail_log', array() ) );" ) );
}

async function clearMail() {
	await wpEval( "delete_option( 'hog_e2e_mail_log' );" );
}

/** Billing email of the most recent generated order. */
async function latestGeneratedOrderEmail() {
	return wpEval(
		"echo wc_get_orders( array( 'limit' => 1, 'orderby' => 'id', 'order' => 'DESC', 'meta_key' => '_happy_order_generator_order', 'meta_value' => 1 ) )[0]->get_billing_email();"
	);
}

/** Generates one order and returns the recipients of every email it sent. */
async function generateAndCollectRecipients( settings ) {
	const productId = await productIdBySlug( 'hog-e2e-product' );
	await setSettings( { orders_per_hour: 6, products: [ String( productId ) ], ...settings } );
	await clearMail();
	await runGeneratorBatch( 1 );
	return ( await sentMail() ).map( ( mail ) => mail.to );
}

/**
 * Sends the customer "processing order" email for a new guest order with the
 * given billing email and customer, and returns who it went to.
 */
async function processingEmailRecipients( billingEmail, customerId = 0 ) {
	await clearMail();
	await wpEval( `
		$order = wc_create_order( array( 'customer_id' => ${ Number( customerId ) } ) );
		$order->set_billing_email( '${ billingEmail }' );
		$order->save();
		WC()->mailer()->get_emails()['WC_Email_Customer_Processing_Order']->trigger( $order->get_id(), $order );
		$order->delete( true );
	` );
	return ( await sentMail() ).map( ( mail ) => mail.to );
}

/** ID and email of a generated customer. */
async function generatedCustomer() {
	return JSON.parse(
		await wpEval( `
			$users = get_users( array( 'meta_key' => '_happy_order_generator_customer', 'meta_value' => 1, 'number' => 1 ) );
			echo wp_json_encode( array( 'id' => $users[0]->ID, 'email' => $users[0]->user_email, 'login' => $users[0]->user_login ) );
		` )
	);
}

test.describe( 'Customer emails', () => {
	let defaultAddress;

	test.beforeAll( async () => {
		const logging = await wpEval( "echo defined( 'HOG_E2E_MAIL_LOG' ) ? 'yes' : 'no';" );
		test.skip( logging !== 'yes', 'The hog-e2e-mail-log mu-plugin is not loaded' );
		await clearScheduledActions();
		defaultAddress = await wpEval( 'echo Happy_Order_Generator\\Customer_Emails::get_default_address();' );
	} );

	test.afterAll( async () => {
		await setSettings();
	} );

	test( 'the default address is hog-customer@ the site\'s domain, or example.com for hosts like localhost', async () => {
		const addresses = JSON.parse(
			await wpEval( `
				$home = function () { return 'https://www.shop.example.org'; };
				add_filter( 'home_url', $home );
				$with_domain = Happy_Order_Generator\\Customer_Emails::get_default_address();
				remove_filter( 'home_url', $home );
				$local = function () { return 'http://localhost:8890'; };
				add_filter( 'home_url', $local );
				$localhost = Happy_Order_Generator\\Customer_Emails::get_default_address();
				remove_filter( 'home_url', $local );
				echo wp_json_encode( array( $with_domain, $localhost ) );
			` )
		);
		expect( addresses ).toEqual( [ 'hog-customer@shop.example.org', 'hog-customer@example.com' ] );
	} );

	test( 'by default, a generated order\'s emails go to the default address and none to the customer', async () => {
		const recipients = await generateAndCollectRecipients( {} );
		const customerEmail = await latestGeneratedOrderEmail();

		expect( recipients ).toContain( defaultAddress );
		expect( recipients ).not.toContain( customerEmail );
	} );

	test( 'emails go to the configured address when one is set', async () => {
		const recipients = await generateAndCollectRecipients( { customer_email_address: 'qa-inbox@example.org' } );

		expect( recipients ).toContain( 'qa-inbox@example.org' );
		expect( recipients ).not.toContain( defaultAddress );
		expect( recipients ).not.toContain( await latestGeneratedOrderEmail() );
	} );

	test( 'no customer emails are sent when they are blocked', async () => {
		const recipients = await generateAndCollectRecipients( { customer_emails: 'block', customer_email_address: 'qa-inbox@example.org' } );

		expect( recipients ).not.toContain( 'qa-inbox@example.org' );
		expect( recipients ).not.toContain( defaultAddress );
		expect( recipients ).not.toContain( await latestGeneratedOrderEmail() );
	} );

	test( 'creating a customer sends no email to the made-up address it starts with', async () => {
		await setSettings( { customer_email_convention: 'customer_id_staging' } );
		await clearMail();
		const userId = Number( await wpEval( 'echo ( new Happy_Order_Generator\\Customer() )->create_user();' ) );
		expect( userId ).toBeGreaterThan( 0 );
		expect( await sentMail() ).toEqual( [] );
	} );

	test( 'real customers still get their emails, in either mode', async () => {
		for ( const mode of [ 'redirect', 'block' ] ) {
			await setSettings( { customer_emails: mode } );
			expect( await processingEmailRecipients( 'real.customer@example.org' ), mode ).toEqual( [ 'real.customer@example.org' ] );
		}
	} );

	test( 'an email is caught by its order\'s customer, its user, or a generated customer\'s address', async () => {
		await setSettings( { customer_emails: 'redirect' } );
		const customer = await generatedCustomer();

		// The order's customer is generated, whatever its billing email says.
		expect( await processingEmailRecipients( 'someone@example.org', customer.id ) ).toEqual( [ defaultAddress ] );

		// A guest order billed to a generated customer's address.
		expect( await processingEmailRecipients( customer.email ) ).toEqual( [ defaultAddress ] );

		// An email about a user rather than an order: password reset.
		await clearMail();
		await wpEval( `
			$user = get_user_by( 'id', ${ customer.id } );
			WC()->mailer()->get_emails()['WC_Email_Customer_Reset_Password']->trigger( $user->user_login, get_password_reset_key( $user ) );
		` );
		expect( ( await sentMail() ).map( ( m ) => m.to ) ).toEqual( [ defaultAddress ] );
	} );

	test( 'the settings save the mode and address, and reject an invalid address', async ( { page } ) => {
		await setSettings();
		await loginAsAdmin( page );
		await page.goto( SETTINGS_URL );

		await expect( page.locator( '#hog_customer_emails' ) ).toHaveValue( 'redirect' );
		await expect( page.locator( '#hog_customer_email_address' ) ).toHaveAttribute( 'placeholder', defaultAddress );

		await page.locator( '#hog_customer_emails' ).selectOption( 'block' );
		await page.locator( '#hog_customer_email_address' ).fill( 'qa-inbox@example.org' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.locator( '#hog_customer_emails' ) ).toHaveValue( 'block' );
		let saved = await getSettings();
		expect( [ saved.customer_emails, saved.customer_email_address ] ).toEqual( [ 'block', 'qa-inbox@example.org' ] );

		// The field is type="email", so the browser would stop this; the server must too.
		await page.locator( '#hog_customer_email_address' ).evaluate( ( input ) => ( input.type = 'text' ) );
		await page.locator( '#hog_customer_email_address' ).fill( 'not an address' );
		await page.getByRole( 'button', { name: 'Save changes' } ).click();
		await expect( page.locator( '#wpbody-content' ) ).toContainText( `isn't a valid email address, so customer emails will go to ${ defaultAddress }` );
		saved = await getSettings();
		expect( saved.customer_email_address ).toBe( '' );
	} );
} );
