<?php
/**
 * Pro: CSV export of the captured carts.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Pro;

use CatCode\AbandonedCart\Core\Repository;

defined( 'ABSPATH' ) || exit;

class Export {

	public function register(): void {
		add_action( 'admin_post_catcode_abandoned_cart_export', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) );
		}
		check_admin_referer( 'catcode_abandoned_cart_export' );

		if ( ! License::is_pro() ) {
			wp_die( esc_html__( 'CSV export is a Pro feature.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) );
		}

		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : '';
		$rows   = Repository::all_for_export( $status );

		$filename = 'abandoned-carts-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open the output stream.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) );
		}

		// BOM so Excel opens UTF-8 correctly.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- php://output stream.

		fputcsv(
			$out,
			array(
				'id',
				'email',
				'customer_name',
				'phone',
				'user_id',
				'status',
				'items',
				'item_count',
				'cart_total',
				'currency',
				'emails_sent',
				'msg_status',
				'coupon_code',
				'recovered_order_id',
				'recovered_total',
				'created_at',
				'abandoned_at',
				'recovered_at',
			)
		);

		foreach ( $rows as $row ) {
			$names = array();
			$items = json_decode( (string) $row['cart_contents'], true );
			if ( is_array( $items ) ) {
				foreach ( $items as $item ) {
					if ( isset( $item['name'] ) ) {
						$names[] = $item['name'] . ' x' . (int) ( $item['quantity'] ?? 1 );
					}
				}
			}

			fputcsv(
				$out,
				array(
					$row['id'],
					$row['email'],
					$row['customer_name'],
					$row['phone'],
					$row['user_id'],
					$row['status'],
					implode( '; ', $names ),
					$row['item_count'],
					$row['cart_total'],
					$row['currency'],
					$row['emails_sent'],
					$row['msg_status'],
					$row['coupon_code'],
					$row['recovered_order_id'],
					$row['recovered_total'],
					$row['created_at'],
					$row['abandoned_at'],
					$row['recovered_at'],
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- php://output stream.
		exit;
	}
}
