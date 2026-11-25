<?php
/**
 * Fast WP automated tests (run without WordPress): php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

use FastWP\Request;
use FastWP\Minify;
use FastWP\Css_Parser;
use FastWP\Used_Css;
use FastWP\Optimizer;
use FastWP\Settings;

// Clean folder for the test files.
$root = rtrim( ABSPATH, '/' );
if ( is_dir( $root ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
}
@mkdir( WP_CONTENT_DIR . '/themes/t/css', 0777, true );
fwp_set( array() );

echo "\n== Cache: per-request decision ==\n";
$cfg = array(
	'dir'           => '/c',
	'hosts'         => array( 'example.com', 'www.example.com' ),
	'ignore_params' => Settings::lines( 'cache_ignore_params' ),
	'exclude_urls'  => array( '/thank-you/' ),
);
$srv = array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.com', 'HTTPS' => 'on' );
$t   = Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/services/' ), array() );
check( $t && '/c/pages/example.com/services/index-https.html' === $t['file'], 'regular page is cached' );
$t = Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/' ), array() );
check( $t && '/c/pages/example.com/index-https.html' === $t['file'], 'home page' );
check( null !== Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/lp/?utm_source=meta&utm_campaign=x&fbclid=abc' ), array() ), 'ad traffic (utm, fbclid) uses the cache' );
check( null !== Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/lp/?gclid=123&gbraid=9' ), array() ), 'gclid/gbraid use the cache' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/?s=botox' ), array() ), 'search is not cached' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/thank-you/' ), array() ), 'excluded URL' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/wp-admin/' ), array() ), 'wp-admin' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/' ), array( 'wordpress_logged_in_abc' => 'x' ) ), 'logged-in user' );
check( null === Request::cache_target( $cfg, array( 'HTTP_HOST' => 'attacker.com', 'REQUEST_URI' => '/' ) + $srv, array() ), 'forged Host header' );
check( null === Request::cache_target( $cfg, array( 'REQUEST_METHOD' => 'POST' ) + $srv + array( 'REQUEST_URI' => '/' ), array() ), 'POST' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/../etc/' ), array() ), 'path with ..' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/?fwp_test=1' ), array() ), 'test mode (?fwp_test) is not cached' );
$t = Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/page' ), array() );
check( $t && '/c/pages/example.com/page/index-https-noslash.html' === $t['file'], 'URL without trailing slash has its own file' );

echo "\n== CSS minification ==\n";
$css = "/* comment */\n.a  >  .b { color : red ;  content: \"a  /* not */  b\"; }\n@media (max-width: 767px) { .c { width: calc(100% - 10px) !important; } }";
$min = Minify::css( $css );
check( false === strpos( $min, 'comment' ), 'removes comments' );
check( false !== strpos( $min, '"a  /* not */  b"' ), 'keeps strings' );
check( false !== strpos( $min, 'calc(100% - 10px)' ), 'keeps spaces inside calc()' );
check( false !== strpos( $min, '.a>.b{color:red;' ), 'joins selectors and properties' );
check( false !== strpos( $min, '@media (max-width:767px){' ), 'media query' );

echo "\n== CSS parser ==\n";
$nodes = Css_Parser::parse( Css_Parser::strip_comments( "@charset 'utf-8';.x{a:b}@media screen{.y{c:d}.z{e:f}}@font-face{font-family:Foo;src:url(a.woff2)}@keyframes spin{from{x:0}to{x:1}}.q{content:'}'}" ) );
check( 'stmt' === $nodes[0][0] && 'rule' === $nodes[1][0] && 'group' === $nodes[2][0] && 2 === count( $nodes[2][2] ), 'rules, @media and children' );
check( 'fontface' === $nodes[3][0] && 'keyframes' === $nodes[4][0], '@font-face and @keyframes' );
check( "content:'}'" === $nodes[5][2], 'brace inside a string' );
check( array( '.a', '.b:is(.c,.d)', 'a[x="1,2"]' ) === Css_Parser::split_selectors( '.a, .b:is(.c,.d), a[x="1,2"]' ), 'splits selectors' );

echo "\n== Unused CSS removal ==\n";
$html = '<html><body class="home e--ua-blink"><div class="hero elementor-element" data-settings="{&quot;_animation&quot;:&quot;fadeInUp&quot;}"><h1 id="title">Hi</h1><a class="btn">x</a></div><script>el.classList.add("menu-open-x")</script></body></html>';
$u    = new Used_Css( $html, array( 'my-*' ) );
check( $u->selector_used( '.hero .btn' ), 'used class' );
check( ! $u->selector_used( '.footer-widget' ), 'unused class' );
check( $u->selector_used( '#title' ), 'used id' );
check( ! $u->selector_used( '#footer' ), 'unused id' );
check( $u->selector_used( '.hero:not(.missing)' ), ':not() does not require the class' );
check( $u->selector_used( '.menu-open-x' ), 'class mentioned in a script' );
check( $u->selector_used( '.elementor-sticky--active' ), 'Elementor JS class (safelist)' );
check( $u->selector_used( '.my-popup' ), 'user safelist' );
check( $u->selector_used( '.md\\:flex' ), 'escaped class is kept' );
check( $u->selector_used( 'body' ) && $u->selector_used( '*' ) && $u->selector_used( ':root' ), 'tags and :root' );
$nodes = Css_Parser::parse( '.hero{a:1}.none{b:2}@media print{.hero{c:3}}@media (max-width:1px){.none{d:4}}@keyframes fadeInUp{to{x:1}}@keyframes zoom{to{x:1}}@font-face{font-family:"Used";src:url(a)}@font-face{font-family:Leftover;src:url(b)}.btn{font-family:Used}' );
$kept  = $u->filter_rules( $nodes );
$ref   = Used_Css::reference_text( $kept ) . 'fadeInUp';
$final = Css_Parser::render( Used_Css::filter_fonts_and_keyframes( $kept, $ref ) );
check( false !== strpos( $final, '.hero{a:1}' ) && false === strpos( $final, '.none' ), 'keeps used, removes unused' );
check( false === strpos( $final, '@media print' ) && false === strpos( $final, 'max-width:1px' ), 'removes print and empty @media' );
check( false !== strpos( $final, '@keyframes fadeInUp' ) && false === strpos( $final, 'zoom' ), 'keeps the used animation (data-settings)' );
check( false !== strpos( $final, 'Used' ) && false === strpos( $final, 'Leftover' ), 'keeps only used fonts' );
check( ! Used_Css::font_face_needed( 'font-family:X;unicode-range:U+0400-045F', null, true ), 'drops Cyrillic font faces' );
check( Used_Css::font_face_needed( 'font-family:X;unicode-range:U+0000-00FF', null, true ), 'keeps Latin font faces' );
check( ! Used_Css::font_face_needed( 'font-family:X;font-weight:100', array( 400, 700 ), true ), 'drops unused weights' );

echo "\n== JS minification ==\n";
$js = "// line\nvar a = 'x // y'; /* block */\nvar r = /ab+c\\/d/gi.test(a);\nvar t = `hi \${a} // nothing`;\nfunction f(){ return /x/.test(a) }\nvar d = 10 / 2 / 1;\n/*! license */\n";
$mj = Minify::js( $js );
check( false === strpos( $mj, '// line' ) && false === strpos( $mj, 'block' ), 'removes comments' );
check( false !== strpos( $mj, "'x // y'" ) && false !== strpos( $mj, '/ab+c\\/d/gi' ) && false !== strpos( $mj, '`hi ${a} // nothing`' ), 'keeps strings, regex and templates' );
check( false !== strpos( $mj, '10 / 2 / 1' ) && false !== strpos( $mj, '/*! license */' ), 'division and license' );
$tmp = sys_get_temp_dir() . '/fwp-js-test.js';
file_put_contents( $tmp, $mj );
exec( 'node --check ' . escapeshellarg( $tmp ) . ' 2>&1', $o, $code );
check( 0 === $code || 127 === $code, 'minified JS is still valid (node --check)' );

echo "\n== Full page optimization ==\n";
file_put_contents( WP_CONTENT_DIR . '/themes/t/css/style.css', ".hero{background:url(../img/bg.jpg)}.not-used{color:red}.btn{font-family:Inter}@font-face{font-family:Inter;src:url(../f/inter.woff2)}\n" . str_repeat( ".leftover-x{color:blue}\n", 50 ) );
$page = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>T</title>'
	. "<link rel='stylesheet' id='theme-css' href='" . FWP_TEST_HOME . "/wp-content/themes/t/css/style.css?ver=1' media='all' />"
	. '<script src="' . FWP_TEST_HOME . '/wp-includes/js/jquery/jquery.min.js"></script>'
	. '<script type="application/ld+json">{"@type":"Thing"}</script>'
	. '</head><body><header><img src="' . FWP_TEST_HOME . '/logo.png" width="120" height="40" alt="logo"></header>'
	. '<main><div class="hero"><img src="' . FWP_TEST_HOME . '/banner.jpg" width="1200" height="600" loading="lazy" alt="b"><a class="btn">Book now</a></div>'
	. '<img src="' . FWP_TEST_HOME . '/a.jpg" alt="a"><img src="' . FWP_TEST_HOME . '/b.jpg" alt="b"><img src="' . FWP_TEST_HOME . '/c.jpg" alt="c">'
	. '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315" title="Video"></iframe>'
	. '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>'
	. '<!-- a comment --><pre>  keep   spaces  </pre></main>'
	. '<script>jQuery(function($){ $(".btn").show(); });</script>'
	. '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'
	. '<script>!function(f){f.fbq=1}(window);fbq("init","1");</script>'
	. '</body></html>';
$out = ( new Optimizer() )->process( $page );
@mkdir( __DIR__ . '/output', 0777, true );
file_put_contents( __DIR__ . '/output/synthetic.html', $out );
check( false !== strpos( $out, 'fwp: optimized' ), 'page optimized' );
check( false !== strpos( $out, 'data-fwp-used' ) && false !== strpos( $out, '.hero{' ) && false === strpos( $out, 'leftover-x' ), 'used CSS inline, unused CSS left out' );
check( (bool) preg_match( '#<link[^>]*data-fwp-href=#', $out ), 'full CSS loads later (safety net)' );
check( false !== strpos( $out, FWP_TEST_HOME . '/wp-content/themes/t/img/bg.jpg' ), 'relative CSS URL became absolute' );
check( false !== strpos( $out, 'font-display:swap' ), 'font-display: swap' );
check( (bool) preg_match( '#<script(?=[^>]*type="fwp/delay")[^>]*data-fwp-src="[^"]*jquery\.min\.js#', $out ), 'jQuery delayed until interaction' );
check( (bool) preg_match( '#<script type="fwp/delay">jQuery#', $out ), 'inline jQuery script delayed too' );
check( false !== strpos( $out, '<script src="https://connect.facebook.net/en_US/fbevents.js"' ) && ! preg_match( '#type="fwp/delay"[^>]*>[^<]*fbq#', $out ), 'Meta pixel is NOT delayed (default)' );
check( 1 === substr_count( $out, 'id="theme-css-used"' ), 'used CSS appears only once' );
check( false !== strpos( $out, 'application/ld+json' ), 'structured data untouched' );
check( (bool) preg_match( '#<img[^>]*banner\.jpg[^>]*fetchpriority="high"#', $out ) && ! preg_match( '#<img[^>]*banner\.jpg[^>]*loading=#', $out ), 'banner: high priority, no lazy load' );
check( false !== strpos( $out, '<link rel="preload" as="image" href="' . FWP_TEST_HOME . '/banner.jpg"' ), 'banner preloaded' );
check( ! preg_match( '#<img[^>]*logo\.png[^>]*loading="lazy"#', $out ), 'logo not lazy loaded' );
check( (bool) preg_match( '#<img[^>]*c\.jpg[^>]*loading="lazy"#', $out ), 'lower images lazy loaded' );
check( false !== strpos( $out, 'data-fwp-yt=' ) && false === strpos( $out, '<iframe src="https://www.youtube.com' ), 'YouTube becomes a thumbnail' );
check( (bool) preg_match( '#<iframe[^>]*maps[^>]*loading="lazy"#', $out ), 'map lazy loaded' );
check( false === strpos( $out, 'a comment' ) && false !== strpos( $out, '<pre>  keep   spaces  </pre>' ), 'HTML minified without touching <pre>' );
check( false !== strpos( $out, 'id="fwp-loader"' ), 'loader included' );
check( false !== strpos( $out, '.elementor-invisible{visibility:visible!important}' ), 'Elementor animations visible' );

// Without JS delay: defer + inline scripts converted in the same order.
fwp_set( array( 'delay_js' => 0 ) );
$out2 = ( new Optimizer() )->process( $page );
check( (bool) preg_match( '#<script src="[^"]*jquery\.min\.js" defer>#', $out2 ), 'defer on jQuery' );
check( (bool) preg_match( '#<script src="data:text/javascript;base64,[^"]+" defer></script>#', $out2 ), 'inline script deferred in the right order' );

// Fail-safe: content that is not a page is returned untouched.
fwp_set( array() );
check( 'not html' === ( new Optimizer() )->process( 'not html' ), 'non-page content untouched' );

// Loader: valid JavaScript.
$loader = FastWP\Js::loader( array( 'delay' => true, 'css' => 'interaction', 'timeout' => 0 ) );
file_put_contents( $tmp, preg_replace( '#^<script[^>]*>|</script>$#', '', $loader ) );
$o = array();
exec( 'node --check ' . escapeshellarg( $tmp ) . ' 2>&1', $o, $code );
check( 0 === $code || 127 === $code, 'loader is valid JavaScript' . ( $code && 127 !== $code ? ': ' . implode( ' ', $o ) : '' ) );

echo "\n== wp-config.php (WP_CACHE) ==\n";
require_once FAST_WP_DIR . 'includes/class-cache.php';
$wpconfig = ABSPATH . 'wp-config.php';
file_put_contents( $wpconfig, "<?php\n// define( 'WP_CACHE', true );\ndefine( 'DB_NAME', 'x' );\nrequire_once ABSPATH . 'wp-settings.php';\ndefine( 'WP_CACHE', true );\n" );
check( FastWP\Cache::set_wp_cache( true ), 'WP_CACHE written' );
$cfgtxt = file_get_contents( $wpconfig );
check( 0 === strpos( $cfgtxt, "<?php\ndefine( 'WP_CACHE', true ); // Fast WP\n" ), 'WP_CACHE moved to the top of wp-config.php' );
check( 1 === preg_match_all( "/^define\( 'WP_CACHE'/m", $cfgtxt ), 'misplaced definition after wp-settings.php removed' );
check( false !== strpos( $cfgtxt, "// define( 'WP_CACHE', true );" ), 'commented lines left alone' );
check( FastWP\Cache::set_wp_cache( false ) && false === strpos( file_get_contents( $wpconfig ), '// Fast WP' ), 'deactivation removes only our line' );
file_put_contents( $wpconfig, "<?php\n// nothing here\n" );
check( ! FastWP\Cache::set_wp_cache( true ) && 'unexpected' === get_option( FastWP\Cache::WPCONFIG_STATUS ), 'unusual wp-config.php is not touched (reason stored)' );
unlink( $wpconfig );

echo "\n== Translations ==\n";
fwp_set( array( 'language' => 'en_US' ) );
check( 'Play video' === FastWP\t( 'Play video' ), 'English is the source language' );
fwp_set( array( 'language' => 'pt_BR' ) );
check( 'Reproduzir vídeo' === FastWP\t( 'Play video' ), 'Portuguese translation' );
check( 'versão 1.2' === FastWP\t( 'version %s', '1.2' ), 'translation with placeholders' );
check( 'Unknown text' === FastWP\t( 'Unknown text' ), 'missing translation falls back to English' );
$out3 = ( new Optimizer() )->process( $page );
check( false !== strpos( $out3, 'aria-label="Reproduzir vídeo"' ), 'front-end label follows the language' );
fwp_set( array() );

echo "\nResult: {$GLOBALS['fwp_pass']} passed, {$GLOBALS['fwp_fail']} failed\n";
exit( $GLOBALS['fwp_fail'] ? 1 : 0 );
