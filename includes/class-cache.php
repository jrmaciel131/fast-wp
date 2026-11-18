<?php
/**
 * Page cache: captures the HTML, optimizes, stores, purges and preloads.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Cache {

	const DROPIN_MARK   = 'FAST_WP_DROPIN';
	const CONFIG_MARK   = '// Fast WP';
	const PRELOAD_QUEUE = 'fast_wp_preload_queue';
	const LAST_PURGE    = 'fast_wp_last_purge';

	/** @var array|null Cache target of the current request. */
	private static $target = null;

	/** @var bool */
	private static $optimize = false;

	/** @var string */
	private static $buffer = '';

	/** Decides on template_redirect whether this page is optimized and/or cached. */
	public static function start() {
		if ( defined( 'FAST_WP_DISABLE' ) && FAST_WP_DISABLE ) {
			return;
		}
		if ( ! self::is_frontend_page() ) {
			return;
		}

		$s          = Settings::all();
		$test_param = isset( $_GET['fwp_test'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$off_param  = isset( $_GET['fwp_off'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $off_param ) {
			return;
		}

		if ( empty( $s['safe_mode'] ) ) {
			if ( ! empty( $s['test_mode'] ) ) {
				self::$optimize = $test_param;
			} else {
				self::$optimize = $test_param || ! is_user_logged_in();
			}
		}

		self::$target = null;
		if ( ! empty( $s['cache_enabled'] ) && ! $test_param && self::page_is_cacheable() ) {
			self::$target = Request::cache_target( Settings::cache_config(), $_SERVER, $_COOKIE );
		}

		if ( self::$optimize || self::$target ) {
			if ( self::$target && ! headers_sent() ) {
				header( 'X-Fast-WP: MISS' );
			}
			ob_start( array( __CLASS__, 'buffer' ) );
		}
	}

	/** Callback do buffer: junta todo o HTML e processa no final. */
	public static function buffer( $chunk, $phase ) {
		self::$buffer .= $chunk;
		if ( ! ( $phase & PHP_OUTPUT_HANDLER_FINAL ) && ! ( $phase & PHP_OUTPUT_HANDLER_END ) ) {
			return '';
		}
		$html         = self::$buffer;
		self::$buffer = '';
		return self::finish( $html );
	}

	private static function finish( $html ) {
		if ( ! Html::is_page( $html ) || ! self::response_is_html() ) {
			return $html;
		}
		if ( self::$optimize ) {
			$opt  = new Optimizer();
			$html = $opt->process( $html );
		}
		if ( self::$target && ! ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) && 200 === http_response_code() ) {
			$stamp = "\n<!-- fwp: cached at " . gmdate( 'Y-m-d H:i:s' ) . ' UTC -->';
			Request::store( self::$target, $html . $stamp );
		}
		return $html;
	}

	private static function response_is_html() {
		foreach ( headers_list() as $h ) {
			if ( 0 === stripos( $h, 'location:' ) ) {
				return false;
			}
			if ( 0 === stripos( $h, 'content-type:' ) && false === stripos( $h, 'text/html' ) ) {
				return false;
			}
		}
		return true;
	}

	/** Front-end pages only (not admin, AJAX or the Elementor editor). */
	private static function is_frontend_page() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		if ( is_feed() || is_robots() || is_trackback() || is_embed() || is_preview() || is_customize_preview() ) {
			return false;
		}
		if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) || isset( $_GET['ver_preview'] ) ) { // phpcs:ignore
			return false;
		}
		if ( function_exists( 'amp_is_request' ) && amp_is_request() ) {
			return false;
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->preview ) && \Elementor\Plugin::$instance->preview->is_preview_mode() ) {
			return false;
		}
		return isset( $_SERVER['REQUEST_METHOD'] ) && in_array( strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ), array( 'GET', 'HEAD' ), true );
	}

	/** WordPress rules for caching. */
	private static function page_is_cacheable() {
		if ( is_user_logged_in() || is_404() || is_search() || post_password_required() ) {
			return false;
		}
		if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
			return false;
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Limpeza
	 * ------------------------------------------------------------------- */

	public static function purge_all() {
		$dir = FAST_WP_CACHE_DIR . '/pages';
		if ( is_dir( $dir ) ) {
			// Rename before deleting: the site never sees a half-deleted cache.
			$trash = FAST_WP_CACHE_DIR . '/trash-' . uniqid();
			if ( @rename( $dir, $trash ) ) { // phpcs:ignore
				self::rrmdir( $trash );
			} else {
				self::rrmdir( $dir );
			}
		}
		update_option( self::LAST_PURGE, time(), false );
		Htaccess::write_pages_htaccess(); // Recreates the headers of the cache folder.
		if ( Settings::get( 'cache_preload' ) ) {
			self::schedule_preload( 30 );
		}
		do_action( 'fast_wp_purged_all' );
	}

	/** Purges the cache of one URL (all variants: mobile, https). */
	public static function purge_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return;
		}
		$target = Request::target_for( FAST_WP_CACHE_DIR, strtolower( $parts['host'] ), isset( $parts['path'] ) ? $parts['path'] : '/', true, false );
		foreach ( (array) glob( $target['dir'] . '/index*.html*' ) as $file ) {
			@unlink( $file ); // phpcs:ignore
		}
	}

	/** Called when a post changes. */
	public static function on_post_change( $post_id ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'nav_menu_item' === $post->post_type ) {
			return;
		}
		$type = get_post_type_object( $post->post_type );
		$is_template = in_array( $post->post_type, array( 'elementor_library', 'wp_template', 'wp_template_part', 'wp_block' ), true );
		if ( ! $is_template && ( ! $type || ! $type->public ) ) {
			return;
		}
		if ( $is_template || Settings::get( 'cache_purge_all' ) ) {
			self::purge_all();
			return;
		}
		self::purge_url( get_permalink( $post ) );
		self::purge_url( home_url( '/' ) );
		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			self::purge_url( $archive );
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$terms = get_the_terms( $post, $tax );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						self::purge_url( $link );
					}
				}
			}
		}
		if ( Settings::get( 'cache_preload' ) ) {
			self::schedule_preload( 30 );
		}
	}

	public static function on_transition( $new, $old, $post ) {
		if ( 'publish' === $new || 'publish' === $old ) {
			self::on_post_change( $post->ID );
		}
	}

	public static function on_comment( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && '1' === (string) $comment->comment_approved ) {
			self::purge_url( get_permalink( (int) $comment->comment_post_ID ) );
		}
	}

	/** Removes expired cache files and optimized files that are no longer used. */
	public static function cleanup_expired() {
		$life = (int) Settings::get( 'cache_lifespan' ) * HOUR_IN_SECONDS;
		$now  = time();
		if ( $life > 0 && is_dir( FAST_WP_CACHE_DIR . '/pages' ) ) {
			$expired = false;
			$it      = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( FAST_WP_CACHE_DIR . '/pages', \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() && $file->getMTime() + $life < $now ) {
					@unlink( $file->getPathname() ); // phpcs:ignore
					$expired = true;
				}
			}
			if ( $expired && Settings::get( 'cache_preload' ) ) {
				self::schedule_preload( 10 );
			}
		}

		// Optimized files: every page generated after the last purge "touches" the files it uses.
		$limit = (int) get_option( self::LAST_PURGE, 0 );
		if ( $life > 0 ) {
			$limit = max( $limit, $now - $life );
		}
		$limit -= 2 * DAY_IN_SECONDS;
		if ( $limit > 0 && is_dir( Assets::dir() ) ) {
			foreach ( array( 'css', 'js', 'memo' ) as $sub ) {
				foreach ( (array) glob( Assets::dir( $sub ) . '/*' ) as $file ) {
					if ( is_file( $file ) && filemtime( $file ) < $limit ) {
						@unlink( $file ); // phpcs:ignore
					}
				}
			}
		}
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore
		}
		@rmdir( $dir ); // phpcs:ignore
	}

	/** Removes every generated file (pages and optimized files). */
	public static function purge_everything() {
		self::purge_all();
		self::rrmdir( Assets::dir() );
	}

	/* ---------------------------------------------------------------------
	 * Preloading
	 * ------------------------------------------------------------------- */

	public static function schedule_preload( $delay = 30 ) {
		if ( ! Settings::get( 'cache_enabled' ) || ! Settings::get( 'cache_preload' ) ) {
			return;
		}
		update_option( self::PRELOAD_QUEUE, self::preload_urls(), false );
		wp_clear_scheduled_hook( 'fast_wp_preload' );
		wp_schedule_single_event( time() + $delay, 'fast_wp_preload' );
	}

	/** URLs to preload: home page, published pages and posts. */
	public static function preload_urls() {
		$urls  = array( home_url( '/' ) );
		$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment', 'elementor_library', 'e-landing-page' ) ) );
		$types[] = 'e-landing-page';
		$ids   = get_posts(
			array(
				'post_type'      => $types,
				'post_status'    => 'publish',
				'posts_per_page' => 300,
				'fields'         => 'ids',
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'no_found_rows'  => true,
				'has_password'   => false,
			)
		);
		foreach ( $ids as $id ) {
			$urls[] = get_permalink( $id );
		}
		$exclude = Settings::lines( 'cache_exclude_urls' );
		$urls    = array_filter(
			array_unique( $urls ),
			function ( $u ) use ( $exclude ) {
				return $u && ! Assets::matches( $u, $exclude );
			}
		);
		return array_values( $urls );
	}

	/** Processes one batch of the queue (called by WP-Cron). */
	public static function run_preload() {
		$queue = get_option( self::PRELOAD_QUEUE, array() );
		if ( ! is_array( $queue ) || ! $queue ) {
			return;
		}
		$batch = array_splice( $queue, 0, 8 );
		update_option( self::PRELOAD_QUEUE, $queue, false );

		$agents = array( 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36 FastWP-Preload' );
		if ( Settings::get( 'cache_mobile_separate' ) ) {
			$agents[] = 'Mozilla/5.0 (Linux; Android 13) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36 FastWP-Preload';
		}
		foreach ( $batch as $url ) {
			foreach ( $agents as $ua ) {
				wp_remote_get(
					$url,
					array(
						'timeout'    => 20,
						'user-agent' => $ua,
						'cookies'    => array(),
						'headers'    => array( 'Accept' => 'text/html' ),
					)
				);
			}
		}
		if ( $queue ) {
			wp_schedule_single_event( time() + 20, 'fast_wp_preload' );
		}
	}

	/* ---------------------------------------------------------------------
	 * advanced-cache.php e WP_CACHE
	 * ------------------------------------------------------------------- */

	public static function dropin_path() {
		return WP_CONTENT_DIR . '/advanced-cache.php';
	}

	/** True se existe um advanced-cache.php de outro plugin. */
	public static function foreign_dropin() {
		$file = self::dropin_path();
		if ( ! is_file( $file ) ) {
			return false;
		}
		$code = (string) file_get_contents( $file ); // phpcs:ignore
		// An empty file (left over by a removed plugin) can be replaced.
		if ( '' === trim( $code ) || '<?php' === trim( $code ) ) {
			return false;
		}
		return false === strpos( $code, self::DROPIN_MARK );
	}

	public static function install_dropin( $force = false ) {
		if ( self::foreign_dropin() && ! $force ) {
			return false;
		}
		$code = (string) file_get_contents( FAST_WP_DIR . 'includes/advanced-cache-template.php' ); // phpcs:ignore
		if ( '' === $code ) {
			return false;
		}
		Settings::write_config();
		return false !== @file_put_contents( self::dropin_path(), $code ); // phpcs:ignore
	}

	public static function remove_dropin() {
		if ( is_file( self::dropin_path() ) && ! self::foreign_dropin() ) {
			@unlink( self::dropin_path() ); // phpcs:ignore
		}
	}

	public static function wp_config_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		return ( file_exists( $up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) ? $up : '';
	}

	/** Liga ou desliga a constante WP_CACHE no wp-config.php. */
	public static function set_wp_cache( $on ) {
		$file = self::wp_config_path();
		if ( ! $file || ! is_writable( $file ) ) {
			return false;
		}
		$code = (string) file_get_contents( $file ); // phpcs:ignore
		if ( '' === $code ) {
			return false;
		}
		$line = "define( 'WP_CACHE', true ); " . self::CONFIG_MARK;
		if ( $on ) {
			if ( preg_match( "/define\s*\(\s*['\"]WP_CACHE['\"]\s*,\s*true\s*\)/i", $code ) ) {
				return true;
			}
			$code = preg_replace( "/^.*define\s*\(\s*['\"]WP_CACHE['\"].*$\R?/mi", '', $code );
			$code = preg_replace( '/^<\?php\s*/', "<?php\n" . $line . "\n", $code, 1 );
		} else {
			$code = preg_replace( '/^.*' . preg_quote( self::CONFIG_MARK, '/' ) . '.*\R?/m', '', $code );
		}
		if ( false === strpos( $code, 'wp-settings.php' ) ) {
			return false; // Unexpected content: do not write.
		}
		return false !== file_put_contents( $file, $code, LOCK_EX ); // phpcs:ignore
	}

	public static function wp_cache_active() {
		return defined( 'WP_CACHE' ) && WP_CACHE;
	}
}
