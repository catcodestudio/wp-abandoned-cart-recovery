<?php
/**
 * Cart capture.
 *
 * Logged-in shoppers are recorded as soon as they have something in the cart.
 * Guests are recorded only once they have handed over an e-mail address at
 * checkout — that address arrives from the classic checkout AJAX
 * (update_order_review / billing_email) or, on the block checkout, from the
 * small front-end script that posts it to our REST route.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

use CatCode\AbandonedCart\Pro\Messenger;
use CatCode\AbandonedCart\Pro\TurboSms;

defined( 'ABSPATH' ) || exit;

class Capture {

	/** WooCommerce session key holding the e-mail a guest typed at checkout. */
	public const SESSION_EMAIL = 'catcode_abandoned_cart_email';
	public const SESSION_NAME = 'catcode_abandoned_cart_name';

	/** The phone a guest typed — only kept while the Pro Viber/SMS reminder is on. */
	public const SESSION_PHONE = 'catcode_abandoned_cart_phone';

	/** Throttle marker for the idle-browsing refresh. */
	public const SESSION_TOUCHED = 'catcode_abandoned_cart_touched';

	public function register(): void {
		// Any cart mutation refreshes the row (and the idle clock).
		add_action( 'woocommerce_add_to_cart', array( $this, 'sync' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'sync' ), 20 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'sync' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'sync' ), 20 );
		add_action( 'woocommerce_cart_emptied', array( $this, 'on_emptied' ), 20 );

		// Classic checkout: the e-mail field travels in the AJAX review payload.
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'on_order_review' ), 10, 1 );

		// Both checkouts: once an order exists the cart counts as recovered.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_created' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_store_api_order' ), 20, 1 );

		// A logged-in shopper browsing the shop keeps their row fresh.
		add_action( 'wp_loaded', array( $this, 'maybe_sync_logged_in' ), 30 );
	}

	/**
	 * Session identifier for the current shopper.
	 *
	 * Registered users are keyed by user id so the row survives a new session
	 * cookie; guests use the WooCommerce session customer id.
	 */
	public static function session_key(): string {
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'user-' . $user_id;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			$customer_id = WC()->session->get_customer_id();
			if ( $customer_id ) {
				return 'guest-' . substr( (string) $customer_id, 0, 56 );
			}
		}
		return '';
	}

	/**
	 * Remember the e-mail a guest typed, then persist the cart.
	 */
	public static function remember_email( string $email, string $name = '' ): void {
		$email = sanitize_email( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_EMAIL, $email );
			if ( '' !== $name ) {
				WC()->session->set( self::SESSION_NAME, sanitize_text_field( $name ) );
			}
		}
		self::store();
	}

	/**
	 * Remember the phone a shopper typed, then persist the cart.
	 *
	 * Ignored unless the Viber/SMS reminder is switched on: a phone we would
	 * never text is personal data with no purpose.
	 */
	public static function remember_phone( string $phone, string $name = '' ): void {
		if ( ! Messenger::is_enabled() ) {
			return;
		}
		$phone = TurboSms::normalise_phone( $phone );
		if ( '' === $phone ) {
			return;
		}
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_PHONE, $phone );
			if ( '' !== $name ) {
				WC()->session->set( self::SESSION_NAME, sanitize_text_field( $name ) );
			}
		}
		self::store();
	}

	public function on_order_review( $post_data ): void {
		if ( ! is_string( $post_data ) ) {
			return;
		}
		$parsed = array();
		parse_str( $post_data, $parsed );

		$first = isset( $parsed['billing_first_name'] ) ? sanitize_text_field( (string) $parsed['billing_first_name'] ) : '';
		$last  = isset( $parsed['billing_last_name'] ) ? sanitize_text_field( (string) $parsed['billing_last_name'] ) : '';
		$name  = trim( $first . ' ' . $last );

		$phone = isset( $parsed['billing_phone'] ) ? sanitize_text_field( (string) $parsed['billing_phone'] ) : '';
		if ( '' !== $phone && Messenger::is_enabled() && '' !== TurboSms::normalise_phone( $phone ) && function_exists( 'WC' ) && WC()->session ) {
			// Only set here; remember_email() / store() below writes the row once.
			WC()->session->set( self::SESSION_PHONE, TurboSms::normalise_phone( $phone ) );
		}

		$email = isset( $parsed['billing_email'] ) ? sanitize_email( (string) $parsed['billing_email'] ) : '';
		if ( '' === $email || ! is_email( $email ) ) {
			if ( '' !== $phone ) {
				self::remember_phone( $phone, $name );
			}
			return;
		}

		self::remember_email( $email, $name );
	}

	public function sync(): void {
		self::store();
	}

	public function maybe_sync_logged_in(): void {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() || ! is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		// Browsing alone must not write a row on every page view: refresh at most
		// once every five minutes. Real cart changes still sync immediately via
		// the woocommerce_* hooks above.
		$last = (int) WC()->session->get( self::SESSION_TOUCHED, 0 );
		if ( $last && ( time() - $last ) < 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		WC()->session->set( self::SESSION_TOUCHED, time() );

		self::store();
	}

	/**
	 * Write the current cart to the table.
	 */
	public static function store(): void {
		if ( ! function_exists( 'WC' ) || is_admin() || wp_doing_cron() ) {
			return;
		}
		$wc = WC();
		if ( ! $wc->cart || ! $wc->session ) {
			return;
		}

		$key = self::session_key();
		if ( '' === $key ) {
			return;
		}

		$user_id = get_current_user_id();
		$email   = '';
		$name    = '';

		if ( $user_id > 0 ) {
			$user  = get_userdata( $user_id );
			$email = $user ? (string) $user->user_email : '';
			$name  = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
			if ( '' === $name && $user ) {
				$name = (string) $user->display_name;
			}
		}

		$session_email = (string) $wc->session->get( self::SESSION_EMAIL, '' );
		if ( '' !== $session_email ) {
			$email = $session_email;
		}
		$session_name = (string) $wc->session->get( self::SESSION_NAME, '' );
		if ( '' !== $session_name ) {
			$name = $session_name;
		}

		$phone = '';
		if ( Messenger::is_enabled() ) {
			$phone = (string) $wc->session->get( self::SESSION_PHONE, '' );
			if ( '' === $phone && $user_id > 0 ) {
				$phone = TurboSms::normalise_phone( (string) get_user_meta( $user_id, 'billing_phone', true ) );
			}
		}

		// Guests without an e-mail or (with Viber/SMS on) a phone are not recorded
		// at all — nothing to store, nothing to recover, no personal data collected.
		if ( '' === $email && '' === $phone ) {
			return;
		}

		$items = self::snapshot( $wc->cart );
		$row   = Repository::find_by_session( $key );

		if ( empty( $items ) ) {
			// An emptied cart that never converted is not worth keeping.
			if ( $row && Repository::STATUS_ACTIVE === $row['status'] ) {
				Repository::delete( (int) $row['id'] );
			}
			return;
		}

		$total = 0.0;
		$count = 0;
		foreach ( $items as $item ) {
			$total += (float) $item['line_total'];
			$count += (int) $item['quantity'];
		}

		Repository::upsert(
			array(
				'session_key'   => $key,
				'user_id'       => $user_id,
				'email'         => $email,
				'customer_name' => $name,
				'phone'         => $phone,
				'cart_contents' => wp_json_encode( $items ),
				'cart_total'    => $total,
				'currency'      => get_woocommerce_currency(),
				'item_count'    => $count,
			)
		);
	}

	public function on_emptied(): void {
		$key = self::session_key();
		if ( '' === $key ) {
			return;
		}
		$row = Repository::find_by_session( $key );
		if ( $row && Repository::STATUS_ACTIVE === $row['status'] ) {
			Repository::delete( (int) $row['id'] );
		}
	}

	/**
	 * A serialisable snapshot of the cart, enough to rebuild it later.
	 *
	 * @param \WC_Cart $cart Cart object.
	 * @return array<int,array<string,mixed>>
	 */
	public static function snapshot( $cart ): array {
		$out = array();

		foreach ( $cart->get_cart() as $item ) {
			$product = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data'] : null;
			if ( ! $product ) {
				continue;
			}

			$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$line     = (float) ( $item['line_total'] ?? 0 ) + (float) ( $item['line_tax'] ?? 0 );
			if ( $line <= 0 ) {
				// WooCommerce fills line_total only in calculate_totals(), which
				// runs after woocommerce_add_to_cart — so a row written straight
				// from that hook prices the line the shopper just added at zero,
				// and the reminder quotes a total lower than the real cart.
				$line = (float) $product->get_price() * $quantity;
			}

			$out[] = array(
				'product_id'   => (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'variation'    => isset( $item['variation'] ) && is_array( $item['variation'] ) ? $item['variation'] : array(),
				'quantity'     => $quantity,
				'name'         => $product->get_name(),
				'price'        => (float) $product->get_price(),
				'line_total'   => $line,
			);
		}

		return $out;
	}

	/**
	 * Classic checkout: an order was created for this shopper.
	 *
	 * @param int   $order_id Order id.
	 * @param array $posted   Posted checkout data.
	 * @param mixed $order    Order object.
	 */
	public function on_order_created( $order_id, $posted, $order = null ): void {
		if ( ! $order instanceof \WC_Order ) {
			$order = wc_get_order( $order_id );
		}
		self::mark_recovered( $order );
	}

	/**
	 * Block checkout (Store API).
	 *
	 * @param mixed $order Order object.
	 */
	public function on_store_api_order( $order ): void {
		self::mark_recovered( $order );
	}

	/**
	 * Close every open cart belonging to this buyer.
	 *
	 * @param mixed $order Order object.
	 */
	public static function mark_recovered( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$email   = sanitize_email( (string) $order->get_billing_email() );
		$phone   = TurboSms::normalise_phone( (string) $order->get_billing_phone() );
		$user_id = (int) $order->get_customer_id();
		if ( '' === $email && '' === $phone && $user_id < 1 ) {
			return;
		}

		foreach ( Repository::open_for_customer( $email, $user_id, $phone ) as $row ) {
			Repository::update(
				(int) $row['id'],
				array(
					'status'             => Repository::STATUS_RECOVERED,
					'recovered_order_id' => (int) $order->get_id(),
					'recovered_total'    => (float) $order->get_total(),
					'recovered_at'       => current_time( 'mysql' ),
					'token_hash'         => '',
					'msg_token_hash'     => '',
					'token_expires_at'   => null,
				),
				array( '%s', '%d', '%f', '%s', '%s', '%s', '%s' )
			);

			/**
			 * Fires when an abandoned cart is marked as recovered.
			 *
			 * @param array     $row   The cart row.
			 * @param \WC_Order $order The order that recovered it.
			 */
			do_action( 'catcode_abandoned_cart_recovered', $row, $order );
		}

		// The shopper converted — forget the captured guest e-mail.
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_EMAIL, '' );
			WC()->session->set( self::SESSION_NAME, '' );
			WC()->session->set( self::SESSION_PHONE, '' );
		}
	}
}
