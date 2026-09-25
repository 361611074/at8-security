=== At8 Security Pro ===

Contributors: x361611074
Author URI: https://www.at8.fun/
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.0.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds two-factor authentication, a hosted anti-bot challenge and the maintenance tools to At8 Security.

== Description ==

At8 Security Pro adds the features that did not belong in the free plugin:
either because they need a deliberate decision from the owner, or because they
talk to a service outside the site.

The free plugin carries none of this code. With the add-on absent or its
licence lapsed the free download behaves exactly as it does on its own, and
nothing on its screens is a tease — the free plugin advertises only features
that exist here.

**What it adds**

* **Two-factor authentication with recovery codes.** Time-based codes, checked
  against an authenticator app. Each person enrols from their own profile
  screen. Recovery codes are issued once and shown once, and a browser can be
  remembered for a set number of days.
* **A hosted anti-bot challenge on the sign-in form**, as an alternative to the
  free plugin's arithmetic question. This is the one feature that loads
  anything from a third party, so it is off until it is switched on and both
  keys are filled in.
* **Automatic detection of CDN and proxy address ranges.** Behind a CDN every
  request arrives from the same few addresses; without this the login limiter
  counts every visitor in the world as one person. The network is confirmed
  from the connection itself on each request, so a site that stops using a CDN
  stops trusting it immediately.
* **File integrity monitoring.** A fingerprint of every core, plugin and theme
  file is recorded while the site is known good, and any later difference is
  listed. It is read-only: nothing is ever deleted or modified.
* **An inactive-account report.** Accounts that have gone quiet, with the date
  they were last used. It reports and nothing more.

== Installation ==

1. Install and activate the free At8 Security plugin first.
2. Upload the `at8-security-pro` folder to `/wp-content/plugins/`, or install
   the zip through the Plugins screen.
3. Activate the add-on.
4. Open **At8 Security → Pro**, paste your licence key and press Activate.
5. Switch on the individual features you want. Nothing is switched on by
   activating the plugin.

== Frequently Asked Questions ==

= What happens if the licence server cannot be reached? =

The features keep running. A failed check is not a rejected key: after the last
successful check the protections stay in place for 14 days, and a key the
server explicitly rejects gets a further 3 days before the paid features stop.
A security feature that switched itself off because a licence server had a bad
afternoon would be worse than the licence problem it was trying to report.

= Can I move the licence to another site? =

Yes. Press "Release this site" on the Pro tab, then activate the same key on
the new site.

= Will two-factor lock me out if I lose my phone? =

Not permanently, and this is the reason the design looks the way it does. You
have recovery codes from the moment you enrol. If those are gone too, an
administrator can reset two-factor for your account from your user profile.
And application passwords and WP-CLI are untouched throughout, so there is
always a way in that does not involve the authenticator app.

= Does the file integrity scan delete anything? =

No. It reports what differs and leaves the decision to you.

== External Services ==

This plugin talks to two services, both of them for functions you asked for.
Nothing is sent for analytics, tracking or marketing.

= AT8 licence server =

Service: https://www.at8.fun/

Data sent: the licence key, the site domain and site URL, the WordPress
version, the PHP version, and whether the site reports itself as production,
staging or development.

When data is sent: when a key is activated, on the twice-daily licence check,
when the Plugins screen checks for updates, and when the key is released.

Purpose: this is the licence itself. The server uses it to decide whether the
key is valid, whether it still has a free site seat, and which build the site
is entitled to download. It also records the calling IP address and the last
time the site checked in, so that "which site is using my key" can be answered.

The server does not receive post content, user records, form submissions or any
other data from your site.

Privacy Policy: https://www.at8.fun/privacy/

= Cloudflare Turnstile =

Service: https://challenges.cloudflare.com/turnstile/v0/siteverify

Data sent: the Turnstile challenge token and the visitor's IP address.

When data is sent: when a visitor submits a form on the site, and only if
Turnstile has been configured and switched on under At8 Security Pro → Forms.

Purpose: to tell a person apart from a bot filling in forms automatically. The
form contents themselves are not sent.

Privacy Policy: https://www.cloudflare.com/privacypolicy/

Terms: https://www.cloudflare.com/website-terms/

= Turning the second one off =

Turnstile is optional. With it switched off, no request is made to Cloudflare
and forms fall back to the plugin's own honeypot and rate limiting.

== Changelog ==

= 2.0.3 =
A refusal now answers as a refusal.

* Permission refusals from the add-on's tools and the two-factor screen return HTTP 403 instead of 500, which read as "the server broke" rather than "you are not allowed".
* A tool request that names no action returns HTTP 400 rather than 500.

= 2.0.2 =
* Bundled a Simplified Chinese translation. The interface language now follows the site language and each user's personal language choice.

= 2.0.0 =
* First release of the add-on.

== Upgrade Notice ==

= 2.0.3 =
No action needed. Permission refusals from the add-on's tools and the two-factor screen now answer 403 rather than 500, and a tool request that names no action answers 400.

= 2.0.2 =
Update to get the Simplified Chinese interface.
