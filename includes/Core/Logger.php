<?php
/**
 * Thin wrapper over the WooCommerce logger.
 *
 * Nothing is written unless WooCommerce's own logging is available, so the
 * plugin never creates files of its own.
 *
 * @package CatCodeAbandonedCart
 */

namespace CatCode\AbandonedCart\Core;

defined( 'ABSPATH' ) || exit;

class Logger {

	private const SOURCE = 'catcode-abandoned-cart';

	public static function info( string $message ): void {
		self::write( 'info', $message );
	}

	public static function error( string $message ): void {
		self::write( 'error', $message );
	}

	private static function write( string $level, string $message ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$logger = wc_get_logger();
		if ( ! $logger ) {
			return;
		}
		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
