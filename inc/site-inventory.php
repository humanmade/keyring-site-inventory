<?php
/**
 * Read-only site-user roster for Keyring.
 */

namespace HM\Keyring\Site_Inventory;

use WP_Error;
use WP_REST_Request;
use WP_Site;
use WP_User;
use WP_User_Query;
use function HM\Keyring\Service_User\is_service_login;
use function HM\Keyring\Service_User\permission_callback;

const NAMESPACE_ROUTE = 'keyring/v1';
const ROUTE = '/site-inventory';
const SCHEMA = 'keyring-site-inventory/v1';
const DEFAULT_PER_PAGE = 100;
const MAX_PER_PAGE = 200;

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_route' );

/**
 * Register the single read-only route.
 *
 * There is deliberately no scope parameter. The roster is always the complete
 * network, or the single site, because a caller-selectable subset could be
 * presented as complete coverage.
 */
function register_route() {
	register_rest_route( NAMESPACE_ROUTE, ROUTE, [
		'methods'             => 'GET',
		'callback'            => __NAMESPACE__ . '\\handle_request',
		'permission_callback' => permission_callback( ... ),
		'args'                => [
			'page'     => [
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			],
			'per_page' => [
				'type'    => 'integer',
				'default' => DEFAULT_PER_PAGE,
				'minimum' => 1,
				'maximum' => MAX_PER_PAGE,
			],
		],
	] );
}

/**
 * Build the roster. Identity is global; membership and roles are per site.
 *
 * @param WP_REST_Request $request Request object.
 * @return WP_REST_Response|WP_Error
 */
function handle_request( WP_REST_Request $request ) {
	$multisite = is_multisite();

	// The route schema applies these defaults for HTTP requests. Apply them here
	// as well so a direct call cannot silently change the page or the scope.
	$per_page = request_arg( $request, 'per_page', DEFAULT_PER_PAGE, 1, MAX_PER_PAGE );
	$page     = request_arg( $request, 'page', 1, 1, PHP_INT_MAX );

	$sites = sites_in_scope();
	if ( is_wp_error( $sites ) ) {
		return $sites;
	}

	$errors = [];

	$query = new WP_User_Query( [
		'blog_id'     => 0,
		'number'      => $per_page,
		'offset'      => ( $page - 1 ) * $per_page,
		'orderby'     => 'ID',
		'order'       => 'ASC',
		'count_total' => true,
	] );

	$total = (int) $query->get_total();
	$users = $query->get_results();

	// Do not infer failure from the database error state: WP_User_Query runs a
	// trailing FOUND_ROWS() query, and wpdb::flush() clears last_error on every
	// query, so a failed main query leaves nothing to read. Reconcile the returned
	// rows against the declared total instead. A short page is a hard failure:
	// presenting it as a complete roster is the exact false completeness this
	// endpoint exists to prevent.
	$offset  = ( $page - 1 ) * $per_page;
	$expected = max( 0, min( $per_page, $total - $offset ) );
	if ( count( $users ) !== $expected ) {
		return new WP_Error(
			'keyring_user_query_incomplete',
			sprintf(
				'The user roster returned %d rows where %d were expected; roster unavailable.',
				count( $users ),
				$expected
			),
			[ 'status' => 500 ]
		);
	}

	$user_ids = array_map( function ( WP_User $user ) : int {
		return (int) $user->ID;
	}, $users );
	// Passed by reference so an unreadable role set is reported rather than
	// silently substituted.
	$roles_unreadable = 0;
	$memberships = memberships_for( $user_ids, $sites, $roles_unreadable );

	// A site whose registered roles could not be read is reported rather than
	// substituted, so a consumer never receives another site's roles mislabelled.
	if ( $roles_unreadable ) {
		$errors[] = [
			'code'    => 'roles_incomplete',
			'message' => sprintf( 'Registered roles could not be read for %d site(s).', $roles_unreadable ),
		];
	}

	// Match core's is_super_admin() exactly: a case-sensitive comparison against
	// the login list, not a normalised one. Normalising here could flag a user as a
	// super admin when core would not, which matters for an access inventory.
	$super_logins = $multisite ? array_flip( get_super_admins() ) : [];

	$data = [];
	foreach ( $users as $user ) {
		$id = (int) $user->ID;

		$data[] = [
			'id'           => $id,
			'login'        => (string) $user->user_login,
			'email'        => (string) $user->user_email,
			'displayName'  => (string) $user->display_name,
			'nicename'     => (string) $user->user_nicename,
			'registeredAt' => registered_at( (string) $user->user_registered ),
			'accountType'  => is_service_login( (string) $user->user_login ) ? 'service' : 'person',
			'isSuperAdmin' => isset( $super_logins[ (string) $user->user_login ] ),
			'flags'        => user_flags( $user, $multisite ),
			'memberships'  => with_implied_memberships(
				$memberships[ $id ] ?? [],
				$sites,
				isset( $super_logins[ (string) $user->user_login ] )
			),
		];
	}

	$pages = (int) ceil( $total / $per_page );

	$response = rest_ensure_response( [
		'schema'      => SCHEMA,
		'generatedAt' => gmdate( 'c' ),
		'multisite'   => $multisite,
		'network'     => [
			'host'      => (string) wp_parse_url( network_home_url(), PHP_URL_HOST ),
			// siteCount is the whole network; includedSiteCount is what this
			// response describes. They differ when deleted or spam sites exist, and
			// reporting both means an exclusion is never invisible to a consumer.
			'siteCount'         => network_site_count(),
			'includedSiteCount' => count( $sites ),
		],
		// Report the identity that actually authenticated, not the configured
		// login, so the audit record cannot misattribute a read.
		'serviceUser' => [
			'id'           => get_current_user_id(),
			'login'        => (string) ( get_userdata( get_current_user_id() )->user_login ?? '' ),
			'isSuperAdmin' => $multisite ? is_super_admin( get_current_user_id() ) : false,
		],
		'page'        => $page,
		'perPage'     => $per_page,
		'total'       => $total,
		'pages'       => $pages,
		'complete'    => [] === $errors,
		'sites'       => $sites,
		'users'       => $data,
		'errors'      => $errors,
	] );

	$response->header( 'X-Keyring-Total', (string) $total );
	$response->header( 'X-Keyring-Pages', (string) $pages );
	$response->header( 'X-Keyring-Schema', 'v1' );

	return $response;
}

/**
 * Sites the response describes. Deleted and spam sites are excluded.
 *
 * @return array<int,array<string,mixed>>|WP_Error
 */
function sites_in_scope() {
	if ( ! is_multisite() ) {
		return [ current_site_record() ];
	}

	$sites = get_sites( [
		'number'  => 0,
		'deleted' => 0,
		'spam'    => 0,
	] );

	if ( ! is_array( $sites ) || [] === $sites ) {
		return new WP_Error(
			'keyring_no_sites',
			'The network returned no sites in scope.',
			[ 'status' => 500 ]
		);
	}

	// An incomplete enumeration is the most dangerous failure here, because every
	// later check derives its expectation from this same list and would therefore
	// agree with it. Cross-check the count against an independently scoped source,
	// and treat any shortfall as a hard failure rather than a smaller roster that
	// still reports itself complete.
	$expected = expected_site_count();
	if ( null !== $expected && count( $sites ) !== $expected ) {
		return new WP_Error(
			'keyring_site_scope_incomplete',
			sprintf(
				'The network enumerated %d sites where %d were expected; roster unavailable.',
				count( $sites ),
				$expected
			),
			[ 'status' => 500 ]
		);
	}

	return array_values( array_map( __NAMESPACE__ . '\\site_record', $sites ) );
}

/**
 * Sites expected in scope, derived independently of get_sites().
 *
 * Uses wp_count_sites(), which returns network-wide totals, and subtracts the
 * excluded lifecycles. Returns null when no independent figure is available, in
 * which case the caller falls back to trusting the enumeration.
 *
 * @return int|null
 */
function expected_site_count() : ?int {
	if ( ! is_multisite() ) {
		return 1;
	}

	$counts = wp_count_sites();
	if ( ! is_array( $counts ) || ! isset( $counts['all'] ) ) {
		return null;
	}

	$all     = (int) $counts['all'];
	$spam    = (int) ( $counts['spam'] ?? 0 );
	$deleted = (int) ( $counts['deleted'] ?? 0 );

	// A site can be flagged both spam and deleted, so intersect rather than merely
	// subtracting: subtracting twice would understate the expectation and mask a
	// shortfall.
	$excluded = $spam + $deleted - sites_flagged_both( $spam, $deleted );

	return max( 0, $all - $excluded );
}

/**
 * Count sites flagged both spam and deleted.
 *
 * Only queried when both flags are present, which is rare, so the cost is
 * incurred only when it can change the answer.
 *
 * @param int $spam Non-zero when spam sites exist.
 * @param int $deleted Non-zero when deleted sites exist.
 * @return int
 */
function sites_flagged_both( int $spam, int $deleted ) : int {
	if ( 0 === $spam || 0 === $deleted ) {
		return 0;
	}

	return count( get_sites( [
		'number'  => 0,
		'fields'  => 'ids',
		'spam'    => 1,
		'deleted' => 1,
	] ) );
}

/**
 * Serialise one site with its lifecycle flags.
 *
 * @param WP_Site $site Site object.
 * @return array<string,mixed>
 */
function site_record( WP_Site $site ) : array {
	$blog_id = (int) $site->blog_id;
	$home    = get_home_url( $blog_id, '/' );
	$host    = (string) wp_parse_url( $home, PHP_URL_HOST );

	if ( '' === $host ) {
		$host = rtrim( $site->domain . $site->path, '/' );
	}

	return [
		'id'    => $blog_id,
		'host'  => $host,
		// Host alone is not unique: a network can host several sites on one domain,
		// differing only by path (the agency site has / and /repo/). Consumers must
		// key on siteId; sitePath lets them display why two sites look alike.
		'sitePath' => (string) ( wp_parse_url( $home, PHP_URL_PATH ) ?: '/' ),
		'url'   => $home,
		'flags' => [
			'public'   => (bool) $site->public,
			'archived' => (bool) $site->archived,
			'spam'     => (bool) $site->spam,
			'deleted'  => (bool) $site->deleted,
			'mature'   => (bool) $site->mature,
		],
	];
}

/**
 * Site record for a single-site install, which has no lifecycle flags.
/**
 * Total sites on the network, including those excluded from this response.
 *
 * @return int
 */
function network_site_count() : int {
	if ( ! is_multisite() ) {
		return 1;
	}

	$count = wp_count_sites();

	return is_array( $count ) && isset( $count['all'] ) ? (int) $count['all'] : 0;
}

/**
 * Site record for a single-site install, which has no lifecycle flags.
 *
 * @return array<string,mixed>
 */
function current_site_record() : array {
	$home = home_url( '/' );

	return [
		'id'       => get_current_blog_id(),
		'host'     => (string) wp_parse_url( $home, PHP_URL_HOST ),
		'sitePath' => (string) ( wp_parse_url( $home, PHP_URL_PATH ) ?: '/' ),
		'url'      => $home,
		// Single site has no network lifecycle flags. An empty object keeps the
		// JSON shape stable for consumers instead of an empty array.
		'flags'    => new \stdClass(),
	];
}

/**
 * Membership and role for each user, per site.
 *
 * Membership comes from core's get_blogs_of_user(), which is the canonical answer
 * to which sites a user is a member of. Roles are read through core's user-meta
 * API and intersected with the site's registered roles, the rule core itself
 * applies in WP_User::get_role_caps().
 *
 * @param int[] $user_ids Users on this page.
 * @param array<int,array<string,mixed>> $sites Sites in scope.
 * @param int|null $roles_unreadable Set to the number of sites whose registered
 *   roles could not be read; roles are never substituted across sites.
 * @return array<int,array<int,array<string,mixed>>>
 */
function memberships_for( array $user_ids, array $sites, ?int &$roles_unreadable = null ) {
	global $wpdb;

	if ( [] === $user_ids || [] === $sites ) {
		return [];
	}

	$scope = [];
	foreach ( $sites as $site ) {
		$scope[ (int) $site['id'] ] = $site;
	}

	$role_names = registered_roles( array_keys( $scope ) );
	$roles_unreadable = count( array_filter( $role_names, function ( $names ) {
		return null === $names;
	} ) );

	$memberships = [];
	foreach ( $user_ids as $user_id ) {
		$user_id = (int) $user_id;

		// $all = true includes archived sites; the scope filter below decides.
		foreach ( get_blogs_of_user( $user_id, true ) as $blog ) {
			$blog_id = (int) $blog->userblog_id;
			$site    = $scope[ $blog_id ] ?? null;
			if ( ! $site ) {
				// Outside the reported scope: a deleted or spam site.
				continue;
			}

			$stored = get_user_meta( $user_id, $wpdb->get_blog_prefix( $blog_id ) . 'capabilities', true );

			$memberships[ $user_id ][] = [
				'siteId'   => $blog_id,
				'host'     => (string) $site['host'],
				'sitePath' => (string) ( $site['sitePath'] ?? '/' ),
				'roles'    => stored_roles( $stored, $role_names[ $blog_id ] ?? [] ),
			];
		}
	}

	foreach ( $memberships as $user_id => $list ) {
		usort( $list, function ( $a, $b ) {
			return $a['siteId'] <=> $b['siteId'];
		} );
		$memberships[ $user_id ] = $list;
	}

	return $memberships;
}

/**
 * Intersect a stored capabilities map with a site's registered roles.
 *
 * An empty capabilities row is still a membership, so it yields no roles rather
 * than being treated as absence of membership.
 *
 * @param mixed $stored Unserialised capabilities meta value.
 * @param string[] $role_names Registered roles for that site.
 * @return string[]
 */
function stored_roles( $stored, array $role_names ) : array {
	if ( ! is_array( $stored ) ) {
		return [];
	}

	$roles = [];
	foreach ( array_keys( $stored ) as $name ) {
		if ( is_string( $name ) && in_array( $name, $role_names, true ) ) {
			$roles[] = $name;
		}
	}

	return $roles;
}

/**
 * Registered role names per site, keyed by blog ID.
 *
 * On multisite this reads the option core's WP_Roles::for_site() uses for a site.
 * get_blog_option() and switch_to_blog() live in ms-blogs.php, which core loads
 * only when is_multisite() is true; on a single site they do not exist at all, so
 * that path must be guarded rather than assumed.
 *
 * @param int[] $blog_ids Site IDs.
 * @return array<int,string[]>
 */
function registered_roles( array $blog_ids ) : array {
	global $wpdb;

	$roles = [];
	foreach ( $blog_ids as $blog_id ) {
		$blog_id = (int) $blog_id;

		$option = is_multisite()
			? get_blog_option( $blog_id, $wpdb->get_blog_prefix( $blog_id ) . 'user_roles' )
			: get_option( $wpdb->get_blog_prefix( $blog_id ) . 'user_roles' );

		// Neither substitute wp_roles()->roles here nor leave the site out: that
		// object describes whichever blog is currently loaded, so using it would
		// attribute one site's roles to another, which spec 3.6 forbids. Record the
		// site as unreadable and let the caller report incompleteness.
		if ( ! is_array( $option ) || [] === $option ) {
			$roles[ $blog_id ] = null;
			continue;
		}

		$roles[ $blog_id ] = array_values( array_filter( array_keys( (array) $option ), 'is_string' ) );
	}

	return $roles;
}

/**
 * Multisite network flags, or an empty object on single site.
/**
 * Add the sites a super admin reaches through network-wide access alone.
 *
 * A super admin holds network-wide capabilities without necessarily being an
 * explicit member of every site, so reporting only stored memberships would
 * understate their reach. Sites already present keep their observed roles; the
 * remainder are added with an empty role list and impliedBySuperAdmin: true, so
 * implied access is never presented as observed membership.
 *
 * @param array<int,array<string,mixed>> $memberships Observed memberships.
 * @param array<int,array<string,mixed>> $sites Sites in scope.
 * @param bool $is_super_admin Whether the user is a super admin.
 * @return array<int,array<string,mixed>>
 */
function with_implied_memberships( array $memberships, array $sites, bool $is_super_admin ) : array {
	$observed = [];
	foreach ( $memberships as $index => $membership ) {
		$observed[ (int) $membership['siteId'] ] = true;
		$memberships[ $index ]['impliedBySuperAdmin'] = false;
	}

	if ( ! $is_super_admin ) {
		return $memberships;
	}

	foreach ( $sites as $site ) {
		$site_id = (int) $site['id'];
		if ( isset( $observed[ $site_id ] ) ) {
			continue;
		}

		$memberships[] = [
			'siteId'              => $site_id,
			'host'                => (string) $site['host'],
			'sitePath'            => (string) ( $site['sitePath'] ?? '/' ),
			'roles'               => [],
			'impliedBySuperAdmin' => true,
		];
	}

	usort( $memberships, function ( $a, $b ) {
		return $a['siteId'] <=> $b['siteId'];
	} );

	return $memberships;
}

/**
 * Multisite network flags, or an empty object on single site.
 *
 * @param WP_User $user User object.
 * @param bool $multisite Whether the install is multisite.
 * @return array<string,bool>|object
 */
function user_flags( WP_User $user, bool $multisite ) {
	if ( ! $multisite ) {
		return new \stdClass();
	}

	return [
		'spam'    => (bool) ( $user->spam ?? false ),
		'deleted' => (bool) ( $user->deleted ?? false ),
	];
}

/**
 * Normalise a stored WordPress registration timestamp for transport.
 *
 * The stored user_registered value is UTC, so it must be read as UTC. mysql2date()
 * interprets it in wp_timezone() instead, which shifts every timestamp by the
 * site's UTC offset (and shifts it inconsistently across a DST boundary).
 *
 * @param string $value Stored user_registered value (UTC).
 * @return string An ISO 8601 UTC timestamp, or the raw value if unparseable.
 */
function registered_at( string $value ) : string {
	$value = trim( $value );
	if ( '' === $value ) {
		return '';
	}

	$timestamp = strtotime( $value . ' UTC' );
	if ( false === $timestamp ) {
		return $value;
	}

	return gmdate( 'c', $timestamp );
}

/**
 * Read an integer request parameter with an explicit default and bounds.
 *
 * @param WP_REST_Request $request Request object.
 * @param string $key Parameter name.
 * @param int $fallback Default when the parameter is absent.
 * @param int $min Minimum accepted value.
 * @param int $max Maximum accepted value.
 * @return int
 */
function request_arg( WP_REST_Request $request, string $key, int $fallback, int $min, int $max ) : int {
	$value = $request[ $key ];
	if ( null === $value || '' === $value ) {
		return $fallback;
	}

	return max( $min, min( $max, (int) $value ) );
}
