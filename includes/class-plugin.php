<?php
/**
 * Wires everything up: WordPress hooks, activation, deactivation and scheduled tasks.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Plugin {

	const VERSION_OPTION = 'fast_wp_version';

	public static function init() {
		Cleanup::init();
		Webp::init();
		Updater::init();

		add_action( 'template_redirect', array( 'FastWP\\Cache', 'start' ), -9999 );

		// Cache purging.
		add_action( 'transition_post_status', array( 'FastWP\\Cache', 'on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( 'FastWP\\Cache', 'on_post_change' ) );
		add_action( 'comment_post', array( 'FastWP\\Cache', 'on_comment' ) );
		add_action( 'wp_set_comment_status', array( 'FastWP\\Cache', 'on_comment' ) );
		foreach ( array( 'switch_theme', 'customize_save_after', 'wp_update_nav_menu', 'update_option_sidebars_widgets', 'activated_plugin', 'deactivated_plugin', 'elementor/editor/after_save', 'elementor/core/files/clear_cache', 'update_option_permalink_structure', 'update_option_blogname', 'update_option_blogdescription', 'update_option_site_icon' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'purge_all_once' ) );
		}
		add_action( 'upgrader_process_complete', array( __CLASS__, 'purge_all_once' ) );

		// Scheduled tasks.
		add_action( 'fast_wp_preload', array( 'FastWP\\Cache', 'run_preload' ) );
		add_action( 'fast_wp_hourly', array( 'FastWP\\Cache', 'cleanup_expired' ) );
		add_action( 'fast_wp_db_weekly', array( __CLASS__, 'db_weekly' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );

		if ( wp_doing_cron() ) {
			// Auto-updates run through WP-Cron: the self-test runs on the next run.
			add_action( 'init', array( __CLASS__, 'maybe_upgraded' ), 99 );
		}
		if ( is_admin() ) {
			Admin::init();
			add_action( 'admin_init', array( __CLASS__, 'maybe_upgraded' ) );
		} else {
			add_action( 'admin_bar_menu', array( 'FastWP\\Admin', 'admin_bar' ), 100 );
		}
	}

	/** Avoids purging the cache several times in the same request. */
	public static function purge_all_once() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		Cache::purge_all();
	}

	public static function cron_schedules( $schedules ) {
		$schedules['fast_wp_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Uma vez por semana (Fast WP)',
		);
		return $schedules;
	}

	public static function db_weekly() {
		if ( Settings::get( 'db_weekly' ) ) {
			Database::clean();
		}
	}

	/** On the first run after an update: reinstall files and run the self-test. */
	public static function maybe_upgraded() {
		$stored = get_option( self::VERSION_OPTION );
		if ( FAST_WP_VERSION === $stored ) {
			return;
		}
		update_option( self::VERSION_OPTION, FAST_WP_VERSION, true );
		self::apply_settings();
		if ( $stored ) {
			Self_Test::after_update();
		}
	}

	/** Applies settings: config file, advanced-cache.php, wp-config, .htaccess and schedules. */
	public static function apply_settings() {
		Settings::write_config();
		$s = Settings::all();

		if ( ! empty( $s['cache_enabled'] ) ) {
			Cache::install_dropin();
			if ( ! Cache::wp_cache_active() ) {
				Cache::set_wp_cache( true );
			}
		}
		Htaccess::sync();

		if ( ! wp_next_scheduled( 'fast_wp_hourly' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'fast_wp_hourly' );
		}
		$weekly = wp_next_scheduled( 'fast_wp_db_weekly' );
		if ( ! empty( $s['db_weekly'] ) && ! $weekly ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'fast_wp_weekly', 'fast_wp_db_weekly' );
		} elseif ( empty( $s['db_weekly'] ) && $weekly ) {
			wp_clear_scheduled_hook( 'fast_wp_db_weekly' );
		}
		Cache::purge_all();
	}

	public static function activate() {
		wp_mkdir_p( FAST_WP_CACHE_DIR );
		// First install: starts in test mode (visitors are not affected until you go live).
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', true );
			Settings::reset_cache();
		}
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		update_option( self::VERSION_OPTION, FAST_WP_VERSION, true );
		self::apply_settings();
	}

	public static function deactivate() {
		Htaccess::remove();
		Cache::remove_dropin();
		Cache::set_wp_cache( false );
		wp_clear_scheduled_hook( 'fast_wp_preload' );
		wp_clear_scheduled_hook( 'fast_wp_hourly' );
		wp_clear_scheduled_hook( 'fast_wp_db_weekly' );
		wp_clear_scheduled_hook( 'fast_wp_webp_batch' );
		Cache::rrmdir( FAST_WP_CACHE_DIR . '/pages' );
		@unlink( FAST_WP_CACHE_DIR . '/config.php' ); // phpcs:ignore
	}
}
