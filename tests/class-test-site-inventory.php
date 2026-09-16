<?php
/**
 * Tests for the Keyring site inventory route.
 */

declare( strict_types=1 );

namespace HM\Keyring\Tests;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;
use WP_User;
use function HM\Keyring\Site_Inventory\handle_request;
use function HM\Keyring\Site_Inventory\is_service_login;
use function HM\Keyring\Site_Inventory\permission_callback;
use function HM\Keyring\Site_Inventory\registered_at;
use function HM\Keyring\Site_Inventory\required_capability;
use function HM\Keyring\Site_Inventory\with_implied_memberships;

/**
 * Verify the read-only roster contract, its WordPress authorisation and role resolution.
 */
class Test_Site_Inventory extends WP_UnitTestCase {
	/**
	 * Login of the authorised reader: an ordinary account that holds the capability.
	 */
	private const READER_LOGIN = 'inventory-reader';

	/**
	 * Login of an ordinary person on the network.
	 */
	private const PERSON_LOGIN = 'ordinary';

	/**
	 * A login that looks automated but is not a declared machine account.
	 */
	private const MACHINE_LOGIN = 'keyring';

	/**
	 * Login of a user holding no role, and therefore no capabilities.
	 */
	private const UNPRIVILEGED_LOGIN = 'roleless';

	/**
	 * ID of the user that holds the required capability.
	 *
	 * @var int
	 */
	private int $reader = 0;

	/**
	 * ID of an ordinary person, used for roster assertions.
	 *
	 * @var int
	 */
	private int $person = 0;

	/**
	 * ID of the account whose login merely looks automated.
	 *
	 * @var int
	 */
	private int $machine = 0;

	/**
	 * ID of a user with no role at all, used for the 403 case.
	 *
	 * @var int
	 */
	private int $unprivileged = 0;

	/**
	 * Saved HTTPS server value, restored after each test.
	 *
	 * @var string|null
	 */
	private ?string $https = null;

	/**
	 * Create the users and present the request as HTTPS.
	 *
	 * The route requires TLS before anything else, so the transport has to be
	 * simulated or every authorisation assertion would stop at the HTTPS check.
	 */
	public function set_up() : void {
		parent::set_up();

		$this->https = isset( $_SERVER['HTTPS'] ) ? (string) $_SERVER['HTTPS'] : null;
		$_SERVER['HTTPS'] = 'on';

		$this->reader = (int) self::factory()->user->create( [
			'user_login' => self::READER_LOGIN,
			'role'       => 'administrator',
		] );
		$this->person = (int) self::factory()->user->create( [
			'user_login' => self::PERSON_LOGIN,
			'role'       => 'subscriber',
		] );
		$this->machine = (int) self::factory()->user->create( [
			'user_login' => self::MACHINE_LOGIN,
			'role'       => 'subscriber',
		] );

		// An explicit empty role, because this network relaxes list_users for
		// members (h2-network's h2_allow_listing_users option), so a subscriber
		// cannot be assumed to lack it.
		$this->unprivileged = (int) self::factory()->user->create( [
			'user_login' => self::UNPRIVILEGED_LOGIN,
			'role'       => '',
		] );
	}

	/**
	 * Restore the transport so no later test inherits a simulated TLS request.
	 */
	public function tear_down() : void {
		if ( null === $this->https ) {
			unset( $_SERVER['HTTPS'] );
		} else {
			$_SERVER['HTTPS'] = $this->https;
		}

		parent::tear_down();
	}

	/**
	 * Build a request for the route.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return WP_REST_Request
	 */
	private function request( array $params = [] ) : WP_REST_Request {
		$request = new WP_REST_Request( 'GET', '/keyring/v1/site-inventory' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return $request;
	}

	/**
	 * Call the route handler as the authorised reader.
	 *
	 * @param array<string,mixed> $params Request parameters.
	 * @return WP_REST_Response
	 */
	private function fetch( array $params ) : WP_REST_Response {
		wp_set_current_user( $this->reader );
		$result = handle_request( $this->request( $params ) );
		self::assertNotInstanceOf( WP_Error::class, $result );

		return $result;
	}

	/** The roster is governed by a core capability, not one the plugin invents. */
	public function test_required_capability_defaults_to_list_users() : void {
		self::assertSame( 'list_users', required_capability() );
	}

	/** An unauthenticated request is 401, distinct from 403. */
	public function test_unauthenticated_request_is_401() : void {
		wp_set_current_user( 0 );
		$result = permission_callback();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'keyring_not_authenticated', $result->get_error_code() );
		self::assertSame( 401, $result->get_error_data()['status'] );
	}

	/** An authenticated user without the capability is refused with 403. */
	public function test_authenticated_user_without_the_capability_is_403() : void {
		self::assertFalse(
			user_can( $this->unprivileged, required_capability() ),
			'A user with no role must not hold the required capability.'
		);

		wp_set_current_user( $this->unprivileged );
		$result = permission_callback();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'keyring_forbidden', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	/** Holding the capability is the whole of authorisation: no identity check follows. */
	public function test_user_with_the_capability_is_allowed() : void {
		self::assertTrue( user_can( $this->reader, required_capability() ) );

		wp_set_current_user( $this->reader );
		self::assertTrue( permission_callback() );
	}

	/**
	 * A network can move the bar, and the callback reads it at request time.
	 *
	 * Also exercises the refusal branch against a capability no account holds,
	 * independently of how this network maps list_users.
	 */
	public function test_required_capability_is_filterable() : void {
		$capability = 'keyring_capability_held_by_nobody';
		$filter     = static function () use ( $capability ) : string {
			return $capability;
		};
		add_filter( 'keyring_site_inventory_required_capability', $filter );

		try {
			self::assertSame( $capability, required_capability() );
			self::assertFalse( user_can( $this->reader, $capability ) );

			wp_set_current_user( $this->reader );
			$result = permission_callback();

			self::assertInstanceOf( WP_Error::class, $result );
			self::assertSame( 'keyring_forbidden', $result->get_error_code() );
			self::assertSame( 403, $result->get_error_data()['status'] );
		} finally {
			remove_filter( 'keyring_site_inventory_required_capability', $filter );
		}

		self::assertTrue( permission_callback(), 'The reader is authorised again once the bar returns to list_users.' );
	}

	/** The payload carries email addresses, so plaintext transport is refused. */
	public function test_request_without_https_is_403() : void {
		unset( $_SERVER['HTTPS'] );
		wp_set_current_user( $this->reader );

		$result = permission_callback();

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'keyring_https_required', $result->get_error_code() );
		self::assertSame( 403, $result->get_error_data()['status'] );
	}

	/**
	 * A super admin is allowed, because they hold the capability.
	 *
	 * The previous design refused them by identity. Authorisation is now the
	 * site's ordinary capability model, and a super admin holds every capability.
	 */
	public function test_super_admin_is_allowed() : void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Super-admin status is multisite only.' );
		}

		$super = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
		grant_super_admin( $super );

		try {
			wp_set_current_user( $super );
			self::assertTrue( permission_callback() );
		} finally {
			revoke_super_admin( $super );
		}
	}

	/**
	 * The plugin owns no capability of its own, stored or granted.
	 *
	 * A regression guard against reintroducing a bespoke capability and the
	 * user_has_cap grant that went with it.
	 */
	public function test_plugin_grants_no_capability_of_its_own() : void {
		// The account named after this plugin is included deliberately: it is the
		// login the removed design granted a capability to.
		foreach ( [ $this->unprivileged, $this->machine, $this->reader ] as $user_id ) {
			$user = new WP_User( $user_id );
			$own  = array_values( array_filter( array_keys( (array) $user->allcaps ), static function ( $cap ) : bool {
				return str_starts_with( (string) $cap, 'keyring' );
			} ) );

			self::assertSame( [], $own, 'No capability of this plugin is held by ' . $user->user_login . '.' );
			self::assertArrayNotHasKey( 'keyring_read_site_inventory', (array) get_user_meta( $user_id, $this->capabilities_meta_key(), true ) );

			wp_set_current_user( $user_id );
			self::assertFalse( current_user_can( 'keyring_read_site_inventory' ) );
		}
	}

	/** The registered route is protected by the permission callback, not left open. */
	public function test_route_permission_callback_is_enforced() : void {
		$routes = rest_get_server()->get_routes();
		self::assertArrayHasKey( '/keyring/v1/site-inventory', $routes );

		$callback = $routes['/keyring/v1/site-inventory'][0]['permission_callback'] ?? null;
		self::assertIsCallable( $callback );

		wp_set_current_user( 0 );
		$result = $callback( $this->request() );

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'keyring_not_authenticated', $result->get_error_code() );
	}

	/** The envelope reconciles its totals and advertises them in headers. */
	public function test_response_envelope_reconciles() : void {
		$response = $this->fetch( [ 'per_page' => 2 ] );
		$data     = $response->get_data();

		self::assertSame( 'keyring-site-inventory/v1', $data['schema'] );
		self::assertSame( 2, $data['perPage'] );
		self::assertSame( 1, $data['page'] );
		self::assertSame( (int) ceil( $data['total'] / 2 ), $data['pages'] );
		self::assertSame( $data['total'], (int) $response->get_headers()['X-Keyring-Total'] );
		self::assertSame( $data['pages'], (int) $response->get_headers()['X-Keyring-Pages'] );
		self::assertSame( 'v1', $response->get_headers()['X-Keyring-Schema'] );
		self::assertTrue( $data['complete'] );
		self::assertSame( [], $data['errors'] );
		self::assertLessThanOrEqual( 2, count( $data['users'] ) );
	}

	/** Every page together equals the declared total. */
	public function test_pages_sum_to_the_declared_total() : void {
		$first = $this->fetch( [ 'per_page' => 2 ] )->get_data();
		$seen  = [];
		for ( $page = 1; $page <= $first['pages']; $page++ ) {
			foreach ( $this->fetch( [
				'per_page' => 2,
				'page' => $page,
			] )->get_data()['users'] as $user ) {
				$seen[] = $user['id'];
			}
		}

		self::assertCount( $first['total'], $seen );
		self::assertCount( $first['total'], array_unique( $seen ) );
		self::assertSame( $seen, array_values( array_unique( $seen ) ), 'Users are stably ordered by ID.' );
	}

	/** Secrets and unrelated internals never appear in the payload. */
	public function test_payload_excludes_secrets_and_internals() : void {
		$data = $this->fetch( [ 'per_page' => 200 ] )->get_data();
		$json = (string) wp_json_encode( $data );

		foreach ( [ 'user_pass', 'user_activation_key', 'session_tokens', 'capabilities' ] as $term ) {
			self::assertStringNotContainsString( $term, $json );
		}

		self::assertArrayHasKey( 'login', $data['users'][0] );
		self::assertArrayNotHasKey( 'user_pass', $data['users'][0] );
	}

	/** Only registered roles are reported; stray capabilities are not. */
	public function test_custom_capability_is_not_reported_as_a_role() : void {
		$meta_key = $this->capabilities_meta_key();
		if ( ! $meta_key ) {
			self::markTestSkipped( 'Requires a blog capabilities meta key.' );
		}

		update_user_meta( $this->person, $meta_key, [ 'manage_workflows' => true ] );

		$data = $this->fetch( [ 'per_page' => 200 ] )->get_data();
		$roles = array_merge( ...array_map( static function ( $user ) : array {
			return array_merge( ...array_map( static function ( $m ) : array {
				return $m['roles'];
			}, $user['memberships'] ) );
		}, $data['users'] ) );

		self::assertNotContains( 'manage_workflows', $roles );
	}

	/** An empty capabilities row still counts as a site membership. */
	public function test_empty_capabilities_row_is_a_membership() : void {
		$meta_key = $this->capabilities_meta_key();
		if ( ! $meta_key ) {
			self::markTestSkipped( 'Requires a blog capabilities meta key.' );
		}

		update_user_meta( $this->person, $meta_key, [] );

		$data    = $this->fetch( [ 'per_page' => 200 ] )->get_data();
		$by_user = array_column( $data['users'], null, 'id' );
		self::assertArrayHasKey( $this->person, $by_user );
		self::assertNotEmpty( $by_user[ $this->person ]['memberships'] );
	}

	/** Roles live on memberships, not on the user record. */
	public function test_roles_are_site_scoped() : void {
		$data = $this->fetch( [ 'per_page' => 200 ] )->get_data();

		self::assertArrayNotHasKey( 'roles', $data['users'][0] );
		foreach ( $data['users'] as $user ) {
			foreach ( $user['memberships'] as $membership ) {
				self::assertArrayHasKey( 'siteId', $membership );
				self::assertArrayHasKey( 'host', $membership );
				self::assertArrayHasKey( 'roles', $membership );
			}
		}
	}

	/** Every membership references a site the response described. */
	public function test_memberships_reference_in_scope_sites() : void {
		$data     = $this->fetch( [ 'per_page' => 200 ] )->get_data();
		$site_ids = array_column( $data['sites'], 'id' );

		foreach ( $data['users'] as $user ) {
			foreach ( $user['memberships'] as $membership ) {
				self::assertContains( $membership['siteId'], $site_ids );
			}
		}
	}

	/**
	 * Page bounds are enforced by the handler, not only by the route schema.
	 *
	 * A bare WP_REST_Request has no route attributes, so rest_validate_request_arg()
	 * returns true for it; against the real route it enforces the schema. The handler
	 * clamps independently so a direct call cannot exceed the documented bounds.
	 */
	public function test_page_bounds_are_enforced_by_the_handler() : void {
		$data = $this->fetch( [ 'per_page' => 9999 ] )->get_data();
		self::assertSame( 200, $data['perPage'] );

		$data = $this->fetch( [ 'per_page' => 0 ] )->get_data();
		self::assertSame( 1, $data['perPage'] );
	}

	/** The route schema declares the documented bounds. */
	public function test_route_schema_declares_bounds() : void {
		$routes = rest_get_server()->get_routes();
		self::assertArrayHasKey( '/keyring/v1/site-inventory', $routes );

		$args = $routes['/keyring/v1/site-inventory'][0]['args'] ?? [];
		self::assertSame( 200, $args['per_page']['maximum'] ?? null );
		self::assertSame( 1, $args['per_page']['minimum'] ?? null );
		self::assertSame( 1, $args['page']['minimum'] ?? null );
	}

	/**
	 * A short user page is a hard failure, never a silently complete roster.
	 *
	 * WP_User_Query runs a trailing SELECT FOUND_ROWS() and wpdb::flush() clears
	 * last_error on every query, so a failed main query leaves no error behind. The
	 * handler reconciles returned rows against the declared total instead.
	 */
	public function test_incomplete_user_page_is_a_hard_failure() : void {
		// users_pre_query short-circuits the query itself. pre_user_query fires at
		// the end of prepare_query(), after the SQL is built, so mutating it there
		// has no effect and the test would silently pass nothing.
		$filter = static function ( $pre, $query, $args ) {
			if ( is_object( $query ) && 0 === (int) ( $args['blog_id'] ?? -1 ) ) {
				return [];
			}
			return $pre;
		};
		add_filter( 'users_pre_query', $filter, 10, 3 );

		try {
			wp_set_current_user( $this->reader );
			$result = handle_request( $this->request( [ 'per_page' => 5 ] ) );
		} finally {
			remove_filter( 'users_pre_query', $filter, 10 );
		}

		self::assertInstanceOf( WP_Error::class, $result );
		self::assertSame( 'keyring_user_query_incomplete', $result->get_error_code() );
		self::assertSame( 500, $result->get_error_data()['status'] );
	}

	/** The response reports whoever authenticated, not a configured account. */
	public function test_requested_by_reports_the_authenticated_user() : void {
		$data = $this->fetch( [ 'per_page' => 1 ] )->get_data();

		self::assertSame( $this->reader, $data['requestedBy']['id'] );
		self::assertSame( self::READER_LOGIN, $data['requestedBy']['login'] );
		self::assertFalse( $data['requestedBy']['isSuperAdmin'] );

		// A different caller is reported as themselves: the field is the identity
		// that authenticated, and the plugin has no opinion about who that is.
		wp_set_current_user( $this->person );
		$result = handle_request( $this->request( [ 'per_page' => 1 ] ) );
		self::assertNotInstanceOf( WP_Error::class, $result );

		$requested_by = $result->get_data()['requestedBy'];
		self::assertSame( $this->person, $requested_by['id'] );
		self::assertSame( self::PERSON_LOGIN, $requested_by['login'] );
	}

	/**
	 * Registration timestamps are UTC, not site-local.
	 *
	 * The stored user_registered value is UTC. mysql2date() reads it in
	 * wp_timezone(), which shifts every timestamp by the site's offset and varies
	 * with DST.
	 */
	public function test_registration_timestamp_is_utc() : void {
		$result = registered_at( '2012-04-18 17:59:49' );

		self::assertSame( '2012-04-18T17:59:49+00:00', $result );
		self::assertSame( gmdate( 'c', strtotime( '2012-04-18 17:59:49 UTC' ) ), $result );
	}

	/**
	 * Membership comes from core and is trusted as returned.
	 *
	 * A second, independent read was removed deliberately: it would compare a core
	 * function against itself, so it could never fail and never proved anything.
	 */
	public function test_memberships_are_taken_from_core_unchanged() : void {
		$data    = $this->fetch( [ 'per_page' => 200 ] )->get_data();
		$by_user = array_column( $data['users'], null, 'id' );

		foreach ( $by_user as $user ) {
			$blogs = get_blogs_of_user( (int) $user['id'], true );
			$expected = [];
			foreach ( $blogs as $blog ) {
				if ( ! in_array( (int) $blog->userblog_id, array_column( $data['sites'], 'id' ), true ) ) {
					continue;
				}
				$expected[] = (int) $blog->userblog_id;
			}

			$reported = array_column( $user['memberships'], 'siteId' );
			sort( $expected );
			sort( $reported );

			// Implied memberships are additive for super admins only.
			if ( ! $user['isSuperAdmin'] ) {
				self::assertSame( $expected, $reported );
			}
		}
	}

	/** Site count reports the whole network; included count reports the scope. */
	public function test_network_counts_distinguish_scope() : void {
		$data = $this->fetch( [ 'per_page' => 1 ] )->get_data();

		self::assertArrayHasKey( 'siteCount', $data['network'] );
		self::assertArrayHasKey( 'includedSiteCount', $data['network'] );
		self::assertGreaterThanOrEqual( $data['network']['includedSiteCount'], $data['network']['siteCount'] );
		self::assertSame( count( $data['sites'] ), $data['network']['includedSiteCount'] );
	}

	/**
	 * Every membership states whether it is observed or implied by super-admin status.
	 */
	public function test_memberships_declare_implied_state() : void {
		$data = $this->fetch( [ 'per_page' => 200 ] )->get_data();

		foreach ( $data['users'] as $user ) {
			foreach ( $user['memberships'] as $membership ) {
				self::assertArrayHasKey( 'impliedBySuperAdmin', $membership );
				self::assertIsBool( $membership['impliedBySuperAdmin'] );
				if ( $membership['impliedBySuperAdmin'] ) {
					self::assertSame( [], $membership['roles'], 'Implied access carries no observed role.' );
				}
			}
		}
	}

	/**
	 * A super admin reaches sites they are not an explicit member of.
	 */
	public function test_super_admin_implied_memberships_are_synthesized() : void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Super-admin network access is multisite only.' );
		}

		$user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		grant_super_admin( $user_id );

		try {
			$data    = $this->fetch( [ 'per_page' => 200 ] )->get_data();
			$by_user = array_column( $data['users'], null, 'id' );
			self::assertArrayHasKey( $user_id, $by_user );

			$user = $by_user[ $user_id ];
			self::assertTrue( $user['isSuperAdmin'] );

			// The factory created the user on the current blog, so that membership is
			// observed with real roles; every other in-scope site is reachable through
			// network access alone and must be flagged implied with no roles.
			self::assertSame( count( $data['sites'] ), count( $user['memberships'] ) );

			$current = get_current_blog_id();
			$implied = 0;
			foreach ( $user['memberships'] as $membership ) {
				if ( (int) $membership['siteId'] === (int) $current ) {
					self::assertFalse( $membership['impliedBySuperAdmin'], 'The current blog is an observed membership.' );
					self::assertNotSame( [], $membership['roles'] );
					continue;
				}

				self::assertTrue( $membership['impliedBySuperAdmin'] );
				self::assertSame( [], $membership['roles'] );
				$implied++;
			}

			self::assertSame( count( $data['sites'] ) - 1, $implied );
		} finally {
			revoke_super_admin( $user_id );
		}
	}

	/**
	 * An observed membership keeps its roles and is never marked implied.
	 */
	public function test_observed_memberships_are_preserved() : void {
		$sites = [
			[
				'id'   => 7,
				'host' => 'example.test',
			],
		];
		$observed = [
			[
				'siteId' => 7,
				'host'   => 'example.test',
				'roles'  => [ 'editor' ],
			],
		];

		$result = with_implied_memberships( $observed, $sites, true );

		self::assertCount( 1, $result );
		self::assertSame( [ 'editor' ], $result[0]['roles'] );
		self::assertFalse( $result[0]['impliedBySuperAdmin'] );
	}

	/**
	 * Sites carry a path so two sites on one host are distinguishable.
	 *
	 * A network can host several sites on a single domain, differing only by path
	 * (the agency site has / and /repo/). Keying on host alone would merge them.
	 */
	public function test_sites_carry_a_path() : void {
		$data = $this->fetch( [ 'per_page' => 1 ] )->get_data();

		foreach ( $data['sites'] as $site ) {
			self::assertArrayHasKey( 'sitePath', $site );
			self::assertNotSame( '', $site['sitePath'] );
		}
	}

	/** Memberships carry the site path too, so they are unambiguous. */
	public function test_memberships_carry_a_site_path() : void {
		$data = $this->fetch( [ 'per_page' => 200 ] )->get_data();

		foreach ( $data['users'] as $user ) {
			foreach ( $user['memberships'] as $membership ) {
				self::assertArrayHasKey( 'sitePath', $membership );
				self::assertNotSame( '', $membership['sitePath'] );
			}
		}
	}

	/** Site IDs are unique even when hosts repeat. */
	public function test_site_identity_is_unambiguous() : void {
		$data = $this->fetch( [ 'per_page' => 1 ] )->get_data();
		$ids  = array_column( $data['sites'], 'id' );

		self::assertSame( $ids, array_values( array_unique( $ids ) ) );

		// host + path is unique per site, which host alone is not.
		$keys = array_map( static function ( $site ) {
			return $site['host'] . $site['sitePath'];
		}, $data['sites'] );
		self::assertSame( $keys, array_values( array_unique( $keys ) ) );
	}

	/**
	 * Machine accounts are an explicit, filterable list, never a name pattern.
	 *
	 * Nothing is declared by default, so even a login named after this plugin is a
	 * person until someone reviews it and says otherwise.
	 */
	public function test_machine_accounts_are_an_explicit_list() : void {
		self::assertFalse( is_service_login( 'robot-person' ) );
		self::assertFalse( is_service_login( self::MACHINE_LOGIN ) );

		$filter = static function ( $logins ) : array {
			return array_merge( (array) $logins, [ 'human-bot' ] );
		};
		add_filter( 'keyring_site_inventory_service_logins', $filter );

		try {
			self::assertTrue( is_service_login( 'human-bot' ) );
			self::assertTrue( is_service_login( 'Human-Bot' ), 'Matching is case-insensitive.' );
			self::assertFalse( is_service_login( 'human-bot-2' ) );
		} finally {
			remove_filter( 'keyring_site_inventory_service_logins', $filter );
		}

		self::assertFalse( is_service_login( 'human-bot' ) );
	}

	/** The account type follows the declared list, and nothing is declared by default. */
	public function test_account_type_follows_the_declared_list() : void {
		$by_login = array_column( $this->fetch( [ 'per_page' => 200 ] )->get_data()['users'], 'accountType', 'login' );

		self::assertSame( 'person', $by_login[ self::MACHINE_LOGIN ] );
		self::assertSame( 'person', $by_login[ self::PERSON_LOGIN ] );

		$filter = static function ( $logins ) : array {
			return array_merge( (array) $logins, [ self::MACHINE_LOGIN ] );
		};
		add_filter( 'keyring_site_inventory_service_logins', $filter );

		try {
			$by_login = array_column( $this->fetch( [ 'per_page' => 200 ] )->get_data()['users'], 'accountType', 'login' );

			self::assertSame( 'service', $by_login[ self::MACHINE_LOGIN ] );
			self::assertSame( 'person', $by_login[ self::PERSON_LOGIN ] );
		} finally {
			remove_filter( 'keyring_site_inventory_service_logins', $filter );
		}
	}

	/** A page past the end returns no users but a truthful total. */
	public function test_out_of_range_page_keeps_the_true_total() : void {
		$first = $this->fetch( [ 'per_page' => 1 ] )->get_data();
		$last  = $this->fetch( [
			'per_page' => 1,
			'page' => 9999,
		] )->get_data();

		self::assertSame( [], $last['users'] );
		self::assertSame( $first['total'], $last['total'] );
	}

	/** The route is registered under the documented namespace. */
	public function test_route_is_registered() : void {
		$server = rest_get_server();
		$routes = $server->get_routes();

		self::assertArrayHasKey( '/keyring/v1/site-inventory', $routes );
	}

	/** Multisite installs report network flags; single site reports none. */
	public function test_network_flags_match_install_type() : void {
		$data = $this->fetch( [ 'per_page' => 1 ] )->get_data();

		self::assertSame( is_multisite(), $data['multisite'] );
		if ( is_multisite() ) {
			self::assertArrayHasKey( 'archived', $data['sites'][0]['flags'] );
			self::assertArrayHasKey( 'deleted', $data['users'][0]['flags'] );
		} else {
			// Single site reports empty objects, not arrays, so the JSON shape is stable.
			self::assertSame( [], (array) $data['users'][0]['flags'] );
			self::assertSame( [], (array) $data['sites'][0]['flags'] );
		}
	}

	/** Deleted and spam sites are excluded from the reported scope. */
	public function test_deleted_and_spam_sites_are_excluded() : void {
		if ( ! is_multisite() ) {
			self::markTestSkipped( 'Multisite only.' );
		}

		$data = $this->fetch( [ 'per_page' => 200 ] )->get_data();
		foreach ( $data['sites'] as $site ) {
			self::assertFalse( $site['flags']['deleted'] );
			self::assertFalse( $site['flags']['spam'] );
		}
	}

	/** Resolve the capabilities meta key for the current blog. */
	private function capabilities_meta_key() : string {
		global $wpdb;

		return $wpdb->get_blog_prefix( get_current_blog_id() ) . 'capabilities';
	}
}
