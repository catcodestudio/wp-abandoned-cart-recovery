<?php
/**
 * "Abandoned Carts" screen: statistic tiles plus the cart list table.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Admin;

use CatCode\AbandonedCart\Pro\License;

defined( 'ABSPATH' ) || exit;

class CartsPage {

	public const SLUG = 'catcode-abandoned-carts';

	/** @var string */
	private $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Abandoned Carts', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			__( 'Abandoned Carts', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function hook_suffix(): string {
		return $this->hook_suffix;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$table = new CartsListTable();
		$table->prepare_items();

		echo '<div class="wrap catcode-abandoned-cart-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Abandoned Carts', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</h1>';
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '" class="page-title-action">' . esc_html__( 'Settings', 'catcode-abandoned-cart-recovery-for-woocommerce' ) . '</a>';
		echo '<hr class="wp-header-end">';

		$table->render_stats();

		if ( License::is_pro() ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="catcode-abandoned-cart-export">';
			echo '<input type="hidden" name="action" value="catcode_abandoned_cart_export"/>';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing filter carried into the export.
			$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '"/>';
			wp_nonce_field( 'catcode_abandoned_cart_export' );
			submit_button( __( 'Export CSV', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		$table->views();

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"/>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only listing filter.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		if ( '' !== $status ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $status ) . '"/>';
		}
		$table->search_box( __( 'Search by e-mail', 'catcode-abandoned-cart-recovery-for-woocommerce' ), 'catcode-abandoned-cart-search' );
		$table->display();
		echo '</form>';

		echo '</div>';
	}

	/**
	 * Admin CSS, scoped to this screen only.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ): void {
		if ( '' === $this->hook_suffix || $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		wp_register_style( 'catcode-abandoned-cart-admin', false, array(), CATCODE_ABANDONED_CART_VERSION );
		wp_enqueue_style( 'catcode-abandoned-cart-admin' );
		wp_add_inline_style( 'catcode-abandoned-cart-admin', self::css() );
	}

	public static function css(): string {
		return '
.catcode-abandoned-cart-tiles{display:flex;flex-wrap:wrap;gap:14px;margin:16px 0 18px}
.catcode-abandoned-cart-tile{flex:1 1 180px;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.catcode-abandoned-cart-tile__value{display:block;font-size:26px;font-weight:600;line-height:1.2;color:#1d2327}
.catcode-abandoned-cart-tile__label{display:block;margin-top:4px;font-size:13px;color:#50575e}
.catcode-abandoned-cart-export{float:right;margin:0 0 8px}
.catcode-abandoned-cart-pill{display:inline-block;padding:2px 10px;border-radius:10px;font-size:12px;font-weight:600;line-height:1.7}
.catcode-abandoned-cart-pill--active{background:#e8f0fe;color:#1e50a2}
.catcode-abandoned-cart-pill--abandoned{background:#fcf0e4;color:#8a4b08}
.catcode-abandoned-cart-pill--recovered{background:#e6f4ea;color:#1a7f37}
.catcode-abandoned-cart-pill--lost{background:#f0f0f1;color:#646970}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:8px 22px 18px;margin:16px 0 18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-card>h2{font-size:15px;margin:14px 0 2px;padding:0;border:0}
.catcode-abandoned-cart-wrap .form-table th{width:260px;font-weight:600}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-locked{opacity:.65}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-badge{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:9px;background:#8c52ff;color:#fff;font-size:11px;font-weight:700;vertical-align:middle}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-hint{color:#1a7f37;font-weight:600}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-status{font-weight:600}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-status--off{color:#646970}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-status--wait{color:#8a4b08}
.catcode-abandoned-cart-wrap textarea{width:100%;max-width:640px;font-family:Consolas,Monaco,monospace;font-size:12px}
.catcode-abandoned-cart-wrap .catcode-abandoned-cart-tokens code{background:#f0f0f1;padding:1px 5px;border-radius:3px}
';
	}
}
