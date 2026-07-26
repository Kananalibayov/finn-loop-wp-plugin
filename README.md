# Finn-Loop Connect (WordPress Plugin)

The WordPress companion plugin for the [Finn-Loop](https://github.com/Kananalibayov/finn-loop) agency platform. Once paired, the platform can deliver generated sites to this WordPress, report on its health, and let the operator manage it remotely.

## What it does

**Phase 1 (current): Pairing-code auto-connect.**

Completes the pairing handshake that the platform's `/api/wp/pairing/register` endpoint (built in finn-loop issue #34) is waiting for:

1. Operator generates a pairing code on the platform's **Connections** page.
2. Operator installs this plugin on the client's WordPress and goes to **Tools → Finn-Loop Connect**.
3. Enters the platform URL + the pairing code → the plugin internally creates a WordPress Application Password and POSTs it to the platform.
4. The connection appears on the platform automatically — no manual password copy-paste, no credentials sent over chat.

## Installation

1. Download the plugin `.zip` from the [latest release](../../releases).
2. In WordPress admin: **Plugins → Add New → Upload Plugin** → choose the `.zip` → **Install Now** → **Activate**.
3. Go to **Tools → Finn-Loop Connect**.
4. Enter the platform URL + the pairing code → click **Connect**.

## Requirements

- WordPress 6.0+ (Application Passwords require WP 5.6+; this plugin gates on 6.0 for safety).
- PHP 7.4+.
- A user with the `manage_options` (Administrator) capability on this WordPress.
- The Finn-Loop agency platform running + reachable from this WordPress server.

## Security

- The settings form is nonce-protected (CSRF guard).
- The Application Password is **not** stored in WordPress — it's created during pairing and immediately sent to the platform, which stores it. The plugin only keeps the platform URL + connection ID locally.
- The plugin only acts for users with `manage_options`.

## Roadmap

The plugin is built in phases (tracked in the main [finn-loop](https://github.com/Kananalibayov/finn-loop) repo):

- ✅ **Phase 1** — Pairing-code auto-connect (this release).
- ⏳ **Phase 2** — Auto-login SSO from the dashboard (issue #61).
- ⏳ **Phase 3** — Health/info reporting to the platform (issue #62).
- ⏳ **Phase 4** — Remote settings sync from the dashboard (issue #63).

## License

GPL-2.0-or-later.
