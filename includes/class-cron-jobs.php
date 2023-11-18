<?php
/**
*
*/

namespace Happy_Order_Generator;

use Faker\Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Cron_Jobs {


	/**
	 * Cron_Jobs The instance of Cron_Jobs
	 *
	 * @var    object
	 * @access  private
	 * @since    1.0.0
	 */
	private static object $instance;

	/**
	 * Main Cron_Jobs Instance
	 *
	 * Ensures only one instance of Gateway is loaded or can be loaded.
	 *
	 * @return Cron_Jobs instance
	 * @since 1.0.0
	 * @static
	 */
	public static function instance(): object {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {

		if ( ! wp_next_scheduled( 'hog_generate_scheduled_orders' ) ) {
			wp_schedule_event( time(), 'hourly', 'hog_generate_scheduled_orders' );
		}

		add_action( 'hog_generate_scheduled_orders', array( $this, 'create_scheduled_orders' ) );
		add_action( 'hog_generate_orders', array( $this, 'generate_batch' ) );
	}

	/**
	 * This function creates the actions in Action Scheduler. It runs each hour, and sets up the actions
	 * required for tha hour only, to create the orders required in that hour in hopefully regularly spaced batches.
	 *
	 * @return void
	 */
	public function create_scheduled_orders(): void {

		$settings = Order_Generator::get_settings();
		$progress = get_option( 'hog_progress_indicator', false );
		$batches  = 7;

		if ( false === $progress ) {
			$progress = 0;
			update_option( 'hog_progress_indicator', 0 );
		}

		if ( $settings['orders_per_hour'] < $progress || '0' === $progress ) {

			if ( $settings['orders_per_hour'] > 60 ) {
				$batch_size = round( $settings['orders_per_hour'] / 10 );
				$batches    = 10;
			} else {
				$batch_size = round( $settings['orders_per_hour'] / 6 );
			}

			for ( $i = 1; $i < $batches; $i ++ ) {
				$timestamp = time() + ( 360 * $i );
				as_schedule_single_action( $timestamp, 'hog_generate_orders', array( $batch_size ) );
			}
		} else {
			update_option( 'hog_progress_indicator', 0 );
		}

	}

	/**
	 * Called by the action scheduler actions to generate the orders.
	 *
	 * @param $args
	 *
	 * @return void
	 */
	public function generate_batch( $args ): void {

		$progress = get_option( 'hog_progress_indicator', false );

		Logger::log('Generating batch of ' . $args . ' orders' );
		Logger::log( 'Progress set to ' . $progress );

		$generator = new \Happy_Order_Generator\Generator();

		for ( $i = 0; $i < $args; $i ++ ) {
			Logger::log('Generating order ' . $i . ' of ' . $args );
			$generator->generate_order();
			$progress ++;
		}

		if ( ! $progress ) {
			update_option( 'hog_progress_indicator', 1 );
		} else {
			update_option( 'hog_progress_indicator', $progress );
		}
	}

}


