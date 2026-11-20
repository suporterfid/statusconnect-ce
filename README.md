🇧🇷 [Leia em Português](README.pt-BR.md) | 🇺🇸 [English](README.md) (this page)

# StatusConnect Community Edition

> Lightweight, multi-tenant uptime monitoring and public status pages for traditional shared hosting.

[![Tests](https://github.com/suporterfid/statusconnect-ce/actions/workflows/tests.yml/badge.svg)](https://github.com/suporterfid/statusconnect-ce/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

StatusConnect checks websites, APIs, servers and scheduled jobs at regular intervals. When something fails it opens an incident, alerts your team and publishes a public status page your users can follow. It runs on plain PHP and MySQL shared hosting: no daemon, no Redis, no queue worker, just a cron entry that fires once a minute.

This is the **Community Edition**, the open-source (MIT) edition of StatusConnect, published as release snapshots. It is part of the [IROH Initiative](https://github.com/suporterfid/iroh) (*It Runs On Hostinger*), a family of self-hosted tools built for low-cost shared hosting.

---

## Features

- **Checks**: HTTP(S) (status code, latency limit, body regex, headers, SSL certificate expiry), TCP, MQTT, heartbeats (cron jobs and scripts ping a URL) and agent monitors (Telegraf and OpenTelemetry push metrics, with threshold rules).
- **Incidents**: confirmation and recovery thresholds, flap suppression, maintenance windows, manual incidents with updates, optional confirmation from a second location.
- **Notifications**: email, webhooks, Telegram and TaskConnect.
- **Public status pages**: components, uptime history, response times, announcements, custom domains, email subscriptions, JSON and feeds. Cached snapshots with ETag/304 support.
- **Multi-tenant**: organizations, environments, members and roles, scoped API keys (`sc_…`), platform administration, optional public sign-up with approval.
- **API and integrations**: a documented REST API (`/api`), an MCP server for AI clients, sign in with Google, single sign-on with [GrandpaSSOn](https://github.com/suporterfid/grandpasson-ce).
- **Safe outbound requests**: DNS-pinned HTTP that blocks loopback, private, CGNAT, link-local and cloud metadata addresses, so monitors cannot be used to reach your internal network.
- **Operator app** in Vue 3, **public pages** in Blade, both in English and Brazilian Portuguese.

## How it works

The minute cron runs three artisan commands:

| Command | What it does |
|---|---|
| `monitor:check-due` | Claims the due monitors with MySQL leases (`SKIP LOCKED`), checks them in parallel with `curl_multi` within a time budget, evaluates incidents and sends notifications |
| `monitor:maintenance` | Releases stale leases, applies retention, sends status page subscriber emails |
| `monitor:rollup` | Aggregates results into hourly and daily rollups and re-renders status page snapshots |

One tick checks a few hundred HTTP monitors on a 60 s interval with the default settings. See [docs/deployment/capacity.md](docs/deployment/capacity.md).

## Requirements

- PHP 8.2 or later with `pdo_mysql`, `curl`, `mbstring`, `openssl` and `fileinfo`
- MySQL 8.0 or later (MariaDB 10.5+ should work but is not tested)
- A cron that can run a command every minute, and a document root you can point at `public/`
- Outbound HTTP from the server to the sites you monitor

For development you only need Docker. PHP, Composer and Node run in containers.

## Installation on shared hosting

1. **Build a release** on your machine (Docker only):
   ```bash
   ./scripts/stc.sh composer install --no-dev --optimize-autoloader
   ./scripts/stc.sh npm --prefix frontend ci
   ./scripts/stc.sh npm run build
   ```
   The release tags of this repository already contain the built operator app in `public/app/`; rebuild it only if you change the frontend.
2. **Upload** the project (including `vendor/`) to the server, for example to `~/statusconnect`.
3. **Point the document root** of your domain or subdomain at `public/`.
4. **Configure**: copy `.env.example` to `.env`, set `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, the `DB_*` and `MAIL_*` settings, and generate a key (`php artisan key:generate`). Set `SETUP_ALLOWED_EMAILS` to the address of the first administrator.
5. **Migrate**: `php artisan migrate --force`, then `php artisan config:cache` and `php artisan route:cache`.
6. **Add the cron entries** (use the full path of the PHP 8.2+ binary your host gives you):
   ```cron
   * * * * * /usr/bin/php /home/you/statusconnect/artisan monitor:check-due >/dev/null 2>&1
   * * * * * /usr/bin/php /home/you/statusconnect/artisan monitor:maintenance >/dev/null 2>&1
   * * * * * /usr/bin/php /home/you/statusconnect/artisan monitor:rollup >/dev/null 2>&1
   ```
7. **Create the first account** at `/app/setup` with an address from `SETUP_ALLOWED_EMAILS`, or from the command line with `php artisan statusconnect:provision-account`.

Step by step guides: [docs/deployment/hostinger.md](docs/deployment/hostinger.md) (shared hosting), [docs/deployment/cron.md](docs/deployment/cron.md) (cron and heartbeats), [docs/deployment/custom-domains.md](docs/deployment/custom-domains.md), [docs/deployment/saas.md](docs/deployment/saas.md) (sign-up, subscriptions, administration, terms acceptance), [docs/deployment/second-location.md](docs/deployment/second-location.md).

### Home page and legal pages

`/` (pt-BR) and `/en` show a plain home page. `/termos` and `/privacidade` (English: `/terms`, `/privacy`) render the Terms of Use and Privacy Policy templates in `resources/legal/`. Fill in the operator's data with the `LEGAL_*` settings in `.env`; empty values render neutral wording. The texts are templates: have a lawyer review them before you offer the service to the public.

## Local development

```bash
./scripts/stc.sh up          # app, MySQL, Mailpit, test target
./scripts/stc.sh bootstrap   # install dependencies, create .env, migrate
./scripts/stc.sh test        # PHPUnit suite
```

On Windows use `.\scripts\stc.ps1` with the same verbs. Other verbs: `artisan`, `composer`, `npm`, `shell`, `e2e` (Playwright browser tests), `down`.

| Service | Address |
|---|---|
| Application | http://localhost:8070 (operator app at `/app`) |
| Mailpit | http://localhost:8035 |
| Test target | http://localhost:8095 |
| MySQL | localhost:3308 |

The code is layered: `app/Domain` (framework-free business logic), `app/Application` (use cases and transactions), `app/Infrastructure` (Eloquent, HTTP, DNS, TCP), `app/Http` (thin controllers). The operator app lives in `frontend/`.

## Integrations

- Server metrics: [Telegraf](docs/integrations/telegraf.md), [OpenTelemetry](docs/integrations/opentelemetry.md), [pull agents and heartbeats](docs/integrations/agents-pull.md)
- Notifications: [Telegram](docs/integrations/telegram.md), [TaskConnect](docs/integrations/taskconnect.md)
- Sign-in: [Google](docs/integrations/google-login.md), [GrandpaSSOn](docs/integrations/grandpasson.md)
- AI clients: [MCP](docs/integrations/mcp.md)
- Monitor recipes: [docs/guides/monitors.md](docs/guides/monitors.md)

## Contributing

Questions and ideas go to [Discussions](https://github.com/suporterfid/statusconnect-ce/discussions); bugs to [Issues](https://github.com/suporterfid/statusconnect-ce/issues). Pull requests are welcome: read [CONTRIBUTING.md](CONTRIBUTING.md) first. Every commit needs a DCO sign-off (`git commit -s`).

Security issues: please do not open a public issue. See [SECURITY.md](SECURITY.md).

## License

[MIT](LICENSE).
