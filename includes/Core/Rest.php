<?php
/**
 * REST endpoint used by the block checkout to hand us the shopper's e-mail
 * as soon as they type it — the classic checkout does the same thing through
 * the update_order_review AJAX call.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

defined( 'ABSPATH' ) || exit;

class Rest {

	public const REST_NAMESPACE = 'catcode-abandoned-cart/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	public function routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/capture',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'capture' ),
				// Public by design: a shopper checking out is not authenticated.
				// The wp_rest nonce sent by the front-end script is verified in
				// the callback, and only the caller's own session is touched.
				'permission_callback' => '__return_true',
				'args'                => array(
					'email' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'phone' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'name'  => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function capture( $request ) {
		$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error(
				'catcode_abandoned_cart_bad_nonce',
				__( 'Invalid security token.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				array( 'status' => 403 )
			);
		}

		$email = sanitize_email( (string) $request->get_param( 'email' ) );
		$email = ( '' !== $email && is_email( $email ) ) ? $email : '';
		$phone = sanitize_text_field( (string) $request->get_param( 'phone' ) );

		if ( '' === $email && '' === $phone ) {
			return new \WP_Error(
				'catcode_abandoned_cart_bad_email',
				__( 'A valid e-mail address is required.', 'catcode-abandoned-cart-recovery-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		// WooCommerce does not boot the session or the cart on custom REST
		// routes, so without this the capture would silently write nothing.
		if ( function_exists( 'wc_load_cart' ) && function_exists( 'WC' ) && ( ! WC()->cart || ! WC()->session ) ) {
			wc_load_cart();
		}

		if ( '' !== $phone ) {
			Capture::remember_phone( $phone, $name );
		}
		if ( '' !== $email ) {
			Capture::remember_email( $email, $name );
		}

		return new \WP_REST_Response( array( 'captured' => true ), 200 );
	}
}
