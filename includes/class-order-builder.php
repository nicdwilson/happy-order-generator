<?php
/**
 * Order Builder class uses the Store API.
 */

namespace Happy_Order_Generator;

use WP_Error;

class Order_Builder {

	/**
	 * @var bool
	 */
	private bool $skip_ssl = false;

	/**
	 * @var string
	 */
	public string $nonce;

	/**
	 * @var array
	 */
	private array $cookies;

	public function __construct() {

		$settings = Order_Generator::get_settings();
		if ( $settings['skip_ssl'] === 1 ) {
			$this->skip_ssl = true;
		}

		$this->nonce = $this->get_nonce();


		if ( $this->nonce === false ) {
			sleep( 3 );
			$this->nonce = $this->get_nonce();
		}

		if ( ! $this->get_nonce() ) {
			Logger::log( 'Unable to fetch nonce when initialising the order builder.' );
		}

	}

	/**
	 * Get a nonce from the Store API
	 *
	 * @return false|string
	 */
	public function get_nonce(): bool|string {

		$url  = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/cart';
		$args = array(
			'timeout' => 20
		);

		if ( $this->skip_ssl ) {
			add_filter( 'https_ssl_verify', '__return_false' );
		}
		$response = wp_safe_remote_get( $url, $args );
		remove_filter( 'https_ssl_verify', '__return_false' );

		$headers = wp_remote_retrieve_headers( $response );

		if ( is_wp_error( $response ) ) {
			$this->handle_error_response( $response );
		}

		return ( isset( $headers['nonce'] ) ) ? $headers['nonce'] : false;
	}

	/**
	 * Returns the status of the cart
	 *
	 * @return string
	 */
	public function get_cart(): string {

		$url  = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/cart';
		$args = array(
			'headers' => array(
				'nonce' => $this->nonce
			),
			'timeout' => 20
		);

		if ( $this->skip_ssl ) {
			add_filter( 'https_ssl_verify', '__return_false' );
		}
		$response = wp_safe_remote_get( $url, $args );
		remove_filter( 'https_ssl_verify', '__return_false' );

		if ( is_wp_error( $response ) ) {
			$this->handle_error_response( $response );
		}

		return json_decode( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Do checkout, creating the order
	 *
	 * @param array $options
	 *
	 * options[user_id]
	 * $options['payment_method']
	 *
	 * @return object
	 */
	public function do_checkout( $options ): object {

		$url = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/checkout';

		$user_meta = get_userdata( $options['user_id'] );

		$body = array(
			'billing_address'  => array(
				'first_name' => $user_meta->first_name,
				'last_name'  => $user_meta->last_name,
				'company'    => '',
				'address_1'  => $user_meta->billing_address_1,
				'address_2'  => '',
				"city"       => $user_meta->billing_city,
				"state"      => $user_meta->billing_state,
				"postcode"   => $user_meta->billing_postcode,
				"country"    => $user_meta->billing_country,
				"email"      => $user_meta->billing_email,
				"phone"      => $user_meta->billing_phone
			),
			'shipping_address' => array(
				'first_name' => $user_meta->first_name,
				'last_name'  => $user_meta->last_name,
				'company'    => "",
				'address_1'  => $user_meta->shipping_address_1,
				'address_2'  => '',
				'city'       => $user_meta->shipping_city,
				'state'      => $user_meta->shipping_state,
				'postcode'   => $user_meta->shipping_postcode,
				'country'    => $user_meta->shipping_country,
			),
			'customer_note'    => '',
			'create_account'   => true,
			'payment_method'   => $options['payment_method'],
		);

		/**
		 * todo should be a do_action
		 */
		if ( 'stripe' === $options['payment_method'] ) {

			$body['payment_data'] = array(
				array(
					'key'   => 'stripe_source',
					'value' => $options['payment_data']['stripe_source_id'],
				),
				array(
					'key'   => 'stripe_customer',
					'value' => $options['payment_data']['stripe_customer_id'],
				),
				array(
					'key'   => 'billing_email',
					'value' => $user_meta->billing_email,
				),
				array(
					'key'   => 'billing_first_name',
					"value" => $user_meta->first_name,
				),
				array(
					"key"   => "billing_last_name",
					"value" => $user_meta->last_name
				),
				array(
					"key"   => "paymentMethod",
					"value" => "stripe"
				),
				array(
					"key"   => "paymentRequestType",
					"value" => "cc"
				),
				array(
					"key"   => "wc-stripe-new-payment-method",
					"value" => true
				),
				array(
					"key"   => "final_status",
					"value" => ( $options['payment_data']['final_status'] === 'failed' ) ? '' : 'paid'
				),
			);
		}

		$args = array(
			'headers' => array(
				'nonce' => $this->nonce
			),
			'cookies' => $this->cookies,
			'body'    => $body,
			'timeout' => 20
		);

		$response_body = $this->get_post_response( $url, $args );

		$response_object = json_decode( $response_body );

		if ( ! $response_object->order_id ) {
			//todo something went wrong here
		}

		$order_id = $response_object->order_id;

		$order = wc_get_order( $order_id );

		// Bail if we're broken
		if ( ! is_a( $order, 'WC_Order' ) ) {

			$message = 'Order creation failed at checkout.\\n';

			if ( $response_object->code ) {
				$code = $response_object->code;
			} else {
				$code = 'Unknown error';
				//todo fix?
				$message .= sanitize_text_field( $response_body );
			}

			if ( $response_object->code ) {
				$message .= 'An error occurred: ' . sanitize_text_field( $response_object->message );
			}
			if ( 'rest_invalid_param' === $code ) {
				$message .= 'Invalid data was returned.\\n';
			}

			if ( strpos( $code, '_missing_' ) !== false ) {
				$message .= 'Data is missing. We provided:\\n';
				$message .= json_encode( $body );
			}

			$order = new WP_Error( $code, $message );
		}

		return $order;
	}

	/**
	 * Return the order ID of the current order from the store api.
	 *
	 * @return false|mixed
	 */
	public function get_checkout_order_id() {

		$url  = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/checkout';
		$args = array(
			'headers' => array(
				'nonce' => $this->nonce
			),
			'cookies' => $this->cookies,
			'timeout' => 20
		);

		$response_body = $this->get_post_response( $url, $args );

		$body = json_decode( wp_remote_retrieve_body( $response_body ) );

		if ( is_array( $body ) && isset( $body['order_id'] ) && ! empty( $body['order_id'] ) ) {
			return $body['order_id'];
		}

		return false;
	}


	/**
	 * Add to the current cart using the Store API batch endpoint. Requires an array
	 * of cart items.
	 *
	 * todo the response from this can get pretty big, we may need to limit
	 * output or the number of cart items maximum
	 *
	 *
	 * @param array $cart_items
	 *
	 * @return array|false The payment methods available for the cart or false on Error
	 */
	public function add_to_cart( array $cart_items = array() ): array|false {

		if ( empty( $cart_items ) ) {
			return false;
		}

		$url = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/batch';

		$body['requests'] = array();

		foreach ( $cart_items as $cart_item ) {

			$body['requests'][] = array(

				'path'    => '/wc/store/v1/cart/add-item',
				'method'  => 'POST',
				'cache'   => 'no-store',
				'body'    => $cart_item,
				'headers' => array(
					'Nonce' => $this->nonce
				)
			);
		}

		$args = array(
			'headers' => array(
				'nonce' => $this->nonce
			),
			'timeout' => 20,
			'body'    => $body
		);

		$response_body = $this->get_post_response( $url, $args );

		/**
		 * This is not an error but could still be an unexpected response.
		 * Check cart contents and bail if we're broken.
		 */
		$cart = json_decode( $response_body );

		if ( ! isset( $cart->responses[0]->body->items[0] ) ) {
			Logger::log( 'Unexpected response from add to cart' );
			Logger::log( 'REQUEST' );
			Logger::log( $cart_items );
			Logger::log( 'RESPONSE' );
			Logger::log( $cart );

			return false;
		}

		/**
		 * Get the payment method
		 */
		$assigned_payment_methods = $cart->responses[0]->body->payment_methods;
		for ( $i = 0; $i < count( $cart->responses ); $i ++ ) {
			if ( $cart->responses[ $i ]->body->payment_methods ) {
				$assigned_payment_methods = array_intersect( $cart->responses[ $i ]->body->payment_methods, $assigned_payment_methods );
			}
		}

		return $assigned_payment_methods;
	}

	private function get_post_response( $url, $args ) {

		if ( $this->skip_ssl ) {
			add_filter( 'https_ssl_verify', '__return_false' );
		}
		$response = wp_safe_remote_post( $url, $args );
		remove_filter( 'https_ssl_verify', '__return_false' );

		if ( is_wp_error( $response ) ) {
			$this->handle_error_response( $response );
		} else {
			$this->cookies = $response['cookies'];
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Handle error logging for errors.
	 *
	 * @param $response
	 *
	 * @return void
	 */
	private function handle_error_response( $response ) {
		Logger::log( 'Error occurred during order build.' . $response->get_error_message() );
	}

}