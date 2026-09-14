<?php
/**
 * Settings repository — a single option holding the whole configuration.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

defined( 'ABSPATH' ) || exit;

class Settings {

	public const OPTION = 'catcode_abandoned_cart_settings';

	/** @var array|null */
	private static $cache = null;

	public static function defaults(): array {
		return array(
			// Capture / lifecycle.
			'abandon_after'      => 60,   // Minutes of inactivity before a cart counts as abandoned.
			'token_lifetime'     => 7,    // Days a recovery link stays valid.
			'retention_days'     => 90,   // Days before a finished cart row is deleted.
			'email_cooldown'     => 3,    // Days before the same address may be mailed again.

			// Reminder e-mail 1 (free).
			'email_1_delay'      => 60,   // Minutes after abandonment.
			'email_1_subject'    => 'You left something behind at {store_name}',
			'email_1_body'       => self::default_body( 1 ),

			// Reminder e-mails 2 and 3 (Pro).
			'email_2_enabled'    => 'no',
			'email_2_delay'      => 1440,
			'email_2_subject'    => 'Still thinking it over? Your cart at {store_name} is waiting',
			'email_2_body'       => self::default_body( 2 ),

			'email_3_enabled'    => 'no',
			'email_3_delay'      => 4320,
			'email_3_subject'    => 'Last reminder about your cart at {store_name}',
			'email_3_body'       => self::default_body( 3 ),

			// Pro: personal coupon.
			'coupon_enabled'     => 'no',
			'coupon_from_email'  => 2,          // Attach the coupon starting from this e-mail.
			'coupon_type'        => 'percent',  // percent | fixed_cart.
			'coupon_amount'      => 10,
			'coupon_expiry_days' => 7,

			// Pro: Telegram notification for the shop owner.
			'telegram_enabled'   => 'no',
			'telegram_bot_token' => '',
			'telegram_chat_id'   => '',

			// Pro: one Viber / SMS reminder through TurboSMS for shoppers who left a phone.
			'sms_enabled'        => 'no',
			'turbosms_token'     => '',
			'sms_channel'        => 'viber_sms', // viber_sms (Viber, SMS fallback) | viber | sms.
			'sms_sender'         => '',
			'viber_sender'       => '',
			'sms_delay'          => 30,    // Minutes after abandonment.
			'sms_text'           => '',    // Empty = Pro\Messenger::default_text().
			'sms_quiet_from'     => 21,    // No texts from this hour…
			'sms_quiet_to'       => 9,     // …until this hour (site timezone).

			// Licensing (see Pro\License — the CatCode licence-server client).
			'license_key'        => '',
			'license_status'     => '',
			'license_checked_at' => '',
			'license_expires_at' => '',
			'license_data'       => '',
			// trial | purchase — written from the server's answer (see Pro\License::store()).
			'license_kind'       => '',
			// '1' once the server confirmed a purchased key: Pro then outlives the term.
			'license_owned'      => '',
			// 0 = the owner has never started the trial. Nothing but an explicit
			// click on "Try for 7 days" may ever write this.
			'trial_started'      => 0,
		);
	}

	public static function default_body( int $which ): string {
		switch ( $which ) {
			case 3:
				return "Hi {customer_name},\n\n"
					. "This is the last reminder about the cart you left at {store_name}. We are holding it for a little longer:\n\n"
					. "{cart_items}\n\n"
					. "{coupon}\n"
					. "Finish your order here: {recovery_link}\n\n"
					. '— {store_name}';

			case 2:
				return "Hi {customer_name},\n\n"
					. "Your cart at {store_name} is still saved. Here is what is in it:\n\n"
					. "{cart_items}\n\n"
					. "{coupon}\n"
					. "Pick up where you left off: {recovery_link}\n\n"
					. '— {store_name}';

			case 1:
			default:
				return "Hi {customer_name},\n\n"
					. "You added a few things to your cart at {store_name} but did not complete the order:\n\n"
					. "{cart_items}\n\n"
					. "Your cart is saved — one click takes you straight back to it: {recovery_link}\n\n"
					. '— {store_name}';
		}
	}

	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		self::$cache = wp_parse_args( $raw, self::defaults() );
		return self::$cache;
	}

	/**
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function get_int( string $key, int $default = 0 ): int {
		return (int) self::get( $key, $default );
	}

	public static function is_on( string $key ): bool {
		return 'yes' === self::get( $key, 'no' );
	}

	/**
	 * Save the values a form submitted. Anything the form does not carry keeps
	 * its stored value — the licence fields live in the same option and must
	 * survive a plain "Save settings".
	 *
	 * @param array<string,mixed> $values Posted values.
	 */
	public static function save( array $values ): void {
		self::update( $values );
	}

	/**
	 * Write a few keys without touching the rest — the licence client stores its
	 * key, status and trial marker in one go, and must never clobber the
	 * settings the owner is editing in another tab.
	 *
	 * @param array<string,mixed> $patch Keys to overwrite.
	 */
	public static function update( array $patch ): void {
		// Read straight from the option, not from the static cache: the cache may
		// predate a write made earlier in this same request.
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		update_option( self::OPTION, wp_parse_args( $patch, wp_parse_args( $raw, self::defaults() ) ), false );
		self::$cache = null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/**
	 * The configured e-mail steps, gated by the licence.
	 *
	 * Free installs get step 1 only; Pro adds steps 2 and 3 when enabled.
	 *
	 * @return array<int,array{step:int,delay:int,subject:string,body:string}>
	 */
	public static function email_steps(): array {
		$steps = array(
			array(
				'step'    => 1,
				'delay'   => max( 1, self::get_int( 'email_1_delay', 60 ) ),
				'subject' => (string) self::get( 'email_1_subject', '' ),
				'body'    => (string) self::get( 'email_1_body', '' ),
			),
		);

		if ( ! \CatCode\AbandonedCart\Pro\License::is_pro() ) {
			return $steps;
		}

		foreach ( array( 2, 3 ) as $n ) {
			if ( ! self::is_on( 'email_' . $n . '_enabled' ) ) {
				continue;
			}
			$steps[] = array(
				'step'    => $n,
				'delay'   => max( 1, self::get_int( 'email_' . $n . '_delay', 1440 ) ),
				'subject' => (string) self::get( 'email_' . $n . '_subject', '' ),
				'body'    => (string) self::get( 'email_' . $n . '_body', '' ),
			);
		}

		return $steps;
	}
}
