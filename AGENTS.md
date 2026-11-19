# AGENTS.md

Guidance for AI agents working in this repository.

## Read first

1. **Hard constraints**: `CLAUDE.md`
2. **Contributing and DCO sign-off**: `CONTRIBUTING.md`
3. **Product spec**: `docs/specs/statusconnectinitialspecandbuildplan.md`
4. **Deployment and cron**: `docs/deployment/`

## Quick facts

| Item | Value |
|------|--------|
| Stack | Laravel 12 + Vue 3 SPA (`frontend/`) + Blade public status page |
| Runtime target | Shared hosting: PHP 8.2+, MySQL 8.0+, minute cron, docroot `public/` |
| Async model | MySQL claim leases + `monitor:*` artisan, no queue workers |
| Dev | Docker only via `scripts/stc.ps1` (Windows) or `scripts/stc.sh` (Unix) |
| Issues | GitHub `suporterfid/statusconnect-ce` |

## Do not

- Require Redis, brokers, Horizon, Octane, or long-running workers
- Install PHP/Composer/Node on the host
- Put Eloquent models in `app/Models/` (use `app/Infrastructure/Persistence/Eloquent/`)
- Bypass outbound SSRF / DNS-pinned HTTP for target URLs
- Commit Packagist mirror configuration
- Commit without a DCO sign-off
