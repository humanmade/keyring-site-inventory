<?php
/**
 * Sites collection for the Keyring site inventory.
 */

namespace HM\Keyring\Site_Inventory;

use WP_Site;

/**
 * Read-only collection of the sites the inventory covers.
 *
 * Site IDs here are the ones user memberships refer to, so the two collections
 * join on id.
 */
class Sites_Controller extends Inventory_Controller {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();

		$this->rest_base = 'sites';
	}

	/**
	 * Retrieve the sites in scope.
	 *
	 * The list is never paginated: a caller-selectable subset could be mistaken for
	 * the whole network, and the inventory exists to answer for the whole network.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$sites = $this->sites_in_scope();
		if ( is_wp_error( $sites ) ) {
			return $sites;
		}

		$items = [];
		foreach ( $sites as $site ) {
			$items[] = $this->prepare_response_for_collection( $this->prepare_item_for_response( $site, $request ) );
		}

		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', (string) count( $items ) );
		$response->header( 'X-WP-TotalPages', '1' );

		return $response;
	}

	/**
	 * Prepare one site for the response.
	 *
	 * The record is already the response shape, so the schema and site_record() are
	 * the only places the field list lives.
	 *
	 * @param array<string,mixed> $item Site record.
	 * @param \WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function prepare_item_for_response( $item, $request ) {
		$fields = $this->get_fields_for_response( $request );
		$data   = [];

		foreach ( $item as $field => $value ) {
			if ( rest_is_field_included( (string) $field, $fields ) ) {
				$data[ $field ] = $value;
			}
		}

		$context = ! empty( $request['context'] ) ? $request['context'] : 'view';
		$data    = $this->add_additional_fields_to_object( $data, $request );
		$data    = $this->filter_response_by_context( $data, $context );

		$response = rest_ensure_response( $data );
		$response->add_links( $this->collection_links() );

		return $response;
	}

	/**
	 * Sites the inventory describes.
	 *
	 * Deleted and spam sites are excluded. Archived sites are included and flagged,
	 * so a consumer can tell a dormant site from a live one.
	 *
	 * @return array<int,array<string,mixed>>|WP_Error Site records, or an error.
	 */
	protected function sites_in_scope() {
		$site_ids = site_ids_in_scope();
		if ( is_wp_error( $site_ids ) ) {
			return $site_ids;
		}

		if ( ! is_multisite() ) {
			return [ $this->current_site_record() ];
		}

		// Resolved from the same scope the users route uses, so a membership can never
		// reference a site this collection omits.
		$sites = get_sites( [
			'number'   => 0,
			'site__in' => $site_ids,
			'orderby'  => 'id',
		] );

		return array_map( [ $this, 'site_record' ], $sites );
	}

	/**
	 * Serialise one site.
	 *
	 * The domain and path are the network's registered values, which is what
	 * membership is recorded against. The url is the site's effective home URL, which
	 * differs when a domain is mapped.
	 *
	 * The home URL is read through WP_Site, which caches it in the site-details group.
	 * get_home_url() switches to the site on every call and never caches, and because
	 * home is autoloaded each switch also loads that site's entire alloptions array.
	 *
	 * @param WP_Site $site Site object.
	 * @return array<string,mixed>
	 */
	protected function site_record( WP_Site $site ) : array {
		$blog_id = (int) $site->blog_id;
		$home    = $site->home;

		return [
			'id'       => $blog_id,
			'url'      => trailingslashit( is_string( $home ) && '' !== $home ? $home : get_home_url( $blog_id ) ),
			'domain'   => (string) $site->domain,
			'path'     => (string) $site->path,
			'public'   => (bool) $site->public,
			'archived' => (bool) $site->archived,
			'mature'   => (bool) $site->mature,
		];
	}

	/**
	 * Site record for a single-site install, which has no network lifecycle flags.
	 *
	 * @return array<string,mixed>
	 */
	protected function current_site_record() : array {
		$url = home_url( '/' );

		return [
			'id'       => get_current_blog_id(),
			'url'      => $url,
			'domain'   => (string) wp_parse_url( $url, PHP_URL_HOST ),
			'path'     => (string) ( wp_parse_url( $url, PHP_URL_PATH ) ?: '/' ),
			'public'   => (bool) get_option( 'blog_public' ),
			'archived' => false,
			'mature'   => false,
		];
	}

	/**
	 * Collection parameters.
	 *
	 * @return array<string,mixed>
	 */
	public function get_collection_params() {
		return [
			'context' => $this->get_context_param( [ 'default' => 'view' ] ),
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
			'title'      => 'keyring-site-inventory-site',
			'type'       => 'object',
			'properties' => [
				'id'       => [
					'description' => __( 'Unique identifier for the site.', 'keyring-site-inventory' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'url'      => [
					'description' => __( 'Home URL of the site.', 'keyring-site-inventory' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'domain'   => [
					'description' => __( 'Domain the site is registered under on the network.', 'keyring-site-inventory' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'path'     => [
					'description' => __( 'Path the site is registered under, which distinguishes sites sharing a domain.', 'keyring-site-inventory' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'public'   => [
					'description' => __( 'Whether the site is public.', 'keyring-site-inventory' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'archived' => [
					'description' => __( 'Whether the site is archived.', 'keyring-site-inventory' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'mature'   => [
					'description' => __( 'Whether the site is flagged as mature.', 'keyring-site-inventory' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}
