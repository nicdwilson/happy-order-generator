<?php
/**
 * Cart Handler for Order Builder
 * 
 * Handles all cart operations including mixed cart items (bundles vs regular products),
 * single vs batch requests, and cart state retrieval.
 * 
 * @package Happy_Order_Generator
 * @since 1.0.0
 */

namespace Happy_Order_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Cart_Handler {

	/**
	 * HTTP client for API requests
	 *
	 * @var HTTP_Client
	 */
	private HTTP_Client $http_client;

	/**
	 * Constructor
	 * 
	 * @param HTTP_Client $http_client HTTP client instance
	 */
	public function __construct( HTTP_Client $http_client ) {
		$this->http_client = $http_client;
	}

	/**
	 * Main entry point to add items to cart
	 * 
	 * @param array $cart_items Array of cart items to add
	 * @return array|false Cart state after adding items or false on error
	 */
	public function add_to_cart( array $cart_items ) {
		if ( empty( $cart_items ) ) {
			Logger::warning( 'No cart items provided' );
			return false;
		}

		try {
			// Separate bundle items from regular products
			$separated_items = $this->separate_cart_items( $cart_items );

			// Add bundles with single requests
			if ( ! empty( $separated_items['bundles'] ) ) {
				foreach ( $separated_items['bundles'] as $bundle_item ) {
					$this->add_bundle_to_cart( $bundle_item );
				}
			}
			
			// Add regular products with batch request
			if ( ! empty( $separated_items['regular_products'] ) ) {
				$this->add_products_to_cart( $separated_items['regular_products'] );
			}
			
			$cart_state = $this->get_cart_state();

			/**
			 * The add-item and batch endpoints report failures in the response
			 * body rather than by throwing, so check the cart really has items
			 * before anything goes on to checkout.
			 */
			if ( false !== $cart_state && empty( $cart_state['items'] ) ) {
				Logger::error( 'Cart is empty after adding items', [
					'cart_items' => $cart_items
				] );
				return false;
			}

			return $cart_state;
			
		} catch ( \Exception $e ) {
			Logger::log_exception( 'Error adding items to cart', $e, [
				'cart_items' => $cart_items
			] );
			return false;
		}
	}

	/**
	 * Get the current cart state including payment methods
	 * 
	 * @return array|false Cart state with payment methods or false on error
	 */
	public function get_cart_state() {
		try {
			$cart_data = $this->http_client->get( '/cart' );
			
			if ( ! isset( $cart_data['payment_methods'] ) || ! is_array( $cart_data['payment_methods'] ) ) {
				Logger::warning( 'No payment methods found in cart response' );
				return false;
			}
			
			return $cart_data;
			
		} catch ( \Exception $e ) {
			Logger::log_exception( 'Error retrieving cart state', $e );
			return false;
		}
	}

	/**
	 * Check if a cart item is a bundle
	 * 
	 * @param array $item Cart item array
	 * @return bool True if item is a bundle
	 */
	private function is_bundle_item( array $item ) {
		return isset( $item['bundle_configuration'] ) && ! empty( $item['bundle_configuration'] );
	}

	/**
	 * Separate bundle items from regular products
	 * 
	 * @param array $cart_items Array of cart items
	 * @return array Separated items with 'bundles' and 'regular_products' keys
	 */
	private function separate_cart_items( array $cart_items ) {
		$bundles = [];
		$regular_products = [];
		
		foreach ( $cart_items as $item ) {
			if ( $this->is_bundle_item( $item ) ) {
				$bundles[] = $item;
			} else {
				$regular_products[] = $item;
			}
		}

		return [
			'bundles' => $bundles,
			'regular_products' => $regular_products
		];
	}

	/**
	 * Add a bundle product to cart using single request
	 * 
	 * Bundles must use single requests, not /batch (checked November 2026 against
	 * WordPress 7.1 and Product Bundles 8.5):
	 * - WP_REST_Server::serve_batch_request_v1() passes each sub-request's body
	 *   with set_body_params(). The nested bundle_configuration arrives intact
	 *   there, but the sub-request has no raw JSON body.
	 * - Product Bundles reads the configuration with get_json_params() only
	 *   (WC_PB_Cart::handle_store_api_add_to_cart_request()), so inside a batch
	 *   it never sees it. The bundle is then added with its default
	 *   configuration and a 201, even when the submitted configuration is
	 *   invalid.
	 * If Product Bundles reads get_param( 'bundle_configuration' ) instead, bundles
	 * can go back into the batch with everything else.
	 *
	 * @param array $bundle_item Bundle item data
	 * @return array Response from the request
	 * @throws \Exception On request failure
	 */
	private function add_bundle_to_cart( array $bundle_item ) {
		$data = [
			'id' => $bundle_item['id'],
			'quantity' => $bundle_item['quantity'] ?? 1,
			'bundle_configuration' => $bundle_item['bundle_configuration']
		];
		
		// Add variation if present
		if ( isset( $bundle_item['variation'] ) ) {
			$data['variation'] = $bundle_item['variation'];
		}

		return $this->http_client->post( '/cart/add-item', $data );
	}

	/**
	 * Add regular products to cart using batch request
	 * 
	 * @param array $regular_items Array of regular product items
	 * @return array Response from the batch request
	 * @throws \Exception On request failure
	 */
	private function add_products_to_cart( array $regular_items ) {
		$requests = [];
		
		foreach ( $regular_items as $item ) {
			$request_body = [
				'id' => $item['id'],
				'quantity' => $item['quantity'] ?? 1
			];
			
			// Add variation if present
			if ( isset( $item['variation'] ) ) {
				$request_body['variation'] = $item['variation'];
			}
			
			$requests[] = [
				// Batch sub-requests need the full route, not one relative to the Store API base.
				'path' => '/wc/store/v1/cart/add-item',
				'method' => 'POST',
				'body' => $request_body,
				// Each sub-request is checked for its own nonce.
				'headers' => [
					'Nonce' => (string) $this->http_client->get_nonce(),
				],
			];
		}
		
		return $this->http_client->batch( $requests );
	}
}
