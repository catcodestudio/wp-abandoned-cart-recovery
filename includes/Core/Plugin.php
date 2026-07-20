<?php
/**
 * Plugin singleton — wires every component together.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

use CatCode\AbandonedCart\Admin\CartsPage;
use CatCode\AbandonedCart\Admin\SettingsPage;
use CatCode\AbandonedCart\Pro\Export;
use CatCode\AbandonedCart\Pro\License;
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

		// Telegram owns its webhook route so the whole integration lives in one
		// file. The route stays registered regardless of the licence state — it
		// only ever writes the chat id, and answering 404 to a bot that is still
		// pointed at us would be worse than answering politely.
		Telegram::register();

		add_action( 'wp_enqueue_scripts', array( $this, 'front_assets' ) );
		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );

		if ( is_admin() ) {
			$this->carts_page = new CartsPage();
			$this->carts_page->register();

			$this->settings_page = new SettingsPage();
			$this->settings_page->register();

			( new Export() )->register();

			add_filter( 'plugin_action_links_' . CATCODE_ABANDONED_CART_BASENAME, array( $this, 'action_links' ) );
			add_action( 'admin_notices', array( $this, 'trial_notice' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
			add_action( 'wp_ajax_catcode_abandoned_cart_dismiss_trial', array( $this, 'dismiss_trial_notice' ) );
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
		Cron::schedule();
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
			)
		);
	}

	/**
	 * Script that persists the dismissal of the trial notice.
	 */
	public function admin_assets(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_enqueue_script(
			'catcode-abandoned-cart-admin-notice',
			CATCODE_ABANDONED_CART_URL . 'assets/js/admin-notice.js',
			array(),
			CATCODE_ABANDONED_CART_VERSION,
			true
		);
		wp_localize_script(
			'catcode-abandoned-cart-admin-notice',
			'catcodeAbandonedCartNotice',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'catcode_abandoned_cart_dismiss_trial' ),
			)
		);
	}

	/**
	 * Admin notice announcing the automatic 7-day Pro free trial.
	 */
	public function trial_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Only on this plugin's own screens, so the notice never stacks on
		// unrelated admin pages.
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		if ( false === strpos( $id, CartsPage::SLUG ) && false === strpos( $id, SettingsPage::SLUG ) ) {
			return;
		}

		if ( License::has_license() ) {
			return;
		}
		if ( get_user_meta( get_current_user_id(), 'catcode_abandoned_cart_trial_notice_off', true ) ) {
			return;
		}

		if ( License::trial_active() ) {
			$message = sprintf(
				/* translators: %d: number of days left in the free Pro trial. */
				__( '<strong>Abandoned Cart Recovery:</strong> all Pro features (up to three reminder e-mails, personal coupons, Telegram notifications, CSV export) are unlocked <strong>free for %d more days</strong>. When the trial ends the free tier keeps working and Pro stays available with a licence.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				License::trial_days_left()
			);
			$class = 'notice-info';
		} else {
			$message = __( '<strong>Abandoned Cart Recovery:</strong> the free Pro trial has ended — the free tier keeps working. Activate a licence to bring the Pro features back.', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			$class   = 'notice-warning';
		}

		$buy = 'https://catcode.com.ua/modules/catcode-abandoned-cart-recovery-for-woocommerce/';

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible" data-catcode-abandoned-cart-trial="1"><p>'
			. wp_kses_post( $message )
			. ' <a href="' . esc_url( $buy ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Get a Pro licence →', 'catcode-abandoned-cart-recovery-for-woocommerce' )
			. '</a></p></div>';
	}

	public function dismiss_trial_notice(): void {
		check_ajax_referer( 'catcode_abandoned_cart_dismiss_trial' );
		if ( current_user_can( 'manage_woocommerce' ) ) {
			update_user_meta( get_current_user_id(), 'catcode_abandoned_cart_trial_notice_off', 1 );
		}
		wp_die();
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

		array_unshift( $links, $settings, $carts );
		return $links;
	}
}
