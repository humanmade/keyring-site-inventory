<?php
/**
 * Plugin bootstrap and authorisation.
 */

namespace HM\Keyring\Site_Inventory;

/**
 * Capability required to read the inventory.
 *
 * A capability of this plugin's own rather than list_users. WordPress treats
 * list_users as the bar for reading a complete user list, but sites relax it: on the
 * hmn.md network a plugin maps it to read, so any logged-in member holds it.
 *
 * Grant it to the account that needs it, on the site the inventory will be called
 * on:
 *
 *     wp user add-cap <account> keyring_read_site_inventory --url=<site>
 *
 * Multisite super admins hold it regardless: WP_User::has_cap() grants them every
 * capability before any plugin is consulted.
 */
const CAPABILITY = 'keyring_read_site_inventory';

/**
 * Register hooks.
 *
 * @return void
 */
function bootstrap() : void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
}

/**
 * Register the inventory routes.
 *
 * @return void
 */
function register_routes() : void {
	( new Users_Controller() )->register_routes();
	( new Sites_Controller() )->register_routes();
}

/**
 * Capability required to read the inventory.
 *
 * @return string
 */
function required_capability() : string {
	return (string) apply_filters( 'keyring_site_inventory_capability', CAPABILITY );
}
