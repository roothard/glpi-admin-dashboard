# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).
A user-facing version of this changelog is published at [`docs/`](docs/index.html).

## [1.7.2] — 2026-10-03 — Multilingual consistency pass
### Added
- **Localized drop-in module names**: a module manifest can declare
  `nombre_i18n` (per-language names); the app bar, the dashboard dock and the
  header show the name in the UI language, falling back to `nombre`.
- **Drop-in modules can ship a UI translation file**: a module's page reads its
  strings from an `i18n.json` and follows the dashboard language. The bundled
  **email-signature module is now fully translated** (ES/EN/FR/DE/PT) — labels,
  the Gmail step-by-step, warnings and the generated confidentiality notice —
  updating live when the language changes.
### Changed
- **One language across the app**: the config panel now shares the dashboard's
  language (same `rh-lang` key), so switching language in one carries over to the
  other (and updates live).
### Fixed
- The **app-bar chips in the config panel** showed Spanish labels regardless of
  the UI language; they now translate with the selected language (like the
  dashboard dock cubes).

## [1.7.1] — 2026-10-03 — Setup theme unified with the dashboard
### Changed
- **Setup panel theme unified with the dashboard**: same tinted palette (the
  user's accent tints the material via `color-mix`), background vignette, display
  font (Space Grotesk) and **shared theme/accent state** (via `rh-theme` + the
  public `config.php`), updating live — the config panel no longer carries its own
  separate palette.
- Setup now uses the **full content width** (matching the dashboard), the app bar
  shrinks to fit its chips, and the sections behave as an **accordion** (only one
  open at a time).
### Added
- **"Back to dashboard"** button in the setup header (i18n, 5 languages).
### Fixed
- Setup **"Test connection"** and **"Fetch from GLPI"** (app name) failed with
  `ERROR_LOGIN_PARAMETERS_MISSING` when no user token was configured; they now use
  the logged-in admin's GLPI session (same per-session model as Projects/CRM).

## [1.7.0] — 2026-10-02 — Config panel redesign & app manager
### Added
- **App manager** in the config panel: an **on/off toggle bar** for every app —
  core apps and **drop-in modules** alike — persisted in the config (`apps` key)
  and honoured by both the dashboard (dock cubes) and module discovery.
- **App-specific icons** on the dock cubes and the app bar, replacing the letter
  placeholders.
- **Current app name in the dashboard header** (e.g. "… · CRM") when you open an app.
### Changed
- **Config panel redesign** (formerly "Install"): renamed to **Settings**,
  sections reordered (Personalisation above apps), the timezone moved inside
  "Connect to GLPI", intro text removed, the connect step collapsed by default and
  a compact language selector. The save button now reads **"Save"**.
- Dashboard: the **Summary / Explorer / Map tabs moved from the header into the
  body** with consistent top spacing across views; the **Tickets selector** now
  uses the same segmented style as the rest; drop-in modules load in an
  **auto-height iframe** (the window scrolls like the other apps, no inner
  scrollbar).

## [1.6.0] — 2026-09-29 — Staging/production split & deploy scripts
### Added
- **Two-mode deploy** (`deploy/`): `deploy-pda <branch>` ships to **staging**
  (`pda`) and `promote-apps <tag>` ships to **production** (`apps`). Each runs
  `php -l` on the server, takes a tarball backup of the live site and installs
  as the site user. `promote-apps --rollback` restores the last backup.
- **Pda-first guard** in `promote-apps`: production only accepts a **tag**, and
  only when that tag's commit is exactly what is currently running on staging —
  the "staging first" rule is enforced by tooling instead of memory.
- **Per-site environment marker**: `Settings::env()` reads `config/version.json`
  (above the docroot) and `config.php` exposes it; the panel shows a **STAGING
  badge** only when `env=staging`. No marker → production (badge hidden).
- **Release runbook** in [`docs/DEPLOY.md`](docs/DEPLOY.md): the staging → tag →
  production flow, rollback, the server layout and troubleshooting.
### Changed
- Deploy copies **without `--delete`**, preserving each site's own files that are
  not in the repo (`assets/`, `icon/`, `modules/`, `vendor/`, runtime `config/`
  state): the docroot is not a mirror of the repository.

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
