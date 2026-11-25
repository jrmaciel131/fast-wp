<?php
/**
 * CSS: minifies the site's stylesheets and removes unused CSS.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Css {

	/** Minimum size of a <style> block to be cleaned. */
	const INLINE_MIN = 8192;

	/** @var bool Whether some original CSS is loaded later. */
	public $has_deferred = false;

	/**
	 * @param string $html      HTML with scripts and comments protected.
	 * @param string $full_html Full original HTML (used to read which words are used).
	 */
	public function process( $html, $full_html ) {
		$minify = (bool) Settings::get( 'minify_css' );
		$used   = (bool) Settings::get( 'used_css' );
		if ( ! $minify && ! $used && ! Settings::get( 'fonts_swap' ) ) {
			return $html;
		}

		// 0) The same stylesheet linked twice: keep only the first one.
		$seen = array();
		$html = preg_replace_callback(
			'#<link\b[^>]*>#i',
			function ( $m ) use ( &$seen ) {
				$attrs = Html::attrs( $m[0] );
				if ( empty( $attrs['href'] ) || 'stylesheet' !== strtolower( trim( (string) ( isset( $attrs['rel'] ) ? $attrs['rel'] : '' ) ) ) ) {
					return $m[0];
				}
				$key = preg_replace( '#^https?:#i', '', Html::decoded( $attrs, 'href' ) ) . '|' . ( isset( $attrs['media'] ) ? $attrs['media'] : 'all' );
				if ( isset( $seen[ $key ] ) ) {
					return '';
				}
				$seen[ $key ] = true;
				return $m[0];
			},
			$html
		);

		// 1) Read every local stylesheet (and those on well-known public CDNs).
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
					continue; // Print and other media stay as they are.
				}
				$href = Html::decoded( $attrs, 'href' );
				$path = Assets::url_to_path( preg_replace( '/[?#].*$/', '', $href ) );
				if ( $path && preg_match( '/\.css$/i', $path ) ) {
					$css = $this->load( $path, $href );
				} elseif ( ! $path && self::is_public_cdn( $href ) ) {
					$css = $this->load_remote( $href );
				} else {
					continue;
				}
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
			// If removal is not reliable, fall back to minification only.
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

	/** Public CDNs that serve versioned, unchanging files (safe to download and clean). */
	const PUBLIC_CDNS = array( 'cdnjs.cloudflare.com', 'cdn.jsdelivr.net', 'unpkg.com', 'use.fontawesome.com', 'maxcdn.bootstrapcdn.com', 'stackpath.bootstrapcdn.com', 'code.jquery.com', 'ajax.googleapis.com' );

	public static function is_public_cdn( $url ) {
		$host = strtolower( (string) wp_parse_url( 0 === strpos( $url, '//' ) ? 'https:' . $url : $url, PHP_URL_HOST ) );
		return in_array( $host, self::PUBLIC_CDNS, true ) && preg_match( '#\.css(\?|$)#i', $url );
	}

	/**
	 * Downloads a stylesheet from a public CDN once (kept for 7 days) and prepares it like a local one.
	 * Fonts and images inside it keep loading from the CDN.
	 */
	public function load_remote( $href ) {
		$url = 0 === strpos( $href, '//' ) ? 'https:' . $href : $href;
		$key = 'remote-' . md5( $url . '|' . Settings::signature() ) . '.css';
		$file = Assets::dir( 'memo' ) . '/' . $key;
		if ( is_file( $file ) && filemtime( $file ) > time() - 7 * DAY_IN_SECONDS ) {
			return Assets::memo_get( $key );
		}
		$fail = 'fast_wp_cdn_fail_' . md5( $url );
		if ( get_transient( $fail ) ) {
			return null;
		}
		$res  = wp_remote_get( $url, array( 'timeout' => 6 ) );
		$body = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || '' === trim( $body ) || strlen( $body ) > 3 * MB_IN_BYTES ) {
			set_transient( $fail, 1, HOUR_IN_SECONDS );
			return null;
		}
		$css = self::prepare( $body, $url );
		Assets::memo_set( $key, $css );
		return $css;
	}

	/**
	 * Reads and prepares a file's CSS: absolute URLs, swap, WebP and minification.
	 * The result is kept while the file does not change.
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

	/** Adjustments applied to any CSS before it is used on the site. */
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

	/** Makes relative url(...) values absolute and applies WebP to images. */
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
	 * Replaces each stylesheet with the CSS the page uses, in the same place (keeps the order).
	 *
	 * @return string|null Null when the result does not look reliable.
	 */
	private function used_css( $html, $full_html, array $sheets ) {
		$checker = new Used_Css( $full_html, Settings::lines( 'used_css_safelist' ) );

		// Large <style> blocks are included too.
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

		// Step 1: rules.
		$before    = 0;
		$filtered  = array();
		$reference = '';
		foreach ( array_merge( $sheets, $inline ) as $i => $s ) {
			$before        += strlen( $s['css'] );
			$nodes          = $this->parsed( $s['css'] );
			$filtered[ $i ] = $checker->filter_rules( $nodes );
			$reference     .= Used_Css::reference_text( $filtered[ $i ] );
		}
		// Inline style attributes also reference fonts and animations.
		if ( preg_match_all( '/\sstyle\s*=\s*(["\'])(.*?)\1/is', $full_html, $sm ) ) {
			$reference .= implode( "\n", $sm[2] );
		}
		$reference .= $this->small_inline_styles( $html );
		// Animations and fonts referenced in Elementor data-settings (e.g. "_animation":"fadeInUp").
		if ( preg_match_all( '/data-settings\s*=\s*(["\'])(.*?)\1/is', $full_html, $dm ) ) {
			$reference .= html_entity_decode( implode( "\n", $dm[2] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}

		// Step 2: fonts and animations + assembly.
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

		// Safety check: a result that is too small means something went wrong.
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

	/** CSS nodes, kept while the content does not change. */
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

	/** Text of small (unfiltered) <style> blocks, used as a font reference. */
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

	/** @import must stay at the top of the CSS. */
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
