# Keyring Site Inventory

Read-only WordPress REST routes that return the network roster: every user, the
sites they belong to, and their roles on each. It is the WordPress half of the
[Keyring](https://github.com/humanmade/keyring.tools.hmn.md) access inventory, and
works on both single site and multisite.

## Routes

    GET /wp-json/keyring/v1/users
    GET /wp-json/keyring/v1/sites

Both follow the conventions of core collection endpoints: the body is an array of
items, pagination is reported in `X-WP-Total` and `X-WP-TotalPages`, `page`,
`per_page`, `context` and `_fields` behave as they do elsewhere, and the schema is
published by the route (`OPTIONS`, or the `schema` link) rather than in the body.

A user record:

    {
      "id": 12,
      "username": "jane",
      "name": "Jane Doe",
      "slug": "jane",
      "email": "jane@humanmade.com",
      "registered_date": "2024-01-02T03:04:05+00:00",
      "is_super_admin": false,
      "is_spam": false,
      "is_deleted": false,
      "memberships": [
        { "site_id": 2, "roles": [ "subscriber" ], "implied_by_super_admin": false }
      ]
    }

A site record:

    {
      "id": 2,
      "url": "https://updates.hmn.md/",
      "domain": "updates.hmn.md",
      "path": "/",
      "public": false,
      "archived": false,
      "mature": false
    }

`memberships[].site_id` is the join key into the sites collection. A network can
run several sites on one domain, differing only by path, so `domain` and `path`
together identify a site for display; `id` identifies it for joins.

## Authorization

Both routes require the `keyring_read_site_inventory` capability. An account holds
it by explicit grant, or by being a multisite super admin, which WordPress grants
every capability before any plugin is consulted.

The plugin defines its own capability because `list_users` is routinely relaxed: on
the hmn.md network a plugin maps it to `read`, so every logged-in member holds it
and could read every email address on the network. `manage_options` has the same
problem at network scale, since each site has its own administrators.

Grants are per site, because that is where WordPress stores capabilities. Grant on
the site the inventory will be called on, and call that same site: a grant on
`updates.hmn.md` authorises `https://updates.hmn.md/wp-json/keyring/v1/users` and
nothing else, while the response still describes the whole network.

The service account appears in its own inventory as a member of that site, with
whatever role it holds there.

Authentication is whatever the site already accepts; an application password is the
usual choice because it is revocable and scoped to one account.

| Filter | Purpose |
| --- | --- |
| `keyring_site_inventory_capability` | Capability required to read either collection. Defaults to `keyring_read_site_inventory`. |

## Why not the core users endpoint

`GET /wp/v2/users` requires `list_users` and, when the caller lacks it, restricts
results to users with published posts, returning a smaller roster that still looks
complete. It also has no network-wide scope. Measured on the hmn.md network, this
route returns 378 users across 154 sites from a single call.

## Completeness

A short roster would be read as a complete one, so three conditions return a 500
rather than a smaller result:

- the network enumerates no sites (`keyring_site_inventory_no_sites`);
- a site's registered roles cannot be read, which would report its members as
  having no roles (`keyring_site_inventory_roles_unavailable`);
- a page returns fewer rows than the declared total implies
  (`keyring_site_inventory_incomplete_page`).

Deleted and spam sites are out of scope; archived sites are in scope and flagged.
Roles are intersected with the roles each site has registered, which is the rule
`WP_User::get_role_caps()` applies, so a custom capability stored beside them is
reported as a capability rather than a role. A user with an empty capabilities row
is still a member of that site, matching WordPress.

Super admins reach every site on a network without being a member of each one.
Those sites appear in `memberships` with `implied_by_super_admin` set and an empty
role list, which keeps implied reach and observed membership distinguishable.

## Layout

    keyring-site-inventory.php          Plugin header, requires inc/ and boots
    inc/namespace.php                   Hooks and the capability
    inc/sites.php                       Site scope and per-site role names
    inc/class-inventory-controller.php  Route registration and authorization
    inc/class-users-controller.php      The /users collection
    inc/class-sites-controller.php      The /sites collection
    tests/bootstrap.php                 Loads the WordPress test library
    tests/wp-tests-config.php           Test database and core paths

## Installation

As an MU plugin, copy the directory into `content/mu-plugins/` with a loader file
beside it. As a conventional plugin, place it in `content/plugins/` and activate
it.

Create the machine account with a role that grants `read`, such as subscriber, and
grant it the capability on the site Keyring will call. Multisite rejects usernames
containing anything but lowercase letters and numbers, so no hyphens:

    wp user create keyringservice keyring@example.com --role=subscriber --url=https://updates.hmn.md
    wp user add-cap keyringservice keyring_read_site_inventory --url=https://updates.hmn.md
    wp user list-caps keyringservice --url=https://updates.hmn.md

The `read` capability matters on any property running `hm-require-login`, which
gates every request on `current_user_can( 'read' )` before the route is reached. A
roleless account authenticates but is stopped at that wall, which returns an empty
`401` rather than JSON. A JSON `403` with `keyring_site_inventory_cannot_view` means
the request got through and the account lacks the inventory capability.

Revoking is the same command in reverse, and takes effect immediately:

    wp user remove-cap keyringservice keyring_read_site_inventory --url=https://updates.hmn.md

Then create a credential for that account:

    wp user application-password create keyringservice "Keyring inventory" --porcelain

Store the login and application password in Keyring's credential store as the
property's Basic pair. WordPress requires HTTPS for application passwords, so the
payload travels encrypted.

## Switching into sites

When core last discussed a sites endpoint it required one without `switch_to_blog()`,
because each switch loads that site's autoloaded options.

Site scope is therefore read as IDs only, and membership is derived from the
capabilities meta keys rather than `get_blogs_of_user()`, which reads each site's
name and URL through `WP_Site` and switches once per site. The key parsing here is
the rule that function applies internally.

Registered roles still switch: `get_blog_option()` does so for any site other than
the current one, as core's own `WP_Roles::get_roles_data()` does. Roles are read
only for the sites a page references, so the cost scales with the page rather than
the network. Measured on hmn.md, a page of 100 users spanning 154 sites made 153
switch and restore pairs, 2 queries and 0.06s warm.

The sites collection resolves each home URL through `WP_Site`, so the result is
cached in the `site-details` group.

## Tests

`tests/` covers authorization, the collection shape and its headers, pagination,
parameter validation, schema publication, role resolution, super-admin reach, the
completeness failures and the switch count. The suite extends `WP_UnitTestCase` and
dispatches through the REST server, so it exercises the contract a client gets.

Site scope is asserted on the `get_sites()` query, and lifecycle flags by updating
an existing site. Creating a site in a test issues `CREATE TABLE`, which the test
case rewrites to `CREATE TEMPORARY TABLE`; those tables belong to the connection
rather than the transaction, so they survive the rollback that removes the matching
`wp_blogs` rows and later tests then run against an inconsistent network.

    composer test
    composer test:multisite

WordPress core is a dev dependency, so `composer install` puts it in `wordpress/`
and there is nothing else to fetch. Only the database needs configuring, through
`WP_DB_NAME`, `WP_DB_USER`, `WP_DB_PASSWORD` and `WP_DB_HOST`; `WP_CORE_DIR` points
at a different checkout if you want one. The defaults are in
`tests/wp-tests-config.php`. The suite drops and recreates its tables, so give it a
database of its own.

CI runs both suites, plus PHPCS against `humanmade/coding-standards` and a syntax
lint.
