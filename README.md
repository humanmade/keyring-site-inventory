# Keyring Site Inventory

A read-only WordPress REST endpoint that returns a complete site-user roster, with
the sites each user belongs to and their role on each site. It is the WordPress half
of the [Keyring](https://github.com/humanmade/keyring.tools.hmn.md) access inventory.

## What it does

Registers one route:

    GET /wp-json/keyring/v1/site-inventory

and grants exactly one capability, `keyring_read_site_inventory`, to a single
configured service user (`keyring` by default) for the duration of an authenticated
REST request. Outside that request the account keeps no extra capability.

The route requires both that capability and that the caller *is* the configured
service user. The identity check is the one that matters: `WP_User::has_cap()`
short-circuits for super admins before any `user_has_cap` filter runs, so a super
admin holds every capability and cannot be excluded by a capability check alone.

## Why not the core users endpoint

`GET /wp/v2/users` requires the broad `list_users` capability and, when the caller
lacks it, silently restricts results to users with published posts — returning a
smaller roster that still looks complete. It also has no network-wide scope. Both
behaviours are disqualifying for an access inventory; measured on the hmn.md network,
the core route returned zero users where this endpoint returned 375.

## Installation

Install as a must-use plugin with Composer:

    composer require humanmade/keyring-site-inventory

Create the service user and give it an application password:

    wp user create keyring keyring@example.com --role=subscriber --user_pass="$(openssl rand -base64 32)"
    wp user application-password create keyring "Keyring inventory" --porcelain

Store the login and application password in Keyring's credential store as the
property's Basic pair. Do not give the account a role with real capabilities, do not
make it a super admin, and enable HTTPS.

## Configuration

| Constant | Meaning |
| --- | --- |
| `KEYRING_SERVICE_USER` | Service user login. Defaults to `keyring`. |
| `KEYRING_SERVICE_LOGINS` | Comma-separated extra machine-account logins reported as `accountType: service`. |

Filters:

| Filter | Purpose |
| --- | --- |
| `keyring_site_inventory_service_user` | Override the service user login. |
| `keyring_site_inventory_service_logins` | Reviewed list of machine-account logins. |
| `keyring_site_inventory_grant_capability` | Final veto over granting the capability. |
| `keyring_site_inventory_is_rest_request` | Test seam for the REST-request scope. |

Machine accounts are property-specific. The list is explicit rather than a name
pattern, because guessing from names would misclassify real people. If a property has
its own automation accounts (for example the agency site's `human-bot`), declare them
with the constant or the filter.

## Response

    {
      "schema": "keyring-site-inventory/v1",
      "generatedAt": "2026-09-16T10:00:00+00:00",
      "multisite": true,
      "network": { "host": "hmn.md", "siteCount": 160, "includedSiteCount": 154 },
      "serviceUser": { "id": 486, "login": "keyring", "isSuperAdmin": false },
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

- `total` and `pages` describe the whole scope and reconcile with the rows returned
  across every page.
- `complete` is true only when every site in scope was read without error. A short
  page, a partial site enumeration or an unreadable role set sets `complete: false`
  with a structured `errors` entry; a partial roster is never presented as complete.
- Membership and roles come from core (`get_blogs_of_user()`, `get_user_meta()`,
  `get_blog_option()`) and are trusted as returned.
- `sites[].id` is the unique key. `host` is **not** unique: a network can host several
  sites on one domain differing only by path, so `sitePath` accompanies it.
- Roles are per site and are intersected with that site's registered roles, so a
  custom capability stored in the capabilities meta is not reported as a role.
- Super admins carry `impliedBySuperAdmin` memberships for sites they reach through
  network access alone, with an empty role list.
- Deleted and spam sites are excluded; archived sites are included and flagged.
- No secrets: password hashes, activation keys, session tokens, user meta and
  capability maps never appear in the response.

## Single site and multisite

`is_multisite()` drives the branch. On a single site the response describes the
current site and every user has one membership; network-only fields are reported as
empty objects rather than invented values. On multisite the global users table is the
identity source and per-site capability rows are the membership source.

## Performance

Measured on the hmn.md network (154 sites in scope, 375 users): a full crawl is
~530 ms warm with no database queries, and ~5 s cold. Every read goes through core;
the plugin contains no direct SQL. Per-site role and URL reads scale with the number
of sites, so a very large network is a known limit — there is deliberately no
wall-clock budget.

## Tests

`tests/class-test-site-inventory.php` is a PHPUnit suite covering the capability
scope, 401/403/200 boundaries, envelope reconciliation, pagination, role resolution,
super-admin implied memberships, secret exclusion and multisite flags. Run it with the
host project's PHPUnit configuration.

## Guarantees

- Read-only: the route performs no writes, role changes, user creation, mail or
  content access.
- HTTPS required; unauthenticated is 401, and every other authenticated user —
  super admins included — is 403.

