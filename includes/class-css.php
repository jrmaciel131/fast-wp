<?php
/**
 * CSS: minificação dos arquivos do site e remoção do CSS não usado.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Css {

	/** Tamanho mínimo de um bloco <style> para ser limpo. */
	const INLINE_MIN = 8192;

	/** @var bool Se algum CSS original ficou para carregar depois. */
	public $has_deferred = false;

	/**
	 * @param string $html     HTML com scripts e comentários protegidos.
	 * @param string $full_html HTML original completo (para ler as palavras usadas).
	 */
	public function process( $html, $full_html ) {
		$minify = (bool) Settings::get( 'minify_css' );
		$used   = (bool) Settings::get( 'used_css' );
		if ( ! $minify && ! $used && ! Settings::get( 'fonts_swap' ) ) {
			return $html;
		}

		// 1) Lê todas as folhas de estilo locais.
		$sheets = array();
		if ( preg_match_all( '#<link\b[^>]*>#i', $html, $links, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $links[0] as $l ) {
				$attrs = Html::attrs( $l[0] );
				$rel   = isset( $attrs['rel'] ) ? strtolower( (string) $attrs['rel'] ) : '';
				if ( 'stylesheet' !== trim( $rel ) || empty( $attrs['href'] ) ) {
					continue;
				}
				$media = isset( $attrs['media'] ) ? strtolower( trim( (string) $attrs['media'] ) ) : 'all';
				if ( '' !== $media && ! preg_match( '/^(all|screen)\b/', $media ) && false === strpos( $media, 'width' ) ) {
					continue; // Impressão e outras mídias ficam como estão.
				}
				$href = Html::decoded( $attrs, 'href' );
				$path = Assets::url_to_path( preg_replace( '/[?#].*$/', '', $href ) );
				if ( ! $path || ! preg_match( '/\.css$/i', $path ) ) {
					continue;
				}
				$css = $this->load( $path, $href );
				if ( null === $css ) {
					continue;
				}
				$sheets[] = array(
					'tag'   => $l[0],
					'attrs' => $attrs,
					'href'  => $href,
					'path'  => $path,
					'css'   => $css,
					'media' => $media,
				);
			}
		}

		if ( $used ) {
			$result = $this->used_css( $html, $full_html, $sheets );
			if ( null !== $result ) {
				return $result;
			}
			// Se a remoção não for confiável, segue só com a minificação.
		}

		if ( $minify || Settings::get( 'fonts_swap' ) ) {
			foreach ( $sheets as $s ) {
				$url = Assets::write( $s['css'], 'css' );
				if ( $url ) {
					$attrs         = $s['attrs'];
					$attrs['href'] = esc_url( $url );
					$html          = str_replace( $s['tag'], Html::tag( 'link', $attrs ), $html );
				}
			}
		}
		return $html;
	}

	/**
	 * Lê e prepara o CSS de um arquivo: URLs absolutas, swap, WebP e minificação.
	 * O resultado fica guardado enquanto o arquivo não mudar.
	 */
	public function load( $path, $href ) {
		$mtime = @filemtime( $path ); // phpcs:ignore
		$size  = @filesize( $path ); // phpcs:ignore
		if ( ! $size || $size > 3 * MB_IN_BYTES ) {
			return null;
		}
		$key    = 'css-' . md5( $path . '|' . $mtime . '|' . $size . '|' . Settings::signature() ) . '.css';
		$cached = Assets::memo_get( $key );
		if ( null !== $cached ) {
			return $cached;
		}
		$css = file_get_contents( $path ); // phpcs:ignore
		if ( false === $css ) {
			return null;
		}
		$css = self::prepare( $css, $href );
		Assets::memo_set( $key, $css );
		return $css;
	}

	/** Ajustes aplicados a qualquer CSS antes de usar no site. */
	public static function prepare( $css, $base_url ) {
		$css = preg_replace( '/^\xEF\xBB\xBF/', '', $css );
		$css = preg_replace( '/@charset\s+["\'][^"\']*["\']\s*;/i', '', $css );
		$css = self::absolute_urls( $css, $base_url );
		if ( Settings::get( 'fonts_swap' ) ) {
			$css = Fonts::swap( $css );
		}
		if ( Settings::get( 'minify_css' ) || Settings::get( 'used_css' ) ) {
			$css = Minify::css( $css );
		}
		return $css;
	}

	/** Torna absolutas as URLs relativas de url(...) e aplica WebP nas imagens. */
	public static function absolute_urls( $css, $base_url ) {
		return preg_replace_callback(
			'/url\(\s*([\'"]?)(.*?)\1\s*\)/i',
			function ( $m ) use ( $base_url ) {
				$u = trim( $m[2] );
				if ( '' === $u || 0 === stripos( $u, 'data:' ) ) {
					return $m[0];
				}
				$u = Assets::resolve( $base_url, $u );
				$u = Assets::webp( $u );
				return 'url(' . $m[1] . $u . $m[1] . ')';
			},
			$css
		);
	}

	/**
	 * Troca cada folha de estilo pelo CSS que a página usa, no mesmo lugar (mantém a ordem).
	 *
	 * @return string|null Null quando o resultado não parece confiável.
	 */
	private function used_css( $html, $full_html, array $sheets ) {
		$checker = new Used_Css( $full_html, Settings::lines( 'used_css_safelist' ) );

		// Blocos <style> grandes também entram.
		$inline = array();
		if ( Settings::get( 'used_css_inline_styles' ) && preg_match_all( '#<style\b([^>]*)>(.*?)</style\s*>#is', $html, $st, PREG_SET_ORDER ) ) {
			foreach ( $st as $s ) {
				if ( strlen( $s[2] ) >= self::INLINE_MIN && false === strpos( $s[1], 'fwp' ) && false === stripos( $s[1], 'media="print"' ) ) {
					$inline[] = array(
						'tag' => $s[0],
						'open' => '<style' . $s[1] . '>',
						'css' => Minify::css( Fonts::swap( $s[2] ) ),
					);
				}
			}
		}
		if ( ! $sheets && ! $inline ) {
			return null;
		}

		// Etapa 1: regras.
		$before    = 0;
		$filtered  = array();
		$reference = '';
		foreach ( array_merge( $sheets, $inline ) as $i => $s ) {
			$before        += strlen( $s['css'] );
			$nodes          = $this->parsed( $s['css'] );
			$filtered[ $i ] = $checker->filter_rules( $nodes );
			$reference     .= Used_Css::reference_text( $filtered[ $i ] );
		}
		// Estilos escritos direto nos elementos também citam fontes e animações.
		if ( preg_match_all( '/\sstyle\s*=\s*(["\'])(.*?)\1/is', $full_html, $sm ) ) {
			$reference .= implode( "\n", $sm[2] );
		}
		$reference .= $this->small_inline_styles( $html );
		// Animações e fontes citadas nos data-settings do Elementor (ex.: "_animation":"fadeInUp").
		if ( preg_match_all( '/data-settings\s*=\s*(["\'])(.*?)\1/is', $full_html, $dm ) ) {
			$reference .= html_entity_decode( implode( "\n", $dm[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		// Etapa 2: fontes e animações + montagem.
		$font_opts = array(
			'weights' => Used_Css::font_weights( $reference ),
			'italic'  => (bool) preg_match( '/<(em|i|cite|dfn|var|address)\b/i', $full_html ) || (bool) preg_match( '/font-style\s*:\s*(italic|oblique)/i', $reference ),
		);
		$after  = 0;
		$output = array();
		foreach ( array_merge( $sheets, $inline ) as $i => $s ) {
			$nodes        = Used_Css::filter_fonts_and_keyframes( $filtered[ $i ], $reference, $font_opts );
			$output[ $i ] = self::hoist_imports( Css_Parser::render( $nodes ) );
			$after       += strlen( $output[ $i ] );
		}

		// Conferência de segurança: um resultado pequeno demais indica que algo deu errado.
		if ( $before > 20000 && $after < 1500 ) {
			return null;
		}

		$fallback = Settings::get( 'used_css_fallback' );
		$count    = count( $sheets );
		foreach ( array_merge( $sheets, $inline ) as $i => $s ) {
			$css = str_replace( '</style', '<\/style', $output[ $i ] );
			if ( $i < $count ) {
				$id    = isset( $s['attrs']['id'] ) ? preg_replace( '/[^a-zA-Z0-9_-]/', '', $s['attrs']['id'] ) : 'fwp-' . $i;
				$media = ( '' !== $s['media'] && 'all' !== $s['media'] ) ? ' media="' . esc_attr( $s['media'] ) . '"' : '';
				$new   = '' === $css ? '' : '<style id="' . esc_attr( $id ) . '-used" data-fwp-used' . $media . '>' . $css . '</style>';
				if ( 'none' !== $fallback ) {
					$attrs = $s['attrs'];
					$orig  = Assets::write( $s['css'], 'css' );
					unset( $attrs['href'] );
					$attrs['data-fwp-href'] = esc_url( $orig ? $orig : $s['href'] );
					$new                   .= Html::tag( 'link', $attrs );
					$this->has_deferred     = true;
				}
				$html = str_replace( $s['tag'], $new, $html );
			} else {
				$html = str_replace( $s['tag'], '' === $css ? '' : $s['open'] . $css . '</style>', $html );
			}
		}
		return $html;
	}

	/** Nós do CSS, guardados enquanto o conteúdo não mudar. */
	private function parsed( $css ) {
		$key = 'parsed-' . md5( $css ) . '.ser';
		$raw = Assets::memo_get( $key );
		if ( null !== $raw ) {
			$nodes = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( is_array( $nodes ) ) {
				return $nodes;
			}
		}
		$nodes = Css_Parser::parse( Css_Parser::strip_comments( $css ) );
		Assets::memo_set( $key, serialize( $nodes ) ); // phpcs:ignore
		return $nodes;
	}

	/** Texto dos blocos <style> pequenos (não filtrados), usado como referência de fontes. */
	private function small_inline_styles( $html ) {
		$out = '';
		if ( preg_match_all( '#<style\b[^>]*>(.*?)</style\s*>#is', $html, $m ) ) {
			foreach ( $m[1] as $css ) {
				if ( strlen( $css ) < self::INLINE_MIN ) {
					$out .= $css . "\n";
				}
			}
		}
		return $out;
	}

	/** @import precisa ficar no começo do CSS. */
	private static function hoist_imports( $css ) {
		if ( false === stripos( $css, '@import' ) ) {
			return $css;
		}
		$imports = array();
		$css     = preg_replace_callback(
			'/@import\s+[^;]+;/i',
			function ( $m ) use ( &$imports ) {
				$imports[] = $m[0];
				return '';
			},
			$css
		);
		return implode( '', $imports ) . $css;
	}
}
