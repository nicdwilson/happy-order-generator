<?php
/**
 * HTTP Client for WooCommerce Store API
 * 
 * Centralized HTTP operations with authentication, SSL handling, and error recovery.
 * 
 * @package Happy_Order_Generator
 * @since 1.0.0
 */

namespace Happy_Order_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class HTTP_Client {

	/**
	 * @var string
	 */
	private string $base_url;

	/**
	 * @var string
	 */
	private string $nonce;

	/**
	 * @var bool
	 */
	private bool $skip_ssl;

	/**
	 * @var array
	 */
	private array $cookies = [];

	/**
	 * @var int
	 */
	private int $max_retries = 3;

	/**
	 * @var int
	 */
	private int $retry_delay = 1000; // milliseconds

	/**
	 * Constructor
	 * 
	 * @param string $base_url Base URL for the Store API
	 * @param bool $skip_ssl Whether to skip SSL verification
	 */
	public function __construct( string $base_url, bool $skip_ssl = false ) {
		$this->base_url = rtrim( $base_url, '/' );
		$this->skip_ssl = $skip_ssl;
	}

	/**
	 * Set the nonce for authenticated requests
	 * 
	 * @param string $nonce The nonce value
	 * @return void
	 */
	public function authenticate( string $nonce ): void {
		$this->nonce = $nonce;
		Logger::info( 'Nonce set for authentication' );
	}

	/**
	 * Get the current nonce
	 * 
	 * @return string|null The current nonce or null if not set
	 */
	public function get_nonce(): ?string {
		return $this->nonce ?? null;
	}

	/**
	 * Make a GET request to the Store API
	 * 
	 * @param string $endpoint API endpoint (without base URL)
	 * @param array $headers Additional headers
	 * @return array Response data
	 * @throws \Exception On request failure
	 */
	public function get( string $endpoint, array $headers = [] ): array {
		$url = $this->base_url . $endpoint;
		$args = $this->build_request_args( 'GET', $headers );

		Logger::log_api_request( $endpoint, 'GET', [], [] );

		return $this->make_request( $url, $args, 'GET' );
	}

	/**
	 * Make a POST request to the Store API
	 * 
	 * @param string $endpoint API endpoint (without base URL)
	 * @param array $data Request data
	 * @param array $headers Additional headers
	 * @return array Response data
	 * @throws \Exception On request failure
	 */
	public function post( string $endpoint, array $data = [], array $headers = [] ): array {
		$url = $this->base_url . $endpoint;
		$args = $this->build_request_args( 'POST', $headers, $data );

		Logger::log_api_request( $endpoint, 'POST', $data, [] );

		return $this->make_request( $url, $args, 'POST' );
	}

	/**
	 * Make a batch request to the Store API
	 * 
	 * @param array $requests Array of request objects
	 * @return array Response data
	 * @throws \Exception On request failure
	 */
	public function batch( array $requests ): array {
		$url = $this->base_url . '/batch';
		$data = [ 'requests' => $requests ];
		$args = $this->build_request_args( 'POST', [], $data );

		Logger::info( 'Batch request initiated', [
			'request_count' => count( $requests )
		] );

		return $this->make_request( $url, $args, 'BATCH' );
	}

	/**
	 * Build request arguments
	 * 
	 * @param string $method HTTP method
	 * @param array $headers Additional headers
	 * @param array $data Request data for POST requests
	 * @return array Request arguments
	 */
	private function build_request_args( string $method, array $headers = [], array $data = [] ): array {
		$args = [
			'method' => $method,
			'timeout' => 30,
			'headers' => array_merge( [
				'Content-Type' => 'application/json',
			], $headers )
		];

		// Add nonce if available
		if ( $this->nonce ) {
			$args['headers']['Nonce'] = $this->nonce;
		}

		// Add cookies if available
		if ( ! empty( $this->cookies ) ) {
			$args['cookies'] = $this->cookies;
		}

		// Add body for POST requests
		if ( $method === 'POST' && ! empty( $data ) ) {
			$args['body'] = wp_json_encode( $data );
		}

		return $args;
	}

	/**
	 * Make the actual HTTP request with retry logic
	 * 
	 * @param string $url Full URL
	 * @param array $args Request arguments
	 * @param string $method HTTP method for logging
	 * @return array Response data
	 * @throws \Exception On request failure
	 */
	private function make_request( string $url, array $args, string $method ): array {
		$attempt = 0;
		$last_error = null;

		while ( $attempt < $this->max_retries ) {
			$attempt++;
			
			try {
				$response = $this->execute_request( $url, $args );
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );

				// Store cookies from response
				$this->cookies = wp_remote_retrieve_cookies( $response );

				Logger::info( $method . ' request successful', [
					'url' => $url,
					'status_code' => $response_code,
					'response_length' => strlen( $response_body ),
					'attempt' => $attempt
				] );

				// Handle 404 responses (nonce might be expired)
				if ( $response_code === 404 ) {
					Logger::warning( '404 response received - nonce may be expired', [
						'url' => $url,
						'attempt' => $attempt
					] );
					
					// If this is a 404 and we have a nonce, it might be expired
					if ( $this->nonce ) {
						throw new \Exception( 'Nonce expired or invalid (404 response)' );
					}
				}

				// Parse response
				$data = json_decode( $response_body, true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					throw new \Exception( 'Failed to parse response JSON: ' . json_last_error_msg() );
				}

				return $data;

			} catch ( \Exception $e ) {
				$last_error = $e;
				Logger::warning( 'Request failed on attempt ' . $attempt, [
					'url' => $url,
					'error' => $e->getMessage(),
					'attempt' => $attempt,
					'max_retries' => $this->max_retries
				] );

				// If this is the last attempt, don't wait
				if ( $attempt >= $this->max_retries ) {
					break;
				}

				// Wait before retrying (exponential backoff)
				$delay = $this->retry_delay * ( 2 ** ( $attempt - 1 ) );
				usleep( $delay * 1000 ); // Convert to microseconds
			}
		}

		// All retries failed
		Logger::error( 'All retry attempts failed', [
			'url' => $url,
			'max_retries' => $this->max_retries,
			'last_error' => $last_error ? $last_error->getMessage() : 'Unknown error'
		] );

		throw new \Exception( 'Request failed after ' . $this->max_retries . ' attempts. Last error: ' . 
			( $last_error ? $last_error->getMessage() : 'Unknown error' ) );
	}

	/**
	 * Execute the actual HTTP request
	 * 
	 * @param string $url Full URL
	 * @param array $args Request arguments
	 * @return array|\WP_Error Response or WP_Error
	 */
	private function execute_request( string $url, array $args ) {
		if ( $this->skip_ssl ) {
			add_filter( 'https_ssl_verify', '__return_false' );
		}

		$response = wp_safe_remote_request( $url, $args );

		if ( $this->skip_ssl ) {
			remove_filter( 'https_ssl_verify', '__return_false' );
		}

		if ( is_wp_error( $response ) ) {
			Logger::log_exception( 'WP_Error occurred during HTTP request', $response, [
				'url' => $url
			] );
			throw new \Exception( 'WP_Error: ' . $response->get_error_message() );
		}

		return $response;
	}

	/**
	 * Get cookies from the last response
	 * 
	 * @return array Cookies array
	 */
	public function get_cookies(): array {
		return $this->cookies;
	}

	/**
	 * Check if the client is authenticated (has a nonce)
	 * 
	 * @return bool True if authenticated
	 */
	public function is_authenticated(): bool {
		return ! empty( $this->nonce );
	}

	/**
	 * Clear the current nonce (for re-authentication)
	 * 
	 * @return void
	 */
	public function clear_authentication(): void {
		$this->nonce = null;
		Logger::info( 'Authentication cleared' );
	}
}
