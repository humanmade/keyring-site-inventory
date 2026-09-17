<?php
/**
 * Site scope shared by the inventory routes.
 */

namespace HM\Keyring\Site_Inventory;

use WP_Error;

/**
 * Site IDs the inventory covers.
 *
 * The users route needs only the scope, not each site's options, so this stays a
 * single query. Reading full site records would switch into every site on the
 * network to resolve its home URL.
 *
 * @return int[]|WP_Error Site IDs, or an error.
 */
function site_ids_in_scope() {
	if ( ! is_multisite() ) {
		return [ get_current_blog_id() ];
	}

	$site_ids = get_sites( [
		'number'  => 0,
		'deleted' => 0,
		'spam'    => 0,
		'fields'  => 'ids',
	] );

	if ( ! is_array( $site_ids ) || [] === $site_ids ) {
		return new WP_Error(
			'keyring_site_inventory_no_sites',
			__( 'The network returned no sites.', 'keyring-site-inventory' ),
			[ 'status' => 500 ]
		);
	}

	return array_map( 'intval', $site_ids );
}

/**
 * Registered role names per site, keyed by site ID.
 *
 * Reads the option WP_Roles::for_site() uses, because roles are per site and the
 * loaded wp_roles() object describes whichever site is current. get_blog_option()
 * lives in ms-blogs.php, which core loads only on multisite.
 *
 * Switches into each site other than the current one, because get_blog_option()
 * does, as core's own WP_Roles::get_roles_data() also does. Callers pass only the
 * sites a page references, so the cost scales with the page.
 *
 * A site whose roles cannot be read is recorded as null rather than falling back to
 * the current site's roles, which would attribute one site's roles to another.
 *
 * @param int[] $site_ids Site IDs.
 * @return array<int,string[]|null>
 */
function registered_roles( array $site_ids ) : array {
	global $wpdb;

	$roles = [];
	foreach ( $site_ids as $site_id ) {
		$site_id = (int) $site_id;
		$option_name = $wpdb->get_blog_prefix( $site_id ) . 'user_roles';

		$option = is_multisite()
			? get_blog_option( $site_id, $option_name )
			: get_option( $option_name );

		$roles[ $site_id ] = is_array( $option ) && [] !== $option
			? array_values( array_filter( array_keys( $option ), 'is_string' ) )
			: null;
	}

	return $roles;
}
