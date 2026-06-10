<?php
/**
 * The class where the order generation actual takes place
 *
 * @package Happy_Order_Generator
 */

namespace Happy_Order_Generator;

use WC_Stripe_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Generator {

	/**
	 * Available payment methods
	 *
	 * @var array|string[]
	 */
	private array $available_payment_methods = array( 'bacs' );

	/**
	 * @var bool
	 */
	private bool $order_contains_subscription = false;

	/**
	 * @var array|false|mixed|null
	 */
	public array $settings = array();

	/**
	 * Order The instance of Order
	 *
	 * @var    object
	 * @access  private
	 * @since    1.0.0
	 */
	private static object $instance;

	/**
	 * Main Order Instance
	 *
	 * Ensures only one instance of Order is loaded or can be loaded.
	 *
	 * @return Order_Generator instance
	 * @since 1.0.0
	 * @static
	 */
	public static function instance(): object {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {

		$this->settings = Order_Generator::get_settings();


	}


	/**
	 * Selects products, adds them to a cart, generates a single order, and pays
	 * for it.
	 *
	 * @return bool
	 *
	 * @throws WC_Stripe_Exception
	 */
	public function generate_order(): bool {

		/**
		 * We log as we go but only write out if there is an error
		 */
		$error_message = 'Starting order generation' . PHP_EOL;

		/**
		 * Sets up the customer to use for checkout
		 */
		$customer            = new Customer();
		$error_message .= 'Customer user id ' . $customer->get_id() . PHP_EOL;
		Logger::log( 'Customer created: ' . $customer->get_id() );

		/**
		 * Selects products to add to the cart
		 */
		$product             = new Product();
		$cart_products       = $product->get_products_for_cart();
		
		$this->order_contains_subscription = $this->check_cart_for_subscription( $cart_products );
		$error_message .= 'Adding ' . count( $cart_products ) . ' products to the cart'  . PHP_EOL;
		Logger::log( 'Cart products prepared: ' . count( $cart_products ) . ' products' );

		$order_builder = new Order_Builder();

		$add_to_cart_response = $order_builder->add_to_cart( $cart_products );

		Logger::log( 'Add to cart response: ' . print_r( $add_to_cart_response, true ) );

		if( ! $add_to_cart_response ){
			Logger::log( 'Error adding to cart' );
			Logger::log( $error_message );
			return false;
		}else{
			$this->available_payment_methods = $add_to_cart_response['payment_methods'];
			Logger::log( 'Products added to cart successfully' );
		}

		/**
		 * What payment method to use for this order.
		 */
		$options['payment_method'] = $this->get_payment_method();
		Logger::log( 'Payment method selected: ' . $options['payment_method'] );

		/**
		 * Set the desired final status of the order. Options are processing, completed or failed.
		 * BACS should go to on-hold, but we don't want that, so we'll switch that too
		 */
		$status = $this->get_final_status();
		Logger::log( 'Order status set to: ' . $status );

		/**
		 * Set up options to add to checkout data
		 */
		$options['user_id'] = $customer->get_id();

		/**
		 * Adds payment data to be used for checkout
		 */
		if ( 'stripe' === $options['payment_method'] ) {
			$gateway                 = new Gateway_Integration_Stripe();
			$options['payment_data'] = $gateway->get_payment_data( $customer->get_id(), $status );
			Logger::log( 'Stripe payment data prepared' );
		}

		$options['payment_data']['final_status'] = $status;

		Logger::log( 'Payment data prepared: ' . print_r( $options, true ) );

		/**
		 * Checkout, with additional options, get back the order and
		 * convert it into a regular order object
		 */
		Logger::log( 'Starting checkout process...' );
		$order = $order_builder->do_checkout( $options );

		// Bail if we're broken
		if ( is_wp_error( $order ) ) {
			Logger::log( $error_message );
			Logger::log( 'Order creation failed to checkout.' );
			Logger::log( $order );
			return false;
		}

		Logger::log( 'Checkout completed successfully, order ID: ' . $order->get_id() );

		/**
		 * Set the fake customer IP.
		 */
		$order->set_customer_ip_address( $customer->get_customer_ip() );

		if( 'failed' == $status ){
			do_action( 'order_generator_order_failed', $order, $options );
		}else{
			do_action( 'order_generator_order_processed', $order, $options );
		}

		/**
		 * If we're using BACS then mark the order as paid if it is to succeed.
		 */

		if ( 'bacs' === $options['payment_method'] ) {
			if( $status && $status !== 'failed' ){
				$order->payment_complete();
			}
			$order->update_status( $status );
			$order->save();
		}

		/**
		 * Add order note to generated order to identify it as generated
		 */
		$order->add_order_note( __( 'Order created by Order Generator', 'happy-order-generator' ) );
		$order->update_meta_data( '_happy_order_generator_order', 1 );

		/**
		 * Optionally give the order plausible WooCommerce order attribution
		 * data so generated orders show up in the attribution reports rather
		 * than landing in "Unknown".
		 */
		if ( 'yes' === ( $this->settings['order_attribution'] ?? 'no' ) ) {
			foreach ( $this->generate_attribution_meta() as $meta_key => $meta_value ) {
				$order->update_meta_data( $meta_key, $meta_value );
			}
		}

		$order->save();

		Logger::log( __('Order ID ' . $order->get_id() . ' created for customer ID ' . $customer->get_id() . ' paid with ' . $options['payment_method'], 'happy-order-generator' ) );

		Logger::log( 'Order generation completed successfully' );
		return true;
	}

	/**
	 * Gets the payment method to assign to the order. Only BACS is support out the box
	 * Stripe is added via the filter because we need to check that Stripe is up and
	 * in test mode before using it.
	 *
	 * @return string
	 */
	public function get_payment_method(): string {

		$hog_payment_methods = apply_filters( 'order_generator_supported_gateways', array( 'bacs' ) );

		/**
		 * If this order contains a subscription, we want to use Stripe if it is available.
		 */
		if ( $this->order_contains_subscription && isset( $hog_payment_methods['stripe'] ) && in_array( 'stripe', $this->available_payment_methods ) ) {
			return apply_filters( 'hog_subscription_payment_method', 'stripe' );
		}

		/**
		 * Make sure the methods are all available for this order
		 */
		$hog_payment_methods = array_intersect( $hog_payment_methods, $this->available_payment_methods );

		return apply_filters('hog_payment_method', array_rand( array_flip( $hog_payment_methods ) ) );
	}

	/**
	 * Checks items in the cart for a subscription. We don't want manual renewals cluttering things
	 * up, so we're going to make sure subs don't go through as bacs payments.
	 *
	 * @param $cart_items
	 *
	 * @return bool True if cart contains a subscription.
	 */
	private function check_cart_for_subscription( $cart_items ): bool {

		foreach ( $cart_items as $cart_item ) {

			$product = wc_get_product( $cart_item['id'] );

			if ( ! $product ) {
				continue;
			}

			if ( $product->is_type( 'subscription' ) || $product->is_type( 'variable-subscription' ) || $product->is_type( 'subscription_variation' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Should the order succeed or not. Batches are not monitored for accuracy - we assign a percentage
	 * fail/complete/processing to individual orders and let the chips fall where they may.
	 *
	 * @return string
	 */
	public function get_final_status(): string {

		$status = 'completed';

		$rand = mt_rand( 1, 100 );

		$completed_pct  = $this->settings['order_completed_pct']; // e.g. 90
		$processing_pct = $completed_pct + $this->settings['order_processing_pct']; // e.g. 90 + 5
		$failed_pct     = $processing_pct + $this->settings['order_failed_pct']; // e.g. 95 + 5

		if ( $this->settings['order_processing_pct'] > 0 && $rand <= $processing_pct ) {
			$status = 'processing';
		} elseif ( $this->settings['order_failed_pct'] > 0 && $rand <= $failed_pct ) {
			$status = 'failed';
		}

		return $status;
	}

	/**
	 * Build a plausible set of WooCommerce order attribution meta for a
	 * generated order.
	 *
	 * Mirrors the meta keys written by WC_Order_Attribution (the
	 * `_wc_order_attribution_*` order meta) so generated orders are
	 * classified into a real origin in the attribution reports instead of
	 * "Unknown". The set is picked at random from a spread of common origins
	 * (organic search, referral, direct, a UTM campaign, and web admin) and
	 * paired with a random device. Keys follow WooCommerce's documented
	 * attribution meta; verify against WC_Order_Attribution for the pinned
	 * WooCommerce version when bumping the supported range.
	 *
	 * @return array<string, string> Map of `_wc_order_attribution_*` meta keys to values.
	 */
	private function generate_attribution_meta(): array {

		$user_agents = array(
			'Desktop' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'Mobile'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
			'Tablet'  => 'Mozilla/5.0 (iPad; CPU OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
		);

		/**
		 * Each preset mirrors how WC_Order_Attribution classifies an origin.
		 * `source_type` is the field that drives the Origin column; the UTM and
		 * referrer fields fill in the detail WooCommerce shows alongside it.
		 */
		$sources = array(
			array(
				'source_type'  => 'organic',
				'utm_source'   => 'google',
				'utm_medium'   => 'organic',
				'referrer'     => 'https://www.google.com/',
			),
			array(
				'source_type'  => 'referral',
				'utm_source'   => 'wordpress.org',
				'utm_medium'   => 'referral',
				'referrer'     => 'https://wordpress.org/',
			),
			array(
				'source_type'  => 'utm',
				'utm_source'   => 'newsletter',
				'utm_medium'   => 'email',
				'utm_campaign' => 'spring_sale',
				'referrer'     => '',
			),
			array(
				'source_type'  => 'typein',
				'utm_source'   => '(direct)',
				'utm_medium'   => '(none)',
				'referrer'     => '',
			),
			array(
				'source_type'  => 'admin',
				'utm_source'   => 'admin',
				'utm_medium'   => 'admin',
				'referrer'     => '',
			),
		);

		$source      = $sources[ wp_rand( 0, count( $sources ) - 1 ) ];
		$device_keys = array_keys( $user_agents );
		$device_type = $device_keys[ wp_rand( 0, count( $device_keys ) - 1 ) ];

		$meta = array(
			'_wc_order_attribution_source_type'       => $source['source_type'],
			'_wc_order_attribution_utm_source'        => $source['utm_source'],
			'_wc_order_attribution_utm_medium'        => $source['utm_medium'],
			'_wc_order_attribution_referrer'          => $source['referrer'],
			'_wc_order_attribution_device_type'       => $device_type,
			'_wc_order_attribution_user_agent'        => $user_agents[ $device_type ],
			'_wc_order_attribution_session_entry'     => home_url( '/' ),
			'_wc_order_attribution_session_start_time' => gmdate( 'Y-m-d H:i:s' ),
			'_wc_order_attribution_session_pages'     => (string) wp_rand( 1, 12 ),
			'_wc_order_attribution_session_count'     => (string) wp_rand( 1, 5 ),
		);

		if ( ! empty( $source['utm_campaign'] ) ) {
			$meta['_wc_order_attribution_utm_campaign'] = $source['utm_campaign'];
		}

		return $meta;
	}
}