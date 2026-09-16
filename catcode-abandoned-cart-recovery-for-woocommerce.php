<?php
/**
 * Plugin Name: CatCode Abandoned Cart Recovery for WooCommerce
 * Plugin URI: https://catcode.com.ua/modules/catcode-abandoned-cart-recovery-for-woocommerce/
 * Update URI: https://catcode.com.ua/modules/catcode-abandoned-cart-recovery-for-woocommerce/
 * Description: Captures abandoned WooCommerce carts and wins them back with a reminder email containing a one-click recovery link. Cart list with statistics, a checkout error log, configurable timings and templates.
 * Version: 1.3.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: CatCode
 * Author URI: https://catcode.com.ua
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: catcode-abandoned-cart-recovery-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 6.0
 * WC tested up to: 10.7
 *
 * @package CatCodeAbandonedCart
 */

defined( 'ABSPATH' ) || exit;

/*
 * This build is sold on catcode.com.ua and shares its slug with the free copy on wordpress.org.
 * The Update URI header keeps wordpress.org from offering that free copy as an "update";
 * the filter below drops such an offer if it still arrives.
 */
add_filter(
	'site_transient_update_plugins',
	static function ( $value ) {
		$file = plugin_basename( __FILE__ );
		if ( is_object( $value ) && isset( $value->response[ $file ]->package ) && false !== strpos( (string) $value->response[ $file ]->package, 'downloads.wordpress.org' ) ) {
			unset( $value->response[ $file ] );
		}
		return $value;
	}
);

// Per-constant guards keep WP's activation sandbox-scrape (which includes this
// file twice) from emitting "already defined" warnings — without ever skipping
// the include/hook registration below.
defined( 'CATCODE_ABANDONED_CART_VERSION' ) || define( 'CATCODE_ABANDONED_CART_VERSION', '1.3.0' );
defined( 'CATCODE_ABANDONED_CART_FILE' ) || define( 'CATCODE_ABANDONED_CART_FILE', __FILE__ );
defined( 'CATCODE_ABANDONED_CART_DIR' ) || define( 'CATCODE_ABANDONED_CART_DIR', plugin_dir_path( __FILE__ ) );
defined( 'CATCODE_ABANDONED_CART_URL' ) || define( 'CATCODE_ABANDONED_CART_URL', plugin_dir_url( __FILE__ ) );
defined( 'CATCODE_ABANDONED_CART_BASENAME' ) || define( 'CATCODE_ABANDONED_CART_BASENAME', plugin_basename( __FILE__ ) );

// Explicit, dependency-ordered includes — deterministic across SAPIs and faster
// than an autoloader for a class set this small.
foreach (
	array(
		'includes/Core/Logger.php',
		'includes/Core/Settings.php',
		'includes/Core/Repository.php',
		'includes/Core/Installer.php',
		'includes/Pro/License.php',
		'includes/Pro/Telegram.php',
		'includes/Pro/Coupons.php',
		'includes/Pro/Export.php',
		'includes/Pro/TurboSms.php',
		'includes/Pro/Messenger.php',
		'includes/Core/Capture.php',
		'includes/Core/ErrorLog.php',
		'includes/Core/Mailer.php',
		'includes/Core/Recovery.php',
		'includes/Core/Cron.php',
		'includes/Core/Rest.php',
		'includes/Core/Privacy.php',
		'includes/Admin/Ajax.php',
		'includes/Admin/Notice.php',
		'includes/Admin/CartsListTable.php',
		'includes/Admin/CartsPage.php',
		'includes/Admin/ErrorsPage.php',
		'includes/Admin/SettingsPage.php',
		'includes/Core/Plugin.php',
	) as $catcode_abandoned_cart_inc
) {
	require_once CATCODE_ABANDONED_CART_DIR . $catcode_abandoned_cart_inc;
}
unset( $catcode_abandoned_cart_inc );

register_activation_hook(
	__FILE__,
	static function () {
		require_once __DIR__ . '/includes/Core/Installer.php';
		require_once __DIR__ . '/includes/Core/ErrorLog.php';
		\CatCode\AbandonedCart\Core\Installer::activate();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		require_once __DIR__ . '/includes/Core/Installer.php';
		\CatCode\AbandonedCart\Core\Installer::deactivate();
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'CatCode Abandoned Cart Recovery for WooCommerce requires an active WooCommerce installation.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}
		\CatCode\AbandonedCart\Core\Plugin::instance()->boot();
	}
);
