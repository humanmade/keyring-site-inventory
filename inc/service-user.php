<?php
/**
 * Shared identity and authorization for the Keyring WordPress service user.
 */

namespace HM\Keyring\Service_User;

use WP_Error;
use WP_User;

const LOGIN = 'keyring';
const CAPABILITY = 'keyring_read_site_inventory';

/**
 * Machine logins reported as service accounts rather than people.
 *
 * An explicit reviewed list, never a name pattern: guessing from names would
 * misclassify real people. Properties add their own automation accounts through
 * the keyring_site_inventory_service_logins filter, because a property's machine
 * accounts are property-specific (the hmn.md network and the agency site have
 * different ones).
 *
 * Paths to add a property account:
 * - define( 'KEYRING_SERVICE_USER', 'login' ) for the Keyring service account;
 * - add_filter( 'keyring_site_inventory_service_logins', fn( $l ) => [ ...$l, 'human-bot' ] );
 */
const SERVICE_LOGINS = [
	'keyring',
];

add_filter( 'user_has_cap', __NAMESPACE__ . '\\grant_capability', 20, 4 );

/**
 * Login of the service user allowed to call the inventory route.
 *
 * @return string
 */
function login() : string {
	$login = defined( 'KEYRING_SERVICE_USER' ) ? (string) KEYRING_SERVICE_USER : LOGIN;

	return (string) apply_filters( 'keyring_site_inventory_service_user', $login );
}

/**
 * Whether a user ID is the configured service user.
 *
 * @param int $user_id User ID.
 * @return bool
 */
function is_service_user( int $user_id ) : bool {
	$user = get_userdata( $user_id );

	return (bool) ( $user && $user->user_login === login() );
}

/**
 * Grant the single capability only for an authorised REST request by the service user.
 *
 * WP_User::has_cap() applies this filter on every call, so the capability is added
 * dynamically and is never persisted to the user's stored capabilities.
 *
 * @param array<string,bool> $allcaps All capabilities for the user.
 * @param string[] $caps Capabilities being checked.
 * @param array<int,mixed> $args Arguments passed to the capability check.
 * @param WP_User $user User object.
 * @return array<string,bool>
 */
function grant_capability( array $allcaps, array $caps, array $args, WP_User $user ) : array {
	if ( ! in_array( CAPABILITY, $caps, true ) ) {
		return $allcaps;
	}

	$allowed = is_rest_request()
		&& is_service_user( (int) $user->ID )
		&& (bool) apply_filters( 'keyring_site_inventory_grant_capability', true, $user );

	if ( $allowed ) {
		$allcaps[ CAPABILITY ] = true;
	}

	return $allcaps;
}

/**
 * Whether the current request is a REST request.
 *
 * HTTPS and authentication are enforced by the route's permission callback; this
 * only scopes when the dynamic capability is granted.
 *
 * @return bool
 */
function is_rest_request() : bool {
	return (bool) apply_filters(
		'keyring_site_inventory_is_rest_request',
		defined( 'REST_REQUEST' ) && REST_REQUEST
	);
}

/**
 * Permission callback for the inventory route.
 *
 * The capability check alone is not sufficient. WP_User::has_cap()
 * short-circuits for super admins before the 'user_has_cap' filter runs, so a
 * super admin holds every capability including this one, regardless of what this
 * plugin grants. Identity is therefore checked directly: only the configured
 * service user may read the roster.
 *
 * @return true|WP_Error
 */
function permission_callback() {
	if ( ! is_ssl() ) {
		return new WP_Error(
			'keyring_https_required',
			'HTTPS is required.',
			[ 'status' => 403 ]
		);
	}

	if ( ! is_user_logged_in() ) {
		return new WP_Error(
			'keyring_not_authenticated',
			'Authentication is required.',
			[ 'status' => 401 ]
		);
	}

	// Must come after the authenticated check so an anonymous request stays a 401.
	$user_id = (int) get_current_user_id();
	if ( ! is_service_user( $user_id ) ) {
		return new WP_Error(
			'keyring_forbidden_user',
			'This endpoint is available only to the Keyring service user.',
			[ 'status' => 403 ]
		);
	}

	if ( ! current_user_can( CAPABILITY ) ) {
		return new WP_Error(
			'keyring_forbidden_user',
			'This endpoint is available only to the Keyring service user.',
			[ 'status' => 403 ]
		);
	}

	return true;
}

/**
 * Whether a login is a reviewed machine account.
 *
 * The configured service login is always included, so changing
 * KEYRING_SERVICE_USER does not leave the plugin reporting its own account as a
 * person.
 *
 * @param string $login WordPress login.
 * @return bool
 */
function is_service_login( string $login ) : bool {
	$configured = defined( 'KEYRING_SERVICE_LOGINS' )
		? array_map( 'trim', explode( ',', (string) KEYRING_SERVICE_LOGINS ) )
		: [];

	$logins = array_merge( (array) SERVICE_LOGINS, $configured, [ login() ] );
	$logins = (array) apply_filters( 'keyring_site_inventory_service_logins', $logins );
	$logins = array_map( 'strtolower', array_map( 'strval', $logins ) );

	return in_array( strtolower( $login ), $logins, true );
}
