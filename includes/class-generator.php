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

	private string $error_message = '';

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
		 * Selects products to add to the cart
		 */
		$product     = new Product();
		$product_ids = $product->get_products_for_cart();
		$this->error_message .= 'Adding ' . count( $product_ids ) . ' products to the cart';

		/**
		 * Sets up the customer to use for checkout
		 */
		$customer = new Customer();
		$this->error_message .= 'Customer user id ' . $customer->get_id() . PHP_EOL;

		/**
		 * Switches to the assigned customer. We're still not sure if this is necessary
		 * but it seems to be working okay - the only place this comes into play is
		 * after checkout, during the order processing.
		 */
		$current_user = get_current_user_id();
		wp_set_current_user( $customer->get_id() );

		Logger::log( 'Starting order generation' );

		$order_builder = new Order_Builder();

		foreach ( $product_ids as $product_id ) {
			$result = $order_builder->add_to_cart( $product_id );
			//Logger::log( 'Product added to cart and said: ' . print_r( $result, true ));
		}

		//todo Can we check here to see if we have a valid cart, because if not we might as well bail?

		/**
		 * What payment method to use for this order.
		 */
		$options['payment_method'] = $this->get_payment_method();

		/**
		 * Set the shipping method
		 * todo - remove?? Shipping method is reassigned during checkout through Store API
		 */
		$options['shipping_method']       = 'free_shipping';
		$options['shipping_method_title'] = 'Free Shipping';

		/**
		 * Set the desired final status of the order. Options are processing, completed or failed.
		 */
		$status                  = $this->get_final_status();

		/**
		 * Set up options to add to checkout data
		 */
		$options['user_id']               = $customer->get_id();
		/**
		 * Adds payment data to be used for checkout
		 */
		$gateway = new Gateway_Integration_Stripe();
		$options['payment_data'] = $gateway->get_payment_data( $customer->get_id(), $status );

		/**
		 * Checkout, with additional options, get back the order and
		 * convert it into a regular order object
		 */
		$checkout_result = $order_builder->do_checkout( $options );
		$order    = json_decode( $checkout_result['body'] );
		$order_id = $order->order_id;
		$order = wc_get_order( $order_id );


		// Bail if we're broken
		if ( empty( $order ) ) {
			Logger::log( 'Order creation failed' );
			$body = json_decode( $checkout_result['body'] );
			Logger::log( 'An error occurred: ' . $body->code . '. ' . $body->message );
			return false;
		}

		/**
		 * Here we hook up subscriptions if the order contains a subscription product
		 * The Store API doesn't appear to do that yet, or maybe we need to enter more into the
		 * extensions' data...
		 */
		do_action( 'woocommerce_checkout_order_processed', $order_id, $options );

		/**
		 * Make sure correct Stripe payment data is in place on the order so that we can generate
		 * an order source when we process the payment in Stripe
		 */
		$order->update_meta_data( '_stripe_customer_id', $options['payment_data']['stripe_customer_id'] );
		$order->update_meta_data( '_stripe_source_id', $options['payment_data']['stripe_source_id'] );
		/**
		 * Make sure all our changes are in place
		 */
		$order->save();

		/**
		 * If we're using Stripe Payment Gateway for this order we need to
		 * pay for it. We do not need to mark it as paid.
		 */
		if ( 'stripe' === $options['payment_method'] ) {

			if ( $order->get_status() !== 'failed' ) {

				Logger::log( 'Processing Stripe order payment' );

				/**
				 * Handling exceptions...
				 */
				try{
					$stripe = new WC_Gateway_Stripe();
					$stripe->process_payment( $order->get_id(), true, true, false, true );
				}catch( Exception $exception ){
					Logger::log( 'Order creation succeeded but Stripe order payment failed. Message was: ' . $exception->getMessage() );
					$order->update_status( 'failed' );
				}
			}
		}

		/**
		 * If we're using bacs then mark the order as paid if it is to succeed.
		 */
		if ( 'bacs' === $options['payment_method'] && 'failed' !== $status ) {
			$order->payment_complete();
		}

		/**
		 * Set the order status correctly
		 */
		if ( 'bacs' === $options['payment_method'] ) {
			if ( $status ) {
				$order->update_status( $status );
			} else {
				$order->payment_complete();
				$order->update_status( $status );
			}
		}

		/**
		 * Reset current user
		 */
		wp_set_current_user( $current_user );

		/**
		 * Add order note to generated order to identify it as generated
		 */
		$order->add_order_note( __( 'Order created by Order Generator', 'happy-order-generator' ) );

		Logger::log( 'Order ID ' . $order_id . ' created for customer ID ' . $customer->get_id() . ' paid with ' . $options['payment_method'], 'order-generator' );

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
		//return array_rand( array_flip( $hog_payment_methods ) );
		return 'stripe';
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