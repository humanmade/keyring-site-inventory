<?php
/**
 * Tests for the Keyring site inventory users route.
 */

declare( strict_types=1 );

namespace HM\Keyring\Site_Inventory\Tests;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use function HM\Keyring\Site_Inventory\required_capability;
use const HM\Keyring\Site_Inventory\CAPABILITY;

/**
 * Verify the users collection, its authorisation and its role resolution.
 */
class Test_Users_Controller extends WP_UnitTestCase {

	/**
	 * Route under test.
	 */
	private const ROUTE = '/keyring/v1/users';

	/**
	 * An ordinary account explicitly granted the capability, as an operator would.
	 *
	 * @var int
	 */
	private int $reader = 0;

	/**
	 * A site administrator holding no inventory capability.
	 *
	 * @var int
	 */
	private int $administrator = 0;

	/**
	 * An ordinary member of the site.
	 *
	 * @var int
	 */
	private int $person = 0;

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

		// The capability is never granted automatically, so the reader is an ordinary
		// account given it explicitly, exactly as `wp user add-cap` would.
		$this->reader = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		get_userdata( $this->reader )->add_cap( CAPABILITY );

		$this->administrator = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->person        = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// An explicitly empty role, because a network can relax the capabilities a
		// subscriber holds; only a roleless account is reliably unprivileged.
		$this->unprivileged = (int) self::factory()->user->create( [ 'role' => '' ] );
	}

	/**
	 * Dispatch a request through the REST server.
	 *
	 * Going through the server rather than calling the callback directly is what
	 * applies the route's argument defaults, validation and field filtering, so the
	 * tests exercise the contract a client actually gets.
	 *
	 * @param array<string,mixed> $params Query parameters.
	 * @param int|null $user User to act as; defaults to the administrator.
	 * @return WP_REST_Response
	 */
	private function dispatch( array $params = [], ?int $user = null ) : WP_REST_Response {
		wp_set_current_user( $user ?? $this->reader );

		$request = new WP_REST_Request( 'GET', self::ROUTE );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Capabilities meta key for the current site.
	 *
	 * @return string
	 */
	private function capabilities_key() : string {
		global $wpdb;

		return $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities';
	}

	/** The route is registered under the plugin namespace. */
	public function test_route_is_registered() : void {
		self::assertArrayHasKey( self::ROUTE, rest_get_server()->get_routes() );
	}

	/** An unauthenticated request is 401, distinct from 403. */
	public function test_unauthenticated_request_is_401() : void {
		$response = $this->dispatch( [], 0 );

		self::assertSame( 401, $response->get_status() );
		self::assertSame( 'keyring_site_inventory_cannot_view', $response->get_data()['code'] );
	}

	/** An authenticated user without the capability is 403. */
	public function test_user_without_the_capability_is_403() : void {
		self::assertFalse( user_can( $this->unprivileged, required_capability() ) );

		$response = $this->dispatch( [], $this->unprivileged );

		self::assertSame( 403, $response->get_status() );
	}

	/** An account explicitly granted the capability is allowed. */
	public function test_explicitly_granted_account_is_allowed() : void {
		self::assertTrue( user_can( $this->reader, required_capability() ) );
		self::assertSame( 200, $this->dispatch()->get_status() );
	}

	/**
	 * A site administrator does not get the roster for free.
	 *
	 * The payload is every email address on the network, so holding manage_options on
	 * one site is not a reason to read it. The capability has to be granted.
	 */
	public function test_administrator_without_the_grant_is_denied() : void {
		if ( is_multisite() && is_super_admin( $this->administrator ) ) {
			self::markTestSkipped( 'Super admins hold every capability by definition.' );
		}

		self::assertTrue( user_can( $this->administrator, 'manage_options' ) );
		self::assertFalse( user_can( $this->administrator, required_capability() ) );
		self::assertSame( 403, $this->dispatch( [], $this->administrator )->get_status() );
	}

	/** The grant is written to the account, so it can be revoked the same way. */
	public function test_the_grant_can_be_revoked() : void {
		get_userdata( $this->reader )->remove_cap( CAPABILITY );

		self::assertFalse( user_can( $this->reader, required_capability() ) );
		self::assertSame( 403, $this->dispatch()->get_status() );
	}

	/** A site can move the bar, and the callback reads it at request time. */
	public function test_required_capability_is_filterable() : void {
		$filter = static function () : string {
			return 'keyring_test_capability_nobody_holds';
		};

		add_filter( 'keyring_site_inventory_capability', $filter );

		try {
			self::assertSame( 403, $this->dispatch()->get_status() );
		} finally {
			remove_filter( 'keyring_site_inventory_capability', $filter );
		}
	}

	/**
	 * The body is a bare collection of users, as core returns.
	 *
	 * The shape matters as much as the contents: an envelope would make every
	 * consumer special-case this route.
	 */
	public function test_body_is_a_bare_collection() : void {
		$data = $this->dispatch()->get_data();

		self::assertIsArray( $data );
		self::assertSame( array_keys( $data ), range( 0, count( $data ) - 1 ), 'The body is a list, not an object.' );

		$user = $data[0];
		foreach ( [ 'id', 'username', 'name', 'slug', 'email', 'registered_date', 'is_super_admin', 'is_spam', 'is_deleted', 'is_disabled', 'memberships' ] as $field ) {
			self::assertArrayHasKey( $field, $user );
		}
	}

	/** Pagination is advertised in the headers core clients already read. */
	public function test_pagination_uses_core_headers() : void {
		$response = $this->dispatch( [ 'per_page' => 2 ] );
		$headers  = $response->get_headers();

		self::assertArrayHasKey( 'X-WP-Total', $headers );
		self::assertArrayHasKey( 'X-WP-TotalPages', $headers );
		self::assertLessThanOrEqual( 2, count( $response->get_data() ) );

		$total = (int) $headers['X-WP-Total'];
		self::assertSame( (int) ceil( $total / 2 ), (int) $headers['X-WP-TotalPages'] );
	}

	/** Every page together covers the whole roster exactly once, in a stable order. */
	public function test_pages_cover_every_user_once() : void {
		$first = $this->dispatch( [ 'per_page' => 2 ] );
		$total = (int) $first->get_headers()['X-WP-Total'];
		$pages = (int) $first->get_headers()['X-WP-TotalPages'];

		$seen = [];
		for ( $page = 1; $page <= $pages; $page++ ) {
			foreach ( $this->dispatch( [
				'per_page' => 2,
				'page'     => $page,
			] )->get_data() as $user ) {
				$seen[] = $user['id'];
			}
		}

		self::assertCount( $total, $seen );
		self::assertCount( $total, array_unique( $seen ) );
		self::assertSame( $seen, array_values( array_unique( $seen ) ) );
	}

	/** Out-of-range parameters are rejected by the route, not clamped silently. */
	public function test_collection_parameters_are_validated() : void {
		self::assertSame( 400, $this->dispatch( [ 'per_page' => 0 ] )->get_status() );
		self::assertSame( 400, $this->dispatch( [ 'per_page' => 101 ] )->get_status() );
		self::assertSame( 400, $this->dispatch( [ 'page' => 0 ] )->get_status() );
	}

	/** The schema is published by the route, not inlined in every response. */
	public function test_schema_is_published_by_the_route() : void {
		$options = rest_get_server()->get_route_options( self::ROUTE );

		self::assertArrayHasKey( 'schema', $options );
		self::assertIsCallable( $options['schema'] );

		$schema = call_user_func( $options['schema'] );
		self::assertSame( 'keyring-site-inventory-user', $schema['title'] );
		self::assertArrayHasKey( 'memberships', $schema['properties'] );
		self::assertArrayHasKey( 'is_disabled', $schema['properties'] );

		$data = $this->dispatch()->get_data();
		self::assertArrayNotHasKey( 'schema', $data[0] );
	}

	/** The _fields parameter narrows the response, as it does for core routes. */
	public function test_fields_parameter_is_honoured() : void {
		$item = $this->dispatch( [ '_fields' => 'id' ] )->get_data()[0];

		self::assertArrayHasKey( 'id', $item );
		self::assertArrayNotHasKey( 'email', $item );
		self::assertArrayNotHasKey( 'memberships', $item );

		// Links survive field filtering for core routes too.
		self::assertSame( [ 'id', '_links' ], array_keys( $item ) );
	}

	/** Secrets and internals never reach the payload. */
	public function test_payload_excludes_secrets() : void {
		$json = (string) wp_json_encode( $this->dispatch()->get_data() );

		foreach ( [ 'user_pass', 'user_activation_key', 'session_tokens', 'capabilities' ] as $term ) {
			self::assertStringNotContainsString( $term, $json );
		}
	}

	/** A capability stored beside the roles is not reported as a role. */
	public function test_custom_capability_is_not_reported_as_a_role() : void {
		update_user_meta( $this->person, $this->capabilities_key(), [
			'subscriber'          => true,
			'keyring_not_a_role'  => true,
		] );
		clean_user_cache( $this->person );

		$membership = $this->membership_for( $this->person );

		self::assertSame( [ 'subscriber' ], $membership['roles'] );
	}

	/** An empty capabilities row is a membership with no roles, matching WordPress. */
	public function test_empty_capabilities_row_is_a_membership() : void {
		update_user_meta( $this->person, $this->capabilities_key(), [] );
		clean_user_cache( $this->person );

		$membership = $this->membership_for( $this->person );

		self::assertSame( [], $membership['roles'] );
	}

	/** An ordinary account is not reported as disabled. */
	public function test_account_is_not_disabled_by_default() : void {
		self::assertFalse( $this->user_in_response( $this->person )['is_disabled'] );
	}

	/**
	 * The Disable Accounts flag is reported as is_disabled.
	 *
	 * The plugin withdraws access at runtime and deliberately keeps stored roles, so
	 * the roles alone read as current access. The flag is what says otherwise, which
	 * makes it the field a consumer has to be able to trust.
	 */
	public function test_disabled_account_is_reported() : void {
		update_user_meta( $this->person, '_hm_disableaccounts_disabled', 'yes' );
		clean_user_cache( $this->person );

		self::assertTrue( $this->user_in_response( $this->person )['is_disabled'] );

		// The stored role and the membership survive disabling, by design.
		self::assertSame( [ 'subscriber' ], $this->membership_for( $this->person )['roles'] );
	}

	/** A re-enabled account is no longer reported as disabled. */
	public function test_reenabled_account_is_not_reported_as_disabled() : void {
		update_user_meta( $this->person, '_hm_disableaccounts_disabled', 'yes' );
		clean_user_cache( $this->person );
		self::assertTrue( $this->user_in_response( $this->person )['is_disabled'] );

		delete_user_meta( $this->person, '_hm_disableaccounts_disabled' );
		clean_user_cache( $this->person );

		self::assertFalse( $this->user_in_response( $this->person )['is_disabled'] );
	}

	/**
	 * Disabling is not a role change, so the field is independent of memberships.
	 *
	 * A disabled account can still hold administrator and super admin reach on
	 * paper, which a consumer must not read as usable access.
	 */
	public function test_disabled_administrator_keeps_its_administrator_role_but_is_flagged() : void {
		update_user_meta( $this->administrator, '_hm_disableaccounts_disabled', 'yes' );
		clean_user_cache( $this->administrator );

		$user = $this->user_in_response( $this->administrator );

		self::assertTrue( $user['is_disabled'] );
		self::assertContains( 'administrator', $this->membership_for( $this->administrator )['roles'] );
	}

	/** Registration dates are reported in UTC, whatever the site timezone. */
	public function test_registered_date_is_utc() : void {
		$original = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'Pacific/Auckland' );

		try {
			$registered = get_userdata( $this->person )->user_registered;
			$user       = $this->user_in_response( $this->person );

			self::assertSame(
				gmdate( 'c', strtotime( $registered . ' UTC' ) ),
				$user['registered_date']
			);
		} finally {
			update_option( 'timezone_string', (string) $original );
		}
	}

	/** Memberships only ever reference sites the sites collection reports. */
	public function test_memberships_reference_sites_in_scope() : void {
		wp_set_current_user( $this->reader );
		$sites = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/keyring/v1/sites' ) )->get_data();
		$ids   = array_column( $sites, 'id' );

		foreach ( $this->dispatch()->get_data() as $user ) {
			foreach ( $user['memberships'] as $membership ) {
				self::assertContains( $membership['site_id'], $ids );
			}
		}
	}

	/** A super admin reaches every site, reported as implied rather than observed. */
	public function test_super_admin_access_is_reported_as_implied() : void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Super admin access is multisite only.' );
		}

		$user_id = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		grant_super_admin( $user_id );

		try {
			$user = $this->user_in_response( $user_id );
			wp_set_current_user( $this->reader );
			$sites = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/keyring/v1/sites' ) )->get_data();

			self::assertTrue( $user['is_super_admin'] );
			self::assertCount( count( $sites ), $user['memberships'] );

			$current = get_current_blog_id();
			foreach ( $user['memberships'] as $membership ) {
				if ( (int) $membership['site_id'] === $current ) {
					self::assertFalse( $membership['implied_by_super_admin'] );
					self::assertNotSame( [], $membership['roles'] );
					continue;
				}

				self::assertTrue( $membership['implied_by_super_admin'] );
				self::assertSame( [], $membership['roles'] );
			}
		} finally {
			revoke_super_admin( $user_id );
		}
	}

	/**
	 * Serving the roster never switches into another site.
	 *
	 * This is the requirement core set for a sites endpoint in 2017: useful data per
	 * site without switch_to_blog(). Reading site records or every site's registered
	 * roles would switch once per site, and each switch loads that site's autoloaded
	 * options.
	 */
	public function test_roster_performs_no_site_switches() : void {
		$switched = [];

		$watch = static function ( $site_id ) use ( &$switched ) : void {
			$switched[] = (int) $site_id;
		};

		add_action( 'switch_blog', $watch );

		try {
			self::assertSame( 200, $this->dispatch()->get_status() );
		} finally {
			remove_action( 'switch_blog', $watch );
		}

		self::assertSame( [], $switched );
	}

	/**
	 * A site whose registered roles cannot be read fails the roster.
	 *
	 * Reporting its members as holding no roles would understate access.
	 */
	public function test_unreadable_roles_fail_the_roster() : void {
		global $wpdb;

		$option = 'pre_option_' . $wpdb->get_blog_prefix( get_current_blog_id() ) . 'user_roles';
		$empty  = static function () : array {
			return [];
		};

		add_filter( $option, $empty );

		try {
			$response = $this->dispatch();
		} finally {
			remove_filter( $option, $empty );
		}

		self::assertSame( 500, $response->get_status() );
		self::assertSame(
			'keyring_site_inventory_roles_unavailable',
			$response->get_data()['code']
		);
	}

	/**
	 * Find one user in the full response.
	 *
	 * @param int $user_id User to find.
	 * @return array<string,mixed>
	 */
	private function user_in_response( int $user_id ) : array {
		$by_id = array_column( $this->dispatch( [ 'per_page' => 100 ] )->get_data(), null, 'id' );
		self::assertArrayHasKey( $user_id, $by_id );

		return $by_id[ $user_id ];
	}

	/**
	 * The current site's membership for a user.
	 *
	 * @param int $user_id User to inspect.
	 * @return array<string,mixed>
	 */
	private function membership_for( int $user_id ) : array {
		$memberships = array_column( $this->user_in_response( $user_id )['memberships'], null, 'site_id' );
		self::assertArrayHasKey( get_current_blog_id(), $memberships );

		return $memberships[ get_current_blog_id() ];
	}
}
