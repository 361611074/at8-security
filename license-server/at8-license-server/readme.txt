=== At8 License Server ===
Contributors: x361611074
Tags: license, licensing, updates
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
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

Endpoints, all under `/wp-json/at8-license/v1/`:

* `POST activate` — bind a key to a site.
* `POST validate` — re-check a key without binding anything.
* `POST deactivate` — release a site from its key.
* `POST update` — report the published version and a signed download link.
* `GET package` — stream a package to a holder of a valid, unexpired link.

Keys are stored in their own table with an index on the key, site bindings in a
second table, and every issued key keeps a record of the sites using it so one
can be released when a customer moves to a new domain.

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

== Changelog ==

= 1.0.1 =
* Bundled a Simplified Chinese translation for the licence management screens.

= 1.0.0 =
* First release.
