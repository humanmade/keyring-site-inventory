<?php
/**
 * PHPUnit bootstrap for the Keyring Site Inventory test suite.
 *
 * Loads the WordPress test library, then the plugin, before handing control to the
 * suite. WP_TESTS_DIR and WP_PHPUNIT__TESTS_CONFIG may both be overridden.
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// wp-phpunit exports WP_PHPUNIT__DIR from its autoloader; WP_TESTS_DIR overrides it for
// a WordPress develop checkout.
$krsi_suite = getenv( 'WP_TESTS_DIR' ) ?: getenv( 'WP_PHPUNIT__DIR' );

require_once $krsi_suite . '/includes/functions.php';

tests_add_filter( 'muplugins_loaded', static function () {
	require dirname( __DIR__ ) . '/keyring-site-inventory.php';
} );

// A WordPress checkout used only for tests has no wp-content/uploads, and the test
// case walks the upload directory between tests.
tests_add_filter( 'upload_dir', static function ( array $dirs ) : array {
	$base = sys_get_temp_dir() . '/keyring-site-inventory-uploads';

	wp_mkdir_p( $base );

	$dirs['basedir'] = $base;
	$dirs['baseurl'] = 'http://example.org/uploads';
	$dirs['path']    = $base . ( $dirs['subdir'] ?? '' );
	$dirs['url']     = $dirs['baseurl'] . ( $dirs['subdir'] ?? '' );

	return $dirs;
} );

require $krsi_suite . '/includes/bootstrap.php';
