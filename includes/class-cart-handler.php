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
		Logger::info( 'Cart_Handler initialized with HTTP client' );
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

			Logger::log_cart_operation( 'Processing cart items', $cart_items, [
				'total_items' => count( $cart_items ),
				'bundle_count' => count( $separated_items['bundles'] ),
				'regular_count' => count( $separated_items['regular_products'] )
			] );

			// Add bundles with single requests
			if ( ! empty( $separated_items['bundles'] ) ) {
				Logger::log( 'Cart_Handler: Adding ' . count( $separated_items['bundles'] ) . ' bundle(s)' );
				foreach ( $separated_items['bundles'] as $bundle_item ) {
					$this->add_bundle_to_cart( $bundle_item );
				}
			}
			
			// Add regular products with batch request
			if ( ! empty( $separated_items['regular_products'] ) ) {
				Logger::log( 'Cart_Handler: Adding ' . count( $separated_items['regular_products'] ) . ' regular product(s)' );
				$this->add_products_to_cart( $separated_items['regular_products'] );
			}
			
			// Return final cart state
			return $this->get_cart_state();
			
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
				Logger::log( 'Cart_Handler: No payment methods found in cart response' );
				return false;
			}
			
			Logger::log( 'Cart_Handler: Retrieved cart state', [
				'payment_methods_count' => count( $cart_data['payment_methods'] ),
				'payment_methods' => $cart_data['payment_methods']
			] );
			
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

		Logger::info( 'Separated cart items', [
			'bundle_count' => count( $bundles ),
			'regular_count' => count( $regular_products )
		] );
		
		return [
			'bundles' => $bundles,
			'regular_products' => $regular_products
		];
	}

	/**
	 * Add a bundle product to cart using single request
	 * 
	 * Bundles MUST use single requests because:
	 * 1. Bundle configuration data is complex and contains nested arrays/objects
	 * 2. The WooCommerce Store API batch endpoint processes requests through WordPress core's 
	 *    WP_REST_Server::serve_batch_request_v1() which converts JSON body to POST parameters
	 * 3. This conversion causes bundle_configuration to be lost or malformed when sent via batch
	 * 4. Single requests preserve the JSON structure exactly as needed by the bundles plugin
	 * 5. The bundles plugin expects bundle_configuration at the top level of the request body
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

		$result = $this->http_client->post( '/cart/add-item', $data );

		Logger::log_cart_operation( 'Bundle added successfully', [$bundle_item], [
			'bundle_id' => $bundle_item['id'],
			'quantity' => $data['quantity']
		] );

		return $result;
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
				'path' => '/cart/add-item',
				'method' => 'POST',
				'body' => $request_body
			];
		}
		
		$result = $this->http_client->batch( $requests );

		Logger::log_cart_operation( 'Regular products added via batch request', $regular_items, [
			'product_count' => count( $regular_items )
		] );

		return $result;
	}

	/**
	 * Clear the current cart
	 * 
	 * @return bool True on success, false on failure
	 */
	public function clear_cart() {
		try {
			$this->http_client->post( '/cart/clear' );
			Logger::info( 'Cart cleared successfully' );
			return true;
		} catch ( \Exception $e ) {
			Logger::log_exception( 'Error clearing cart', $e );
			return false;
		}
	}

	/**
	 * Get available payment methods for the current cart
	 * 
	 * @return array Available payment methods
	 */
	public function get_payment_methods() {
		try {
			$cart_data = $this->get_cart_state();
			
			if ( $cart_data === false ) {
				return [];
			}
			
			return $cart_data['payment_methods'] ?? [];
			
		} catch ( \Exception $e ) {
			Logger::log_exception( 'Error retrieving payment methods', $e );
			return [];
		}
	}
}
