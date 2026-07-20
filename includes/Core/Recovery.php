<?php
/**
 * Recovery links.
 *
 * A link carries a 32-character token generated with wp_generate_password().
 * Only its SHA-256 hash is stored, the lookup is a constant-time hash_equals()
 * comparison, and the token is consumed the moment it is used.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

defined( 'ABSPATH' ) || exit;

class Recovery {

	public const QUERY_VAR = 'catcode_abandoned_cart_token';

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_restore' ), 5 );
	}

	public static function build_link( string $token ): string {
		$base = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), $base );
	}

	public function maybe_restore(): void {
		// Read-only, token-authenticated entry point: the token itself is the
		// credential, so a nonce would be meaningless in an e-mail link.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		if ( '' === $token || ! function_exists( 'WC' ) ) {
			return;
		}

		$hash = hash( 'sha256', $token );
		$row  = Repository::find_by_token_hash( $hash );

		if ( ! $row || ! hash_equals( (string) $row['token_hash'], $hash ) ) {
			self::notice( __( 'This recovery link is no longer valid.', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'error' );
			self::redirect_clean();
			return;
		}

		$expires = (string) $row['token_expires_at'];
		if ( '' === $expires || strtotime( $expires ) < Repository::now() ) {
			Repository::update(
				(int) $row['id'],
				array(
					'token_hash'       => '',
					'token_expires_at' => null,
				),
				array( '%s', '%s' )
			);
			self::notice( __( 'This recovery link has expired.', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'error' );
			self::redirect_clean();
			return;
		}

		$restored = self::restore_cart( $row );

		// One-time token: burn it whether or not every line item survived.
		Repository::update(
			(int) $row['id'],
			array(
				'token_hash'       => '',
				'token_expires_at' => null,
			),
			array( '%s', '%s' )
		);

		if ( $restored > 0 ) {
			self::adopt_identity( $row );
			self::notice( __( 'Welcome back — your cart has been restored.', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'success' );

			/**
			 * Fires after a shopper follows a recovery link successfully.
			 *
			 * @param array $row The cart row.
			 */
			do_action( 'catcode_abandoned_cart_link_used', $row );
		} else {
			self::notice( __( 'Sorry, the products from that cart are no longer available.', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'error' );
		}

		self::redirect_clean();
	}

	/**
	 * Put the stored line items back into the live cart.
	 *
	 * @param array<string,mixed> $row Cart row.
	 * @return int Number of items restored.
	 */
	private static function restore_cart( array $row ): int {
		$items = json_decode( (string) $row['cart_contents'], true );
		if ( ! is_array( $items ) ) {
			return 0;
		}

		if ( ! WC()->cart ) {
			return 0;
		}

		WC()->cart->empty_cart();
		$restored = 0;

		foreach ( $items as $item ) {
			$product_id = (int) ( $item['product_id'] ?? 0 );
			if ( $product_id < 1 ) {
				continue;
			}
			$product = wc_get_product( (int) ( $item['variation_id'] ?? 0 ) > 0 ? (int) $item['variation_id'] : $product_id );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}

			$added = WC()->cart->add_to_cart(
				$product_id,
				max( 1, (int) ( $item['quantity'] ?? 1 ) ),
				(int) ( $item['variation_id'] ?? 0 ),
				isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array()
			);
			if ( $added ) {
				++$restored;
			}
		}

		return $restored;
	}

	/**
	 * Pre-fill the checkout with the address we already know.
	 *
	 * @param array<string,mixed> $row Cart row.
	 */
	private static function adopt_identity( array $row ): void {
		$email = sanitize_email( (string) $row['email'] );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}

		if ( WC()->session ) {
			WC()->session->set( Capture::SESSION_EMAIL, $email );
			if ( '' !== (string) $row['customer_name'] ) {
				WC()->session->set( Capture::SESSION_NAME, (string) $row['customer_name'] );
			}
		}

		if ( WC()->customer && ! is_user_logged_in() ) {
			WC()->customer->set_billing_email( $email );

			$name = trim( (string) $row['customer_name'] );
			if ( '' !== $name ) {
				$parts = explode( ' ', $name, 2 );
				WC()->customer->set_billing_first_name( $parts[0] );
				if ( isset( $parts[1] ) ) {
					WC()->customer->set_billing_last_name( $parts[1] );
				}
			}
			WC()->customer->save();
		}
	}

	private static function notice( string $message, string $type ): void {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' === $type ? 'error' : 'success' );
		}
	}

	/**
	 * Drop the token from the address bar so it is never bookmarked or leaked
	 * through a referrer header.
	 */
	private static function redirect_clean(): void {
		$url = remove_query_arg( self::QUERY_VAR, function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
