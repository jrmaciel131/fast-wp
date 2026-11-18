<?php
/**
 * Autoteste: abre a página inicial (com e sem otimização) e confere se o site responde normalmente.
 * Se falhar depois de uma atualização, liga o modo de segurança sozinho.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Self_Test {

	const OPTION = 'fast_wp_self_test';

	/** Erros de PHP e do WordPress que indicam página quebrada. */
	const ERROR_SIGNS = array( 'Fatal error', 'Parse error', 'There has been a critical error', 'Houve um erro crítico', 'Ocorreu um erro crítico', 'Uncaught Error' );

	/** @return array ['ok' => bool, 'checks' => [...], 'time' => int] */
	public static function run() {
		$home   = home_url( '/' );
		$checks = array(
			'normal'     => self::check( add_query_arg( 'fwp_selftest', wp_rand(), $home ), false ),
			'otimizado'  => self::check( add_query_arg( array( 'fwp_test' => 1, 'fwp_selftest' => wp_rand() ), $home ), true ),
		);
		$ok     = $checks['normal']['ok'] && $checks['otimizado']['ok'];
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
			// Alguns servidores bloqueiam o site de acessar a si mesmo: não é erro do plugin.
			return array(
				'ok'      => true,
				'skipped' => true,
				'message' => 'Não foi possível testar (o servidor bloqueou o acesso a si mesmo): ' . $res->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code >= 500 ) {
			return array( 'ok' => false, 'message' => 'O site respondeu com erro ' . $code . '.' );
		}
		foreach ( self::ERROR_SIGNS as $sign ) {
			if ( false !== stripos( $body, $sign ) ) {
				return array( 'ok' => false, 'message' => 'A página mostra um erro do PHP/WordPress ("' . $sign . '").' );
			}
		}
		if ( 200 === $code && false === stripos( $body, '</html>' ) ) {
			return array( 'ok' => false, 'message' => 'A página veio incompleta.' );
		}
		$msg = 'OK (código ' . $code . ').';
		if ( $expect_optimized && 200 === $code && false === strpos( $body, 'fwp: otimizado' ) ) {
			$msg = 'O site respondeu, mas a página não veio otimizada. Confira se outro plugin de cache está ativo.';
		}
		return array( 'ok' => true, 'message' => $msg );
	}

	/** Roda quando a versão do plugin muda (atualização). Falhou: liga o modo de segurança. */
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
