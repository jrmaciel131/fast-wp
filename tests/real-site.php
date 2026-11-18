<?php
/**
 * Teste com uma página real (não roda no GitHub).
 *
 * Uso:
 *   FWP_TEST_ROOT=tests/fixtures/site FWP_TEST_HOME=https://www.seusite.com.br php tests/real-site.php tests/fixtures/home.html
 *
 * A pasta FWP_TEST_ROOT deve ter os arquivos CSS do site nos mesmos caminhos (wp-content/...).
 * Gera tests/output/real-original.html e tests/output/real-optimized.html e um resumo.
 */

require __DIR__ . '/bootstrap.php';

$file = isset( $argv[1] ) ? $argv[1] : '';
if ( ! is_file( $file ) ) {
	fwrite( STDERR, "Informe o arquivo HTML.\n" );
	exit( 1 );
}
$html = file_get_contents( $file );
fwp_set( array() );

$t0  = microtime( true );
$out = ( new FastWP\Optimizer() )->process( $html );
$t1  = microtime( true );
// Segunda vez: com os arquivos já processados guardados.
$out = ( new FastWP\Optimizer() )->process( $html );
$t2  = microtime( true );

@mkdir( __DIR__ . '/output', 0777, true );
file_put_contents( __DIR__ . '/output/real-original.html', $html );
file_put_contents( __DIR__ . '/output/real-optimized.html', $out );

$err = get_option( FastWP\Optimizer::ERROR_OPTION );
if ( $err ) {
	echo 'ERRO REGISTRADO: ' . $err['message'] . "\n";
}

// Tamanho do CSS que bloqueia a renderização, antes e depois.
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
echo "\n== Resumo ==\n";
printf( "Tempo da 1a otimização: %d ms | com arquivos guardados: %d ms\n", ( $t1 - $t0 ) * 1000, ( $t2 - $t1 ) * 1000 );
printf( "HTML: %s -> %s\n", $kb( strlen( $html ) ), $kb( strlen( $out ) ) );
printf( "CSS em arquivos que bloqueiam a página (antes): %s\n", $kb( $blocking_before ) );
printf( "CSS inline antes: %s | depois (inclui CSS usado): %s | CSS usado: %s\n", $kb( $inline_before ), $kb( $inline_after ), $kb( $used ) );
printf( "Folhas de estilo bloqueando depois: %d\n", preg_match_all( "#<link(?![^>]*data-fwp-href)[^>]*rel=['\"]?stylesheet#i", $out ) );
printf( "Scripts atrasados: %d | com defer: %d | normais: %d\n", substr_count( $out, 'type="fwp/delay"' ), preg_match_all( '#<script[^>]*\sdefer[\s>]#', $out ), preg_match_all( '#<script(?![^>]*(?:fwp/delay|defer|ld\+json|application/json|data-fwp-skip))[^>]*>#', $out ) );
printf( "Imagens com lazy: %d | prioridade alta: %d | preload: %d\n", preg_match_all( '#<img[^>]*loading="lazy"#', $out ), preg_match_all( '#<img[^>]*fetchpriority="high"#', $out ), preg_match_all( '#<link rel="preload"#', $out ) );

// Valida o HTML resultante com o DOMDocument (contagem de erros, comparada com o original).
libxml_use_internal_errors( true );
$d = new DOMDocument();
$d->loadHTML( $html );
$e1 = count( libxml_get_errors() );
libxml_clear_errors();
$d->loadHTML( $out );
$e2 = count( libxml_get_errors() );
libxml_clear_errors();
printf( "Avisos de HTML (libxml): original %d | otimizado %d\n", $e1, $e2 );
