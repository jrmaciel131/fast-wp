<?php
/**
 * .htaccess rules (Apache/LiteSpeed): cache served directly, GZIP and browser caching.
 * The block always sits BEFORE the WordPress rules and is removed when the plugin is deactivated.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Htaccess {

	const BEGIN = '# BEGIN Fast WP';
	const END   = '# END Fast WP';

	public static function is_apache() {
		$soft = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) ) : '';
		if ( '' !== $soft ) {
			$apache = false !== strpos( $soft, 'apache' ) || false !== strpos( $soft, 'litespeed' );
			update_option( 'fast_wp_server_apache', $apache ? 1 : 0, false );
			return $apache;
		}
		// Command line (WP-CLI) or cron without a server: use what a previous visit saw.
		$known = get_option( 'fast_wp_server_apache', null );
		if ( null !== $known && false !== $known ) {
			return (bool) $known;
		}
		// No information: a .htaccess with WordPress rules means Apache/LiteSpeed.
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = get_home_path() . '.htaccess';
		return is_file( $file ) && false !== stripos( (string) file_get_contents( $file ), 'RewriteEngine' ); // phpcs:ignore
	}

	public static function path() {
		return get_home_path() . '.htaccess';
	}

	/** Writes (or removes) the block according to the settings. */
	public static function sync() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$rules = self::rules();
		self::write_pages_htaccess();
		return self::write_block( $rules );
	}

	public static function remove() {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return self::write_block( '' );
	}

	/** Puts the block at the top of .htaccess, before "# BEGIN WordPress". */
	private static function write_block( $rules ) {
		$file = self::path();
		if ( ! file_exists( $file ) ) {
			if ( '' === $rules || ! is_writable( dirname( $file ) ) ) {
				return '' === $rules;
			}
			$current = '';
		} else {
			if ( ! is_writable( $file ) ) {
				return false;
			}
			$current = (string) file_get_contents( $file ); // phpcs:ignore
		}
		$clean = preg_replace( '/' . preg_quote( self::BEGIN, '/' ) . '.*?' . preg_quote( self::END, '/' ) . '\R*/s', '', $current );
		$new   = '' === $rules ? $clean : self::BEGIN . "\n" . $rules . "\n" . self::END . "\n\n" . ltrim( $clean );
		if ( $new === $current ) {
			return true;
		}
		return false !== file_put_contents( $file, $new, LOCK_EX ); // phpcs:ignore
	}

	/** Builds the rules. */
	public static function rules() {
		$s   = Settings::all();
		$out = array( '# Rules from the Fast WP plugin. Do not edit between these lines.' );

		if ( ! empty( $s['webp_serve'] ) ) {
			$out[] = '<IfModule mod_mime.c>';
			$out[] = 'AddType image/webp .webp';
			$out[] = 'AddType font/woff2 .woff2';
			$out[] = '</IfModule>';
		}

		if ( ! empty( $s['cache_enabled'] ) && ! empty( $s['cache_htaccess'] ) && self::is_apache() ) {
			$out = array_merge( $out, self::cache_rules( $s ) );
		}

		if ( ! empty( $s['gzip'] ) ) {
			$out[] = '<IfModule mod_deflate.c>';
			$out[] = 'AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml text/javascript application/javascript application/x-javascript application/json application/xml application/rss+xml image/svg+xml font/ttf font/otf application/vnd.ms-fontobject';
			$out[] = '</IfModule>';
		}

		if ( ! empty( $s['browser_cache'] ) ) {
			$out[] = '<IfModule mod_expires.c>';
			$out[] = 'ExpiresActive On';
			$out[] = 'ExpiresDefault "access plus 1 month"';
			$out[] = 'ExpiresByType text/html "access plus 0 seconds"';
			$out[] = 'ExpiresByType application/json "access plus 0 seconds"';
			$out[] = 'ExpiresByType application/xml "access plus 0 seconds"';
			$out[] = 'ExpiresByType text/xml "access plus 0 seconds"';
			foreach ( array( 'text/css', 'application/javascript', 'text/javascript', 'application/x-javascript', 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/svg+xml', 'image/x-icon', 'image/vnd.microsoft.icon', 'video/mp4', 'video/webm', 'font/woff2', 'font/woff', 'font/ttf', 'font/otf', 'application/font-woff', 'application/font-woff2', 'application/vnd.ms-fontobject' ) as $type ) {
				$out[] = 'ExpiresByType ' . $type . ' "access plus 1 year"';
			}
			$out[] = '</IfModule>';
			$out[] = '<IfModule mod_headers.c>';
			$out[] = '<FilesMatch "\.(css|js|jpe?g|png|gif|webp|avif|svg|ico|woff2?|ttf|otf|eot|mp4|webm)$">';
			$out[] = 'Header set Cache-Control "public, max-age=31536000"';
			$out[] = '</FilesMatch>';
			$out[] = '</IfModule>';
		}

		return count( $out ) > 1 ? implode( "\n", $out ) : '';
	}

	/** Rules that serve the cache file without going through PHP. */
	private static function cache_rules( array $s ) {
		$home     = wp_parse_url( home_url( '/' ) );
		$base     = isset( $home['path'] ) ? rtrim( $home['path'], '/' ) . '/' : '/';
		$https    = isset( $home['scheme'] ) && 'https' === $home['scheme'];
		$suffix   = $https ? '-https' : '';
		$cache_fs = str_replace( '\\', '/', FAST_WP_CACHE_DIR ) . '/pages';
		$cache_url = wp_parse_url( content_url( '/cache/fast-wp/pages' ), PHP_URL_PATH );

		$cookies = array_merge( Request::DEFAULT_COOKIES, Settings::lines( 'cache_exclude_cookies' ) );
		$cookies = implode( '|', array_map( array( __CLASS__, 'regex_escape' ), $cookies ) );

		$conds   = array();
		$conds[] = 'RewriteCond %{REQUEST_METHOD} =GET';
		$conds[] = 'RewriteCond %{QUERY_STRING} =""';
		$conds[] = 'RewriteCond %{HTTP:Cookie} !(' . $cookies . ') [NC]';
		$conds[] = 'RewriteCond %{REQUEST_URI} /$';
		$conds[] = 'RewriteCond %{REQUEST_URI} !^' . self::regex_escape( $base ) . '(wp-admin|wp-json|wp-content|wp-includes)/';
		if ( $https ) {
			$conds[] = 'RewriteCond %{HTTPS} =on [OR]';
			$conds[] = 'RewriteCond %{HTTP:X-Forwarded-Proto} =https [OR]';
			$conds[] = 'RewriteCond %{SERVER_PORT} =443';
		}
		if ( ! empty( $s['cache_mobile_separate'] ) ) {
			$conds[] = 'RewriteCond %{HTTP_USER_AGENT} !(Mobile|Android|Silk/|Kindle|BlackBerry|Opera\ Mini|Opera\ Mobi|iPhone|iPod) [NC]';
		}

		$out   = array();
		$out[] = '<IfModule mod_rewrite.c>';
		$out[] = 'RewriteEngine On';
		$out[] = 'RewriteBase ' . $base;
		// Compressed version (.gz).
		$out[] = '<IfModule mod_mime.c>';
		$out   = array_merge( $out, $conds );
		$out[] = 'RewriteCond %{HTTP:Accept-Encoding} gzip';
		$out[] = 'RewriteCond "' . $cache_fs . '/%{HTTP_HOST}%{REQUEST_URI}index' . $suffix . '.html.gz" -f';
		$out[] = 'RewriteRule .* "' . $cache_url . '/%{HTTP_HOST}%{REQUEST_URI}index' . $suffix . '.html.gz" [L]';
		$out[] = '</IfModule>';
		// Plain version.
		$out   = array_merge( $out, $conds );
		$out[] = 'RewriteCond "' . $cache_fs . '/%{HTTP_HOST}%{REQUEST_URI}index' . $suffix . '.html" -f';
		$out[] = 'RewriteRule .* "' . $cache_url . '/%{HTTP_HOST}%{REQUEST_URI}index' . $suffix . '.html" [L]';
		$out[] = '</IfModule>';
		return $out;
	}

	/** .htaccess inside the cache folder: correct headers for HTML and .gz. */
	public static function write_pages_htaccess() {
		$dir = FAST_WP_CACHE_DIR . '/pages';
		if ( ! wp_mkdir_p( $dir ) ) {
			return;
		}
		$rules = array(
			'# Fast WP',
			'<IfModule mod_mime.c>',
			'<FilesMatch "\.html\.gz$">',
			'ForceType text/html',
			'FileETag None',
			'</FilesMatch>',
			'AddEncoding gzip .gz',
			'AddType text/html .gz',
			'</IfModule>',
			'<IfModule mod_deflate.c>',
			'SetEnvIf Request_URI \.gz$ no-gzip',
			'</IfModule>',
			'<IfModule mod_headers.c>',
			'Header set Vary "Accept-Encoding, Cookie"',
			'Header set Cache-Control "no-cache, must-revalidate, max-age=0"',
			'Header set X-Fast-WP "HIT (apache)"',
			'</IfModule>',
			'<IfModule mod_expires.c>',
			'ExpiresActive Off',
			'</IfModule>',
		);
		@file_put_contents( $dir . '/.htaccess', implode( "\n", $rules ) . "\n" ); // phpcs:ignore
		@file_put_contents( FAST_WP_CACHE_DIR . '/index.html', '' ); // phpcs:ignore
	}

	public static function regex_escape( $s ) {
		return preg_replace( '/([.^$*+?()\[\]{}|\\\\ ])/', '\\\\$1', $s );
	}
}
