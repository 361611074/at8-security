=== At8 License Server ===
Contributors: x361611074
Tags: license, licensing, updates
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Issues licence keys for the add-ons sold from this site, and serves their updates.

== Description ==

This plugin is the server side of the add-ons distributed from the author's own
site. It issues licence keys, answers the activate, validate and deactivate
calls an add-on makes from a customer's site, and streams the update package to
sites that hold a valid key.

It is not meant to run on a customer's site and it is not distributed through
the WordPress plugin directory.

Endpoints, under `/wp-json/at8/v1/` (and at the original
`/wp-json/at8-license/v1/` address, which is kept for sites that still call it):

* `POST activate` — bind a key to a site.
* `POST validate` — re-check a key without binding anything.
* `POST deactivate` — release a site from its key.
* `POST update` — report the published version and a signed download link.
* `GET package` — stream a package to a holder of a valid, unexpired link.

Keys are stored in their own table, site bindings in a second table, and every
issued key keeps a record of the sites using it so one can be released when a
customer moves to a new domain. A third table holds the audit trail: who
created, changed or removed a licence, and from where.

A key is never stored. What the table holds is an HMAC-SHA256 of the key, keyed
with the site's authentication salt, plus the last five characters so two keys
can be told apart on screen. A copy of the database therefore contains no key
that can be presented to the activate endpoint. The key is shown once, on the
screen that follows the create form, and cannot be looked up afterwards —
if it is lost, a new key is created and the old one revoked.

Packages are published with the SHA-256 of the file as it was uploaded, and each
download is checked against it, so a package replaced on disk is refused rather
than installed. Download links are signed and expire after thirty minutes.

Licence records are commercial records. Deleting this plugin keeps them by
default; the audit screen has a switch, and `AT8LS_PURGE_ON_UNINSTALL` in
wp-config.php decides it per site.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/at8-license-server` directory, or install the plugin through the WordPress Plugins screen directly.
2. Activate the plugin through the Plugins screen.
3. Open the **At8 Licences** menu, create a key, and give it to the customer.

== Frequently Asked Questions ==

= What happens when a licence server cannot be reached? =

A customer's add-on keeps running on a grace period rather than switching its
protections off. Losing the security features because of a licensing hiccup
would be a worse outcome than a licence that is briefly overdue.

= Can a key be moved to another site? =

Yes. Release the site from the **Activated sites** tab, or use the release
button on the customer's own settings screen, and activate the key on the new
site.

== Privacy ==

This plugin runs on the vendor's own site, not on a customer's. It receives
data and sends none.

It stores, for each activated site: the hostname, the site and home URL, the
WordPress and PHP version, the reporting environment, the source IP address of
the call, and when the site last checked in. That information exists to run the
licence — to know which site holds a seat, to tell a customer which site to
release, and to notice a key being used somewhere it was not sold to. It is not
sold, shared or used for anything else.

== Changelog ==

= 1.2.0 =
* Licence keys are no longer stored. The table holds an HMAC-SHA256 of the key, keyed with the site's authentication salt, and the last five characters for identification. A leaked database backup no longer contains working keys.
* A key is shown once, on the screen that follows the create form. It is held in a short-lived, per-user entry for five minutes and deleted as it is read; the licence list shows a masked form (AT8-SEC-•••••-•••••-•••••-9K3M2) from then on.
* Licences created by an earlier version are converted on the first request after the update: the stored key is hashed, its tail kept, and the readable copy erased.
* The Activated sites tab no longer selects the key column, because there is no longer one to select.

= 1.1.0 =
* Audit log: every licence decision is recorded with the actor, the address it came from and both ends of a status change. Keys themselves are never written to it. Entries are trimmed by a daily job, and the retention window is configurable.
* Download links now expire after thirty minutes instead of six hours.
* Each package is fingerprinted with SHA-256 at upload and checked on every download.
* Releases have a status — draft, testing, published, deprecated, withdrawn — and only a published build is offered to licensed sites. Withdrawing keeps the record and the file.
* Licences can be suspended: a reversible stop that is not a revocation.
* Site records carry the full site and home URL, an environment (production, staging, development) and a site hash.
* The REST API also answers at `/wp-json/at8/v1/`, and accepts the documented field names (site_url, home_url, wp_version, php_version, environment) alongside the original ones.
* Deleting the plugin no longer destroys licence records: they are kept unless the site owner opts in, on the audit screen or with AT8LS_PURGE_ON_UNINSTALL.

= 1.0.1 =
* Bundled a Simplified Chinese translation for the licence management screens.

= 1.0.0 =
* First release.
