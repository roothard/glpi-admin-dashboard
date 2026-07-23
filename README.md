# GLPI Projects Dashboard

A lightweight, self-hosted **dashboard for GLPI Projects**. It reads your GLPI
over the **REST API** (App-Token + user token), builds a static `data.json`, and
serves a clean, zero-dependency board grouped into zones, with per-project
progress, tasks, and linked knowledge-base articles.

- **Runs anywhere** — same server as GLPI or a separate host; it only needs the
  GLPI REST API over HTTPS.
- **Read-only & safe** — never writes to GLPI; secrets live in `.env` outside the
  web docroot.
- **Configurable** — filter by project type, group by parent/entity/type, map
  your own state names (any language).

> Status: **Phase 1** — the REST generator and static front-end are working.
> Setup wizard, Docker image, and auth modes are on the roadmap (see below).

---

## How it works

```
GLPI REST API  ──►  bin/generate.php  ──►  public/data.json  ──►  public/index.html
   (your data)        (cron/CLI)            (static cache)          (the board)
```

`generate.php` pulls Projects, tasks, states, entities, users and linked
KnowbaseItems, and writes `public/data.json`. The front-end is a single static
HTML file that renders that JSON — no backend needed to view it.

## Requirements

- PHP 7.4+ with `curl` and `json` (CLI).
- A GLPI instance (10.x) with the **REST API enabled**
  (*Setup → General → API*), an **API client** (App-Token), and a **user API
  token** (*Preferences → Remote access keys*).

## Quick start

```bash
git clone https://github.com/your-org/glpi-projects-dashboard.git
cd glpi-projects-dashboard
cp .env.example .env
# edit .env: set GLPI_URL, GLPI_APP_TOKEN, GLPI_USER_TOKEN
php bin/generate.php
```

Then serve the `public/` folder with any web server, or locally:

```bash
php -S localhost:8080 -t public
# open http://localhost:8080
```

### Keep it fresh (cron)

```cron
*/15 * * * * php /path/to/glpi-projects-dashboard/bin/generate.php >/dev/null 2>&1
```

## Configuration

All settings live in `.env` (see [`.env.example`](.env.example) for the full,
commented list). The essentials:

| Variable | Description |
|---|---|
| `GLPI_URL` | Host or full `.../apirest.php` URL |
| `GLPI_APP_TOKEN` / `GLPI_USER_TOKEN` | API credentials |
| `GLPI_TOKENS_IN_QUERY` | `true` if GLPI is behind Cloudflare (strips `Authorization`) |
| `PROJECT_TYPE` | Only show projects of this type (empty = all) |
| `GROUP_BY` | `parent` \| `entity` \| `type` |
| `STATE_INPROGRESS` / `STATE_DONE` / `STATE_PLANNED` | Keywords that map your state names to a status color |

## Security

- Keep `.env` **out of the docroot** (only `public/` should be web-served) and
  never commit it — it's already in `.gitignore`.
- The generator is **read-only**; the user token only needs read rights.
- `public/.htaccess` ships basic hardening for Apache; adapt for Nginx.

## Notes & limitations

- **Project progress** comes from each project's `percent_done` and is always
  accurate over the API.
- **Task breakdown is best-effort.** GLPI's REST API only lists *project tasks*
  whose **team includes the API user** (hardcoded in GLPI core —
  `Search::addDefaultWhere` for `ProjectTask`, with no "read all" bypass). So the
  per-project task list in the detail view shows only the tasks visible to the
  token's user. The board, progress, states, KB and dates are unaffected. If you
  need every task listed, add the API user to the relevant project teams.

## Roadmap

- [x] **Phase 1** — REST generator + static front-end
- [ ] **Phase 2** — first-run setup wizard (web UI to enter/test connection & options)
- [ ] **Phase 3** — Docker image + release tarball, CI
- [ ] **Phase 4** — optional auth (shared password / GLPI login), i18n, richer views (Gantt/Kanban/map)

## Contributing

Issues and PRs welcome. This project talks to GLPI only through its public REST
API and ships no GLPI code, so it is distributed under the permissive MIT
license.

## License

[MIT](LICENSE)
