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

		/**
		 * Re-sync the schedule as soon as the settings change, so switching
		 * the generator off (or back on) takes effect immediately rather than
		 * on the next request.
		 */
		add_action( 'add_option_happy_order_generator_settings', array( $this, 'on_settings_updated' ), 10, 0 );
		add_action( 'update_option_happy_order_generator_settings', array( $this, 'on_settings_updated' ), 10, 0 );
	}

	/**
	 * Reloads the settings and re-syncs the scheduled action after a save.
	 *
	 * @return void
	 */
	public function on_settings_updated(): void {

		$this->settings = Order_Generator::get_settings();

		$this->setup_action_scheduler();
	}

	public function setup_action_scheduler(): void {

		/**
		 * When generation is switched off, remove any pending action and stop.
		 * Only pending actions are checked: one that is already running can't
		 * be cancelled, and create_orders() refuses to run while disabled.
		 */
		if ( ! Order_Generator::is_enabled() ) {
			if ( $this->has_pending_action() ) {
				as_unschedule_all_actions( $this->action_hook );
				Logger::log( 'Order generation disabled: scheduled actions removed' );
			}
			return;
		}

		/**
		 * Zero (or unset) orders per hour means "generate nothing". Without this
		 * the interval falls back to its 60 second default and a rate of 0
		 * would generate roughly 60 orders an hour.
		 */
		if ( $this->get_orders_per_hour() < 1 ) {
			if ( $this->has_pending_action() ) {
				as_unschedule_all_actions( $this->action_hook );
				Logger::log( 'Orders per hour is 0: scheduled actions removed' );
			}
			return;
		}

		$args['batch_size']  = $this->get_batch_size();
		$interval_in_seconds = $this->get_interval_in_seconds();

		$scheduler_state = get_option( 'hog_scheduler_state', array() );

		$this->settings['batch_size'] = ( isset( $scheduler_state['batch_size'] ) ) ? $scheduler_state['batch_size'] : 0;
		$this->settings['interval']   = ( isset( $scheduler_state['interval'] ) ) ? $scheduler_state['interval'] : 0;

		//todo if current action is okay, leave it.
		if ( $this->settings['batch_size'] == $args['batch_size'] && $this->settings['interval'] == $interval_in_seconds ) {
			if ( as_has_scheduled_action( $this->action_hook ) ) {
				return;
			}
		}

		as_unschedule_all_actions( $this->action_hook );

		$result = as_schedule_recurring_action( time(), $interval_in_seconds, $this->action_hook, $args, 'happy-order-generator', true );

		update_option( 'hog_scheduler_state', array(
			'batch_size' => $args['batch_size'],
			'interval'   => $interval_in_seconds,
		) );

	}

	/**
	 * Whether a pending (not yet started) generation action exists.
	 *
	 * @return bool
	 */
	private function has_pending_action(): bool {

		$pending = as_get_scheduled_actions(
			array(
				'hook'     => $this->action_hook,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);

		return ! empty( $pending );
	}

	/**
	 * Get the schedule interval
	 *
	 * @return int
	 */
	private function get_interval_in_seconds(): int {

		$interval        = 60;
		$orders_per_hour = $this->get_orders_per_hour();

		if ( $orders_per_hour > 0 && $orders_per_hour < 60 ) {
			$interval = (int) round( 3600 / $orders_per_hour );
		}

		return $interval;
	}

	/**
	 * The size of each batch of orders created by the scheduled action.
	 *
	 * @return int
	 */
	private function get_batch_size(): int {

		$batch_size      = 1;
		$orders_per_hour = $this->get_orders_per_hour();

		if ( $orders_per_hour > 60 ) {
			$batch_size = (int) round( $orders_per_hour / 60 );
		}

		return $batch_size;
	}

	/**
	 * The configured orders per hour, as a non-negative integer. Negative
	 * values count as 0 (absint() would turn -5 into 5 orders an hour).
	 *
	 * @return int
	 */
	private function get_orders_per_hour(): int {

		return max( 0, (int) ( $this->settings['orders_per_hour'] ?? 0 ) );
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

		/**
		 * An action may already be claimed by a queue runner when generation
		 * is switched off, so check again before creating anything.
		 */
		if ( ! Order_Generator::is_enabled() ) {
			Logger::log( 'Order generation is disabled: skipping scheduled batch' );
			return;
		}

		/**
		 * Likewise if orders per hour was set to 0 after this action was queued.
		 */
		$this->settings = Order_Generator::get_settings();

		if ( $this->get_orders_per_hour() < 1 ) {
			Logger::log( 'Orders per hour is 0: skipping scheduled batch' );
			return;
		}

		Logger::log( PHP_EOL . 'Generating batch of ' . $args . ' orders' );

		$generator = new \Happy_Order_Generator\Generator();

		for ( $i = 0; $i < $args; $i ++ ) {
			$generator->generate_order();
			sleep( 5 );
		}
	}
}


