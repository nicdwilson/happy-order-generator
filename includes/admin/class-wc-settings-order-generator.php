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

		$message     = 'todo';
		$country_ids = ( isset( $settings['customer_locales'] ) ) ? maybe_unserialize( $settings['customer_locales'] ) : array();

		$settings_array = array(
			array(
				'title' => __( 'Settings', 'happy-order-generator' ),
				'type'  => 'title',
				'desc'  => $message,
				'id'    => 'hog_settings_start'
			),
			array(
				'title'    => __( 'Orders per Hour', 'happy-order-generator' ),
				'desc'     => __( 'The maximum number of orders to generate per hour.', 'happy-order-generator' ),
				'id'       => 'hog_orders_per_hour',
				'css'      => 'width:100px;',
				'value'    => ( isset( $settings['orders_per_hour'] ) ) ? $settings['orders_per_hour'] : '',
				'type'     => 'number',
				'desc_tip' => true,
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
	 * Save settings
	 *
	 * @return void
	 */
	public function save(): void {

		$settings = array(
			'orders_per_hour'            => absint( $_POST['hog_orders_per_hour'] ?? 0 ),
			'min_order_products'         => absint( $_POST['hog_min_order_products'] ?? 1 ),
			'max_order_products'         => absint( $_POST['hog_max_order_products'] ?? 1 ),
			'create_users'               => (bool) $_POST['hog_create_users'],
			'order_completed_pct'        => absint( $_POST['hog_order_completed_pct'] ?? 0 ),
			'order_processing_pct'       => absint( $_POST['hog_order_processing_pct'] ?? 90 ),
			'order_failed_pct'           => absint( $_POST['hog_order_failed_pct'] ?? 10 ),
			'customer_naming_convention' => sanitize_text_field( $_POST['hog_customer_naming_convention'] ?? 'faker' ),
			'customer_email_convention'  => sanitize_text_field( $_POST['hog_customer_email_convention'] ?? 'faker' ),
			'skip_ssl'                   => absint( $_POST['hog_skip_ssl'] ?? 0 ),
			'enable_debug'               => ( $_POST['hog_debug'] == '1' ) ? 'yes' : 'no',
		);

		/**
		 * clean a grab product settings
		 */
		$products             = isset( $_POST['hog_products'] ) ? wp_unslash( $_POST['hog_products'] ) : '';
		$products             = array_filter( array_map( 'wc_clean', (array) $products ) );
		$settings['products'] = $products;

		/**
		 * Clean and grab locale settings
		 */
		$customer_locales             = isset( $_POST['hog_customer_locales'] ) ? wp_unslash( $_POST['hog_customer_locales'] ) : '';
		$customer_locales             = array_filter( array_map( 'wc_clean', (array) $customer_locales ) );
		$settings['customer_locales'] = $customer_locales;

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
}

return new WC_Settings_Order_Generator_Settings();
