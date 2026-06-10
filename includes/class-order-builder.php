<?php
/**
 * Order Builder class uses the Store API.
 */

namespace Happy_Order_Generator;

use WP_Error;

class Order_Builder {

	/**
	 * @var HTTP_Client
	 */
	private HTTP_Client $http_client;

	/**
	 * @var Cart_Handler
	 */
	private Cart_Handler $cart_handler;

	/**
	 * @var string
	 */
	private string $nonce;

	/**
	 * @var bool
	 */
	private bool $skip_ssl = false;

	/**
	 * @var array
	 */
	private array $cookies = [];

	public function __construct() {
		$settings = Order_Generator::get_settings();
		if ( $settings['skip_ssl'] === 1 ) {
			$this->skip_ssl = true;
		}

		// Initialize HTTP client
		$this->http_client = new HTTP_Client(
			get_bloginfo( 'url' ) . '/wp-json/wc/store/v1',
			$this->skip_ssl
		);

		// Get and validate nonce
		$this->nonce = $this->get_nonce();
		if ( $this->nonce === false ) {
			Logger::warning( 'Failed to get nonce on first attempt, retrying...' );
			sleep( 3 );
			$this->nonce = $this->get_nonce();
		}

		if ( ! $this->nonce ) {
			Logger::error( 'Unable to fetch nonce when initialising the order builder.' );
			throw new \Exception( 'Failed to obtain nonce for Store API' );
		}

		// Authenticate HTTP client with nonce
		$this->http_client->authenticate( $this->nonce );

		// Initialize cart handler with HTTP client
		$this->cart_handler = new Cart_Handler( $this->http_client );

		Logger::info( 'Order_Builder initialized successfully with nonce' );
	}

	/**
	 * Get a nonce from the Store API
	 *
	 * @return false|string
	 */
	private function get_nonce(): bool|string {
		$url  = get_bloginfo( 'url' ) . '/wp-json/wc/store/v1/cart';
		$args = array(
			'timeout' => 20
		);

		if ( $this->skip_ssl ) {
			add_filter( 'https_ssl_verify', '__return_false' );
		}
		$response = wp_safe_remote_get( $url, $args );
		remove_filter( 'https_ssl_verify', '__return_false' );

		if ( is_wp_error( $response ) ) {
			Logger::log_exception( 'Error fetching nonce', $response );
			return false;
		}

		$headers = wp_remote_retrieve_headers( $response );
		$nonce = isset( $headers['nonce'] ) ? $headers['nonce'] : false;

		if ( $nonce ) {
			Logger::info( 'Nonce obtained successfully' );
		} else {
			Logger::warning( 'No nonce found in response headers' );
		}

		return $nonce;
	}

	/**
	 * Get the current cart state including payment methods
	 *
	 * @return array|false Cart state with payment methods or false on error
	 */
	public function get_cart_state(): array|false {
		try {
			return $this->cart_handler->get_cart_state();
		} catch ( \Exception $e ) {
			Logger::log_exception( 'Error retrieving cart state', $e );
			return false;
		}
	}

	/**
	 * Add items to the current cart using the Cart Handler.
	 * Handles both bundle and regular products with appropriate request methods.
	 *
	 * @param array $cart_items Array of cart items to add
	 * @return array|false Cart state with payment methods or false on error
	 */
	public function add_to_cart( array $cart_items = array() ): array|false {
		if ( empty( $cart_items ) ) {
			Logger::warning( 'No cart items provided' );
			return false;
		}

		Logger::info( 'Processing cart items via Cart Handler', [
			'item_count' => count( $cart_items )
		] );
		
		try {
			$cart_state = $this->cart_handler->add_to_cart( $cart_items );
			
			if ( $cart_state === false ) {
				Logger::warning( 'Cart handler returned false' );
				return false;
			}

			Logger::info( 'Cart state retrieved', [
			'cart_state_keys' => is_array( $cart_state ) ? array_keys( $cart_state ) : ['not_array']
		] );
			
			return $cart_state;
			
		} catch ( \Exception $e ) {
			Logger::log_exception( 'Error adding items to cart', $e, [
				'cart_items' => $cart_items
			] );
			return false;
		} catch ( \Error $e ) {
			Logger::log_exception( 'Fatal error adding items to cart', $e, [
				'cart_items' => $cart_items
			] );
			return false;
		}
	}

	/**
	 * Do checkout, creating the order
	 *
	 * @param array $options
	 * $options[user_id]
	 * $options['payment_method']
	 *
	 * @return object WP_Order or WP_Error
	 */
	public function do_checkout( $options ): object {
		$user = get_userdata( $options['user_id'] );

		if( ! $user ){
			Logger::error( 'User not found for checkout', [
			'user_id' => $options['user_id']
		] );
			return new WP_Error( 'user_not_found', 'User not found' );
		}

		$first_name = $user->get('first_name');
		$last_name = $user->get('last_name');
		$billing_address_1 = $user->get('billing_address_1');
		$billing_city = $user->get('billing_city');
		$billing_state = $user->get('billing_state');
		$billing_postcode = $user->get('billing_postcode');
		$billing_country = $user->get('billing_country');
		$billing_email = $user->get('billing_email');
		$billing_phone = $user->get('billing_phone');
		$shipping_address_1 = $user->get('shipping_address_1');
		$shipping_city = $user->get('shipping_city');
		$shipping_state = $user->get('shipping_state');
		$shipping_postcode = $user->get('shipping_postcode');
		$shipping_country = $user->get('shipping_country');

		$body = array(
			'billing_address'  => array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'company'    => '',
				'address_1'  => $billing_address_1,
				'address_2'  => '',
				"city"       => $billing_city,
				"state"      => $billing_state,
				"postcode"   => $billing_postcode,
				"country"    => $billing_country,
				"email"      => $billing_email,
				"phone"      => $billing_phone
			),
			'shipping_address' => array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'company'    => '',
				'address_1'  => $shipping_address_1,
				'address_2'  => '',
				'city'       => $shipping_city,
				'state'      => $shipping_state,
				'postcode'   => $shipping_postcode,
				'country'    => $shipping_country,
			),
			'customer_note'    => '',
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
					'value' => $user->get('billing_email'),
				),
				array(
					'key'   => 'billing_first_name',
					"value" => $user->get('first_name'),
				),
				array(
					"key"   => "billing_last_name",
					"value" => $user->get('last_name'),
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

		try {
			Logger::info( 'Creating order via checkout endpoint' );
			
			$response_data = $this->http_client->post( '/checkout', $body );

			Logger::log( 'Checkout response: ' . print_r( $response_data, true ) );
			
			Logger::info( 'Checkout response received', [
				'has_order_id' => isset( $response_data['order_id'] ),
				'response_keys' => array_keys( $response_data )
			] );

			/**
			 * Bail if we're broken
			 */
			if ( ! isset( $response_data['order_id'] ) ) {
				$message = 'Order creation failed at checkout.\n';

				if ( isset( $response_data['code'] ) ) {
					$code = $response_data['code'];
				} else {
					$code = 'Unknown error';
					$message .= 'Response: ' . print_r( $response_data, true );
				}

				if ( isset( $response_data['message'] ) ) {
					$message .= 'An error occurred: ' . sanitize_text_field( $response_data['message'] );
				}
				
				if ( 'rest_invalid_param' === $code ) {
					$message .= 'Invalid data was returned.\n';
				}

				if ( str_contains( $code, '_missing_' ) ) {
					$message .= 'Data is missing. We provided:\n';
					$message .= json_encode( $body );
				}

				Logger::error( 'Order creation failed', [
					'error_code' => $code,
					'error_message' => $message
				] );

				$order = new WP_Error( $code, $message );

			} else {
				$order_id = $response_data['order_id'];
				$order = wc_get_order( $order_id );

				if( ! is_a( $order, 'WC_Order') ){
					Logger::error( 'Order ID failed to return a valid order', [
						'order_id' => $order_id
					] );
					$order = new WP_Error( 'invalid_order_id', 'Order ID failed to return an order' );
				} else {
					Logger::info( 'Order created successfully', [
						'order_id' => $order_id
					] );
				}
			}

		} catch ( \Exception $e ) {
			Logger::log_exception( 'Exception during checkout', $e, [
				'user_id' => $options['user_id'],
				'payment_method' => $options['payment_method']
			] );
			$order = new WP_Error( 'checkout_exception', 'Checkout failed: ' . $e->getMessage() );
		}

		return $order;
	}

	/**
	 * Refresh the nonce if it has expired
	 * 
	 * @return bool True if nonce was refreshed, false otherwise
	 */
	public function refresh_nonce(): bool {
		Logger::info( 'Attempting to refresh nonce' );
		
		$new_nonce = $this->get_nonce();
		if ( $new_nonce ) {
			$this->nonce = $new_nonce;
			$this->http_client->authenticate( $new_nonce );
			Logger::info( 'Nonce refreshed successfully' );
			return true;
		}
		
		Logger::error( 'Failed to refresh nonce' );
		return false;
	}

	/**
	 * Get the current nonce
	 * 
	 * @return string|null The current nonce or null if not set
	 */
	public function get_current_nonce(): ?string {
		return $this->nonce;
	}

	/**
	 * Check if the order builder is properly authenticated
	 * 
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		return ! empty( $this->nonce ) && $this->http_client->is_authenticated();
	}
}
