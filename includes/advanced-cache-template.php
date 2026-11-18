<?php
/**
 * Fast WP - advanced-cache.php (FAST_WP_DROPIN)
 *
 * File generated automatically by the Fast WP plugin. It serves cached pages
 * before WordPress loads. It is removed when the plugin is deactivated.
 *
 * To switch everything off in an emergency, add to wp-config.php:
 * define( 'FAST_WP_DISABLE', true );
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'FAST_WP_DISABLE' ) && FAST_WP_DISABLE ) {
	return;
}
if ( 'cli' === PHP_SAPI || ( defined( 'WP_CLI' ) && WP_CLI ) || isset( $_GET['fwp_off'] ) || isset( $_GET['fwp_test'] ) ) {
	return;
}

$fast_wp_cfg_file = WP_CONTENT_DIR . '/cache/fast-wp/config.php';
if ( ! is_file( $fast_wp_cfg_file ) ) {
	return;
}
$fast_wp_cfg = include $fast_wp_cfg_file;
if ( ! is_array( $fast_wp_cfg ) || empty( $fast_wp_cfg['enabled'] ) || empty( $fast_wp_cfg['request_class'] ) || ! is_file( $fast_wp_cfg['request_class'] ) ) {
	return;
}

require_once $fast_wp_cfg['request_class'];

$fast_wp_target = \FastWP\Request::cache_target( $fast_wp_cfg, $_SERVER, $_COOKIE );
if ( $fast_wp_target && \FastWP\Request::serve( $fast_wp_target, $fast_wp_cfg, $_SERVER ) ) {
	exit;
}
unset( $fast_wp_cfg_file, $fast_wp_cfg, $fast_wp_target );
