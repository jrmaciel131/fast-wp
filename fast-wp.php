<?php
/**
 * Plugin Name:       Fast WP
 * Description:       Page cache and performance optimizations with no paid tier: unused CSS removal, delayed JS, lazy load, main image (LCP), self-hosted Google Fonts, WebP, cleanup and database. Built for Elementor sites.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fast-wp
 * Update URI:        https://github.com/fast-wp/fast-wp
 */

defined( 'ABSPATH' ) || exit;

define( 'FAST_WP_VERSION', '0.1.0' );
define( 'FAST_WP_FILE', __FILE__ );
define( 'FAST_WP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FAST_WP_URL', plugin_dir_url( __FILE__ ) );
define( 'FAST_WP_CACHE_DIR', WP_CONTENT_DIR . '/cache/fast-wp' );

/*
 * GitHub repository used for automatic updates, as "owner/repository".
 * It can also be set in the Advanced tab or in wp-config.php with define( 'FAST_WP_GITHUB_REPO', '...' ).
 */
if ( ! defined( 'FAST_WP_GITHUB_REPO' ) ) {
	define( 'FAST_WP_GITHUB_REPO', '' );
}

require_once FAST_WP_DIR . 'includes/class-request.php';
require_once FAST_WP_DIR . 'includes/class-i18n.php';
require_once FAST_WP_DIR . 'includes/class-settings.php';
require_once FAST_WP_DIR . 'includes/class-html.php';
require_once FAST_WP_DIR . 'includes/class-minify.php';
require_once FAST_WP_DIR . 'includes/class-css-parser.php';
require_once FAST_WP_DIR . 'includes/class-used-css.php';
require_once FAST_WP_DIR . 'includes/class-assets.php';
require_once FAST_WP_DIR . 'includes/class-fonts.php';
require_once FAST_WP_DIR . 'includes/class-css.php';
require_once FAST_WP_DIR . 'includes/class-media.php';
require_once FAST_WP_DIR . 'includes/class-js.php';
require_once FAST_WP_DIR . 'includes/class-optimizer.php';
require_once FAST_WP_DIR . 'includes/class-cache.php';
require_once FAST_WP_DIR . 'includes/class-htaccess.php';
require_once FAST_WP_DIR . 'includes/class-cleanup.php';
require_once FAST_WP_DIR . 'includes/class-webp.php';
require_once FAST_WP_DIR . 'includes/class-database.php';
require_once FAST_WP_DIR . 'includes/class-self-test.php';
require_once FAST_WP_DIR . 'includes/class-updater.php';
require_once FAST_WP_DIR . 'includes/class-admin.php';
require_once FAST_WP_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'FastWP\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FastWP\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'FastWP\\Plugin', 'init' ) );
