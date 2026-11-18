<?php
/**
 * Settings screen, notices, admin bar and button actions.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Admin {

	const PAGE = 'fast-wp';

	/** Plugins that do the same job and conflict with Fast WP. */
	const CONFLICTS = array(
		'wp-fastest-cache/wpFastestCache.php'        => 'WP Fastest Cache',
		'wp-rocket/wp-rocket.php'                    => 'WP Rocket',
		'litespeed-cache/litespeed-cache.php'        => 'LiteSpeed Cache',
		'w3-total-cache/w3-total-cache.php'          => 'W3 Total Cache',
		'wp-super-cache/wp-cache.php'                => 'WP Super Cache',
		'autoptimize/autoptimize.php'                => 'Autoptimize',
		'perfmatters/perfmatters.php'                => 'Perfmatters',
		'wp-optimize/wp-optimize.php'                => 'WP-Optimize',
		'sg-cachepress/sg-cachepress.php'            => 'Speed Optimizer (SiteGround)',
		'breeze/breeze.php'                          => 'Breeze',
		'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
		'cache-enabler/cache-enabler.php'            => 'Cache Enabler',
		'flying-press/flying-press.php'              => 'FlyingPress',
		'easy-optimizer/easy-optimizer.php'          => 'Easy Optimizer',
		'speedycache/speedycache.php'                => 'SpeedyCache',
		'nitropack/main.php'                         => 'NitroPack',
		'jetpack-boost/jetpack-boost.php'            => 'Jetpack Boost',
		'swift-performance-lite/performance.php'     => 'Swift Performance',
	);

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_fast_wp', array( __CLASS__, 'handle' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 100 );
		add_filter( 'plugin_action_links_' . plugin_basename( FAST_WP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'Fast WP', 'Fast WP', 'manage_options', self::PAGE, array( __CLASS__, 'render' ), 'dashicons-performance', 81 );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html( t( 'Settings' ) ) . '</a>' );
		return $links;
	}

	/** URL of an action (button) with a nonce. */
	public static function action_url( $do, array $extra = array() ) {
		$args = array_merge(
			array(
				'action' => 'fast_wp',
				'do'     => $do,
			),
			$extra
		);
		return wp_nonce_url( add_query_arg( $args, admin_url( 'admin-post.php' ) ), 'fast_wp_' . $do );
	}

	public static function conflicts() {
		$active = (array) get_option( 'active_plugins', array() );
		$found  = array();
		foreach ( self::CONFLICTS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $name;
			}
		}
		return $found;
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------- */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( t( 'You are not allowed to do this.' ) ) );
		}
		$do = isset( $_REQUEST['do'] ) ? sanitize_key( wp_unslash( $_REQUEST['do'] ) ) : '';
		check_admin_referer( 'fast_wp_' . $do );

		$msg  = '';
		$type = 'success';
		switch ( $do ) {
			case 'save':
				$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
				$in  = isset( $_POST['fwp'] ) && is_array( $_POST['fwp'] ) ? $_POST['fwp'] : array(); // phpcs:ignore -- sanitized in sanitize_tab.
				Settings::update( Settings::sanitize_tab( $tab, $in ) );
				Plugin::apply_settings();
				$msg = t( 'Settings saved and cache cleared.' );
				break;
			case 'preset':
				$name    = isset( $_GET['preset'] ) ? sanitize_key( wp_unslash( $_GET['preset'] ) ) : '';
				$presets = Settings::presets();
				if ( isset( $presets[ $name ] ) ) {
					Settings::update( $presets[ $name ]['values'] );
					Plugin::apply_settings();
					$msg = t( 'Preset "%s" applied.', $presets[ $name ]['label'] );
				}
				break;
			case 'test_mode':
				$on = ! empty( $_GET['on'] );
				Settings::update( array( 'test_mode' => $on ? 1 : 0 ) );
				Plugin::apply_settings();
				$msg = $on ? t( 'Test mode on: visitors do not get the optimizations.' ) : t( 'Test mode off: optimizations are live for visitors.' );
				break;
			case 'safe_mode_off':
				Settings::update( array( 'safe_mode' => 0 ) );
				Plugin::apply_settings();
				$msg = t( 'Safe mode turned off.' );
				break;
			case 'purge':
				Cache::purge_all();
				$msg = t( 'Cache cleared.' );
				break;
			case 'purge_everything':
				Cache::purge_everything();
				$msg = t( 'Cache and optimized files deleted.' );
				break;
			case 'purge_url':
				$url = isset( $_GET['url'] ) ? esc_url_raw( wp_unslash( $_GET['url'] ) ) : '';
				if ( $url ) {
					Cache::purge_url( $url );
					$msg = t( 'Cache of this page cleared.' );
				}
				break;
			case 'preload':
				Cache::schedule_preload( 1 );
				spawn_cron();
				$msg = t( 'Preloading started in the background.' );
				break;
			case 'self_test':
				$r    = Self_Test::run();
				$msg  = $r['ok'] ? t( 'Self-test OK: the site responded normally.' ) : t( 'The self-test found a problem. See the details on the Dashboard tab.' );
				$type = $r['ok'] ? 'success' : 'error';
				break;
			case 'db_clean':
				$done = Database::clean();
				$msg  = t( 'Database cleaned: %d items removed and tables optimized.', array_sum( $done ) );
				break;
			case 'webp_bulk':
				Webp::start_bulk();
				spawn_cron();
				$msg = t( 'WebP conversion started in the background.' );
				break;
			case 'dropin_force':
				Cache::install_dropin( true );
				$msg = t( 'advanced-cache.php replaced with the Fast WP one.' );
				break;
			case 'check_update':
				$rel = Updater::latest( true );
				delete_site_transient( 'update_plugins' );
				$msg = $rel ? t( 'Latest version on GitHub: %s.', $rel['version'] ) : t( 'Could not reach GitHub (check the repository in the Advanced tab).' );
				break;
			case 'export':
				nocache_headers();
				header( 'Content-Type: application/json; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename=fast-wp-config.json' );
				$data = Settings::all();
				unset( $data['safe_mode'] );
				echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore
				exit;
			case 'import':
				$raw  = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : ''; // phpcs:ignore
				$data = json_decode( (string) $raw, true );
				if ( is_array( $data ) ) {
					$clean = array();
					foreach ( array_keys( Settings::fields() ) as $tab ) {
						$clean = array_merge( $clean, Settings::sanitize_tab( $tab, wp_slash( $data ) ) );
					}
					$clean['test_mode'] = 1; // Imported: start in test mode to be safe.
					Settings::update( $clean );
					Plugin::apply_settings();
					$msg = t( 'Settings imported. Test mode was turned on: test before going live.' );
				} else {
					$msg  = t( 'The pasted text is not a valid Fast WP configuration.' );
					$type = 'error';
				}
				break;
		}

		$back = isset( $_REQUEST['back'] ) ? esc_url_raw( wp_unslash( $_REQUEST['back'] ) ) : wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::PAGE );
		}
		if ( $msg ) {
			set_transient( 'fast_wp_notice_' . get_current_user_id(), array( $type, $msg ), 60 );
		}
		wp_safe_redirect( $back );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------- */

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$n = get_transient( 'fast_wp_notice_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'fast_wp_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p><strong>Fast WP:</strong> %s</p></div>', esc_attr( $n[0] ), esc_html( $n[1] ) );
		}

		$s = Settings::all();
		if ( ! empty( $s['safe_mode'] ) ) {
			$test = get_option( Self_Test::OPTION );
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s %s <a href="%s">%s</a> · <a href="%s">%s</a></p></div>',
				esc_html( t( 'Fast WP is in safe mode:' ) ),
				esc_html( t( 'the self-test found a problem after an update and the optimizations were switched off (the cache keeps working).' ) ),
				$test && ! empty( $test['checks'] ) ? esc_html( self::test_summary( $test ) ) : '',
				esc_url( self::action_url( 'self_test' ) ),
				esc_html( t( 'Run the self-test again' ) ),
				esc_url( self::action_url( 'safe_mode_off' ) ),
				esc_html( t( 'Turn safe mode off' ) )
			);
		} elseif ( ! empty( $s['test_mode'] ) ) {
			$preview = add_query_arg( 'fwp_test', 1, home_url( '/' ) );
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s" target="_blank">%s</a> · <a href="%s" target="_blank">%s</a> · <a href="%s"><strong>%s</strong></a></p></div>',
				esc_html( t( 'Fast WP is in test mode:' ) ),
				esc_html( t( 'visitors do not get the optimizations yet. Preview:' ) ),
				esc_url( $preview ),
				esc_html( $preview ),
				esc_url( self::pagespeed_url( home_url( '/' ) ) ),
				esc_html( t( 'test on PageSpeed' ) ),
				esc_url( self::action_url( 'test_mode', array( 'on' => 0 ) ) ),
				esc_html( t( 'go live for visitors' ) )
			);
		}

		$conflicts = self::conflicts();
		if ( $conflicts ) {
			printf( '<div class="notice notice-error"><p><strong>Fast WP:</strong> %s</p></div>', esc_html( t( 'deactivate %s. Two cache/optimization plugins at the same time conflict.', implode( ', ', $conflicts ) ) ) );
		}
		if ( ! empty( $s['cache_enabled'] ) && Cache::foreign_dropin() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Fast WP:</strong> %s <a href="%s">%s</a></p></div>',
				esc_html( t( 'there is an advanced-cache.php file from another plugin. After deactivating the other cache plugin,' ) ),
				esc_url( self::action_url( 'dropin_force' ) ),
				esc_html( t( 'use the Fast WP one' ) )
			);
		}
		if ( ! empty( $s['cache_enabled'] ) && ! Cache::wp_cache_active() && ! Cache::foreign_dropin() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Fast WP:</strong> %s <code>define( \'WP_CACHE\', true );</code></p></div>',
				esc_html( t( 'could not enable the cache in wp-config.php (file not writable). Add this line right after <?php:' ) )
			);
		}
		$err = get_option( Optimizer::ERROR_OPTION );
		if ( $err && ! empty( $err['time'] ) && $err['time'] > time() - DAY_IN_SECONDS && isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) { // phpcs:ignore
			printf(
				'<div class="notice notice-info"><p><strong>Fast WP:</strong> %s</p></div>',
				esc_html( t( 'an optimization failed on %1$s and the page was served without optimization (the site did not break). Details: %2$s', $err['url'], $err['message'] ) )
			);
		}
	}

	private static function test_summary( array $test ) {
		$out = array();
		foreach ( $test['checks'] as $name => $c ) {
			$out[] = self::check_label( $name ) . ': ' . $c['message'];
		}
		return implode( ' | ', $out );
	}

	private static function check_label( $name ) {
		return 'optimized' === $name ? t( 'Optimized' ) : t( 'Normal' );
	}

	public static function pagespeed_url( $url ) {
		if ( Settings::get( 'test_mode' ) ) {
			$url = add_query_arg( 'fwp_test', 1, $url );
		}
		return 'https://pagespeed.web.dev/report?url=' . rawurlencode( $url ) . '&form_factor=mobile';
	}

	/* ---------------------------------------------------------------------
	 * Admin bar
	 * ------------------------------------------------------------------- */

	public static function admin_bar( $bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = Settings::all();
		$title = 'Fast WP' . ( ! empty( $s['safe_mode'] ) ? ' (' . t( 'safe mode' ) . ')' : ( ! empty( $s['test_mode'] ) ? ' (' . t( 'test' ) . ')' : '' ) );
		$bar->add_node(
			array(
				'id'    => 'fast-wp',
				'title' => esc_html( $title ),
				'href'  => admin_url( 'admin.php?page=' . self::PAGE ),
			)
		);
		$bar->add_node(
			array(
				'parent' => 'fast-wp',
				'id'     => 'fast-wp-purge',
				'title'  => esc_html( t( 'Clear the whole cache' ) ),
				'href'   => self::action_url( 'purge' ),
			)
		);
		if ( ! is_admin() ) {
			$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$uri     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			$current = ( is_ssl() ? 'https://' : 'http://' ) . $host . $uri;
			$current = remove_query_arg( array( 'fwp_test', 'fwp_off' ), $current );
			$items   = array(
				'fast-wp-purge-url' => array( t( 'Clear the cache of this page' ), self::action_url( 'purge_url', array( 'url' => rawurlencode( $current ), 'back' => rawurlencode( $current ) ) ), false ),
				'fast-wp-view-opt'  => array( t( 'View this page optimized' ), add_query_arg( 'fwp_test', 1, $current ), false ),
				'fast-wp-view-off'  => array( t( 'View this page without optimization' ), add_query_arg( 'fwp_off', 1, $current ), false ),
				'fast-wp-psi'       => array( t( 'Test this page on PageSpeed' ), self::pagespeed_url( $current ), true ),
			);
			foreach ( $items as $id => $item ) {
				$bar->add_node(
					array(
						'parent' => 'fast-wp',
						'id'     => $id,
						'title'  => esc_html( $item[0] ),
						'href'   => $item[1],
						'meta'   => $item[2] ? array( 'target' => '_blank' ) : array(),
					)
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Screen
	 * ------------------------------------------------------------------- */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$fields = Settings::fields();
		$tab    = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'painel'; // phpcs:ignore
		if ( 'painel' !== $tab && ! isset( $fields[ $tab ] ) ) {
			$tab = 'painel';
		}
		echo '<div class="wrap fwp-wrap"><h1>Fast WP <small style="font-size:13px;color:#666">' . esc_html( t( 'version %s', FAST_WP_VERSION ) ) . '</small></h1>';
		self::styles();
		echo '<nav class="nav-tab-wrapper">';
		$tabs = array_merge( array( 'painel' => t( 'Dashboard' ) ), wp_list_pluck( $fields, 'title' ) );
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&tab=' . $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		if ( 'painel' === $tab ) {
			self::render_dashboard();
		} else {
			self::render_form( $tab, $fields[ $tab ] );
		}
		echo '</div>';
	}

	private static function styles() {
		echo '<style>.fwp-wrap .fwp-box{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:16px 20px;margin:16px 0;max-width:980px}.fwp-wrap .fwp-box h2{margin-top:0}.fwp-wrap .fwp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:8px 24px}.fwp-ok{color:#008a20;font-weight:600}.fwp-bad{color:#d63638;font-weight:600}.fwp-warn{color:#b26200;font-weight:600}.fwp-wrap .form-table th{width:300px}.fwp-wrap .description{max-width:640px}.fwp-btns .button{margin:0 6px 6px 0}</style>';
	}

	private static function status_line( $label, $state, $text ) {
		$class = 'ok' === $state ? 'fwp-ok' : ( 'bad' === $state ? 'fwp-bad' : 'fwp-warn' );
		printf( '<div>%s: <span class="%s">%s</span></div>', esc_html( $label ), esc_attr( $class ), esc_html( $text ) );
	}

	private static function render_dashboard() {
		$s = Settings::all();

		echo '<div class="fwp-box"><h2>' . esc_html( t( 'Status' ) ) . '</h2><div class="fwp-grid">';
		if ( ! empty( $s['safe_mode'] ) ) {
			self::status_line( t( 'Mode' ), 'bad', t( 'safe mode (optimizations off)' ) );
		} elseif ( ! empty( $s['test_mode'] ) ) {
			self::status_line( t( 'Mode' ), 'warn', t( 'test (only with ?fwp_test=1)' ) );
		} else {
			self::status_line( t( 'Mode' ), 'ok', t( 'live for visitors' ) );
		}
		self::status_line( t( 'Page cache' ), ! empty( $s['cache_enabled'] ) ? 'ok' : 'warn', ! empty( $s['cache_enabled'] ) ? t( 'on' ) : t( 'off' ) );
		self::status_line( t( 'WP_CACHE in wp-config' ), Cache::wp_cache_active() ? 'ok' : 'bad', Cache::wp_cache_active() ? t( 'active' ) : t( 'inactive' ) );
		$dropin_state = Cache::foreign_dropin() ? 'bad' : ( is_file( Cache::dropin_path() ) ? 'ok' : 'warn' );
		$dropin_text  = Cache::foreign_dropin() ? t( 'from another plugin' ) : ( is_file( Cache::dropin_path() ) ? t( 'from Fast WP' ) : t( 'missing' ) );
		self::status_line( 'advanced-cache.php', $dropin_state, $dropin_text );
		self::status_line( t( 'Server' ), 'ok', Htaccess::is_apache() ? t( 'Apache/LiteSpeed (cache served by .htaccess)' ) : t( 'other (cache served by PHP)' ) );
		self::status_line( t( 'Cached pages' ), 'ok', (string) self::count_cached() );
		self::status_line( t( 'WebP on the server' ), Webp::supported() ? 'ok' : 'warn', Webp::supported() ? t( 'supported' ) : t( 'not supported' ) );
		$conf = self::conflicts();
		self::status_line( t( 'Conflicts' ), $conf ? 'bad' : 'ok', $conf ? implode( ', ', $conf ) : t( 'none' ) );
		echo '</div></div>';

		echo '<div class="fwp-box fwp-btns"><h2>' . esc_html( t( 'Actions' ) ) . '</h2>';
		printf( '<a class="button button-primary" href="%s">%s</a>', esc_url( self::action_url( 'purge' ) ), esc_html( t( 'Clear the whole cache' ) ) );
		printf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'preload' ) ), esc_html( t( 'Preload the cache now' ) ) );
		printf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'self_test' ) ), esc_html( t( 'Run self-test' ) ) );
		printf( '<a class="button" target="_blank" href="%s">%s</a>', esc_url( self::pagespeed_url( home_url( '/' ) ) ), esc_html( t( 'Test on PageSpeed (mobile)' ) ) );
		if ( ! empty( $s['test_mode'] ) ) {
			printf( '<a class="button button-primary" href="%s">%s</a>', esc_url( self::action_url( 'test_mode', array( 'on' => 0 ) ) ), esc_html( t( 'Go live for visitors' ) ) );
		} else {
			printf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'test_mode', array( 'on' => 1 ) ) ), esc_html( t( 'Back to test mode' ) ) );
		}
		echo '<p class="description">' . esc_html( t( 'In test mode, only visitors who add ?fwp_test=1 to the address see the optimized version. The PageSpeed link above already includes it. To compare, ?fwp_off=1 shows the page without any optimization.' ) ) . '</p>';
		echo '</div>';

		echo '<div class="fwp-box fwp-btns"><h2>' . esc_html( t( 'Presets' ) ) . '</h2>';
		foreach ( Settings::presets() as $key => $p ) {
			printf(
				'<a class="button" href="%s" onclick="return confirm(%s)">%s</a>',
				esc_url( self::action_url( 'preset', array( 'preset' => $key ) ) ),
				esc_attr( wp_json_encode( t( 'Apply the "%s" preset?', $p['label'] ) ) ),
				esc_html( $p['label'] )
			);
		}
		echo '<p class="description">' . esc_html( t( 'Safe: cache, minification, lazy load and fonts, without delaying JS or removing CSS. Recommended: everything except delaying ad pixels. Maximum: everything, including pixels and GTM (tracking only starts after the first interaction).' ) ) . '</p>';
		echo '</div>';

		$test = get_option( Self_Test::OPTION );
		if ( $test && ! empty( $test['checks'] ) ) {
			echo '<div class="fwp-box"><h2>' . esc_html( t( 'Last self-test' ) ) . '</h2>';
			printf(
				'<p>%s · %s</p><ul>',
				$test['ok'] ? '<span class="fwp-ok">' . esc_html( t( 'All good' ) ) . '</span>' : '<span class="fwp-bad">' . esc_html( t( 'Problem found' ) ) . '</span>',
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $test['time'] ) )
			);
			foreach ( $test['checks'] as $name => $c ) {
				printf( '<li><strong>%s:</strong> %s</li>', esc_html( self::check_label( $name ) ), esc_html( $c['message'] ) );
			}
			echo '</ul></div>';
		}

		echo '<div class="fwp-box"><h2>' . esc_html( t( 'Emergency' ) ) . '</h2>';
		echo '<p>' . esc_html( t( 'If the site breaks and you cannot open the dashboard, add this to wp-config.php:' ) ) . '</p>';
		echo '<p><code>define( \'FAST_WP_DISABLE\', true );</code></p>';
		echo '<p>' . esc_html( t( 'This switches the cache and every optimization off immediately. Deactivating the plugin also undoes everything: it removes the .htaccess rules, advanced-cache.php and the wp-config.php line.' ) ) . '</p></div>';
	}

	private static function count_cached() {
		$dir = FAST_WP_CACHE_DIR . '/pages';
		if ( ! is_dir( $dir ) ) {
			return 0;
		}
		$n  = 0;
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $f ) {
			if ( $f->isFile() && '.html' === substr( $f->getFilename(), -5 ) ) {
				$n++;
			}
		}
		return $n;
	}

	private static function render_form( $tab, array $def ) {
		$s = Settings::all();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'fast_wp_save' );
		echo '<input type="hidden" name="action" value="fast_wp"><input type="hidden" name="do" value="save"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<div class="fwp-box"><table class="form-table" role="presentation">';
		foreach ( $def['fields'] as $key => $f ) {
			list( $type, , $label ) = $f;
			$desc = isset( $f[3] ) ? $f[3] : '';
			$val  = isset( $s[ $key ] ) ? $s[ $key ] : $f[1];
			$name = 'fwp[' . $key . ']';
			echo '<tr><th scope="row"><label for="fwp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td>';
			switch ( $type ) {
				case 'checkbox':
					printf( '<label><input type="checkbox" id="fwp-%1$s" name="%2$s" value="1"%3$s> %4$s</label>', esc_attr( $key ), esc_attr( $name ), checked( ! empty( $val ), true, false ), esc_html( t( 'On' ) ) );
					break;
				case 'number':
					printf( '<input type="number" min="0" class="small-text" id="fwp-%1$s" name="%2$s" value="%3$s">', esc_attr( $key ), esc_attr( $name ), esc_attr( (string) $val ) );
					break;
				case 'select':
					printf( '<select id="fwp-%1$s" name="%2$s">', esc_attr( $key ), esc_attr( $name ) );
					foreach ( $f[4] as $opt => $text ) {
						printf( '<option value="%s"%s>%s</option>', esc_attr( $opt ), selected( $val, $opt, false ), esc_html( $text ) );
					}
					echo '</select>';
					break;
				case 'textarea':
					printf( '<textarea id="fwp-%1$s" name="%2$s" rows="5" class="large-text code">%3$s</textarea>', esc_attr( $key ), esc_attr( $name ), esc_textarea( (string) $val ) );
					break;
				default:
					printf( '<input type="text" class="regular-text" id="fwp-%1$s" name="%2$s" value="%3$s">', esc_attr( $key ), esc_attr( $name ), esc_attr( (string) $val ) );
			}
			if ( $desc ) {
				echo '<p class="description">' . esc_html( $desc ) . '</p>';
			}
			echo '</td></tr>';
		}
		echo '</table>';
		submit_button( t( 'Save and clear the cache' ) );
		echo '</div></form>';

		if ( 'midia' === $tab ) {
			self::render_webp_box();
		} elseif ( 'banco' === $tab ) {
			self::render_db_box();
		} elseif ( 'avancado' === $tab ) {
			self::render_advanced_box();
		}
	}

	private static function render_webp_box() {
		echo '<div class="fwp-box fwp-btns"><h2>' . esc_html( t( 'Convert existing images' ) ) . '</h2>';
		if ( ! Webp::supported() ) {
			echo '<p class="fwp-bad">' . esc_html( t( 'This server has no WebP support in PHP (GD or Imagick). Ask your host to enable it.' ) ) . '</p></div>';
			return;
		}
		$state = get_option( Webp::BULK_OPTION );
		if ( $state ) {
			printf(
				'<p>%s · %s</p>',
				! empty( $state['running'] ) ? '<span class="fwp-warn">' . esc_html( t( 'Running' ) ) . '</span>' : '<span class="fwp-ok">' . esc_html( t( 'Finished' ) ) . '</span>',
				esc_html( t( 'images checked: %1$d · WebP copies created: %2$d', (int) $state['offset'], (int) $state['converted'] ) )
			);
		}
		printf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'webp_bulk' ) ), esc_html( t( 'Convert every image in the library' ) ) );
		echo '<p class="description">' . esc_html( t( 'Runs in the background, in batches. The original files are not changed.' ) ) . '</p></div>';
	}

	private static function render_db_box() {
		echo '<div class="fwp-box fwp-btns"><h2>' . esc_html( t( 'Clean now' ) ) . '</h2><ul>';
		$labels = Database::labels();
		foreach ( Database::counts() as $k => $n ) {
			printf( '<li>%s: <strong>%d</strong></li>', esc_html( $labels[ $k ] ), (int) $n );
		}
		echo '</ul>';
		printf(
			'<a class="button button-primary" href="%s" onclick="return confirm(%s)">%s</a>',
			esc_url( self::action_url( 'db_clean' ) ),
			esc_attr( wp_json_encode( t( 'The items listed will be deleted permanently (they do not go to the trash). Continue?' ) ) ),
			esc_html( t( 'Clean the database' ) )
		);
		echo '<p class="description">' . esc_html( t( 'Deleted items cannot be recovered. Make a backup first if in doubt.' ) ) . '</p></div>';
	}

	private static function render_advanced_box() {
		$rel = Updater::repo() ? Updater::latest() : null;
		echo '<div class="fwp-box fwp-btns"><h2>' . esc_html( t( 'Updates' ) ) . '</h2>';
		if ( ! Updater::repo() ) {
			echo '<p class="fwp-warn">' . esc_html( t( 'No GitHub repository set. Fill in the field above to receive automatic updates.' ) ) . '</p>';
		} else {
			printf(
				'<p>%s</p>',
				esc_html( t( 'Repository: %1$s · installed version: %2$s · latest on GitHub: %3$s', Updater::repo(), FAST_WP_VERSION, $rel ? $rel['version'] : t( 'not checked' ) ) )
			);
		}
		printf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'check_update' ) ), esc_html( t( 'Check for updates now' ) ) );
		echo '</div>';

		echo '<div class="fwp-box fwp-btns"><h2>' . esc_html( t( 'Copy the settings to another site' ) ) . '</h2>';
		printf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'export' ) ), esc_html( t( 'Download settings (.json)' ) ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:12px">';
		wp_nonce_field( 'fast_wp_import' );
		echo '<input type="hidden" name="action" value="fast_wp"><input type="hidden" name="do" value="import">';
		echo '<textarea name="config" rows="5" class="large-text code" placeholder="' . esc_attr( t( 'Paste the contents of fast-wp-config.json here' ) ) . '"></textarea>';
		submit_button( t( 'Import' ), 'secondary', 'submit', false );
		echo '<p class="description">' . esc_html( t( 'Importing turns test mode on in the destination site.' ) ) . '</p></form></div>';

		echo '<div class="fwp-box fwp-btns"><h2>' . esc_html( t( 'Delete generated files' ) ) . '</h2>';
		printf( '<a class="button" href="%s">%s</a>', esc_url( self::action_url( 'purge_everything' ) ), esc_html( t( 'Delete cache and optimized files' ) ) );
		echo '<p class="description">' . esc_html( t( 'Use it if something looks outdated even after clearing the cache. Everything is generated again on the next visits.' ) ) . '</p></div>';
	}
}
