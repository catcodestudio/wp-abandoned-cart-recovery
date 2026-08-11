<?php
/**
 * Admin AJAX: licence key, trial, notice dismissal.
 *
 * Every Pro-side action is refused on the server (not merely greyed out in the
 * form): a forged POST from an install without a licence must get a refusal,
 * not the feature.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Admin;

use CatCode\AbandonedCart\Pro\License;

defined( 'ABSPATH' ) || exit;

class Ajax {

	public const NONCE = 'catcode_abandoned_cart_admin';

	public static function register(): void {
		add_action( 'wp_ajax_catcode_abandoned_cart_license', array( __CLASS__, 'handle_license' ) );
		add_action( 'wp_ajax_catcode_abandoned_cart_trial', array( __CLASS__, 'handle_trial' ) );
	}

	private static function guard(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) ), 403 );
		}
	}

	private static function post( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran above.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/** Activate a purchased key, or release it from this site. */
	public static function handle_license(): void {
		self::guard();

		if ( 'deactivate' === self::post( 'mode' ) ) {
			License::deactivate();
			wp_send_json_success(
				array(
					'message' => __( 'Licence released from this site — the key can now be used on another store.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					'status'  => License::describe(),
				)
			);
		}

		$result = License::activate( self::post( 'key' ) );

		if ( ! empty( $result['ok'] ) ) {
			wp_send_json_success(
				array(
					'message' => __( 'Licence active — the Pro features are unlocked.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					'status'  => License::describe(),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => License::error_message( (string) ( isset( $result['error'] ) ? $result['error'] : '' ) ),
				'status'  => License::describe(),
			)
		);
	}

	/** The trial starts here and nowhere else — i.e. only on an explicit click. */
	public static function handle_trial(): void {
		self::guard();

		$result = License::start_trial( sanitize_email( self::post( 'email' ) ) );

		if ( ! empty( $result['ok'] ) ) {
			$until = (string) ( isset( $result['expires_at'] ) ? $result['expires_at'] : '' );
			$until = '' !== $until ? gmdate( 'd.m.Y', (int) strtotime( $until ) ) : '';

			wp_send_json_success(
				array(
					'message' => '' !== $until
						? sprintf(
							/* translators: %s: date the trial ends. */
							__( 'Trial started — Pro works until %s. The key has also been e-mailed to you.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
							$until
						)
						: __( 'Trial started. The key has also been e-mailed to you.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					'key'     => (string) ( isset( $result['key'] ) ? $result['key'] : '' ),
					'status'  => License::describe(),
				)
			);
		}

		wp_send_json_error(
			array(
				'message' => License::error_message( (string) ( isset( $result['error'] ) ? $result['error'] : '' ) ),
				'status'  => License::describe(),
			)
		);
	}
}
