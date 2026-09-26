# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).
A user-facing version of this changelog is published at [`docs/`](docs/index.html).

## [1.5.0] — 2026-09-26 — macOS UI, per-user themes & login polish
### Added
- **Per-user accent palette** — a palette button in the toolbar (visible to
  everyone) lets each user pick their accent colour, stored per-browser
  (localStorage) **without touching the global config**; the setup gear stays
  Super-Admin only.
- **macOS-style colour themes** — each preset tints the whole material
  (vibrancy) via `color-mix`, not just the button; instance-overridable names.
- **Loading spinner** after login/2FA with the brand logo, to avoid the
  pre-load flash.
- **Show/hide password** toggle on the login form (i18n, 5 languages).
- **Dark mode on the login screen** — the login now follows the theme (manual
  toggle + system auto); it was previously always light.
### Changed
- **macOS UI pass** (no behavioural changes): floating glass toolbar island that
  reclaims space when hidden (animated), monochrome SVG icons with subtle
  separators, language as a pill, refined KPI tiles, tables and cards.
- Setup panel: **collapsible sections grouped** into Configuration / Dashboards /
  Personalisation, keeping the numbering.
### Fixed
- **Fichadas** showed check-in/out times +3h (UTC): now converted to local time
  (`America/Argentina/Buenos_Aires`); the MySQL server runs in UTC.
- Setup **"Pick from GLPI"** (Projects) failed with
  `ERROR_LOGIN_PARAMETERS_MISSING` because it used the empty service token; it
  now uses the logged-in admin's GLPI session (same as CRM entities).
- Tickets search placeholder wrongly said "Search project…"; now "Search ticket…".

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
