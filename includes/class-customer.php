<?php
/**
 *
 */

namespace Happy_Order_Generator;

use Exception;
use Faker\Factory;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class Customer {

	/**
	 * @var array
	 */
	private array $settings;

	/**
	 * Customer The instance of Customer
	 *
	 * @var    object|null
	 * @access  private
	 * @since    1.0.0
	 */
	private static object $instance;

	/**
	 * @var string
	 */
	public string $ID = '0';

	/**
	 * Main Customer Instance
	 *
	 * Ensures only one instance of Customer is loaded or can be loaded.
	 *
	 * @return Customer instance
	 * @since 1.0.0
	 * @static
	 */
	public static function instance(): object {
		if ( empty( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct( $user_id = 0 ) {

		$this->settings = Order_Generator::get_settings();

		if ( 0 === $user_id ) {
			$this->ID = $this->get_random_user();
		} else {
			$this->ID = $user_id;
		}
	}

	/**
	 * Create a new user using FakerPHP for data generation
	 *
	 * @return int|bool
	 */
	public function create_user(): int|bool {

		$country = $this->get_customer_locale_details();

		try {
			$faker = Factory::create( $country['locale'] );
		} catch ( Exception $ex ) {
			Logger::log('faker exception occurred while creating customer');
			Logger::log('Faker said ' . $ex->getMessage() );
			return 0;
		}

		$state      = $this->get_customer_state( $faker, $country );
		$first_name = $this->get_customer_firstname( $faker );

		$user = array(
			'user_login' => $faker->username(),
			'user_pass'  => $this->get_password(),
			'user_email' => $faker->email(),
			'first_name' => $first_name,
			'last_name'  => 'ID',
			'role'       => 'customer'
		);

		$user_id = wp_insert_user( $user );

		if ( is_wp_error( $user_id ) ) {
			return false;
		}

		$last_name      = $this->get_customer_lastname( $faker, $user_id );
		$customer_email = $this->get_customer_email( $faker, $user_id );

		wp_update_user( array(
			'ID'         => $user_id,
			'last_name'  => $last_name,
			'user_email' => $customer_email
		) );

		// billing/shipping address
		$meta = array(
			'billing_first_name'   => $first_name,
			'billing_last_name'    => $last_name,
			'billing_address_1'    => $faker->streetAddress(),
			'billing_city'         => $faker->city(),
			'billing_country'      => $country['code'],
			'billing_postcode'     => $faker->postcode(),
			'billing_email'        => $customer_email,
			'billing_phone'        => $faker->phoneNumber(),
			'billing_state'        => $state,
			'shipping_country'     => $country['code'],
			'shipping_first_name'  => $first_name,
			'shipping_last_name'   => $last_name,
			'shipping_address_1'   => $faker->streetAddress(),
			'shipping_city'        => $faker->city(),
			'shipping_state'       => $state,
			'shipping_postcode'    => $faker->postcode(),
			'shipping_email'       => $faker->email(),
			'shipping_phone'       => $faker->phoneNumber(),
			'_customer_ip_address' => $faker->ipv4(),
			'billing_company'      => '',
			'billing_address_2'    => '',
			'shipping_company'     => '',
			'shipping_address_2'   => '',
		);

		foreach ( $meta as $key => $value ) {
			update_user_meta( $user_id, $key, $value );
		}

		return $user_id;
	}

	/**
	 * @return false|int|mixed|WP_Error
	 */
	public function get_random_user() {

		$new_customer = ( rand( 1, 100 ) > 50 );
		$users        = array();

		if ( $new_customer ) {

			$offset = rand( 1, get_user_count() );

			$args = array(
				'role__in'     => array( 'customer' ),
				'role__not_in' => array( 'administrator', 'shop manager' ),
				'fields'       => 'ID',
				'offset'       => $offset,
				'number'       => 1
			);

			$users = get_users( $args );
		}

		if ( is_array( $users ) && ! empty( $users[0] ) ) {
			$user_id = $users[0];
		} else {
			$user_id = $this->create_user();
		}
		return $user_id;
	}

	public function get_id() {
		return $this->ID;
	}

	/**
	 * Get the customer Stripe ID. This is stored as a user option, to be multisite compatible.
	 *
	 * @return string
	 */
	public function get_stripe_customer_id(): string {
		return get_user_meta( $this->ID, 'wp__stripe_customer_id', true );
	}

	/**
	 * Get the customer IP
	 *
	 * @return string
	 */
	public function get_customer_ip(): string {
		return ( get_user_meta( $this->ID, '_customer_ip_address', true ) ) ?? '127.0.0.1';
	}

	/**
	 * Create a password
	 *
	 * @return string
	 */
	private function get_password(): string {
		return wp_generate_password();
	}

	/**
	 * @param $faker
	 *
	 * @return string
	 */
	private function get_customer_firstname( $faker ): string {

		if ( 'faker' === $this->settings['customer_naming_convention'] ) {
			$first_name = $faker->firstName;
		} else {
			$first_name = 'Test Customer';
		}

		return $first_name;
	}

	/**
	 * @param $faker
	 * @param $user_id
	 *
	 * @return string
	 */
	private function get_customer_lastname( $faker, $user_id ): string {

		if ( 'faker' === $this->settings['customer_naming_convention'] ) {
			$last_name = $faker->lastName;
		} else {
			$last_name = 'ID: ' . $user_id;
		}

		return $last_name;
	}


	/**
	 * @param $faker
	 * @param $user_id
	 *
	 * @return string
	 */
	private function get_customer_email( $faker, $user_id ): string {

		switch ( $this->settings['customer_email_convention'] ) {
			case 'customer_id_staging':
				$email = 'test.customer.id.' . $user_id . '@mystagingsite.com';
				break;
			case 'customer_id_here':
				$email = 'test.customer.id.' . $user_id . '@' . parse_url( get_bloginfo( 'url' ), PHP_URL_HOST );
				break;
			default:
				$email = $faker->email;
		}

		return $email;
	}

	/**
	 *
	 *
	 * @return array
	 */
	private function get_customer_locale_details(): array {

		$locale = 'random';

		if ( isset( $this->settings['customer_locales'] ) && ! empty( $this->settings['customer_locales'] ) ) {

			if ( count( $this->settings['customer_locales'] ) == 1 ) {
				$locale = $this->settings['customer_locales'][0];
			} else {
				$locale = $this->settings['customer_locales'][ rand( 0, count( $this->settings['customer_locales'] ) ) ];
			}
		}

		return $this->get_country_name( $locale );
	}

	/**
	 * Faker PHP only supports 75 locales from 68 countries, while
	 * WooCommerce supports 249 countries. In an ideal world, we would only have let you select
	 * a valid Faker locale, but instead we used built-in WC functions to list countries so here we need
	 * to make sure you only selected a country Faker supports. If you did not, we'll feed back
	 * a random country, locale and country code.
	 *
	 * @param $country_code
	 *
	 * @return array
	 */
	private function get_country_name( $country_code ): array {

		$wc_countries    = WC()->countries->get_countries();
		$faker_countries = $this->get_faker_countries();
		$country         = array();

		// Reduce WC country list to only countries supported by Faker.
		$wc_countries = array_intersect_key( $wc_countries, $faker_countries );

		// Is the current country code supported by faker? If not, replace it.
		if ( ! in_array( $country_code, array_keys( $wc_countries ) ) ) {
			$country_code = array_rand( $wc_countries );
		}

		$country['locale'] = $faker_countries[ $country_code ];
		$country['code']   = $country_code;
		$country['name']   = $wc_countries[ $country_code ];

		return $country;
	}

	/**
	 * Align current Faker state value with WC Unicode CLDR state value
	 * or grab a new value if it is available. Might return an empty string
	 * since states are not always required.
	 *
	 * @param $faker
	 * @param $country
	 *
	 * @return string
	 */
	private function get_customer_state( $faker, $country ): string {

		// Some Faker locales support states so we try this first
		try {
			$state = $faker->state;
		} catch ( Exception $ex ) {
			$state = '';
		}

		$wc_states = wc()->countries->get_states( $country['code'] );

		/**
		 * if - we have no Faker state, but do have states in WC, so get a state
		 * elseif - we have a Faker state name, so align it with Unicode CLDR state values or get a new one
		 * else - if WC has no states we can default to an empty state
		 */
		if ( empty( $state ) && is_array( $wc_states ) && ! empty( $wc_states ) ) {
			$state = array_rand( $wc_states );
		} elseif ( ! empty( $state ) && is_array( $wc_states ) && ! empty( $wc_states ) ) {
			$key   = array_search( $state, $wc_states );
			$state = ( ! empty( $key ) ) ? $key : array_rand( $wc_states );
		} else {
			$state = '';
		}

		return $state;
	}

	/**
	 * Returns an associative array of all valid Faker locales as $country_code => $locale
	 *
	 * @return array
	 */
	private function get_faker_countries(): array {

		/**
		 * The WordPress Way instead of glob
		 */
		global $wp_filesystem;
		require_once( ABSPATH . '/wp-admin/includes/file.php' );
		WP_Filesystem();

		// Get the list of valid Faker locales
		$locale_list     = $wp_filesystem->dirlist( dirname( plugin_dir_path( __FILE__ ) ) . '/vendor/fakerphp/faker/src/Faker/Provider/' );
		$faker_countries = array();

		foreach ( $locale_list as $key => $value ) {
			if ( 'd' === ( $value['type'] ) ) {
				// we currently use only en locales because special characters are giving us trouble
				if( str_starts_with( $value['name'],  'en' ) ) {
					continue;
				}
				// Extract the country code from the locale
				$country_code = substr( $value['name'], strrpos( $value['name'], '_' ) + 1 );
				// There Can Be Only One - we are overwriting locales where more than one locale exists for a country code
				$faker_countries[ $country_code ] = $value['name'];
			}
		}

		return $faker_countries;
	}

}
