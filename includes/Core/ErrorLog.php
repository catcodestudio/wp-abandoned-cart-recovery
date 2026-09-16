<?php
/**
 * Checkout error log.
 *
 * Records what stopped a shopper on the checkout, so the owner can see where
 * people drop off instead of guessing:
 *  - classic checkout: every error notice raised while the order is placed
 *    (field validation, gateway validate_fields(), payment failures, session
 *    expiry), with the field id taken from WooCommerce's own WP_Error data;
 *  - block checkout: every failed Store API /checkout response (validation,
 *    payment errors, price changes);
 *  - both: JavaScript errors on the checkout page and the block checkout's
 *    client-side field validation, which never reaches the server — both
 *    reported by assets/js/capture.js.
 *
 * Nothing that identifies the shopper is stored here: messages are stripped of
 * markup, e-mail addresses and long digit runs, page URLs lose their query
 * string. A row is tied to the session only through a random id kept in the
 * WooCommerce session, used to tell whether that shopper ordered afterwards.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

defined( 'ABSPATH' ) || exit;

class ErrorLog {

	public const SOURCE_VALIDATION = 'validation';
	public const SOURCE_PAYMENT    = 'payment';
	public const SOURCE_JS         = 'js';
	public const SOURCE_OTHER      = 'other';

	/** Random per-shopper id in the WooCommerce session (survives login at checkout). */
	public const SESSION_SID = 'catcode_abandoned_cart_err_sid';

	/** Rows written for this session so far — a flood guard. */
	public const SESSION_COUNT = 'catcode_abandoned_cart_err_count';

	public const MAX_PER_SESSION = 40;
	public const MAX_BROWSER_PER_HOUR = 300;
	public const MAX_ITEMS_PER_CALL = 5;

	/** Report period available without Pro, in days. */
	public const FREE_DAYS = 7;

	/** A session with an error counts as "no order" only after this many minutes. */
	public const GRACE_MINUTES = 30;

	/**
	 * Normalised message => [code, field] collected from the classic checkout's
	 * WP_Error before WooCommerce turns it into notices.
	 *
	 * @var array<string,array{0:string,1:string}>
	 */
	private $validation_map = array();

	/** @var array<string,bool> Messages already written during this request. */
	private $written = array();

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'catcode_abandoned_cart_errors';
	}

	/** @return string[] */
	public static function sources(): array {
		return array( self::SOURCE_VALIDATION, self::SOURCE_PAYMENT, self::SOURCE_JS, self::SOURCE_OTHER );
	}

	public static function source_label( string $source ): string {
		switch ( $source ) {
			case self::SOURCE_VALIDATION:
				return __( 'Form validation', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			case self::SOURCE_PAYMENT:
				return __( 'Payment', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			case self::SOURCE_JS:
				return __( 'JavaScript', 'catcode-abandoned-cart-recovery-for-woocommerce' );
			default:
				return __( 'Other', 'catcode-abandoned-cart-recovery-for-woocommerce' );
		}
	}

	public static function is_enabled(): bool {
		return Settings::is_on( 'error_log_enabled' );
	}

	public static function browser_enabled(): bool {
		return self::is_enabled() && Settings::is_on( 'error_log_js' );
	}

	public function register(): void {
		// The route is registered even when logging is off, so a cached checkout
		// page that still runs the reporter gets a quiet answer, not a 404.
		add_action( 'rest_api_init', array( $this, 'routes' ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		add_action( 'woocommerce_after_checkout_validation', array( $this, 'on_classic_validation' ), 9999, 2 );
		add_filter( 'woocommerce_add_error', array( $this, 'on_error_notice' ), 9999 );
		add_filter( 'rest_post_dispatch', array( $this, 'on_rest_response' ), 20, 3 );
		add_action( 'woocommerce_thankyou', array( $this, 'on_thankyou' ), 5 );
		// The block order-confirmation template does not always run woocommerce_thankyou.
		add_action( 'template_redirect', array( $this, 'on_order_received_page' ), 5 );
	}

	/* ------------------------------------------------------------------ */
	/* Classic checkout                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Remember codes and field ids of the validation errors; the notices that
	 * WooCommerce raises from them right after are what actually gets logged.
	 *
	 * @param array     $data   Posted checkout data.
	 * @param \WP_Error $errors Validation errors.
	 */
	public function on_classic_validation( $data, $errors ): void {
		if ( ! $errors instanceof \WP_Error ) {
			return;
		}
		foreach ( $errors->get_error_codes() as $code ) {
			$error_data = $errors->get_error_data( $code );
			$field      = ( is_array( $error_data ) && isset( $error_data['id'] ) ) ? (string) $error_data['id'] : '';
			foreach ( $errors->get_error_messages( $code ) as $message ) {
				$this->validation_map[ self::normalise_message( (string) $message ) ] = array( (string) $code, $field );
			}
		}
	}

	/**
	 * Every error notice added while a classic checkout is being placed.
	 *
	 * @param mixed $message Notice text.
	 * @return mixed Unchanged.
	 */
	public function on_error_notice( $message ) {
		if ( ! is_string( $message ) || '' === trim( $message ) || ! self::is_classic_checkout_request() ) {
			return $message;
		}

		$normal = self::normalise_message( $message );
		if ( '' === $normal || isset( $this->written[ $normal ] ) ) {
			return $message;
		}
		$this->written[ $normal ] = true;

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only: WooCommerce verifies the checkout nonce itself.
		$gateway = isset( $_POST['payment_method'] ) ? sanitize_key( wp_unslash( (string) $_POST['payment_method'] ) ) : '';

		if ( isset( $this->validation_map[ $normal ] ) ) {
			list( $code, $field ) = $this->validation_map[ $normal ];
			$source               = ( 'payment' === $code ) ? self::SOURCE_PAYMENT : self::SOURCE_VALIDATION;
		} elseif ( did_action( 'woocommerce_checkout_order_processed' ) ) {
			// The order exists, so the gateway is the one that said no.
			$code   = 'payment_failed';
			$field  = '';
			$source = self::SOURCE_PAYMENT;
		} elseif ( did_action( 'woocommerce_checkout_process' ) ) {
			// Raised during validation but not through the WP_Error: typically a
			// gateway's validate_fields() or another plugin's checkout check.
			$code   = 'checkout_notice';
			$field  = '';
			$source = self::SOURCE_VALIDATION;
		} else {
			// Before validation even started: expired session, stale nonce, empty cart.
			$code   = 'session';
			$field  = '';
			$source = self::SOURCE_OTHER;
		}

		self::write(
			array(
				'source'  => $source,
				'code'    => $code,
				'field'   => $field,
				'message' => $normal,
				'gateway' => $gateway,
				'page'    => self::checkout_path(),
			)
		);

		return $message;
	}

	public static function is_classic_checkout_request(): bool {
		// phpcs:disable WordPress.Security.NonceVerification -- request detection only.
		if ( ! empty( $_POST['woocommerce_checkout_update_totals'] ) ) {
			return false;
		}
		if ( wp_doing_ajax() && isset( $_GET['wc-ajax'] ) && 'checkout' === $_GET['wc-ajax'] ) {
			return true;
		}
		return isset( $_POST['woocommerce-process-checkout-nonce'] ) || isset( $_POST['woocommerce_checkout_place_order'] );
		// phpcs:enable
	}

	/* ------------------------------------------------------------------ */
	/* Block checkout (Store API)                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * @param mixed            $response Response about to be served.
	 * @param mixed            $server   REST server.
	 * @param \WP_REST_Request $request  Request.
	 * @return mixed Unchanged.
	 */
	public function on_rest_response( $response, $server, $request ) {
		if ( ! $response instanceof \WP_REST_Response || ! $request instanceof \WP_REST_Request ) {
			return $response;
		}
		if ( 'POST' !== $request->get_method() || ! preg_match( '#^/wc/store(?:/v\d+)?/checkout/?$#', (string) $request->get_route() ) ) {
			return $response;
		}
		$status = (int) $response->get_status();
		if ( $status < 400 ) {
			return $response;
		}

		$data    = $response->get_data();
		$data    = is_array( $data ) ? $data : array();
		$gateway = sanitize_key( (string) $request->get_param( 'payment_method' ) );
		$entries = array();

		if ( isset( $data['code'] ) && is_string( $data['code'] ) ) {
			$code    = sanitize_key( $data['code'] );
			$details = isset( $data['data']['details'] ) && is_array( $data['data']['details'] ) ? $data['data']['details'] : array();

			// Address errors come with one entry per field.
			foreach ( $details as $field => $detail ) {
				if ( is_array( $detail ) && isset( $detail['message'] ) ) {
					$entries[] = array(
						'code'    => isset( $detail['code'] ) ? sanitize_key( (string) $detail['code'] ) : $code,
						'field'   => sanitize_key( (string) $field ),
						'message' => (string) $detail['message'],
					);
				}
			}
			if ( ! $entries ) {
				$entries[] = array(
					'code'    => $code,
					'field'   => '',
					'message' => isset( $data['message'] ) ? (string) $data['message'] : $code,
				);
			}
			$source = self::classify_code( $code, $status );
		} else {
			// A payment result of "failure" is served as an order with HTTP 400.
			$message = '';
			if ( isset( $data['payment_result']['payment_details'] ) && is_array( $data['payment_result']['payment_details'] ) ) {
				foreach ( $data['payment_result']['payment_details'] as $detail ) {
					if ( is_array( $detail ) && isset( $detail['key'], $detail['value'] ) && 'message' === $detail['key'] ) {
						$message = (string) $detail['value'];
					}
				}
			}
			$entries[] = array(
				'code'    => 'payment_failed',
				'field'   => '',
				'message' => '' !== $message ? $message : __( 'The payment method returned a failure without a message.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			);
			$source    = self::SOURCE_PAYMENT;
		}

		foreach ( $entries as $entry ) {
			$normal = self::normalise_message( $entry['message'] );
			if ( '' === $normal || isset( $this->written[ $normal ] ) ) {
				continue;
			}
			$this->written[ $normal ] = true;
			self::write(
				array(
					'source'  => $source,
					'code'    => $entry['code'],
					'field'   => $entry['field'],
					'message' => $normal,
					'gateway' => $gateway,
					'page'    => self::checkout_path(),
				)
			);
		}

		return $response;
	}

	public static function classify_code( string $code, int $status = 400 ): string {
		if ( false !== strpos( $code, 'payment' ) ) {
			return self::SOURCE_PAYMENT;
		}
		if ( 409 === $status || false !== strpos( $code, 'nonce' ) || false !== strpos( $code, 'session' ) ) {
			return self::SOURCE_OTHER;
		}
		if ( preg_match( '/invalid|required|terms|address|email|phone|shipping|coupon|stock|quantity/', $code ) ) {
			return self::SOURCE_VALIDATION;
		}
		return self::SOURCE_OTHER;
	}

	/* ------------------------------------------------------------------ */
	/* Browser reports                                                     */
	/* ------------------------------------------------------------------ */

	public function routes(): void {
		register_rest_route(
			Rest::REST_NAMESPACE,
			'/checkout-error',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_report' ),
				// Public by design, like /capture: the reporter runs for guests.
				// The wp_rest nonce is verified in the callback, input is capped.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function rest_report( $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'catcode_abandoned_cart_bad_nonce',
				__( 'Invalid security token.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				array( 'status' => 403 )
			);
		}
		if ( ! self::browser_enabled() ) {
			return new \WP_REST_Response( array( 'logged' => 0 ), 200 );
		}

		$items = $request->get_param( 'items' );
		if ( ! is_array( $items ) ) {
			return new \WP_REST_Response( array( 'logged' => 0 ), 200 );
		}

		if ( function_exists( 'wc_load_cart' ) && function_exists( 'WC' ) && ( ! WC()->cart || ! WC()->session ) ) {
			wc_load_cart();
		}

		// Site-wide cap: a broken script on a busy store must not flood the table.
		$hour_key = 'catcode_acr_err_' . gmdate( 'YmdH' );
		$in_hour  = (int) get_transient( $hour_key );

		$page   = self::path_only( is_string( $request->get_param( 'page' ) ) ? $request->get_param( 'page' ) : '' );
		$logged = 0;

		foreach ( array_slice( $items, 0, self::MAX_ITEMS_PER_CALL ) as $item ) {
			if ( ! is_array( $item ) || $in_hour >= self::MAX_BROWSER_PER_HOUR ) {
				break;
			}
			$kind    = sanitize_key( self::scalar( $item, 'kind' ) );
			$message = self::scalar( $item, 'message' );
			if ( '' === trim( $message ) || ! in_array( $kind, array( 'js', 'validation' ), true ) ) {
				continue;
			}

			$context = array();
			if ( 'js' === $kind ) {
				$file = self::path_only( self::scalar( $item, 'file' ), true );
				if ( '' !== $file ) {
					$context['file'] = $file;
				}
				$context['line'] = absint( self::scalar( $item, 'line' ) );
				$context['col']  = absint( self::scalar( $item, 'col' ) );
			}

			$written = self::write(
				array(
					'source'  => 'js' === $kind ? self::SOURCE_JS : self::SOURCE_VALIDATION,
					'code'    => 'js' === $kind ? 'js_error' : 'client_validation',
					'field'   => sanitize_key( self::scalar( $item, 'field' ) ),
					'message' => self::normalise_message( $message ),
					'gateway' => sanitize_key( self::scalar( $item, 'gateway' ) ),
					'page'    => $page,
					'context' => $context,
				)
			);
			if ( $written ) {
				++$logged;
				++$in_hour;
			}
		}

		if ( $logged > 0 ) {
			set_transient( $hour_key, $in_hour, HOUR_IN_SECONDS + MINUTE_IN_SECONDS );
		}

		return new \WP_REST_Response( array( 'logged' => $logged ), 200 );
	}

	/**
	 * A string value from browser input; anything nested is ignored.
	 *
	 * @param array<string,mixed> $item Reported item.
	 */
	private static function scalar( array $item, string $key ): string {
		return ( isset( $item[ $key ] ) && is_scalar( $item[ $key ] ) ) ? mb_substr( (string) $item[ $key ], 0, 500 ) : '';
	}

	/* ------------------------------------------------------------------ */
	/* Conversion                                                          */
	/* ------------------------------------------------------------------ */

	public function on_order_received_page(): void {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			$this->on_thankyou( 0 );
		}
	}

	/**
	 * The shopper reached the "order received" page: their errors did not cost
	 * the order.
	 *
	 * @param mixed $order_id Order id.
	 */
	public function on_thankyou( $order_id ): void {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$sid = (string) WC()->session->get( self::SESSION_SID, '' );
		if ( '' === $sid ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET converted = 1 WHERE sid = %s AND converted = 0',
				self::table(),
				$sid
			)
		);
		// A new visit after this order starts a fresh count.
		WC()->session->set( self::SESSION_SID, '' );
		WC()->session->set( self::SESSION_COUNT, 0 );
	}

	/* ------------------------------------------------------------------ */
	/* Storage                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array{source:string,code:string,field:string,message:string,gateway:string,page:string,context?:array} $entry Entry.
	 */
	public static function write( array $entry ): bool {
		if ( ! self::is_enabled() || ! function_exists( 'WC' ) || ! WC()->session ) {
			return false;
		}
		$session = WC()->session;

		$count = (int) $session->get( self::SESSION_COUNT, 0 );
		if ( $count >= self::MAX_PER_SESSION ) {
			return false;
		}

		$sid = (string) $session->get( self::SESSION_SID, '' );
		if ( '' === $sid ) {
			$sid = wp_generate_password( 32, false, false );
			$session->set( self::SESSION_SID, $sid );
		}
		// Guests without a cookie yet would lose the id at the end of the request.
		if ( method_exists( $session, 'has_session' ) && ! $session->has_session() && method_exists( $session, 'set_customer_session_cookie' ) ) {
			$session->set_customer_session_cookie( true );
		}
		$session->set( self::SESSION_COUNT, $count + 1 );

		$source  = in_array( $entry['source'], self::sources(), true ) ? $entry['source'] : self::SOURCE_OTHER;
		$code    = substr( sanitize_key( $entry['code'] ), 0, 100 );
		$field   = substr( sanitize_key( $entry['field'] ), 0, 100 );
		$message = mb_substr( $entry['message'], 0, 255 );
		$context = isset( $entry['context'] ) && is_array( $entry['context'] ) ? $entry['context'] : array();

		$agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? self::short_agent( sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_USER_AGENT'] ) ) ) : '';
		if ( '' !== $agent ) {
			$context['browser'] = $agent;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table.
		$ok = $wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql' ),
				'sid'         => $sid,
				'session_key' => Capture::session_key(),
				'source'      => $source,
				'code'        => $code,
				'field'       => $field,
				'gateway'     => substr( sanitize_key( $entry['gateway'] ), 0, 100 ),
				'message'     => $message,
				'hash'        => md5( $source . '|' . $code . '|' . $field . '|' . $message ),
				'page'        => mb_substr( $entry['page'], 0, 190 ),
				'context'     => $context ? (string) wp_json_encode( $context ) : '',
				'converted'   => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( $ok ) {
			/**
			 * Fires after a checkout error has been logged.
			 *
			 * @param array $entry The logged entry (source, code, field, message, gateway, page).
			 */
			do_action( 'catcode_abandoned_cart_checkout_error', $entry );
		}

		return (bool) $ok;
	}

	/**
	 * Plain text, no personal data, stable enough to group identical errors.
	 */
	public static function normalise_message( string $message ): string {
		$text = html_entity_decode( wp_strip_all_tags( $message ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/[^\s@<>"\']+@[^\s@<>"\']+\.[a-z]{2,}/iu', '[email]', $text );
		// Phone numbers, card fragments, order and transaction ids.
		$text = preg_replace( '/\+?\d[\d\s\-()]{5,}\d/u', '[number]', (string) $text );
		$text = preg_replace( '/\s+/u', ' ', (string) $text );
		return trim( (string) $text );
	}

	/**
	 * Path of a URL, without host, query or fragment — query strings carry
	 * order keys and tracking ids.
	 */
	public static function path_only( string $url, bool $keep_host = false ): string {
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$path = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( $keep_host && isset( $parts['host'] ) ) {
			$home = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( $parts['host'] !== $home ) {
				$path = $parts['host'] . $path;
			}
		}
		return mb_substr( sanitize_text_field( $path ), 0, 190 );
	}

	private static function checkout_path(): string {
		$referer = wp_get_raw_referer();
		if ( $referer ) {
			return self::path_only( $referer );
		}
		return function_exists( 'wc_get_checkout_url' ) ? self::path_only( wc_get_checkout_url() ) : '';
	}

	/**
	 * Browser family, major version and platform — enough to spot "only on
	 * Safari iOS", without keeping a fingerprint-grade user-agent string.
	 */
	public static function short_agent( string $agent ): string {
		$browser = '';
		$rules   = array(
			'Edge'    => '#Edg(?:e|A|iOS)?/(\d+)#',
			'Opera'   => '#(?:OPR|Opera)/(\d+)#',
			'Samsung' => '#SamsungBrowser/(\d+)#',
			'Firefox' => '#(?:Firefox|FxiOS)/(\d+)#',
			'Chrome'  => '#(?:Chrome|CriOS)/(\d+)#',
			'Safari'  => '#Version/(\d+).*Safari#',
		);
		foreach ( $rules as $name => $pattern ) {
			if ( preg_match( $pattern, $agent, $m ) ) {
				$browser = $name . ' ' . $m[1];
				break;
			}
		}

		$platform = '';
		foreach (
			array(
				'Android' => 'Android',
				'iOS'     => 'iPhone|iPad|iPod',
				'Windows' => 'Windows',
				'macOS'   => 'Macintosh',
				'Linux'   => 'Linux',
			) as $name => $pattern
		) {
			if ( preg_match( '#' . $pattern . '#', $agent ) ) {
				$platform = $name;
				break;
			}
		}

		if ( preg_match( '#bot|crawl|spider#i', $agent ) ) {
			$browser = 'Bot';
		}

		return trim( ( '' !== $browser ? $browser : 'Other' ) . ( '' !== $platform ? ' / ' . $platform : '' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Reports                                                             */
	/* ------------------------------------------------------------------ */

	private static function since( int $days ): string {
		return gmdate( 'Y-m-d H:i:s', Repository::now() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
	}

	private static function grace_cutoff(): string {
		return gmdate( 'Y-m-d H:i:s', Repository::now() - ( self::GRACE_MINUTES * MINUTE_IN_SECONDS ) );
	}

	/**
	 * @return array{errors:int,sessions:int,lost:int}
	 */
	public static function totals( int $days, string $source = '' ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT COUNT(*) AS errors, COUNT(DISTINCT sid) AS sessions,
					COUNT(DISTINCT CASE WHEN converted = 0 AND created_at < %s THEN sid END) AS lost
				FROM %i WHERE created_at >= %s AND ( %s = \'\' OR source = %s )',
				self::grace_cutoff(),
				self::table(),
				self::since( $days ),
				$source,
				$source
			),
			ARRAY_A
		);
		return array(
			'errors'   => (int) ( $row['errors'] ?? 0 ),
			'sessions' => (int) ( $row['sessions'] ?? 0 ),
			'lost'     => (int) ( $row['lost'] ?? 0 ),
		);
	}

	/**
	 * Errors grouped by kind, most frequent first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function top( int $days, string $source = '', int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT hash, MAX(source) AS source, MAX(code) AS code, MAX(field) AS field, MAX(message) AS message,
					MAX(gateway) AS gateway, COUNT(*) AS occurrences, COUNT(DISTINCT sid) AS sessions,
					COUNT(DISTINCT CASE WHEN converted = 0 AND created_at < %s THEN sid END) AS lost,
					MAX(created_at) AS last_seen
				FROM %i WHERE created_at >= %s AND ( %s = \'\' OR source = %s )
				GROUP BY hash ORDER BY occurrences DESC, last_seen DESC LIMIT %d',
				self::grace_cutoff(),
				self::table(),
				self::since( $days ),
				$source,
				$source,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Latest occurrences of one error, with the cart it belongs to (if any).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function occurrences( string $hash, int $days, int $limit = 20 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT e.*, c.id AS cart_id, c.email AS cart_email, c.status AS cart_status, c.cart_total, c.currency
				FROM %i e LEFT JOIN %i c ON c.session_key = e.session_key AND e.session_key <> \'\'
				WHERE e.hash = %s AND e.created_at >= %s
				ORDER BY e.id DESC LIMIT %d',
				self::table(),
				Repository::table(),
				$hash,
				self::since( $days ),
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Every row of the period, for the CSV export.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_rows( int $days, string $source = '' ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE created_at >= %s AND ( %s = \'\' OR source = %s ) ORDER BY id DESC LIMIT 20000',
				self::table(),
				self::since( $days ),
				$source,
				$source
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function purge_old( int $days ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), self::since( max( 1, $days ) ) )
		);
	}

	/**
	 * Personal-data eraser: rows of the sessions whose carts belonged to this address.
	 */
	public static function delete_for_sessions( array $session_keys ): int {
		$session_keys = array_values( array_filter( array_map( 'strval', $session_keys ) ) );
		if ( ! $session_keys ) {
			return 0;
		}
		global $wpdb;
		$removed = 0;
		foreach ( $session_keys as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$removed += (int) $wpdb->delete( self::table(), array( 'session_key' => $key ), array( '%s' ) );
		}
		return $removed;
	}

	public static function create_table(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			sid VARCHAR(32) NOT NULL DEFAULT '',
			session_key VARCHAR(64) NOT NULL DEFAULT '',
			source VARCHAR(20) NOT NULL DEFAULT '',
			code VARCHAR(100) NOT NULL DEFAULT '',
			field VARCHAR(100) NOT NULL DEFAULT '',
			gateway VARCHAR(100) NOT NULL DEFAULT '',
			message VARCHAR(255) NOT NULL DEFAULT '',
			hash CHAR(32) NOT NULL DEFAULT '',
			page VARCHAR(190) NOT NULL DEFAULT '',
			context TEXT NULL,
			converted TINYINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY hash (hash),
			KEY sid (sid),
			KEY session_key (session_key)
		) {$charset};";

		dbDelta( $sql );
	}
}
