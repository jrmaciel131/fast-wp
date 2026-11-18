<?php
/**
 * Testes automáticos do Fast WP (rodam sem WordPress): php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

use FastWP\Request;
use FastWP\Minify;
use FastWP\Css_Parser;
use FastWP\Used_Css;
use FastWP\Optimizer;
use FastWP\Settings;

// Pasta limpa para os arquivos do teste.
$root = rtrim( ABSPATH, '/' );
if ( is_dir( $root ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() );
	}
}
@mkdir( WP_CONTENT_DIR . '/themes/t/css', 0777, true );
fwp_set( array() );

echo "\n== Cache: decisão por requisição ==\n";
$cfg = array(
	'dir'           => '/c',
	'hosts'         => array( 'exemplo.com.br', 'www.exemplo.com.br' ),
	'ignore_params' => Settings::lines( 'cache_ignore_params' ),
	'exclude_urls'  => array( '/obrigado/' ),
);
$srv = array( 'REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'exemplo.com.br', 'HTTPS' => 'on' );
$t   = Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/servicos/' ), array() );
check( $t && '/c/pages/exemplo.com.br/servicos/index-https.html' === $t['file'], 'página comum vai para o cache' );
$t = Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/' ), array() );
check( $t && '/c/pages/exemplo.com.br/index-https.html' === $t['file'], 'página inicial' );
check( null !== Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/lp/?utm_source=meta&utm_campaign=x&fbclid=abc' ), array() ), 'tráfego de anúncio (utm, fbclid) usa o cache' );
check( null !== Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/lp/?gclid=123&gbraid=9' ), array() ), 'gclid/gbraid usam o cache' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/?s=botox' ), array() ), 'busca não usa o cache' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/obrigado/' ), array() ), 'URL excluída' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/wp-admin/' ), array() ), 'wp-admin' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/' ), array( 'wordpress_logged_in_abc' => 'x' ) ), 'usuário logado' );
check( null === Request::cache_target( $cfg, array( 'HTTP_HOST' => 'hacker.com', 'REQUEST_URI' => '/' ) + $srv, array() ), 'Host forjado' );
check( null === Request::cache_target( $cfg, array( 'REQUEST_METHOD' => 'POST' ) + $srv + array( 'REQUEST_URI' => '/' ), array() ), 'POST' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/../etc/' ), array() ), 'caminho com ..' );
check( null === Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/?fwp_test=1' ), array() ), 'modo de teste (?fwp_test) não usa o cache' );
$t = Request::cache_target( $cfg, $srv + array( 'REQUEST_URI' => '/pagina' ), array() );
check( $t && '/c/pages/exemplo.com.br/pagina/index-https-noslash.html' === $t['file'], 'URL sem barra no fim tem arquivo próprio' );

echo "\n== Minificação de CSS ==\n";
$css = "/* comentário */\n.a  >  .b { color : red ;  content: \"a  /* não */  b\"; }\n@media (max-width: 767px) { .c { width: calc(100% - 10px) !important; } }";
$min = Minify::css( $css );
check( false === strpos( $min, 'comentário' ), 'remove comentários' );
check( false !== strpos( $min, '"a  /* não */  b"' ), 'mantém strings' );
check( false !== strpos( $min, 'calc(100% - 10px)' ), 'mantém espaços do calc()' );
check( false !== strpos( $min, '.a>.b{color:red;' ), 'junta seletores e propriedades' );
check( false !== strpos( $min, '@media (max-width:767px){' ), 'media query' );

echo "\n== Leitor de CSS ==\n";
$nodes = Css_Parser::parse( Css_Parser::strip_comments( "@charset 'utf-8';.x{a:b}@media screen{.y{c:d}.z{e:f}}@font-face{font-family:Foo;src:url(a.woff2)}@keyframes spin{from{x:0}to{x:1}}.q{content:'}'}" ) );
check( 'stmt' === $nodes[0][0] && 'rule' === $nodes[1][0] && 'group' === $nodes[2][0] && 2 === count( $nodes[2][2] ), 'regras, @media e filhos' );
check( 'fontface' === $nodes[3][0] && 'keyframes' === $nodes[4][0], '@font-face e @keyframes' );
check( "content:'}'" === $nodes[5][2], 'chave dentro de string' );
check( array( '.a', '.b:is(.c,.d)', 'a[x="1,2"]' ) === Css_Parser::split_selectors( '.a, .b:is(.c,.d), a[x="1,2"]' ), 'divide seletores' );

echo "\n== Remover CSS não usado ==\n";
$html = '<html><body class="home e--ua-blink"><div class="hero elementor-element" data-settings="{&quot;_animation&quot;:&quot;fadeInUp&quot;}"><h1 id="titulo">Oi</h1><a class="btn">x</a></div><script>el.classList.add("menu-aberto")</script></body></html>';
$u    = new Used_Css( $html, array( 'meu-*' ) );
check( $u->selector_used( '.hero .btn' ), 'classe usada' );
check( ! $u->selector_used( '.footer-widget' ), 'classe não usada' );
check( $u->selector_used( '#titulo' ), 'id usado' );
check( ! $u->selector_used( '#rodape' ), 'id não usado' );
check( $u->selector_used( '.hero:not(.inexistente)' ), ':not() não exige a classe' );
check( $u->selector_used( '.menu-aberto' ), 'classe citada em script' );
check( $u->selector_used( '.elementor-sticky--active' ), 'classe de JS do Elementor (lista segura)' );
check( $u->selector_used( '.meu-popup' ), 'lista segura do usuário' );
check( $u->selector_used( '.md\\:flex' ), 'classe com escape é mantida' );
check( $u->selector_used( 'body' ) && $u->selector_used( '*' ) && $u->selector_used( ':root' ), 'tags e :root' );
$nodes = Css_Parser::parse( '.hero{a:1}.nada{b:2}@media print{.hero{c:3}}@media (max-width:1px){.nada{d:4}}@keyframes fadeInUp{to{x:1}}@keyframes zoom{to{x:1}}@font-face{font-family:"Usada";src:url(a)}@font-face{font-family:Sobra;src:url(b)}.btn{font-family:Usada}' );
$kept  = $u->filter_rules( $nodes );
$ref   = Used_Css::reference_text( $kept ) . 'fadeInUp';
$final = Css_Parser::render( Used_Css::filter_fonts_and_keyframes( $kept, $ref ) );
check( false !== strpos( $final, '.hero{a:1}' ) && false === strpos( $final, '.nada' ), 'mantém usada, remove não usada' );
check( false === strpos( $final, '@media print' ) && false === strpos( $final, 'max-width:1px' ), 'remove @media de impressão e vazio' );
check( false !== strpos( $final, '@keyframes fadeInUp' ) && false === strpos( $final, 'zoom' ), 'mantém animação usada (data-settings)' );
check( false !== strpos( $final, 'Usada' ) && false === strpos( $final, 'Sobra' ), 'mantém só as fontes usadas' );

echo "\n== Minificação de JS ==\n";
$js = "// linha\nvar a = 'x // y'; /* bloco */\nvar r = /ab+c\\/d/gi.test(a);\nvar t = `oi \${a} // nada`;\nfunction f(){ return /x/.test(a) }\nvar d = 10 / 2 / 1;\n/*! licença */\n";
$mj = Minify::js( $js );
check( false === strpos( $mj, '// linha' ) && false === strpos( $mj, 'bloco' ), 'remove comentários' );
check( false !== strpos( $mj, "'x // y'" ) && false !== strpos( $mj, '/ab+c\\/d/gi' ) && false !== strpos( $mj, '`oi ${a} // nada`' ), 'mantém strings, regex e template' );
check( false !== strpos( $mj, '10 / 2 / 1' ) && false !== strpos( $mj, '/*! licença */' ), 'divisão e licença' );
$tmp = sys_get_temp_dir() . '/fwp-js-test.js';
file_put_contents( $tmp, $mj );
exec( 'node --check ' . escapeshellarg( $tmp ) . ' 2>&1', $o, $code );
check( 0 === $code || 127 === $code, 'JS minificado continua válido (node --check)' );

echo "\n== Otimização completa de uma página ==\n";
file_put_contents( WP_CONTENT_DIR . '/themes/t/css/style.css', ".hero{background:url(../img/bg.jpg)}.nao-usada{color:red}.btn{font-family:Inter}@font-face{font-family:Inter;src:url(../f/inter.woff2)}\n" . str_repeat( ".sobra-x{color:blue}\n", 50 ) );
$page = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>T</title>'
	. "<link rel='stylesheet' id='tema-css' href='" . FWP_TEST_HOME . "/wp-content/themes/t/css/style.css?ver=1' media='all' />"
	. '<script src="' . FWP_TEST_HOME . '/wp-includes/js/jquery/jquery.min.js"></script>'
	. '<script type="application/ld+json">{"@type":"Thing"}</script>'
	. '</head><body><header><img src="' . FWP_TEST_HOME . '/logo.png" width="120" height="40" alt="logo"></header>'
	. '<main><div class="hero"><img src="' . FWP_TEST_HOME . '/banner.jpg" width="1200" height="600" loading="lazy" alt="b"><a class="btn">Agende</a></div>'
	. '<img src="' . FWP_TEST_HOME . '/a.jpg" alt="a"><img src="' . FWP_TEST_HOME . '/b.jpg" alt="b"><img src="' . FWP_TEST_HOME . '/c.jpg" alt="c">'
	. '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" width="560" height="315" title="Vídeo"></iframe>'
	. '<iframe src="https://www.google.com/maps/embed?pb=1"></iframe>'
	. '<!-- comentário --><pre>  manter   espaços  </pre></main>'
	. '<script>jQuery(function($){ $(".btn").show(); });</script>'
	. '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'
	. '<script>!function(f){f.fbq=1}(window);fbq("init","1");</script>'
	. '</body></html>';
$opt = new Optimizer();
$out = $opt->process( $page );
@mkdir( __DIR__ . '/output', 0777, true );
file_put_contents( __DIR__ . '/output/synthetic.html', $out );
check( false !== strpos( $out, 'fwp: otimizado' ), 'página otimizada' );
check( false !== strpos( $out, 'data-fwp-used' ) && false !== strpos( $out, '.hero{' ) && false === strpos( $out, 'sobra-x' ), 'CSS usado no HTML, CSS não usado fora' );
check( (bool) preg_match( '#<link[^>]*data-fwp-href=#', $out ), 'CSS completo fica para depois (garantia)' );
check( false !== strpos( $out, FWP_TEST_HOME . '/wp-content/themes/t/img/bg.jpg' ), 'URL relativa do CSS virou absoluta' );
check( false !== strpos( $out, 'font-display:swap' ), 'font-display: swap' );
check( (bool) preg_match( '#<script(?=[^>]*type="fwp/delay")[^>]*data-fwp-src="[^"]*jquery\.min\.js#', $out ), 'jQuery atrasado até a interação' );
check( (bool) preg_match( '#<script type="fwp/delay">jQuery#', $out ), 'script da página que usa jQuery também atrasado' );
check( false !== strpos( $out, '<script src="https://connect.facebook.net/en_US/fbevents.js"' ) && ! preg_match( '#type="fwp/delay"[^>]*>[^<]*fbq#', $out ), 'pixel da Meta NÃO é atrasado (padrão)' );
check( 1 === substr_count( $out, 'id="tema-css-used"' ), 'CSS usado aparece uma vez só' );
check( false !== strpos( $out, 'application/ld+json' ), 'dados estruturados intactos' );
check( (bool) preg_match( '#<img[^>]*banner\.jpg[^>]*fetchpriority="high"#', $out ) && ! preg_match( '#<img[^>]*banner\.jpg[^>]*loading=#', $out ), 'banner: prioridade alta e sem lazy load' );
check( false !== strpos( $out, '<link rel="preload" as="image" href="' . FWP_TEST_HOME . '/banner.jpg"' ), 'banner pré-carregado' );
check( ! preg_match( '#<img[^>]*logo\.png[^>]*loading="lazy"#', $out ), 'logo sem lazy load' );
check( (bool) preg_match( '#<img[^>]*c\.jpg[^>]*loading="lazy"#', $out ), 'imagens de baixo com lazy load' );
check( false !== strpos( $out, 'data-fwp-yt=' ) && false === strpos( $out, '<iframe src="https://www.youtube.com' ), 'YouTube vira miniatura' );
check( (bool) preg_match( '#<iframe[^>]*maps[^>]*loading="lazy"#', $out ), 'mapa com lazy load' );
check( false === strpos( $out, 'comentário' ) && false !== strpos( $out, '<pre>  manter   espaços  </pre>' ), 'HTML minificado sem mexer no <pre>' );
check( false !== strpos( $out, 'id="fwp-loader"' ), 'carregador incluído' );
check( false !== strpos( $out, '.elementor-invisible{visibility:visible!important}' ), 'animações do Elementor visíveis' );

// Sem atraso de JS: defer + scripts da página convertidos na mesma ordem.
fwp_set( array( 'delay_js' => 0 ) );
$out2 = ( new Optimizer() )->process( $page );
check( (bool) preg_match( '#<script src="[^"]*jquery\.min\.js" defer>#', $out2 ), 'defer no jQuery' );
check( (bool) preg_match( '#<script src="data:text/javascript;base64,[^"]+" defer></script>#', $out2 ), 'script da página adiado na ordem certa' );

// Modo seguro: erro interno devolve a página original.
fwp_set( array() );
check( 'não é html' === ( new Optimizer() )->process( 'não é html' ), 'conteúdo que não é página fica intacto' );

// Carregador: JavaScript válido.
$loader = FastWP\Js::loader( array( 'delay' => true, 'css' => 'interaction', 'timeout' => 0 ) );
file_put_contents( $tmp, preg_replace( '#^<script[^>]*>|</script>$#', '', $loader ) );
$o = array();
exec( 'node --check ' . escapeshellarg( $tmp ) . ' 2>&1', $o, $code );
check( 0 === $code || 127 === $code, 'carregador é JavaScript válido' . ( $code && 127 !== $code ? ': ' . implode( ' ', $o ) : '' ) );

echo "\nResultado: {$GLOBALS['fwp_pass']} ok, {$GLOBALS['fwp_fail']} falharam\n";
exit( $GLOBALS['fwp_fail'] ? 1 : 0 );
