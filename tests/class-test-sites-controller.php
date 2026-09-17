<?php
/**
 * Tests for the Keyring site inventory sites route.
 */

declare( strict_types=1 );

namespace HM\Keyring\Site_Inventory\Tests;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use const HM\Keyring\Site_Inventory\CAPABILITY;

/**
 * Verify the sites collection and the join key user memberships rely on.
 */
class Test_Sites_Controller extends WP_UnitTestCase {

	/**
	 * Route under test.
	 */
	private const ROUTE = '/keyring/v1/sites';

	/**
	 * An ordinary account explicitly granted the capability.
	 *
	 * @var int
	 */
	private int $reader = 0;

	/**
	 * A user holding no role, and therefore no capabilities.
	 *
	 * @var int
	 */
	private int $unprivileged = 0;

	/**
	 * Create the fixtures.
	 */
	public function set_up() : void {
		parent::set_up();

		$this->reader = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $this->reader )->add_cap( CAPABILITY );

		$this->unprivileged = (int) self::factory()->user->create( [ 'role' => '' ] );
	}

	/**
	 * Dispatch a request through the REST server.
	 *
	 * @param int|null $user User to act as; defaults to the administrator.
	 * @return WP_REST_Response
	 */
	private function dispatch( ?int $user = null ) : WP_REST_Response {
		wp_set_current_user( $user ?? $this->reader );

		return rest_get_server()->dispatch( new WP_REST_Request( 'GET', self::ROUTE ) );
	}

	/** The route is registered under the plugin namespace. */
	public function test_route_is_registered() : void {
		self::assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/** The same capability gates both collections. */
	public function test_authorisation_matches_the_users_route() : void {
		self::assertSame( 401, $this->dispatch( 0 )->get_status() );
		self::assertSame( 403, $this->dispatch( $this->unprivileged )->get_status() );
		self::assertSame( 200, $this->dispatch()->get_status() );
	}

	/** The body is a bare collection, with the fields a consumer needs to key on. */
	public function test_body_is_a_bare_collection() : void {
		$response = $this->dispatch();
		$data     = $response->get_data();

		self::assertIsArray( $data );
		self::assertSame( array_keys( $data ), range( 0, count( $data ) - 1 ) );
		self::assertNotSame( [], $data );

		foreach ( [ 'id', 'url', 'domain', 'path', 'public', 'archived', 'mature' ] as $field ) {
			self::assertArrayHasKey( $field, $data[0] );
		}

		self::assertSame( count( $data ), (int) $response->get_headers()['X-WP-Total'] );
	}

	/** The schema is published by the route rather than inlined in the body. */
	public function test_schema_is_published_by_the_route() : void {
		$options = rest_get_server()->get_route_options( self::ROUTE );

		self::assertIsCallable( $options['schema'] );
		self::assertSame( 'keyring-site-inventory-site', call_user_func( $options['schema'] )['title'] );
	}

	/** Site identity is unambiguous: domain and path together, never domain alone. */
	public function test_sites_are_identified_by_domain_and_path() : void {
		$data = $this->dispatch()->get_data();
		$keys = [];

		foreach ( $data as $site ) {
			$keys[] = $site['domain'] . $site['path'];
			self::assertNotSame( '', $site['path'] );
		}

		self::assertSame( count( $keys ), count( array_unique( $keys ) ) );
	}

	/**
	 * Deleted and spam sites are outside the reported scope.
	 *
	 * Asserted on the query rather than by building a network. Creating a site issues
	 * CREATE TABLE, which the test case rewrites to CREATE TEMPORARY TABLE; those
	 * tables belong to the connection rather than the transaction, so they outlive the
	 * rollback that removes the matching wp_blogs rows.
	 */
	public function test_deleted_and_spam_sites_are_out_of_scope() : void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Site lifecycle flags are multisite only.' );
		}

		$queries = [];

		$capture = static function ( $query ) use ( &$queries ) : void {
			$queries[] = $query->query_vars;
		};

		add_action( 'pre_get_sites', $capture );

		try {
			self::assertSame( 200, $this->dispatch()->get_status() );
		} finally {
			remove_action( 'pre_get_sites', $capture );
		}

		// Both routes take their scope from site_ids_in_scope(), which is the query
		// asking for IDs. Later queries resolve records for IDs it already vetted.
		$scope = null;
		foreach ( $queries as $query_vars ) {
			if ( 'ids' === ( $query_vars['fields'] ?? '' ) ) {
				$scope = $query_vars;
				break;
			}
		}

		self::assertIsArray( $scope, 'The scope query was not made.' );
		self::assertSame( 0, $scope['deleted'] );
		self::assertSame( 0, $scope['spam'] );

		$query_vars = $scope;

		// WP_Site_Query only adds a WHERE clause when the value is numeric, so leaving
		// archived unset is what keeps archived sites in scope.
		self::assertFalse( is_numeric( $query_vars['archived'] ) );
	}

	/**
	 * Archived sites stay in scope and are flagged, so dormant access stays visible.
	 *
	 * Archiving an existing site is an ordinary update, so it rolls back with the test.
	 */
	public function test_archived_sites_are_included_and_flagged() : void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Site lifecycle flags are multisite only.' );
		}

		$site_id = get_current_blog_id();
		update_blog_details( $site_id, [ 'archived' => 1 ] );

		try {
			$sites = array_column( $this->dispatch()->get_data(), null, 'id' );

			self::assertArrayHasKey( $site_id, $sites );
			self::assertTrue( $sites[ $site_id ]['archived'] );
		} finally {
			update_blog_details( $site_id, [ 'archived' => 0 ] );
		}
	}
}
