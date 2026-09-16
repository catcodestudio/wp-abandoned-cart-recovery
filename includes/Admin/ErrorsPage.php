<?php
/**
 * "Checkout errors" report: where shoppers get stuck and how many of them
 * left without an order.
 *
 * Free: the last 7 days, errors grouped by kind.
 * Pro: 30 / 90-day periods, the latest occurrences of each error with the
 * cart they belong to, and a CSV export.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Admin;

use CatCode\AbandonedCart\Core\ErrorLog;
use CatCode\AbandonedCart\Pro\License;

defined( 'ABSPATH' ) || exit;

class ErrorsPage {

	public const SLUG = 'catcode-abandoned-cart-errors';

	public const EXPORT_ACTION = 'catcode_abandoned_cart_errors_export';

	/** @var string */
	private $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_' . self::EXPORT_ACTION, array( $this, 'export' ) );
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Checkout Errors', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			__( 'Checkout Errors', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
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
		wp_add_inline_style( 'catcode-abandoned-cart-admin', CartsPage::css() . self::css() );
	}

	/** @return int[] */
	public static function periods(): array {
		return array( 7, 30, 90 );
	}

	/**
	 * Period and source requested in the query, clamped to what the licence allows.
	 *
	 * @param array<string,mixed> $input $_GET or $_POST.
	 * @return array{0:int,1:string}
	 */
	private static function filters( array $input ): array {
		$days = ( isset( $input['days'] ) && is_scalar( $input['days'] ) ) ? absint( $input['days'] ) : ErrorLog::FREE_DAYS;
		if ( ! in_array( $days, self::periods(), true ) ) {
			$days = ErrorLog::FREE_DAYS;
		}
		if ( ! License::is_pro() ) {
			$days = ErrorLog::FREE_DAYS;
		}
		$source = ( isset( $input['source'] ) && is_string( $input['source'] ) ) ? sanitize_key( wp_unslash( $input['source'] ) ) : '';
		if ( ! in_array( $source, ErrorLog::sources(), true ) ) {
			$source = '';
		}
		return array( $days, $source );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$is_pro = License::is_pro();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filters.
		list( $days, $source ) = self::filters( $_GET );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only drill-down.
		$hash = ( isset( $_GET['error'] ) && is_string( $_GET['error'] ) ) ? substr( (string) preg_replace( '/[^a-f0-9]/', '', strtolower( wp_unslash( $_GET['error'] ) ) ), 0, 32 ) : '';

		echo '<div class="wrap catcode-abandoned-cart-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Checkout Errors', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . CartsPage::SLUG ) ) . '" class="page-title-action">' . esc_html__( 'Cart list', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '" class="page-title-action">' . esc_html__( 'Settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
		echo '<hr class="wp-header-end">';

		echo '<p class="description">' . esc_html__( 'What stopped shoppers on the checkout: form validation messages, payment failures and JavaScript errors, with the number of shoppers who then left without placing an order.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';

		if ( ! ErrorLog::is_enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The checkout error log is switched off. Nothing new is being recorded.', 'catcode-abandoned-cart-recovery-for-woocommerce' )
				. ' <a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '">' . esc_html__( 'Turn it on in the settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a></p></div>';
		}

		$this->render_filters( $days, $source, $is_pro );

		$totals = ErrorLog::totals( $days, $source );
		$share  = $totals['sessions'] > 0 ? round( ( $totals['lost'] / $totals['sessions'] ) * 100 ) : 0;
		$tiles  = array(
			array( __( 'Errors shown to shoppers', 'catcode-abandoned-cart-recovery-for-woocommerce' ), (string) $totals['errors'] ),
			array( __( 'Shoppers who hit an error', 'catcode-abandoned-cart-recovery-for-woocommerce' ), (string) $totals['sessions'] ),
			array( __( 'Of them left without an order', 'catcode-abandoned-cart-recovery-for-woocommerce' ), $totals['lost'] . ' (' . $share . '%)' ),
		);
		echo '<div class="catcode-abandoned-cart-tiles">';
		foreach ( $tiles as $tile ) {
			echo '<div class="catcode-abandoned-cart-tile"><span class="catcode-abandoned-cart-tile__value">' . esc_html( $tile[1] ) . '</span>'
				. '<span class="catcode-abandoned-cart-tile__label">' . esc_html( $tile[0] ) . '</span></div>';
		}
		echo '</div>';

		if ( '' !== $hash ) {
			$this->render_occurrences( $hash, $days, $is_pro );
		}

		$this->render_top( $days, $source, $is_pro );

		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %d: minutes. */
				__( 'A shopper counts as "left without an order" when no order was placed in the same browser session and at least %d minutes have passed since the error.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				ErrorLog::GRACE_MINUTES
			)
		) . '</p>';

		echo '</div>';
	}

	private function render_filters( int $days, string $source, bool $is_pro ): void {
		echo '<form method="get" class="catcode-acr-errors-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"/>';

		echo '<label>' . esc_html__( 'Period', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' <select name="days">';
		foreach ( self::periods() as $period ) {
			$locked = ! $is_pro && ErrorLog::FREE_DAYS !== $period;
			$label  = sprintf(
				/* translators: %d: number of days. */
				_n( 'Last %d day', 'Last %d days', $period, 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				$period
			);
			if ( $locked ) {
				$label .= ' (PRO)';
			}
			echo '<option value="' . esc_attr( (string) $period ) . '"' . selected( $days, $period, false ) . disabled( $locked, true, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label> ';

		echo '<label>' . esc_html__( 'Type', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . ' <select name="source">';
		echo '<option value="">' . esc_html__( 'All', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</option>';
		foreach ( ErrorLog::sources() as $item ) {
			echo '<option value="' . esc_attr( $item ) . '"' . selected( $source, $item, false ) . '>' . esc_html( ErrorLog::source_label( $item ) ) . '</option>';
		}
		echo '</select></label> ';

		submit_button( __( 'Show', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';

		if ( $is_pro ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="catcode-abandoned-cart-export">';
			echo '<input type="hidden" name="action" value="' . esc_attr( self::EXPORT_ACTION ) . '"/>';
			echo '<input type="hidden" name="days" value="' . esc_attr( (string) $days ) . '"/>';
			echo '<input type="hidden" name="source" value="' . esc_attr( $source ) . '"/>';
			wp_nonce_field( self::EXPORT_ACTION );
			submit_button( __( 'Export CSV', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'secondary', 'submit', false );
			echo '</form>';
		} else {
			echo '<div class="catcode-abandoned-cart-export catcode-abandoned-cart-export--locked">';
			echo '<button type="button" class="button" disabled>' . esc_html__( 'Export CSV', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</button>';
			echo '<span class="catcode-abandoned-cart-badge">PRO</span>';
			echo '<p class="catcode-abandoned-cart-why">' . esc_html__( 'Pro: 30 and 90-day reports, every occurrence with its cart, CSV export.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
			echo '</div>';
		}
	}

	private function render_top( int $days, string $source, bool $is_pro ): void {
		$rows = ErrorLog::top( $days, $source );

		echo '<h2>' . esc_html__( 'Most frequent errors', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No checkout errors recorded in this period.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped catcode-acr-errors">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Type', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'Message', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'Field / payment method', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Times', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Shoppers', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th class="num">' . esc_html__( 'Left without an order', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'Last seen', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$where = array_filter( array( (string) $row['field'], self::gateway_title( (string) $row['gateway'] ) ) );

			echo '<tr>';
			echo '<td><span class="catcode-acr-type catcode-acr-type--' . esc_attr( (string) $row['source'] ) . '">' . esc_html( ErrorLog::source_label( (string) $row['source'] ) ) . '</span></td>';
			echo '<td>' . esc_html( (string) $row['message'] ) . '<br><code>' . esc_html( (string) $row['code'] ) . '</code></td>';
			echo '<td>' . esc_html( implode( ' · ', $where ) ) . '</td>';
			echo '<td class="num">' . esc_html( (string) $row['occurrences'] ) . '</td>';
			echo '<td class="num">' . esc_html( (string) $row['sessions'] ) . '</td>';
			echo '<td class="num"><strong>' . esc_html( (string) $row['lost'] ) . '</strong></td>';
			echo '<td>' . esc_html( self::when( (string) $row['last_seen'] ) ) . '</td>';
			echo '<td>';
			if ( $is_pro ) {
				$url = add_query_arg(
					array(
						'page'   => self::SLUG,
						'days'   => $days,
						'source' => $source,
						'error'  => (string) $row['hash'],
					),
					admin_url( 'admin.php' )
				);
				echo '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Details', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
			} else {
				echo '<span class="catcode-abandoned-cart-locked">' . esc_html__( 'Details', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</span><span class="catcode-abandoned-cart-badge">PRO</span>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function render_occurrences( string $hash, int $days, bool $is_pro ): void {
		if ( ! $is_pro ) {
			return;
		}
		$rows = ErrorLog::occurrences( $hash, $days );

		echo '<div class="catcode-abandoned-cart-card">';
		echo '<h2>' . esc_html__( 'Latest occurrences', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'No occurrences in this period.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<p><strong>' . esc_html( (string) $rows[0]['message'] ) . '</strong></p>';
		echo '<table class="widefat striped catcode-acr-errors">';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Time', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'Page', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'Browser', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'Details', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'Cart', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '<th>' . esc_html__( 'Order placed', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $rows as $row ) {
			$context = json_decode( (string) $row['context'], true );
			$context = is_array( $context ) ? $context : array();

			$details = array();
			if ( '' !== (string) $row['gateway'] ) {
				$details[] = self::gateway_title( (string) $row['gateway'] );
			}
			if ( ! empty( $context['file'] ) ) {
				$details[] = $context['file'] . ( ! empty( $context['line'] ) ? ':' . (int) $context['line'] : '' ) . ( ! empty( $context['col'] ) ? ':' . (int) $context['col'] : '' );
			}

			$cart = '—';
			if ( ! empty( $row['cart_id'] ) ) {
				$cart = trim( (string) $row['cart_email'] . ' · ' . (string) $row['cart_status'] . ' · ' . wp_strip_all_tags( wc_price( (float) $row['cart_total'], array( 'currency' => (string) $row['currency'] ) ) ), ' ·' );
			}

			echo '<tr>';
			echo '<td>' . esc_html( self::when( (string) $row['created_at'] ) ) . '</td>';
			echo '<td>' . esc_html( (string) $row['page'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $context['browser'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( implode( ' · ', $details ) ) . '</td>';
			echo '<td>' . esc_html( $cart ) . '</td>';
			echo '<td>' . ( (int) $row['converted'] ? esc_html__( 'Yes', 'catcode-abandoned-cart-recovery-for-woocommerce' ) : esc_html__( 'No', 'catcode-abandoned-cart-recovery-for-woocommerce' ) ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	public function export(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) );
		}
		check_admin_referer( self::EXPORT_ACTION );

		if ( ! License::is_pro() ) {
			wp_die( esc_html__( 'CSV export is a Pro feature.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) );
		}

		list( $days, $source ) = self::filters( $_POST );
		$rows                  = ErrorLog::all_rows( $days, $source );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=checkout-errors-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open the output stream.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) );
		}
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output stream.

		fputcsv( $out, array( 'id', 'created_at', 'type', 'code', 'field', 'payment_method', 'message', 'page', 'browser', 'file', 'order_placed' ) );
		foreach ( $rows as $row ) {
			$context = json_decode( (string) $row['context'], true );
			$context = is_array( $context ) ? $context : array();
			$file    = isset( $context['file'] ) ? $context['file'] . ( ! empty( $context['line'] ) ? ':' . (int) $context['line'] : '' ) : '';

			fputcsv(
				$out,
				array(
					$row['id'],
					$row['created_at'],
					$row['source'],
					$row['code'],
					$row['field'],
					$row['gateway'],
					self::csv_safe( (string) $row['message'] ),
					self::csv_safe( (string) $row['page'] ),
					$context['browser'] ?? '',
					self::csv_safe( $file ),
					(int) $row['converted'] ? 'yes' : 'no',
				)
			);
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream.
		exit;
	}

	/**
	 * Messages come from shoppers' browsers: keep a spreadsheet from treating
	 * one as a formula.
	 */
	private static function csv_safe( string $value ): string {
		return ( '' !== $value && false !== strpos( '=+-@', $value[0] ) ) ? "'" . $value : $value;
	}

	private static function gateway_title( string $id ): string {
		if ( '' === $id ) {
			return '';
		}
		if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
			$gateways = WC()->payment_gateways()->payment_gateways();
			if ( isset( $gateways[ $id ] ) ) {
				return wp_strip_all_tags( (string) $gateways[ $id ]->get_method_title() );
			}
		}
		return $id;
	}

	private static function when( string $mysql ): string {
		$time = strtotime( $mysql );
		if ( ! $time ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}

	public static function css(): string {
		return '
.catcode-acr-errors-filters{display:inline-flex;flex-wrap:wrap;gap:10px;align-items:center;margin:12px 0 4px}
.catcode-acr-errors .num{text-align:right;white-space:nowrap}
.catcode-acr-errors code{font-size:11px;color:#646970;background:none;padding:0}
.catcode-acr-type{display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;line-height:1.7;white-space:nowrap}
.catcode-acr-type--validation{background:#fcf0e4;color:#8a4b08}
.catcode-acr-type--payment{background:#fce8e8;color:#b32d2e}
.catcode-acr-type--js{background:#e8f0fe;color:#1e50a2}
.catcode-acr-type--other{background:#f0f0f1;color:#646970}
';
	}
}
