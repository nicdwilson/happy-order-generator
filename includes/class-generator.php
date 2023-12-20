<?php
/**
 * The class where the order generation actual takes place
 *
 * @package Happy_Order_Generator
 */

namespace Happy_Order_Generator;

use Exception;
use WC_Gateway_Stripe;
use WC_Stripe_Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Generator {

	private array $available_payment_methods = array( 'bacs' );

	private string $error_message = '';

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

		$this->settings = get_option( 'wc_order_generator_settings', array() );


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
		$this->error_message = 'Starting order generation' . PHP_EOL;

		/**
		 * Sets up the customer to use for checkout
		 */
		$customer            = new Customer();
		$this->error_message .= 'Customer user id ' . $customer->get_id() . PHP_EOL;

		/**
		 * Selects products to add to the cart
		 */
		$product             = new Product();
		$cart_products       = $product->get_products_for_cart();
		$this->order_contains_subscription = $this->check_cart_for_subscription( $cart_products );
		$this->error_message .= 'Adding ' . count( $cart_products ) . ' products to the cart'  . PHP_EOL;

		$order_builder = new Order_Builder();

		$add_to_cart_response = $order_builder->add_to_cart( $cart_products );

		if( ! $add_to_cart_response ){
			Logger::log( 'Error adding to cart' );
			Logger::log( $this->error_message );
			return false;
		}else{
			$this->available_payment_methods = $add_to_cart_response;
		}

		/**
		 * What payment method to use for this order.
		 */
		$options['payment_method'] = $this->get_payment_method();

		/**
		 * Set the desired final status of the order. Options are processing, completed or failed.
		 * BACS should go to on-hold, but we don't want that, so we'll switch that too
		 */
		$status = $this->get_final_status();

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
		}

		$options['payment_data']['final_status'] = $status;

		/**
		 * Checkout, with additional options, get back the order and
		 * convert it into a regular order object
		 */
		$order = $order_builder->do_checkout( $options );

		// Bail if we're broken
		if ( is_wp_error( $order ) ) {
			Logger::log( $this->error_message );
			Logger::log( 'Order creation failed to checkout.' );
			Logger::log( $order );
		}

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
		$order->save();

		Logger::log( __('Order ID ' . $order->get_id() . ' created for customer ID ' . $customer->get_id() . ' paid with ' . $options['payment_method'], 'happy-order-generator' ) );

		return true;
	}

	/**
	 * Gets the payment method to assign to the order. Only BACS is support out the box
	 * Stripe is added via the filter because we need to check that Stripe is up and
	 * in test mode before using it.
	 *
	 * @return string
	 */
	public function get_payment_method() {

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

		return 'stripe';

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
	private function check_cart_for_subscription( $cart_items ){

		foreach ( $cart_items as $cart_item ) {

			$product = wc_get_product( $cart_item['id'] );
			if ( $product->is_type( 'subscription' ) || $product->is_type( 'variable-subscription' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Should the order succeed or not. Batches are not monitored for accuracy - we assign a percentage
	 * fail/complete/processing to individual orders and let the chips fall where they may.
	 *
	 * @return bool
	 */
	public function get_final_status() {

		$status = 'completed';

		$rand = mt_rand( 1, 100 );

		$completed_pct  = $this->settings['order_completed_pct']; // e.g. 90
		$processing_pct = $completed_pct + $this->settings['order_processing_pct']; // e.g. 90 + 5
		$failed_pct     = $processing_pct + $this->settings['order_failed_pct']; // e.g. 95 + 5

		if ( $this->settings['order_completed_pct'] > 0 && $rand <= $completed_pct ) {
			$status = 'completed';
		} elseif ( $this->settings['order_processing_pct'] > 0 && $rand <= $processing_pct ) {
			$status = 'processing';
		} elseif ( $this->settings['order_failed_pct'] > 0 && $rand <= $failed_pct ) {
			$status = 'failed';
		}

		return $status;
	}
}