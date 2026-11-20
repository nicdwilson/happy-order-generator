<?php
/**
 * PHPStan stubs for optional plugins Happy Order Generator integrates with
 * (WooCommerce Stripe Gateway, Woo Subscriptions). Only the symbols the plugin
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
