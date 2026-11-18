<?php
/**
 * Funções comuns para arquivos: URL <-> caminho, gravação de arquivos otimizados e WebP.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Assets {

	/** @var array */
	private static $webp_memo = array();

	public static function dir( $sub = '' ) {
		return FAST_WP_CACHE_DIR . '/assets' . ( $sub ? '/' . $sub : '' );
	}

	public static function url( $sub = '' ) {
		return content_url( '/cache/fast-wp/assets' . ( $sub ? '/' . $sub : '' ) );
	}

	/**
	 * Converte a URL de um arquivo do site no caminho do arquivo no servidor.
	 *
	 * @return string|null
	 */
	public static function url_to_path( $url ) {
		$url = html_entity_decode( (string) $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['path'] ) ) {
			return null;
		}
		if ( ! empty( $parts['host'] ) && ! in_array( strtolower( $parts['host'] ), Settings::hosts(), true ) ) {
			return null;
		}
		$path = rawurldecode( $parts['path'] );
		if ( false !== strpos( $path, '..' ) ) {
			return null;
		}

		$maps = array(
			wp_parse_url( content_url(), PHP_URL_PATH ) => WP_CONTENT_DIR,
			wp_parse_url( includes_url(), PHP_URL_PATH ) => ABSPATH . WPINC,
			wp_parse_url( site_url( '/' ), PHP_URL_PATH ) => ABSPATH,
		);
		foreach ( $maps as $url_path => $dir ) {
			$url_path = rtrim( (string) $url_path, '/' ) . '/';
			if ( 0 === strpos( $path, $url_path ) ) {
				$file = rtrim( $dir, '/\\' ) . '/' . substr( $path, strlen( $url_path ) );
				return is_file( $file ) ? $file : null;
			}
		}
		return null;
	}

	/**
	 * Grava um arquivo otimizado (nome pelo conteúdo) e devolve a URL.
	 *
	 * @return string|null
	 */
	public static function write( $content, $ext ) {
		$dir = self::dir( $ext );
		if ( ! wp_mkdir_p( $dir ) ) {
			return null;
		}
		$name = substr( md5( $content ), 0, 16 ) . '.' . $ext;
		$file = $dir . '/' . $name;
		if ( is_file( $file ) ) {
			@touch( $file ); // phpcs:ignore -- marca como em uso (limpeza de arquivos antigos).
		} else {
			$tmp = $file . '.' . getmypid() . '.tmp';
			if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore
				return null;
			}
			rename( $tmp, $file );
		}
		return self::url( $ext . '/' . $name );
	}

	/** Lê um arquivo do cache de processamento (por chave). */
	public static function memo_get( $key ) {
		$file = self::dir( 'memo' ) . '/' . $key;
		return is_file( $file ) ? file_get_contents( $file ) : null; // phpcs:ignore
	}

	public static function memo_set( $key, $data ) {
		$dir = self::dir( 'memo' );
		if ( wp_mkdir_p( $dir ) ) {
			$tmp = $dir . '/' . $key . '.' . getmypid() . '.tmp';
			if ( false !== file_put_contents( $tmp, $data ) ) { // phpcs:ignore
				rename( $tmp, $dir . '/' . $key );
			}
		}
	}

	/** Resolve uma URL relativa a partir da URL do arquivo CSS. */
	public static function resolve( $base_url, $rel ) {
		if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|\#)#i', $rel ) ) {
			return $rel; // Já é absoluta, data:, ou âncora.
		}
		$parts = wp_parse_url( $base_url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return $rel;
		}
		$origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] . ':' : '' ) . '//' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		if ( '/' === $rel[0] ) {
			return $origin . $rel;
		}
		$dir   = isset( $parts['path'] ) ? preg_replace( '#/[^/]*$#', '/', $parts['path'] ) : '/';
		$path  = $dir . $rel;
		$query = '';
		if ( preg_match( '/^([^?#]*)([?#].*)$/', $path, $qm ) ) {
			$path  = $qm[1];
			$query = $qm[2];
		}
		$segments = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '..' === $seg ) {
				array_pop( $segments );
			} elseif ( '.' !== $seg ) {
				$segments[] = $seg;
			}
		}
		$path = implode( '/', $segments );
		return $origin . ( '/' === substr( $path, 0, 1 ) ? '' : '/' ) . $path . $query;
	}

	/**
	 * Troca a URL de uma imagem JPG/PNG pela versão WebP, se existir.
	 * As versões WebP ficam ao lado do original: foto.jpg -> foto.jpg.webp
	 */
	public static function webp( $url ) {
		if ( ! Settings::get( 'webp_serve' ) || ! preg_match( '#\.(jpe?g|png)(\?.*)?$#i', $url ) ) {
			return $url;
		}
		if ( isset( self::$webp_memo[ $url ] ) ) {
			return self::$webp_memo[ $url ];
		}
		$clean = preg_replace( '/\?.*$/', '', $url );
		$path  = self::url_to_path( $clean );
		$out   = $url;
		if ( $path && is_file( $path . '.webp' ) ) {
			$out = $clean . '.webp';
		}
		self::$webp_memo[ $url ] = $out;
		return $out;
	}

	/** Aplica WebP em todas as URLs de um srcset. */
	public static function webp_srcset( $srcset ) {
		$items = array_map( 'trim', explode( ',', $srcset ) );
		foreach ( $items as &$item ) {
			$bits = preg_split( '/\s+/', $item, 2 );
			if ( ! empty( $bits[0] ) ) {
				$bits[0] = self::webp( $bits[0] );
			}
			$item = implode( ' ', $bits );
		}
		return implode( ', ', $items );
	}

	/** Lista de URLs de scripts e trechos que contam como pixel/rastreamento. */
	public static function tracker_patterns() {
		return array(
			'googletagmanager.com', 'gtag(', 'gtm.js', 'dataLayer', 'google-analytics.com', 'googleadservices.com',
			'fbevents.js', 'fbq(', 'connect.facebook.net', 'analytics.tiktok.com', 'ttq.', 'clarity.ms', 'hotjar',
			'snap.licdn.com', '_linkedin_partner_id', 'pintrk', 's.pinimg.com', 'sc-static.net', 'bat.bing.com',
		);
	}

	/** Plugins de pixel que precisam do jQuery carregado. */
	public static function jquery_trackers() {
		return array( 'pixelyoursite', 'pysOptions', 'pys-version', 'pys-pbid', 'pys_' );
	}

	/** True se o texto contém algum dos trechos da lista. */
	public static function matches( $haystack, array $needles ) {
		foreach ( $needles as $n ) {
			if ( '' !== $n && false !== stripos( $haystack, $n ) ) {
				return true;
			}
		}
		return false;
	}
}
