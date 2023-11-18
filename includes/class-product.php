<?php
/**
 *
 */

namespace Happy_Order_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Product {


	/**
	 * Product The instance of Product
	 *
	 * @var    object
	 * @access  private
	 * @since    1.0.0
	 */
	private static object $instance;

	public array $settings = array();

	/**
	 * Main Product Instance
	 *
	 * Ensures only one instance of Product is loaded or can be loaded.
	 *
	 * @return Product instance
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
	 * Gets and array of product IDs to add to the cart
	 *
	 * @return array
	 */
	public function get_products_for_cart(): array {

		if ( isset( $this->settings['products'] ) && ! empty( $this->settings['products'] ) ) {
			$product_ids = $this->settings['products'];
			//Logger::log( 'Products are set to ' . print_r( $product_ids, true ));
		} else {
			$product_ids = $this->get_product_ids();
		}

		return $product_ids;
	}

	/**
	 * Returns an array of product IDs
	 *
	 * @return array
	 */
	public function get_product_ids(): array {

		/**
		 * Get th number of products to put in the cart. This is randomized according to settings
		 */
		$number_of_products = $this->get_number_of_products();

		$product_ids = array();

		// add random products to cart
		for ( $i = 0; $i < $number_of_products; $i ++ ) {
			$product_ids[] = $this->get_random_product_id();
		}

		return $product_ids;
	}

	/**
	 * Returns the product id of a single random product of one of the supported
	 * product types. By default, simple, variable and subscription and variable
	 * subscription products are supported.
	 * The filter 'hog_get_random_product_id' provides the current product and product ID.
	 *
	 * @return int
	 */
	public function get_random_product_id(): int {

		$product_types = array(
			'simple', 'variable', 'subscription', 'variable_subscription'
			);

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => 1,
			'orderby'        => 'rand',
			'tax_query'      => array(
				array(
					'taxonomy' => 'product_type',
					'field'    => 'slug',
					'terms'    => $product_types
				),
			),
		);

		$posts = get_posts( $args );

		if ( ! empty( $posts ) && ! is_wp_error( $posts ) ) {
			$product = wc_get_product( $posts[0]->ID );
		}else{
			return 0;
		}

		$type = $product->get_type();

		switch ( $type ) {
			case 'variable':
				$variations = $product->get_children();
				$index      = rand( 0, count( $variations ) - 1 );
				$product_id = (int) $variations[ $index ];
				break;
			case 'simple':
			case 'subscription':
				$product_id = $product->get_id();
				break;
			default:
				$product_id = 0;
				break;
		}

		return apply_filters( 'hog_get_random_product_id', $product_id, $product );
	}

	/**
	 * Returns the number of products to be put into the cart. If a maximum and
	 * minimum number of products is set, a random number between the max and min
	 * is returned
	 *
	 * @return int
	 */
	private function get_number_of_products() {

		$number_of_products = 1;
		$max_order_products = ( isset( $this->settings['max_order_products'] ) ) ? $this->settings['max_order_products'] : 1;

		if ( $max_order_products > 1 ) {
			$min_order_products = ( isset( $this->settings['min_order_products'] ) ) ? $this->settings['min_order_products'] : 1;
			$number_of_products = rand( $min_order_products, $max_order_products );
		}

		return $number_of_products;
	}
}

Product::instance();
