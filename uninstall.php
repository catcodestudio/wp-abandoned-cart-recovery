<?php
/**
 * Uninstall — removes the cart table, the options and the per-user notice flag.
 *
 * Captured carts hold personal data, so nothing is left behind on uninstall.
 *
 * @package CatCodeAbandonedCart
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$catcode_abandoned_cart_table = $wpdb->prefix . 'catcode_abandoned_carts';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off uninstall cleanup.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $catcode_abandoned_cart_table ) );

delete_option( 'catcode_abandoned_cart_settings' );
delete_option( 'catcode_abandoned_cart_version' );
delete_option( 'catcode_abandoned_cart_trial_started' );
delete_option( 'catcode_abandoned_cart_telegram_webhook_secret' );

delete_metadata( 'user', 0, 'catcode_abandoned_cart_trial_notice_off', '', true );

wp_clear_scheduled_hook( 'catcode_abandoned_cart_scan' );
wp_clear_scheduled_hook( 'catcode_abandoned_cart_cleanup' );
