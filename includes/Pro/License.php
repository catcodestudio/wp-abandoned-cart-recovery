<?php
/**
 * CatCode licence-server client — the same contract every CatCode module uses.
 *
 *  - the owner pastes a purchased key  → activate;
 *  - or clicks "Try Pro for 7 days"    → e-mail → start_trial() issues a real
 *    7-day key through POST /wp-json/catcode/v1/trial and activates it right
 *    away. The trial NEVER starts by itself: a fresh install is simply the free
 *    tier until the owner asks for something else;
 *  - a daily cron re-checks the stored key and refreshes the cache;
 *  - is_pro() reads that cache: valid (or still within GRACE_DAYS of the last
 *    successful check) → the Pro features are unlocked.
 *
 * Product binding: the server returns product_slug; a present but different
 * value is a hard refusal, so a key for another CatCode module cannot unlock
 * Pro here.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Pro;

use CatCode\AbandonedCart\Core\Settings;

defined( 'ABSPATH' ) || exit;

class License {

	private const DEFAULT_ENDPOINT = 'https://catcode.com.ua/wp-json/catcode/v1/license';

	private const DEFAULT_TRIAL_ENDPOINT = 'https://catcode.com.ua/wp-json/catcode/v1/trial';

	/** Trial length — used for wording only; the real date comes from the server. */
	public const TRIAL_DAYS = 7;

	/** Canonical module slug — must match the post_name of the `module` CPT. */
	public const EXPECTED_SLUG = 'catcode-abandoned-cart-recovery-for-woocommerce';

	/** Module page: buying a licence and leaving a review. */
	public const MODULE_URL = 'https://catcode.com.ua/modules/catcode-abandoned-cart-recovery-for-woocommerce/';

	/** How long the cached verdict stays authoritative while the server is down. */
	public const GRACE_DAYS = 14;

	private const HTTP_TIMEOUT = 8;

	public static function endpoint(): string {
		if ( defined( 'CATCODE_LICENSE_API' ) ) {
			return (string) constant( 'CATCODE_LICENSE_API' );
		}
		return self::DEFAULT_ENDPOINT;
	}

	public static function trial_endpoint(): string {
		if ( defined( 'CATCODE_TRIAL_API' ) ) {
			return (string) constant( 'CATCODE_TRIAL_API' );
		}
		return self::DEFAULT_TRIAL_ENDPOINT;
	}

	public static function key(): string {
		return trim( (string) Settings::get( 'license_key', '' ) );
	}

	/** A key is stored — regardless of whether the server has blessed it yet. */
	public static function has_license(): bool {
		return '' !== self::key();
	}

	/** When the owner started the trial; 0 = never. */
	public static function trial_started(): int {
		return (int) Settings::get( 'trial_started', 0 );
	}

	/**
	 * The trial is offered once per install, and never when a key is already
	 * stored: someone who bought a licence has nothing left to try.
	 */
	public static function trial_available(): bool {
		return self::trial_started() <= 0 && ! self::has_license();
	}

	/** Pro is on, and it is the trial rather than a purchase. */
	public static function on_trial(): bool {
		return self::trial_started() > 0 && self::is_pro();
	}

	/** Expiry date as returned by the server ('' when perpetual). */
	public static function expires_at(): string {
		$raw = (string) Settings::get( 'license_expires_at', '' );
		if ( '' === $raw ) {
			return '';
		}
		$ts = (int) strtotime( $raw );
		return $ts > 0 ? gmdate( 'Y-m-d', $ts ) : '';
	}

	/** Whole days left, or -1 when the key never expires. */
	public static function days_left(): int {
		$raw = (string) Settings::get( 'license_expires_at', '' );
		if ( '' === $raw ) {
			return -1;
		}
		$ts = (int) strtotime( $raw );
		if ( $ts <= 0 ) {
			return -1;
		}
		return max( 0, (int) ceil( ( $ts - time() ) / DAY_IN_SECONDS ) );
	}

	/** Valid licence (or still inside the offline grace period)? */
	public static function is_pro(): bool {
		// A purchase, once confirmed, is confirmed for good. The flag is only ever
		// written after the server blessed a non-trial key, and only cleared when the
		// owner detaches the licence themselves.
		if ( self::is_owned() ) {
			return true;
		}
		if ( 'valid' !== (string) Settings::get( 'license_status', '' ) ) {
			return false;
		}
		$checked_at = (string) Settings::get( 'license_checked_at', '' );
		if ( '' === $checked_at ) {
			return false;
		}
		// A trial also has to be inside its own window: the grace period below is for
		// paying customers, not for a trial stretched by cutting off the network.
		if ( self::is_trial_key() && self::days_left() < 1 ) {
			return false;
		}
		// Both sides in UTC: checked_at is written with gmdate(), so comparing
		// against time() cannot drift by the site's timezone offset.
		$delta = ( time() - (int) strtotime( $checked_at . ' UTC' ) ) / DAY_IN_SECONDS;
		return $delta <= self::GRACE_DAYS;
	}

	/** A confirmed purchase — the features stay on even after the term lapses. */
	public static function is_owned(): bool {
		return '1' === (string) Settings::get( 'license_owned', '' );
	}

	/** The stored key came from the trial endpoint rather than from a purchase. */
	public static function is_trial_key(): bool {
		return 'trial' === (string) Settings::get( 'license_kind', '' );
	}

	/**
	 * Are updates and support still covered?
	 *
	 * Separate from is_pro() on purpose: after an annual term ends the plugin keeps
	 * working, but the shop is no longer entitled to new versions.
	 */
	public static function updates_active(): bool {
		if ( self::is_trial_key() ) {
			return self::days_left() > 0;
		}
		if ( ! self::has_license() ) {
			return false;
		}
		$expires = (string) Settings::get( 'license_expires_at', '' );
		if ( '' === $expires ) {
			return 'valid' === (string) Settings::get( 'license_status', '' );
		}
		return (int) strtotime( $expires . ' UTC' ) >= time();
	}

	/** Status line for the settings screen. */
	public static function describe(): string {
		$status     = (string) Settings::get( 'license_status', '' );
		$checked_at = (string) Settings::get( 'license_checked_at', '' );

		if ( '' === $status ) {
			return __( 'not checked', 'catcode-abandoned-cart-recovery-for-woocommerce' );
		}

		if ( 'valid' === $status ) {
			if ( self::on_trial() ) {
				$left = self::days_left();
				if ( $left >= 0 ) {
					return sprintf(
						/* translators: %d: days left in the trial. */
						_n( 'trial — %d day left', 'trial — %d days left', $left, 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						$left
					);
				}
				return __( 'trial active', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			}
			if ( '' === $checked_at ) {
				return __( 'valid (not re-checked yet)', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			}
			$delta = (int) floor( ( time() - (int) strtotime( $checked_at . ' UTC' ) ) / DAY_IN_SECONDS );
			$left  = self::GRACE_DAYS - $delta;
			if ( $left > 0 ) {
				return sprintf(
					/* translators: 1: days since the last check, 2: days of grace left. */
					__( 'valid (checked %1$d days ago, %2$d days of grace left)', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					$delta,
					$left
				);
			}
			return __( 'valid (grace period over — waiting for a re-check)', 'catcode-abandoned-cart-recovery-for-woocommerce' );
		}

		if ( '' !== $checked_at ) {
			return sprintf(
				/* translators: %s: date and time of the last check. */
				__( 'invalid (last checked %s)', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				$checked_at
			);
		}

		return $status;
	}

	/** Server error code → what the shop owner should do about it. */
	public static function error_message( string $code ): string {
		$map = array(
			'invalid_key'      => __( 'Key not found. Check that you copied it in full, with no stray spaces.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'revoked'          => __( 'This key is no longer valid (refunded). Contact support if that is a mistake.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'expired'          => __( 'The licence has expired. Renew it on catcode.com.ua to keep the Pro features.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'limit_reached'    => __( 'This key is already in use on another store. Deactivate it there first.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'wrong_product'    => __( 'This key belongs to a different CatCode product, not to Abandoned Cart Recovery.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'network'          => __( 'Could not reach our licence server. We will retry automatically in a few hours.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'missing_key'      => __( 'Enter a licence key.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'no_key'           => __( 'No licence key is stored yet.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'bad_email'        => __( 'Enter a valid e-mail address — the key will be sent there.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'trial_used_email' => __( 'A trial has already been issued for this e-mail address.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'trial_used_site'  => __( 'A trial has already been issued for this site.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'rate_limited'     => __( 'Too many attempts. Try again in an hour.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'bad_response'     => __( 'The server answered with something we could not read. Try again in a few minutes.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
		);

		return isset( $map[ $code ] ) ? $map[ $code ] : __( 'This key does not fit.', 'catcode-abandoned-cart-recovery-for-woocommerce' );
	}

	/**
	 * Activate a key. verify() runs first (it does not spend an activation
	 * slot) so a typo or a key from another product cannot burn the buyer's
	 * limit; only then comes the real activate.
	 *
	 * @return array<string,mixed>
	 */
	public static function activate( string $key ): array {
		$key = trim( $key );
		if ( '' === $key ) {
			return array(
				'ok'    => false,
				'error' => 'missing_key',
			);
		}

		$pre = self::call( $key, 'verify' );
		if ( empty( $pre['ok'] ) ) {
			return $pre;
		}

		$result = self::call( $key, 'activate' );
		self::store( $key, $result );

		return $result;
	}

	/**
	 * Issues a 7-day key for this e-mail and activates it immediately — the
	 * same path a purchased key takes, only with expires_at = +7 days. Refuses
	 * when a trial already started here or a key is stored, so the button
	 * cannot quietly restart an expired trial.
	 *
	 * @return array<string,mixed>
	 */
	public static function start_trial( string $email ): array {
		$email = trim( $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return array(
				'ok'    => false,
				'error' => 'bad_email',
			);
		}
		if ( ! self::trial_available() ) {
			return array(
				'ok'    => false,
				'error' => 'trial_used_site',
			);
		}

		$response = self::post(
			self::trial_endpoint(),
			array(
				'email'       => $email,
				'module_slug' => self::EXPECTED_SLUG,
				'site_url'    => self::site_url(),
			)
		);

		if ( empty( $response['ok'] ) || empty( $response['key'] ) ) {
			if ( empty( $response['error'] ) ) {
				$response['error'] = 429 === (int) ( isset( $response['http'] ) ? $response['http'] : 0 )
					? 'rate_limited'
					: 'network';
			}
			return $response;
		}

		$key        = (string) $response['key'];
		$activation = self::call( $key, 'activate' );
		// trial_started is written in the same transaction as the key: a failure
		// between two writes would otherwise leave a key with no trial marker.
		self::store( $key, $activation, array( 'trial_started' => time() ) );

		$activation['key']        = $key;
		$activation['expires_at'] = (string) ( isset( $response['expires_at'] ) ? $response['expires_at'] : ( isset( $activation['expires_at'] ) ? $activation['expires_at'] : '' ) );

		return $activation;
	}

	/**
	 * Re-check the stored key — the daily cron and the "check now" button.
	 *
	 * @return array<string,mixed>
	 */
	public static function verify(): array {
		$key = self::key();
		if ( '' === $key ) {
			return array(
				'ok'    => false,
				'error' => 'no_key',
			);
		}
		$result = self::call( $key, 'verify' );
		self::store( $key, $result );

		return $result;
	}

	/**
	 * Frees the slot on the server AND clears the local cache.
	 *
	 * @return array<string,mixed>
	 */
	public static function deactivate(): array {
		$key    = self::key();
		$result = '' === $key
			? array(
				'ok'   => true,
				'note' => 'no_key_stored',
			)
			: self::call( $key, 'deactivate' );

		Settings::update(
			array(
				'license_key'        => '',
				'license_status'     => '',
				'license_checked_at' => '',
				'license_expires_at' => '',
				'license_data'       => '',
				// trial_started stays on purpose: one trial per install, and
				// removing a key must not hand out a second one.
			)
		);

		return $result;
	}

	/**
	 * POST to the licence endpoint. A transport failure returns ok=false with
	 * error=network and never pretends the server rejected the key — so an
	 * outage cannot turn a valid licence into an invalid one (that is what the
	 * grace period covers).
	 *
	 * @return array<string,mixed>
	 */
	private static function call( string $key, string $action ): array {
		$data = self::post(
			self::endpoint(),
			array(
				'key'      => $key,
				'site_url' => self::site_url(),
				'action'   => $action,
			)
		);

		if ( ! empty( $data['error'] ) && in_array( $data['error'], array( 'network', 'bad_response' ), true ) ) {
			return $data;
		}

		// UTC on both sides: checked_at is compared with time(), while
		// current_time() would return site time and shift the grace period.
		$data['verified_at'] = gmdate( 'Y-m-d H:i:s' );

		// Older server builds may not return product_slug — an empty value means
		// "no information"; a present but foreign one is a hard refusal.
		if ( ! empty( $data['ok'] ) && ! empty( $data['product_slug'] ) && $data['product_slug'] !== self::EXPECTED_SLUG ) {
			$data['ok']    = false;
			$data['error'] = 'wrong_product';
		}

		if ( empty( $data['ok'] ) && empty( $data['error'] ) ) {
			$http = (int) ( isset( $data['http'] ) ? $data['http'] : 0 );
			if ( ! empty( $data['expired'] ) ) {
				$data['error'] = 'expired';
			} elseif ( 429 === $http ) {
				$data['error'] = 'rate_limited';
			} elseif ( 401 === $http ) {
				$data['error'] = 'invalid_key';
			} elseif ( 403 === $http ) {
				$data['error'] = 'limit_reached';
			}
		}

		return $data;
	}

	/**
	 * @param array<string,mixed> $payload Request body.
	 * @return array<string,mixed>
	 */
	private static function post( string $url, array $payload ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => self::HTTP_TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'error'   => 'network',
				'message' => $response->get_error_message(),
				'http'    => 0,
			);
		}

		$http = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) ) {
			return array(
				'ok'    => false,
				'error' => 'bad_response',
				'http'  => $http,
				'raw'   => substr( $raw, 0, 500 ),
			);
		}

		$data['http'] = $http;

		return $data;
	}

	/**
	 * scheme://host — the server normalises the same way, so http/https and a
	 * trailing slash cannot burn extra activation slots.
	 */
	private static function site_url(): string {
		$parts = wp_parse_url( home_url() );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';

		return strtolower( $scheme ) . '://' . strtolower( $parts['host'] );
	}

	/**
	 * @param array<string,mixed> $result Server response.
	 * @param array<string,mixed> $extra  Extra fields written in the same record.
	 */
	private static function store( string $key, array $result, array $extra = array() ): void {
		$patch = array( 'license_key' => $key );

		// "We could not ask" — not "the key is bad". A throttled or unreachable
		// server must leave the cached verdict alone; 429 in particular is easy
		// to hit from a shared IP, and treating it as a refusal would switch Pro
		// off on a perfectly valid licence.
		$transport_failure = ! empty( $result['error'] )
			&& in_array( $result['error'], array( 'network', 'bad_response', 'rate_limited' ), true );

		if ( $transport_failure ) {
			// Our server was unreachable — that is our outage, not a verdict on
			// the key. Leaving status and checked_at untouched is what makes the
			// grace period real: overwriting them with "invalid" would switch Pro
			// off the moment the licence server hiccuped.
			$patch['license_data'] = (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE );
			foreach ( $extra as $field => $value ) {
				$patch[ $field ] = $value;
			}
			Settings::update( $patch );

			return;
		}

		$patch['license_status']     = ! empty( $result['ok'] ) ? 'valid' : 'invalid';
		$patch['license_checked_at'] = (string) ( isset( $result['verified_at'] ) ? $result['verified_at'] : gmdate( 'Y-m-d H:i:s' ) );
		$patch['license_data']       = (string) wp_json_encode( $result, JSON_UNESCAPED_UNICODE );

		if ( isset( $result['expires_at'] ) ) {
			$patch['license_expires_at'] = (string) $result['expires_at'];
		}

		foreach ( $extra as $field => $value ) {
			$patch[ $field ] = $value;
		}

		Settings::update( $patch );
	}
}
