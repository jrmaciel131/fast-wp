<?php
/**
 * Minimal environment to test the Fast WP classes without installing WordPress.
 * It only mimics the WordPress functions used by the optimization classes.
 */

error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$fwp_test_root = getenv( 'FWP_TEST_ROOT' ) ? getenv( 'FWP_TEST_ROOT' ) : __DIR__ . '/output/site';
$fwp_test_home = getenv( 'FWP_TEST_HOME' ) ? getenv( 'FWP_TEST_HOME' ) : 'https://example.com';

define( 'ABSPATH', rtrim( str_replace( '\\', '/', $fwp_test_root ), '/' ) . '/' );
define( 'WPINC', 'wp-includes' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'MB_IN_BYTES', 1048576 );
define( 'FAST_WP_VERSION', 'test' );
define( 'FAST_WP_DIR', dirname( __DIR__ ) . '/' );
define( 'FAST_WP_FILE', FAST_WP_DIR . 'fast-wp.php' );
define( 'FAST_WP_CACHE_DIR', WP_CONTENT_DIR . '/cache/fast-wp' );
define( 'FAST_WP_GITHUB_REPO', '' );
define( 'FWP_TEST_HOME', rtrim( $fwp_test_home, '/' ) );

$GLOBALS['fwp_options']    = array();
$GLOBALS['fwp_transients'] = array();

function get_option( $k, $d = false ) {
	return array_key_exists( $k, $GLOBALS['fwp_options'] ) ? $GLOBALS['fwp_options'][ $k ] : $d;
}
function update_option( $k, $v ) {
	$GLOBALS['fwp_options'][ $k ] = $v;
	return true;
}
function get_transient( $k ) {
	return isset( $GLOBALS['fwp_transients'][ $k ] ) ? $GLOBALS['fwp_transients'][ $k ] : false;
}
function set_transient( $k, $v ) {
	$GLOBALS['fwp_transients'][ $k ] = $v;
	return true;
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function home_url( $path = '' ) {
	return FWP_TEST_HOME . '/' . ltrim( $path, '/' );
}
function site_url( $path = '' ) {
	return home_url( $path );
}
function content_url( $path = '' ) {
	return FWP_TEST_HOME . '/wp-content' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
}
function includes_url( $path = '' ) {
	return FWP_TEST_HOME . '/wp-includes/' . ltrim( $path, '/' );
}
function is_ssl() {
	return 0 === strpos( FWP_TEST_HOME, 'https' );
}
function wp_mkdir_p( $dir ) {
	return is_dir( $dir ) || @mkdir( $dir, 0777, true );
}
function esc_url( $u ) {
	return str_replace( array( '&', '"', "'" ), array( '&#038;', '%22', '%27' ), (string) $u );
}
function esc_url_raw( $u ) {
	return (string) $u;
}
function esc_attr( $s ) {
	return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8', false );
}
function esc_html( $s ) {
	return esc_attr( $s );
}
function wp_json_encode( $d, $o = 0 ) {
	return json_encode( $d, $o | JSON_UNESCAPED_SLASHES );
}
function untrailingslashit( $s ) {
	return rtrim( $s, '/\\' );
}
function trailingslashit( $s ) {
	return untrailingslashit( $s ) . '/';
}
function add_query_arg( $k, $v = null, $url = null ) {
	if ( is_array( $k ) ) {
		$url  = $v;
		$args = $k;
	} else {
		$args = array( $k => $v );
	}
	$sep = false === strpos( $url, '?' ) ? '?' : '&';
	return $url . $sep . http_build_query( $args );
}
function remove_query_arg( $k, $url ) {
	$parts = explode( '?', $url, 2 );
	if ( count( $parts ) < 2 ) {
		return $url;
	}
	parse_str( $parts[1], $q );
	foreach ( (array) $k as $key ) {
		unset( $q[ $key ] );
	}
	return $parts[0] . ( $q ? '?' . http_build_query( $q ) : '' );
}
function is_wp_error( $x ) {
	return $x instanceof WP_Error;
}
class WP_Error {
	public function get_error_message() {
		return 'offline';
	}
}
function wp_remote_get( $url, $args = array() ) {
	if ( getenv( 'FWP_TEST_ONLINE' ) && function_exists( 'curl_init' ) ) {
		$ch = curl_init( $url );
		curl_setopt_array( $ch, array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERAGENT => isset( $args['user-agent'] ) ? $args['user-agent'] : 'test' ) );
		$body = curl_exec( $ch );
		$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		return array( 'body' => (string) $body, 'response' => array( 'code' => $code ) );
	}
	return new WP_Error();
}
function wp_remote_retrieve_body( $r ) {
	return is_array( $r ) ? $r['body'] : '';
}
function wp_remote_retrieve_response_code( $r ) {
	return is_array( $r ) ? $r['response']['code'] : 0;
}
function sanitize_text_field( $s ) {
	return trim( strip_tags( (string) $s ) );
}
function sanitize_textarea_field( $s ) {
	return trim( (string) $s );
}
function wp_unslash( $s ) {
	return is_string( $s ) ? stripslashes( $s ) : $s;
}
function get_bloginfo() {
	return '6.8';
}

require FAST_WP_DIR . 'includes/class-request.php';
require FAST_WP_DIR . 'includes/class-i18n.php';
require FAST_WP_DIR . 'includes/class-settings.php';
require FAST_WP_DIR . 'includes/class-html.php';
require FAST_WP_DIR . 'includes/class-minify.php';
require FAST_WP_DIR . 'includes/class-css-parser.php';
require FAST_WP_DIR . 'includes/class-used-css.php';
require FAST_WP_DIR . 'includes/class-assets.php';
require FAST_WP_DIR . 'includes/class-fonts.php';
require FAST_WP_DIR . 'includes/class-css.php';
require FAST_WP_DIR . 'includes/class-media.php';
require FAST_WP_DIR . 'includes/class-js.php';
require FAST_WP_DIR . 'includes/class-optimizer.php';

/** Sets options for a test (merged with defaults, test mode off). */
function fwp_set( array $values ) {
	$GLOBALS['fwp_options']['fast_wp_settings'] = array_merge( FastWP\Settings::defaults(), array( 'test_mode' => 0 ), $values );
	FastWP\Settings::reset_cache();
}

$GLOBALS['fwp_fail'] = 0;
$GLOBALS['fwp_pass'] = 0;
function check( $cond, $label ) {
	if ( $cond ) {
		$GLOBALS['fwp_pass']++;
		echo "  ok   $label\n";
	} else {
		$GLOBALS['fwp_fail']++;
		echo "  FAILED  $label\n";
	}
}
