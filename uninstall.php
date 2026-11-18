<?php
/**
 * Remoção completa do Fast WP (ao excluir o plugin pelo painel).
 *
 * @package FastWP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'fast_wp_settings', 'fast_wp_version', 'fast_wp_last_error', 'fast_wp_self_test', 'fast_wp_preload_queue', 'fast_wp_last_purge', 'fast_wp_webp_bulk', 'fast_wp_db_last', 'fast_wp_server_apache' ) as $option ) {
	delete_option( $option );
}

$dir = WP_CONTENT_DIR . '/cache/fast-wp';
if ( is_dir( $dir ) ) {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) {
		$f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); // phpcs:ignore
	}
	@rmdir( $dir ); // phpcs:ignore
}
// As versões .webp das imagens ficam na pasta de uploads e continuam funcionando sem o plugin;
// não são apagadas para não quebrar páginas em cache de CDN.
