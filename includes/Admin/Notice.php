<?php
/**
 * The only notice this plugin is allowed to show.
 *
 * It appears after the plugin has actually done something (a cart was captured,
 * or a reminder went out) — never right after activation, when the owner is
 * still setting things up and any banner reads as noise. It offers the trial
 * while the trial is still available, and a place to leave a review otherwise.
 * Dismissed once → never shown again for that user.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Admin;

use CatCode\AbandonedCart\Pro\License;

defined( 'ABSPATH' ) || exit;

class Notice {

	/** Option: time of the first successful action, 0 = nothing has happened yet. */
	public const OPTION_SUCCESS = 'catcode_abandoned_cart_first_success';

	/** User meta: this user closed the notice. */
	public const META_DISMISSED = 'catcode_abandoned_cart_notice_dismissed';

	public static function register(): void {
		add_action( 'admin_notices', array( __CLASS__, 'render' ) );
		add_action( 'wp_ajax_catcode_abandoned_cart_dismiss_notice', array( __CLASS__, 'dismiss' ) );

		// Both mean "the plugin worked": a captured cart reached the abandoned
		// state, or a reminder went out. The first one to fire wins.
		add_action( 'catcode_abandoned_cart_abandoned', array( __CLASS__, 'mark_success' ) );
		add_action( 'catcode_abandoned_cart_reminder_sent', array( __CLASS__, 'mark_success' ) );
	}

	/** Remember that something worked. The first call wins, later ones are no-ops. */
	public static function mark_success(): void {
		if ( (int) get_option( self::OPTION_SUCCESS, 0 ) > 0 ) {
			return;
		}
		update_option( self::OPTION_SUCCESS, time(), false );
	}

	private static function should_show(): bool {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return false;
		}
		if ( (int) get_option( self::OPTION_SUCCESS, 0 ) <= 0 ) {
			return false;
		}
		return ! get_user_meta( get_current_user_id(), self::META_DISMISSED, true );
	}

	public static function render(): void {
		if ( ! self::should_show() ) {
			return;
		}

		$trial    = License::trial_available();
		$settings = admin_url( 'admin.php?page=' . SettingsPage::SLUG );

		echo '<div class="notice notice-info is-dismissible catcode-abandoned-cart-notice" data-catcode-nonce="'
			. esc_attr( wp_create_nonce( 'catcode_abandoned_cart_notice' ) ) . '"><p><strong>'
			. esc_html__( 'Abandoned Cart Recovery is working.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
			. '</strong> ';

		if ( $trial ) {
			echo esc_html__( 'Pro adds a chain of up to three reminders, a personal discount coupon per cart, a Telegram alert the moment a cart is abandoned and CSV export. You can try it for 7 days, no card required.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. ' <a class="button button-small" href="' . esc_url( $settings ) . '">'
				. esc_html__( 'Try Pro for 7 days', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a> ';
		} else {
			echo esc_html__( 'If it suits you, please leave a review — it helps us keep the module going.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' ';
		}

		echo '<a class="button button-small" href="' . esc_url( License::MODULE_URL ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Leave a review', 'catcode-abandoned-cart-recovery-for-woocommerce' )
			. '</a></p></div>';

		// The notice can appear on any admin screen, including ones where our
		// admin script is not enqueued — so "never again" travels with it.
		wp_print_inline_script_tag(
			'document.addEventListener("click",function(e){' .
			'var n=e.target.closest(".catcode-abandoned-cart-notice .notice-dismiss");if(!n)return;' .
			'var box=e.target.closest(".catcode-abandoned-cart-notice");' .
			'fetch(' . wp_json_encode( admin_url( 'admin-ajax.php' ) ) . ',{method:"POST",credentials:"same-origin",' .
			'headers:{"Content-Type":"application/x-www-form-urlencoded"},' .
			'body:"action=catcode_abandoned_cart_dismiss_notice&nonce="+encodeURIComponent(box.dataset.catcodeNonce)});' .
			'});'
		);
	}

	/** Close it for good, for the current user. */
	public static function dismiss(): void {
		check_ajax_referer( 'catcode_abandoned_cart_notice', 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}
		update_user_meta( get_current_user_id(), self::META_DISMISSED, 1 );
		wp_send_json_success();
	}
}
