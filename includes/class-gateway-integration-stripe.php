<?php
/**
 *
 */

namespace Happy_Order_Generator;

use AutomateWoo\Log;
use Exception;
use http\Env\Request;
use WC_Gateway_Stripe;
use WC_Payment_Token_CC;
use WC_Stripe_API;
use WC_Stripe_Exception;
use WC_Stripe_Helper;
use WC_Stripe_Payment_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Gateway_Integration_Stripe {

	/**
	 * Gateway The instance of Gateway
	 *
	 * @var    object
	 * @access  private
	 * @since    1.0.0
	 */
	private static object $instance;

	/**
	 * The Stripe Helper
	 *
	 * @var object
	 */
	public object $stripe_helper;

	/**
	 * Main Gateway Instance
	 *
	 * Ensures only one instance of Gateway is loaded or can be loaded.
	 *
	 * @return Gateway_Stripe_Integration instance
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

		/**
		 * Add Stripe gateway support
		 */
		add_filter( 'order_generator_supported_gateways', array( $this, 'add_stripe_support' ) );
		add_action( 'woocommerce_rest_checkout_process_payment_with_context', array( $this, 'pay_test' ), 99, 2 );

		//$thing = new \WC_Stripe_Blocks_Support();
		//remove_action( 'woocommerce_rest_checkout_process_payment_with_context', [ $thing, 'add_stripe_intents' ], 9999, 2 );


		//apply_filters( 'woocommerce_payment_gateways', array( $this, 'add_hog_gateway' ) );

	}


	/**
	 * Adds Stripe payment gateway to order generator payment methods if plugin
	 * - is active
	 * - is listed as available
	 * - is in test mode
	 *
	 * @param $supported_gateways
	 *
	 * @return mixed
	 */
	public function add_stripe_support( $supported_gateways ) {

		/**
		 * Is Stripe plugin active? Doublecheck because there are clones out there
		 */
		if ( ! is_plugin_active( 'woocommerce-gateway-stripe/woocommerce-gateway-stripe.php' ) || ! class_exists( WC_Gateway_Stripe::class ) ) {
			return $supported_gateways;
		}

		/**
		 * Is Stripe gateway enabled? It doesn't have to be! As long as the plugin is active
		 * and set to test mode, we can use it. However, we decided it might not be wise...
		 */
		if ( ! in_array( 'stripe', array_keys( WC()->payment_gateways->get_available_payment_gateways() ) ) ) {
			return $supported_gateways;
		}

		/**
		 * Is Stripe in test mode?
		 */
		if ( ! ( new WC_Gateway_Stripe() )->is_in_test_mode() ) {
			return $supported_gateways;
		}

		$supported_gateways[] = 'stripe';

		return $supported_gateways;
	}


	public function pay_test( $context, &$result ) {

		$this->stripe_helper = new WC_Stripe_Helper();

		$order          = $context->order;
		$payment_intent = $this->setup_payment_intent( $context->payment_data['stripe_source'], $context->payment_data['stripe_customer'], $order );

		Logger::log( 'Stripe payment intent ' . $payment_intent->id );

		$order->add_order_note(
			sprintf(
			/* translators: $1%s payment intent ID */
				__( 'Stripe payment intent created (Payment Intent ID: %1$s)', 'order-generator' ),
				$payment_intent->id
			)
		);

		$this->confirm_payment_intent( $payment_intent->id, $context->payment_data['stripe_source'] );

		$order->update_meta_data( '_stripe_intent_id', $payment_intent->id );
		$order->update_meta_data( '_stripe_card_id', $context->payment_data['stripe_source'] );

		$payment_details = $context->payment_data;
		$payment_details['setup_intent_secret'] = $payment_intent->client_secret;
		$payment_details['save_payment_method'] = 1;
		$result->set_payment_details( $payment_details );

		$result->set_status( 'success' );
		$order->save();
	}

	/**
	 * @param $order
	 * @param $final_status
	 *
	 * @return mixed|void
	 * @throws WC_Stripe_Exception
	 */
	public function get_payment_data( $user_id = '', $final_status = true ) {

		$this->stripe_helper = new WC_Stripe_Helper();

		$output = array(
			'final_status'       => 'paid',
			'stripe_source_id'   => '',
			'stripe_customer_id' => ''
		);

		if ( ! empty( $user_id ) ) {

			$stripe_customer_id = get_user_option( '_stripe_customer_id', $user_id );

			if ( ! $stripe_customer_id ) {

				$stripe_customer_id = $this->create_stripe_customer();

				/**
				 * If creating the Stripe customer failed, mark the order as failed
				 * and bail immediately, otherwise update the Stripe customer ID in userdata
				 */
				if ( $stripe_customer_id ) {
					update_user_option( $user_id, '_stripe_customer_id', $stripe_customer_id );
				} else {
					$output['final_status']     = 'failed';
					$output['stripe_source_id'] = '';

					return $output;
				}
			}

			Logger::log( 'Logging Stripe Customer data: ' . $stripe_customer_id );

			$payment_method = $this->get_payment_method( $final_status );

			if ( false !== strpos( $payment_method->id, 'cus_', 0 ) ) {
				$payment_method_id = $payment_method->default_source;
				if ( $stripe_customer_id !== $payment_method->id ) {
					$stripe_customer_id = $payment_method->id;
					update_user_option( $user_id, '_stripe_customer_id', $stripe_customer_id );
				}
			} else {
				$payment_method_id = $payment_method->id;
			}

			/**
			 * If the payment method failed, mark the order as failed
			 * and bail immediately, otherwise setup a payment intent
			 */
			//if ( isset( $payment_method_id ) && ! empty( $payment_method_id ) ) {
			//Logger::log( 'Stripe order successfully paid' );
			$output['stripe_source_id']   = $payment_method_id;
			$output['stripe_customer_id'] = $stripe_customer_id;
			//} else {
			//Logger::log( 'There was a problem with the Stripe order payment.' );
			//Logger::log( print_r( $payment_method, true ) );
			//$output['final_status'] = 'failed';
			//}
		}

		return $output;

	}


	/**
	 * @param $order
	 * @param $final_status
	 *
	 * @return mixed|void
	 * @throws WC_Stripe_Exception
	 */
	public function pay_generated_order( $order, $final_status = true ) {

		$this->stripe_helper = new WC_Stripe_Helper();

		if ( ! empty( $order ) ) {

			$stripe_customer_id = get_user_option( '_stripe_customer_id', $order->get_customer_id() );

			if ( ! $stripe_customer_id ) {

				$stripe_customer_id = $this->create_stripe_customer();

				/**
				 * If creating the Stripe customer failed, mark the order as failed
				 * and bail immediately, otherwise update the Stripe customer ID in userdata
				 */
				if ( $stripe_customer_id ) {
					update_user_option( $order->get_customer_id(), '_stripe_customer_id', $stripe_customer_id );
				} else {
					$order->update_meta_data( 'failed' );

					return $order;
				}
			}

			$payment_method = $this->get_payment_method( $final_status );

			if ( false !== strpos( $payment_method->id, 'cus_', 0 ) ) {
				$payment_method_id = $payment_method->default_source;
				if ( $stripe_customer_id !== $payment_method->id ) {
					$stripe_customer_id = $payment_method->id;
					update_user_option( $order->get_customer_id(), '_stripe_customer_id', $stripe_customer_id );
					$order->update_meta_data( '_stripe_customer_id', $stripe_customer_id );
				}
			} else {
				$payment_method_id = $payment_method->id;
			}

			/**
			 * If the payment method failed, mark the order as failed
			 * and bail immediately, otherwise setup a payment intent
			 */
			if ( isset( $payment_method_id ) && ! empty( $payment_method_id ) ) {
				Logger::log( 'Stripe order successfully paid' );
			} else {
				Logger::log( 'There was a problem with the Stripe order payment.' );
				Logger::log( print_r( $payment_method, true ) );
				$order->update_status( 'failed' );

				return $order;
			}

			$order->update_meta_data( '_stripe_source_id', $payment_method_id );
			$payment_intent = $this->setup_payment_intent(  $payment_method_id, $stripe_customer_id, $order );

			if ( isset( $payment_intent ) && ! empty( $payment_intent ) ) {
				Logger::log( 'Stripe payment intent ' . $payment_intent->id );
			} else {
				Logger::log( 'There was a problem with the Stripe payment intent.' );
				Logger::log( print_r( $payment_method, true ) );
				$order->update_status( 'failed' );

				return $order;
			}

			$order->update_meta_data( '_stripe_intent_id', $payment_intent->id );

			$order->add_order_note(
				sprintf(
				/* translators: $1%s payment intent ID */
					__( 'Stripe payment intent created (Payment Intent ID: %1$s)', 'order-generator' ),
					$payment_intent->id
				)
			);

			$this->confirm_payment_intent( $payment_intent->id, $payment_method_id );

			if ( $this->has_subscription( $order->get_id() ) ) {

				$payment_method_last4     = ( $payment_method->sources->data[0]->last4 ) ?? '';
				$payment_method_brand     = ( strtolower( $payment_method->sources->data[0]->brand ) ) ?? '';
				$payment_method_exp_month = ( $payment_method->sources->data[0]->exp_month ) ?? '';
				$payment_method_exp_year  = ( $payment_method->sources->data[0]->exp_year ) ?? '';

				$token = new WC_Payment_Token_CC();
				$token->set_token( $payment_method_id );
				$token->set_gateway_id( 'stripe' );
				$token->set_card_type( strtolower( $payment_method_brand ) );
				$token->set_last4( $payment_method_last4 );
				$token->set_expiry_month( $payment_method_exp_month );
				$token->set_expiry_year( $payment_method_exp_year );
				$token->set_user_id( $order->get_customer_id() );
				$token->save();

			}

			return $order;
		}
	}

	/**
	 * Submit to get the Stripe customer object.
	 *
	 * @return string|bool
	 */
	public function create_stripe_customer(): string|bool {

		$request = array(
			'description' => 'My first email'
		);

		/**
		 * Can throw a Stripe Exception
		 */
		try {
			$response = WC_Stripe_API::request( $request, 'customers' );
		} catch ( Exception $ex ) {
			Logger::log( 'Creating the Stripe customer failed with ' . $ex->getMessage() );
		}

		if ( isset( $response->id ) && ! empty( $response->id ) ) {
			return $response->id;
		} else {
			return false;
		}

	}


	public function confirm_payment_intent( $payment_intent_id, $payment_method_id ) {

		$request = array(
			'payment_method' => $payment_method_id
		);

		$response = WC_Stripe_API::request( $request, 'payment_intents/' . $payment_intent_id . '/confirm' );

		return $response;
	}

	/**
	 * Setup the payment intent.
	 *
	 * @param $payment_method_id
	 * @param $order
	 *
	 * @return object|bool
	 */
	public function setup_payment_intent( $payment_method_id, $stripe_customer_id, $order ): object|bool {

		$request = array(
			"shipping"             => array(
				"address" => array(
					"country"     => $order->get_shipping_country(),
					"line1"       => $order->get_shipping_address_1(),
					"state"       => $order->get_shipping_state(),
					"city"        => $order->get_shipping_city(),
					"postal_code" => $order->get_shipping_postcode(),
					"line2"       => $order->get_shipping_address_2()
				),
				"name"    => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			),
			"description"          => "Autogenerated order " . $order->get_id() . " for " . get_bloginfo( 'url' ),
			"capture_method"       => "automatic",
			"metadata"             => array(
				"order_id"       => $order->get_id(),
				"site_url"       => get_bloginfo( 'url' ),
				"customer_email" => $order->get_billing_email(),
				"customer_name"  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name()
			),
			"statement_descriptor" => 'AUTO GENERATED ORDER',
			"currency"             => strtolower( $order->get_currency() ),
			"customer"             => $stripe_customer_id,
			"payment_method"       => $payment_method_id,
			"amount"               => $this->stripe_helper->get_stripe_amount( $order->get_total(), $order->get_currency() ),
			"payment_method_types" => array(
				"0" => "card"
			)
		);

		if ( $this->has_subscription( $order->get_id() ) ) {
			$request['metadata']['payment_type'] = 'recurring';
		}

		/**
		 * Can throw a Stripe Exception
		 */
		try {
			$response = WC_Stripe_API::request( $request, 'payment_intents' );
		} catch ( Exception $ex ) {
			Logger::log( 'Setting up the Stripe payment intent failed with ' . $ex->getMessage() );
		}

		if ( isset( $response->id ) && ! empty( $response->id ) ) {
			return $response;
		} else {
			return false;
		}

	}

	/**
	 * Submit card details to get back the payment method.
	 *
	 * @param $final_status
	 *
	 * @return array|
	 * @throws WC_Stripe_Exception
	 */
	function get_payment_method( $final_status = 'processing' ): object|bool {

		if ( $final_status != 'failed' ) {
			$card_number = '4242424242424242';
		} else {
			$card_number = '4000000000009995';
		}

		$request = array(
			'card' => array(
				'number'    => $card_number,
				'exp_month' => 8,
				'exp_year'  => 2035,
				'cvc'       => '314',
			)
		);

		$response = WC_Stripe_API::request( $request, 'payment_methods' );

		/**
		 * Can throw a Stripe Exception
		 */
		try {
			$response = WC_Stripe_API::request( $request, 'customers' );
		} catch ( Exception $ex ) {
			Logger::log( 'Creating the Stripe payment method failed with ' . $ex->getMessage() );
		}

		if ( isset( $response->id ) && ! empty( $response->id ) ) {
			return $response;
		} else {
			return false;
		}
	}


	/**
	 * Check subscription
	 *
	 * @param $order_id
	 *
	 * @return bool
	 */
	public function has_subscription( $order_id ) {
		return ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order_id ) || wcs_is_subscription( $order_id ) || wcs_order_contains_renewal( $order_id ) ) );
	}
}