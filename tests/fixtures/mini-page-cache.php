<?php
/**
 * Test stand-in for a page cache (like WP Super Cache): installed as a
 * must-use plugin by tests/test-confirmation.php. Only active for requests
 * with the header "X-Flexo-Mini-Cache: 1". Every GET answered with 200 is
 * stored – unless the page defined DONOTCACHEPAGE – and served to every
 * later visitor, whoever they are (stricter than real caches, which skip
 * logged-in users).
 */
if ( empty( $_SERVER['HTTP_X_FLEXO_MINI_CACHE'] ) || 'GET' !== $_SERVER['REQUEST_METHOD'] ) {
	return;
}
$flexo_mc_dir  = WP_CONTENT_DIR . '/flexo-mini-cache';
$flexo_mc_file = $flexo_mc_dir . '/' . md5( $_SERVER['REQUEST_URI'] ) . '.html';
if ( is_file( $flexo_mc_file ) ) {
	header( 'X-Flexo-Mini-Cache: hit' );
	echo file_get_contents( $flexo_mc_file ); // phpcs:ignore
	exit;
}
ob_start(
	static function ( $html ) use ( $flexo_mc_dir, $flexo_mc_file ) {
		if ( ! defined( 'DONOTCACHEPAGE' ) && 200 === http_response_code() && '' !== $html ) {
			if ( ! is_dir( $flexo_mc_dir ) ) {
				mkdir( $flexo_mc_dir );
			}
			file_put_contents( $flexo_mc_file, $html ); // phpcs:ignore
		}
		return $html;
	}
);
