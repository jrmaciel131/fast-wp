<?php
/**
 * Helpers to change HTML without breaking the markup.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Html {

	/** @var array */
	private $saved = array();

	/** @var int Global counter: each instance creates different placeholders (avoids swapping the wrong block). */
	private static $counter = 0;

	/**
	 * Replaces blocks that must not change (comments, scripts...) with placeholders.
	 *
	 * @param string   $html HTML.
	 * @param string[] $what comment, script, style, noscript, textarea, template, pre.
	 */
	public function protect( $html, array $what ) {
		$patterns = array(
			'comment'  => '#<!--.*?-->#s',
			'script'   => '#<script\b[^>]*>.*?</script\s*>#is',
			'style'    => '#<style\b[^>]*>.*?</style\s*>#is',
			'noscript' => '#<noscript\b[^>]*>.*?</noscript\s*>#is',
			'textarea' => '#<textarea\b[^>]*>.*?</textarea\s*>#is',
			'template' => '#<template\b[^>]*>.*?</template\s*>#is',
			'pre'      => '#<pre\b[^>]*>.*?</pre\s*>#is',
		);
		foreach ( $what as $w ) {
			if ( ! isset( $patterns[ $w ] ) ) {
				continue;
			}
			$html = preg_replace_callback(
				$patterns[ $w ],
				function ( $m ) {
					$key                 = "\x1Afwp" . ( ++self::$counter ) . "\x1A";
					$this->saved[ $key ] = $m[0];
					return $key;
				},
				$html
			);
		}
		return $html;
	}

	/** Puts the protected blocks back. */
	public function restore( $html ) {
		// Restore in several passes to handle nested blocks.
		for ( $i = 0; $i < 3 && false !== strpos( $html, "\x1Afwp" ); $i++ ) {
			$html = strtr( $html, $this->saved );
		}
		$this->saved = array();
		return $html;
	}

	/** Reads the attributes of a tag. Attributes without a value become true. */
	public static function attrs( $tag ) {
		$tag = preg_replace( '#^<[a-zA-Z][a-zA-Z0-9-]*#', '', $tag );
		$tag = preg_replace( '#/?>$#', '', $tag );
		$out = array();
		if ( preg_match_all( '/([^\s=\/>"\']+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>"\']+)))?/', (string) $tag, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $a ) {
				$name = strtolower( $a[1] );
				if ( isset( $a[4] ) && '' !== $a[4] ) {
					$out[ $name ] = $a[4];
				} elseif ( isset( $a[3] ) && '' !== $a[3] ) {
					$out[ $name ] = $a[3];
				} elseif ( isset( $a[2] ) && ( '' !== $a[2] || false !== strpos( $a[0], '=' ) ) ) {
					$out[ $name ] = $a[2];
				} elseif ( false !== strpos( $a[0], '=' ) ) {
					$out[ $name ] = '';
				} else {
					$out[ $name ] = true; // Attribute without a value (async, defer...).
				}
			}
		}
		return $out;
	}

	/** Builds an opening tag from attributes. */
	public static function tag( $name, array $attrs, $self_close = false ) {
		$out = '<' . $name;
		foreach ( $attrs as $k => $v ) {
			if ( false === $v ) {
				continue;
			}
			$out .= ( null === $v || true === $v ) ? ' ' . $k : ' ' . $k . '="' . str_replace( '"', '&quot;', (string) $v ) . '"';
		}
		return $out . ( $self_close ? ' />' : '>' );
	}

	/** Decoded attribute value (&amp; becomes &). */
	public static function decoded( array $attrs, $name ) {
		return isset( $attrs[ $name ] ) ? html_entity_decode( (string) $attrs[ $name ], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
	}

	/** Inserts code right after the opening <head>. */
	public static function after_head_open( $html, $code ) {
		if ( '' === $code ) {
			return $html;
		}
		if ( preg_match( '#<head\b[^>]*>#i', $html, $m, PREG_OFFSET_CAPTURE ) ) {
			$pos = $m[0][1] + strlen( $m[0][0] );
			// Keeps <meta charset> as the first thing in the head.
			if ( preg_match( '#\G\s*<meta\s+charset=[^>]*>#i', $html, $c, 0, $pos ) ) {
				$pos += strlen( $c[0] );
			}
			return substr( $html, 0, $pos ) . $code . substr( $html, $pos );
		}
		return $code . $html;
	}

	/** Inserts code before the closing </head>. */
	public static function before_head_close( $html, $code ) {
		if ( '' === $code ) {
			return $html;
		}
		$pos = stripos( $html, '</head>' );
		return false === $pos ? $code . $html : substr( $html, 0, $pos ) . $code . substr( $html, $pos );
	}

	/** Inserts code before the last </body>. */
	public static function before_body_close( $html, $code ) {
		if ( '' === $code ) {
			return $html;
		}
		$pos = strripos( $html, '</body>' );
		return false === $pos ? $html . $code : substr( $html, 0, $pos ) . $code . substr( $html, $pos );
	}

	/** True when the text looks like a complete HTML page. */
	public static function is_page( $html ) {
		return strlen( $html ) > 255
			&& false !== stripos( $html, '<html' )
			&& false !== stripos( $html, '</html>' )
			&& ! preg_match( '#^\s*<\?xml#i', $html );
	}
}
