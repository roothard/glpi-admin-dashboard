# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).
A user-facing version of this changelog is published at [`docs/`](docs/index.html).

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
