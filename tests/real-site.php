<?php
/**
 * Test with a real page (does not run on GitHub).
 *
 * Usage:
 *   FWP_TEST_ROOT=tests/fixtures/site FWP_TEST_HOME=https://www.yoursite.com php tests/real-site.php tests/fixtures/home.html
 *
 * The FWP_TEST_ROOT folder must hold the site's CSS files at the same paths (wp-content/...).
 * Writes tests/output/real-original.html, tests/output/real-optimized.html and a summary.
 */

require __DIR__ . '/bootstrap.php';

$file = isset( $argv[1] ) ? $argv[1] : '';
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "Pass the HTML file.\n" );
	exit( 1 );
}
$html = file_get_contents( $file );
fwp_set( array() );

$t0  = microtime( true );
$out = ( new FastWP\Optimizer() )->process( $html );
$t1  = microtime( true );
// Second run: with the processed files already cached.
$out = ( new FastWP\Optimizer() )->process( $html );
$t2  = microtime( true );

@mkdir( __DIR__ . '/output', 0777, true );
file_put_contents( __DIR__ . '/output/real-original.html', $html );
file_put_contents( __DIR__ . '/output/real-optimized.html', $out );

$err = get_option( FastWP\Optimizer::ERROR_OPTION );
if ( $err ) {
	echo 'LOGGED ERROR: ' . $err['message'] . "\n";
}

// Size of render-blocking CSS, before and after.
$blocking_before = 0;
if ( preg_match_all( "#<link[^>]*rel=['\"]stylesheet['\"][^>]*>#i", $html, $m ) ) {
	foreach ( $m[0] as $tag ) {
		$a    = FastWP\Html::attrs( $tag );
		$path = FastWP\Assets::url_to_path( preg_replace( '/[?#].*$/', '', FastWP\Html::decoded( $a, 'href' ) ) );
		if ( $path ) {
			$blocking_before += filesize( $path );
		}
	}
}
$used = 0;
if ( preg_match_all( '#<style[^>]*data-fwp-used[^>]*>(.*?)</style>#s', $out, $m ) ) {
	foreach ( $m[1] as $css ) {
		$used += strlen( $css );
	}
}
$inline_before = 0;
if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $html, $m ) ) {
	foreach ( $m[1] as $css ) {
		$inline_before += strlen( $css );
	}
}
$inline_after = 0;
if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $out, $m ) ) {
	foreach ( $m[1] as $css ) {
		$inline_after += strlen( $css );
	}
}

$kb = function ( $b ) {
	return number_format( $b / 1024, 1, ',', '.' ) . ' KB';
};
echo "\n== Summary ==\n";
printf( "First optimization: %d ms | with cached files: %d ms\n", ( $t1 - $t0 ) * 1000, ( $t2 - $t1 ) * 1000 );
printf( "HTML: %s -> %s\n", $kb( strlen( $html ) ), $kb( strlen( $out ) ) );
printf( "Render-blocking CSS files (before): %s\n", $kb( $blocking_before ) );
printf( "Inline CSS before: %s | after (includes used CSS): %s | used CSS: %s\n", $kb( $inline_before ), $kb( $inline_after ), $kb( $used ) );
printf( "Render-blocking stylesheets after: %d\n", preg_match_all( "#<link(?![^>]*data-fwp-href)[^>]*rel=['\"]?stylesheet#i", $out ) );
printf( "Delayed scripts: %d | deferred: %d | normal: %d\n", substr_count( $out, 'type="fwp/delay"' ), preg_match_all( '#<script[^>]*\sdefer[\s>]#', $out ), preg_match_all( '#<script(?![^>]*(?:fwp/delay|defer|ld\+json|application/json|data-fwp-skip))[^>]*>#', $out ) );
printf( "Lazy images: %d | high priority: %d | preloads: %d\n", preg_match_all( '#<img[^>]*loading="lazy"#', $out ), preg_match_all( '#<img[^>]*fetchpriority="high"#', $out ), preg_match_all( '#<link rel="preload"#', $out ) );

// Validates the resulting HTML with DOMDocument (error count compared with the original).
libxml_use_internal_errors( true );
$d = new DOMDocument();
$d->loadHTML( $html );
$e1 = count( libxml_get_errors() );
libxml_clear_errors();
$d->loadHTML( $out );
$e2 = count( libxml_get_errors() );
libxml_clear_errors();
printf( "HTML warnings (libxml): original %d | optimized %d\n", $e1, $e2 );
