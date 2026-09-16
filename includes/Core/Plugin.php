<?php
/**
 * Plugin singleton — wires every component together.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

use CatCode\AbandonedCart\Admin\Ajax;
use CatCode\AbandonedCart\Admin\CartsPage;
use CatCode\AbandonedCart\Admin\ErrorsPage;
use CatCode\AbandonedCart\Admin\Notice;
use CatCode\AbandonedCart\Admin\SettingsPage;
use CatCode\AbandonedCart\Pro\Export;
use CatCode\AbandonedCart\Pro\Messenger;
use CatCode\AbandonedCart\Pro\Telegram;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	/** @var CartsPage|null */
	private $carts_page = null;

	/** @var SettingsPage|null */
	private $settings_page = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		( new Capture() )->register();
		( new Recovery() )->register();
		( new Cron() )->register();
		( new Rest() )->register();
		( new Privacy() )->register();
		( new ErrorLog() )->register();

		// Telegram owns its webhook route so the whole integration lives in one
		// file. The route stays registered regardless of the licence state — it
		// only ever writes the chat id, and answering 404 to a bot that is still
		// pointed at us would be worse than answering politely.
		Telegram::register();

		add_action( 'wp_enqueue_scripts', array( $this, 'front_assets' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );

		// The notice listens for the plugin's first successful action, which can
		// happen during a cron run — so it is registered outside is_admin().
		Notice::register();

		if ( is_admin() ) {
			$this->carts_page = new CartsPage();
			$this->carts_page->register();

			$this->settings_page = new SettingsPage();
			$this->settings_page->register();

			( new ErrorsPage() )->register();

			( new Export() )->register();
			Ajax::register();

			add_filter( 'plugin_action_links_' . CATCODE_ABANDONED_CART_BASENAME, array( $this, 'action_links' ) );
		}
	}

	/**
	 * Keep the schema and the cron events in step after an update.
	 */
	public function maybe_upgrade(): void {
		$installed = get_option( 'catcode_abandoned_cart_version' );
		if ( CATCODE_ABANDONED_CART_VERSION === $installed ) {
			return;
		}
		Installer::create_table();
		ErrorLog::create_table();
		Cron::schedule();
		// 1.2.0 and older stored a key without its kind, so a purchase could not be
		// told from a trial. Ask the server once, shortly, instead of waiting for
		// the daily check — until then such a key is treated with caution.
		if ( \CatCode\AbandonedCart\Pro\License::has_license() && '' === (string) Settings::get( 'license_kind', '' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, Cron::LICENSE_HOOK );
		}
		update_option( 'catcode_abandoned_cart_version', CATCODE_ABANDONED_CART_VERSION, false );
	}

	/**
	 * Front-end capture script — checkout pages only, both classic and block.
	 */
	public function front_assets(): void {
		if ( is_admin() || ! function_exists( 'is_checkout' ) ) {
			return;
		}
		if ( ! is_checkout() || is_order_received_page() ) {
			return;
		}

		wp_enqueue_script(
			'catcode-abandoned-cart-capture',
			CATCODE_ABANDONED_CART_URL . 'assets/js/capture.js',
			array(),
			CATCODE_ABANDONED_CART_VERSION,
			true
		);
		wp_localize_script(
			'catcode-abandoned-cart-capture',
			'catcodeAbandonedCart',
			array(
				'endpoint' => esc_url_raw( rest_url( Rest::REST_NAMESPACE . '/capture' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				// Phones are watched only while the Pro Viber/SMS reminder is on.
				'phone'    => Messenger::is_enabled() ? '1' : '',
				// Checkout error log: JavaScript errors and block-checkout field errors.
				'errors'   => ErrorLog::browser_enabled() ? esc_url_raw( rest_url( Rest::REST_NAMESPACE . '/checkout-error' ) ) : '',
			)
		);
	}

	/**
	 * @param array<int,string> $links Plugin row links.
	 * @return array<int,string>
	 */
	public function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '">'
			. esc_html__( 'Settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
		$carts    = '<a href="' . esc_url( admin_url( 'admin.php?page=' . CartsPage::SLUG ) ) . '">'
			. esc_html__( 'Carts', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';

		$errors   = '<a href="' . esc_url( admin_url( 'admin.php?page=' . ErrorsPage::SLUG ) ) . '">'
			. esc_html__( 'Checkout errors', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';

		array_unshift( $links, $settings, $carts, $errors );
		return $links;
	}
}
