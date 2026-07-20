<?php
/**
 * Pro licence gate — CatCode standard trial model.
 *
 * Pro features are unlocked when EITHER a valid licence key is present OR the
 * install is still within its automatic free trial (7 days from activation).
 * After the trial ends only the Pro features lock — the free tier keeps working.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Pro;

use CatCode\AbandonedCart\Core\Settings;

defined( 'ABSPATH' ) || exit;

class License {

	public const TRIAL_OPTION = 'catcode_abandoned_cart_trial_started';
	public const TRIAL_DAYS   = 7;

	public static function key(): string {
		return trim( (string) Settings::get( 'license_key', '' ) );
	}

	/** A paid, activated licence key (a remote check can tighten this via the filter). */
	public static function has_license(): bool {
		$key = self::key();
		if ( '' === $key ) {
			return false;
		}
		return (bool) apply_filters( 'catcode_abandoned_cart_license_valid', true, $key );
	}

	/** Unix timestamp when the free trial began (lazily initialised on first call). */
	public static function trial_started(): int {
		$started = (int) get_option( self::TRIAL_OPTION, 0 );
		if ( ! $started ) {
			$started = time();
			update_option( self::TRIAL_OPTION, $started, false );
		}
		return $started;
	}

	public static function trial_active(): bool {
		return ( time() - self::trial_started() ) < self::TRIAL_DAYS * DAY_IN_SECONDS;
	}

	public static function trial_days_left(): int {
		$left = self::TRIAL_DAYS - (int) floor( ( time() - self::trial_started() ) / DAY_IN_SECONDS );
		return max( 0, $left );
	}

	/** Pro unlocked while under a valid licence OR within the free trial. */
	public static function is_pro(): bool {
		return self::has_license() || self::trial_active();
	}
}
