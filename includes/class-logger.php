<?php
/**
 *
 */

namespace Happy_Order_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Logger {


	public static object $logger;

	const LOG_NAME = 'happy-order-generator';

	/**
	 * WC logger
	 *
	 */
	public static function log( $message ): void {

		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		if ( empty( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}

		self::$logger->debug( $message, array( 'source' => self::LOG_NAME ) );
	}
}