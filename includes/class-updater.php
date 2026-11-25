<?php
/**
 * Updates from GitHub (Releases). No external libraries.
 *
 * "Stable" channel: latest normal release. "Beta" channel: includes pre-releases (tags with -beta, -rc).
 * The package is the fast-wp.zip file attached to the release (built by the repository's GitHub Action).
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Updater {

	const SLUG = 'fast-wp';

	/** The project's repository (set in fast-wp.php; can be overridden in wp-config.php). */
	public static function repo() {
		return (string) FAST_WP_GITHUB_REPO;
	}

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 10, 2 );
	}

	private static function basename() {
		return plugin_basename( FAST_WP_FILE );
	}

	/** Latest version available on GitHub (cached for 6 hours). */
	public static function latest( $force = false ) {
		$repo = self::repo();
		if ( '' === $repo ) {
			return null;
		}
		$channel = 'beta' === Settings::get( 'update_channel' ) ? 'beta' : 'stable';
		$key     = 'fast_wp_release_' . md5( $repo . $channel );
		if ( ! $force ) {
			$cached = get_site_transient( $key );
			if ( is_array( $cached ) ) {
				return $cached ? $cached : null;
			}
		}
		$api = 'https://api.github.com/repos/' . $repo . ( 'beta' === $channel ? '/releases?per_page=10' : '/releases/latest' );
		$res = wp_remote_get(
			$api,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Fast-WP-Updater',
				),
			)
		);
		$data = is_wp_error( $res ) ? null : json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! is_array( $data ) ) {
			set_site_transient( $key, array(), HOUR_IN_SECONDS );
			return null;
		}
		if ( 'beta' === $channel ) {
			$data = array_values(
				array_filter(
					$data,
					function ( $r ) {
						return empty( $r['draft'] );
					}
				)
			);
			$data = isset( $data[0] ) ? $data[0] : null;
		}
		if ( ! $data || empty( $data['tag_name'] ) ) {
			set_site_transient( $key, array(), HOUR_IN_SECONDS );
			return null;
		}
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( isset( $asset['name'] ) && self::SLUG . '.zip' === $asset['name'] ) {
				$package = $asset['browser_download_url'];
			}
		}
		if ( '' === $package && ! empty( $data['zipball_url'] ) ) {
			$package = $data['zipball_url'];
		}
		$release = array(
			'version' => ltrim( (string) $data['tag_name'], 'vV' ),
			'package' => $package,
			'url'     => (string) ( $data['html_url'] ?? '' ),
			'notes'   => (string) ( $data['body'] ?? '' ),
			'date'    => (string) ( $data['published_at'] ?? '' ),
		);
		set_site_transient( $key, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/** update_plugins_github.com filter: tells WordPress about the new version. */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( self::basename() !== $plugin_file ) {
			return $update;
		}
		$rel = self::latest();
		if ( ! $rel || ! $rel['package'] ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $rel['version'],
			'url'          => $rel['url'],
			'package'      => $rel['package'],
			'tested'       => get_bloginfo( 'version' ),
			'requires_php' => '7.4',
			'autoupdate'   => (bool) Settings::get( 'auto_update' ),
		);
	}

	/** "View details" screen in the plugins list. */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$rel = self::latest();
		if ( ! $rel ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Fast WP',
			'slug'          => self::SLUG,
			'version'       => $rel['version'],
			'homepage'      => $rel['url'],
			'download_link' => $rel['package'],
			'last_updated'  => $rel['date'],
			'sections'      => array(
				'changelog' => wpautop( esc_html( $rel['notes'] ) ),
			),
		);
	}

	/** The GitHub zip may use another folder name: rename it to "fast-wp". */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || self::basename() !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return $source;
	}

	public static function auto_update( $update, $item ) {
		if ( isset( $item->plugin ) && self::basename() === $item->plugin ) {
			return (bool) Settings::get( 'auto_update' );
		}
		return $update;
	}
}
