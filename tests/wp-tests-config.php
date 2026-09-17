<?php
/**
 * PHPUnit configuration for the WordPress test suite.
 *
 * Every value can be overridden with an environment variable, so the same file
 * works for a local WordPress checkout and for CI.
 */

define( 'ABSPATH', rtrim( getenv( 'WP_CORE_DIR' ) ?: dirname( __DIR__ ) . '/wordpress', '/' ) . '/' );

define( 'DB_NAME', getenv( 'WP_DB_NAME' ) ?: 'wordpress_test' );
define( 'DB_USER', getenv( 'WP_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Keyring Site Inventory Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
