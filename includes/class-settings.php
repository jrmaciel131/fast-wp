<?php
/**
 * Settings: fields, defaults, presets and the config file read by advanced-cache.php.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Settings {

	const OPTION = 'fast_wp_settings';

	/** @var array|null */
	private static $cache = null;

	/** URL parameters that do not prevent caching (paid traffic and email). */
	const IGNORE_PARAMS = "utm_*\ngclid\ngbraid\nwbraid\nfbclid\nmsclkid\nttclid\ntwclid\nli_fat_id\ndclid\n_ga\n_gl\nmc_cid\nmc_eid\nref\nsrsltid";

	/**
	 * Every tab and field. The settings screen is built from this list.
	 * Field format: [ type, default, label, description, (select options) ].
	 */
	public static function fields() {
		return array(
			'geral'      => array(
				'title'  => t( 'General' ),
				'fields' => array(
					'test_mode'             => array(
						'select',
						1,
						t( 'Mode' ),
						t( 'In test mode only you see the optimized site (with ?fwp_test=1). Switch to live when the tests look right.' ),
						array(
							1 => t( 'Test: visitors do NOT get the optimizations' ),
							0 => t( 'Live: optimizations active for every visitor' ),
						),
					),
					'optimize_exclude_urls' => array( 'textarea', '', t( 'Do not optimize these URLs' ), t( 'One URL fragment per line, for example /checkout/. These pages keep the cache but skip CSS, JS and image optimizations. Use it if one page looks broken.' ) ),
					'language'              => array(
						'select',
						'auto',
						t( 'Plugin language' ),
						t( 'Language of the Fast WP screens.' ),
						array_merge( array( 'auto' => t( 'Automatic (WordPress language)' ) ), I18n::LANGUAGES ),
					),
					'auto_update'           => array( 'checkbox', 1, t( 'Update the plugin automatically' ), t( 'New versions are installed automatically within about a day.' ) ),
					'update_channel'        => array(
						'select',
						'stable',
						t( 'Update channel' ),
						t( 'Use "Beta" on a test site to receive new versions before the other sites.' ),
						array(
							'stable' => t( 'Stable' ),
							'beta'   => t( 'Beta (receives pre-releases)' ),
						),
					),
				),
			),
			'cache'      => array(
				'title'  => t( 'Cache' ),
				'fields' => array(
					'cache_enabled'         => array( 'checkbox', 1, t( 'Enable page cache' ), t( 'Stores the finished HTML and serves it without loading WordPress.' ) ),
					'cache_htaccess'        => array( 'checkbox', 1, t( 'Serve the cache directly from Apache (.htaccess)' ), t( 'Faster. If the server is not Apache/LiteSpeed, the cache is served by PHP automatically.' ) ),
					'cache_lifespan'        => array( 'number', 10, t( 'Cache lifetime (hours)' ), t( '0 = never expires. 10 hours is safe for Elementor forms.' ) ),
					'cache_preload'         => array( 'checkbox', 1, t( 'Preload the cache for the whole site' ), t( 'Builds the cache of published pages and posts in the background.' ) ),
					'cache_purge_all'       => array( 'checkbox', 1, t( 'Clear the whole cache when any page is updated' ), t( 'Recommended for Elementor (header and footer appear on every page).' ) ),
					'cache_mobile_separate' => array( 'checkbox', 0, t( 'Separate cache for mobile' ), t( 'Only needed if the theme outputs different HTML on mobile. Elementor does not.' ) ),
					'browser_cache'         => array( 'checkbox', 1, t( 'Browser caching' ), t( 'Images, CSS, JS and fonts are kept in the visitor\'s browser for 1 year.' ) ),
					'gzip'                  => array( 'checkbox', 1, t( 'GZIP compression' ), t( 'Reduces the size of the files sent.' ) ),
					'cache_ignore_params'   => array( 'textarea', self::IGNORE_PARAMS, t( 'Ignored URL parameters' ), t( 'One per line. URLs with these parameters (ads) still get the cache. Use * at the end for a prefix.' ) ),
					'cache_exclude_urls'    => array( 'textarea', '', t( 'Never cache these URLs' ), t( 'One URL fragment per line, for example /thank-you/' ) ),
					'cache_exclude_cookies' => array( 'textarea', '', t( 'Never use the cache with these cookies' ), t( 'One cookie prefix per line.' ) ),
				),
			),
			'arquivos'   => array(
				'title'  => t( 'CSS and HTML' ),
				'fields' => array(
					'minify_html'            => array( 'checkbox', 1, t( 'Minify HTML' ), t( 'Removes comments and extra spaces from the HTML.' ) ),
					'minify_css'             => array( 'checkbox', 1, t( 'Minify CSS' ), t( 'Creates smaller copies of the site\'s CSS files.' ) ),
					'used_css'               => array( 'checkbox', 1, t( 'Remove unused CSS' ), t( 'Each page gets only the CSS it uses, inside the HTML. Fixes "Reduce unused CSS" and "Eliminate render-blocking resources".' ) ),
					'used_css_fallback'      => array(
						'select',
						'interaction',
						t( 'Load the full CSS later' ),
						t( 'When to load the original CSS, as a safety net for menus, popups and effects.' ),
						array(
							'interaction' => t( 'On the visitor\'s first interaction (recommended)' ),
							'load'        => t( 'Right after the page loads' ),
							'none'        => t( 'Never (maximum, riskier)' ),
						),
					),
					'used_css_inline_styles' => array( 'checkbox', 1, t( 'Also clean large CSS blocks inside the HTML' ), t( '<style> blocks larger than 8 KB.' ) ),
					'used_css_safelist'      => array( 'textarea', '', t( 'Classes that must never be removed' ), t( 'One per line. Use * at the end for a prefix (e.g. my-menu-*) or /regex/.' ) ),
				),
			),
			'javascript' => array(
				'title'  => t( 'JavaScript' ),
				'fields' => array(
					'delay_js'             => array( 'checkbox', 1, t( 'Delay JavaScript until interaction' ), t( 'Scripts only load when the visitor taps, scrolls or moves the mouse. This raises the mobile score the most.' ) ),
					'delay_js_trackers'    => array( 'checkbox', 0, t( 'Also delay pixels and GTM (Meta, Google, TikTok, Clarity)' ), t( 'Raises the score, but the PageView only fires after the first interaction. Leave it off if ad tracking is the priority.' ) ),
					'delay_js_timeout'     => array( 'number', 0, t( 'Load even without interaction after (seconds)' ), t( '0 = only on interaction.' ) ),
					'elementor_animations' => array( 'checkbox', 1, t( 'Show elements with entrance animations right away' ), t( 'Without this, Elementor elements with animations stay invisible until the JS loads.' ) ),
					'delay_exclude'        => array( 'textarea', '', t( 'Never delay (script fragments)' ), t( 'One per line: part of the file URL or of the script code.' ) ),
					'defer_js'             => array( 'checkbox', 1, t( 'Defer JavaScript' ), t( 'Scripts that are not delayed stop blocking the page.' ) ),
					'defer_jquery'         => array( 'checkbox', 1, t( 'Also defer jQuery' ), t( 'Inline scripts that use jQuery are deferred too, in the same order.' ) ),
					'defer_exclude'        => array( 'textarea', '', t( 'Never defer (script fragments)' ), t( 'One per line.' ) ),
					'minify_js'            => array( 'checkbox', 0, t( 'Minify JS' ), t( 'Only files that are not .min.js yet. Small gain; leave it off if something breaks.' ) ),
				),
			),
			'midia'      => array(
				'title'  => t( 'Images and videos' ),
				'fields' => array(
					'lazy_images'      => array( 'checkbox', 1, t( 'Lazy load images' ), t( 'Images outside the screen only load when the visitor reaches them.' ) ),
					'lazy_skip'        => array( 'number', 3, t( 'Skip lazy load for the first images' ), t( 'How many images at the top load right away (logo and banner).' ) ),
					'image_dimensions' => array( 'checkbox', 1, t( 'Add missing image width and height' ), t( 'Reads the size of the image file so the browser reserves its space. Stops the page from jumping while images load (CLS), common in Elementor carousels.' ) ),
					'lazy_iframes'     => array( 'checkbox', 1, t( 'Lazy load iframes (maps, videos)' ), '' ),
					'youtube_facade'   => array( 'checkbox', 1, t( 'Replace YouTube videos with a thumbnail' ), t( 'The player only loads on click. Saves about 500 KB per video.' ) ),
					'lazy_bg'          => array( 'checkbox', 1, t( 'Lazy load background images written in the HTML' ), t( 'For Elementor backgrounds, also enable "Lazy Load Background Images" in Elementor > Settings > Features.' ) ),
					'lcp_priority'     => array( 'checkbox', 1, t( 'Prioritize the main image (LCP)' ), t( 'The first large content image gets high priority and is preloaded.' ) ),
					'lcp_preload_urls' => array( 'textarea', '', t( 'Preload these images on every page' ), t( 'One URL per line. Useful when the banner is a background image.' ) ),
					'webp_convert'     => array( 'checkbox', 1, t( 'Create WebP copies of uploaded images' ), t( 'Done on your own server, with no external service.' ) ),
					'webp_serve'       => array( 'checkbox', 1, t( 'Use the WebP copies on the site' ), t( 'Swaps JPG/PNG for the WebP copy when it exists and is smaller.' ) ),
				),
			),
			'fontes'     => array(
				'title'  => t( 'Fonts' ),
				'fields' => array(
					'fonts_google_local' => array( 'checkbox', 1, t( 'Host Google Fonts on your own site' ), t( 'Downloads the fonts once and serves them from your domain. Removes the extra connection to Google.' ) ),
					'fonts_swap'         => array( 'checkbox', 1, t( 'Visible text while the font loads (font-display: swap)' ), t( 'Fixes "Ensure text remains visible".' ) ),
					'fonts_preload'      => array( 'checkbox', 0, t( 'Preload the main font' ), t( 'Preloads the regular (400) weight of self-hosted Google Fonts. It competes with the main image, so it is off by default.' ) ),
				),
			),
			'limpeza'    => array(
				'title'  => t( 'Cleanup' ),
				'fields' => array(
					'cleanup_emojis'         => array( 'checkbox', 1, t( 'Disable WordPress emojis' ), t( 'Removes the emoji script and CSS (browsers already show emojis).' ) ),
					'cleanup_embeds'         => array( 'checkbox', 1, t( 'Disable WordPress embeds' ), t( 'Removes wp-embed.js.' ) ),
					'cleanup_head'           => array( 'checkbox', 1, t( 'Clean the head' ), t( 'Removes RSD, wlwmanifest, shortlink and the WordPress version.' ) ),
					'cleanup_xmlrpc'         => array( 'checkbox', 1, t( 'Disable XML-RPC' ), t( 'Closes a door used in attacks. Turn it off if you use the WordPress app or Jetpack.' ) ),
					'cleanup_dashicons'      => array( 'checkbox', 0, t( 'Remove Dashicons for visitors' ), t( 'Dashboard icons that some themes load without needing them.' ) ),
					'cleanup_block_css'      => array( 'checkbox', 0, t( 'Remove Gutenberg CSS on Elementor pages' ), '' ),
					'cleanup_jquery_migrate' => array( 'checkbox', 0, t( 'Remove jQuery Migrate' ), t( 'May break old plugins.' ) ),
					'heartbeat'              => array(
						'select',
						'reduce',
						t( 'WordPress Heartbeat' ),
						t( 'Automatic requests made by the dashboard.' ),
						array(
							'default' => t( 'WordPress default' ),
							'reduce'  => t( 'Reduce to 60 seconds' ),
							'front'   => t( 'Disable on the site and reduce in the dashboard' ),
						),
					),
				),
			),
			'cdn'        => array(
				'title'  => t( 'CDN' ),
				'fields' => array(
					'cdn_enabled' => array( 'checkbox', 0, t( 'Use a CDN' ), t( 'Rewrites image, CSS, JS and font URLs to the CDN address.' ) ),
					'cdn_url'     => array( 'text', '', t( 'CDN address' ), t( 'E.g. https://cdn.yoursite.com' ) ),
				),
			),
			'banco'      => array(
				'title'  => t( 'Database' ),
				'fields' => array(
					'db_weekly' => array( 'checkbox', 0, t( 'Clean the database automatically every week' ), t( 'Revisions, auto-drafts, trash, spam and expired transients.' ) ),
				),
			),
		);
	}

	/** Internal values that are not shown as fields. */
	private static function internal_defaults() {
		return array(
			'safe_mode' => 0,
		);
	}

	/** Presets applied by the dashboard buttons. */
	public static function presets() {
		return array(
			'seguro'      => array(
				'label'  => t( 'Safe' ),
				'values' => array(
					'delay_js'          => 0,
					'used_css'          => 0,
					'defer_jquery'      => 0,
					'minify_js'         => 0,
					'cleanup_dashicons' => 0,
					'cleanup_block_css' => 0,
				),
			),
			'recomendado' => array(
				'label'  => t( 'Recommended' ),
				'values' => array(
					'delay_js'          => 1,
					'delay_js_trackers' => 0,
					'used_css'          => 1,
					'used_css_fallback' => 'interaction',
					'defer_js'          => 1,
					'defer_jquery'      => 1,
					'minify_js'         => 0,
					'cleanup_dashicons' => 0,
					'cleanup_block_css' => 0,
				),
			),
			'maximo'      => array(
				'label'  => t( 'Maximum' ),
				'values' => array(
					'delay_js'          => 1,
					'delay_js_trackers' => 1,
					'used_css'          => 1,
					'used_css_fallback' => 'interaction',
					'defer_js'          => 1,
					'defer_jquery'      => 1,
					'minify_js'         => 1,
					'cleanup_dashicons' => 1,
					'cleanup_block_css' => 1,
				),
			),
		);
	}

	public static function defaults() {
		$out = self::internal_defaults();
		foreach ( self::fields() as $tab ) {
			foreach ( $tab['fields'] as $key => $f ) {
				$out[ $key ] = $f[1];
			}
		}
		return $out;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Reads a text field that holds one rule per line. */
	public static function lines( $key ) {
		$raw = (string) self::get( $key );
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** Saves values (merged with the current ones) and refreshes the cache config file. */
	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, true );
		self::$cache = $all;
		I18n::reset();
		self::write_config();
	}

	/** Sanitizes the values sent by one tab of the settings screen. */
	public static function sanitize_tab( $tab, array $input ) {
		$fields = self::fields();
		if ( ! isset( $fields[ $tab ] ) ) {
			return array();
		}
		$out = array();
		foreach ( $fields[ $tab ]['fields'] as $key => $f ) {
			$type = $f[0];
			$val  = isset( $input[ $key ] ) ? wp_unslash( $input[ $key ] ) : null;
			switch ( $type ) {
				case 'checkbox':
					$out[ $key ] = empty( $val ) ? 0 : 1;
					break;
				case 'number':
					$out[ $key ] = max( 0, (int) $val );
					break;
				case 'select':
					$out[ $key ] = isset( $f[4][ $val ] ) ? ( is_int( $f[1] ) ? (int) $val : $val ) : $f[1];
					break;
				case 'textarea':
					$out[ $key ] = sanitize_textarea_field( (string) $val );
					break;
				default:
					$out[ $key ] = sanitize_text_field( (string) $val );
			}
		}
		if ( isset( $out['cdn_url'] ) ) {
			$out['cdn_url'] = untrailingslashit( esc_url_raw( $out['cdn_url'] ) );
		}
		return $out;
	}

	/** Hosts accepted by the cache (avoids creating cache files for a forged Host header). */
	public static function hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$h = wp_parse_url( $url, PHP_URL_HOST );
			if ( $h ) {
				$h       = strtolower( $h );
				$hosts[] = $h;
				$hosts[] = 0 === strpos( $h, 'www.' ) ? substr( $h, 4 ) : 'www.' . $h;
			}
		}
		return array_values( array_unique( $hosts ) );
	}

	/** Config used by advanced-cache.php (before WordPress loads). */
	public static function cache_config() {
		$s = self::all();
		return array(
			'enabled'         => ! empty( $s['cache_enabled'] ),
			'dir'             => FAST_WP_CACHE_DIR,
			'request_class'   => FAST_WP_DIR . 'includes/class-request.php',
			'hosts'           => self::hosts(),
			'lifespan'        => (int) $s['cache_lifespan'] * HOUR_IN_SECONDS,
			'mobile_separate' => ! empty( $s['cache_mobile_separate'] ),
			'ignore_params'   => self::lines( 'cache_ignore_params' ),
			'exclude_urls'    => self::lines( 'cache_exclude_urls' ),
			'exclude_cookies' => self::lines( 'cache_exclude_cookies' ),
			'charset'         => get_option( 'blog_charset', 'UTF-8' ),
		);
	}

	public static function write_config() {
		if ( ! wp_mkdir_p( FAST_WP_CACHE_DIR ) ) {
			return false;
		}
		$code = "<?php\ndefined( 'ABSPATH' ) || exit;\n// Generated by Fast WP. Do not edit: it is rewritten when settings are saved.\nreturn " . var_export( self::cache_config(), true ) . ";\n"; // phpcs:ignore
		$file = FAST_WP_CACHE_DIR . '/config.php';
		$tmp  = $file . '.tmp';
		if ( false === file_put_contents( $tmp, $code ) ) { // phpcs:ignore
			return false;
		}
		return rename( $tmp, $file );
	}

	/** Signature of the settings that change optimization output (used in generated file names). */
	public static function signature() {
		$s    = self::all();
		$keys = array( 'minify_css', 'fonts_swap', 'webp_serve', 'cdn_enabled', 'cdn_url', 'minify_js' );
		$sig  = array();
		foreach ( $keys as $k ) {
			$sig[ $k ] = isset( $s[ $k ] ) ? $s[ $k ] : '';
		}
		return substr( md5( FAST_WP_VERSION . wp_json_encode( $sig ) ), 0, 8 );
	}

	/** Used by the automated tests. */
	public static function reset_cache() {
		self::$cache = null;
		I18n::reset();
	}
}
