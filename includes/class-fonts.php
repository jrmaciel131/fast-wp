<?php
/**
 * Google Fonts no próprio site + font-display: swap + pré-carregamento da fonte principal.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Fonts {

	const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

	/** Subconjuntos baixados para o servidor. Os outros continuam vindo do Google, se forem usados. */
	const LOCAL_SUBSETS = array( 'latin', 'latin-ext' );

	/** @var string[] Tags de preload para o <head>. */
	public $preloads = array();

	/** @var float */
	private $started = 0.0;

	public function process( $html ) {
		if ( ! Settings::get( 'fonts_google_local' ) || false === stripos( $html, 'fonts.googleapis.com' ) ) {
			return $html;
		}
		$this->started = microtime( true );
		$localized     = 0;
		$html          = preg_replace_callback(
			'#<link\b[^>]*>#i',
			function ( $m ) use ( &$localized ) {
				$attrs = Html::attrs( $m[0] );
				$href  = Html::decoded( $attrs, 'href' );
				$rel   = isset( $attrs['rel'] ) ? strtolower( (string) $attrs['rel'] ) : '';
				if ( false === strpos( $rel, 'stylesheet' ) || false === stripos( $href, 'fonts.googleapis.com/css' ) ) {
					return $m[0];
				}
				$local = $this->localize( $href );
				if ( ! $local ) {
					return $m[0];
				}
				$localized++;
				$attrs['href'] = esc_url( $local['url'] );
				unset( $attrs['crossorigin'] );
				foreach ( $local['preload'] as $font ) {
					$this->preloads[ $font ] = '<link rel="preload" href="' . esc_url( $font ) . '" as="font" type="font/woff2" crossorigin>';
				}
				return Html::tag( 'link', $attrs );
			},
			$html
		);

		// Sem nenhum link do Google sobrando, as conexões antecipadas não servem mais.
		if ( $localized && false === stripos( $html, 'fonts.googleapis.com/css' ) ) {
			$html = preg_replace( '#<link\b[^>]*rel=["\']?(?:preconnect|dns-prefetch)["\']?[^>]*fonts\.(?:googleapis|gstatic)\.com[^>]*>\s*#i', '', $html );
			$html = preg_replace( '#<link\b[^>]*fonts\.(?:googleapis|gstatic)\.com[^>]*rel=["\']?(?:preconnect|dns-prefetch)["\']?[^>]*>\s*#i', '', $html );
		}
		if ( ! Settings::get( 'fonts_preload' ) ) {
			$this->preloads = array();
		}
		$this->preloads = array_slice( $this->preloads, 0, 2 );
		return $html;
	}

	/**
	 * Baixa o CSS e as fontes (latin) do Google e devolve o endereço local.
	 *
	 * @return array|null ['url' => ..., 'preload' => [...]]
	 */
	public function localize( $url ) {
		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		}
		$url = remove_query_arg( 'ver', $url );
		if ( false === stripos( $url, 'display=' ) ) {
			$url = add_query_arg( 'display', 'swap', $url );
		}
		$key  = substr( md5( $url ), 0, 16 );
		$meta = Assets::memo_get( 'gf-' . $key . '.json' );
		if ( $meta ) {
			$data = json_decode( $meta, true );
			if ( is_array( $data ) && ! empty( $data['url'] ) ) {
				return $data;
			}
		}
		if ( get_transient( 'fast_wp_gf_fail_' . $key ) ) {
			return null;
		}
		// Limite de tempo: nunca segura a página por mais de 15 segundos baixando fontes.
		if ( microtime( true ) - $this->started > 15 ) {
			return null;
		}

		$res = wp_remote_get(
			$url,
			array(
				'timeout'    => 8,
				'user-agent' => self::UA,
			)
		);
		$css = is_wp_error( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || false === stripos( $css, '@font-face' ) ) {
			set_transient( 'fast_wp_gf_fail_' . $key, 1, HOUR_IN_SECONDS );
			return null;
		}

		$preload = array();
		$dir     = Assets::dir( 'fonts' );
		wp_mkdir_p( $dir );

		$css = preg_replace_callback(
			'#(?:/\*\s*([a-z0-9\-\[\]]+)\s*\*/\s*)?@font-face\s*\{([^}]*)\}#i',
			function ( $m ) use ( $dir, &$preload ) {
				$subset = isset( $m[1] ) && '' !== $m[1] ? strtolower( $m[1] ) : 'latin';
				$body   = $m[2];
				if ( in_array( $subset, self::LOCAL_SUBSETS, true ) ) {
					$body = preg_replace_callback(
						'#url\((https://fonts\.gstatic\.com/[^)]+)\)#i',
						function ( $u ) use ( $dir, $subset, $body, &$preload ) {
							$local = $this->download( $u[1], $dir );
							if ( ! $local ) {
								return $u[0];
							}
							$normal = ! preg_match( '/font-style\s*:\s*italic/i', $body );
							if ( 'latin' === $subset && $normal && preg_match( '/font-weight\s*:\s*400\b/', $body ) && preg_match( '/\.woff2$/i', $local ) ) {
								$preload[] = $local;
							}
							return 'url(' . $local . ')';
						},
						$body
					);
				}
				if ( ! preg_match( '/font-display\s*:/i', $body ) ) {
					$body .= ';font-display:swap';
				}
				return ( isset( $m[1] ) && '' !== $m[1] ? '/* ' . $m[1] . ' */' : '' ) . '@font-face{' . trim( $body ) . '}';
			},
			$css
		);

		$local_url = Assets::write( $css, 'css' );
		if ( ! $local_url ) {
			return null;
		}
		$data = array(
			'url'     => $local_url,
			'preload' => array_values( array_unique( $preload ) ),
		);
		Assets::memo_set( 'gf-' . $key . '.json', wp_json_encode( $data ) );
		return $data;
	}

	/** Baixa um arquivo de fonte do Google e devolve a URL local. */
	private function download( $url, $dir ) {
		$ext  = preg_match( '#\.(woff2|woff|ttf|otf)(\?|$)#i', $url, $m ) ? strtolower( $m[1] ) : 'woff2';
		$name = substr( md5( $url ), 0, 16 ) . '.' . $ext;
		$file = $dir . '/' . $name;
		if ( ! is_file( $file ) ) {
			if ( microtime( true ) - $this->started > 15 ) {
				return null;
			}
			$res = wp_remote_get(
				$url,
				array(
					'timeout'    => 8,
					'user-agent' => self::UA,
				)
			);
			$body = is_wp_error( $res ) ? '' : wp_remote_retrieve_body( $res );
			if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || strlen( $body ) < 100 ) {
				return null;
			}
			if ( false === file_put_contents( $file, $body ) ) { // phpcs:ignore
				return null;
			}
		}
		return Assets::url( 'fonts/' . $name );
	}

	/** Garante font-display: swap em todos os @font-face de um CSS. */
	public static function swap( $css ) {
		if ( false === stripos( $css, '@font-face' ) ) {
			return $css;
		}
		return preg_replace_callback(
			'#@font-face\s*\{([^}]*)\}#i',
			function ( $m ) {
				if ( preg_match( '/font-display\s*:/i', $m[1] ) ) {
					return $m[0];
				}
				return '@font-face{' . rtrim( trim( $m[1] ), ';' ) . ';font-display:swap}';
			},
			$css
		);
	}
}
