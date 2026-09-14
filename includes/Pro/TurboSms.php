<?php
/**
 * Pro: Viber / SMS through TurboSMS (turbosms.ua).
 *
 * One JSON endpoint serves every channel:
 *
 *   POST https://api.turbosms.ua/message/send.json
 *   Authorization: Bearer <token>
 *   {"recipients":["380XXXXXXXXX"], "viber":{sender,text,ttl}, "sms":{sender,text}}
 *
 * Sending both blocks is the hybrid mode: TurboSMS tries Viber first and falls
 * back to SMS when the number has no Viber or it was not delivered within ttl.
 * Only the blocks of the chosen channel are sent, so "SMS only" never pays for
 * a Viber attempt. Every recipient carries its own response_code and that one
 * is trusted: 800 ACCEPTED, 801 SENT, 802/803 partial.
 *
 * Docs: https://turbosms.ua/api.html
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Pro;

defined( 'ABSPATH' ) || exit;

class TurboSms {

	public const API = 'https://api.turbosms.ua/';

	public const CHANNEL_HYBRID = 'viber_sms';
	public const CHANNEL_VIBER  = 'viber';
	public const CHANNEL_SMS    = 'sms';

	/** Seconds Viber gets before the hybrid falls back to SMS. */
	private const VIBER_TTL = 3600;

	/** @var string */
	private $token;

	public function __construct( string $token ) {
		$this->token = trim( $token );
	}

	/** @return string[] */
	public static function channels(): array {
		return array( self::CHANNEL_HYBRID, self::CHANNEL_VIBER, self::CHANNEL_SMS );
	}

	/**
	 * 380XXXXXXXXX, or '' when the input is not a Ukrainian number.
	 *
	 * Shoppers type 0XX…, +380…, 80XX…, spaces and brackets; TurboSMS rejects
	 * anything but the bare international form with code 305.
	 */
	public static function normalise_phone( string $raw ): string {
		$digits = (string) preg_replace( '/\D+/', '', $raw );

		if ( 9 === strlen( $digits ) ) {
			$digits = '380' . $digits;
		} elseif ( 10 === strlen( $digits ) && '0' === $digits[0] ) {
			$digits = '38' . $digits;
		} elseif ( 11 === strlen( $digits ) && 0 === strpos( $digits, '80' ) ) {
			$digits = '3' . $digits;
		}

		return ( 12 === strlen( $digits ) && 0 === strpos( $digits, '380' ) ) ? $digits : '';
	}

	/**
	 * @return array{ok:bool,code:int,status:string,message_id:string}
	 */
	public function send( string $phone, string $text, string $channel, string $sms_sender, string $viber_sender ): array {
		$phone = self::normalise_phone( $phone );
		if ( '' === $phone ) {
			return array(
				'ok'         => false,
				'code'       => 305,
				'status'     => 'INVALID_PHONE',
				'message_id' => '',
			);
		}

		$payload = array( 'recipients' => array( $phone ) );

		if ( self::CHANNEL_SMS !== $channel ) {
			$payload['viber'] = array(
				'sender' => $viber_sender,
				'text'   => $text,
				'ttl'    => self::VIBER_TTL,
			);
		}
		if ( self::CHANNEL_VIBER !== $channel ) {
			$payload['sms'] = array(
				'sender' => $sms_sender,
				'text'   => $text,
			);
		}

		$result    = $this->call( 'message/send.json', $payload );
		$recipient = ( is_array( $result['result'] ) && isset( $result['result'][0] ) && is_array( $result['result'][0] ) ) ? $result['result'][0] : array();

		$code       = isset( $recipient['response_code'] ) ? (int) $recipient['response_code'] : (int) $result['code'];
		$status     = isset( $recipient['response_status'] ) ? (string) $recipient['response_status'] : (string) $result['status'];
		$message_id = isset( $recipient['message_id'] ) ? (string) $recipient['message_id'] : '';
		$accepted   = 0 === $code || ( $code >= 800 && $code <= 803 );

		return array(
			'ok'         => $accepted && '' !== $message_id,
			'code'       => $code,
			'status'     => $status,
			'message_id' => $message_id,
		);
	}

	/**
	 * Account balance — doubles as the "check connection" button.
	 *
	 * @return array{ok:bool,balance:float,status:string,code:int}
	 */
	public function balance(): array {
		$result = $this->call( 'user/balance.json', array() );

		return array(
			'ok'      => 0 === $result['code'] && isset( $result['result']['balance'] ),
			'balance' => isset( $result['result']['balance'] ) ? (float) $result['result']['balance'] : 0.0,
			'status'  => $result['status'],
			'code'    => $result['code'],
		);
	}

	/**
	 * @return array{code:int,status:string,result:mixed}
	 */
	private function call( string $method, array $payload ): array {
		if ( '' === $this->token ) {
			return array(
				'code'   => 103,
				'status' => 'REQUIRED_TOKEN',
				'result' => null,
			);
		}

		/**
		 * Base URL of the TurboSMS API — a staging site can point it at a mock
		 * instead of paying for real messages.
		 *
		 * @param string $base Default API base, with a trailing slash.
		 */
		$base = (string) apply_filters( 'catcode_abandoned_cart_turbosms_api', self::API );

		$response = wp_remote_post(
			$base . $method,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
					'Authorization' => 'Bearer ' . $this->token,
				),
				'body'    => (string) wp_json_encode( $payload ? $payload : new \stdClass() ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'code'   => -1,
				'status' => $response->get_error_message(),
				'result' => null,
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $decoded ) ) {
			return array(
				'code'   => -1,
				'status' => 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ),
				'result' => null,
			);
		}

		return array(
			'code'   => isset( $decoded['response_code'] ) ? (int) $decoded['response_code'] : -1,
			'status' => isset( $decoded['response_status'] ) ? (string) $decoded['response_status'] : '',
			'result' => $decoded['response_result'] ?? null,
		);
	}
}
