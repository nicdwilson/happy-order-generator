<?php
/**
 * Enhanced Logger for Happy Order Generator
 * 
 * Provides structured logging with context support and different log levels.
 * 
 * @package Happy_Order_Generator
 * @since 1.0.0
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
	 * @param mixed $message Message to log
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function log( $message, array $context = [] ): void {
		if ( ! class_exists( 'WC_Logger' ) ) {
			return;
		}

		$settings = Order_Generator::get_settings();

		if( ( $settings['enable_debug'] ?? 'no' ) !== 'yes' ){
			return;
		}

		if ( empty( self::$logger ) ) {
			self::$logger = wc_get_logger();
		}

		// Handle WP_Error objects
		if( is_wp_error( $message )){
			self::$logger->debug( $message->get_code(), array( 'source' => self::LOG_NAME ) );
			self::$logger->error( implode( ', ', $message->get_error_messages() ), array( 'source' => self::LOG_NAME ) );
			return;
		}

		// Build log message with context
		$log_message = $message;
		if ( ! empty( $context ) ) {
			$log_message .= ' | Context: ' . json_encode( $context );
		}

		self::$logger->debug( $log_message, array( 'source' => self::LOG_NAME ) );
	}

	/**
	 * Log an info message
	 *
	 * @param string $message Message to log
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function info( string $message, array $context = [] ): void {
		self::log( 'INFO: ' . $message, $context );
	}

	/**
	 * Log a warning message
	 *
	 * @param string $message Message to log
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function warning( string $message, array $context = [] ): void {
		self::log( 'WARNING: ' . $message, $context );
	}

	/**
	 * Log an error message
	 *
	 * @param string $message Message to log
	 * @param array $context Additional context data
	 * @return void
	 */
	public static function error( string $message, array $context = [] ): void {
		self::log( 'ERROR: ' . $message, $context );
	}

	/**
	 * Log API request details
	 *
	 * @param string $endpoint API endpoint
	 * @param string $method HTTP method
	 * @param array $data Request data
	 * @param array $response Response data
	 * @return void
	 */
	public static function log_api_request( string $endpoint, string $method, array $data = [], array $response = [] ): void {
		$context = [
			'endpoint' => $endpoint,
			'method' => $method,
			'data_keys' => array_keys( $data ),
			'response_keys' => array_keys( $response ),
			'timestamp' => current_time( 'mysql' )
		];

		self::info( 'API Request completed', $context );
	}

	/**
	 * Log cart operation details
	 *
	 * @param string $operation Operation performed
	 * @param array $items Items involved
	 * @param mixed $result Result of operation
	 * @return void
	 */
	public static function log_cart_operation( string $operation, array $items = [], $result = null ): void {
		$context = [
			'operation' => $operation,
			'items_count' => count( $items ),
			'result_type' => gettype( $result ),
			'timestamp' => current_time( 'mysql' )
		];

		if ( is_array( $result ) ) {
			$context['result_keys'] = array_keys( $result );
		}

		self::info( 'Cart operation completed', $context );
	}

	/**
	 * Log error with full context
	 *
	 * @param string $message Error message
	 * @param \Exception|\Error $exception Exception object
	 * @param array $additional_context Additional context data
	 * @return void
	 */
	public static function log_exception( string $message, $exception, array $additional_context = [] ): void {
		$context = array_merge( $additional_context, [
			'error_message' => $exception->getMessage(),
			'error_code' => method_exists( $exception, 'getCode' ) ? $exception->getCode() : 'N/A',
			'error_file' => $exception->getFile(),
			'error_line' => $exception->getLine(),
			'error_trace' => $exception->getTraceAsString(),
			'timestamp' => current_time( 'mysql' )
		] );

		self::error( $message, $context );
	}
}
