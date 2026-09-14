<?php
/**
 * Pro: the single Viber/SMS reminder for an abandoned cart.
 *
 * One message per cart, never a chain: a text lands next to messages from
 * family, and a second one about the same cart is what gets the sender
 * blocked. Follow-ups stay in the e-mail chain.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Pro;

use CatCode\AbandonedCart\Core\Mailer;
use CatCode\AbandonedCart\Core\Recovery;
use CatCode\AbandonedCart\Core\Repository;
use CatCode\AbandonedCart\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Messenger {

	/** Viber allows 1000 characters; an SMS that long is already several paid parts. */
	private const MAX_LEN = 1000;

	public static function default_text(): string {
		return __( '{store_name}: you left items worth {cart_total} in your cart. It is saved for you: {recovery_link}', 'catcode-abandoned-cart-recovery-for-woocommerce' );
	}

	public static function is_enabled(): bool {
		return License::is_pro()
			&& Settings::is_on( 'sms_enabled' )
			&& '' !== trim( (string) Settings::get( 'turbosms_token', '' ) )
			&& self::senders_ready();
	}

	private static function senders_ready(): bool {
		$channel = self::channel();
		$sms     = trim( (string) Settings::get( 'sms_sender', '' ) );
		$viber   = trim( (string) Settings::get( 'viber_sender', '' ) );

		if ( TurboSms::CHANNEL_SMS === $channel ) {
			return '' !== $sms;
		}
		if ( TurboSms::CHANNEL_VIBER === $channel ) {
			return '' !== $viber;
		}
		return '' !== $sms && '' !== $viber;
	}

	public static function channel(): string {
		$channel = (string) Settings::get( 'sms_channel', TurboSms::CHANNEL_HYBRID );
		return in_array( $channel, TurboSms::channels(), true ) ? $channel : TurboSms::CHANNEL_HYBRID;
	}

	/**
	 * Is it a sensible local hour to write to somebody's phone?
	 *
	 * Site timezone. from > to spans midnight (21 → 9 blocks the night);
	 * from == to disables the window.
	 */
	public static function in_quiet_hours( ?int $hour = null ): bool {
		$from = min( 23, max( 0, Settings::get_int( 'sms_quiet_from', 21 ) ) );
		$to   = min( 23, max( 0, Settings::get_int( 'sms_quiet_to', 9 ) ) );
		$hour = null === $hour ? (int) wp_date( 'G' ) : $hour;

		if ( $from === $to ) {
			return false;
		}
		return $from > $to ? ( $hour >= $from || $hour < $to ) : ( $hour >= $from && $hour < $to );
	}

	/**
	 * Fill the template for one cart. Issues the recovery token, so call it only
	 * right before the message is actually sent.
	 */
	public static function compose( array $cart, string $template ): string {
		$token = Repository::issue_message_token( (int) $cart['id'], max( 1, Settings::get_int( 'token_lifetime', 7 ) ) );

		$text = strtr(
			$template,
			array(
				'{customer_name}' => Mailer::customer_name( $cart ),
				'{store_name}'    => Mailer::store_name(),
				'{cart_total}'    => html_entity_decode( wp_strip_all_tags( wc_price( (float) $cart['cart_total'], array( 'currency' => (string) $cart['currency'] ) ) ), ENT_QUOTES, 'UTF-8' ),
				'{item_count}'    => (string) (int) $cart['item_count'],
				'{recovery_link}' => Recovery::build_link( $token ),
				'{coupon_code}'   => (string) $cart['coupon_code'],
			)
		);

		$text = trim( (string) preg_replace( "/[ \t]+\n/", "\n", $text ) );

		return mb_strlen( $text ) > self::MAX_LEN ? mb_substr( $text, 0, self::MAX_LEN ) : $text;
	}

	/**
	 * Send the reminder for one cart and record the outcome.
	 *
	 * The row is marked handled even when TurboSMS refuses (bad number, no
	 * balance) — otherwise every scan would retry and bill again.
	 *
	 * @return array{ok:bool,code:int,status:string,message_id:string}
	 */
	public static function send( array $cart ): array {
		$template = trim( (string) Settings::get( 'sms_text', '' ) );
		$text     = self::compose( $cart, '' !== $template ? $template : self::default_text() );

		$api    = new TurboSms( (string) Settings::get( 'turbosms_token', '' ) );
		$result = $api->send(
			(string) $cart['phone'],
			$text,
			self::channel(),
			trim( (string) Settings::get( 'sms_sender', '' ) ),
			trim( (string) Settings::get( 'viber_sender', '' ) )
		);

		Repository::update(
			(int) $cart['id'],
			array(
				'msg_sent'    => 1,
				'last_msg_at' => current_time( 'mysql' ),
				'msg_status'  => substr( $result['ok'] ? 'sent' : ( 'error ' . $result['code'] . ' ' . $result['status'] ), 0, 40 ),
			),
			array( '%d', '%s', '%s' )
		);

		/**
		 * Fires after a Viber/SMS reminder was handed to TurboSMS (or refused).
		 *
		 * @param array $cart   The cart row.
		 * @param array $result TurboSMS outcome.
		 */
		do_action( 'catcode_abandoned_cart_message_sent', $cart, $result );

		return $result;
	}

	/** Pending carts with a phone, oldest first, respecting delay, cooldown and quiet hours. */
	public static function send_due(): void {
		if ( ! self::is_enabled() || self::in_quiet_hours() ) {
			return;
		}

		$delay    = max( 1, Settings::get_int( 'sms_delay', 30 ) );
		$cooldown = Settings::get_int( 'email_cooldown', 3 );
		$now      = Repository::now();

		foreach ( Repository::pending_messages() as $row ) {
			if ( empty( $row['abandoned_at'] ) || strtotime( (string) $row['abandoned_at'] ) + $delay * MINUTE_IN_SECONDS > $now ) {
				continue;
			}

			// Same number already texted for another cart recently: mark handled, do not pay again.
			if ( Repository::messaged_recently( (string) $row['phone'], $cooldown, (int) $row['id'] ) ) {
				Repository::update(
					(int) $row['id'],
					array(
						'msg_sent'   => 1,
						'msg_status' => 'skipped: cooldown',
					),
					array( '%d', '%s' )
				);
				continue;
			}

			self::send( $row );
		}
	}
}
