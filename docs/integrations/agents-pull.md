# Server metrics with existing agents (pull) and script heartbeats

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

StatusConnect does not ship its own agent. To watch CPU, memory, disk or load on a server, run an open agent that already exposes those numbers over HTTP, and point an ordinary `http` monitor at it. The minute cron fetches the URL and evaluates assertions; a failed assertion marks the check `down` and goes through the normal incident and notification flow.

This is phase 1 of epic [#67](https://github.com/suporterfid/statusconnect/issues/67). It needs nothing beyond what monitors already support:

- `json_path` assertions with numeric operators `lt`, `lte`, `gte` and `between` ([#70](https://github.com/suporterfid/statusconnect/issues/70)). The path is dot-separated; array items are addressed by index (`0`, `1`, …).
- `body_matches` (a PCRE regex) and `body_contains` for text formats.
- `request_headers_json` for an `Authorization` header.

For push-based ingestion with Telegraf, which also works behind NAT/CGNAT and covers SNMP devices through an edge collector, see [`telegraf.md`](telegraf.md). For the OpenTelemetry Collector or Grafana Alloy (OTLP/HTTP JSON), see [`opentelemetry.md`](opentelemetry.md).

## Before you start: what the checker can reach

The checker applies the outbound (SSRF) policy to every monitor:

- **Ports**: only the ports in `OUTBOUND_ALLOWED_PORTS` (default `80,443,8080,8095`). Agent default ports (Glances 61208, Netdata 19999, node_exporter 9100) are refused unless you add them.
- **Addresses**: loopback, private (RFC 1918), shared/CGNAT (`100.64.0.0/10`, also used by Tailscale), link-local, benchmarking, documentation, multicast and reserved IPv4 ranges are refused, as are their IPv6 equivalents, IPv6 forms that embed one of those IPv4 addresses (`::ffff:10.0.0.1`, NAT64, 6to4) and cloud metadata hosts. A checker on shared hosting cannot reach a VPN or Tailscale address in any case, so the agent needs a public address. These checks apply to the `public-crawl`, `api` and webhook egress profiles; the `internal` profile only reaches hosts listed in `OUTBOUND_PLATFORM_ALLOW_HOSTS`, which is the way to monitor such an address on purpose.
- **Response size**: bodies over 64 KB (`OUTBOUND_RESPONSE_BODY_LIMIT`) are truncated before assertions run.

**Recommended setup:** keep the agent bound to localhost on the server, and publish only the endpoints you need through the server's existing reverse proxy, on HTTPS port 443 with HTTP Basic authentication. Never expose an agent's full API to the internet without authentication: Glances, Netdata and node_exporter all reveal process lists, hostnames or network details.

Example nginx location for Glances (adapt the path for the other agents):

```nginx
location /agent/glances/ {
    auth_basic           "metrics";
    auth_basic_user_file /etc/nginx/statusconnect.htpasswd;   # htpasswd -c ... statusconnect
    limit_except GET { deny all; }
    proxy_pass http://127.0.0.1:61208/api/4/;
}
```

Then, on the monitor, set the header (stored with the monitor; use a credential that can only read these endpoints):

```json
{ "Authorization": "Basic c3RhdHVzY29ubmVjdDpzM2NyZXQ=" }
```

The value is `base64("user:password")`, for example `printf 'statusconnect:s3cret' | base64`.

## Glances (REST API v4, JSON)

Run Glances in web-server mode without the web UI, for example `glances -w --disable-webui` (Docker image `nicolargo/glances`, port 61208). Useful endpoints:

| Endpoint | Sample response (trimmed) | Monitor assertion |
|---|---|---|
| `/api/4/mem` | `{"total":16769032192,"percent":38.5,…}` | `json_path` `percent` `lt` `90` |
| `/api/4/cpu` | `{"total":27.3,"user":6.0,…,"cpucore":4}` | `json_path` `total` `lt` `90` |
| `/api/4/load` | `{"min1":3.13,"min5":1.81,"min15":0.90,"cpucore":4}` | `json_path` `min5` `lt` `8` (set it to about 2 × `cpucore`) |
| `/api/4/fs` | `[{"mnt_point":"/","percent":66.1,…}, …]` | `json_path` `0.percent` `lt` `90`, only if the list order is stable on that host |

Notes:

- Prefer `/api/4/cpu` over `/api/4/quicklook`: right after Glances starts, `quicklook.cpu` reports `0.0` until a second sample exists.
- A Glances request takes about one second (it samples CPU), so keep the monitor timeout at the default 10 s or more.
- The order of `/api/4/fs` follows the host's mounts. For a disk alert that cannot drift to another mount, use Netdata's `disk_space./` chart below, or check the order on that host first.

## Netdata (`/api/v1/data`)

Ask for one averaged point and the `array` format, which returns a bare JSON list such as `[23.34]`. Address it with `json_path` `0`.

| What | URL (append to `https://host/agent/netdata`) | Assertion |
|---|---|---|
| CPU busy % (sum of all CPU dimensions) | `/api/v1/data?chart=system.cpu&points=1&after=-60&group=average&format=array` | `json_path` `0` `lt` `90` |
| RAM used % | `/api/v1/data?chart=system.ram&dimensions=used&points=1&after=-60&group=average&format=array&options=percentage` | `json_path` `0` `lt` `90` |
| Disk `/` used % | `/api/v1/data?chart=disk_space./&dimensions=used&points=1&after=-60&group=average&format=array&options=percentage` | `json_path` `0` `lt` `90` |
| Load (1 min) | `/api/v1/data?chart=system.load&dimensions=load1&points=1&after=-60&group=average&format=array` | `json_path` `0` `lt` `8` |

Keep `points=1`: without it Netdata returns every second of the window and the body grows quickly. Never point a monitor at `/api/v1/allmetrics` or `/api/v1/charts`; both run far past the 64 KB limit.

## node_exporter (Prometheus text format)

node_exporter's `/metrics` is plain text, not JSON, so the checker can only match text:

- The full page is about 60 KB on a small host and much more on real servers, so it is truncated at 64 KB. Always filter with `collect[]`, for example `/metrics?collect[]=loadavg`, and run the exporter with `--web.disable-exporter-metrics` to drop its own `go_*` and `process_*` lines.
- There are no numeric comparisons on text. A regex can check that a metric exists, or that a value has a given shape:

| Check | URL | Assertion |
|---|---|---|
| Exporter alive and collecting load | `/metrics?collect[]=loadavg` | `body_matches` `/^node_load1 [0-9.e+]+$/m` |
| Load (1 min) below 10 | `/metrics?collect[]=loadavg` | `body_matches` `/^node_load1 [0-9](\.[0-9]+)?(e[-+]?[0-9]+)?$/m` |

The second regex only accepts a single digit before the decimal point, so it fails once the load reaches 10. For real thresholds on node_exporter data, put Glances or Netdata next to it, or wait for push ingestion (#67, phase 2).

## Heartbeat from a script on the monitored server

When the checker cannot reach the server at all (private network, no inbound HTTP), invert the direction: create a **heartbeat** monitor, and let a cron job on the server ping its URL only when a local check passes. If the pings stop for longer than the interval plus the grace period, StatusConnect opens an incident.

1. Create a monitor with kind **Heartbeat**, an interval (for example 60 s) and a grace period (for example 180 s). Copy its ping URL, `https://<app-host>/ping/<token>`.
2. On the server, keep the URL out of the script and readable only by root:

   ```bash
   install -d -m 700 /etc/statusconnect
   printf 'PING_URL=%s\n' 'https://<app-host>/ping/<token>' > /etc/statusconnect/heartbeat.env
   chmod 600 /etc/statusconnect/heartbeat.env
   ```

3. Write the check. The rule is simple: exit code `0` pings, anything else does not.

   ```bash
   #!/bin/sh
   # /usr/local/bin/statusconnect-heartbeat
   set -eu
   . /etc/statusconnect/heartbeat.env

   # Any command works: a service health URL, a systemctl check, a plugin...
   /usr/lib/monitoring-plugins/check_disk -w 20% -c 10% -p / >/dev/null || exit 0

   curl -fsS -m 10 --retry 2 -o /dev/null "$PING_URL"
   ```

   Nagios / Monitoring Plugins already follow the exit-code convention (`0` OK, `1` warning, `2` critical, `3` unknown), so any of them can gate the ping. With `|| exit 0` the script ends quietly when the check fails, and the missing ping is what raises the alert.

4. Run it every minute:

   ```bash
   chmod 755 /usr/local/bin/statusconnect-heartbeat
   echo '* * * * * root /usr/local/bin/statusconnect-heartbeat' > /etc/cron.d/statusconnect-heartbeat
   ```

## Validation

Recorded on 2026-11-07 against real containers. Start them with the optional `agents` profile:

```bash
docker compose --profile agents up -d glances node-exporter netdata
```

In development the agents run on their default ports inside the compose network, so the validation run widened the outbound policy for that process only:

```bash
docker compose exec -e OUTBOUND_ALLOWED_PORTS=80,443,8080,8095,61208,19999,9100 \
  -e OUTBOUND_TESTING_ALLOW_HOSTS=target,glances,netdata,node-exporter \
  app php artisan monitor:check-due
```

Results of one tick with nine monitors (confirmation threshold 1):

| Monitor | State | Latency |
|---|---|---|
| Glances `/api/4/mem`, `percent lt 90` | up | 1052 ms |
| Glances `/api/4/cpu`, `total lt 90` | up | 1101 ms |
| Glances `/api/4/load`, `min5 lt 8` | up | 1052 ms |
| Glances `/api/4/mem`, `percent lt 1` (expected to fail) | down: `JSON path "percent" value 38.9 does not satisfy lt 1` | 1059 ms |
| Netdata CPU, `0 lt 90` | up | 15 ms |
| Netdata RAM used %, `0 lt 90` | up | 5 ms |
| Netdata disk `/` used %, `0 between 0..90` | up | 6 ms |
| node_exporter `collect[]=loadavg`, metric present | up | 5 ms |
| node_exporter `collect[]=loadavg`, load1 below 10 | up | 5 ms |

Heartbeat recipe, against a local `/ping/<token>`:

- passing check (`true`): ping sent, `204`, `last_ping_at` updated;
- failing check (`false`): no ping;
- `check_disk -w 20% -c 10% -p /` (Alpine `monitoring-plugins`): `DISK OK`, exit 0, ping `204`; with thresholds the disk could not meet (`-w 99% -c 98%`): non-zero exit, no ping.

Images used: `nicolargo/glances:4.3.1`, `netdata/netdata:v2.2.6`, `prom/node-exporter:v1.8.2`.
