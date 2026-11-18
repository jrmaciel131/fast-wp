<?php
/**
 * Images and videos: lazy load, main image (LCP), YouTube thumbnail, backgrounds and WebP.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Media {

	/** @var string[] Tags for the <head>. */
	public $preloads = array();

	/** @var bool */
	public $uses_youtube = false;

	/** @var bool */
	public $uses_lazy_bg = false;

	/** Markers of where the main content starts (after the header). */
	const CONTENT_MARKERS = '#data-elementor-type=["\'](?:wp-page|wp-post|single-page|single-post|single|archive|product|search-results|error-404|landing-page)["\']|<main\b|elementor-location-single|elementor-location-archive|id=["\'](?:content|primary|main)["\']#i';

	public function process( $html ) {
		$lazy      = (bool) Settings::get( 'lazy_images' );
		$skip      = (int) Settings::get( 'lazy_skip' );
		$lcp       = (bool) Settings::get( 'lcp_priority' );
		$webp      = (bool) Settings::get( 'webp_serve' );
		$lcp_index = $lcp ? $this->find_lcp_index( $html ) : -1;

		$index = -1;
		$html  = preg_replace_callback(
			'#<img\b[^>]*>#i',
			function ( $m ) use ( &$index, $lazy, $skip, $lcp_index, $webp ) {
				$index++;
				$tag   = $m[0];
				$attrs = Html::attrs( $tag );
				if ( isset( $attrs['data-fwp-skip'] ) ) {
					return $tag;
				}
				$class   = isset( $attrs['class'] ) ? (string) $attrs['class'] : '';
				$no_lazy = isset( $attrs['data-no-lazy'] ) || isset( $attrs['data-skip-lazy'] ) || preg_match( '/\b(skip-lazy|no-lazy)\b/', $class );
				$changed = false;

				if ( $webp ) {
					if ( ! empty( $attrs['src'] ) ) {
						$new = Assets::webp( Html::decoded( $attrs, 'src' ) );
						if ( $new !== Html::decoded( $attrs, 'src' ) ) {
							$attrs['src'] = esc_url( $new );
							$changed      = true;
						}
					}
					if ( ! empty( $attrs['srcset'] ) ) {
						$new = Assets::webp_srcset( Html::decoded( $attrs, 'srcset' ) );
						if ( $new !== Html::decoded( $attrs, 'srcset' ) ) {
							$attrs['srcset'] = esc_attr( $new );
							$changed         = true;
						}
					}
				}

				if ( $index === $lcp_index ) {
					unset( $attrs['loading'] );
					$attrs['fetchpriority'] = 'high';
					$attrs['decoding']      = 'async';
					$this->add_lcp_preload( $attrs );
					$changed = true;
				} elseif ( $index < $skip ) {
					if ( isset( $attrs['loading'] ) && 'lazy' === strtolower( (string) $attrs['loading'] ) ) {
						unset( $attrs['loading'] );
						$changed = true;
					}
				} elseif ( $lazy && ! $no_lazy && ! isset( $attrs['loading'] ) && ! isset( $attrs['data-src'] ) ) {
					$attrs['loading'] = 'lazy';
					if ( ! isset( $attrs['decoding'] ) ) {
						$attrs['decoding'] = 'async';
					}
					$changed = true;
				}
				return $changed ? Html::tag( 'img', $attrs, '/>' === substr( $tag, -2 ) ) : $tag;
			},
			$html
		);

		if ( Settings::get( 'youtube_facade' ) ) {
			$html = $this->youtube( $html );
		}
		if ( Settings::get( 'lazy_iframes' ) ) {
			$html = preg_replace_callback(
				'#<iframe\b[^>]*>#i',
				function ( $m ) {
					$attrs = Html::attrs( $m[0] );
					if ( isset( $attrs['loading'] ) || isset( $attrs['data-no-lazy'] ) || empty( $attrs['src'] ) ) {
						return $m[0];
					}
					$attrs['loading'] = 'lazy';
					return Html::tag( 'iframe', $attrs );
				},
				$html
			);
		}
		if ( Settings::get( 'lazy_bg' ) ) {
			$html = $this->lazy_backgrounds( $html );
		}
		foreach ( Settings::lines( 'lcp_preload_urls' ) as $url ) {
			$url                    = Assets::webp( $url );
			$this->preloads[ $url ] = '<link rel="preload" as="image" href="' . esc_url( $url ) . '" fetchpriority="high">';
		}
		return $html;
	}

	/**
	 * Picks the main image: the first content image (after the header)
	 * that is not a small icon or an SVG.
	 */
	private function find_lcp_index( $html ) {
		$start = 0;
		if ( preg_match( self::CONTENT_MARKERS, $html, $mk, PREG_OFFSET_CAPTURE ) ) {
			$start = $mk[0][1];
		}
		if ( ! preg_match_all( '#<img\b[^>]*>#i', $html, $imgs, PREG_OFFSET_CAPTURE ) ) {
			return -1;
		}
		// WordPress already marks the main image with fetchpriority="high": respect its choice.
		foreach ( $imgs[0] as $i => $img ) {
			if ( preg_match( '/\sfetchpriority\s*=\s*["\']?high/i', $img[0] ) ) {
				return $i;
			}
		}
		foreach ( $imgs[0] as $i => $img ) {
			if ( $img[1] < $start ) {
				continue;
			}
			$attrs = Html::attrs( $img[0] );
			$src   = Html::decoded( $attrs, 'src' );
			if ( '' === $src || 0 === stripos( $src, 'data:' ) || preg_match( '#\.svg(\?|$)#i', $src ) ) {
				continue;
			}
			if ( isset( $attrs['width'] ) && (int) $attrs['width'] > 0 && (int) $attrs['width'] < 150 ) {
				continue;
			}
			return $i;
		}
		return -1;
	}

	private function add_lcp_preload( array $attrs ) {
		$src = Html::decoded( $attrs, 'src' );
		if ( '' === $src ) {
			return;
		}
		$tag = '<link rel="preload" as="image" href="' . esc_url( $src ) . '"';
		if ( ! empty( $attrs['srcset'] ) ) {
			$tag .= ' imagesrcset="' . esc_attr( Html::decoded( $attrs, 'srcset' ) ) . '"';
			if ( ! empty( $attrs['sizes'] ) ) {
				$tag .= ' imagesizes="' . esc_attr( Html::decoded( $attrs, 'sizes' ) ) . '"';
			}
		}
		$this->preloads[ $src ] = $tag . ' fetchpriority="high">';
	}

	/** Replaces YouTube iframes with a thumbnail + button. The player only loads on click. */
	private function youtube( $html ) {
		return preg_replace_callback(
			'#<iframe\b[^>]*src=["\']([^"\']*youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{6,20})[^"\']*)["\'][^>]*>\s*</iframe>#i',
			function ( $m ) {
				$attrs = Html::attrs( $m[0] );
				if ( isset( $attrs['data-no-lazy'] ) ) {
					return $m[0];
				}
				$this->uses_youtube = true;
				$src                = html_entity_decode( $m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$src               .= ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'autoplay=1';
				$id                 = $m[2];
				$title              = isset( $attrs['title'] ) ? $attrs['title'] : t( 'YouTube video' );
				$style              = '';
				if ( ! empty( $attrs['width'] ) && ! empty( $attrs['height'] ) && (int) $attrs['height'] > 0 && ctype_digit( (string) $attrs['width'] ) ) {
					$style = ' style="aspect-ratio:' . (int) $attrs['width'] . '/' . (int) $attrs['height'] . '"';
				}
				$class = 'fwp-yt' . ( ! empty( $attrs['class'] ) ? ' ' . esc_attr( $attrs['class'] ) : '' );
				return '<div class="' . $class . '" data-fwp-yt="' . esc_url( $src ) . '" data-title="' . esc_attr( $title ) . '"' . $style . '>'
					. '<img src="https://i.ytimg.com/vi/' . esc_attr( $id ) . '/hqdefault.jpg" alt="' . esc_attr( $title ) . '" loading="lazy" decoding="async" data-fwp-skip>'
					. '<button type="button" class="fwp-yt-play" aria-label="' . esc_attr( t( 'Play video' ) ) . '"></button></div>';
			},
			$html
		);
	}

	/** Backgrounds in the style attribute: only load when the element comes into view. */
	private function lazy_backgrounds( $html ) {
		$count = 0;
		return preg_replace_callback(
			'#<(div|section|span|a|li|figure|article|header|footer|aside)\b([^>]*\bstyle\s*=\s*(["\'])[^>]*?url\([^>]*)>#i',
			function ( $m ) use ( &$count ) {
				$count++;
				if ( $count <= 2 ) {
					return $m[0]; // The first backgrounds may be at the top of the page.
				}
				$attrs = Html::attrs( $m[0] );
				if ( empty( $attrs['style'] ) || isset( $attrs['data-no-lazy'] ) ) {
					return $m[0];
				}
				$style = Html::decoded( $attrs, 'style' );
				// Only the background-image property (the "background:" shorthand also carries color and position).
				if ( ! preg_match( '/background-image\s*:\s*url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)\s*;?/i', $style, $bg ) ) {
					return $m[0];
				}
				$this->uses_lazy_bg      = true;
				$url                     = Assets::webp( $bg[1] );
				$attrs['style']          = esc_attr( trim( str_replace( $bg[0], '', $style ) ) );
				$attrs['data-fwp-bg']    = esc_url( $url );
				$attrs['class']          = trim( ( isset( $attrs['class'] ) ? $attrs['class'] : '' ) . ' fwp-lazy-bg' );
				if ( '' === $attrs['style'] ) {
					unset( $attrs['style'] );
				}
				return Html::tag( $m[1], $attrs );
			},
			$html
		);
	}
}
