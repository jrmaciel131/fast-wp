<?php
/**
 * WordPress cleanup: emojis, embeds, head tags, XML-RPC, Dashicons, Gutenberg CSS,
 * jQuery Migrate and Heartbeat.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Cleanup {

	public static function init() {
		$s = Settings::all();

		if ( ! empty( $s['cleanup_emojis'] ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			remove_action( 'admin_print_styles', 'print_emoji_styles' );
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_action( 'admin_enqueue_scripts', 'wp_enqueue_emoji_styles' );
			remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
			remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
			remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
			add_filter( 'emoji_svg_url', '__return_false' );
			add_filter(
				'tiny_mce_plugins',
				function ( $plugins ) {
					return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
				}
			);
			add_filter(
				'wp_resource_hints',
				function ( $urls, $type ) {
					if ( 'dns-prefetch' === $type ) {
						$urls = array_filter(
							$urls,
							function ( $u ) {
								return false === strpos( is_array( $u ) ? (string) ( $u['href'] ?? '' ) : (string) $u, 's.w.org' );
							}
						);
					}
					return $urls;
				},
				10,
				2
			);
		}

		if ( ! empty( $s['cleanup_embeds'] ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
			add_action(
				'wp_footer',
				function () {
					wp_dequeue_script( 'wp-embed' );
				},
				1
			);
		}

		if ( ! empty( $s['cleanup_head'] ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wlwmanifest_link' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}

		if ( ! empty( $s['cleanup_xmlrpc'] ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter(
				'wp_headers',
				function ( $headers ) {
					unset( $headers['X-Pingback'] );
					return $headers;
				}
			);
			add_filter( 'pings_open', '__return_false', 99 );
		}

		if ( ! empty( $s['cleanup_dashicons'] ) ) {
			add_action(
				'wp_enqueue_scripts',
				function () {
					if ( ! is_user_logged_in() ) {
						wp_dequeue_style( 'dashicons' );
						wp_deregister_style( 'dashicons' );
					}
				},
				100
			);
		}

		if ( ! empty( $s['cleanup_block_css'] ) ) {
			add_action(
				'wp_enqueue_scripts',
				function () {
					$id = get_queried_object_id();
					if ( $id && 'builder' === get_post_meta( $id, '_elementor_edit_mode', true ) ) {
						foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $h ) {
							wp_dequeue_style( $h );
						}
					}
				},
				100
			);
		}

		if ( ! empty( $s['cleanup_jquery_migrate'] ) ) {
			add_action(
				'wp_default_scripts',
				function ( $scripts ) {
					if ( ! is_admin() && isset( $scripts->registered['jquery'] ) ) {
						$scripts->registered['jquery']->deps = array_diff( $scripts->registered['jquery']->deps, array( 'jquery-migrate' ) );
					}
				}
			);
		}

		if ( 'default' !== $s['heartbeat'] ) {
			add_filter(
				'heartbeat_settings',
				function ( $settings ) {
					$settings['interval'] = 60;
					return $settings;
				}
			);
			if ( 'front' === $s['heartbeat'] ) {
				add_action(
					'wp_enqueue_scripts',
					function () {
						wp_deregister_script( 'heartbeat' );
					},
					100
				);
			}
		}
	}
}
