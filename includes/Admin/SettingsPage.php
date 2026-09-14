<?php
/**
 * Settings screen — native WP admin components (.form-table, submit_button).
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Admin;

use CatCode\AbandonedCart\Core\Settings;
use CatCode\AbandonedCart\Pro\License;
use CatCode\AbandonedCart\Pro\Messenger;
use CatCode\AbandonedCart\Pro\Telegram;
use CatCode\AbandonedCart\Pro\TurboSms;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	public const SLUG = 'catcode-abandoned-cart-settings';

	/** @var string */
	private $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_catcode_abandoned_cart_save', array( $this, 'handle_save' ) );
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Abandoned Cart Settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			__( 'Abandoned Cart Settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}
		wp_register_style( 'catcode-abandoned-cart-admin', false, array(), CATCODE_ABANDONED_CART_VERSION );
		wp_enqueue_style( 'catcode-abandoned-cart-admin' );
		wp_add_inline_style( 'catcode-abandoned-cart-admin', CartsPage::css() );

		wp_enqueue_script(
			'catcode-abandoned-cart-license',
			CATCODE_ABANDONED_CART_URL . 'assets/js/admin-license.js',
			array(),
			CATCODE_ABANDONED_CART_VERSION,
			true
		);
		wp_localize_script(
			'catcode-abandoned-cart-license',
			'catcodeAbandonedCartLicense',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( Ajax::NONCE ),
				'settingsUrl' => admin_url( 'admin.php?page=' . self::SLUG ),
				'i18n'        => array(
					'network'           => __( 'Could not reach the server. Try again in a moment.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					'confirmDeactivate' => __( 'Release the licence from this site? The Pro features switch off here and the key becomes free for another store.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				),
			)
		);
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$cfg    = Settings::all();
		$is_pro = License::is_pro();

		echo '<div class="wrap catcode-abandoned-cart-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Abandoned Cart Settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . CartsPage::SLUG ) ) . '" class="page-title-action">' . esc_html__( 'Cart list', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
		echo '<hr class="wp-header-end">';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only flash message.
		if ( isset( $_GET['catcode_abandoned_cart_msg'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$msg = sanitize_text_field( wp_unslash( (string) $_GET['catcode_abandoned_cart_msg'] ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$type  = isset( $_GET['catcode_abandoned_cart_status'] ) ? sanitize_key( wp_unslash( (string) $_GET['catcode_abandoned_cart_status'] ) ) : 'ok';
			$class = 'error' === $type ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="catcode_abandoned_cart_save"/>';
		wp_nonce_field( 'catcode_abandoned_cart_save' );

		// Implicit submission (Enter inside a text field) picks the first submit
		// button in the form. Without this decoy that would be "Connect bot".
		echo '<button type="submit" class="screen-reader-text" tabindex="-1" aria-hidden="true">'
			. esc_html__( 'Save settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button>';

		$this->section_timing( $cfg );
		$this->section_email( 1, $cfg, true );
		$this->section_email( 2, $cfg, $is_pro );
		$this->section_email( 3, $cfg, $is_pro );
		$this->section_coupon( $cfg, $is_pro );
		$this->section_sms( $cfg, $is_pro );
		$this->section_telegram( $cfg, $is_pro );
		$this->section_privacy( $cfg );
		$this->section_license( $cfg );

		submit_button( __( 'Save settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'primary large' );
		echo '</form>';
		echo '</div>';
	}

	/**
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function section_timing( array $cfg ): void {
		echo '<div class="catcode-abandoned-cart-card">';
		echo '<h2>' . esc_html__( 'Timing', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h2>';
		echo '<table class="form-table" role="presentation">';

		$this->number_row(
			'abandon_after',
			__( 'Mark as abandoned after', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			(int) $cfg['abandon_after'],
			__( 'Minutes of inactivity before a cart is considered abandoned. The scan runs every 15 minutes.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			__( 'minutes', 'catcode-abandoned-cart-recovery-for-woocommerce' )
		);

		$this->number_row(
			'token_lifetime',
			__( 'Recovery link lifetime', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			(int) $cfg['token_lifetime'],
			__( 'How long a recovery link stays valid. Afterwards the cart is marked as lost.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			__( 'days', 'catcode-abandoned-cart-recovery-for-woocommerce' )
		);

		$this->number_row(
			'email_cooldown',
			__( 'Do not e-mail the same address more often than once every', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			(int) $cfg['email_cooldown'],
			__( 'Protects frequent shoppers from repeated reminders. Set to 0 to disable.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			__( 'days', 'catcode-abandoned-cart-recovery-for-woocommerce' )
		);

		echo '</table></div>';
	}

	/**
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function section_email( int $n, array $cfg, bool $available ): void {
		$titles = array(
			1 => __( 'Reminder e-mail 1', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			2 => __( 'Reminder e-mail 2', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			3 => __( 'Reminder e-mail 3', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
		);

		echo '<div class="catcode-abandoned-cart-card' . ( $available ? '' : ' catcode-abandoned-cart-locked' ) . '">';
		echo '<h2>' . esc_html( $titles[ $n ] );
		if ( $n > 1 ) {
			echo '<span class="catcode-abandoned-cart-badge">PRO</span>';
		}
		echo '</h2>';

		if ( $n > 1 && ! $available ) {
			echo '<p class="catcode-abandoned-cart-why">'
				. esc_html__( 'Pro: a second and third nudge win back the shoppers who ignored the first one — the free tier sends reminder 1 only.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. '</p>';
		}

		echo '<table class="form-table" role="presentation">';

		if ( $n > 1 ) {
			$key = 'email_' . $n . '_enabled';
			echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th><td>';
			echo '<label><input type="checkbox" name="' . esc_attr( $key ) . '" value="yes"' . checked( 'yes' === $cfg[ $key ], true, false ) . disabled( $available, false, false ) . '/> ';
			echo esc_html__( 'Send this reminder', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label>';
			echo '</td></tr>';
		}

		$this->number_row(
			'email_' . $n . '_delay',
			1 === $n
				? __( 'Send after abandonment', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				: __( 'Send after the previous reminder', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			(int) $cfg[ 'email_' . $n . '_delay' ],
			'',
			__( 'minutes', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			! $available
		);

		$sub_id = 'catcode-abandoned-cart-subject-' . $n;
		echo '<tr><th scope="row"><label for="' . esc_attr( $sub_id ) . '">' . esc_html__( 'Subject', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" class="large-text" id="' . esc_attr( $sub_id ) . '" name="email_' . esc_attr( (string) $n ) . '_subject" value="' . esc_attr( (string) $cfg[ 'email_' . $n . '_subject' ] ) . '"' . disabled( $available, false, false ) . '/>';
		echo '</td></tr>';

		$body_id = 'catcode-abandoned-cart-body-' . $n;
		echo '<tr><th scope="row"><label for="' . esc_attr( $body_id ) . '">' . esc_html__( 'Message', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<textarea id="' . esc_attr( $body_id ) . '" name="email_' . esc_attr( (string) $n ) . '_body" rows="10"' . disabled( $available, false, false ) . '>' . esc_textarea( (string) $cfg[ 'email_' . $n . '_body' ] ) . '</textarea>';
		echo '<p class="description catcode-abandoned-cart-tokens">' . esc_html__( 'Placeholders:', 'catcode-abandoned-cart-recovery-for-woocommerce' )
			. ' <code>{customer_name}</code> <code>{cart_items}</code> <code>{recovery_link}</code> <code>{store_name}</code> <code>{coupon}</code> <code>{coupon_code}</code></p>';
		echo '</td></tr>';

		echo '</table></div>';
	}

	/**
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function section_coupon( array $cfg, bool $available ): void {
		echo '<div class="catcode-abandoned-cart-card' . ( $available ? '' : ' catcode-abandoned-cart-locked' ) . '">';
		echo '<h2>' . esc_html__( 'Personal discount coupon', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '<span class="catcode-abandoned-cart-badge">PRO</span></h2>';
		echo '<p class="description">' . esc_html__( 'A single-use WooCommerce coupon is generated per cart, restricted to the shopper e-mail address, and inserted through the {coupon} placeholder.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		if ( ! $available ) {
			echo '<p class="catcode-abandoned-cart-why">'
				. esc_html__( 'Pro: a discount that belongs to one shopper and expires on its own — the usual reason a hesitating cart finally converts.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. '</p>';
		}
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="coupon_enabled" value="yes"' . checked( 'yes' === $cfg['coupon_enabled'], true, false ) . disabled( $available, false, false ) . '/> ';
		echo esc_html__( 'Attach a personal coupon to the reminders', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-coupon-type">' . esc_html__( 'Discount type', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<select id="catcode-abandoned-cart-coupon-type" name="coupon_type"' . disabled( $available, false, false ) . '>';
		echo '<option value="percent"' . selected( 'percent', $cfg['coupon_type'], false ) . '>' . esc_html__( 'Percentage discount', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</option>';
		echo '<option value="fixed_cart"' . selected( 'fixed_cart', $cfg['coupon_type'], false ) . '>' . esc_html__( 'Fixed cart discount', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</option>';
		echo '</select></td></tr>';

		$this->number_row( 'coupon_amount', __( 'Discount value', 'catcode-abandoned-cart-recovery-for-woocommerce' ), (int) $cfg['coupon_amount'], '', '', ! $available );
		$this->number_row( 'coupon_from_email', __( 'Attach starting from reminder', 'catcode-abandoned-cart-recovery-for-woocommerce' ), (int) $cfg['coupon_from_email'], __( 'Reminder number the coupon first appears in (2 by default).', 'catcode-abandoned-cart-recovery-for-woocommerce' ), '', ! $available );
		$this->number_row( 'coupon_expiry_days', __( 'Coupon valid for', 'catcode-abandoned-cart-recovery-for-woocommerce' ), (int) $cfg['coupon_expiry_days'], '', __( 'days', 'catcode-abandoned-cart-recovery-for-woocommerce' ), ! $available );

		echo '</table></div>';
	}

	/**
	 * Viber / SMS reminder through TurboSMS.
	 *
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function section_sms( array $cfg, bool $available ): void {
		$off = disabled( $available, false, false );

		echo '<div class="catcode-abandoned-cart-card' . ( $available ? '' : ' catcode-abandoned-cart-locked' ) . '">';
		echo '<h2>' . esc_html__( 'Viber / SMS reminder', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '<span class="catcode-abandoned-cart-badge">PRO</span></h2>';
		echo '<p class="description">' . esc_html__( 'One short message with the recovery link through TurboSMS (turbosms.ua) — also for shoppers who typed only a phone number at checkout. One message per cart, never a chain; the e-mail reminders keep working as before.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		if ( ! $available ) {
			echo '<p class="catcode-abandoned-cart-why">'
				. esc_html__( 'Pro: many shoppers leave a phone and no e-mail — a Viber message reaches them where they actually read.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. '</p>';
		}
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="sms_enabled" value="yes"' . checked( 'yes' === $cfg['sms_enabled'], true, false ) . $off . '/> ';
		echo esc_html__( 'Send a Viber / SMS reminder', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'While this is off the plugin does not store shoppers\' phone numbers at all.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-turbosms-token">' . esc_html__( 'TurboSMS API token', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="password" class="regular-text" id="catcode-abandoned-cart-turbosms-token" name="turbosms_token" value="' . esc_attr( (string) $cfg['turbosms_token'] ) . '" autocomplete="new-password"' . $off . '/>';
		echo '<p class="description">' . esc_html__( 'TurboSMS cabinet → Settings → API (HTTP). Viber and SMS sender names are registered in the same cabinet.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		$channels = array(
			TurboSms::CHANNEL_HYBRID => __( 'Viber, SMS if Viber is not delivered', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			TurboSms::CHANNEL_VIBER  => __( 'Viber only', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			TurboSms::CHANNEL_SMS    => __( 'SMS only', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
		);
		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-sms-channel">' . esc_html__( 'Channel', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<select id="catcode-abandoned-cart-sms-channel" name="sms_channel"' . $off . '>';
		foreach ( $channels as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, (string) $cfg['sms_channel'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-viber-sender">' . esc_html__( 'Viber sender', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="catcode-abandoned-cart-viber-sender" name="viber_sender" value="' . esc_attr( (string) $cfg['viber_sender'] ) . '"' . $off . '/>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-sms-sender">' . esc_html__( 'SMS sender (alpha name)', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="catcode-abandoned-cart-sms-sender" name="sms_sender" value="' . esc_attr( (string) $cfg['sms_sender'] ) . '" maxlength="11"' . $off . '/>';
		echo '</td></tr>';

		$this->number_row( 'sms_delay', __( 'Send after abandonment', 'catcode-abandoned-cart-recovery-for-woocommerce' ), (int) $cfg['sms_delay'], '', __( 'minutes', 'catcode-abandoned-cart-recovery-for-woocommerce' ), ! $available );

		echo '<tr><th scope="row">' . esc_html__( 'Quiet hours', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th><td>';
		echo esc_html__( 'from', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' <input type="number" class="small-text" name="sms_quiet_from" value="' . esc_attr( (string) (int) $cfg['sms_quiet_from'] ) . '" min="0" max="23" step="1"' . $off . '/> ';
		echo esc_html__( 'to', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' <input type="number" class="small-text" name="sms_quiet_to" value="' . esc_attr( (string) (int) $cfg['sms_quiet_to'] ) . '" min="0" max="23" step="1"' . $off . '/>';
		echo '<p class="description">' . esc_html__( 'Hours in the site timezone. Messages that fall due at night wait for the morning. Equal values switch the window off.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-sms-text">' . esc_html__( 'Message', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<textarea id="catcode-abandoned-cart-sms-text" name="sms_text" rows="4" placeholder="' . esc_attr( Messenger::default_text() ) . '"' . $off . '>' . esc_textarea( (string) $cfg['sms_text'] ) . '</textarea>';
		echo '<p class="description catcode-abandoned-cart-tokens">' . esc_html__( 'Placeholders:', 'catcode-abandoned-cart-recovery-for-woocommerce' )
			. ' <code>{customer_name}</code> <code>{store_name}</code> <code>{cart_total}</code> <code>{item_count}</code> <code>{recovery_link}</code></p>';
		echo '<p class="description">' . esc_html__( 'Keep it short: every 70 Cyrillic characters is one more paid SMS part. Leave empty for the default text.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-sms-test-phone">' . esc_html__( 'Check', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<button type="submit" class="button button-secondary" name="catcode_abandoned_cart_sms_action" value="balance"' . disabled( ! $available, true, false ) . '>'
			. esc_html__( 'Check connection and balance', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button>';
		echo '<p><input type="tel" class="regular-text" id="catcode-abandoned-cart-sms-test-phone" name="catcode_abandoned_cart_sms_test_phone" placeholder="380XXXXXXXXX" autocomplete="off"' . $off . '/> ';
		echo '<button type="submit" class="button button-secondary" name="catcode_abandoned_cart_sms_action" value="test"' . disabled( ! $available, true, false ) . '>'
			. esc_html__( 'Send test message', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Both buttons save the settings first. The test message is a real, paid message.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</table></div>';
	}

	/**
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function section_telegram( array $cfg, bool $available ): void {
		echo '<div class="catcode-abandoned-cart-card' . ( $available ? '' : ' catcode-abandoned-cart-locked' ) . '">';
		echo '<h2>' . esc_html__( 'Telegram notification', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '<span class="catcode-abandoned-cart-badge">PRO</span></h2>';
		echo '<p class="description">' . esc_html__( 'Sends you a message the moment a cart is marked as abandoned.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		if ( ! $available ) {
			echo '<p class="catcode-abandoned-cart-why">'
				. esc_html__( 'Pro: you hear about an abandoned cart while the shopper is still around, and can call them back the same hour.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. '</p>';
		}
		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th><td>';
		echo '<label><input type="checkbox" name="telegram_enabled" value="yes"' . checked( 'yes' === $cfg['telegram_enabled'], true, false ) . disabled( $available, false, false ) . '/> ';
		echo esc_html__( 'Notify me in Telegram', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label>';
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-tg-token">' . esc_html__( 'Bot token', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="catcode-abandoned-cart-tg-token" name="telegram_bot_token" value="' . esc_attr( (string) $cfg['telegram_bot_token'] ) . '" autocomplete="off"' . disabled( $available, false, false ) . '/>';
		echo '<p class="description">' . esc_html__( 'Create a bot with @BotFather in Telegram and paste the token here.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		$this->telegram_connection_row( $cfg, $available );

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-tg-chat">' . esc_html__( 'Chat ID', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="catcode-abandoned-cart-tg-chat" name="telegram_chat_id" value="' . esc_attr( (string) $cfg['telegram_chat_id'] ) . '"' . disabled( $available, false, false ) . '/>';
		echo '<p class="description">' . esc_html__( 'Filled in automatically once you send /start to the bot — there is no need to look the number up by hand. A numeric chat id (negative for groups) or @channelname also works if you prefer to type it.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '</td></tr>';

		echo '</table></div>';
	}

	/**
	 * Connect / disconnect row — the whole webhook UI.
	 *
	 * The buttons submit the settings form itself, so the token typed above is
	 * saved before it is used, and no second nonce or inline script is needed.
	 *
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function telegram_connection_row( array $cfg, bool $available ): void {
		$connected = Telegram::is_connected();
		$has_chat  = '' !== trim( (string) $cfg['telegram_chat_id'] );

		echo '<tr><th scope="row">' . esc_html__( 'Connection', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th><td>';

		if ( ! $connected ) {
			echo '<p><span class="catcode-abandoned-cart-status catcode-abandoned-cart-status--off">'
				. esc_html__( 'Bot not connected.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. '</span></p>';
			echo '<button type="submit" class="button button-secondary" name="catcode_abandoned_cart_tg_action" value="connect"'
				. disabled( ! $available, true, false ) . '>'
				. esc_html__( 'Connect bot', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'Saves the settings and registers a Telegram webhook pointing at this store. Afterwards send /start to the bot and the chat ID appears in the field below.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		} else {
			if ( $has_chat ) {
				echo '<p><span class="catcode-abandoned-cart-hint">'
					. esc_html__( 'Bot connected — chat ID received.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
					. '</span></p>';
			} else {
				echo '<p><span class="catcode-abandoned-cart-status catcode-abandoned-cart-status--wait">'
					. esc_html__( 'Bot connected — waiting for /start.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
					. '</span></p>';
				echo '<p class="description">' . esc_html__( 'Open the chat with your bot (or add it to a group) and send /start. The bot replies with the chat ID and saves it here — reload this page to see it.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
			}
			echo '<button type="submit" class="button button-secondary" name="catcode_abandoned_cart_tg_action" value="disconnect"'
				. disabled( ! $available, true, false ) . '>'
				. esc_html__( 'Disconnect bot', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button>';
			echo '<p class="description">' . esc_html__( 'Send /start again at any time to move the notifications to a different chat or group.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		}

		echo '<p class="description">' . esc_html__( 'Telegram allows a bot either a webhook or getUpdates polling, never both — so one bot can serve only one store. Create a separate bot for every shop that uses this feature.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';

		echo '</td></tr>';
	}

	/**
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function section_privacy( array $cfg ): void {
		echo '<div class="catcode-abandoned-cart-card">';
		echo '<h2>' . esc_html__( 'Data retention', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'The plugin stores the e-mail address (and, with the Viber/SMS reminder on, the phone), the name and the cart contents of shoppers who did not complete an order. Rows are deleted automatically once they are older than the retention period.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '<table class="form-table" role="presentation">';
		$this->number_row(
			'retention_days',
			__( 'Delete cart data after', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			(int) $cfg['retention_days'],
			__( 'Set to 0 to keep the data indefinitely (not recommended).', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			__( 'days', 'catcode-abandoned-cart-recovery-for-woocommerce' )
		);
		echo '</table></div>';
	}

	/**
	 * @param array<string,mixed> $cfg Settings.
	 */
	private function section_license( array $cfg ): void {
		$is_pro    = License::is_pro();
		$on_trial  = License::on_trial();
		$can_trial = License::trial_available();
		$owned     = License::is_owned();
		$updates   = License::updates_active();
		$key       = (string) $cfg['license_key'];

		echo '<div class="catcode-abandoned-cart-card">';
		echo '<h2>' . esc_html__( 'Pro licence', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h2>';

		echo '<p class="description">';
		if ( $is_pro ) {
			if ( $on_trial ) {
				$line = __( 'Pro is on — trial.', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			} elseif ( $owned ) {
				$line = __( 'Pro is on — licence purchased, the features stay on for good.', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			} else {
				$line = __( 'Pro is on — licence active.', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			}
			echo '<span class="catcode-abandoned-cart-hint">' . esc_html( $line ) . '</span> ';
		} else {
			echo '<span class="catcode-abandoned-cart-status catcode-abandoned-cart-status--off">'
				. esc_html__( 'Free version.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. '</span> ';
			echo esc_html__( 'The first reminder, the cart list and the statistics work without a licence. Pro adds reminders 2 and 3, personal coupons, Viber/SMS reminders, Telegram alerts and CSV export.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' ';
		}
		echo esc_html(
			sprintf(
				/* translators: %s: human-readable licence status. */
				__( 'Status: %s.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				License::describe()
			)
		);
		$expires = License::expires_at();
		if ( '' !== $expires && $owned ) {
			// A purchase: the date is the end of updates and support, not of Pro.
			echo '</p><p class="description">' . esc_html(
				$updates
					? sprintf(
						/* translators: %s: date the updates and support term ends, YYYY-MM-DD. */
						__( 'Updates and support until %s.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						$expires
					)
					: sprintf(
						/* translators: %s: date the updates and support term ended, YYYY-MM-DD. */
						__( 'The updates and support term ended on %s. Renew the licence to keep receiving new versions — the Pro features keep working either way.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						$expires
					)
			);
			if ( ! $updates ) {
				echo ' <a class="button button-small" href="' . esc_url( License::MODULE_URL ) . '" target="_blank" rel="noopener">'
					. esc_html__( 'Renew the licence', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
			}
		} elseif ( '' !== $expires ) {
			echo ' ' . esc_html(
				sprintf(
					/* translators: %s: expiry date, YYYY-MM-DD. */
					__( 'Valid until %s.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					$expires
				)
			);
		}
		echo '</p>';

		echo '<table class="form-table" role="presentation">';

		echo '<tr><th scope="row"><label for="catcode-abandoned-cart-license">' . esc_html__( 'Licence key', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label></th><td>';
		echo '<input type="text" class="regular-text" id="catcode-abandoned-cart-license" value="' . esc_attr( $key ) . '" autocomplete="off" spellcheck="false"/> ';

		// AJAX buttons, never submits: the key must not ride along with a plain
		// "Save settings", otherwise a stale form could wipe an active licence.
		echo '<button type="button" class="button button-secondary" id="catcode-acr-activate">'
			. esc_html__( 'Activate key', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button> ';

		if ( '' !== $key ) {
			echo '<button type="button" class="button" id="catcode-acr-deactivate">'
				. esc_html__( 'Release licence', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button>';
		}

		echo '<p class="description">'
			. esc_html__( 'The key arrives by e-mail after purchase and is checked against catcode.com.ua. Once a purchased key is confirmed, Pro stays on for good — the licence term covers updates and support. A trial key switches Pro off when its 7 days are over.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
			. '</p>';
		echo '<p id="catcode-acr-license-msg" class="catcode-abandoned-cart-msg"></p>';
		echo '</td></tr>';

		if ( $owned ) {
			// Nothing left to try or to buy on this site.
			echo '</table>';
			echo '</div>';
			return;
		}

		echo '<tr><th scope="row">' . esc_html__( 'No licence yet?', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th><td>';
		if ( $can_trial ) {
			echo '<button type="button" class="button button-primary" id="catcode-acr-trial-open">'
				. esc_html__( 'Try Pro for 7 days', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button> ';
			echo '<a class="button" href="' . esc_url( License::MODULE_URL ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Buy a licence', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
			echo '<p class="description">'
				. esc_html__( 'The trial issues a real 7-day key to your e-mail. No card, nothing is charged, and it never starts by itself.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. '</p>';
		} else {
			echo '<a class="button" href="' . esc_url( License::MODULE_URL ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Buy a licence', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
			echo '<p class="description">'
				. esc_html__( 'The trial has already been used on this site — one per install.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. '</p>';
		}
		echo '</td></tr>';

		echo '</table>';

		if ( $can_trial ) {
			$this->trial_modal();
		}

		echo '</div>';
	}

	/**
	 * Trial dialog: one e-mail field. The key is shown right here as well as
	 * mailed, so nobody has to dig through an inbox to carry on working.
	 */
	private function trial_modal(): void {
		$user  = wp_get_current_user();
		$email = ( $user && ! empty( $user->user_email ) ) ? (string) $user->user_email : '';

		echo '<div class="catcode-acr-modal" id="catcode-acr-modal" hidden>';
		echo '<div class="catcode-acr-modal__backdrop catcode-acr-modal-close"></div>';
		echo '<div class="catcode-acr-modal__box" role="dialog" aria-modal="true" aria-labelledby="catcode-acr-modal-title">';
		echo '<h2 id="catcode-acr-modal-title">' . esc_html__( 'Try Pro for 7 days', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Reminders 2 and 3, personal coupons, Viber/SMS reminders, Telegram alerts and CSV export — free for 7 days. We e-mail you the key and switch Pro on right away.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
		echo '<p><label for="catcode-acr-trial-email">' . esc_html__( 'Your e-mail', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</label><br/>';
		echo '<input type="email" class="regular-text" id="catcode-acr-trial-email" value="' . esc_attr( $email ) . '" autocomplete="email"/></p>';
		echo '<p><button type="button" class="button button-primary" id="catcode-acr-trial-start">'
			. esc_html__( 'Get the key', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button> ';
		echo '<button type="button" class="button catcode-acr-modal-close">'
			. esc_html__( 'Cancel', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button></p>';
		echo '<p id="catcode-acr-trial-msg" class="catcode-abandoned-cart-msg"></p>';
		echo '<p id="catcode-acr-trial-key" class="catcode-acr-modal__key" hidden>'
			. esc_html__( 'Your key:', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' <code></code></p>';
		echo '</div></div>';
	}

	/**
	 * One numeric form-table row.
	 */
	private function number_row( string $name, string $label, int $value, string $description = '', string $suffix = '', bool $disabled = false ): void {
		$id = 'catcode-abandoned-cart-' . str_replace( '_', '-', $name );

		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		echo '<input type="number" class="small-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="0" step="1"' . disabled( $disabled, true, false ) . '/>';
		if ( '' !== $suffix ) {
			echo ' <span class="description">' . esc_html( $suffix ) . '</span>';
		}
		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) );
		}
		check_admin_referer( 'catcode_abandoned_cart_save' );

		$current = Settings::all();
		$is_pro  = License::is_pro();

		$values = array(
			'abandon_after'  => $this->post_int( 'abandon_after', (int) $current['abandon_after'], 1 ),
			'token_lifetime' => $this->post_int( 'token_lifetime', (int) $current['token_lifetime'], 1 ),
			'email_cooldown' => $this->post_int( 'email_cooldown', (int) $current['email_cooldown'], 0 ),
			'retention_days' => $this->post_int( 'retention_days', (int) $current['retention_days'], 0 ),
		);

		// Reminder 1 is always editable; 2 and 3 only while Pro is unlocked, so a
		// lapsed licence can never have its stored chain silently wiped.
		foreach ( array( 1, 2, 3 ) as $n ) {
			$editable = ( 1 === $n ) || $is_pro;

			if ( ! $editable ) {
				$values[ 'email_' . $n . '_enabled' ] = (string) $current[ 'email_' . $n . '_enabled' ];
				$values[ 'email_' . $n . '_delay' ]   = (int) $current[ 'email_' . $n . '_delay' ];
				$values[ 'email_' . $n . '_subject' ] = (string) $current[ 'email_' . $n . '_subject' ];
				$values[ 'email_' . $n . '_body' ]    = (string) $current[ 'email_' . $n . '_body' ];
				continue;
			}

			if ( $n > 1 ) {
				$values[ 'email_' . $n . '_enabled' ] = isset( $_POST[ 'email_' . $n . '_enabled' ] ) ? 'yes' : 'no';
			}
			$values[ 'email_' . $n . '_delay' ]   = $this->post_int( 'email_' . $n . '_delay', (int) $current[ 'email_' . $n . '_delay' ], 1 );
			$values[ 'email_' . $n . '_subject' ] = $this->post_text( 'email_' . $n . '_subject', (string) $current[ 'email_' . $n . '_subject' ] );
			$values[ 'email_' . $n . '_body' ]    = $this->post_textarea( 'email_' . $n . '_body', (string) $current[ 'email_' . $n . '_body' ] );
		}

		if ( $is_pro ) {
			$type = isset( $_POST['coupon_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['coupon_type'] ) ) : 'percent';

			$values['coupon_enabled']     = isset( $_POST['coupon_enabled'] ) ? 'yes' : 'no';
			$values['coupon_type']        = in_array( $type, array( 'percent', 'fixed_cart' ), true ) ? $type : 'percent';
			$values['coupon_amount']      = $this->post_int( 'coupon_amount', (int) $current['coupon_amount'], 0 );
			$values['coupon_from_email']  = min( 3, max( 1, $this->post_int( 'coupon_from_email', (int) $current['coupon_from_email'], 1 ) ) );
			$values['coupon_expiry_days'] = $this->post_int( 'coupon_expiry_days', (int) $current['coupon_expiry_days'], 1 );

			$values['telegram_enabled']   = isset( $_POST['telegram_enabled'] ) ? 'yes' : 'no';
			$values['telegram_bot_token'] = $this->post_text( 'telegram_bot_token', (string) $current['telegram_bot_token'] );
			// The webhook may have stored a chat id after this page was rendered,
			// so a form that still carries an empty field must never wipe it.
			$posted_chat                = $this->post_text( 'telegram_chat_id', (string) $current['telegram_chat_id'] );
			$values['telegram_chat_id'] = ( '' === trim( $posted_chat ) && '' !== trim( (string) $current['telegram_chat_id'] ) )
				? (string) $current['telegram_chat_id']
				: $posted_chat;

			$channel = isset( $_POST['sms_channel'] ) ? sanitize_key( wp_unslash( (string) $_POST['sms_channel'] ) ) : TurboSms::CHANNEL_HYBRID;

			$values['sms_enabled']    = isset( $_POST['sms_enabled'] ) ? 'yes' : 'no';
			$values['turbosms_token'] = trim( $this->post_text( 'turbosms_token', (string) $current['turbosms_token'] ) );
			$values['sms_channel']    = in_array( $channel, TurboSms::channels(), true ) ? $channel : TurboSms::CHANNEL_HYBRID;
			$values['sms_sender']     = trim( $this->post_text( 'sms_sender', (string) $current['sms_sender'] ) );
			$values['viber_sender']   = trim( $this->post_text( 'viber_sender', (string) $current['viber_sender'] ) );
			$values['sms_delay']      = $this->post_int( 'sms_delay', (int) $current['sms_delay'], 1 );
			$values['sms_quiet_from'] = min( 23, $this->post_int( 'sms_quiet_from', (int) $current['sms_quiet_from'], 0 ) );
			$values['sms_quiet_to']   = min( 23, $this->post_int( 'sms_quiet_to', (int) $current['sms_quiet_to'], 0 ) );
			$values['sms_text']       = trim( $this->post_textarea( 'sms_text', (string) $current['sms_text'] ) );
		} else {
			foreach ( array( 'coupon_enabled', 'coupon_type', 'coupon_amount', 'coupon_from_email', 'coupon_expiry_days', 'telegram_enabled', 'telegram_bot_token', 'telegram_chat_id', 'sms_enabled', 'turbosms_token', 'sms_channel', 'sms_sender', 'viber_sender', 'sms_delay', 'sms_quiet_from', 'sms_quiet_to', 'sms_text' ) as $key ) {
				$values[ $key ] = $current[ $key ];
			}
		}

		Settings::save( $values );

		$status  = 'ok';
		$message = __( 'Settings saved.', 'catcode-abandoned-cart-recovery-for-woocommerce' );

		// The connect/disconnect buttons submit this same form, so the token the
		// user just typed is already stored by the time we call Telegram.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$tg_action = isset( $_POST['catcode_abandoned_cart_tg_action'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_key( wp_unslash( (string) $_POST['catcode_abandoned_cart_tg_action'] ) )
			: '';

		if ( $is_pro && in_array( $tg_action, array( 'connect', 'disconnect' ), true ) ) {
			$result  = 'connect' === $tg_action ? Telegram::connect() : Telegram::disconnect();
			$status  = $result['ok'] ? 'ok' : 'error';
			$message = $result['message'];
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.
		$sms_action = isset( $_POST['catcode_abandoned_cart_sms_action'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_key( wp_unslash( (string) $_POST['catcode_abandoned_cart_sms_action'] ) )
			: '';

		if ( $is_pro && in_array( $sms_action, array( 'balance', 'test' ), true ) ) {
			list( $status, $message ) = 'balance' === $sms_action ? $this->sms_balance() : $this->sms_test();
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'                          => self::SLUG,
					'catcode_abandoned_cart_status' => $status,
					// Telegram error descriptions are short, but never let a
					// remote string bloat the redirect URL.
					'catcode_abandoned_cart_msg'    => rawurlencode( mb_substr( $message, 0, 240 ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** @return array{0:string,1:string} Status and message for the flash notice. */
	private function sms_balance(): array {
		$api    = new TurboSms( (string) Settings::get( 'turbosms_token', '' ) );
		$result = $api->balance();

		if ( ! $result['ok'] ) {
			return array(
				'error',
				sprintf(
					/* translators: 1: TurboSMS status code, 2: status text. */
					__( 'TurboSMS refused the connection: %1$d %2$s. Check the API token.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					$result['code'],
					$result['status']
				),
			);
		}

		return array(
			'ok',
			sprintf(
				/* translators: %s: account balance. */
				__( 'TurboSMS connected. Balance: %s UAH.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				number_format_i18n( $result['balance'], 2 )
			),
		);
	}

	/** @return array{0:string,1:string} Status and message for the flash notice. */
	private function sms_test(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in handle_save().
		$raw   = isset( $_POST['catcode_abandoned_cart_sms_test_phone'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['catcode_abandoned_cart_sms_test_phone'] ) ) : '';
		$phone = TurboSms::normalise_phone( $raw );
		if ( '' === $phone ) {
			return array( 'error', __( 'Enter a Ukrainian phone number for the test message, e.g. 380XXXXXXXXX.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) );
		}

		$template = trim( (string) Settings::get( 'sms_text', '' ) );
		$text     = strtr(
			'' !== $template ? $template : Messenger::default_text(),
			array(
				'{customer_name}' => __( 'Test', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				'{store_name}'    => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'{cart_total}'    => html_entity_decode( wp_strip_all_tags( wc_price( 1000 ) ), ENT_QUOTES, 'UTF-8' ),
				'{item_count}'    => '2',
				'{recovery_link}' => function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/' ),
				'{coupon_code}'   => '',
			)
		);

		$api    = new TurboSms( (string) Settings::get( 'turbosms_token', '' ) );
		$result = $api->send(
			$phone,
			$text,
			Messenger::channel(),
			trim( (string) Settings::get( 'sms_sender', '' ) ),
			trim( (string) Settings::get( 'viber_sender', '' ) )
		);

		if ( ! $result['ok'] ) {
			return array(
				'error',
				sprintf(
					/* translators: 1: TurboSMS status code, 2: status text. */
					__( 'The test message was not accepted: %1$d %2$s. Codes 400–401 usually mean the sender name is not approved for this account.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					$result['code'],
					$result['status']
				),
			);
		}

		return array(
			'ok',
			sprintf(
				/* translators: %s: TurboSMS message id. */
				__( 'Test message accepted by TurboSMS (id %s).', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				$result['message_id']
			),
		);
	}

	private function post_int( string $key, int $fallback, int $min ): int {
		// Nonce is verified by check_admin_referer() in handle_save().
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$value = (int) sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
		return max( $min, $value );
	}

	private function post_text( string $key, string $fallback ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) );
	}

	private function post_textarea( string $key, string $fallback ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! isset( $_POST[ $key ] ) ) {
			return $fallback;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) );
	}
}
