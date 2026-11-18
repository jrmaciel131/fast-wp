<?php
/**
 * Leitor de CSS simples e tolerante: separa regras, @media, @font-face, @keyframes etc.
 *
 * Formato dos nós:
 *   ['rule', seletor, corpo]
 *   ['group', '@media ...', [filhos]]
 *   ['fontface', '@font-face', corpo]
 *   ['keyframes', '@keyframes nome', corpo]
 *   ['atblock', '@page ...', corpo]
 *   ['stmt', '@import ...']
 *   ['raw', texto]
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Css_Parser {

	const GROUPS = array( 'media', 'supports', 'layer', 'container', 'document', 'scope', 'starting-style' );

	/** Remove comentários respeitando strings. */
	public static function strip_comments( $css ) {
		if ( false === strpos( $css, '/*' ) ) {
			return $css;
		}
		$len = strlen( $css );
		$out = '';
		$i   = 0;
		while ( $i < $len ) {
			$n = strcspn( $css, "\"'/", $i );
			if ( $n > 0 ) {
				$out .= substr( $css, $i, $n );
				$i   += $n;
				if ( $i >= $len ) {
					break;
				}
			}
			$c = $css[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$end  = self::string_end( $css, $i, $len );
				$out .= substr( $css, $i, $end - $i );
				$i    = $end;
			} elseif ( '/' === $c && isset( $css[ $i + 1 ] ) && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				if ( false === $end ) {
					break;
				}
				$i = $end + 2;
			} else {
				$out .= $c;
				$i++;
			}
		}
		return $out;
	}

	public static function string_end( $s, $i, $len ) {
		$q = $s[ $i ];
		$j = $i + 1;
		while ( $j < $len ) {
			$ch = $s[ $j ];
			if ( '\\' === $ch ) {
				$j += 2;
				continue;
			}
			if ( $ch === $q || "\n" === $ch ) {
				return $j + 1;
			}
			$j++;
		}
		return $len;
	}

	/** Converte CSS (já sem comentários) em nós. */
	public static function parse( $css ) {
		$nodes = array();
		$len   = strlen( $css );
		$i     = 0;
		while ( $i < $len ) {
			$i += strspn( $css, " \t\r\n\f", $i );
			if ( $i >= $len ) {
				break;
			}
			list( $pos, $ch ) = self::prelude_end( $css, $i, $len );
			if ( -1 === $pos ) {
				$rest = trim( substr( $css, $i ) );
				if ( '' !== $rest ) {
					$nodes[] = array( 'raw', $rest );
				}
				break;
			}
			$prelude = trim( substr( $css, $i, $pos - $i ) );
			if ( ';' === $ch ) {
				if ( '' !== $prelude ) {
					$nodes[] = array( 'stmt', $prelude );
				}
				$i = $pos + 1;
				continue;
			}
			if ( '}' === $ch ) {
				$i = $pos + 1; // Chave sobrando: ignora.
				continue;
			}
			$close = self::block_end( $css, $pos, $len );
			$end   = -1 === $close ? $len : $close;
			$body  = substr( $css, $pos + 1, $end - $pos - 1 );
			$i     = $end + 1;

			if ( '' !== $prelude && '@' === $prelude[0] ) {
				$name = preg_match( '/^@([\w-]+)/', $prelude, $m ) ? strtolower( $m[1] ) : '';
				$base = (string) preg_replace( '/^-[a-z]+-/', '', $name );
				if ( in_array( $base, self::GROUPS, true ) ) {
					$nodes[] = array( 'group', $prelude, self::parse( $body ) );
				} elseif ( 'font-face' === $base ) {
					$nodes[] = array( 'fontface', $prelude, $body );
				} elseif ( 'keyframes' === $base ) {
					$nodes[] = array( 'keyframes', $prelude, $body );
				} else {
					$nodes[] = array( 'atblock', $prelude, $body );
				}
			} else {
				$nodes[] = array( 'rule', $prelude, $body );
			}
		}
		return $nodes;
	}

	/** Procura o fim do seletor/prelúdio: "{", ";" ou "}" fora de strings e parênteses. */
	private static function prelude_end( $css, $i, $len ) {
		$depth = 0;
		while ( $i < $len ) {
			$i += strcspn( $css, "\"'(){};", $i );
			if ( $i >= $len ) {
				break;
			}
			$c = $css[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$i = self::string_end( $css, $i, $len );
				continue;
			}
			if ( '(' === $c ) {
				$depth++;
			} elseif ( ')' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( 0 === $depth ) {
				return array( $i, $c );
			}
			$i++;
		}
		return array( -1, '' );
	}

	/** Posição do "}" que fecha o bloco aberto em $open. */
	private static function block_end( $css, $open, $len ) {
		$depth = 0;
		$i     = $open;
		while ( $i < $len ) {
			$i += strcspn( $css, "\"'{}", $i );
			if ( $i >= $len ) {
				break;
			}
			$c = $css[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$i = self::string_end( $css, $i, $len );
				continue;
			}
			if ( '{' === $c ) {
				$depth++;
			} else {
				$depth--;
				if ( 0 === $depth ) {
					return $i;
				}
			}
			$i++;
		}
		return -1;
	}

	/** Converte os nós de volta em CSS. */
	public static function render( array $nodes ) {
		$out = '';
		foreach ( $nodes as $n ) {
			switch ( $n[0] ) {
				case 'rule':
				case 'fontface':
				case 'keyframes':
				case 'atblock':
					$out .= $n[1] . '{' . trim( $n[2] ) . '}';
					break;
				case 'group':
					$inner = self::render( $n[2] );
					if ( '' !== $inner ) {
						$out .= $n[1] . '{' . $inner . '}';
					}
					break;
				case 'stmt':
					$out .= $n[1] . ';';
					break;
				case 'raw':
					$out .= $n[1];
					break;
			}
		}
		return $out;
	}

	/** Divide uma lista de seletores pelas vírgulas de primeiro nível. */
	public static function split_selectors( $sel ) {
		$out   = array();
		$depth = 0;
		$cur   = '';
		$len   = strlen( $sel );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $sel[ $i ];
			if ( '"' === $c || "'" === $c ) {
				$end  = self::string_end( $sel, $i, $len );
				$cur .= substr( $sel, $i, $end - $i );
				$i    = $end - 1;
				continue;
			}
			if ( '(' === $c || '[' === $c ) {
				$depth++;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( ',' === $c && 0 === $depth ) {
				$out[] = trim( $cur );
				$cur   = '';
				continue;
			}
			$cur .= $c;
		}
		if ( '' !== trim( $cur ) ) {
			$out[] = trim( $cur );
		}
		return $out;
	}
}
