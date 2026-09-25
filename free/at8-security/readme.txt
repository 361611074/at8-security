=== At8 Security ===
Contributors: x361611074
Tags: security, firewall, login, captcha, spam
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight WordPress hardening: hide version info, disable XML-RPC and user enumeration, throttle logins, filter requests, keep an audit log.

== Description ==

At8 Security adds the protections most sites actually need, without a dashboard full of charts and without contacting a third-party service.

Nothing is loaded from another domain. Every feature runs on your own server, so there is no account to create, no usage limit and no data leaving your site.

**Hardening**

* Hide the WordPress version in the page source and from asset URLs
* Disable XML-RPC, including the pingback methods used for reflection attacks
* Hide the user list from the REST API and block author enumeration
* Use a neutral login error so the form stops revealing whether a username exists
* Disable the built-in plugin and theme file editor
* Move the login form to a path of your choosing, with the default entry point answering 404
* Switch directory listing off and block PHP execution in the uploads folder (Apache)

**Login protection**

* Limit failed attempts per address and lock the address out for a chosen number of minutes
* Hidden honeypot field, filled in by bots and invisible to people
* A short arithmetic question on the login form — nothing is loaded from a third party
* E-mail notification when an account signs in from an address it has not used before
* Enforce a minimum password strength on profile and registration forms
* Included tool that checks stored accounts against a list of commonly used passwords

**Request filtering**

* Signature checks for SQL injection, directory traversal and command execution
* Cross-site scripting signatures tested against the URL only, so your own post content is never affected
* Blocked scanner user agents, per-address request rate limiting and 404 flood protection
* Allow list and block list, accepted as single addresses or CIDR ranges

**Response headers**

* X-Content-Type-Options, X-Frame-Options, Referrer-Policy and Permissions-Policy
* Optional Content Security Policy, sent in report-only mode by default so an incorrect policy cannot take the site down
* HTTPS redirect and optional HSTS for TLS-terminating proxies

**Comments and uploads**

* Comment spam filtering and a one-comment-per-minute limit for signed-out visitors
* A single switch that closes comments across the whole site and hides the ones already published
* Upload filename checks that reject executable types and the double-extension trick

**Audit log**

* Failed sign-ins, lockouts and settings changes recorded in a table of its own
* Automatic cleanup after a retention period you choose

**Privacy**

This plugin stores no personal data outside your own database. Addresses in the audit log and in the new-sign-in notice are hashed where they are used as keys, and the log is limited to what an administrator needs in order to see what happened.

**External services**

None. This plugin does not connect to any external service: there is no licence check, no telemetry, no statistics and no account. Every protection runs on your own server. The link to the paid add-on on the settings screen is an ordinary link — it carries nothing about your site, and nothing is sent unless you follow it.

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/at8-security/`, or install it through the Plugins screen.
2. Activate the plugin.
3. Open **At8 Security** in the admin menu and switch on the protections you want.
4. Save each tab with the button at the bottom of the page.

The settings live in a single option, `at8_security_settings`. Deleting the plugin removes that option, the audit table, the scheduled cleanup job and the `.htaccess` block it wrote.

== Frequently Asked Questions ==

= Will this break my site? =

Every setting that could lock you out is off by default, including the custom login path, HSTS and the REST API lockdown. The features that are on by default — hiding the version, disabling XML-RPC, the security headers — do not affect sign-in or front-end rendering.

= I turned on the custom login path and lost access. What now? =

Add this line to `wp-config.php` and the default form comes back:

`define( 'AT8SEC_DISABLE_CUSTOM_LOGIN', true );`

= Does the firewall replace updates? =

No. It is a second layer for requests that look wrong. Keeping WordPress, your theme and your plugins up to date remains the first line of defence.

= Does it work behind a CDN or reverse proxy? =

Yes, once you list the proxy addresses under **Firewall → Reverse proxy or CDN addresses**. Forwarded address headers are only trusted when the connection itself comes from one of those addresses, so a visitor cannot claim to be someone else.

= Is anything loaded from a third-party server? =

No. The human check is generated and verified on your own server, and no request leaves it.

= Does it work on Nginx? =

Everything except the two `.htaccess` options, which are Apache-specific. The settings screen says so, and the equivalent Nginx rules are described next to each option.

== Screenshots ==

1. Hardening and login settings.
2. Firewall rules and rate limiting.
3. Security headers and Content Security Policy.
4. Tools: weak-password check and the audit log.

== Changelog ==

= 2.0.3 =
A refusal now answers as a refusal.

* Reaching the plugin's screens without permission returns HTTP 403. It previously returned 500, which reads as "the server broke" rather than "you are not allowed" — misleading both to the person in front of the screen and to anything watching the site's error rate.

= 2.0.2 =
Chinese translation and language loading.

* Bundled a Simplified Chinese translation covering the whole admin interface.
* The interface language now follows the site language set under Settings → General (and each user's personal language choice).
* The request firewall no longer runs for WP-CLI and plain PHP scripts that bootstrap WordPress (custom cron jobs), which it previously blocked for having no remote address.

= 2.0.1 =
Fixes around the custom login path.

* The custom login entry point no longer answers 404 when the address is opened with different letter casing than the saved path, or with a percent-encoded variant.
* After saving, the settings page confirms the exact address that is now in force, and says so when the entered path had to be adjusted (for example when unsupported characters were removed).
* The current login address is shown directly below the custom path field, so it can be checked and bookmarked at any time.

= 2.0.0 =
First public release. Supersedes the 1.0.x builds that were distributed privately.

* Hardening: version fingerprint, XML-RPC, user enumeration, REST user list, file editor, RSD/WLW links, directory listing, PHP execution in uploads.
* Login: failed-attempt throttling, honeypot, arithmetic human check, new-address e-mail notice, custom login path, password policy, weak-password check.
* Firewall: SQL injection, traversal, command execution and XSS signatures, scanner user agents, request rate limiting, 404 flood protection, allow and block lists.
* Headers: X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy, Content Security Policy, HTTPS redirect, HSTS.
* Comments: honeypot, per-address rate limit, site-wide switch to close comments.
* Uploads: executable extension rejection and double-extension check.
* Audit log with a retention period and a daily cleanup job.

== Upgrade Notice ==

= 2.0.3 =
No action needed. The plugin's own screens now answer 403 rather than 500 when they are reached without permission. Nothing else changed.

= 2.0.2 =
Update if you want the Chinese admin interface, or if the plugin's request firewall ever interfered with WP-CLI or a custom cron job. The interface language now follows the site language, and the firewall no longer blocks command-line and cron requests.

= 2.0.1 =
If the custom login path ever showed you a 404, update: the entry point now accepts the address regardless of letter casing, and the settings page always shows the address that is in force.

= 2.0.0 =
First public release. If you were running an earlier private build, review the settings after updating — the proxy list and the .htaccess options are worth a second look.
