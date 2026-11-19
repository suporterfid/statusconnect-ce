# Checker capacity

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

StatusConnect has no worker process. The minute cron runs `monitor:check-due`, which claims due monitors, checks them in parallel, and stops before the tick budget runs out. HTTP monitors use rolling concurrency; TCP, MQTT, heartbeat and agent monitors go first, in waves. This page explains how many monitors one tick can check, how that was measured, and what to watch in production. Tracked in #60 and #214.

## How a tick spends its time

| Setting | Default | Effect |
|---|---|---|
| `CHECK_TARGET_DURATION_SECONDS` | 45 | Wall-clock budget per tick. Capped at `max_execution_time` minus `CHECK_BUDGET_SAFETY_MARGIN_SECONDS` when PHP has a limit. |
| `CHECK_EXECUTION_RESERVE_SECONDS` | 1 | Kept free at the end of the tick. |
| `CHECK_CONCURRENCY` | 10 | HTTP requests kept in flight (maximum 50), and TCP connections per wave. |
| `CHECK_WAVE_SIZE` | concurrency | Monitors claimed per wave for TCP, MQTT, heartbeat and agent monitors. HTTP is not in waves. |
| Monitor `timeout_ms` | 10000 | A monitor is only claimed while its timeout still fits in the remaining budget. |

Two things follow from this:

- **HTTP: a slow target holds one slot, not a wave.** Up to `CHECK_CONCURRENCY` requests stay in flight. When one finishes, its result is stored and the next due monitor starts at once. Monitors are claimed a few at a time (a fifth of the concurrency) as slots free up.
- **Results with side effects wait for the stream to drain.** A result that only records another "up" for a monitor that is up is stored at once. Any other result (a failure, a recovery, a first check, maintenance) can open or close an incident, send notifications or ask a second location to confirm, which can take seconds. Such results are held until no request is in flight, stored then, and the stream resumes. During an outage with many failing monitors, the checker therefore behaves more like waves.
- **TCP, MQTT, heartbeat and agent monitors run first, in waves,** and a wave lasts as long as its slowest check. They are few in practice. If they used the whole budget, HTTP monitors would wait for the next tick.
- **With 10 s timeouts, claiming stops about 34 s into the tick** (45 − 1 reserve − 10 timeout), not at 45 s. Shorter timeouts give a longer window: 5 s timeouts claim until about 39 s.

Each executed check also costs about 10 ms of database work on shared hosting (result, incident state, next due time), done one monitor at a time.

A rough estimate for HTTP in one tick: `checks ≈ window ÷ (average latency ÷ concurrency + 10 ms)`.

## Measured capacity

Measured on 2026-11-07 in the Docker dev environment (MySQL 8 on the same machine) against the `target` service with a fixed response delay. Every monitor had a 60 s interval and a 10 s timeout. Script: one tick of `ParallelMonitorCheckRunner` over N due monitors, then cleanup.

| Due monitors | Response delay | Concurrency | Checked in one tick | Tick duration | Left for next tick |
|---:|---:|---:|---:|---:|---:|
| 200 | 500 ms | 10 | 200 | 13.3 s | 0 |
| 500 | 500 ms | 10 | 470 | 34.0 s | 30 |
| 800 | 500 ms | 10 | 500 | 34.0 s | 300 |
| 200 | 1300 ms | 10 | 200 | 31.5 s | 0 |
| 400 | 1300 ms | 10 | 230 | 34.8 s | 170 |
| 800 | 500 ms | 25 | 800 | 32.7 s | 0 |
| 1000 | 100 ms | 10 | 1000 | 31.0 s | 0 |
| 1000 | 20 ms | 10 | 1000 | 19.3 s | 0 |

## Measuring this host

`php artisan monitor:capacity-test` runs one real tick of the checker against synthetic monitors and rolls everything back. It creates a throwaway organization with `--monitors` HTTP monitors pointed at `--target`, claims only those, and prints how many were checked, how many were left for the next tick and how long the tick took. No monitor, check result, incident or heartbeat is committed, and real monitors are never claimed.

```bash
php artisan monitor:capacity-test --target=https://<probe>/delay/500 --monitors=400 --concurrency=10 --force
```

| Option | Default | Meaning |
|---|---|---|
| `--target` | required | URL every synthetic monitor checks. In production it must be a public address. |
| `--monitors` | 200 | Synthetic monitors due in the tick (1 to 2000). |
| `--concurrency` | `CHECK_CONCURRENCY` | Concurrency for this run only. |
| `--timeout-ms` | 10000 | Timeout of each synthetic monitor. |
| `--egress` | `public-crawl` | Egress profile of the synthetic monitors. |
| `--no-wait` | off | Start at once (development only, see below). |
| `--force` | off | Required when `APP_ENV=production`. |

The test holds one database transaction for up to the tick budget. To keep it from overlapping the real checker, it waits until this minute's tick has written its heartbeat and the clock is between second 5 and 10 of the minute, and it stops at least 4 seconds before the next minute. Run it when the real checker's ticks are short (a few seconds), which is the normal case.

A slow target makes the numbers realistic. The [remote probe](../../probe/README.md) answers `GET /delay/{ms}` after `{ms}` milliseconds when `PROBE_DELAY_MAX_MS` is set. Turn it on only while measuring: the endpoint is unsigned.

When the result is known, set `CHECKER_CAPACITY_PER_MINUTE` to the number of checks one tick handled with realistic latency. The platform console (**Administration → Checker**) then shows the current demand against it.

## Saturation alert

A tick is saturated when it leaves due monitors for the next tick (`deferred` above 0), or runs `CHECKER_SATURATION_SECONDS` (default 30) or longer. After `CHECKER_SATURATION_TICKS` (default 3) saturated ticks in a row:

- the platform admins get an email, at most once every `CHECKER_SATURATION_ALERT_HOURS` (default 6) while it lasts;
- `GET /v1/platform/health?checker=1` answers `503` with `status: checker_saturated`, so the external monitor that watches for a stopped checker ([cron.md](cron.md)) also catches this;
- the platform console shows it.

The state lives in the `checker_saturation` row of `system_heartbeats`. One clean tick resets it.

## Load in the platform console

**Administration → Checker** shows, for platform admins:

- **Demand:** network checks per minute that the enabled monitors ask for (`60 ÷ interval` summed over HTTP, TCP and MQTT monitors). Heartbeat and agent monitors are left out because they make no request.
- **Measured capacity** (`CHECKER_CAPACITY_PER_MINUTE`) and the share of it in use.
- The last tick: duration, monitors checked, monitors left for the next tick, and the configured concurrency.

The same data is at `GET /v1/platform/checker`.

## What happens over capacity

Monitors that do not fit stay due and are claimed first in the next tick, because claiming is ordered by `next_check_at`. Nothing is lost, but those checks run late, and incident confirmation slows down by the same amount. If every tick leaves monitors behind, the backlog grows.

## Watching it in production

Every tick updates the `checker` row in `system_heartbeats`:

```json
{"claimed": 12, "executed": 12, "deferred": 0, "budget_stopped": false, "duration_ms": 4120}
```

- `duration_ms`: wall-clock time of the tick.
- `deferred`: due monitors left for the next tick. Above 0, `budget_stopped` is true.

Check it with:

```bash
php artisan tinker --execute='echo DB::table("system_heartbeats")->where("name","checker")->value("meta_json");'
```

When `duration_ms` is regularly above 30000 or `deferred` is above 0:

1. Shorten timeouts on monitors that do not need 10 s (`timeout_ms`): it widens the claim window and limits how long a dead target holds a slot.
2. Raise `CHECK_CONCURRENCY` gradually (15, 20, 25) and watch `duration_ms`.
3. Move rarely changing targets to longer intervals.
4. Beyond that, split tenants across more than one installation. A single tick cannot grow past one minute.

## Single checking location

All checks run from the server that hosts StatusConnect, so they come from one IP address in one region. Consequences:

- A network problem between that server and a target looks like an outage of the target. There is no second location to confirm it. Confirmation thresholds (`confirmation_threshold`, default 3 checks) reduce false alarms from short blips but cannot tell a local network issue from a real one.
- A provider outage at the hosting server stops every check at once. The status pages then show the last snapshot until the cron runs again.
- Latency figures are from that one location, not what users elsewhere see.
- Targets that filter by IP address must allow the hosting server's outbound address.

A failure can be confirmed from another location before an incident opens: see [second-location.md](second-location.md).
