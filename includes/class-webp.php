<?php
/**
 * Creates WebP copies of images on the server itself (GD or Imagick). photo.jpg -> photo.jpg.webp
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Webp {

	const BULK_OPTION = 'fast_wp_webp_bulk';
	const QUALITY     = 82;

	public static function supported() {
		if ( function_exists( 'imagewebp' ) && function_exists( 'imagecreatefromjpeg' ) ) {
			return true;
		}
		return class_exists( '\Imagick' ) && in_array( 'WEBP', \Imagick::queryFormats( 'WEBP' ), true );
	}

	public static function init() {
		if ( Settings::get( 'webp_convert' ) && self::supported() ) {
			add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );
		}
		// When an image is deleted, delete its WebP copy too.
		add_filter(
			'wp_delete_file',
			function ( $file ) {
				if ( is_string( $file ) && preg_match( '/\.(jpe?g|png)$/i', $file ) && is_file( $file . '.webp' ) ) {
					@unlink( $file . '.webp' ); // phpcs:ignore
				}
				return $file;
			}
		);
		add_action( 'fast_wp_webp_batch', array( __CLASS__, 'run_bulk' ) );
	}

	public static function on_upload( $metadata, $attachment_id ) {
		foreach ( self::files_for( $attachment_id, $metadata ) as $file ) {
			self::convert( $file );
		}
		return $metadata;
	}

	/** Original file + every generated size. */
	public static function files_for( $attachment_id, $metadata = null ) {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! preg_match( '/\.(jpe?g|png)$/i', $file ) ) {
			return array();
		}
		$files = array( $file );
		if ( null === $metadata ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		$dir = dirname( $file );
		if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$files[] = $dir . '/' . $size['file'];
				}
			}
		}
		if ( ! empty( $metadata['original_image'] ) ) {
			$files[] = $dir . '/' . $metadata['original_image'];
		}
		return array_unique( $files );
	}

	/** Converts one file. Only keeps the WebP when it is smaller than the original. */
	public static function convert( $file ) {
		if ( ! is_file( $file ) || is_file( $file . '.webp' ) ) {
			return false;
		}
		$target = $file . '.webp';
		$ok     = false;
		if ( function_exists( 'imagewebp' ) ) {
			$ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
			$img = 'png' === $ext ? @imagecreatefrompng( $file ) : @imagecreatefromjpeg( $file ); // phpcs:ignore
			if ( $img ) {
				if ( 'png' === $ext ) {
					imagepalettetotruecolor( $img );
					imagealphablending( $img, true );
					imagesavealpha( $img, true );
				}
				$ok = @imagewebp( $img, $target, self::QUALITY ); // phpcs:ignore
				imagedestroy( $img );
			}
		} elseif ( class_exists( '\Imagick' ) ) {
			try {
				$im = new \Imagick( $file );
				$im->setImageFormat( 'webp' );
				$im->setImageCompressionQuality( self::QUALITY );
				$ok = $im->writeImage( $target );
				$im->clear();
			} catch ( \Exception $e ) {
				$ok = false;
			}
		}
		if ( ! $ok || ! is_file( $target ) ) {
			return false;
		}
		if ( filesize( $target ) >= filesize( $file ) ) {
			@unlink( $target ); // phpcs:ignore
			return false;
		}
		return true;
	}

	/** Starts converting the images that already exist. */
	public static function start_bulk() {
		update_option(
			self::BULK_OPTION,
			array(
				'offset'    => 0,
				'converted' => 0,
				'running'   => true,
			),
			false
		);
		wp_clear_scheduled_hook( 'fast_wp_webp_batch' );
		wp_schedule_single_event( time() + 5, 'fast_wp_webp_batch' );
	}

	public static function run_bulk() {
		$state = get_option( self::BULK_OPTION, array() );
		if ( empty( $state['running'] ) || ! self::supported() ) {
			return;
		}
		$ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'post_status'    => 'inherit',
				'posts_per_page' => 15,
				'offset'         => (int) $state['offset'],
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
		$started   = microtime( true );
		$processed = 0;
		foreach ( $ids as $id ) {
			foreach ( self::files_for( $id ) as $file ) {
				if ( self::convert( $file ) ) {
					$state['converted']++;
				}
			}
			$state['offset']++;
			$processed++;
			if ( microtime( true ) - $started > 20 ) {
				break; // Avoids the server time limit; continues in the next batch.
			}
		}
		if ( count( $ids ) < 15 && count( $ids ) === $processed ) {
			$state['running'] = false;
			Cache::purge_all();
		} else {
			wp_schedule_single_event( time() + 15, 'fast_wp_webp_batch' );
		}
		update_option( self::BULK_OPTION, $state, false );
	}
}
