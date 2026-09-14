<?php
/**
 * WP_List_Table listing the captured carts.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Admin;

use CatCode\AbandonedCart\Core\Repository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class CartsListTable extends \WP_List_Table {

	private const PER_PAGE = 20;

	/** @var array{abandoned:int,recovered:int,revenue:float,rate:float} */
	private $stats = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'catcode_abandoned_cart',
				'plural'   => 'catcode_abandoned_carts',
				'ajax'     => false,
			)
		);
	}

	/**
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'email'      => __( 'Customer', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'items'      => __( 'Products', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'cart_total' => __( 'Total', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'status'     => __( 'Status', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'emails'     => __( 'Reminders', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'created_at' => __( 'Captured', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
		);
	}

	/**
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			'email'      => array( 'email', false ),
			'cart_total' => array( 'cart_total', false ),
			'status'     => array( 'status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	/**
	 * Status filter rendered as the standard subsubsub links.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$base    = admin_url( 'admin.php?page=' . CartsPage::SLUG );
		$current = $this->current_status();

		$labels = array(
			''                            => __( 'All', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			Repository::STATUS_ACTIVE     => __( 'Active', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			Repository::STATUS_ABANDONED  => __( 'Abandoned', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			Repository::STATUS_RECOVERED  => __( 'Recovered', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			Repository::STATUS_LOST       => __( 'Lost', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
		);

		$views = array();
		foreach ( $labels as $slug => $label ) {
			$url            = '' === $slug ? $base : add_query_arg( 'status', $slug, $base );
			$class          = $current === $slug ? ' class="current"' : '';
			$views[ $slug ] = '<a href="' . esc_url( $url ) . '"' . $class . '>' . esc_html( $label ) . '</a>';
		}

		return $views;
	}

	public function no_items() {
		esc_html_e( 'No carts captured yet.', 'catcode-abandoned-cart-recovery-for-woocommerce' );
	}

	private function current_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		return in_array( $status, Repository::statuses(), true ) ? $status : '';
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only listing controls.
		$search  = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'created_at';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( (string) $_GET['order'] ) ) : 'desc';
		$paged   = isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$result = Repository::paged( $this->current_status(), $search, self::PER_PAGE, $paged, $orderby, $order );

		$this->items = $result['items'];
		$this->stats = Repository::stats();

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => self::PER_PAGE,
				'total_pages' => (int) ceil( $result['total'] / self::PER_PAGE ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $item   Row.
	 * @param string              $column Column id.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return esc_html( (string) ( $item[ $column_name ] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	public function column_email( $item ): string {
		$name = trim( (string) $item['customer_name'] );
		$out  = '<strong>' . esc_html( '' !== (string) $item['email'] ? (string) $item['email'] : '—' ) . '</strong>';
		if ( ! empty( $item['phone'] ) ) {
			$out .= '<br>+' . esc_html( (string) $item['phone'] );
		}
		if ( '' !== $name ) {
			$out .= '<br><span class="description">' . esc_html( $name ) . '</span>';
		}
		if ( (int) $item['user_id'] > 0 ) {
			$out .= '<br><span class="description">' . esc_html__( 'Registered customer', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</span>';
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	public function column_items( $item ): string {
		$data = json_decode( (string) $item['cart_contents'], true );
		if ( ! is_array( $data ) || ! $data ) {
			return '—';
		}

		$lines = array();
		foreach ( array_slice( $data, 0, 5 ) as $line ) {
			if ( ! isset( $line['name'] ) ) {
				continue;
			}
			$lines[] = esc_html( (string) $line['name'] ) . ' <span class="description">× ' . esc_html( (string) (int) ( $line['quantity'] ?? 1 ) ) . '</span>';
		}
		if ( count( $data ) > 5 ) {
			$lines[] = '<span class="description">' . esc_html(
				sprintf(
					/* translators: %d: number of additional products not shown. */
					__( '+%d more', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					count( $data ) - 5
				)
			) . '</span>';
		}

		return implode( '<br>', $lines );
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	public function column_cart_total( $item ): string {
		$total = (float) $item['cart_total'];
		$out   = function_exists( 'wc_price' )
			? wp_kses_post( wc_price( $total, array( 'currency' => (string) $item['currency'] ) ) )
			: esc_html( (string) $total );

		if ( Repository::STATUS_RECOVERED === $item['status'] && (int) $item['recovered_order_id'] > 0 ) {
			$link = admin_url( 'admin.php?page=wc-orders&action=edit&id=' . (int) $item['recovered_order_id'] );
			$out .= '<br><a href="' . esc_url( $link ) . '">#' . esc_html( (string) (int) $item['recovered_order_id'] ) . '</a>';
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	public function column_status( $item ): string {
		$labels = array(
			Repository::STATUS_ACTIVE    => __( 'Active', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			Repository::STATUS_ABANDONED => __( 'Abandoned', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			Repository::STATUS_RECOVERED => __( 'Recovered', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			Repository::STATUS_LOST      => __( 'Lost', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
		);
		$status = (string) $item['status'];
		$label  = $labels[ $status ] ?? $status;

		return '<span class="catcode-abandoned-cart-pill catcode-abandoned-cart-pill--' . esc_attr( $status ) . '">' . esc_html( $label ) . '</span>';
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	public function column_emails( $item ): string {
		$out = esc_html( (string) (int) $item['emails_sent'] );
		if ( ! empty( $item['last_email_at'] ) ) {
			$out .= '<br><span class="description">' . esc_html( (string) $item['last_email_at'] ) . '</span>';
		}
		if ( ! empty( $item['coupon_code'] ) ) {
			$out .= '<br><code>' . esc_html( (string) $item['coupon_code'] ) . '</code>';
		}
		if ( ! empty( $item['msg_status'] ) ) {
			$out .= '<br><span class="description">Viber/SMS: ' . esc_html( (string) $item['msg_status'] ) . '</span>';
		}
		return $out;
	}

	/**
	 * @param array<string,mixed> $item Row.
	 */
	public function column_created_at( $item ): string {
		$out = esc_html( (string) $item['created_at'] );
		if ( ! empty( $item['abandoned_at'] ) ) {
			$out .= '<br><span class="description">' . esc_html(
				sprintf(
					/* translators: %s: date and time the cart was marked as abandoned. */
					__( 'abandoned: %s', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					(string) $item['abandoned_at']
				)
			) . '</span>';
		}
		return $out;
	}

	/**
	 * The four statistic tiles above the table.
	 */
	public function render_stats(): void {
		$stats = $this->stats ? $this->stats : Repository::stats();

		$revenue = function_exists( 'wc_price' )
			? wp_kses_post( wc_price( (float) $stats['revenue'] ) )
			: esc_html( (string) $stats['revenue'] );

		$tiles = array(
			array( __( 'Carts abandoned', 'catcode-abandoned-cart-recovery-for-woocommerce' ), esc_html( (string) $stats['abandoned'] ) ),
			array( __( 'Carts recovered', 'catcode-abandoned-cart-recovery-for-woocommerce' ), esc_html( (string) $stats['recovered'] ) ),
			array( __( 'Revenue recovered', 'catcode-abandoned-cart-recovery-for-woocommerce' ), $revenue ),
			array( __( 'Recovery rate', 'catcode-abandoned-cart-recovery-for-woocommerce' ), esc_html( $stats['rate'] . '%' ) ),
		);

		echo '<div class="catcode-abandoned-cart-tiles">';
		foreach ( $tiles as $tile ) {
			echo '<div class="catcode-abandoned-cart-tile">';
			echo '<span class="catcode-abandoned-cart-tile__value">' . wp_kses_post( $tile[1] ) . '</span>';
			echo '<span class="catcode-abandoned-cart-tile__label">' . esc_html( $tile[0] ) . '</span>';
			echo '</div>';
		}
		echo '</div>';
	}
}
