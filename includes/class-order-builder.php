<?php

namespace Happy_Order_Generator;

class Order_Builder {

	/**
	 * @var string
	 */
	public string $nonce;

	/**
	 * @var array
	 */
	private array $cookies;

	public function __construct() {

		$this->nonce = $this->get_nonce();

		if( $this->nonce === false ){
			sleep(3);
			$this->nonce = $this->get_nonce();
		}

		if ( ! $this->get_nonce() ) {
			//todo log error and bail
		}

	}

	/**
	 * Get a nonce from the Store API
	 *
	 * @return false|string
	 */
	public function get_nonce(): bool|string {

		$url = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/cart';
		$args = array(
			'timeout' => 20
		);

		$response = wp_safe_remote_get( $url, $args );
		$headers  = wp_remote_retrieve_headers( $response );

		if ( is_wp_error( $response ) ) {
			$this->handle_error_response( $response );
		}

		$nonce = ( isset( $headers['nonce'] ) ) ? $headers['nonce'] : false;

		return $nonce;
	}

	public function get_cart() {

		$url  = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/cart';
		$args = array(
			'headers' => array(
				'nonce' => $this->nonce
			),
			'timeout' => 20
		);

		$response = wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->handle_error_response( $response );
		}

		$body = wp_remote_retrieve_body( $response );

		return $body;
	}

	public function do_checkout( $options ) {

		$url = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/checkout';

		$usermeta = get_userdata( $options['user_id'] );

		$body = array(
			'billing_address'  => array(
				'first_name' => $usermeta->first_name,
				'last_name'  => $usermeta->last_name,
				'company'    => '',
				'address_1'  => $usermeta->billing_address_1,
				'address_2'  => '',
				"city"       => $usermeta->billing_city,
				"state"      => $usermeta->billing_state,
				"postcode"   => $usermeta->billing_postcode,
				"country"    => $usermeta->billing_country,
				"email"      => $usermeta->billing_email,
				"phone"      => $usermeta->billing_phone
			),
			'shipping_address' => array(
				'first_name' => $usermeta->first_name,
				'last_name'  => $usermeta->last_name,
				'company'    => "",
				'address_1'  => $usermeta->shipping_address_1,
				'address_2'  => '',
				'city'       => $usermeta->shipping_city,
				'state'      => $usermeta->shipping_state,
				'postcode'   => $usermeta->shipping_postcode,
				'country'    => $usermeta->shipping_country,
			),
			'customer_note'    => '',
			'create_account'   => true,
			'payment_method'   => $options['payment_method'],
			'payment_data'     => array()
			/**
				array(
					'key' => 'stripe_source',
					'value'=> $options['payment_data']['stripe_source_id'],
				),
				array(
					'key' => 'stripe_customer',
					'value'=> $options['payment_data']['stripe_customer_id'],
				),
				array(
					'key' => 'billing_email',
					'value'=> $usermeta->billing_email,
				),
				array(
					'key' => 'billing_first_name',
					'value'=> $usermeta->first_name,
				),
				array(
					'key' => 'billing_last_name',
					'value'=> $usermeta->last_name,
				),
				array(
					'key' => 'paymentMethod',
					'value'=> $options['payment_method'],
				),
				array(
					'key' => 'paymentRequestType',
					'value'=> 'cc',
				),
				array(
					'key' => 'wc-stripe-new-payment-method',
					'value'=> true,
				),
			)
			 */
			/**

				'billing_email'                => $usermeta->billing_email,
				'billing_first_name'           => $usermeta->first_name,
				'billing_last_name'            => $usermeta->last_name,
				'paymentMethod'                => $options['payment_method'],
				'paymentRequestType'           => 'cc',
				'wc-stripe-new-payment-method' => true
			)
		*/
		);

		$args = array(
			'headers' => array(
				'nonce' => $this->nonce
			),
			'cookies' => $this->cookies,
			'body'    => $body,
			'timeout' => 20
		);

		$response = wp_safe_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->handle_error_response( $response );
		}

		$checkout_data = wp_remote_retrieve_body( $response );

		return $response;

	}

	public function get_checkout_order_id() {

		$url  = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/checkout';
		$args = array(
			'headers' => array(
				'nonce' => $this->nonce
			),
			'cookies' => $this->cookies,
			'timeout' => 20
		);

		$response = wp_safe_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->handle_error_response( $response );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ) );

		if( is_array( $body ) && isset( $body['order_id'] ) && !empty( $body['order_id'] ) ){
			return $body['order_id'];
		}

		return false;
	}


	public function add_to_cart( $product_id ) {

		if( $product_id === 0 ){
			return '';
		}

		$url  = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/cart/add-item?id=' . $product_id . '&quantity=1';
		$args = array(
			'headers' => array(
				'nonce' => $this->nonce
			),
			'timeout' => 20
		);

		$response = wp_safe_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			Logger::log( 'Unable to fetch nonce during add to cart.' );
			Logger::log( print_r( $response, true ));
			return $response;
		}

		$this->cookies = $response['cookies'];

		$body = wp_remote_retrieve_body( $response );

		return $body;

	}

	private function handle_error_response( $response ) {
		Logger::log( 'Error occurred during order build.' . $response->get_error_message() );
	}

}