# Keyring Site Inventory

A read-only WordPress REST endpoint that returns a complete site-user roster, with
the sites each user belongs to and their role on each site. It is the WordPress half
of the [Keyring](https://github.com/humanmade/keyring.tools.hmn.md) access inventory.

## What it does

Registers one route:

    GET /wp-json/keyring/v1/site-inventory

Authorization is standard WordPress. The route requires HTTPS, an authenticated
user, and a capability of this plugin's own, `keyring_read_site_inventory`, which is
granted to accounts holding `manage_options` (administrators by default). Sites can
widen or narrow that with filters; see Configuration.

The plugin defines **no identity system**: no service user to create, and no
account it recognises by name. Authentication is whatever the site already accepts,
so an application password on any qualifying account will do, and nothing is written
to that account's stored capabilities.

It does define its own capability, rather than gating on `list_users`. WordPress uses
`list_users` as the bar for reading a complete user list, but sites routinely relax
it: on the hmn.md network a plugin maps it to `read`, so every logged-in member holds
it and could read every email address on the network. A capability of our own cannot
be relaxed by accident, and holding it is granted through the ordinary capability
system rather than by naming an account.

## Why not the core users endpoint

`GET /wp/v2/users` requires the broad `list_users` capability and, when the caller
lacks it, silently restricts results to users with published posts — returning a
smaller roster that still looks complete. It also has no network-wide scope. Both
behaviours are disqualifying for an access inventory; measured on the hmn.md network,
the core route returned zero users where this endpoint returned 375.

## Structure

    keyring-site-inventory.php        MU loader (required by WordPress's top-level scan)
    keyring-site-inventory/
      keyring-site-inventory.php      Plugin header; requires the inc/ files
      inc/site-inventory.php          The REST route, authorization and roster
      tests/class-test-site-inventory.php

This mirrors the existing `human-bot.php` + `human-bot/` layout used elsewhere in
the network's MU plugins: one loader at the top level, implementation split into
`inc/` files under a `HM\Keyring` namespace.

## Installation

Copy the directory into `content/mu-plugins/` with the `keyring-site-inventory.php`
loader beside it. As a conventional plugin, place the directory in
`content/plugins/` and activate `keyring-site-inventory.php`.

On the hmn.md network, MU plugins in `content/mu-plugins/` auto-load through
WordPress's standard top-level scan, so no `loader.php` change is needed.

Then create a credential for a machine caller. Any account that qualifies works
(an administrator by default);
an application password is the usual choice because it is revocable and scoped to
that account:

    wp user application-password create <account> "Keyring inventory" --porcelain

Store the login and application password in Keyring's credential store as the
property's Basic pair. Enable HTTPS: the payload contains email addresses.

## Configuration

| Constant | Meaning |
| --- | --- |
| `KEYRING_SERVICE_LOGINS` | Comma-separated machine-account logins to report as `accountType: service`. Empty by default. |

Filters:

| Filter | Purpose |
| --- | --- |
| `keyring_site_inventory_required_capability` | Capability required to read the roster. Defaults to `keyring_read_site_inventory`. |
| `keyring_site_inventory_granting_capability` | Capability whose holders are granted the read capability. Defaults to `manage_options`. |
| `keyring_site_inventory_grant_capability` | Per-user override; return true to grant the read capability to a specific account. |
| `keyring_site_inventory_service_logins` | Reviewed list of machine-account logins reported as `accountType: service`. |

`KEYRING_SERVICE_LOGINS` and the filter only affect the `accountType` field. They
have no bearing on who may call the endpoint. Machine accounts are property-specific
and nothing is assumed by default, so declare your own; guessing from names would
misclassify real people.

## Response

    {
      "schema": "keyring-site-inventory/v1",
      "generatedAt": "2026-09-16T10:00:00+00:00",
      "multisite": true,
      "network": { "host": "hmn.md", "siteCount": 154, "includedSiteCount": 154 },
      "requestedBy": { "id": 486, "login": "keyring", "isSuperAdmin": false },
      "page": 1, "perPage": 100, "total": 375, "pages": 4,
      "complete": true,
      "sites": [ { "id": 2, "host": "updates.hmn.md", "sitePath": "/", "url": "https://updates.hmn.md/", "flags": { "public": false, "archived": false, "spam": false, "deleted": false, "mature": false } } ],
      "users": [ {
        "id": 12, "login": "jane", "email": "jane@humanmade.com",
        "displayName": "Jane Doe", "nicename": "jane",
        "registeredAt": "2024-01-02T03:04:05+00:00",
        "accountType": "person", "isSuperAdmin": false,
        "flags": { "spam": false, "deleted": false },
        "memberships": [ { "siteId": 2, "host": "updates.hmn.md", "sitePath": "/", "roles": [ "subscriber" ], "impliedBySuperAdmin": false } ]
      } ],
      "errors": []
    }

Headers: `X-Keyring-Total`, `X-Keyring-Pages`, `X-Keyring-Schema`.

Rules:

- `total` and `pages` describe the whole scope and reconcile with the rows
  returned across every page.
- `complete` is true only when every site in scope was read without error. A
  failed or skipped site sets `complete: false` and adds a structured `errors`
  entry; a partial roster is never presented as complete.
- Membership and roles are taken from core (`get_blogs_of_user()`, `get_user_meta()`,
  `get_blog_option()`) and trusted as returned.
- Identity is global (the WordPress user ID is the merge key); roles are
  per-site. Only registered roles are reported, so custom capabilities stored in
  the capabilities meta are not mistaken for roles. A user with an empty
  capabilities row is still a site member, matching WordPress.
- Super-admin status is separate evidence from the `administrator` role.
- Deleted and spam sites are excluded. Archived sites are included and flagged.
- No secrets: absent from the payload are password hashes, activation keys,
  session tokens, user meta, capability maps and plugin data.

## Single site and multisite

`is_multisite()` drives the branch. On a single site the response describes the
current site and every user has one membership; network-only fields are reported
as empty objects rather than invented values. On multisite the global users table
is the identity source and per-site capability rows are the membership source, so
one person across subsites is one user with several memberships.

## Tests

`tests/class-test-site-inventory.php` covers the capability scope, 401/403/200
boundaries, envelope reconciliation, pagination, role resolution, secret
exclusion, machine-account classification and multisite flags. Add the directory
to the host project's PHPUnit configuration to run it.

The plugin is written to the network's coding standards. It passes the project's
PHPCS ruleset (`humanmade/coding-standards`) with no errors in the implementation
files and no errors in the test suite. The one remaining finding is the MU-loader
file-comment warning that `human-bot.php` and the other top-level MU plugins also
have.

## Guarantees

- Read-only: the route performs no writes, role changes, user creation, mail or
  content access.
- HTTPS required; unauthenticated is 401, and an authenticated user without the
  required capability is 403.
- The route is registered only by this plugin and does not alter core endpoint
  permissions.
