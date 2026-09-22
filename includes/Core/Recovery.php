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

	/**
	 * ⚠ Two steps on purpose. The link is opened from a mail client or webmail,
	 * i.e. from ANOTHER site. Two things break when the GET does the work:
	 *
	 * 1. Link scanners (Outlook SafeLinks, Proofpoint, Barracuda, corporate
	 *    antivirus) fetch every URL in a message before the human sees it. The
	 *    one-time token was burned by that fetch, and the shopper then read
	 *    "This recovery link is no longer valid."
	 * 2. A shop whose session cookie is SameSite=Strict gets no cookie on a
	 *    request coming from another site nor on any redirect in the same
	 *    chain, so the restored cart landed in a session the next page never
	 *    saw — "cart is empty".
	 *
	 * So the GET only answers a tiny page that re-submits the token by POST
	 * from our own origin; that request carries the session cookie and does
	 * the actual work.
	 */
	public function maybe_restore(): void {
		// Read-only, token-authenticated entry point: the token itself is the
		// credential, so a nonce would be meaningless in an e-mail link.
		$is_post = 'POST' === strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );

		// phpcs:disable WordPress.Security.NonceVerification
		$raw = $is_post
			? ( $_POST[ self::QUERY_VAR ] ?? '' )
			: ( $_GET[ self::QUERY_VAR ] ?? '' );
		// phpcs:enable WordPress.Security.NonceVerification

		if ( '' === $raw || ! is_string( $raw ) ) {
			return;
		}

		$token = sanitize_text_field( wp_unslash( $raw ) );
		if ( ! preg_match( '/^[A-Za-z0-9]{16,64}$/', $token ) || ! function_exists( 'WC' ) ) {
			return;
		}

		if ( ! $is_post ) {
			self::bounce_page( $token );
			return;
		}

		$hash = hash( 'sha256', $token );
		$row  = Repository::find_by_token_hash( $hash );

		if ( ! $row || ! ( hash_equals( (string) $row['token_hash'], $hash ) || hash_equals( (string) $row['msg_token_hash'], $hash ) ) ) {
			self::notice( __( 'This recovery link is no longer valid.', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'error' );
			self::redirect_clean();
			return;
		}

		$expires = (string) $row['token_expires_at'];
		if ( '' === $expires || strtotime( $expires ) < Repository::now() ) {
			self::burn_tokens( (int) $row['id'] );
			self::notice( __( 'This recovery link has expired.', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'error' );
			self::redirect_clean();
			return;
		}

		$restored = self::restore_cart( $row );

		// One-time token: burn it (and its e-mail / message twin) whether or not
		// every line item survived.
		self::burn_tokens( (int) $row['id'] );

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
	 * The intermediate page of the recovery link: posts the token back to
	 * maybe_restore() from our own origin, so the session cookie travels with
	 * it. A visible button covers browsers with scripts switched off.
	 */
	private static function bounce_page( string $token ): void {
		$action = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' );
		$title  = __( 'One moment — we are putting your cart back together…', 'catcode-abandoned-cart-recovery-for-woocommerce' );
		$button = __( 'Open my cart', 'catcode-abandoned-cart-recovery-for-woocommerce' );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );

		echo '<!DOCTYPE html><html lang="' . esc_attr( str_replace( '_', '-', get_locale() ) ) . '"><head><meta charset="utf-8">'
			. '<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
			. '<title>' . esc_html( $title ) . '</title></head>'
			. '<body style="font-family:Arial,Helvetica,sans-serif;text-align:center;padding:48px 16px;color:#23282d">'
			. '<form id="catcode-acr-recover" method="post" action="' . esc_url( $action ) . '">'
			. '<input type="hidden" name="' . esc_attr( self::QUERY_VAR ) . '" value="' . esc_attr( $token ) . '">'
			. '<p>' . esc_html( $title ) . '</p>'
			. '<button type="submit" style="padding:10px 22px;font-size:15px;cursor:pointer">' . esc_html( $button ) . '</button>'
			. '</form>'
			. '<script>document.getElementById("catcode-acr-recover").submit();</script>'
			. '</body></html>';
		exit;
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
	private static function burn_tokens( int $id ): void {
		Repository::update(
			$id,
			array(
				'token_hash'       => '',
				'msg_token_hash'   => '',
				'token_expires_at' => null,
			),
			array( '%s', '%s', '%s' )
		);
	}

	private static function adopt_identity( array $row ): void {
		$email = sanitize_email( (string) $row['email'] );
		$email = ( '' !== $email && is_email( $email ) ) ? $email : '';
		$phone = (string) ( $row['phone'] ?? '' );
		if ( '' === $email && '' === $phone ) {
			return;
		}

		if ( WC()->session ) {
			if ( '' !== $email ) {
				WC()->session->set( Capture::SESSION_EMAIL, $email );
			}
			if ( '' !== $phone ) {
				WC()->session->set( Capture::SESSION_PHONE, $phone );
			}
			if ( '' !== (string) $row['customer_name'] ) {
				WC()->session->set( Capture::SESSION_NAME, (string) $row['customer_name'] );
			}
		}

		if ( WC()->customer && ! is_user_logged_in() ) {
			if ( '' !== $email ) {
				WC()->customer->set_billing_email( $email );
			}
			if ( '' !== $phone ) {
				// The block checkout shows the phone inside the shipping address.
				WC()->customer->set_billing_phone( '+' . $phone );
				WC()->customer->set_shipping_phone( '+' . $phone );
			}

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
