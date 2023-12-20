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

		$settings = Order_Generator::get_settings();

		if( $settings['enable_debug'] !== 'yes' ){
			return;
		}

		if ( empty( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}

		//todo esc for db
		if( is_wp_error( $message )){

			self::$logger->debug( $message->get_code(), array( 'source' => self::LOG_NAME ) );
			self::$logger->error( $message->get_all_error_messages(), array( 'source' => self::LOG_NAME ) );
			return;
		}

		self::$logger->debug( print_r( $message, true ), array( 'source' => self::LOG_NAME ) );
	}
}