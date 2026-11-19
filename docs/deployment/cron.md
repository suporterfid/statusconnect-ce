# Hostinger Cron Jobs & Scheduled Tasks

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

StatusConnect has no daemon or queue worker. Everything that happens on a schedule runs from three artisan commands that the host's cron starts every minute. Without them, monitors are never checked, incidents never open, and the public status page never updates.

---

## 1. Setting up cron jobs in Hostinger hPanel

Shared hosting has no `crontab` over SSH; cron jobs are managed only in hPanel.

1. Log in to **Hostinger hPanel**.
2. Go to **Advanced > Cron Jobs**.
3. Choose **Custom command** and add each line from section 2.

---

## 2. Commands

Use the full path to the PHP binary that hPanel serves the site with. The plain `php` on the cron `PATH` can be an older version (7.4 on this account), which fails to boot Laravel 12. List the available binaries over SSH with `ls /opt/alt/php*/usr/bin/php` and use the one matching hPanel's PHP version (8.3 here, the same value as `REMOTE_PHP_BIN` in `scripts/deploy.secrets`).

Replace `uXXXXXXXXX` and `status.example.com` with your account user and domain.

```bash
* * * * * /opt/alt/php83/usr/bin/php /home/uXXXXXXXXX/domains/status.example.com/public_html/artisan monitor:check-due >/dev/null 2>&1
* * * * * /opt/alt/php83/usr/bin/php /home/uXXXXXXXXX/domains/status.example.com/public_html/artisan monitor:maintenance >/dev/null 2>&1
* * * * * /opt/alt/php83/usr/bin/php /home/uXXXXXXXXX/domains/status.example.com/public_html/artisan monitor:rollup >/dev/null 2>&1
```

| Command | What it does |
|---|---|
| `monitor:check-due` | Claims the monitors that are due, using a MySQL lease, and runs their HTTP/TCP checks in parallel within the tick budget. It also evaluates heartbeat monitors, maintenance windows and incidents, and sends notifications. Writes the `checker` heartbeat. |
| `monitor:maintenance` | Releases stale claim leases left by a tick that died, and deletes raw check results already covered by a daily rollup (retention). Sends status page subscriber emails (#92) and, where billing is configured, retries Asaas webhook events and ends expired paid plans. Writes the `maintenance` heartbeat. |
| `monitor:rollup` | Aggregates closed check-result buckets into `check_rollups` and re-renders status page snapshots whose monitors or components changed. The public `/status/{slug}` route only serves these snapshots. |

Earlier versions of this guide listed `monitor:maintain-windows` and `monitor:rollup-retention`. Those commands do not exist. With output sent to `/dev/null`, a cron line naming a missing command fails silently.

---

## 3. Verifying that the scheduler runs

Each run of `monitor:check-due` and `monitor:maintenance` updates a row in `system_heartbeats`. Over SSH:

```bash
cd ~/domains/app.statusconnect.net/public_html
/opt/alt/php83/usr/bin/php artisan tinker --execute='echo json_encode(DB::table("system_heartbeats")->get(["name","last_seen_at"]));'
```

Both `last_seen_at` values should be less than two minutes old. To run one tick by hand and see its output, call a command directly, for example `/opt/alt/php83/usr/bin/php artisan monitor:check-due`.

The `checker` row also records how long the tick took and how many due monitors were left for the next one (`duration_ms`, `deferred`). See [`capacity.md`](capacity.md) for what those numbers mean and when to tune.

## 4. When the checker stops

If cron stops, the server is down or the installation is being moved, nothing is checked. The checker cannot report its own absence, so StatusConnect handles it in two places ([#199](https://github.com/suporterfid/statusconnect/issues/199)).

**After the fact.** When `monitor:check-due` runs and the previous tick is `CHECKER_GAP_MINUTES` (default 3) or more minutes old, it:
- stores the period in `checker_gaps`;
- logs a warning;
- emails the platform admins.

Status pages then show that time on the day's bar as "without checks". The uptime percentages count only checked time, so a gap counts neither as up nor as down.

**While it happens.** Point a monitor outside this server at:

```
GET https://<your host>/v1/platform/health?checker=1
```

It answers `200` while the checker ticks and `503` once the last tick is `CHECKER_GAP_MINUTES` or more minutes old (`status: checker_stopped`). It also answers `503` while the checker is saturated (`status: checker_saturated`, see [capacity.md](capacity.md#saturation-alert)). Without `?checker=1` the endpoint stays a plain liveness check that does not touch the database. Any external uptime service works, for example a Google Cloud Monitoring uptime check with an alert policy, or another StatusConnect installation. A monitor on this same installation does not work, because it stops together with the checker.

