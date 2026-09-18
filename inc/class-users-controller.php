<?php
/**
 * Users collection for the Keyring site inventory.
 */

namespace HM\Keyring\Site_Inventory;

use WP_Error;
use WP_User_Query;

/**
 * Read-only collection of network users and the sites they belong to.
 *
 * Identity is global, so one person across several sites is one user with several
 * memberships. Roles are per site, so they hang off each membership. Site IDs
 * resolve against the sites collection.
 */
class Users_Controller extends Inventory_Controller {

	/**
	 * Memberships for the users on the current page, keyed by user ID.
	 *
	 * Built once per request because it needs the whole page and the whole site
	 * scope; prepare_item_for_response() reads one user's slice.
	 *
	 * @var array<int,array<int,array<string,mixed>>>
	 */
	protected $memberships = [];

	/**
	 * Super admin logins, flipped for lookup.
	 *
	 * Compared case-sensitively, as is_super_admin() does.
	 *
	 * @var array<string,int>
	 */
	protected $super_admins = [];

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->rest_base = 'users';
	}

	/**
	 * Retrieve a page of users.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$site_ids = site_ids_in_scope();
		if ( is_wp_error( $site_ids ) ) {
			return $site_ids;
		}

		$per_page = (int) $request['per_page'];
		$page     = (int) $request['page'];
		$offset   = ( $page - 1 ) * $per_page;

		$query = new WP_User_Query( [
			'blog_id'     => 0,
			'number'      => $per_page,
			'offset'      => $offset,
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'count_total' => true,
		] );

		$total = (int) $query->get_total();
		$users = $query->get_results();

		// Reconcile the rows returned against the declared total. WP_User_Query runs a
		// trailing FOUND_ROWS() query and wpdb::flush() clears last_error on every
		// query, so a failed main query leaves no error behind to read.
		$expected = max( 0, min( $per_page, $total - $offset ) );
		if ( count( $users ) !== $expected ) {
			return new WP_Error(
				'keyring_site_inventory_incomplete_page',
				sprintf(
					/* translators: 1: rows returned, 2: rows expected. */
					__( 'The user query returned %1$d rows where %2$d were expected.', 'keyring-site-inventory' ),
					count( $users ),
					$expected
				),
				[ 'status' => 500 ]
			);
		}

		$this->super_admins = is_multisite() ? array_flip( get_super_admins() ) : [];

		// Capabilities first, so roles are read only for the sites this page references.
		// Reading them network-wide switches into every site, and would fail the whole
		// roster over a site nobody on this page belongs to.
		$capabilities = $this->capabilities_for( $users, $site_ids );
		$roles        = registered_roles( $this->referenced_sites( $capabilities ) );
		$unreadable   = array_keys( array_filter( $roles, 'is_null' ) );

		// An unreadable site would otherwise appear as a membership with no roles,
		// understating access.
		if ( [] !== $unreadable ) {
			return new WP_Error(
				'keyring_site_inventory_roles_unavailable',
				sprintf(
					/* translators: %s: comma-separated list of site IDs. */
					__( 'Registered roles could not be read for site(s) %s.', 'keyring-site-inventory' ),
					implode( ', ', $unreadable )
				),
				[ 'status' => 500 ]
			);
		}

		$this->memberships = $this->memberships_for( $users, $capabilities, $site_ids, $roles );

		$items = [];
		foreach ( $users as $user ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $user, $request ) );
		}

		$response  = rest_ensure_response( $items );
		$max_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;

		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $max_pages );

		$base = add_query_arg(
			urlencode_deep( $request->get_query_params() ),
			rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) )
		);

		if ( $page > 1 ) {
			$response->link_header( 'prev', add_query_arg( 'page', $page - 1, $base ) );
		}

		if ( $max_pages > $page ) {
			$response->link_header( 'next', add_query_arg( 'page', $page + 1, $base ) );
		}

		return $response;
	}

	/**
	 * Prepare one user for the response.
	 *
	 * @param \WP_User $item User object.
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$user   = $item;
		$fields = $this->get_fields_for_response( $request );
		$data   = [];

		if ( rest_is_field_included( 'id', $fields ) ) {
			$data['id'] = (int) $user->ID;
		}

		if ( rest_is_field_included( 'username', $fields ) ) {
			$data['username'] = (string) $user->user_login;
		}

		if ( rest_is_field_included( 'name', $fields ) ) {
			$data['name'] = (string) $user->display_name;
		}

		if ( rest_is_field_included( 'slug', $fields ) ) {
			$data['slug'] = (string) $user->user_nicename;
		}

		if ( rest_is_field_included( 'email', $fields ) ) {
			$data['email'] = (string) $user->user_email;
		}

		if ( rest_is_field_included( 'registered_date', $fields ) ) {
			$data['registered_date'] = gmdate( 'c', strtotime( $user->user_registered ) );
		}

		if ( rest_is_field_included( 'is_super_admin', $fields ) ) {
			$data['is_super_admin'] = isset( $this->super_admins[ $user->user_login ] );
		}

		if ( rest_is_field_included( 'is_spam', $fields ) ) {
			$data['is_spam'] = isset( $user->data->spam ) ? (bool) $user->data->spam : false;
		}

		if ( rest_is_field_included( 'is_deleted', $fields ) ) {
			$data['is_deleted'] = isset( $user->data->deleted ) ? (bool) $user->data->deleted : false;
		}

		if ( rest_is_field_included( 'is_disabled', $fields ) ) {
			$data['is_disabled'] = user_is_disabled( $user );
		}

		if ( rest_is_field_included( 'memberships', $fields ) ) {
			$data['memberships'] = $this->memberships[ (int) $user->ID ] ?? [];
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		$response = rest_ensure_response( $data );
		$response->add_links( $this->collection_links() );

		return $response;
	}

	/**
	 * Read the stored capabilities row for every site each user belongs to.
	 *
	 * Membership is derived from the capabilities meta keys using the same rule
	 * get_blogs_of_user() applies. That function is not used directly because it goes
	 * on to read each site's blogname and siteurl through WP_Site, which switches into
	 * every site the user belongs to; this route needs only the site IDs.
	 *
	 * A filter on get_blogs_of_user() therefore cannot hide a membership from the
	 * inventory.
	 *
	 * One read per user returns all of their meta, and WP_User_Query has already
	 * primed that cache for this page.
	 *
	 * @param \WP_User[] $users Users on this page.
	 * @param int[] $site_ids Site IDs in scope.
	 * @return array<int,array<int,mixed>> Stored capabilities, keyed by user then site.
	 */
	protected function capabilities_for( array $users, array $site_ids ) : array {
		$scope        = array_flip( array_map( 'intval', $site_ids ) );
		$capabilities = [];

		foreach ( $users as $user ) {
			$user_id                  = (int) $user->ID;
			$capabilities[ $user_id ] = [];

			foreach ( (array) get_user_meta( $user_id ) as $key => $values ) {
				$site_id = $this->membership_site_id( (string) $key );

				// Outside the reported scope: a deleted or spam site, or a stale row for
				// a site that no longer exists.
				if ( null === $site_id || ! isset( $scope[ $site_id ] ) ) {
					continue;
				}

				$capabilities[ $user_id ][ $site_id ] = maybe_unserialize( $values[0] ?? '' );
			}
		}

		return $capabilities;
	}

	/**
	 * Resolve the site a capabilities meta key belongs to.
	 *
	 * Mirrors the key parsing in get_blogs_of_user(). The main site stores its row
	 * without a site ID in the key.
	 *
	 * @param string $key User meta key.
	 * @return int|null Site ID, or null when the key is not a capabilities row.
	 */
	protected function membership_site_id( string $key ) : ?int {
		global $wpdb;

		if ( ! str_ends_with( $key, 'capabilities' ) ) {
			return null;
		}

		if ( $wpdb->base_prefix && ! str_starts_with( $key, $wpdb->base_prefix ) ) {
			return null;
		}

		if ( $wpdb->base_prefix . 'capabilities' === $key ) {
			return is_multisite() ? 1 : get_current_blog_id();
		}

		$site_id = str_replace( [ $wpdb->base_prefix, '_capabilities' ], '', $key );

		return is_numeric( $site_id ) ? (int) $site_id : null;
	}

	/**
	 * Distinct site IDs the current page actually references.
	 *
	 * @param array<int,array<int,mixed>> $capabilities Stored capabilities by user and site.
	 * @return int[]
	 */
	protected function referenced_sites( array $capabilities ) : array {
		$site_ids = [];
		foreach ( $capabilities as $by_site ) {
			foreach ( array_keys( $by_site ) as $site_id ) {
				$site_ids[ $site_id ] = true;
			}
		}

		return array_keys( $site_ids );
	}

	/**
	 * Build memberships for a page of users.
	 *
	 * Roles are the stored capabilities intersected with the roles the site has
	 * registered, which is the rule WP_User::get_role_caps() itself applies.
	 *
	 * A super admin reaches every site without necessarily being a member of it, so
	 * those sites are added with implied_by_super_admin set rather than being
	 * presented as observed membership.
	 *
	 * @param \WP_User[] $users Users on this page.
	 * @param array<int,array<int,mixed>> $capabilities Stored capabilities by user and site.
	 * @param int[] $site_ids Site IDs in scope.
	 * @param array<int,string[]> $roles Registered roles keyed by site ID.
	 * @return array<int,array<int,array<string,mixed>>>
	 */
	protected function memberships_for( array $users, array $capabilities, array $site_ids, array $roles ) : array {
		$memberships = [];

		foreach ( $users as $user ) {
			$user_id = (int) $user->ID;
			$entries = [];

			foreach ( $capabilities[ $user_id ] ?? [] as $site_id => $stored ) {
				$entries[ $site_id ] = [
					'site_id'                => (int) $site_id,
					'roles'                  => $this->roles_from_capabilities( $stored, $roles[ $site_id ] ?? [] ),
					'implied_by_super_admin' => false,
				];
			}

			if ( isset( $this->super_admins[ $user->user_login ] ) ) {
				foreach ( $site_ids as $site_id ) {
					$site_id = (int) $site_id;
					if ( isset( $entries[ $site_id ] ) ) {
						continue;
					}

					$entries[ $site_id ] = [
						'site_id'                => $site_id,
						'roles'                  => [],
						'implied_by_super_admin' => true,
					];
				}
			}

			ksort( $entries );
			$memberships[ $user_id ] = array_values( $entries );
		}

		return $memberships;
	}

	/**
	 * Intersect a stored capabilities map with a site's registered roles.
	 *
	 * An empty capabilities row is still a membership, and yields no roles. Custom
	 * capabilities stored alongside roles stay out of the role list.
	 *
	 * @param mixed $stored Unserialised capabilities meta value.
	 * @param string[] $registered Roles the site has registered.
	 * @return string[]
	 */
	protected function roles_from_capabilities( $stored, array $registered ) : array {
		if ( ! is_array( $stored ) ) {
			return [];
		}

		$names = array_filter( array_keys( $stored ), 'is_string' );

		return array_values( array_intersect( $names, $registered ) );
	}

	/**
	 * Collection parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function get_collection_params() {
		return [
			'context'  => $this->get_context_param( [ 'default' => 'view' ] ),
			'page'     => [
				'description'       => __( 'Current page of the collection.', 'keyring-site-inventory' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'per_page' => [
				'description'       => __( 'Maximum number of items to be returned in result set.', 'keyring-site-inventory' ),
				'type'              => 'integer',
				'default'           => 100,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];
	}

	/**
	 * Item schema.
	 *
	 * @return array<string,mixed>
	 */
	public function get_item_schema() {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'keyring-site-inventory-user',
			'type'       => 'object',
			'properties' => [
				'id'              => [
					'description' => __( 'Unique identifier for the user.', 'keyring-site-inventory' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'username'        => [
					'description' => __( 'Login name for the user.', 'keyring-site-inventory' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'name'            => [
					'description' => __( 'Display name for the user.', 'keyring-site-inventory' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'slug'            => [
					'description' => __( 'An alphanumeric identifier for the user.', 'keyring-site-inventory' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'email'           => [
					'description' => __( 'The email address for the user.', 'keyring-site-inventory' ),
					'type'        => 'string',
					'format'      => 'email',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'registered_date' => [
					'description' => __( 'Registration date for the user.', 'keyring-site-inventory' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'is_super_admin'  => [
					'description' => __( 'Whether the user is a network super admin.', 'keyring-site-inventory' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'is_spam'         => [
					'description' => __( 'Whether the user is flagged as spam on the network.', 'keyring-site-inventory' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'is_deleted'      => [
					'description' => __( 'Whether the user is flagged as deleted on the network.', 'keyring-site-inventory' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'is_disabled'     => [
					'description' => __( 'Whether the user\'s account is disabled. A disabled account cannot sign in even where stored roles remain.', 'keyring-site-inventory' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'memberships'     => [
					'description' => __( 'Sites the user belongs to, with their roles on each.', 'keyring-site-inventory' ),
					'type'        => 'array',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'site_id'                => [
								'description' => __( 'Identifier of the site, matching the sites collection.', 'keyring-site-inventory' ),
								'type'        => 'integer',
							],
							'roles'                  => [
								'description' => __( 'Roles the user holds on that site.', 'keyring-site-inventory' ),
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
							],
							'implied_by_super_admin' => [
								'description' => __( 'Whether access comes from super admin status rather than membership.', 'keyring-site-inventory' ),
								'type'        => 'boolean',
							],
						],
					],
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}
