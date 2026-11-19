# CLAUDE.md

This file guides Claude Code, Cursor and other coding agents working in this repository.

## What this is

StatusConnect Community Edition is an open-source (MIT), multi-tenant uptime monitor and public status page built to run on commodity PHP + MySQL shared hosting. A Laravel 12 backend with a layered (DDD-ish) structure, Blade-rendered public pages, and a Vue 3 SPA for the operator app. A minute cron runs artisan commands that claim due monitors from MySQL, run the checks in parallel with `curl_multi`, evaluate incidents and build the status rollups.

Original specification: `docs/specs/statusconnectinitialspecandbuildplan.md`.

## Hard constraints (non-negotiable)

1. **Must stay deployable on commodity shared hosting**: PHP 8.2+, MySQL 8.0+, minute cron, docroot `public/`.
   - NO dependency on an always-on process, daemon, or external broker (no Redis, Memcached, RabbitMQ, Horizon, Octane, Reverb, supervisor workers).
   - MySQL-backed claim leases + minute cron (`monitor:check-due`, `monitor:rollup`, `monitor:maintenance`).
   - `QUEUE_CONNECTION=sync`, `CACHE_STORE=file` or `database`, `SESSION_DRIVER=database` or `file`.
   - `exec()`, `shell_exec()`, `proc_open()`, `popen()` MUST NOT be used at runtime.
2. **Development is Docker-only**: PHP, Composer, Node, npm MUST NOT be run on the host. Everything runs through containers via `scripts/stc.sh` / `scripts/stc.ps1`.
3. **Contributions**: every commit carries a DCO sign-off (`git commit -s`). See `CONTRIBUTING.md`.
4. **Licence & provenance**: MIT licensed. Code adapted from `TaskConnect` carries source attribution comments.
5. **Extension points**: the core declares interfaces and registries (`SignupLimits`, `CheckRegions`, `LandingPage`, `LegalDocuments`, `NotificationTransports`, `MaintenanceTasks`, `Features`) with its own implementations. Keep new optional behavior behind them rather than adding conditionals across the code.

## Dev environment

```bash
./scripts/stc.sh up          # Windows: .\scripts\stc.ps1 up
./scripts/stc.sh bootstrap
```

Services & ports:
- App: http://localhost:8070 (operator app at `/app`)
- Mailpit UI: http://localhost:8035
- Target service (controllable check target): http://localhost:8095
- MySQL: 3308

### Common commands

| Command | Purpose |
|---|---|
| `stc test` | Run the PHPUnit suite inside the app container |
| `stc e2e` | Run the Playwright browser tests on an isolated stack (`docs/testing/e2e.md`) |
| `stc artisan <cmd>` | Run an artisan command |
| `stc composer <cmd>` | Run composer in the app container |
| `stc npm <cmd>` | Run npm in the node container |
| `stc shell` | Shell in the app container |

## Layering

- `app/Domain/` — Framework-free business logic (Monitoring, Incidents, Outbound, StatusPage, Secrets, Shared).
- `app/Application/` — Orchestration, transaction boundaries (Checks, Incidents, Notifications, Rollups, StatusPages, etc.).
- `app/Infrastructure/` — Concrete persistence (`Persistence/Eloquent/`), HTTP clients (`HttpClient/`), TCP, DNS.
- `app/Http/` — Thin controllers (`Controllers/Api/V1/`, `Controllers/Public/`), Resources, Middleware.
