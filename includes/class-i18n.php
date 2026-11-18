<?php
/**
 * Lightweight translations.
 *
 * Source strings are English. Translations live in languages/{locale}.php as
 * [ 'English text' => 'Translated text' ]. The language comes from the plugin
 * setting ("auto" follows the WordPress/user language).
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class I18n {

	/** Languages shipped with the plugin. */
	const LANGUAGES = array(
		'en_US' => 'English',
		'pt_BR' => 'Português (Brasil)',
	);

	/** @var string|null */
	private static $lang = null;

	/** @var array|null */
	private static $strings = null;

	/** Active language code (en_US or pt_BR). */
	public static function lang() {
		if ( null === self::$lang ) {
			// Read the option directly: Settings::all() itself uses translated labels.
			$opt    = get_option( 'fast_wp_settings', array() );
			$choice = is_array( $opt ) && ! empty( $opt['language'] ) ? $opt['language'] : 'auto';
			if ( ! isset( self::LANGUAGES[ $choice ] ) ) {
				$locale = 'en_US';
				if ( function_exists( 'is_admin' ) && is_admin() && function_exists( 'get_user_locale' ) && did_action( 'set_current_user' ) ) {
					$locale = get_user_locale();
				} elseif ( function_exists( 'determine_locale' ) ) {
					$locale = determine_locale();
				}
				$choice = 0 === strpos( (string) $locale, 'pt' ) ? 'pt_BR' : 'en_US';
			}
			self::$lang = $choice;
		}
		return self::$lang;
	}

	/** Translates an English source string. */
	public static function translate( $text ) {
		$lang = self::lang();
		if ( 'en_US' === $lang ) {
			return $text;
		}
		if ( null === self::$strings ) {
			$file          = dirname( __DIR__ ) . '/languages/' . $lang . '.php';
			$data          = is_file( $file ) ? include $file : array();
			self::$strings = is_array( $data ) ? $data : array();
		}
		return isset( self::$strings[ $text ] ) ? self::$strings[ $text ] : $text;
	}

	/** Forgets the cached language (after the setting changes). */
	public static function reset() {
		self::$lang    = null;
		self::$strings = null;
	}
}

/**
 * Translates a string. Extra arguments are passed to sprintf.
 *
 * @param string $text English source text.
 * @param mixed  ...$args Values for sprintf placeholders.
 * @return string
 */
function t( $text, ...$args ) {
	$out = I18n::translate( $text );
	return $args ? vsprintf( $out, $args ) : $out;
}
