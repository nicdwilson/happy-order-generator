<?php
/**
 *
 */

namespace Happy_Order_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Cron_Jobs {

	private string $action_hook = 'create_happy_orders';

	/**
	 * @var array
	 */
	private array $settings;


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

		$this->settings = Order_Generator::get_settings();

		$this->setup_action_scheduler();

		add_action( 'create_happy_orders', array( $this, 'create_orders') , 10, 1 );
	}

	public function setup_action_scheduler(): void {

		if( empty( $this->settings ) ){
			return;
		}

		$args['batch_size']  = $this->get_batch_size();
		$interval_in_seconds = $this->get_interval_in_seconds();

		$this->settings['batch_size'] = ( isset( $this->settings['batch_size'] ) ) ? $this->settings['batch_size'] : 0;
		$this->settings['interval']   = ( isset( $this->settings['interval'] ) ) ? $this->settings['interval'] : 0;

		//todo if current action is okay, leave it.
		if ( $this->settings['batch_size'] == $args['batch_size'] && $this->settings['interval'] = $interval_in_seconds ) {
			if ( as_has_scheduled_action( $this->action_hook ) ) {
				return;
			}
		}

		as_unschedule_all_actions( $this->action_hook );

		$result = as_schedule_recurring_action( time(), $interval_in_seconds, $this->action_hook, $args, 'happy-order-generator', true );

		$this->settings['batch_size'] = $args['batch_size'];
		$this->settings['interval']   = $interval_in_seconds;

		update_option( 'wc_order_generator_settings', $this->settings );

	}

	/**
	 * Get the schedule interval
	 *
	 * @return int
	 */
	private function get_interval_in_seconds(): int {

		$interval = 60;

		if ( $this->settings['orders_per_hour'] < 60 ) {
			$interval = 3600 / $this->settings['orders_per_hour'];
		}

		return $interval;
	}

	/**
	 * The size of each batch of orders created by the scheduled action.
	 *
	 * @return int
	 */
	private function get_batch_size(): int {

		$batch_size = 1;

		if ( $this->settings['orders_per_hour'] > 60 ) {
			$batch_size = round( $this->settings['orders_per_hour'] / 60 );
		}

		return $batch_size;
	}

	/**
	 * Called by the action scheduler actions to generate the orders.
	 *
	 * @param $args
	 *
	 * @return void
	 * @throws \WC_Stripe_Exception
	 */
	public function create_orders( $args = 1 ): void {

		Logger::log( PHP_EOL . 'Generating batch of ' . $args . ' orders' );

		$generator = new \Happy_Order_Generator\Generator();

		for ( $i = 0; $i < $args; $i ++ ) {
			$generator->generate_order();
			sleep( 5 );
		}
	}
}


