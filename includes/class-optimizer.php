<?php
/**
 * Junta todas as otimizações do HTML. Qualquer erro devolve a página original (falha segura).
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Optimizer {

	const ERROR_OPTION = 'fast_wp_last_error';

	public function process( $html ) {
		if ( ! Html::is_page( $html ) ) {
			return $html;
		}
		$original = $html;
		try {
			$out = $this->run( $html );
		} catch ( \Throwable $e ) {
			self::log_error( $e->getMessage() . ' em ' . basename( $e->getFile() ) . ':' . $e->getLine() );
			return $original;
		}

		// Conferência final: se a página encolheu demais ou perdeu o fechamento, usa a original.
		if ( false === stripos( $out, '</html>' ) || strlen( $out ) < strlen( $original ) * 0.3 ) {
			self::log_error( 'Resultado inválido (página incompleta). A versão original foi entregue.' );
			return $original;
		}
		return $out;
	}

	private function run( $html ) {
		$full = $html;

		// Fontes do Google.
		$fonts = new Fonts();
		$html  = $fonts->process( $html );

		// CSS e mídia trabalham com scripts e comentários protegidos.
		$guard = new Html();
		$html  = $guard->protect( $html, array( 'script', 'comment', 'noscript', 'textarea', 'template' ) );

		$css  = new Css();
		$html = $css->process( $html, $full );

		$style_guard = new Html();
		$html        = $style_guard->protect( $html, array( 'style' ) );
		$media       = new Media();
		$html        = $media->process( $html );
		$html        = $style_guard->restore( $html );

		$html = $guard->restore( $html );

		// JavaScript.
		$js   = new Js();
		$html = $js->process( $html );

		// Itens no <head>: pré-carregamentos e CSS do próprio plugin.
		$animations = $js->has_delayed && Settings::get( 'elementor_animations' );
		$head       = implode( '', $media->preloads ) . implode( '', $fonts->preloads ) . Js::inline_css( $media->uses_youtube, $animations );
		$html       = Html::after_head_open( $html, $head );

		$fallback = Settings::get( 'used_css_fallback' );
		$cfg      = array(
			'delay'   => $js->has_delayed,
			'css'     => $css->has_deferred ? $fallback : '',
			'timeout' => (int) Settings::get( 'delay_js_timeout' ),
		);
		if ( $cfg['delay'] || $cfg['css'] || $media->uses_youtube || $media->uses_lazy_bg ) {
			$html = Html::before_body_close( $html, Js::loader( $cfg ) );
		}

		$html = $this->cdn( $html );

		if ( Settings::get( 'minify_html' ) ) {
			$html = Minify::html( $html );
		}
		return $html . "\n<!-- fwp: otimizado pelo Fast WP " . FAST_WP_VERSION . ' -->';
	}

	/** Troca o endereço de arquivos estáticos pelo da CDN. */
	private function cdn( $html ) {
		$cdn = (string) Settings::get( 'cdn_url' );
		if ( ! Settings::get( 'cdn_enabled' ) || '' === $cdn ) {
			return $html;
		}
		$site = untrailingslashit( site_url() );
		$from = array();
		$to   = array();
		foreach ( array( '/wp-content/', '/wp-includes/' ) as $dir ) {
			foreach ( array( $site, str_replace( array( 'https:', 'http:' ), '', $site ) ) as $base ) {
				$from[] = $base . $dir;
				$to[]   = $cdn . $dir;
				$from[] = str_replace( '/', '\/', $base . $dir );
				$to[]   = str_replace( '/', '\/', $cdn . $dir );
			}
		}
		return str_replace( $from, $to, $html );
	}

	public static function log_error( $message ) {
		update_option(
			self::ERROR_OPTION,
			array(
				'message' => (string) $message,
				'time'    => time(),
				'url'     => isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			),
			false
		);
	}
}
