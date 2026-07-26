=== Finn-Loop Connect ===
Contributors: kananalibayov
Tags: agency, management, pairing, application-passwords, remote
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects this WordPress to your Finn-Loop agency platform via a one-time pairing code. Enables remote management, health reporting, and single sign-on from the dashboard.

== Description ==

Finn-Loop Connect is the companion plugin for the Finn-Loop agency platform. Once paired, the platform can deliver generated sites to this WordPress, report on its health, and (in future versions) let the operator manage it remotely without logging in separately.

**Phase 1 (this version):** Pairing-code auto-connect.

1. On the agency platform: go to **Connections → Generate Pairing Code** and copy the code.
2. On this WordPress: go to **Tools → Finn-Loop Connect**, paste the platform URL + the code, and click **Connect**.
3. The plugin creates a WordPress Application Password for the current user and sends it to the platform. A connection row appears on the platform's Connections page automatically — no manual password copy-paste.

The plugin stores only the platform URL + connection ID locally. The Application Password lives on the platform side (where it's used to call this WordPress's REST API).

== Installation ==

1. Download the plugin `.zip`.
2. In WordPress admin: **Plugins → Add New → Upload Plugin** → choose the `.zip` → **Install Now** → **Activate**.
3. Go to **Tools → Finn-Loop Connect**.
4. Enter your agency platform URL + the pairing code from the platform → click **Connect**.

== Frequently Asked Questions ==

= Where is the Application Password stored? =

It isn't. The plugin creates the password during pairing and immediately sends it to the platform, which stores it (encrypted at rest in a future version). The plugin never persists the plaintext password locally — only the platform URL and connection ID.

= What happens when I click Disconnect? =

The local connection state is cleared. The platform-side connection still exists until you delete it there. If you want to fully unpair, disconnect here AND delete the connection on the platform.

= Can I pair as a non-admin user? =

No. The settings page and pairing require the `manage_options` capability, and Application Passwords are created for the current user. Pair as an administrator; the platform then uses that user's credentials for REST API access.

== Changelog ==

= 0.3.0 =
* Health reporting: the plugin now reports WP version, active theme, plugin count, and a computed health score to the platform daily + on pairing. Surfaced on the connection card.

= 0.2.0 =
* SSO auto-login: the platform's connection card gains a "Log into WP" button that opens this WP's admin in a new tab, logged in as the paired user. Uses single-use, 5-minute-expiry tokens validated against the platform.

= 0.1.0 =
* Initial release.
* Pairing-code auto-connect: Tools → Finn-Loop Connect settings page.
* Creates an Application Password and registers with the platform.
* Connection status display + Disconnect button.

== Upgrade Notice ==

= 0.2.0 =
Adds SSO auto-login from the platform dashboard. Update the plugin + re-pair is NOT required — existing pairings work automatically.

= 0.1.0 =
First release. Requires WordPress 6.0+ and the Finn-Loop agency platform.
