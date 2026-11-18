<?php
/**
 * Decide se a requisição pode usar o cache de página e calcula o caminho do arquivo.
 *
 * Esta classe NÃO usa funções do WordPress: ela também é carregada pelo advanced-cache.php,
 * que roda antes do WordPress terminar de carregar.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Request {

	/** Cookies que indicam visitante logado, com carrinho ou com conteúdo personalizado. */
	const DEFAULT_COOKIES = array(
		'wordpress_logged_in_',
		'wp-postpass_',
		'comment_author_',
		'woocommerce_items_in_cart',
		'woocommerce_cart_hash',
		'wp_woocommerce_session_',
		'edd_items_in_cart',
	);

	/** Caminhos que nunca entram no cache. */
	const DENY_PATHS = '#(/wp-admin|/wp-login\.php|/wp-json|/xmlrpc\.php|/wp-cron\.php|/wp-content/|/wp-includes/|\.php$|/feed/?$|/embed/?$|/trackback/?$|sitemap[^/]*\.xml|robots\.txt$|\.(xml|txt|json|ico|map|xsl)$)#i';

	/**
	 * Retorna o destino do cache para a requisição atual ou null se ela não pode usar cache.
	 *
	 * @param array $cfg     Configuração gravada pelo plugin (config.php).
	 * @param array $server  Normalmente $_SERVER.
	 * @param array $cookies Normalmente $_COOKIE.
	 * @return array|null    ['dir' => ..., 'file' => ...]
	 */
	public static function cache_target( array $cfg, array $server, array $cookies ) {
		$method = isset( $server['REQUEST_METHOD'] ) ? strtoupper( $server['REQUEST_METHOD'] ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return null;
		}
		if ( empty( $server['REQUEST_URI'] ) || empty( $cfg['dir'] ) ) {
			return null;
		}

		$blocked = self::DEFAULT_COOKIES;
		if ( ! empty( $cfg['exclude_cookies'] ) ) {
			$blocked = array_merge( $blocked, (array) $cfg['exclude_cookies'] );
		}
		foreach ( array_keys( $cookies ) as $name ) {
			foreach ( $blocked as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( (string) $name, $prefix ) ) {
					return null;
				}
			}
		}

		$host = self::host( $server );
		if ( '' === $host || empty( $cfg['hosts'] ) || ! in_array( $host, (array) $cfg['hosts'], true ) ) {
			return null;
		}

		$uri   = (string) $server['REQUEST_URI'];
		$qpos  = strpos( $uri, '?' );
		$path  = false === $qpos ? $uri : substr( $uri, 0, $qpos );
		$query = false === $qpos ? '' : substr( $uri, $qpos + 1 );

		if ( '' !== $query && ! self::query_is_ignorable( $query, isset( $cfg['ignore_params'] ) ? (array) $cfg['ignore_params'] : array() ) ) {
			return null;
		}
		if ( ! self::path_is_cacheable( $path, isset( $cfg['exclude_urls'] ) ? (array) $cfg['exclude_urls'] : array() ) ) {
			return null;
		}

		$mobile = ! empty( $cfg['mobile_separate'] ) && self::is_mobile( $server );

		return self::target_for( $cfg['dir'], $host, $path, self::is_https( $server ), $mobile );
	}

	/** Host da requisição, em minúsculas e sem porta. */
	public static function host( array $server ) {
		$host = isset( $server['HTTP_HOST'] ) ? strtolower( (string) $server['HTTP_HOST'] ) : '';
		return (string) preg_replace( '/:\d+$/', '', $host );
	}

	/** True quando todos os parâmetros da URL podem ser ignorados (utm, gclid, fbclid...). */
	public static function query_is_ignorable( $query, array $ignore ) {
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$eq   = strpos( $pair, '=' );
			$name = urldecode( false === $eq ? $pair : substr( $pair, 0, $eq ) );
			if ( '' === $name ) {
				continue;
			}
			$ok = false;
			foreach ( $ignore as $rule ) {
				$rule = trim( (string) $rule );
				if ( '' === $rule ) {
					continue;
				}
				if ( '*' === substr( $rule, -1 ) ) {
					if ( 0 === strpos( $name, substr( $rule, 0, -1 ) ) ) {
						$ok = true;
						break;
					}
				} elseif ( $name === $rule ) {
					$ok = true;
					break;
				}
			}
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/** Verifica se o caminho pode ir para o cache. */
	public static function path_is_cacheable( $path, array $exclude ) {
		if ( '' === $path || '/' !== $path[0] || false !== strpos( $path, '..' ) ) {
			return false;
		}
		if ( preg_match( '#[^A-Za-z0-9\-._~%/!$&\'()*+,;=:@]#', $path ) ) {
			return false;
		}
		if ( preg_match( self::DENY_PATHS, $path ) ) {
			return false;
		}
		foreach ( $exclude as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' !== $rule && false !== strpos( $path, $rule ) ) {
				return false;
			}
		}
		return true;
	}

	public static function is_https( array $server ) {
		if ( ! empty( $server['HTTPS'] ) && 'off' !== strtolower( (string) $server['HTTPS'] ) ) {
			return true;
		}
		if ( isset( $server['SERVER_PORT'] ) && '443' === (string) $server['SERVER_PORT'] ) {
			return true;
		}
		if ( isset( $server['HTTP_X_FORWARDED_PROTO'] ) && 'https' === strtolower( (string) $server['HTTP_X_FORWARDED_PROTO'] ) ) {
			return true;
		}
		return isset( $server['HTTP_X_FORWARDED_SSL'] ) && 'on' === strtolower( (string) $server['HTTP_X_FORWARDED_SSL'] );
	}

	public static function is_mobile( array $server ) {
		$ua = isset( $server['HTTP_USER_AGENT'] ) ? (string) $server['HTTP_USER_AGENT'] : '';
		return (bool) preg_match( '/Mobile|Android|Silk\/|Kindle|BlackBerry|Opera Mini|Opera Mobi|iPhone|iPod/i', $ua );
	}

	/**
	 * Calcula pasta e arquivo do cache. A estrutura casa com as regras do .htaccess:
	 * pages/{host}/{caminho}/index[-mobile][-https].html
	 */
	public static function target_for( $base, $host, $path, $https, $mobile ) {
		$path     = (string) preg_replace( '#/+#', '/', $path );
		$trailing = '/' === substr( $path, -1 );
		$clean    = trim( $path, '/' );
		$dir      = rtrim( $base, '/' ) . '/pages/' . $host . ( '' === $clean ? '' : '/' . $clean );
		$name     = 'index' . ( $mobile ? '-mobile' : '' ) . ( $https ? '-https' : '' ) . ( ! $trailing && '' !== $clean ? '-noslash' : '' ) . '.html';
		return array(
			'dir'  => $dir,
			'file' => $dir . '/' . $name,
		);
	}

	/**
	 * Entrega o arquivo do cache, se existir e estiver válido.
	 *
	 * @return bool True se a resposta foi enviada.
	 */
	public static function serve( array $target, array $cfg, array $server ) {
		$file = $target['file'];
		if ( ! is_file( $file ) ) {
			return false;
		}
		$mtime = (int) filemtime( $file );
		if ( ! empty( $cfg['lifespan'] ) && $mtime + (int) $cfg['lifespan'] < time() ) {
			return false;
		}
		if ( headers_sent() ) {
			return false;
		}

		$charset = ! empty( $cfg['charset'] ) ? $cfg['charset'] : 'UTF-8';
		header( 'Content-Type: text/html; charset=' . $charset );
		header( 'Vary: Accept-Encoding, Cookie' );
		header( 'Cache-Control: no-cache, must-revalidate, max-age=0' );
		header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $mtime ) . ' GMT' );
		header( 'X-Fast-WP: HIT' );

		if ( ! empty( $server['HTTP_IF_MODIFIED_SINCE'] ) ) {
			$since = strtotime( (string) $server['HTTP_IF_MODIFIED_SINCE'] );
			if ( $since && $since >= $mtime ) {
				http_response_code( 304 );
				return true;
			}
		}

		$head = isset( $server['REQUEST_METHOD'] ) && 'HEAD' === strtoupper( $server['REQUEST_METHOD'] );
		$gz   = $file . '.gz';
		$use_gz = isset( $server['HTTP_ACCEPT_ENCODING'] )
			&& false !== stripos( (string) $server['HTTP_ACCEPT_ENCODING'], 'gzip' )
			&& ! ini_get( 'zlib.output_compression' )
			&& is_file( $gz )
			&& filemtime( $gz ) >= $mtime;

		if ( $use_gz ) {
			header( 'Content-Encoding: gzip' );
			header( 'Content-Length: ' . filesize( $gz ) );
			if ( ! $head ) {
				readfile( $gz );
			}
		} else {
			header( 'Content-Length: ' . filesize( $file ) );
			if ( ! $head ) {
				readfile( $file );
			}
		}
		return true;
	}

	/** Grava o HTML no cache de forma atômica (arquivo temporário + rename). */
	public static function store( array $target, $html ) {
		$dir = $target['dir'];
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore
			return false;
		}
		$tmp = $target['file'] . '.' . getmypid() . '.tmp';
		if ( false === @file_put_contents( $tmp, $html ) ) { // phpcs:ignore
			return false;
		}
		@rename( $tmp, $target['file'] ); // phpcs:ignore
		if ( function_exists( 'gzencode' ) ) {
			$gz = gzencode( $html, 6 );
			if ( false !== $gz ) {
				$tmpgz = $target['file'] . '.gz.' . getmypid() . '.tmp';
				if ( false !== @file_put_contents( $tmpgz, $gz ) ) { // phpcs:ignore
					@rename( $tmpgz, $target['file'] . '.gz' ); // phpcs:ignore
				}
			}
		}
		return true;
	}
}
