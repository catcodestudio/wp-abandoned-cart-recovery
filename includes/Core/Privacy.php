<?php
/**
 * Privacy: WordPress personal-data exporter and eraser, plus the privacy
 * policy suggestion.
 *
 * The plugin stores an e-mail address, an optional name and a snapshot of the
 * cart contents. Both tools key off the e-mail address, exactly like
 * WooCommerce's own customer data tools.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

defined( 'ABSPATH' ) || exit;

class Privacy {

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * @param array<string,array> $exporters Registered exporters.
	 * @return array<string,array>
	 */
	public function register_exporter( $exporters ) {
		if ( ! is_array( $exporters ) ) {
			$exporters = array();
		}
		$exporters['catcode-abandoned-cart'] = array(
			'exporter_friendly_name' => __( 'Abandoned carts (CatCode)', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param array<string,array> $erasers Registered erasers.
	 * @return array<string,array>
	 */
	public function register_eraser( $erasers ) {
		if ( ! is_array( $erasers ) ) {
			$erasers = array();
		}
		$erasers['catcode-abandoned-cart'] = array(
			'eraser_friendly_name' => __( 'Abandoned carts (CatCode)', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * @param string $email_address Address being exported.
	 * @param int    $page          Page number (single page — all rows at once).
	 * @return array{data:array,done:bool}
	 */
	public function export( $email_address, $page = 1 ) {
		$export = array();

		foreach ( Repository::find_by_email( sanitize_email( (string) $email_address ) ) as $row ) {
			$items = array();
			$data  = json_decode( (string) $row['cart_contents'], true );
			if ( is_array( $data ) ) {
				foreach ( $data as $item ) {
					if ( isset( $item['name'] ) ) {
						$items[] = $item['name'] . ' x' . (int) ( $item['quantity'] ?? 1 );
					}
				}
			}

			$export[] = array(
				'group_id'    => 'catcode-abandoned-cart',
				'group_label' => __( 'Abandoned carts', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				'item_id'     => 'catcode-abandoned-cart-' . (int) $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'E-mail', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						'value' => (string) $row['email'],
					),
					array(
						'name'  => __( 'Name', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						'value' => (string) $row['customer_name'],
					),
					array(
						'name'  => __( 'Cart contents', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						'value' => implode( '; ', $items ),
					),
					array(
						'name'  => __( 'Cart total', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						'value' => $row['cart_total'] . ' ' . $row['currency'],
					),
					array(
						'name'  => __( 'Status', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						'value' => (string) $row['status'],
					),
					array(
						'name'  => __( 'Reminders sent', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						'value' => (string) $row['emails_sent'],
					),
					array(
						'name'  => __( 'Captured on', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
						'value' => (string) $row['created_at'],
					),
				),
			);
		}

		return array(
			'data' => $export,
			'done' => true,
		);
	}

	/**
	 * @param string $email_address Address being erased.
	 * @param int    $page          Page number (single page — all rows at once).
	 * @return array{items_removed:bool,items_retained:bool,messages:array,done:bool}
	 */
	public function erase( $email_address, $page = 1 ) {
		$removed = Repository::delete_by_email( sanitize_email( (string) $email_address ) );

		return array(
			'items_removed'  => $removed > 0,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * Suggested wording for the site privacy policy.
	 */
	public function add_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$retention = max( 1, Settings::get_int( 'retention_days', 90 ) );

		$content = '<p>' . esc_html__( 'When you add products to your cart and enter your e-mail address at checkout, this store saves your e-mail address, your name, the contents of your cart and its total so that it can send you a reminder if you do not complete the order.', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</p>'
			. '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of days cart data is retained. */
					__( 'This data is stored in the store database and is automatically deleted %d days after the cart was last updated. It is never sent to any third party.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
					$retention
				)
			) . '</p>';

		wp_add_privacy_policy_content(
			__( 'CatCode Abandoned Cart Recovery for WooCommerce', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			wp_kses_post( $content )
		);
	}
}
