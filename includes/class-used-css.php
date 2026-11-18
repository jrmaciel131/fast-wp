<?php
/**
 * Remove o CSS que a página não usa.
 *
 * Como funciona: lê todas as palavras do HTML (classes, ids, textos de scripts e data-settings do
 * Elementor) e só mantém as regras cujos seletores usam classes e ids que aparecem na página.
 * Regras com casos difíceis (escapes, seletores estranhos) são mantidas por segurança.
 * Classes que o JavaScript adiciona depois (menus, popups, sliders, animações do Elementor)
 * ficam numa lista segura.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Used_Css {

	/** Prefixos e padrões de classes adicionadas por JavaScript (Elementor, Swiper, menus). */
	const SAFE_PATTERNS = array(
		'^e--ua-', '^elementor-sticky', '^elementor-motion-effects', '^elementor-popup', '^dialog-',
		'^elementor-lightbox', '^swiper', '^elementor-swiper', '^elementor-slide', '^elementor-active',
		'^elementor-item-', '^elementor-menu-toggle', '^elementor-nav-menu', '^e-n-', '^e-con',
		'^elementor-invisible', '^animated', '^elementor-animation-', '^e-lazyloaded', '^lazyload',
		'^elementor-toggle', '^elementor-tab-', '^elementor-message', '^elementor-error', '^elementor-field',
		'^elementor-form', '^elementor-help-inline', '^elementor-video', '^elementor-background-video',
		'^elementor-background-slideshow', '^elementor-share', '^elementor-search-form',
		'^elementor-location-popup', '^elementor-ken-burns', '^elementor-headline-', '^elementor-countdown',
		'^elementor-accordion', '^elementor-gallery', '^e-gallery', '^e-transform', '^elementor-sticky',
		'^menu-item', '^sub-menu', '^current', '^highlighted', '^is-', '^has-', '^fwp-', '^wpcf7-',
		'-active$', '-open$', '-opened$', '-visible$', '-hidden$', '-show$', '-expanded$', '-collapsed$',
		'-loaded$', '-loading$', '-sticky$', '-scrolled$', '-fixed$', '-selected$', '-focus$', '-hover$',
	);

	/** Classes de estado comuns, adicionadas por JavaScript. */
	const SAFE_WORDS = array(
		'active', 'open', 'opened', 'show', 'showing', 'shown', 'visible', 'hidden', 'hide', 'current',
		'selected', 'focus', 'focused', 'expanded', 'collapsed', 'sticky', 'fixed', 'scrolled', 'loaded',
		'loading', 'js', 'no-js', 'touch', 'mobile', 'desktop', 'rtl', 'ltr', 'toggled', 'in', 'out',
		'fade', 'collapse', 'collapsing', 'modal-open', 'menu-open', 'nav-open', 'disabled', 'checked',
		'error', 'success', 'valid', 'invalid', 'playing', 'paused', 'inview', 'in-view', 'appear',
		'animated', 'elementor-invisible', 'elementor-active', 'elementor-item-active',
	);

	/** @var array Palavras encontradas no HTML. */
	private $words = array();

	/** @var string Regex única com os padrões seguros. */
	private $pattern = '';

	/** @var array */
	private $memo = array();

	/**
	 * @param string   $html     HTML completo da página.
	 * @param string[] $safelist Regras extras (exata, prefixo* ou /regex/).
	 */
	public function __construct( $html, array $safelist = array() ) {
		// O próprio CSS dentro de <style> não conta como "uso".
		$text = preg_replace( '#<style\b[^>]*>.*?</style\s*>#is', ' ', $html );
		if ( preg_match_all( '/-?[A-Za-z_][A-Za-z0-9_-]*/', (string) $text, $m ) ) {
			$this->words = array_fill_keys( $m[0], true );
		}
		foreach ( self::SAFE_WORDS as $w ) {
			$this->words[ $w ] = true;
		}

		$patterns = self::SAFE_PATTERNS;
		foreach ( $safelist as $rule ) {
			$rule = trim( $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( strlen( $rule ) > 2 && '/' === $rule[0] && '/' === substr( $rule, -1 ) ) {
				$inner = substr( $rule, 1, -1 );
				if ( false !== @preg_match( '/' . str_replace( '/', '\/', $inner ) . '/', '' ) ) { // phpcs:ignore
					$patterns[] = str_replace( '/', '\/', $inner );
				}
			} elseif ( '*' === substr( $rule, -1 ) ) {
				$patterns[] = '^' . preg_quote( ltrim( substr( $rule, 0, -1 ), '.#' ), '/' );
			} else {
				$this->words[ ltrim( $rule, '.#' ) ] = true;
			}
		}
		$this->pattern = '/' . implode( '|', $patterns ) . '/';
	}

	/** True se a palavra (classe ou id) aparece na página ou está na lista segura. */
	public function has( $token ) {
		if ( isset( $this->words[ $token ] ) ) {
			return true;
		}
		if ( ! isset( $this->memo[ $token ] ) ) {
			$this->memo[ $token ] = (bool) preg_match( $this->pattern, $token );
		}
		return $this->memo[ $token ];
	}

	/** True se o seletor pode casar com algo na página. Na dúvida, mantém. */
	public function selector_used( $sel ) {
		$s = trim( $sel );
		if ( '' === $s ) {
			return false;
		}
		if ( false !== strpos( $s, '\\' ) ) {
			return true; // Classes com caracteres escapados: mantém.
		}
		$s = (string) preg_replace( '/\[[^\]]*\]/', ' ', $s );
		$s = self::strip_functional_pseudos( $s );
		$s = (string) preg_replace( '/::?[a-zA-Z-]+/', '', $s );

		if ( preg_match_all( '/[.#](-?[_a-zA-Z][_a-zA-Z0-9-]*)/', $s, $m ) ) {
			foreach ( $m[1] as $token ) {
				if ( ! $this->has( $token ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/** Remove :not(...), :is(...), :where(...), :has(...) etc. (fica mais permissivo, nunca menos). */
	public static function strip_functional_pseudos( $s ) {
		$guard = 0;
		while ( $guard++ < 50 && preg_match( '/::?[a-zA-Z-]+\(/', $s, $m, PREG_OFFSET_CAPTURE ) ) {
			$start = $m[0][1];
			$i     = $start + strlen( $m[0][0] );
			$depth = 1;
			$len   = strlen( $s );
			while ( $i < $len && $depth > 0 ) {
				if ( '(' === $s[ $i ] ) {
					$depth++;
				} elseif ( ')' === $s[ $i ] ) {
					$depth--;
				}
				$i++;
			}
			$s = substr( $s, 0, $start ) . substr( $s, $i );
		}
		return $s;
	}

	/**
	 * Primeira etapa: filtra as regras comuns. @font-face e @keyframes ficam para a etapa final.
	 */
	public function filter_rules( array $nodes ) {
		$out = array();
		foreach ( $nodes as $n ) {
			switch ( $n[0] ) {
				case 'rule':
					$keep = array();
					foreach ( Css_Parser::split_selectors( $n[1] ) as $sel ) {
						if ( $this->selector_used( $sel ) ) {
							$keep[] = $sel;
						}
					}
					if ( $keep ) {
						$out[] = array( 'rule', implode( ',', $keep ), $n[2] );
					}
					break;
				case 'group':
					if ( preg_match( '/^@media\s+print\b/i', $n[1] ) ) {
						break; // CSS de impressão não é necessário para abrir a página.
					}
					$children = $this->filter_rules( $n[2] );
					if ( $children ) {
						$out[] = array( 'group', $n[1], $children );
					}
					break;
				default:
					$out[] = $n;
			}
		}
		return $out;
	}

	/**
	 * Etapa final: mantém só as fontes e animações citadas no CSS que sobrou (de todos os arquivos).
	 *
	 * @param array  $nodes     Nós já filtrados.
	 * @param string $reference CSS mantido de toda a página + estilos inline do HTML.
	 */
	public static function filter_fonts_and_keyframes( array $nodes, $reference, array $opts = array() ) {
		$out     = array();
		$weights = isset( $opts['weights'] ) ? $opts['weights'] : null;
		$italic  = ! isset( $opts['italic'] ) || $opts['italic'];
		foreach ( $nodes as $n ) {
			if ( 'fontface' === $n[0] ) {
				if ( preg_match( '/font-family\s*:\s*([^;]+)/i', $n[2], $m ) ) {
					$family = trim( trim( $m[1] ), '"\'' );
					if ( '' !== $family && false === stripos( $reference, $family ) ) {
						continue;
					}
				}
				if ( ! self::font_face_needed( $n[2], $weights, $italic ) ) {
					continue;
				}
			} elseif ( 'keyframes' === $n[0] ) {
				$name = trim( (string) preg_replace( '/^@[\w-]+\s*/', '', $n[1] ), " \"'" );
				if ( '' !== $name && ! preg_match( '/(?<![\w-])' . preg_quote( $name, '/' ) . '(?![\w-])/', $reference ) ) {
					continue;
				}
			} elseif ( 'group' === $n[0] ) {
				$n[2] = self::filter_fonts_and_keyframes( $n[2], $reference );
				if ( ! $n[2] ) {
					continue;
				}
			}
			$out[] = $n;
		}
		return $out;
	}

	/**
	 * Decide se um @font-face precisa entrar no CSS inicial.
	 * Fica de fora: alfabetos que não são latinos (cirílico, grego, vietnamita, símbolos),
	 * pesos que a página não usa e itálico quando não há texto em itálico.
	 * O CSS completo, carregado depois, traz tudo de volta.
	 */
	public static function font_face_needed( $body, $weights, $italic ) {
		if ( preg_match( '/unicode-range\s*:\s*([^;]+)/i', $body, $m ) ) {
			$latin = false;
			foreach ( explode( ',', $m[1] ) as $range ) {
				if ( preg_match( '/U\+([0-9A-F?]+)(?:-([0-9A-F]+))?/i', trim( $range ), $r ) ) {
					$from = hexdec( str_replace( '?', '0', $r[1] ) );
					$to   = isset( $r[2] ) && '' !== $r[2] ? hexdec( $r[2] ) : hexdec( str_replace( '?', 'F', $r[1] ) );
					if ( $from <= 0x7A && $to >= 0x41 ) { // Contém as letras A-z.
						$latin = true;
						break;
					}
				}
			}
			if ( ! $latin ) {
				return false;
			}
		}
		if ( ! $italic && preg_match( '/font-style\s*:\s*(italic|oblique)/i', $body ) ) {
			return false;
		}
		if ( is_array( $weights ) && preg_match( '/font-weight\s*:\s*(\d+)(?:\s+(\d+))?/i', $body, $w ) ) {
			$min = (int) $w[1];
			$max = isset( $w[2] ) && '' !== $w[2] ? (int) $w[2] : $min;
			foreach ( $weights as $weight ) {
				if ( $weight >= $min && $weight <= $max ) {
					return true;
				}
			}
			return false;
		}
		return true;
	}

	/** Pesos de fonte usados no CSS e nos estilos da página (400 e 700 sempre entram). */
	public static function font_weights( $reference ) {
		$weights = array( 400 => true, 700 => true );
		if ( preg_match_all( '/font-weight\s*:\s*([a-z0-9]+)/i', $reference, $m ) ) {
			foreach ( $m[1] as $v ) {
				$v = strtolower( $v );
				if ( ctype_digit( $v ) ) {
					$weights[ (int) $v ] = true;
				} elseif ( 'bold' === $v || 'bolder' === $v ) {
					$weights[700] = true;
					$weights[900] = 'bolder' === $v ? true : ( $weights[900] ?? false );
				} elseif ( 'lighter' === $v ) {
					$weights[300] = true;
				}
			}
		}
		if ( preg_match_all( '/\bfont\s*:\s*(?:[a-z-]+\s+)*?(\d{3})\s/i', $reference, $m ) ) {
			foreach ( $m[1] as $v ) {
				$weights[ (int) $v ] = true;
			}
		}
		return array_keys( array_filter( $weights ) );
	}

	/** Texto usado como referência para fontes e animações (regras comuns, sem @font-face/@keyframes). */
	public static function reference_text( array $nodes ) {
		$out = '';
		foreach ( $nodes as $n ) {
			if ( 'rule' === $n[0] || 'atblock' === $n[0] ) {
				$out .= $n[2] . "\n";
			} elseif ( 'group' === $n[0] ) {
				$out .= self::reference_text( $n[2] );
			}
		}
		return $out;
	}
}
