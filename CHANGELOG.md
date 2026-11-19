# Changelog

Notable changes to StatusConnect Community Edition. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and versions follow [Semantic Versioning](https://semver.org/). Each version is published as one commit, `Release vX.Y.Z`, with a GitHub Release whose notes are the section below.

## [1.0.0] - 2026-11-19

First public release of the StatusConnect Community Edition, under the MIT licence.

### Monitoring

- HTTP(S) checks with status code, latency limit, body regex and header assertions, and SSL certificate expiry; TCP and MQTT checks; heartbeat monitors that scripts and cron jobs ping; agent monitors fed by Telegraf (InfluxDB line protocol) or OpenTelemetry (OTLP/HTTP JSON), with threshold rules on the metrics.
- A daemonless scheduler for shared hosting: the minute cron claims due monitors with MySQL leases (`SKIP LOCKED`) and checks HTTP monitors with rolling `curl_multi` concurrency inside a time budget. A checker heartbeat, gap and saturation alerts to the platform admins, and `monitor:capacity-test` to measure a host.
- Outbound requests pinned to the resolved address and refused for loopback, private, CGNAT, link-local, special-purpose and cloud metadata ranges.
- Optional confirmation of a failure from a second location (the PHP probe in `probe/`, or Globalping) before an incident opens.

### Incidents and notifications

- An incident state machine with confirmation and recovery thresholds, flap suppression, maintenance windows, and manual incidents with updates.
- Availability counts only confirmed failures. Hourly and daily rollups with retention.
- Notifications by email, webhook, Telegram and TaskConnect.

### Status pages

- Public status pages with components, uptime history, response times, announcements, custom domains, a JSON endpoint, feeds and email subscriptions with double opt-in. Cached snapshots with ETag/304 and rate limits.

### Organizations and access

- Organizations, environments, members with owner, admin and viewer roles, environment API keys (`sc_…`), platform administration of users and organizations (blocking, ending sessions, slug changes with redirects), and an audit log.
- First-run setup, optional public sign-up (closed, approval or open), sign in with Google, single sign-on with GrandpaSSOn, and Terms of Use and Privacy Policy templates with optional acceptance tracking.
- A documented REST API at `/api` and an MCP server for AI clients.
- The operator app (Vue 3) and the public pages in English and Brazilian Portuguese.
