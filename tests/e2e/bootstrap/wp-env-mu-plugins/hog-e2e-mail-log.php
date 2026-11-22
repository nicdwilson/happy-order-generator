<?php
/**
 * Plugin Name: HOG E2E – mail log
 * Description: Test-environment glue for wp-env only (mounted via .wp-env.json, never shipped or used under QIT).
 *
 * Records each email's recipients and subject in the hog_e2e_mail_log option
 * instead of sending it, so tests can check where WooCommerce emails go.
 */

define( 'HOG_E2E_MAIL_LOG', true );

add_filter(
	'pre_wp_mail',
	function ( $return, $atts ) {
		$log   = get_option( 'hog_e2e_mail_log', array() );
		$log[] = array(
			'to'      => implode( ',', (array) $atts['to'] ),
			'subject' => (string) $atts['subject'],
		);
		update_option( 'hog_e2e_mail_log', $log, false );
		return true;
	},
	10,
	2
);
