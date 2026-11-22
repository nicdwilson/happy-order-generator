<?php
/**
 * Plugin Name: Happy Order Generator
 * Plugin URI: https://github.com/nicdwilson/happy-order-generator
 * Description: Automatically generates a steady stream of test orders on WooCommerce test and staging sites.
 * Version: 1.0.1
 * Author: nicw
 * Author URI: https://woocommerce.com
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0
 * Text Domain: happy-order-generator
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * Tested up to: 7.1
 *
 * WC requires at least: 6.7
 * WC tested up to: 11.3
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package Happy_Order_Generator
 */

namespace Happy_Order_Generator;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'ORDER_GENERATOR_MIN_PHP_VER', '8.1.0' );
define( 'ORDER_GENERATOR_MIN_WP_VER', '6.2' );
define( 'ORDER_GENERATOR_MIN_WC_VER', '6.7.0' );

require_once plugin_dir_path( __FILE__ ) . '/vendor/autoload.php';

class Order_Generator {

	private static array $errors = array();

	/**
	 * Order_Simulator The instance of Order_Generator
	 *
	 * @var    object
	 * @access private
	 * @since  1.0.0
	 */
	private static object $instance;

	/**
	 * Main Order_Generator Instance
	 *
	 * Ensures only one instance of Order_Generator is loaded or can be loaded.
	 *
	 * @return Order_Generator instance
	 * @since  1.0.0
	 * @static
	 */
	public static function instance(): object {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {

		/**
		 * Activation and deactivation hooks
		 */
		register_activation_hook( __FILE__, array( 'Happy_Order_Generator\Installer', 'activate_plugin' ) );
		register_deactivation_hook( __FILE__, array( 'Happy_Order_Generator\Installer', 'deactivate_plugin' ) );

		/**
		 * Load settings page from inside a namespace
		 */
		add_action( 'woocommerce_get_settings_pages', array( $this, 'add_setting_page' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_feature_compatibility' ) );

		/**
		 * Log the user in before process_customer runs during checkout
		 */
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array(
			$this,
			'log_customer_in'
		), 10, 2 );

		/**
		 * Load up the Action Scheduler jobs
		 */
		add_action( 'action_scheduler_init', array( 'Happy_Order_Generator\Cron_Jobs', 'instance' ) );

		/**
		 * Remind store staff that orders are being generated
		 */
		add_action( 'admin_notices', array( $this, 'running_notice' ) );
		add_action( 'wp_ajax_hog_dismiss_running_notice', array( $this, 'dismiss_running_notice' ) );

		/**
		 * Keep customer emails for generated customers away from their addresses
		 */
		add_action( 'woocommerce_email', array( 'Happy_Order_Generator\Customer_Emails', 'register' ) );

		/**
		 * Fire up the Stripe Gateway integration
		 */
		add_action( 'plugins_loaded', array( 'Happy_Order_Generator\Gateway_Integration_Stripe', 'instance' ) );

	}

	/**
	 * Returns the plugin settings
	 *
	 * @return array
	 */
	public function add_setting_page(): array {
		$settings[] = include_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wc-settings-order-generator.php';

		return $settings;
	}

	/**
	 * Header the generator sends with its own Store API checkout requests.
	 */
	const CHECKOUT_TOKEN_HEADER = 'X-HOG-Checkout-Token';

	/**
	 * Issues a single-use token that lets one generator checkout log in as
	 * the given generated customer. Only a hash of the token is stored.
	 *
	 * @param int $user_id The customer the checkout is for.
	 *
	 * @return string The token to send in the CHECKOUT_TOKEN_HEADER header.
	 */
	public static function issue_checkout_token( int $user_id ): string {

		$token = bin2hex( random_bytes( 32 ) );

		set_transient( self::checkout_token_key( $token ), $user_id, 2 * MINUTE_IN_SECONDS );

		return $token;
	}

	/**
	 * Removes a checkout token, whether or not it was used.
	 *
	 * @param string $token The token returned by issue_checkout_token().
	 *
	 * @return void
	 */
	public static function revoke_checkout_token( string $token ): void {
		delete_transient( self::checkout_token_key( $token ) );
	}

	/**
	 * The transient key for a checkout token.
	 *
	 * @param string $token The token.
	 *
	 * @return string
	 */
	private static function checkout_token_key( string $token ): string {
		return 'hog_checkout_' . hash( 'sha256', $token );
	}

	/**
	 * Log the generated customer in before process_customer runs during a
	 * Store API checkout.
	 *
	 * This runs on every Store API checkout, so it only acts on requests
	 * that carry a valid single-use token from issue_checkout_token(), and
	 * only logs in the generated customer that token was issued for.
	 *
	 * @param \WC_Customer    $customer The customer being updated.
	 * @param \WP_REST_Request $request  The checkout request.
	 *
	 * @return void
	 */
	public function log_customer_in( $customer, $request ): void {

		if ( ! self::is_allowed_environment() ) {
			return;
		}

		$token = (string) $request->get_header( self::CHECKOUT_TOKEN_HEADER );

		if ( '' === $token || ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return;
		}

		$key     = self::checkout_token_key( $token );
		$user_id = (int) get_transient( $key );

		// Single use: remove it before doing anything else.
		delete_transient( $key );

		// Never log in anyone the generator didn't create, or anyone who can manage the store.
		if ( ! Customer::is_generated( $user_id ) || user_can( $user_id, 'manage_woocommerce' ) ) {
			return;
		}

		wc_set_customer_auth_cookie( $user_id );
	}

	/**
	 * Returns the plugin settings
	 *
	 * @return array
	 */
	public static function get_settings(): array {

		$settings = get_option( 'happy_order_generator_settings', array() );

		return ( is_array( $settings ) ) ? $settings : array();
	}

	/**
	 * Whether order generation is switched on.
	 *
	 * This is separate from the plugin being active: it lets order generation
	 * be paused from the settings page without deactivating the plugin or
	 * losing the configuration. Sites that saved settings before this option
	 * existed have no 'enabled' key, so a missing value counts as enabled to
	 * keep their current behaviour.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {

		$settings = self::get_settings();
		$enabled  = 'no' !== ( $settings['enabled'] ?? 'yes' );

		/**
		 * Filters whether Happy Order Generator should generate orders.
		 *
		 * @param bool  $enabled  Whether order generation is enabled.
		 * @param array $settings The plugin settings.
		 */
		$enabled = (bool) apply_filters( 'hog_is_enabled', $enabled, $settings );

		// Checked last so the filter above can't switch generation on in production.
		return $enabled && self::is_allowed_environment();
	}

	/**
	 * Whether this site's environment type allows order generation.
	 *
	 * The generator creates real orders and customers, so it only runs on
	 * sites whose WP_ENVIRONMENT_TYPE is local, development or staging. Sites
	 * that don't set it count as production, as wp_get_environment_type()
	 * reports.
	 *
	 * @return bool
	 */
	public static function is_allowed_environment(): bool {

		/**
		 * Filters the environment types the order generator may run in.
		 *
		 * @param string[] $types Allowed values of wp_get_environment_type().
		 */
		$types = (array) apply_filters( 'hog_allowed_environment_types', array( 'local', 'development', 'staging' ) );

		return in_array( wp_get_environment_type(), $types, true );
	}

	/**
	 * Checks if the plugin should load.
	 *
	 * @return bool
	 */
	public static function check(): bool {

		$passed = true;
		$inactive_text = '<strong>' . sprintf( __( '%s is inactive.', 'Happy Order Generator' ), __( 'Happy Order Generator', 'happy-order-generator' ) ) . '</strong>';

		if ( version_compare( phpversion(), ORDER_GENERATOR_MIN_PHP_VER, '<' ) ) {
			self::$errors[] = sprintf( __( '%1$s The plugin requires PHP version %2$s or newer.', 'happy-order-generator' ), $inactive_text, ORDER_GENERATOR_MIN_PHP_VER );
			$passed         = false;
		} elseif ( ! self::is_woocommerce_version_ok() ) {
			self::$errors[] = sprintf( __( '%1$s The plugin requires WooCommerce version %2$s or newer.', 'happy-order-generator' ), $inactive_text, ORDER_GENERATOR_MIN_WC_VER );
			$passed         = false;
		} elseif ( ! self::is_wp_version_ok() ) {
			self::$errors[] = sprintf( __( '%1$s The plugin requires WordPress version %2$s or newer.', 'happy-order-generator' ), $inactive_text, ORDER_GENERATOR_MIN_WP_VER );
			$passed         = false;
		}

		return $passed;
	}

	/**
	 * Checks if the installed WooCommerce version is ok.
	 *
	 * @return bool
	 */
	public static function is_woocommerce_version_ok(): bool {

		if ( ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( ! ORDER_GENERATOR_MIN_WC_VER ) {
			return true;
		}

		return version_compare( WC()->version, ORDER_GENERATOR_MIN_WC_VER, '>=' );
	}

	/**
	 * Checks if the installed WordPress version is ok.
	 *
	 * @return bool
	 */
	public static function is_wp_version_ok(): bool {

		global $wp_version;

		if ( ! ORDER_GENERATOR_MIN_WP_VER ) {
			return true;
		}

		return version_compare( $wp_version, ORDER_GENERATOR_MIN_WP_VER, '>=' );
	}

	/**
	 * Displays any errors as admin notices.
	 */
	public static function admin_notices(): void {
		if ( empty( self::$errors ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>';
		echo wp_kses_post( implode( '<br>', self::$errors ) );
		echo '</p></div>';
	}

	/**
	 * User meta holding a hash of the login session the running notice was
	 * dismissed in. A new login has a new session, so the notice returns.
	 */
	const NOTICE_DISMISSED_META_KEY = '_hog_running_notice_dismissed';

	/**
	 * Warns administrators and shop managers, on every admin screen, that
	 * orders are being generated. It can be dismissed until the next login.
	 *
	 * @return void
	 */
	public function running_notice(): void {

		if ( ! current_user_can( 'manage_woocommerce' ) || ! self::is_enabled() ) {
			return;
		}

		// Only claim it's running when a generation action is actually queued.
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'create_happy_orders' ) ) {
			return;
		}

		$session = wp_get_session_token();
		$stored  = (string) get_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_META_KEY, true );

		if ( '' !== $session && '' !== $stored && hash_equals( $stored, hash( 'sha256', $session ) ) ) {
			return;
		}

		$settings = self::get_settings();
		$rate     = (int) ( $settings['orders_per_hour'] ?? 0 );

		if ( $rate >= 1 ) {
			$message = sprintf(
				/* translators: %s: number of orders per hour */
				_n(
					'Happy Order Generator is creating test orders on this site at %s order an hour.',
					'Happy Order Generator is creating test orders on this site at %s orders an hour.',
					$rate,
					'happy-order-generator'
				),
				number_format_i18n( $rate )
			);
		} else {
			$message = __( 'Happy Order Generator is creating test orders on this site.', 'happy-order-generator' );
		}

		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=order_generator' );
		$nonce        = wp_create_nonce( 'hog_dismiss_running_notice' );
		?>
		<div class="notice notice-warning is-dismissible hog-running-notice" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<p>
				<strong><?php echo esc_html( $message ); ?></strong>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Change or pause it in the settings', 'happy-order-generator' ); ?></a>
			</p>
		</div>
		<script>
			jQuery( function ( $ ) {
				$( document ).on( 'click', '.hog-running-notice .notice-dismiss', function () {
					$.post( ajaxurl, {
						action: 'hog_dismiss_running_notice',
						_ajax_nonce: $( this ).closest( '.hog-running-notice' ).data( 'nonce' )
					} );
				} );
			} );
		</script>
		<?php
	}

	/**
	 * Remembers that the running notice was dismissed in this login session.
	 *
	 * @return void
	 */
	public function dismiss_running_notice(): void {

		check_ajax_referer( 'hog_dismiss_running_notice' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( null, 403 );
		}

		$session = wp_get_session_token();

		if ( '' !== $session ) {
			update_user_meta( get_current_user_id(), self::NOTICE_DISMISSED_META_KEY, hash( 'sha256', $session ) );
		}

		wp_send_json_success();
	}

	/**
	 * Declare compatibility for WooCommerce features.
	 *
	 * @since 5.5.23
	 */
	public function declare_feature_compatibility(): void {

		/**
		 * HPOS compatible
		 */
		if ( class_exists( FeaturesUtil::class ) ) {
			FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
}

Order_Generator::instance();
