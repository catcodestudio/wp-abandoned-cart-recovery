<?php
/**
 * Pro: Telegram notification to the shop owner when a cart is abandoned,
 * plus the webhook that picks up the chat id automatically.
 *
 * The only external host the plugin ever contacts. Calls are plain JSON POSTs
 * to https://api.telegram.org/bot<token>/<method>; Telegram answers
 * {"ok":true,…} or {"ok":false,"description":…}, so both the HTTP status and
 * the payload are inspected.
 *
 * Docs: https://core.telegram.org/bots/api
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Pro;

use CatCode\AbandonedCart\Core\Logger;
use CatCode\AbandonedCart\Core\Rest;
use CatCode\AbandonedCart\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Telegram {

	/** Telegram hard-caps a message at 4096 UTF-8 characters. */
	private const MAX_LEN = 4096;

	/**
	 * Shared secret echoed back by Telegram in every webhook request.
	 * Never rendered in the UI; its presence is what "connected" means.
	 */
	public const SECRET_OPTION = 'catcode_abandoned_cart_telegram_webhook_secret';

	/** REST route (relative to the plugin namespace) that Telegram posts to. */
	public const WEBHOOK_ROUTE = '/telegram/webhook';

	/** Header Telegram sends the secret in. */
	private const SECRET_HEADER = 'X-Telegram-Bot-Api-Secret-Token';

	public static function register(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Rest::REST_NAMESPACE,
			self::WEBHOOK_ROUTE,
			array(
				'methods'  => 'POST',
				'callback' => array( __CLASS__, 'handle_webhook' ),
				// Public by design: Telegram cannot carry a WordPress nonce or a
				// cookie. Authentication is the secret token generated locally and
				// handed to Telegram through setWebhook — it is compared with
				// hash_equals() as the very first thing the callback does.
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function is_enabled(): bool {
		return License::is_pro()
			&& Settings::is_on( 'telegram_enabled' )
			&& '' !== trim( (string) Settings::get( 'telegram_bot_token', '' ) )
			&& '' !== trim( (string) Settings::get( 'telegram_chat_id', '' ) );
	}

	/* ---------------------------------------------------------------------
	 * Webhook lifecycle
	 * ------------------------------------------------------------------ */

	public static function webhook_url(): string {
		return rest_url( Rest::REST_NAMESPACE . self::WEBHOOK_ROUTE );
	}

	public static function secret(): string {
		return (string) get_option( self::SECRET_OPTION, '' );
	}

	public static function is_connected(): bool {
		return '' !== self::secret();
	}

	/**
	 * Point the bot at our REST route.
	 *
	 * A fresh secret is minted on every connect, so re-connecting invalidates
	 * whatever the previous owner of the token knew.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function connect(): array {
		$token = trim( (string) Settings::get( 'telegram_bot_token', '' ) );
		if ( '' === $token ) {
			return array(
				'ok'      => false,
				'message' => __( 'Enter the bot token first, then connect the bot.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			);
		}

		$url = self::webhook_url();
		if ( 0 !== stripos( $url, 'https://' ) ) {
			return array(
				'ok'      => false,
				'message' => __( 'Telegram only delivers webhooks to HTTPS addresses. Enable HTTPS on the store and try again.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			);
		}

		// Alphanumeric by construction, which is inside the character set
		// Telegram accepts for secret_token (A-Z, a-z, 0-9, _ and -).
		$secret = wp_generate_password( 32, false );

		$response = self::api(
			$token,
			'setWebhook',
			array(
				'url'                  => $url,
				'secret_token'         => $secret,
				'allowed_updates'      => array( 'message', 'channel_post' ),
				'drop_pending_updates' => true,
				'max_connections'      => 10,
			)
		);

		if ( ! $response['ok'] ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: error description returned by Telegram. */
					__( 'Telegram refused the connection: %s', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					$response['description']
				),
			);
		}

		update_option( self::SECRET_OPTION, $secret, false );

		return array(
			'ok'      => true,
			'message' => __( 'Bot connected. Open the chat with the bot (or add it to a group) and send /start — the chat ID will be filled in automatically.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
		);
	}

	/**
	 * Drop the webhook on Telegram's side and forget the secret.
	 *
	 * The secret is cleared even when the API call fails: a stale secret would
	 * only keep a webhook we no longer want alive.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function disconnect(): array {
		$token = trim( (string) Settings::get( 'telegram_bot_token', '' ) );

		$failure = '';
		if ( '' !== $token ) {
			$response = self::api( $token, 'deleteWebhook', array( 'drop_pending_updates' => true ) );
			if ( ! $response['ok'] ) {
				$failure = $response['description'];
			}
		}

		delete_option( self::SECRET_OPTION );

		if ( '' !== $failure ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: error description returned by Telegram. */
					__( 'The webhook was cleared locally, but Telegram reported: %s', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					$failure
				),
			);
		}

		return array(
			'ok'      => true,
			'message' => __( 'Bot disconnected. Telegram will no longer send updates to this store.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
		);
	}

	/**
	 * Incoming update from Telegram.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_webhook( $request ) {
		$expected = self::secret();
		$given    = (string) $request->get_header( self::SECRET_HEADER );

		// Constant-time compare, and never accept anything while disconnected.
		if ( '' === $expected || '' === $given || ! hash_equals( $expected, $given ) ) {
			return new \WP_Error(
				'catcode_abandoned_cart_bad_secret',
				__( 'Invalid webhook secret.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				array( 'status' => 403 )
			);
		}

		$update = $request->get_json_params();
		if ( ! is_array( $update ) ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$message = null;
		foreach ( array( 'message', 'channel_post', 'edited_message', 'edited_channel_post' ) as $key ) {
			if ( isset( $update[ $key ] ) && is_array( $update[ $key ] ) ) {
				$message = $update[ $key ];
				break;
			}
		}

		if ( null === $message || ! isset( $message['chat']['id'] ) ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		// Groups and channels have negative ids, so absint() would be wrong here.
		$chat_id = (int) $message['chat']['id'];
		if ( 0 === $chat_id ) {
			return new \WP_REST_Response( array( 'ok' => true ), 200 );
		}

		$text     = isset( $message['text'] ) ? sanitize_text_field( (string) $message['text'] ) : '';
		$is_start = ( 0 === strpos( $text, '/start' ) );

		$current = trim( (string) Settings::get( 'telegram_chat_id', '' ) );

		// /start always re-points the store at the chat it came from, so the
		// owner can move the notifications to another chat or group later.
		if ( $is_start || '' === $current ) {
			$values                     = Settings::all();
			$values['telegram_chat_id'] = (string) $chat_id;
			Settings::save( $values );

			self::send_to(
				(string) $chat_id,
				sprintf(
					/* translators: %s: Telegram chat id. */
					__( 'Your chat ID is %s. It has been saved in your store settings — abandoned cart notifications will arrive here.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					'<code>' . $chat_id . '</code>'
				)
			);
		} elseif ( '' !== $text ) {
			self::send_to(
				(string) $chat_id,
				sprintf(
					/* translators: %s: Telegram chat id. */
					__( 'Your chat ID is %s. Another chat is already saved in the store settings — send /start to move the notifications here.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					'<code>' . $chat_id . '</code>'
				)
			);
		}

		return new \WP_REST_Response( array( 'ok' => true ), 200 );
	}

	/* ---------------------------------------------------------------------
	 * Outgoing messages
	 * ------------------------------------------------------------------ */

	/**
	 * Announce one abandoned cart.
	 *
	 * @param array<string,mixed> $cart Cart row.
	 */
	public static function notify_abandoned( array $cart ): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		$items = array();
		$data  = json_decode( (string) $cart['cart_contents'], true );
		if ( is_array( $data ) ) {
			foreach ( $data as $item ) {
				if ( ! isset( $item['name'] ) ) {
					continue;
				}
				$items[] = '• ' . $item['name'] . ' × ' . (int) ( $item['quantity'] ?? 1 );
			}
		}

		$lines = array(
			'🛒 <b>' . esc_html__( 'Abandoned cart', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</b>',
		);
		if ( '' !== (string) $cart['email'] ) {
			$lines[] = esc_html__( 'E-mail:', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' ' . esc_html( (string) $cart['email'] );
		}
		if ( ! empty( $cart['phone'] ) ) {
			$lines[] = esc_html__( 'Phone:', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' +' . esc_html( (string) $cart['phone'] );
		}
		$lines[] = esc_html__( 'Total:', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' ' . $cart['cart_total'] . ' ' . $cart['currency'];
		if ( '' !== (string) $cart['customer_name'] ) {
			$lines[] = esc_html__( 'Customer:', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' ' . $cart['customer_name'];
		}
		if ( $items ) {
			$lines[] = '';
			$lines[] = implode( "\n", $items );
		}

		self::send( implode( "\n", $lines ) );
	}

	public static function send( string $text ): bool {
		$chat_id = trim( (string) Settings::get( 'telegram_chat_id', '' ) );
		if ( '' === $chat_id ) {
			return false;
		}
		return self::send_to( $chat_id, $text );
	}

	/**
	 * Send one message to an explicit chat — used both by the notifications and
	 * by the webhook, which has to answer the chat the update came from.
	 */
	public static function send_to( string $chat_id, string $text ): bool {
		$token = trim( (string) Settings::get( 'telegram_bot_token', '' ) );
		if ( '' === $token || '' === $chat_id ) {
			return false;
		}

		if ( mb_strlen( $text ) > self::MAX_LEN ) {
			$text = mb_substr( $text, 0, self::MAX_LEN - 2 ) . '…';
		}

		$response = self::api(
			$token,
			'sendMessage',
			array(
				'chat_id'              => $chat_id,
				'text'                 => $text,
				'parse_mode'           => 'HTML',
				'link_preview_options' => array( 'is_disabled' => true ),
			)
		);

		if ( ! $response['ok'] ) {
			Logger::error( 'Telegram: ' . $response['description'] );
		}

		return $response['ok'];
	}

	/* ---------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------ */

	/**
	 * One Bot API call.
	 *
	 * @param string              $token  Bot token.
	 * @param string              $method Bot API method name.
	 * @param array<string,mixed> $body   JSON payload.
	 * @return array{ok:bool,description:string,result:mixed}
	 */
	private static function api( string $token, string $method, array $body ): array {
		$response = wp_remote_post(
			'https://api.telegram.org/bot' . rawurlencode( $token ) . '/' . rawurlencode( $method ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'          => false,
				'description' => $response->get_error_message(),
				'result'      => null,
			);
		}

		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$ok      = ( $status >= 200 && $status < 300 ) && is_array( $decoded ) && ! empty( $decoded['ok'] );

		if ( $ok ) {
			return array(
				'ok'          => true,
				'description' => '',
				'result'      => $decoded['result'] ?? null,
			);
		}

		$why = is_array( $decoded ) && ! empty( $decoded['description'] )
			? (string) $decoded['description']
			: 'HTTP ' . $status;

		return array(
			'ok'          => false,
			'description' => $why,
			'result'      => null,
		);
	}
}
