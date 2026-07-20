<?php
/**
 * Activation / deactivation: table, defaults, cron schedule, Pro trial.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

use CatCode\AbandonedCart\Pro\License;

defined( 'ABSPATH' ) || exit;

class Installer {

	public static function activate(): void {
		self::create_table();

		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		// Start the 7-day Pro free trial on first activation.
		if ( ! get_option( License::TRIAL_OPTION, 0 ) ) {
			update_option( License::TRIAL_OPTION, time(), false );
		}

		Cron::schedule();

		update_option( 'catcode_abandoned_cart_version', CATCODE_ABANDONED_CART_VERSION, false );
	}

	public static function deactivate(): void {
		Cron::unschedule();
	}

	public static function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = Repository::table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// session_key is the WooCommerce customer/session id — one row per shopper
		// session. token_hash holds a SHA-256 digest, never the token itself.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			session_key VARCHAR(64) NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(190) NOT NULL DEFAULT '',
			customer_name VARCHAR(190) NOT NULL DEFAULT '',
			cart_contents LONGTEXT NULL,
			cart_total DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT '',
			item_count INT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			token_hash VARCHAR(64) NOT NULL DEFAULT '',
			token_expires_at DATETIME NULL DEFAULT NULL,
			emails_sent TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_email_at DATETIME NULL DEFAULT NULL,
			coupon_code VARCHAR(64) NOT NULL DEFAULT '',
			recovered_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			recovered_total DECIMAL(18,4) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			abandoned_at DATETIME NULL DEFAULT NULL,
			recovered_at DATETIME NULL DEFAULT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY session_key (session_key),
			KEY email (email),
			KEY status (status),
			KEY token_hash (token_hash),
			KEY updated_at (updated_at)
		) {$charset};";

		dbDelta( $sql );
	}
}
