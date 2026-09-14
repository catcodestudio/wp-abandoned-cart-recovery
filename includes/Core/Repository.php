<?php
/**
 * Data access layer for the abandoned cart table.
 *
 * Every statement goes through $wpdb->prepare(); the table name is injected
 * with the %i identifier placeholder rather than string concatenation.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

defined( 'ABSPATH' ) || exit;

class Repository {

	public const STATUS_ACTIVE    = 'active';
	public const STATUS_ABANDONED = 'abandoned';
	public const STATUS_RECOVERED = 'recovered';
	public const STATUS_LOST      = 'lost';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'catcode_abandoned_carts';
	}

	/** @return string[] */
	public static function statuses(): array {
		return array( self::STATUS_ACTIVE, self::STATUS_ABANDONED, self::STATUS_RECOVERED, self::STATUS_LOST );
	}

	/**
	 * Insert or refresh the row belonging to one shopper session.
	 *
	 * @param array $data Column values; session_key is required.
	 * @return int Row id, 0 on failure.
	 */
	public static function upsert( array $data ): int {
		global $wpdb;

		$session_key = isset( $data['session_key'] ) ? (string) $data['session_key'] : '';
		if ( '' === $session_key ) {
			return 0;
		}

		$now = current_time( 'mysql' );
		$row = self::find_by_session( $session_key );

		$fields = array(
			'user_id'       => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
			'email'         => isset( $data['email'] ) ? (string) $data['email'] : '',
			'customer_name' => isset( $data['customer_name'] ) ? (string) $data['customer_name'] : '',
			'phone'         => isset( $data['phone'] ) ? (string) $data['phone'] : '',
			'cart_contents' => isset( $data['cart_contents'] ) ? (string) $data['cart_contents'] : '',
			'cart_total'    => isset( $data['cart_total'] ) ? (float) $data['cart_total'] : 0,
			'currency'      => isset( $data['currency'] ) ? (string) $data['currency'] : '',
			'item_count'    => isset( $data['item_count'] ) ? (int) $data['item_count'] : 0,
			'updated_at'    => $now,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s' );

		if ( $row ) {
			// Never overwrite a known e-mail, name or phone with an empty one.
			if ( '' === $fields['email'] ) {
				$fields['email'] = (string) $row['email'];
			}
			if ( '' === $fields['customer_name'] ) {
				$fields['customer_name'] = (string) $row['customer_name'];
			}
			if ( '' === $fields['phone'] ) {
				$fields['phone'] = (string) $row['phone'];
			}
			// A shopper who is active again leaves the abandoned queue.
			if ( self::STATUS_ABANDONED === $row['status'] ) {
				$fields['status']       = self::STATUS_ACTIVE;
				$fields['abandoned_at'] = null;
				$formats[]              = '%s';
				$formats[]              = '%s';
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$wpdb->update( self::table(), $fields, array( 'id' => (int) $row['id'] ), $formats, array( '%d' ) );
			return (int) $row['id'];
		}

		$fields['session_key'] = $session_key;
		$fields['status']      = self::STATUS_ACTIVE;
		$fields['created_at']  = $now;
		$formats[]             = '%s';
		$formats[]             = '%s';
		$formats[]             = '%s';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$ok = $wpdb->insert( self::table(), $fields, $formats );
		return $ok ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find_by_session( string $session_key ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE session_key = %s LIMIT 1', self::table(), $session_key ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', self::table(), $id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Look the cart up by the hash of a recovery token — the e-mail one or the
	 * one issued for the Viber/SMS message.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find_by_token_hash( string $hash ): ?array {
		if ( '' === $hash ) {
			return null;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE token_hash = %s OR msg_token_hash = %s LIMIT 1', self::table(), $hash, $hash ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string,mixed> $fields  Column => value.
	 * @param array<int,string>   $formats Matching wpdb formats.
	 */
	public static function update( int $id, array $fields, array $formats ): void {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql' );
		$formats[]            = '%s';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->update( self::table(), $fields, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	public static function delete( int $id ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Active carts whose last update is older than $minutes — the scan promotes
	 * them to "abandoned".
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function stale_active( int $minutes, int $limit = 200 ): array {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', self::now() - ( max( 1, $minutes ) * MINUTE_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND ( email <> %s OR phone <> %s ) AND updated_at < %s ORDER BY updated_at ASC LIMIT %d',
				self::table(),
				self::STATUS_ACTIVE,
				'',
				'',
				$cutoff,
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Abandoned carts still eligible for another reminder.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending_reminders( int $max_step, int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND email <> %s AND emails_sent < %d ORDER BY abandoned_at ASC LIMIT %d',
				self::table(),
				self::STATUS_ABANDONED,
				'',
				max( 1, $max_step ),
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Abandoned carts with a phone that have not had their Viber/SMS reminder yet.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function pending_messages( int $limit = 50 ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status = %s AND phone <> %s AND msg_sent = 0 AND abandoned_at IS NOT NULL ORDER BY abandoned_at ASC LIMIT %d',
				self::table(),
				self::STATUS_ABANDONED,
				'',
				max( 1, $limit )
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/** Throttle for texts: was this number messaged within the last $days days? */
	public static function messaged_recently( string $phone, int $days, int $exclude_id = 0 ): bool {
		if ( $days < 1 || '' === $phone ) {
			return false;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', self::now() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE phone = %s AND id <> %d AND last_msg_at IS NOT NULL AND last_msg_at > %s',
				self::table(),
				$phone,
				$exclude_id,
				$cutoff
			)
		);
		return $found > 0;
	}

	/**
	 * Has this address been mailed by us within the last $days days?
	 *
	 * Guards against spamming a shopper who abandons repeatedly.
	 */
	public static function emailed_recently( string $email, int $days, int $exclude_id = 0 ): bool {
		if ( $days < 1 || '' === $email ) {
			return false;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', self::now() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE email = %s AND id <> %d AND last_email_at IS NOT NULL AND last_email_at > %s',
				self::table(),
				$email,
				$exclude_id,
				$cutoff
			)
		);
		return $found > 0;
	}

	/**
	 * Carts belonging to a shopper that are still open — used to flip them to
	 * "recovered" once an order comes in.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function open_for_customer( string $email, int $user_id, string $phone = '' ): array {
		global $wpdb;
		$statuses = array( self::STATUS_ACTIVE, self::STATUS_ABANDONED );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE status IN ( %s, %s ) AND ( ( %s <> %s AND email = %s ) OR ( %s <> %s AND phone = %s ) OR ( %d > 0 AND user_id = %d ) ) LIMIT 50',
				self::table(),
				$statuses[0],
				$statuses[1],
				$email,
				'',
				$email,
				$phone,
				'',
				$phone,
				$user_id,
				$user_id
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Paged listing for the admin table.
	 *
	 * @return array{items:array<int,array<string,mixed>>,total:int}
	 */
	public static function paged( string $status, string $search, int $per_page, int $page, string $orderby, string $order ): array {
		global $wpdb;

		$allowed_orderby = array( 'created_at', 'updated_at', 'cart_total', 'email', 'status' );
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'updated_at';
		}
		$order  = ( 'asc' === strtolower( $order ) ) ? 'ASC' : 'DESC';
		$offset = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

		$where  = 'WHERE 1=1';
		$params = array( self::table() );

		if ( in_array( $status, self::statuses(), true ) ) {
			$where   .= ' AND status = %s';
			$params[] = $status;
		}
		if ( '' !== $search ) {
			$where   .= ' AND ( email LIKE %s OR phone LIKE %s )';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// %i (table) and the filter values are bound; ORDER BY comes from the
		// whitelist above, never from raw input.
		$sql = 'SELECT * FROM %i ' . $where . ' ORDER BY ' . $orderby . ' ' . $order . ' LIMIT %d OFFSET %d';

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$items = $wpdb->get_results( $wpdb->prepare( $sql, $list_params ), ARRAY_A );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i ' . $where, $params ) );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Every row, oldest first — used by the CSV export.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all_for_export( string $status ): array {
		global $wpdb;
		if ( in_array( $status, self::statuses(), true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i WHERE status = %s ORDER BY id ASC', self::table(), $status ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i ORDER BY id ASC', self::table() ),
				ARRAY_A
			);
		}
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Counts per status plus recovered revenue.
	 *
	 * @return array{abandoned:int,recovered:int,revenue:float,rate:float}
	 */
	public static function stats(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$abandoned = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE status IN ( %s, %s, %s )',
				self::table(),
				self::STATUS_ABANDONED,
				self::STATUS_RECOVERED,
				self::STATUS_LOST
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$recovered = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE status = %s', self::table(), self::STATUS_RECOVERED )
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$revenue = (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT SUM(recovered_total) FROM %i WHERE status = %s', self::table(), self::STATUS_RECOVERED )
		);

		return array(
			'abandoned' => $abandoned,
			'recovered' => $recovered,
			'revenue'   => $revenue,
			'rate'      => $abandoned > 0 ? round( ( $recovered / $abandoned ) * 100, 1 ) : 0.0,
		);
	}

	/**
	 * Abandoned carts whose recovery window has closed become "lost".
	 */
	public static function expire_stale( int $days ): int {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', self::now() - ( max( 1, $days ) * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return (int) $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET status = %s, token_hash = %s, msg_token_hash = %s, token_expires_at = NULL WHERE status = %s AND abandoned_at IS NOT NULL AND abandoned_at < %s',
				self::table(),
				self::STATUS_LOST,
				'',
				'',
				self::STATUS_ABANDONED,
				$cutoff
			)
		);
	}

	/**
	 * Retention: physically remove rows older than $days days.
	 */
	public static function purge_old( int $days ): int {
		if ( $days < 1 ) {
			return 0;
		}
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', self::now() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return (int) $wpdb->query(
			$wpdb->prepare( 'DELETE FROM %i WHERE updated_at < %s', self::table(), $cutoff )
		);
	}

	/**
	 * Rows tied to one e-mail address — the privacy exporter and eraser.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function find_by_email( string $email ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE email = %s ORDER BY id ASC', self::table(), $email ),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	public static function delete_by_email( string $email ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table.
		return (int) $wpdb->delete( self::table(), array( 'email' => $email ), array( '%s' ) );
	}

	/**
	 * Issue a fresh one-time recovery token.
	 *
	 * Only the SHA-256 hash is persisted; the plaintext is returned to the
	 * caller once, for the outgoing e-mail, and never stored.
	 *
	 * @return string Plaintext token.
	 */
	public static function issue_token( int $id, int $lifetime_days ): string {
		$token = wp_generate_password( 32, false );
		self::update(
			$id,
			array(
				'token_hash'       => hash( 'sha256', $token ),
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', self::now() + ( max( 1, $lifetime_days ) * DAY_IN_SECONDS ) ),
			),
			array( '%s', '%s' )
		);
		return $token;
	}

	/**
	 * Token for the Viber/SMS link. Stored apart from the e-mail token so a later
	 * reminder e-mail does not kill the link already sitting in the messenger;
	 * 24 hex characters keep the SMS short.
	 *
	 * @return string Plaintext token.
	 */
	public static function issue_message_token( int $id, int $lifetime_days ): string {
		$token = bin2hex( random_bytes( 12 ) );
		self::update(
			$id,
			array(
				'msg_token_hash'   => hash( 'sha256', $token ),
				'token_expires_at' => gmdate( 'Y-m-d H:i:s', self::now() + ( max( 1, $lifetime_days ) * DAY_IN_SECONDS ) ),
			),
			array( '%s', '%s' )
		);
		return $token;
	}

	/** Site-local "now" as a Unix timestamp, matching the stored DATETIME values. */
	public static function now(): int {
		return (int) current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- rows store site-local DATETIME.
	}
}
