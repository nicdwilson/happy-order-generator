<?php
/**
 * Keeps WooCommerce customer emails for generated customers away from the
 * addresses on their accounts: they're sent to one address instead, or not
 * sent at all.
 *
 * @package Happy_Order_Generator
 */

namespace Happy_Order_Generator;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Customer_Emails {

	/**
	 * Setting value: send generated customers' emails to one address.
	 */
	const REDIRECT = 'redirect';

	/**
	 * Setting value: don't send generated customers' emails.
	 */
	const BLOCK = 'block';

	/**
	 * Hooks the recipient filter of every customer email. Runs on the
	 * woocommerce_email action, once all email classes are registered.
	 *
	 * @param \WC_Emails $mailer WooCommerce's mailer.
	 *
	 * @return void
	 */
	public static function register( $mailer ): void {
		foreach ( $mailer->get_emails() as $email ) {
			if ( $email instanceof \WC_Email && $email->is_customer_email() ) {
				add_filter( 'woocommerce_email_recipient_' . $email->id, array( __CLASS__, 'filter_recipient' ), PHP_INT_MAX, 2 );
			}
		}
	}

	/**
	 * Sends the email to the configured address, or to nobody, when it's for
	 * a generated customer. WooCommerce doesn't send an email with no recipient.
	 *
	 * @param string|null $recipient Comma-separated recipients.
	 * @param mixed       $object    What the email is about: usually an order or a user.
	 *
	 * @return string|null
	 */
	public static function filter_recipient( $recipient, $object = null ) {
		if ( ! self::is_for_generated_customer( (string) $recipient, $object ) ) {
			return $recipient;
		}

		return self::BLOCK === self::get_mode() ? '' : self::get_address();
	}

	/**
	 * What to do with generated customers' emails: REDIRECT (the default) or BLOCK.
	 *
	 * @return string
	 */
	public static function get_mode(): string {
		$settings = Order_Generator::get_settings();

		return self::BLOCK === ( $settings['customer_emails'] ?? '' ) ? self::BLOCK : self::REDIRECT;
	}

	/**
	 * The address generated customers' emails go to: the one in the settings,
	 * or hog-customer@ the site's domain.
	 *
	 * @return string
	 */
	public static function get_address(): string {
		$settings = Order_Generator::get_settings();
		$address  = (string) ( $settings['customer_email_address'] ?? '' );

		return is_email( $address ) ? $address : self::get_default_address();
	}

	/**
	 * hog-customer@ the site's domain. WordPress rejects domains with no dot,
	 * such as localhost, so those sites get hog-customer@example.com.
	 *
	 * @return string
	 */
	public static function get_default_address(): string {
		$host    = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$address = 'hog-customer@' . $host;

		return is_email( $address ) ? $address : 'hog-customer@example.com';
	}

	/**
	 * Whether an email is for a generated customer: the order's customer or
	 * the user it's about was created by the generator, or one of the
	 * recipients is a generated customer's address.
	 *
	 * @param string $recipient Comma-separated recipients.
	 * @param mixed  $object    What the email is about.
	 *
	 * @return bool
	 */
	private static function is_for_generated_customer( string $recipient, $object ): bool {
		if ( $object instanceof \WC_Order && Customer::is_generated( (int) $object->get_customer_id() ) ) {
			return true;
		}

		if ( $object instanceof \WP_User && Customer::is_generated( (int) $object->ID ) ) {
			return true;
		}

		foreach ( array_filter( array_map( 'trim', explode( ',', $recipient ) ) ) as $address ) {
			$user = get_user_by( 'email', $address );
			if ( $user && Customer::is_generated( (int) $user->ID ) ) {
				return true;
			}
		}

		return false;
	}
}
