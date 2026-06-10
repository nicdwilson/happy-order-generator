<?php
/**
 *
 */

namespace Happy_Order_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 *
 */
class Product {

	/**
	 * todo add filter which allows the addition of product types
	 * specifically - can we support subscriptions without a subscription capable
	 * gateway?
	 *
	 * @var array|string[]
	 */
	public array $product_types = array(
		'simple',
		'variable',
		'subscription',
		'variable_subscription',
		'variation',
		'bundle'
	);


	/**
	 * The number of products for the cart
	 *
	 * @var int
	 */
	private int $number_of_products;

	/**
	 * Product The instance of Product
	 *
	 * @var    object
	 * @access  private
	 * @since    1.0.0
	 */
	private static object $instance;

	/**
	 * The plugin settings
	 *
	 * @var array
	 */
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

		$this->settings           = Order_Generator::get_settings();
		$this->number_of_products = $this->get_number_of_products();
		
		// Check if bundle support is available
		if ( ! $this->has_bundle_support() ) {
			// Remove bundle from supported types if not available
			$this->product_types = array_diff( $this->product_types, array( 'bundle' ) );
		}
	}

	/**
	 * Check if WooCommerce Product Bundles plugin is active and available
	 *
	 * @return bool
	 */
	private function has_bundle_support(): bool {
		// Check if the Product Bundles plugin is active
		if ( ! class_exists( 'WC_Product_Bundle' ) ) {
			return false;
		}

		// Check if bundle functions are available
		if ( ! function_exists( 'WC_PB' ) && ! function_exists( 'wc_pb_get_bundled_item' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Public method to check bundle support (can be called later if timing is an issue)
	 *
	 * @return bool
	 */
	public function check_bundle_support(): bool {
		return $this->has_bundle_support();
	}

	/**
	 * Dynamically add bundle support if it becomes available
	 *
	 * @return void
	 */
	public function add_bundle_support_if_available(): void {
		if ( $this->has_bundle_support() && ! in_array( 'bundle', $this->product_types, true ) ) {
			$this->product_types[] = 'bundle';
		}
	}

	/**
	 * Gets an array of product items formatted ready to add to the cart via the Store API.
	 *
	 * @return array
	 *
	 *  ['id'] => the product id of the rpdoct to be added to the cart
	 *  ['quantity'] => the quantity of the product to the added to the cart
	 *  ['variation'] => variation data (if this is a variation)
	 *  ['variation'][]['attribute'] => attribute name
	 *  ['variation'][]['value'] => attribute value
	 *  See add-to-cart endpoint documentation
	 *  https://github.com/woocommerce/woocommerce-blocks/blob/trunk/src/StoreApi/docs/cart.md
	 */
	public function get_products_for_cart(): array {

		if ( isset( $this->settings['products'] ) && ! empty( $this->settings['products'] ) ) {

			$product_ids   = $this->settings['products'];
			$cart_products = $this->get_cart_products( $product_ids );

			/**
			 * When the number of items selected in settings is higher than the number of products
			 * available for selection, we need to make sure the number of items meets the standard.
			 * We do this fairly crudely, by bumping the quantity of the first product in the cart.
			 */
			if ( $this->number_of_products > count( $cart_products ) ) {
				$diff                         = $this->number_of_products - count( $cart_products );
				$cart_products[0]['quantity'] = $diff + 1;
			}

		} else {
			$cart_products = $this->get_cart_products();
		}

		return $cart_products;
	}

	/**
	 * Returns a ready-to-add-to-cart array of products
	 * See add-to-cart endpoint documentation
	 * https://github.com/woocommerce/woocommerce-blocks/blob/trunk/src/StoreApi/docs/cart.md
	 *
	 * @param array $product_ids
	 *
	 * @return array
	 */
	public function get_cart_products( array $product_ids = array() ): array {

		if ( empty( $product_ids ) ) {
			$product_ids = $this->get_random_product_ids();
		}

		$cart_products = array();

		$args = array(
			'type'    => $this->product_types,
			'limit'   => $this->number_of_products,
			'include' => $product_ids
		);

		$products = wc_get_products( $args );

		if ( empty( $products ) || is_wp_error( $products ) ) {
			return array();
		}

		// Check for bundle support before processing products
		$this->add_bundle_support_if_available();

		foreach ( $products as $product ) {

			Logger::log( 'Processing product: ' . $product->get_id() . ' (' . $product->get_type() . ') - ' . $product->get_title() );

			$type         = $product->get_type();
			$variation_id = 0;
			$variation    = false;

			switch ( $type ) {
				case 'variation':
					$variation_id = $product->get_id();
					$variation    = wc_get_product( $variation_id );
					break;
				case 'variable':
					$variations   = $product->get_children();
					$index        = rand( 0, count( $variations ) - 1 );
					$variation_id = (int) $variations[ $index ];
					$variation    = wc_get_product( $variation_id );
					break;
				case 'bundle':
					// Handle bundle products - generate configuration
					$bundle_config = $this->generate_bundle_configuration( $product );
					break;
				default:
					break;
			}

			/**
			 * Minimum requirements are id and quantity
			 */
			$cart_product = array(
				'id'        => $product->get_id(),
				'quantity'  => 1,
			);

			/**
			 * If this is a variation we need to add variation
			 * data - each attribute name and value is added
			 */
			if ( $variation_id > 0 && $variation ) {

				$cart_product['id'] = $variation->get_id();

				foreach ( $variation->get_attributes() as $attribute_name => $attribute_value ) {

					/**
					 * A variation may have a missing attribute value if it has an 'Any' value set.
					 * If this is the case we need to go back to the parent and choose a random value.
					 */
					if ( empty( $attribute_value ) ) {
						$attribute_terms = wc_get_product_terms( $product->get_id(), $attribute_name );
						if ( ! empty( $attribute_terms ) ) {
							$key             = array_rand( $attribute_terms );
							$attribute_value = $attribute_terms[ $key ]->name;
						}
					}

					$cart_product['variation'][] = array(
						'attribute' => $attribute_name,
						'value'     => $attribute_value
					);
				}
			}

			/**
			 * If this is a bundle, add the bundle configuration
			 */
			if ( $type === 'bundle' && ! empty( $bundle_config ) ) {
				$cart_product['bundle_configuration'] = $bundle_config;
			}

			$cart_products[] = $cart_product;

		}

		Logger::log( 'Total products processed: ' . count( $cart_products ) );

		return apply_filters( 'hog_get_cart_products', $cart_products, $products );
	}

	/**
	 * Returns an array of random product IDs
	 *
	 * @return array An array of random product IDs.
	 */
	public function get_random_product_ids(): array {

		$args = array(
			'type'    => $this->product_types,
			'limit'   => $this->number_of_products,
			'orderby' => 'rand',
			'return'  => 'ids'
		);

		return wc_get_products( $args );
	}

	/**
	 * Returns the number of products to be put into the cart. If a maximum and
	 * minimum number of products is set, a random number between the max and min
	 * is returned
	 *
	 * @return int
	 */
	private function get_number_of_products(): int {

		$number_of_products = 1;
		$max_order_products = ( isset( $this->settings['max_order_products'] ) ) ? $this->settings['max_order_products'] : 1;

		if ( $max_order_products > 1 ) {
			$min_order_products = ( isset( $this->settings['min_order_products'] ) ) ? $this->settings['min_order_products'] : 1;
			$number_of_products = rand( $min_order_products, $max_order_products );
		}

		return $number_of_products;
	}

	/**
	 * Generates a valid bundle configuration for a bundle product
	 *
	 * @param WC_Product_Bundle $bundle The bundle product
	 * @return array Bundle configuration array
	 */
	private function generate_bundle_configuration( $bundle ): array {
		$configuration = array();

		if ( ! $bundle || ! $bundle->is_type( 'bundle' ) ) {
			return $configuration;
		}

		$bundled_items = $bundle->get_bundled_items();
		
		if ( empty( $bundled_items ) ) {
			return $configuration;
		}

		foreach ( $bundled_items as $bundled_item_id => $bundled_item ) {

			$include_item = $include_item = rand( 0, 1 );
			
			if (  $bundled_item->is_optional() && $include_item === 0 ) {
				continue;
			}


			
			$item_config = array();
			$item_config['bundled_item_id'] = $bundled_item->get_product_id();

			// Handle optional items - randomly include them
			if ( $bundled_item->is_optional() && $include_item === 1 ) {
				$item_config['optional_selected'] = 'yes';
			}

			// Set quantity for the bundled item
			$quantity_min = ( $bundled_item->get_quantity( 'min' ) < 1 ) ? 1 : $bundled_item->get_quantity( 'min' );
			$quantity_max = $bundled_item->get_quantity( 'max' );
			
			if ( $quantity_max > $quantity_min ) {
				$item_config['quantity'] = rand( $quantity_min, $quantity_max );
			} else {
				$item_config['quantity'] = $quantity_min;
			}
			/**
			// Handle variable products within bundles
			if ( $bundled_item->is_variable() ) {
				$variations = $bundled_item->get_product_variations();
				if ( ! empty( $variations ) ) {
					$random_variation = array_rand( $variations );
					$variation_id = $variations[ $random_variation ];
					$item_config['variation_id'] = $variation_id;

					Logger::log( 'Variable item ' . $bundled_item_id . ' - selected variation: ' . $variation_id );

					// Get variation attributes
					$variation = wc_get_product( $variation_id );
					if ( $variation ) {
						$attributes = $variation->get_attributes();
						if ( ! empty( $attributes ) ) {
							$item_config['attributes'] = array();
							foreach ( $attributes as $attribute_name => $attribute_value ) {
								$item_config['attributes'][] = array(
									'name'   => $attribute_name,
									'option' => $attribute_value
								);
							}
							Logger::log( 'Variation attributes added for item ' . $bundled_item_id );
						}
					}
				}
			}
			*/
				

			$configuration[] = $item_config;
		}



		return $configuration;
	}
}

Product::instance();
