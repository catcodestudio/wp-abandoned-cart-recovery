<?php
/**
 * Pro: personal discount coupon attached to the follow-up reminders.
 *
 * One coupon per cart, restricted to the shopper's e-mail address, single use,
 * with its own expiry date. Created lazily the first time a reminder that
 * carries a coupon goes out, then reused for later steps.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Pro;

use CatCode\AbandonedCart\Core\Logger;
use CatCode\AbandonedCart\Core\Repository;
use CatCode\AbandonedCart\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Coupons {

	/**
	 * Should this reminder step carry a coupon?
	 */
	public static function applies_to_step( int $step ): bool {
		if ( ! License::is_pro() || ! Settings::is_on( 'coupon_enabled' ) ) {
			return false;
		}
		return $step >= max( 1, Settings::get_int( 'coupon_from_email', 2 ) );
	}

	/**
	 * Coupon code for this cart, creating it on first use.
	 *
	 * @param array<string,mixed> $cart Cart row.
	 * @return string Coupon code, empty when disabled or on failure.
	 */
	public static function code_for_cart( array $cart, int $step ): string {
		if ( ! self::applies_to_step( $step ) ) {
			return '';
		}

		$existing = trim( (string) ( $cart['coupon_code'] ?? '' ) );
		if ( '' !== $existing ) {
			return $existing;
		}

		$email = sanitize_email( (string) $cart['email'] );
		if ( '' === $email || ! class_exists( 'WC_Coupon' ) ) {
			return '';
		}

		$type   = 'fixed_cart' === Settings::get( 'coupon_type', 'percent' ) ? 'fixed_cart' : 'percent';
		$amount = (float) Settings::get( 'coupon_amount', 10 );
		if ( $amount <= 0 ) {
			return '';
		}
		$days = max( 1, Settings::get_int( 'coupon_expiry_days', 7 ) );
		$code = 'CATCODE-BACK-' . strtoupper( wp_generate_password( 8, false ) );

		try {
			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( $type );
			$coupon->set_amount( $amount );
			$coupon->set_individual_use( true );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_email_restrictions( array( $email ) );
			$coupon->set_date_expires( time() + ( $days * DAY_IN_SECONDS ) );
			$coupon->set_description(
				sprintf(
					/* translators: %s: customer e-mail address. */
					__( 'Abandoned cart recovery coupon for %s', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					$email
				)
			);
			$coupon->save();
		} catch ( \Exception $e ) {
			Logger::error( 'Coupon creation failed: ' . $e->getMessage() );
			return '';
		}

		Repository::update( (int) $cart['id'], array( 'coupon_code' => $code ), array( '%s' ) );

		return $code;
	}

	/**
	 * Human-readable coupon line for the {coupon} placeholder.
	 */
	public static function describe( string $code ): string {
		if ( '' === $code ) {
			return '';
		}

		$amount = (float) Settings::get( 'coupon_amount', 10 );
		$days   = max( 1, Settings::get_int( 'coupon_expiry_days', 7 ) );

		if ( 'fixed_cart' === Settings::get( 'coupon_type', 'percent' ) ) {
			$value = function_exists( 'wp_strip_all_tags' ) && function_exists( 'wc_price' )
				? wp_strip_all_tags( wc_price( $amount ) )
				: (string) $amount;
		} else {
			$value = $amount . '%';
		}

		return sprintf(
			/* translators: 1: discount value, 2: coupon code, 3: number of days the coupon stays valid. */
			__( 'Here is %1$s off with the code %2$s — it is valid for %3$d days.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			$value,
			$code,
			$days
		);
	}
}
