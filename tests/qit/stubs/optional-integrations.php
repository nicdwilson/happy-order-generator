<?php
/**
 * PHPStan stubs for optional plugins Happy Order Generator integrates with
 * (WooCommerce Stripe Gateway, Woo Subscriptions, Product Bundles). Only the symbols the plugin
 * uses are declared; signatures follow the upstream plugins.
 *
 * phpcs:ignoreFile
 */

class WC_Stripe_Exception extends Exception {
	public function __construct( $error_message = '', $localized_message = '' ) {}
	public function getLocalizedMessage() {}
}

class WC_Stripe_API {
	/**
	 * @param array  $request
	 * @param string $api
	 * @param string $method
	 * @param bool   $with_headers
	 * @return stdClass|array
	 * @throws WC_Stripe_Exception
	 */
	public static function request( $request, $api = 'charges', $method = 'POST', $with_headers = false ) {}
}

class WC_Stripe_Helper {
	/** @return array */
	public static function get_stripe_amount( $total, $currency = '' ) {}
}

/**
 * @param mixed $subscription
 * @return bool
 */
function wcs_is_subscription( $subscription ) {}

/**
 * @param mixed  $order
 * @param array  $order_type
 * @return bool
 */
function wcs_order_contains_renewal( $order ) {}

/*
 * WooCommerce Product Bundles (8.x). Only the symbols the plugin uses.
 */

class WC_Product_Bundle extends WC_Product {
	/**
	 * @param string $context
	 * @return array<int, WC_Bundled_Item> Keyed by bundled item ID.
	 */
	public function get_bundled_items( $context = 'view' ) {}
}

class WC_Bundled_Item {
	/** @return int Bundled item ID. */
	public function get_id() {}
	/** @return int ID of the bundled product. */
	public function get_product_id() {}
	/** @return bool */
	public function is_optional() {}
	/** @return bool */
	public function is_variable() {}
	/** @return int[] */
	public function get_product_variations() {}
	/**
	 * @param string $type min|max|default
	 * @param array  $args
	 * @return int|string '' when there is no maximum.
	 */
	public function get_quantity( $type = 'min', $args = array() ) {}
}

/** @return object */
function WC_PB() {}

/**
 * @param int|WC_Bundled_Item $item
 * @param WC_Product_Bundle|false $parent
 * @return WC_Bundled_Item|false
 */
function wc_pb_get_bundled_item( $item, $parent = false ) {}
