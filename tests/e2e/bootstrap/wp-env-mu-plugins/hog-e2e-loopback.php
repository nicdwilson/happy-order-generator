<?php
/**
 * Plugin Name: HOG E2E – wp-env loopback
 * Description: Test-environment glue for wp-env only (mounted via .wp-env.json, never shipped or used under QIT).
 *
 * The site URL is http://localhost:8890 on the host, but inside the wp-env
 * containers that address is not the web server. Happy Order Generator calls
 * its own Store API over HTTP, so route those requests to the WordPress
 * container on port 80 while keeping the original Host header.
 */

add_filter(
	'pre_http_request',
	function ( $response, $args, $url ) {
		static $rerouting = false;
		$home = untrailingslashit( home_url() );

		if ( $rerouting || 0 !== strpos( $url, $home ) ) {
			return $response;
		}

		$parts                    = wp_parse_url( $home );
		$args['headers']          = (array) ( $args['headers'] ?? array() );
		$args['headers']['Host']  = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		// wp_safe_remote_*() would reject the internal container hostname.
		$args['reject_unsafe_urls'] = false;

		$rerouting = true;
		$result    = wp_remote_request( 'http://wordpress' . substr( $url, strlen( $home ) ), $args );
		$rerouting = false;

		return $result;
	},
	10,
	3
);
