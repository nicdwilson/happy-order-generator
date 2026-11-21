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
		'variable-subscription',
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
				case 'variable-subscription':
					$variations   = $product->get_children();
					$index        = rand( 0, count( $variations ) - 1 );
					$variation_id = (int) $variations[ $index ];
					$variation    = wc_get_product( $variation_id );
					break;
				case 'bundle':
					$bundle_config = $this->generate_bundle_configuration( $product );
					// A bundle that can't be bought would fail the whole order at add to cart.
					if ( null === $bundle_config ) {
						continue 2;
					}
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
	 * How far above its minimum a bundled item's quantity can go when it has no maximum.
	 */
	const UNLIMITED_QUANTITY_RANGE = 2;

	/**
	 * Generates a random configuration that Product Bundles will accept for a bundle.
	 *
	 * Required items are always included and optional items are included at random.
	 * Quantities stay within each item's limits and the bundle's min/max item limits,
	 * variable items get an in-stock variation, and items that can't be bought are
	 * left out. At least one item is always included, because Product Bundles
	 * rejects an empty selection.
	 *
	 * @param \WC_Product_Bundle $bundle The bundle product.
	 * @return array|null Store API bundle_configuration rows, or null if the bundle can't be bought.
	 */
	private function generate_bundle_configuration( $bundle ): ?array {

		if ( ! $bundle || ! $bundle->is_type( 'bundle' ) || ! $bundle->is_purchasable() || ! $bundle->is_in_stock() ) {
			return null;
		}

		$candidates = array();

		foreach ( $bundle->get_bundled_items() as $bundled_item_id => $bundled_item ) {
			$candidate = $this->get_bundled_item_candidate( $bundled_item_id, $bundled_item );

			if ( null === $candidate ) {
				if ( ! $bundled_item->is_optional() ) {
					Logger::log( 'Skipping bundle ' . $bundle->get_id() . ': required item ' . $bundled_item_id . ' is unavailable' );
					return null;
				}
				continue;
			}

			$candidate['selected'] = ! $candidate['optional'] || 1 === wp_rand( 0, 1 );
			$candidate['quantity'] = wp_rand( $candidate['min'], $candidate['max'] );
			$candidates[]          = $candidate;
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		// Product Bundles rejects a bundle with nothing selected.
		if ( ! in_array( true, array_column( $candidates, 'selected' ), true ) ) {
			$candidates[ array_rand( $candidates ) ]['selected'] = true;
		}

		$candidates = $this->fit_bundle_size( $bundle, $candidates );

		if ( null === $candidates ) {
			Logger::log( 'Skipping bundle ' . $bundle->get_id() . ': its item limits can\'t be met' );
			return null;
		}

		$configuration = array();

		foreach ( $candidates as $candidate ) {
			if ( ! $candidate['selected'] ) {
				continue;
			}

			$row = array(
				// The bundled item's own ID (the key of get_bundled_items()), not the ID
				// of the product it bundles: Product Bundles rejects anything else.
				'bundled_item_id' => $candidate['bundled_item_id'],
				'quantity'        => $candidate['quantity'],
			);

			if ( $candidate['optional'] ) {
				$row['optional_selected'] = 'yes';
			}

			if ( ! empty( $candidate['variation'] ) ) {
				$row['variation_id'] = $candidate['variation']['variation_id'];
				$row['attributes']   = $candidate['variation']['attributes'];
			}

			$configuration[] = $row;
		}

		return $configuration;
	}

	/**
	 * Works out what can be chosen for one bundled item.
	 *
	 * @param int              $bundled_item_id The bundled item's ID.
	 * @param \WC_Bundled_Item $bundled_item    The bundled item.
	 * @return array|null The item's quantity range and chosen variation, or null if it can't be bought.
	 */
	private function get_bundled_item_candidate( int $bundled_item_id, $bundled_item ): ?array {

		if ( ! $bundled_item->is_purchasable() || ! $bundled_item->is_in_stock() ) {
			return null;
		}

		$min       = max( 1, (int) $bundled_item->get_quantity( 'min' ) );
		$max       = $bundled_item->get_quantity( 'max', array( 'bound_by_stock' => true ) );
		$variation = null;

		if ( $bundled_item->product->is_type( array( 'variable', 'variable-subscription' ) ) ) {
			$variation = $this->choose_bundled_variation( $bundled_item );

			if ( null === $variation ) {
				return null;
			}

			// Stock bounds for variations aren't included in get_quantity().
			if ( '' !== $variation['available'] ) {
				$max = '' === $max ? $variation['available'] : min( (int) $max, $variation['available'] );
			}
		}

		// No maximum: allow a small range above the minimum rather than always the minimum.
		$max = '' === $max ? $min + self::UNLIMITED_QUANTITY_RANGE : (int) $max;

		if ( $max < $min ) {
			return null;
		}

		return array(
			'bundled_item_id' => $bundled_item_id,
			'optional'        => $bundled_item->is_optional(),
			'min'             => $min,
			'max'             => $max,
			'variation'       => $variation,
		);
	}

	/**
	 * Chooses an in-stock variation for a variable bundled item.
	 *
	 * Every attribute is sent, including the ones the variation fixes, so this works
	 * with Product Bundles versions before 8.6.0, which don't fill those in.
	 * Attributes the variation leaves as "Any" get a random value.
	 *
	 * @param \WC_Bundled_Item $bundled_item The bundled item.
	 * @return array|null The variation ID, its attributes and available stock, or null if none can be bought.
	 */
	private function choose_bundled_variation( $bundled_item ): ?array {

		// get_product_variations() respects the bundle's allowed-variations filter.
		$variations = array_filter(
			(array) $bundled_item->get_product_variations(),
			static function ( $variation ) {
				return ! empty( $variation['variation_id'] )
					&& ! empty( $variation['is_in_stock'] )
					&& ! empty( $variation['is_purchasable'] )
					&& ( ! isset( $variation['variation_is_active'] ) || $variation['variation_is_active'] );
			}
		);

		if ( empty( $variations ) ) {
			return null;
		}

		$variation = $variations[ array_rand( $variations ) ];
		$options   = array();

		foreach ( (array) $bundled_item->get_product_variation_attributes() as $attribute_name => $attribute_options ) {
			$options[ sanitize_title( $attribute_name ) ] = array_values( (array) $attribute_options );
		}

		$attributes = array();

		foreach ( (array) $variation['attributes'] as $key => $value ) {
			// Keys look like attribute_pa_color; Product Bundles adds the prefix back.
			$name = 0 === strpos( $key, 'attribute_' ) ? substr( $key, strlen( 'attribute_' ) ) : $key;

			if ( '' === (string) $value ) {
				if ( empty( $options[ $name ] ) ) {
					return null;
				}
				$value = $options[ $name ][ array_rand( $options[ $name ] ) ];
			}

			$attributes[] = array(
				'name'   => $name,
				'option' => (string) $value,
			);
		}

		$available = isset( $variation['avail_qty'] ) && is_numeric( $variation['avail_qty'] ) ? (int) $variation['avail_qty'] : '';

		return array(
			'variation_id' => (int) $variation['variation_id'],
			'attributes'   => $attributes,
			'available'    => $available,
		);
	}

	/**
	 * Adjusts a selection to the bundle's min/max item limits (the Min/Max Items module).
	 *
	 * Too few items: adds optional items, then raises quantities. Too many: lowers
	 * quantities, then drops optional items.
	 *
	 * @param \WC_Product_Bundle $bundle     The bundle product.
	 * @param array              $candidates The bundled item candidates.
	 * @return array|null The adjusted candidates, or null if the limits can't be met.
	 */
	private function fit_bundle_size( $bundle, array $candidates ): ?array {

		$min_size = method_exists( $bundle, 'get_min_bundle_size' ) ? $bundle->get_min_bundle_size() : '';
		$max_size = method_exists( $bundle, 'get_max_bundle_size' ) ? $bundle->get_max_bundle_size() : '';

		$total = static function ( array $candidates ): int {
			$count = 0;
			foreach ( $candidates as $candidate ) {
				$count += $candidate['selected'] ? $candidate['quantity'] : 0;
			}
			return $count;
		};

		if ( '' !== $min_size && null !== $min_size ) {
			$min_size = (int) $min_size;

			foreach ( array_keys( $candidates ) as $key ) {
				if ( $total( $candidates ) >= $min_size ) {
					break;
				}
				if ( ! $candidates[ $key ]['selected'] ) {
					$candidates[ $key ]['selected'] = true;
					$candidates[ $key ]['quantity'] = $candidates[ $key ]['min'];
				}
			}

			foreach ( array_keys( $candidates ) as $key ) {
				$shortfall = $min_size - $total( $candidates );
				if ( $shortfall <= 0 ) {
					break;
				}
				if ( $candidates[ $key ]['selected'] ) {
					$candidates[ $key ]['quantity'] = min( $candidates[ $key ]['max'], $candidates[ $key ]['quantity'] + $shortfall );
				}
			}

			if ( $total( $candidates ) < $min_size ) {
				return null;
			}
		}

		if ( '' !== $max_size && null !== $max_size ) {
			$max_size = (int) $max_size;

			foreach ( array_keys( $candidates ) as $key ) {
				$excess = $total( $candidates ) - $max_size;
				if ( $excess <= 0 ) {
					break;
				}
				if ( $candidates[ $key ]['selected'] ) {
					$candidates[ $key ]['quantity'] = max( $candidates[ $key ]['min'], $candidates[ $key ]['quantity'] - $excess );
				}
			}

			foreach ( array_keys( $candidates ) as $key ) {
				if ( $total( $candidates ) <= $max_size ) {
					break;
				}
				$remaining = array_filter( array_column( $candidates, 'selected' ) );
				if ( $candidates[ $key ]['optional'] && $candidates[ $key ]['selected'] && count( $remaining ) > 1 ) {
					$candidates[ $key ]['selected'] = false;
				}
			}

			if ( $total( $candidates ) > $max_size ) {
				return null;
			}
		}

		return $candidates;
	}
}

Product::instance();
