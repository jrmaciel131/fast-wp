<?php
/**
 * Minificadores conservadores de CSS, JS e HTML (sem bibliotecas externas).
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Minify {

	/** Minifica CSS. Não mexe no conteúdo de strings. */
	public static function css( $css ) {
		$css   = Css_Parser::strip_comments( $css );
		$parts = preg_split( '/("(?:\\\\.|[^"\\\\\n])*"|\'(?:\\\\.|[^\'\\\\\n])*\')/s', $css, -1, PREG_SPLIT_DELIM_CAPTURE );
		$out   = '';
		foreach ( $parts as $i => $p ) {
			if ( 1 === $i % 2 ) {
				$out .= $p; // String: mantém como está.
				continue;
			}
			$p = preg_replace( '/\s+/', ' ', $p );
			$p = preg_replace( '/\s*([{};,>])\s*/', '$1', $p );
			$p = preg_replace( '/:\s+/', ':', $p );
			// Espaço antes de ":" só dentro de declarações (em seletores, "a :hover" é diferente de "a:hover").
			$p = preg_replace( '/\s+:(?=[^{}]*[;}])/', ':', $p );
			$p = preg_replace( '/\s+!important/i', '!important', $p );
			$out .= $p;
		}
		$out = str_replace( ';}', '}', $out );
		return trim( $out );
	}

	/**
	 * Minificação conservadora de JS: remove comentários e espaços repetidos,
	 * mas mantém as quebras de linha (para não quebrar a inserção automática de ponto e vírgula).
	 */
	public static function js( $js ) {
		$len  = strlen( $js );
		$out  = '';
		$i    = 0;
		$prev = ''; // Último caractere significativo (para saber se "/" começa uma regex).
		while ( $i < $len ) {
			$c = $js[ $i ];
			$n = $i + 1 < $len ? $js[ $i + 1 ] : '';

			if ( '"' === $c || "'" === $c || '`' === $c ) {
				$end   = self::js_string_end( $js, $i, $len );
				$out  .= substr( $js, $i, $end - $i );
				$i     = $end;
				$prev  = $c;
				continue;
			}
			if ( '/' === $c && '/' === $n ) {
				$end = strpos( $js, "\n", $i );
				$i   = false === $end ? $len : $end;
				continue;
			}
			if ( '/' === $c && '*' === $n ) {
				$end = strpos( $js, '*/', $i + 2 );
				if ( false === $end ) {
					return $js; // Comentário sem fim: devolve o original.
				}
				if ( isset( $js[ $i + 2 ] ) && '!' === $js[ $i + 2 ] ) {
					$out .= substr( $js, $i, $end + 2 - $i ); // Mantém licenças /*! ... */.
				} elseif ( false !== strpos( substr( $js, $i, $end - $i ), "\n" ) ) {
					$out .= "\n";
				} else {
					$out .= ' ';
				}
				$i = $end + 2;
				continue;
			}
			if ( '/' === $c && self::regex_can_start( $prev, $out ) ) {
				$end = self::js_regex_end( $js, $i, $len );
				if ( false === $end ) {
					return $js;
				}
				$out  .= substr( $js, $i, $end - $i );
				$i     = $end;
				$prev  = '/';
				continue;
			}
			if ( ctype_space( $c ) ) {
				$j = $i;
				$nl = false;
				while ( $j < $len && ctype_space( $js[ $j ] ) ) {
					if ( "\n" === $js[ $j ] || "\r" === $js[ $j ] ) {
						$nl = true;
					}
					$j++;
				}
				$out .= $nl ? "\n" : ' ';
				$i    = $j;
				continue;
			}
			$out .= $c;
			$prev = $c;
			$i++;
		}
		// Remove espaços no começo das linhas e linhas vazias.
		$out = preg_replace( "/\n[ ]+/", "\n", $out );
		$out = preg_replace( "/\n{2,}/", "\n", $out );
		return trim( $out );
	}

	private static function js_string_end( $js, $i, $len ) {
		$q = $js[ $i ];
		$j = $i + 1;
		$depth = 0;
		while ( $j < $len ) {
			$ch = $js[ $j ];
			if ( '\\' === $ch ) {
				$j += 2;
				continue;
			}
			if ( '`' === $q ) {
				if ( '$' === $ch && isset( $js[ $j + 1 ] ) && '{' === $js[ $j + 1 ] ) {
					$depth++;
					$j += 2;
					continue;
				}
				if ( '}' === $ch && $depth > 0 ) {
					$depth--;
				} elseif ( '`' === $ch && 0 === $depth ) {
					return $j + 1;
				}
			} elseif ( $ch === $q || "\n" === $ch ) {
				return $j + 1;
			}
			$j++;
		}
		return $len;
	}

	private static function regex_can_start( $prev, $out ) {
		if ( '' === $prev ) {
			return true;
		}
		if ( false !== strpos( '(,=:[!&|?{};+-*%<>~^', $prev ) ) {
			return true;
		}
		// Palavras-chave depois das quais vem uma expressão.
		return (bool) preg_match( '/(?:^|[^\w$])(return|typeof|instanceof|in|of|new|delete|void|throw|case|do|else|yield|await)\s*$/', substr( $out, -20 ) );
	}

	private static function js_regex_end( $js, $i, $len ) {
		$j     = $i + 1;
		$class = false;
		while ( $j < $len ) {
			$ch = $js[ $j ];
			if ( "\n" === $ch ) {
				return false;
			}
			if ( '\\' === $ch ) {
				$j += 2;
				continue;
			}
			if ( '[' === $ch ) {
				$class = true;
			} elseif ( ']' === $ch ) {
				$class = false;
			} elseif ( '/' === $ch && ! $class ) {
				$j++;
				while ( $j < $len && ctype_alpha( $js[ $j ] ) ) {
					$j++; // Flags.
				}
				return $j;
			}
			$j++;
		}
		return false;
	}

	/**
	 * Minifica HTML de forma segura: remove comentários e junta espaços,
	 * sem tocar em <pre>, <textarea>, <script> e <style>.
	 */
	public static function html( $html ) {
		$h    = new Html();
		$html = $h->protect( $html, array( 'script', 'style', 'pre', 'textarea' ) );
		// Remove comentários, menos os condicionais do IE e marcadores usados por outros plugins.
		$html = preg_replace( '#<!--(?!\s*(?:\[if|<!|noindex|/noindex|googleoff|googleon))(?!\s*fwp).*?-->#s', '', $html );
		$html = preg_replace_callback(
			'/\s{2,}/',
			function ( $m ) {
				return false !== strpbrk( $m[0], "\r\n" ) ? "\n" : ' ';
			},
			$html
		);
		return $h->restore( $html );
	}
}
