<?php
/**
 * Class WC_Settings_Order_Generator_Settings extends WC_Settings_Page
 * Provides settings for Happy Order Generator
 */

namespace Happy_Order_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

use WC_Settings_Page;

/**
 * WC_Admin_Settings_Order_Simulator
 */
class WC_Settings_Order_Generator_Settings extends WC_Settings_Page {

	/**
	 * Constructor.
	 */
	public function __construct() {

		$this->id    = 'order_generator';
		$this->label = __( 'Order Generator', 'happy-order-generator' );

		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_settings_page' ), 20 );
		add_action( 'woocommerce_settings_' . $this->id, array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_' . $this->id, array( $this, 'save' ) );
	}

	/**
	 * Get settings array
	 *
	 * @return array
	 */
	public function get_settings(): array {

		$settings    = Order_Generator::get_settings();
		$product_ids = ( isset( $settings['products'] ) ) ? maybe_unserialize( $settings['products'] ) : array();

		$json_ids = array();

		foreach ( $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( is_object( $product ) ) {
				$json_ids[ $product_id ] = wp_kses_post( html_entity_decode( $product->get_formatted_name(), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
			}
		}

		$data_selected = esc_attr( json_encode( $json_ids ) );

		$message = '';
		if ( ! Order_Generator::is_allowed_environment() ) {
			$message = '<strong>' . sprintf(
				/* translators: %s: the site's environment type, e.g. production */
				esc_html__( 'Order generation is blocked because this site\'s environment type is "%s". It only runs on local, development or staging sites: set WP_ENVIRONMENT_TYPE in wp-config.php.', 'happy-order-generator' ),
				esc_html( wp_get_environment_type() )
			) . '</strong>';
		} elseif ( ! Order_Generator::is_enabled() ) {
			$message = '<strong>' . esc_html__( 'Order generation is paused. No orders will be created until it is enabled again.', 'happy-order-generator' ) . '</strong>';
		} elseif ( (int) ( $settings['orders_per_hour'] ?? 0 ) < 1 ) {
			$message = '<strong>' . esc_html__( 'Orders per Hour is 0, so no orders will be created. Set it to 1 or more to start generating.', 'happy-order-generator' ) . '</strong>';
		}

		$unusable_bundles = $this->get_unusable_bundle_names( $product_ids );
		if ( ! empty( $unusable_bundles ) ) {
			$message .= ( '' === $message ? '' : '<br><br>' ) . '<strong>' . esc_html(
				sprintf(
					/* translators: %s: comma-separated product names */
					_n(
						'Product Bundles isn\'t active, so this selected product can\'t be added to generated orders: %s',
						'Product Bundles isn\'t active, so these selected products can\'t be added to generated orders: %s',
						count( $unusable_bundles ),
						'happy-order-generator'
					),
					implode( ', ', $unusable_bundles )
				)
			) . '</strong>';
		}

		$country_ids = ( isset( $settings['customer_locales'] ) ) ? maybe_unserialize( $settings['customer_locales'] ) : array();

		$settings_array = array(
			array(
				'title' => __( 'Settings', 'happy-order-generator' ),
				'type'  => 'title',
				'desc'  => $message,
				'id'    => 'hog_settings_start'
			),
			array(
				'title' => __( 'Enable/Disable', 'happy-order-generator' ),
				'desc'  => __( 'Enable order generation', 'happy-order-generator' ),
				'id'    => 'hog_enabled',
				'type'  => 'checkbox',
				'value' => ( $settings['enabled'] ?? 'yes' ) === 'no' ? 'no' : 'yes',
			),
			array(
				'title'    => __( 'Orders per Hour', 'happy-order-generator' ),
				'desc'     => __( 'The maximum number of orders to generate per hour.', 'happy-order-generator' ),
				'id'       => 'hog_orders_per_hour',
				'css'      => 'width:100px;',
				'value'    => ( isset( $settings['orders_per_hour'] ) ) ? $settings['orders_per_hour'] : '',
				'type'     => 'number',
				'desc_tip' => true,
				'custom_attributes' => array(
					'min' => 0,
				),
			),
			array(
				'title'             => __( 'Products', 'happy-order-generator' ),
				'desc'              => __( 'The products that will be added to the generated orders. Leave empty to randomly select from all products.', 'happy-order-generator' ),
				'id'                => 'hog_products',
				'type'              => 'multiselect',
				'class'             => 'wc-product-search',
				'value'             => $product_ids,
				'search_action'     => 'woocommerce_json_search_products',
				'css'               => 'min-width: 350px;',
				'options'           => $json_ids,
				'custom_attributes' => array(
					'data-multiple' => "true",
					'data-selected' => $data_selected
				),
				'desc_tip'          => true,
			),
			array(
				'title'             => __( 'Min Order Products', 'happy-order-generator' ),
				'id'                => 'hog_min_order_products',
				'desc'              => __( 'The minimum number of products to add to the generated orders', 'happy-order-generator' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => 1,
				),
				'value'             => ( isset( $settings['min_order_products'] ) ) ? $settings['min_order_products'] : '1',
				'css'               => 'width:50px;',
				'autoload'          => false,
			),
			array(
				'title'             => __( 'Max Order Products', 'happy-order-generator' ),
				'id'                => 'hog_max_order_products',
				'desc'              => __( 'The maximum number of products to add to the generated orders', 'happy-order-generator' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => 1,
				),
				'value'             => ( isset( $settings['max_order_products'] ) ) ? $settings['max_order_products'] : 3,
				'css'               => 'width:50px;',
				'autoload'          => false
			),
			array(
				'title'    => __( 'Create User Accounts', 'woocommerce' ),
				'desc_tip' => true,
				'id'       => 'hog_create_users',
				'desc'     => __( 'If enabled, accounts will be created and will randomly assigned to new orders.', 'happy-order-generator' ),
				'type'     => 'select',
				'options'  => array(
					0 => __( 'No - assign existing accounts to new orders', 'happy-order-generator' ),
					1 => __( 'Yes - create a new account or randomly select an existing account to assign to new orders', 'happy-order-generator' )
				),
				'value'    => ( isset( $settings['create_users'] ) ) ? $settings['create_users'] : 'Yes',
				'autoload' => false,
				'class'    => 'wc-enhanced-select'
			),
			array(
				'title'    => __( 'Bypass SSL verify', 'happy-order-generator' ),
				'desc_tip' => true,
				'id'       => 'hog_skip_ssl',
				'desc'     => __( 'If enabled, SSL verify will be bypassed. Handy if you\'re running a local dev site.', 'happy-order-generator' ),
				'type'     => 'select',
				'options'  => array(
					0 => __( 'Do not bypass SSL verify', 'happy-order-generator' ),
					1 => __( 'Bypass SSL verify', 'happy-order-generator' )
				),
				'value'    => ( isset( $settings['skip_ssl'] ) ) ? $settings['skip_ssl'] : 0,
				'autoload' => false,
				'class'    => 'wc-enhanced-select'
			),
			array(
				'title'    => __( 'Order attribution', 'happy-order-generator' ),
				'desc_tip' => true,
				'id'       => 'hog_order_attribution',
				'desc'     => __( 'If enabled, generated orders get plausible WooCommerce order attribution data (source, referrer, device) so they appear in attribution reports instead of "Unknown".', 'happy-order-generator' ),
				'type'     => 'select',
				'options'  => array(
					'no'  => __( 'No - leave order attribution empty', 'happy-order-generator' ),
					'yes' => __( 'Yes - add order attribution data', 'happy-order-generator' )
				),
				'value'    => ( isset( $settings['order_attribution'] ) ) ? $settings['order_attribution'] : 'no',
				'autoload' => false,
				'class'    => 'wc-enhanced-select'
			),
			array(
				'title'    => __( 'New customer locales', 'happy-order-generator' ),
				'desc'     => __( 'Billing country for new users. Leave empty to randomly generate.', 'happy-order-generator' ),
				'id'       => 'hog_customer_locales',
				'type'     => 'multi_select_countries',
				'class'    => 'wc-country-search',
				'value'    => $country_ids,
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Customer naming convention', 'happy-order-generator' ),
				'desc'     => __( 'How customers are named', 'happy-order-generator' ),
				'id'       => 'hog_customer_naming_convention',
				'type'     => 'select',
				'class'    => 'wc-country-search',
				'value'    => ( isset( $settings['customer_naming_convention'] ) ) ? $settings['customer_naming_convention'] : 'faker',
				'options'  => array(
					'faker'       => 'Fake names',
					'customer_id' => 'Customer IDs (Test Customer ID: 000)',
				),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Email convention', 'happy-order-generator' ),
				'desc'     => __( 'Convention to use for email addresses', 'happy-order-generator' ),
				'id'       => 'hog_customer_email_convention',
				'type'     => 'select',
				'class'    => 'wc-country-search',
				'value'    => ( isset( $settings['customer_email_convention'] ) ) ? $settings['customer_email_convention'] : 'faker',
				'options'  => array(
					'faker'               => 'Fake email addresses',
					'customer_id_here'    => 'TestCustomer.id.000@' . parse_url( get_bloginfo( 'url' ), PHP_URL_HOST ) . ')',
					'customer_id_staging' => 'TestCustomer.id.000@mystagingwebsite.com',
				),
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Customer emails', 'happy-order-generator' ),
				'desc'     => __( 'What happens to WooCommerce emails for generated customers, such as order confirmations. Emails for real customers and store staff aren\'t affected.', 'happy-order-generator' ),
				'id'       => 'hog_customer_emails',
				'type'     => 'select',
				'value'    => Customer_Emails::get_mode(),
				'options'  => array(
					Customer_Emails::REDIRECT => __( 'Send them to one address', 'happy-order-generator' ),
					Customer_Emails::BLOCK    => __( 'Don\'t send them', 'happy-order-generator' ),
				),
				'desc_tip' => true,
			),
			array(
				'title'       => __( 'Send customer emails to', 'happy-order-generator' ),
				'desc'        => sprintf(
					/* translators: %s: the default address, e.g. hog-customer@example.com */
					__( 'Used when customer emails are sent to one address. Leave empty to use %s.', 'happy-order-generator' ),
					Customer_Emails::get_default_address()
				),
				'id'          => 'hog_customer_email_address',
				'type'        => 'email',
				'value'       => (string) ( $settings['customer_email_address'] ?? '' ),
				'placeholder' => Customer_Emails::get_default_address(),
				'desc_tip'    => true,
			),
			array(
				'title'             => __( 'Completed Order Status Chance', 'happy-order-generator' ),
				'desc_tip'          => false,
				'id'                => 'hog_order_completed_pct',
				'desc'              => __( '%', 'happy-order-generator' ),
				'type'              => 'number',
				'value'             => ( isset( $settings['order_completed_pct'] ) ) ? $settings['order_completed_pct'] : '0',
				'autoload'          => false,
				'css'               => 'width:50px;',
				'custom_attributes' => array(
					'min' => 0,
					'max' => 100
				)
			),
			array(
				'title'             => __( 'Processing Order Status Chance', 'happy-order-generator' ),
				'desc_tip'          => false,
				'id'                => 'hog_order_processing_pct',
				'desc'              => __( '%', 'happy-order-generator' ),
				'type'              => 'number',
				'value'             => ( isset( $settings['order_processing_pct'] ) ) ? $settings['order_processing_pct'] : '90',
				'autoload'          => false,
				'css'               => 'width:50px;',
				'custom_attributes' => array(
					'min' => 0,
					'max' => 100
				)
			),
			array(
				'title'             => __( 'Failed Order Status Chance', 'happy-order-generator' ),
				'desc_tip'          => false,
				'id'                => 'hog_order_failed_pct',
				'desc'              => __( '%', 'happy-order-generator' ),
				'type'              => 'number',
				'value'             => ( isset( $settings['order_failed_pct'] ) ) ? $settings['order_failed_pct'] : '10',
				'autoload'          => false,
				'css'               => 'width:70px;',
				'custom_attributes' => array(
					'min' => 0,
					'max' => 100
				)
			),
			array(
				'title' => __( 'Enable logging', 'happy-order-generator' ),
				'id'    => 'hog_debug',
				'type'  => 'checkbox',
				'value' => ( isset( $settings['enable_debug'] ) ) ? $settings['enable_debug'] : 'no',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'hog_settings_end'
			),
		);

		return apply_filters( 'woocommerce_get_settings_' . $this->id, $settings_array );
	}

	/**
	 * Names of selected bundle products that can't be used because Product Bundles isn't active.
	 *
	 * @param array $product_ids Selected product IDs.
	 * @return string[]
	 */
	private function get_unusable_bundle_names( array $product_ids ): array {

		if ( class_exists( 'WC_Product_Bundle' ) ) {
			return array();
		}

		$names = array();

		foreach ( $product_ids as $product_id ) {
			// Without Product Bundles, wc_get_product() loads a bundle as a simple product,
			// so read the stored product type instead.
			if ( 'bundle' === \WC_Product_Factory::get_product_type( (int) $product_id ) ) {
				$names[] = get_the_title( (int) $product_id );
			}
		}

		return $names;
	}

	/**
	 * Save settings
	 *
	 * @return void
	 */
	public function save(): void {

		/**
		 * WC_Admin_Settings::save() checks both of these before it fires
		 * woocommerce_settings_save_{tab}. Checking again here keeps this
		 * method safe if it's ever called from somewhere else.
		 */
		check_admin_referer( 'woocommerce-settings' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$settings = array(
			'enabled'                    => $this->posted_checkbox( 'hog_enabled' ),
			'orders_per_hour'            => max( 0, intval( $_POST['hog_orders_per_hour'] ?? 0 ) ),
			'min_order_products'         => absint( $_POST['hog_min_order_products'] ?? 1 ),
			'max_order_products'         => absint( $_POST['hog_max_order_products'] ?? 1 ),
			'create_users'               => ! empty( absint( $_POST['hog_create_users'] ?? 0 ) ),
			'order_completed_pct'        => absint( $_POST['hog_order_completed_pct'] ?? 0 ),
			'order_processing_pct'       => absint( $_POST['hog_order_processing_pct'] ?? 90 ),
			'order_failed_pct'           => absint( $_POST['hog_order_failed_pct'] ?? 10 ),
			'customer_naming_convention' => sanitize_text_field( wp_unslash( $_POST['hog_customer_naming_convention'] ?? 'faker' ) ),
			'customer_email_convention'  => sanitize_text_field( wp_unslash( $_POST['hog_customer_email_convention'] ?? 'faker' ) ),
			'skip_ssl'                   => absint( $_POST['hog_skip_ssl'] ?? 0 ),
			'order_attribution'          => 'yes' === sanitize_text_field( wp_unslash( $_POST['hog_order_attribution'] ?? 'no' ) ) ? 'yes' : 'no',
			'enable_debug'               => $this->posted_checkbox( 'hog_debug' ),
			'products'                   => $this->posted_list( 'hog_products' ),
			'customer_locales'           => $this->posted_list( 'hog_customer_locales' ),
			'customer_emails'            => Customer_Emails::BLOCK === sanitize_key( wp_unslash( $_POST['hog_customer_emails'] ?? '' ) ) ? Customer_Emails::BLOCK : Customer_Emails::REDIRECT,
			'customer_email_address'     => sanitize_email( wp_unslash( $_POST['hog_customer_email_address'] ?? '' ) ),
		);

		$posted_address = trim( sanitize_text_field( wp_unslash( $_POST['hog_customer_email_address'] ?? '' ) ) );
		if ( '' !== $posted_address && ! is_email( $settings['customer_email_address'] ) ) {
			$settings['customer_email_address'] = '';
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: the default address, e.g. hog-customer@example.com */
					__( 'The customer email address isn\'t a valid email address, so customer emails will go to %s.', 'happy-order-generator' ),
					Customer_Emails::get_default_address()
				)
			);
		}

		/**
		 * The maximum orders per hour can be reset by a filter, but defaults
		 * for now to 500
		 */
		if ( $settings['orders_per_hour'] > 500 ) {
			$settings['orders_per_hour'] = apply_filters( 'order_generator_max_orders_per_hour', 500 );
		}

		if ( empty( $settings['min_order_products'] ) || $settings['min_order_products'] < 1 ) {
			$settings['min_order_products'] = 1;
		}

		if ( empty( $settings['max_order_products'] ) || $settings['max_order_products'] < $settings['min_order_products'] ) {
			$settings['max_order_products'] = $settings['min_order_products'];
		}

		/**
		 * The completed, processing and failed percentages need to balance to 100
		 * If they do not, we work through them and adjust until they do
		 */
		$sum = $settings['order_completed_pct'] + $settings['order_processing_pct'] + $settings['order_failed_pct'];

		while ( $sum > 100 ) {
			if ( $settings['order_failed_pct'] > 0 ) {
				$settings['order_failed_pct'] --;
			} elseif ( $settings['order_processing_pct'] > 0 ) {
				$settings['order_processing_pct'] --;
			} else {
				$settings['order_completed_pct'] --;
			}
			$sum = $settings['order_completed_pct'] + $settings['order_processing_pct'] + $settings['order_failed_pct'];
		}

		while ( $sum < 100 ) {
			$settings['order_processing_pct'] ++;
			$sum = $settings['order_completed_pct'] + $settings['order_processing_pct'] + $settings['order_failed_pct'];
		}

		/**
		 * Save the settings
		 */
		update_option( 'happy_order_generator_settings', $settings );
	}

	/**
	 * A posted checkbox as 'yes' or 'no'. Unticked checkboxes aren't posted.
	 * Only call after the nonce check in save().
	 *
	 * @param string $key The field ID.
	 *
	 * @return string
	 */
	private function posted_checkbox( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified in save().
		return isset( $_POST[ $key ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) ? 'yes' : 'no';
	}

	/**
	 * A posted multiselect as a list of non-empty sanitized strings.
	 * Only call after the nonce check in save().
	 *
	 * @param string $key The field ID.
	 *
	 * @return string[]
	 */
	private function posted_list( string $key ): array {
		// Nonce verified in save(); each value is sanitized below.
		$values = isset( $_POST[ $key ] ) ? (array) wp_unslash( $_POST[ $key ] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return array_filter( array_map( 'sanitize_text_field', array_filter( $values, 'is_scalar' ) ) );
	}
}

return new WC_Settings_Order_Generator_Settings();
