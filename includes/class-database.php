<?php
/**
 * Database cleanup.
 *
 * @package FastWP
 */

namespace FastWP;

defined( 'ABSPATH' ) || exit;

class Database {

	/** Items removed by the cleanup. */
	public static function counts() {
		global $wpdb;
		return array(
			'revisions'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'autodrafts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'trash'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'spam'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash')" ),
			'transients' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout_' ) . '%', time() ) ),
		);
	}

	public static function labels() {
		return array(
			'revisions'  => t( 'Post and page revisions' ),
			'autodrafts' => t( 'Auto-drafts' ),
			'trash'      => t( 'Posts and pages in the trash' ),
			'spam'       => t( 'Spam and trashed comments' ),
			'transients' => t( 'Expired transients' ),
		);
	}

	/** Runs the cleanup. Uses WordPress functions (which also clean metadata). */
	public static function clean() {
		global $wpdb;
		$done = array_fill_keys( array_keys( self::labels() ), 0 );

		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT 2000" );
		foreach ( $ids as $id ) {
			if ( wp_delete_post_revision( (int) $id ) ) {
				$done['revisions']++;
			}
		}
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft' LIMIT 2000" );
		foreach ( $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				$done['autodrafts']++;
			}
		}
		$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash' LIMIT 2000" );
		foreach ( $ids as $id ) {
			if ( wp_delete_post( (int) $id, true ) ) {
				$done['trash']++;
			}
		}
		$ids = $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved IN ('spam','trash') LIMIT 5000" );
		foreach ( $ids as $id ) {
			if ( wp_delete_comment( (int) $id, true ) ) {
				$done['spam']++;
			}
		}
		$before = self::counts()['transients'];
		delete_expired_transients( true );
		$done['transients'] = $before;

		// Optimizes the site's tables.
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );
		foreach ( $tables as $t ) {
			$wpdb->query( 'OPTIMIZE TABLE `' . esc_sql( $t ) . '`' ); // phpcs:ignore
		}
		update_option( 'fast_wp_db_last', array( 'time' => time(), 'done' => $done ), false );
		return $done;
	}
}
