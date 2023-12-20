<?php
/**
 * Plugin Name: Happy Order Generator
 * Plugin URI:
 * Description: Automated order generator
 * Version: 1
 * Author: nicw
 * Author URI: https://woocommerce.com
 * License: GPLv3
 * License URI: http://www.gnu.org/licenses/gpl-3.0
 * Text Domain: happy-order-generator
 * Domain Path: /languages
 * Tested up to: 6.2
 *
 * WC requires at least: 6.7
 * WC tested up to: 7.7
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
		add_action( 'woocommerce_store_api_checkout_update_customer_from_request', array( $this, 'log_customer_in' ), 10, 2 );

		add_action('action_scheduler_init', array( 'Happy_Order_Generator\Cron_Jobs', 'instance' ));
		add_action('plugins_loaded', array( 'Happy_Order_Generator\Gateway_Integration_Stripe', 'instance' ));

	}

	public function add_setting_page(): array {
		$settings[] = include_once plugin_dir_path( __FILE__ ) . 'includes/admin/class-wc-settings-order-generator.php';

		return $settings;
	}

	/**
	 * Log the user in before process_customer runs during checkout in store-api
	 *
	 * @param $customer
	 * @param $request
	 *
	 * @return void
	 */
	public function log_customer_in( $customer, $request ): void {

		$user = get_user_by( 'email', sanitize_email( $_POST['billing_address']['email']) );

		if( $user ){
			wc_set_customer_auth_cookie( $user->ID );
		}
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
	 * Checks if the plugin should load.
	 *
	 * @return bool
	 */
	public static function check(): bool {

		$passed        = true;
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
	 * Declare compatibility for WooCommerce features.
	 *
	 * @since 5.5.23
	 */
	public function declare_feature_compatibility(): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
}

Order_Generator::instance();
