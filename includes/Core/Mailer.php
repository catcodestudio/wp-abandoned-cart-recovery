<?php
/**
 * Builds and sends the reminder e-mails.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

use CatCode\AbandonedCart\Pro\Coupons;

defined( 'ABSPATH' ) || exit;

class Mailer {

	/**
	 * Send one reminder step for one cart.
	 *
	 * @param array<string,mixed> $cart Cart row.
	 * @param array{step:int,delay:int,subject:string,body:string} $step Step config.
	 * @return bool Whether wp_mail() accepted the message.
	 */
	public static function send( array $cart, array $step ): bool {
		$email = sanitize_email( (string) $cart['email'] );
		if ( '' === $email || ! is_email( $email ) ) {
			return false;
		}

		$token = Repository::issue_token(
			(int) $cart['id'],
			max( 1, Settings::get_int( 'token_lifetime', 7 ) )
		);
		$link  = Recovery::build_link( $token );

		$coupon_code = Coupons::code_for_cart( $cart, (int) $step['step'] );
		$coupon_text = Coupons::describe( $coupon_code );

		$replacements = array(
			'{customer_name}' => self::customer_name( $cart ),
			'{store_name}'    => self::store_name(),
			'{cart_items}'    => self::items_text( $cart ),
			'{recovery_link}' => $link,
			'{coupon_code}'   => $coupon_code,
			'{coupon}'        => $coupon_text,
		);

		$subject = strtr( (string) $step['subject'], $replacements );
		$body    = strtr( (string) $step['body'], $replacements );

		/**
		 * Filter the reminder before it is handed to wp_mail().
		 *
		 * @param array $mail {subject, body} pair.
		 * @param array $cart The cart row.
		 * @param array $step The reminder step.
		 */
		$mail = apply_filters(
			'catcode_abandoned_cart_reminder_mail',
			array(
				'subject' => wp_strip_all_tags( $subject ),
				'body'    => $body,
			),
			$cart,
			$step
		);

		$html = self::wrap_html( (string) $mail['body'] );

		add_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );
		$sent = wp_mail( $email, (string) $mail['subject'], $html );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'content_type' ) );

		if ( $sent ) {
			Repository::update(
				(int) $cart['id'],
				array(
					'emails_sent'   => (int) $cart['emails_sent'] + 1,
					'last_email_at' => current_time( 'mysql' ),
				),
				array( '%d', '%s' )
			);

			/**
			 * Fires after a reminder has been sent.
			 *
			 * @param array $cart The cart row.
			 * @param int   $step Reminder step number.
			 */
			do_action( 'catcode_abandoned_cart_reminder_sent', $cart, (int) $step['step'] );
		} else {
			Logger::error( 'Reminder e-mail could not be sent for cart #' . (int) $cart['id'] );
		}

		return (bool) $sent;
	}

	public static function content_type(): string {
		return 'text/html';
	}

	public static function store_name(): string {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * @param array<string,mixed> $cart Cart row.
	 */
	public static function customer_name( array $cart ): string {
		$name = trim( (string) $cart['customer_name'] );
		if ( '' !== $name ) {
			return $name;
		}
		return __( 'there', 'catcode-abandoned-cart-recovery-for-woocommerce' );
	}

	/**
	 * Plain-text item list for the {cart_items} placeholder.
	 *
	 * @param array<string,mixed> $cart Cart row.
	 */
	public static function items_text( array $cart ): string {
		$items = json_decode( (string) $cart['cart_contents'], true );
		if ( ! is_array( $items ) || ! $items ) {
			return '';
		}

		$lines = array();
		foreach ( $items as $item ) {
			if ( ! isset( $item['name'] ) ) {
				continue;
			}
			$price   = function_exists( 'wc_price' )
				? wp_strip_all_tags( wc_price( (float) ( $item['line_total'] ?? 0 ) ) )
				: (string) ( $item['line_total'] ?? 0 );
			$lines[] = sprintf(
				/* translators: 1: product name, 2: quantity, 3: line total. */
				__( '%1$s — %2$d pcs — %3$s', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				(string) $item['name'],
				(int) ( $item['quantity'] ?? 1 ),
				$price
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Turn the plain-text template into a minimal, safe HTML e-mail.
	 */
	private static function wrap_html( string $body ): string {
		// Linkify the recovery URL so the shopper gets a clickable link.
		$escaped = esc_html( $body );
		$escaped = preg_replace_callback(
			'#(https?://[^\s<]+)#',
			static function ( $m ) {
				$url = esc_url( html_entity_decode( $m[1], ENT_QUOTES, 'UTF-8' ) );
				return '<a href="' . $url . '">' . esc_html( $url ) . '</a>';
			},
			$escaped
		);

		return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#23282d">'
			. nl2br( (string) $escaped )
			. '</div>';
	}
}
