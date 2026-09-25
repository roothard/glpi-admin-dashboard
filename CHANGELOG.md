# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).
A user-facing version of this changelog is published at [`docs/`](docs/index.html).

## [1.4.0] — 2026-09-25 — CRM, 2FA & modular config
### Added
- **CRM** app (native dock cube, admin/supervisor only): clients are the **child
  entities** of a **"company"** (parent entity). A company selector lets admins
  and supervisors switch context, and each client shows its contract count,
  nearest **renewal date** and **commercial owner**. Enriched from the GLPI
  *manageentities* plugin when present, and **degrades gracefully** without it
  (contracts/renewal show as N/A).
- **Two-factor authentication (2FA)**: TOTP (authenticator app, self-hosted QR)
  with an **email-OTP backup** and one-time **recovery codes**; optional org-wide
  enforcement. Every auth event (login ok/fail, throttle, 2FA) is logged as
  JSON + syslog for a SIEM (**Wazuh**). New `rh-auth.php` module + `public/2fa.php`.
- **Modular configuration panel**: a **General · Brand** section (app name/logo, a
  **separate login brand** — name, subtitle, logo — and **3 preset colour
  palettes** plus a custom colour) and a per-app section registry (Projects, CRM,
  GPS, Contact). New `Settings::sections()` and `Settings::palettes()` are the
  backbone so each app contributes its own config section.
- **Super-Admin config gear** — the setup panel is reachable from a ⚙ button in the
  header, shown **only to the Super-Admin** profile; `/setup.php` is likewise
  restricted to Super-Admin.
- Generic **drop-in modules** hook (`public/modules.php`): auto-discovers
  `public/modules/<id>/module.json` and lists them as extra cubes, filtered by
  entity.
### Changed
- **Per-session data model** — the live board is now built with **each user's own
  GLPI session** (`data.php` → `GlpiClient::useSession()` +
  `DashboardGenerator::buildLive()`) and cached in the PHP session (TTL). No
  service **user token** needs to be stored for the board; the cron generator
  remains available for a static cache.
- `config.php` now also exposes the login branding and the resolved palette accent
  (light/dark); secrets still never reach the browser.
- `public/.htaccess`: CSP `frame-ancestors 'self'` so the board can embed its own
  drop-in modules while still blocking external framing.

## [1.3.0] — 2026-09-19 — Compliance app
### Added
- **Compliance** app (fourth dock cube): critical processes verified against real
  GLPI tickets, with four states (up to date / in progress / overdue / no data) and
  a rolling 12-cycle history bar.
- Responsible **person** (Super-Admin / Supervisor profiles) and **responsible group**,
  both picked from GLPI.
- **Multi-entity selection** per process: one row per client, grouped by entity.
- New **half-yearly** frequency. Process editor with real GLPI categories, owners,
  groups and entities.

## [1.2.0] — 2026-09-18 — Tickets app
### Added
- **Tickets** app with Queue, Analytics and Hours sub-tabs.
- SLA traffic lights (assignment and resolution) computed from ticket deadlines.
- Analytics: gauges, entity × month compliance matrix, and worked-hours reports by
  client, technician and month.

## [1.1.0] — 2026-09-18 — Security & map
### Changed
- **Login hardening:** credentials sent in an `Authorization: Basic` header (never in
  URLs/logs), session id regenerated on login, per-IP brute-force throttle.
- Check-ins map migrated from CARTO to **OpenStreetMap** with a correct referrer policy.

## [1.0.0] — 2026-07 — Base
### Added
- Projects and GPS Check-ins apps, web setup wizard, custom Map area board,
  5-language UI and the "editorial grey" redesign.
