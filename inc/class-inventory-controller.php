<?php
/**
 * Shared behaviour for the inventory collections.
 */

namespace HM\Keyring\Site_Inventory;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Server;

/**
 * Base controller for the read-only inventory collections.
 *
 * Both collections are registered the same way and gated on the same capability;
 * they differ only in what they return.
 */
abstract class Inventory_Controller extends WP_REST_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = 'keyring/v1';
	}

	/**
	 * Register the route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => [ $this, 'get_items_permissions_check' ],
				'args'                => $this->get_collection_params(),
			],
			'schema' => [ $this, 'get_public_item_schema' ],
		] );
	}

	/**
	 * Check whether the request may read the inventory.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( required_capability() ) ) {
			return new WP_Error(
				'keyring_site_inventory_cannot_view',
				__( 'Sorry, you are not allowed to read the site inventory.', 'keyring-site-inventory' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Links for one item.
	 *
	 * Collection only: these routes serve no single-item route for a self link.
	 *
	 * @return array<string,array<string,string>>
	 */
	protected function collection_links() : array {
		return [
			'collection' => [
				'href' => rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) ),
			],
		];
	}
}
