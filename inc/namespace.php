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
 * User meta key the Disable Accounts plugin sets on a disabled account.
 *
 * Disable Accounts (humanmade/disable-accounts, bundled as the Altis Security
 * `disable-accounts` feature) owns this flag. It is read rather than required, so
 * the route reports the same fact on a network where that plugin is not loaded.
 */
const DISABLED_META_KEY = '_hm_disableaccounts_disabled';

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

/**
 * Whether an account has been disabled.
 *
 * Disable Accounts deliberately leaves stored per-site roles in place so access can
 * be restored later, and it withdraws access at runtime instead: it randomises the
 * password, drops the sessions and wipes capabilities through user_has_cap. The
 * stored roles this route reports are therefore not evidence that the account can
 * still sign in, and this flag is.
 *
 * The plugin's own check is preferred when it is loaded, so its definition of
 * disabled stays authoritative rather than being reimplemented here.
 *
 * @param \WP_User $user User to check.
 * @return bool
 */
function user_is_disabled( \WP_User $user ) : bool {
	$check = 'DisableAccounts\\is_disabled';
	if ( function_exists( $check ) ) {
		return (bool) call_user_func( $check, $user );
	}

	return 'yes' === get_user_meta( (int) $user->ID, DISABLED_META_KEY, true );
}
