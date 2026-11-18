<?php
/**
 * Self-test: opens the home page (with and without optimization) and checks the site responds normally.
 * If it fails after an update, it turns safe mode on by itself.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Self_Test {

	const OPTION = 'fast_wp_self_test';

	/** PHP and WordPress errors that mean a broken page. */
	const ERROR_SIGNS = array( 'Fatal error', 'Parse error', 'There has been a critical error', 'Houve um erro crítico', 'Ocorreu um erro crítico', 'Uncaught Error' );

	/** @return array ['ok' => bool, 'checks' => [...], 'time' => int] */
	public static function run() {
		$home   = home_url( '/' );
		$checks = array(
			'normal'     => self::check( add_query_arg( 'fwp_selftest', wp_rand(), $home ), false ),
			'optimized'  => self::check( add_query_arg( array( 'fwp_test' => 1, 'fwp_selftest' => wp_rand() ), $home ), true ),
		);
		$ok     = $checks['normal']['ok'] && $checks['optimized']['ok'];
		$result = array(
			'ok'     => $ok,
			'checks' => $checks,
			'time'   => time(),
		);
		update_option( self::OPTION, $result, false );
		return $result;
	}

	private static function check( $url, $expect_optimized ) {
		$res = wp_remote_get(
			$url,
			array(
				'timeout'   => 30,
				'sslverify' => false,
				'cookies'   => array(),
				'headers'   => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $res ) ) {
			// Some servers block the site from requesting itself: not a plugin error.
			return array(
				'ok'      => true,
				'skipped' => true,
				'message' => t( 'Could not test (the server blocked the site from requesting itself): %s', $res->get_error_message() ),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code >= 500 ) {
			return array( 'ok' => false, 'message' => t( 'The site responded with error %d.', $code ) );
		}
		foreach ( self::ERROR_SIGNS as $sign ) {
			if ( false !== stripos( $body, $sign ) ) {
				return array( 'ok' => false, 'message' => t( 'The page shows a PHP/WordPress error ("%s").', $sign ) );
			}
		}
		if ( 200 === $code && false === stripos( $body, '</html>' ) ) {
			return array( 'ok' => false, 'message' => t( 'The page came back incomplete.' ) );
		}
		$msg = t( 'OK (code %d).', $code );
		if ( $expect_optimized && 200 === $code && false === strpos( $body, 'fwp: optimized' ) ) {
			$msg = t( 'The site responded, but the page was not optimized. Check whether another cache plugin is active.' );
		}
		return array( 'ok' => true, 'message' => $msg );
	}

	/** Runs when the plugin version changes (update). On failure: turns safe mode on. */
	public static function after_update() {
		$result = self::run();
		if ( ! $result['ok'] ) {
			Settings::update( array( 'safe_mode' => 1 ) );
			Htaccess::sync();
			Cache::purge_all();
		}
		return $result;
	}
}
