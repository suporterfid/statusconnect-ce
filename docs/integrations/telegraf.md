# Server and device metrics with Telegraf (push)

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

An **agent monitor** receives metrics that [Telegraf](https://github.com/influxdata/telegraf) pushes over HTTPS, keeps the latest value of each metric, and turns your threshold rules into `up`, `degraded` or `down` on the minute cron. Incidents, notifications and the public status page work as for any other monitor. Metric names, values and tags never appear on the public page.

Nothing runs on the StatusConnect side except the existing cron. Telegraf runs on the machine you want to watch, or on a small "edge collector" in the same network (see [Edge collector](#edge-collector-snmp-devices-on-a-local-network)), and only makes outbound HTTPS requests. That makes it work behind NAT and CGNAT, where pull monitoring ([`agents-pull.md`](agents-pull.md)) cannot reach.

This is phase 2 of epic [#67](https://github.com/suporterfid/statusconnect/issues/67).

If your servers run the OpenTelemetry Collector or Grafana Alloy instead, the same monitor accepts OTLP/HTTP JSON: see [`opentelemetry.md`](opentelemetry.md).

## 1. Enable ingestion

Agent monitors are off by default. On the server, set in `.env`:

```dotenv
AGENT_INGEST_ENABLED=true
```

Limits, all optional (defaults shown):

| Variable | Default | Meaning |
|---|---|---|
| `AGENT_INGEST_MAX_BYTES` | 262144 | Request body limit, as sent (gzip or not) |
| `AGENT_INGEST_MAX_DECOMPRESSED_BYTES` | 1048576 | Body limit after gunzip |
| `AGENT_INGEST_MAX_LINES` | 2000 | Line protocol lines per request |
| `AGENT_INGEST_RATE_PER_TOKEN_PER_MINUTE` | 12 | Requests per token per minute |
| `AGENT_INGEST_RATE_PER_IP_PER_MINUTE` | 60 | Requests per source IP per minute |
| `AGENT_MAX_METRICS_PER_MONITOR` | 200 | Distinct metric keys kept per monitor; new keys beyond this are dropped |
| `AGENT_MAX_RULES_PER_MONITOR` | 50 | Rules per monitor |
| `RETENTION_AGENT_METRIC_DAYS` | 30 | Metrics not received for this long are deleted by `monitor:maintenance` |

## 2. Create the monitor

In the operator SPA: **Monitors → New monitor → Kind: Agent (Telegraf / OpenTelemetry)**. Only owners and admins can create one.

- **Interval**: how often the cron evaluates the monitor (minimum 60 s).
- **Grace**: extra seconds allowed before missing data counts. If no metrics arrive for *interval + grace*, the check is `down` with reason `no_data: last metrics …s ago`.

After saving, the monitor page shows the **ingestion URL once**:

```
https://status.example.com/ingest/sca_<48 hex characters>
```

Copy it now. Only a SHA-256 hash is stored; if you lose it, use **Rotate token** (the old URL stops working at once). The page also shows a ready-made `outputs.http` snippet.

## 3. Install Telegraf

Use a current Telegraf release (examples were tested with 1.32).

**Debian / Ubuntu**

```bash
curl -fsSL https://repos.influxdata.com/influxdata-archive.key \
  | sudo gpg --dearmor -o /usr/share/keyrings/influxdata-archive.gpg
echo "deb [signed-by=/usr/share/keyrings/influxdata-archive.gpg] https://repos.influxdata.com/debian stable main" \
  | sudo tee /etc/apt/sources.list.d/influxdata.list
sudo apt-get update && sudo apt-get install telegraf
```

For RHEL-family systems, use the `yum` repository from the same site. Check the key fingerprint against the [InfluxData install docs](https://docs.influxdata.com/telegraf/v1/install/).

**Windows**

1. Download the Windows zip from the [releases page](https://github.com/influxdata/telegraf/releases) and unpack it to `C:\Program Files\Telegraf`.
2. Put the configuration below in `C:\Program Files\Telegraf\telegraf.conf` (on Windows, use `[[inputs.win_perf_counters]]` or keep `cpu`, `mem`, `disk`, `system`, which also work there).
3. From an elevated prompt:

   ```powershell
   cd "C:\Program Files\Telegraf"
   .\telegraf.exe --config telegraf.conf --test          # prints metrics, sends nothing
   .\telegraf.exe --service install --config "C:\Program Files\Telegraf\telegraf.conf"
   net start telegraf
   ```

## 4. Minimal `telegraf.conf`

```toml
[agent]
  interval = "60s"          # collect once a minute; the cron evaluates once a minute
  flush_interval = "60s"
  hostname = "web1"         # becomes the host tag in metric keys

[[inputs.cpu]]
  percpu = false
  totalcpu = true
  fieldinclude = ["usage_user", "usage_system", "usage_idle", "usage_iowait"]

[[inputs.mem]]
  fieldinclude = ["used_percent", "available_percent"]

[[inputs.disk]]
  mount_points = ["/"]
  fieldinclude = ["used_percent", "inodes_used_percent"]

[[inputs.system]]
  fieldinclude = ["load1", "load5", "load15", "uptime"]

[[inputs.net]]
  interfaces = ["eth0"]
  ignore_protocol_stats = true
  fieldinclude = ["bytes_recv", "bytes_sent", "err_in", "err_out"]

[[outputs.http]]
  url = "${STATUSCONNECT_INGEST_URL}"
  method = "POST"
  data_format = "influx"
  content_encoding = "gzip"
  timeout = "10s"
  [outputs.http.headers]
    Content-Type = "text/plain; charset=utf-8"
```

Restart Telegraf. Within a minute the **Latest metrics** table on the monitor page fills, with each value's age.

## 5. Metric keys and rules

Each numeric field becomes one key, `measurement.field{tag=value,...}`, with tags sorted by name:

```
mem.used_percent{host=web1}
disk.used_percent{device=sda1,fstype=ext4,host=web1,mode=rw,path=/}
```

Integers, floats and booleans (as 1/0) are kept; string fields are ignored.

Add rules from the metrics table (**+ Rule**) or **Alert rules → + Rule**. A rule **fires** when the condition is true:

| Operator | Fires when value is |
|---|---|
| `gt`, `gte`, `lt`, `lte` | above / below the threshold |
| `between` | inside `low..high` (for example `90..100`) |
| `equals` | equal to the number |

- **Severity** `down` opens incidents; `degraded` marks the monitor degraded without opening one.
- **If missing**: `ignore` skips the rule when the key did not arrive in the latest push, `fail` makes it fire.
- Evaluation order: maintenance window (skipped), no data (down), `down` rules, `degraded` rules, otherwise `up`. The reason shows the first rule that fired, for example `disk.used_percent{…,path=/}=93.2 gte 90`.

A metric that is a latency in ms can also become the monitor's response time: set **Latency metric (optional)** on the monitor to its key. See [Latency from an agent metric](../guides/monitors.md#latency-from-an-agent-metric).

## Edge collector: SNMP devices on a local network

A single Telegraf on the local network, on any small Linux box such as a Raspberry Pi, can poll switches, UPSs, RFID readers or other IoT devices over SNMP and push the results. It plays the role of a Zabbix proxy, with no server of your own: the edge box only needs outbound HTTPS.

```toml
[agent]
  interval = "60s"
  flush_interval = "60s"
  hostname = "edge-lab"

# Reachability without exec(): native ICMP (run Telegraf with CAP_NET_RAW).
[[inputs.ping]]
  urls = ["192.168.10.1", "192.168.10.20", "192.168.10.31"]
  method = "native"
  count = 3
  fieldinclude = ["percent_packet_loss", "average_response_ms", "result_code"]

# UPS (RFC 1628 UPS-MIB). Numeric OIDs, so no MIB files are needed.
[[inputs.snmp]]
  agents = ["udp://192.168.10.20:161"]
  version = 2
  community = "public"         # use a read-only community, or SNMPv3 below
  agent_host_tag = "source"    # device address goes in the "source" tag
  name = "ups"
  [[inputs.snmp.field]]
    name = "battery_status"      # 2 = normal, 3 = low, 4 = depleted
    oid = ".1.3.6.1.2.1.33.1.2.1.0"
  [[inputs.snmp.field]]
    name = "charge_percent"
    oid = ".1.3.6.1.2.1.33.1.2.4.0"
  [[inputs.snmp.field]]
    name = "on_battery_seconds"
    oid = ".1.3.6.1.2.1.33.1.2.2.0"

# Switch port status (IF-MIB). One key per port, tagged by name.
[[inputs.snmp]]
  agents = ["udp://192.168.10.1:161"]
  version = 3
  sec_name = "monitor"
  sec_level = "authPriv"
  auth_protocol = "SHA"
  auth_password = "${SNMP_AUTH_PASSWORD}"
  priv_protocol = "AES"
  priv_password = "${SNMP_PRIV_PASSWORD}"
  agent_host_tag = "source"
  name = "switch"
  [[inputs.snmp.table]]
    name = "switch_if"
    [[inputs.snmp.table.field]]
      name = "ifName"
      oid = ".1.3.6.1.2.1.31.1.1.1.1"
      is_tag = true
    [[inputs.snmp.table.field]]
      name = "oper_status"        # 1 = up, 2 = down
      oid = ".1.3.6.1.2.1.2.2.1.8"
  # Keep only the uplink and reader ports, to stay well under 200 keys.
  [inputs.snmp.tagpass]
    ifName = ["Gi1/0/1", "Gi1/0/24"]

[[outputs.http]]
  url = "${STATUSCONNECT_INGEST_URL}"
  method = "POST"
  data_format = "influx"
  content_encoding = "gzip"
  timeout = "10s"
  [outputs.http.headers]
    Content-Type = "text/plain; charset=utf-8"
```

Example rules:

- `ping.percent_packet_loss{host=edge-lab,url=192.168.10.31}` `gte 100`, severity `down`: the RFID reader is unreachable.
- `ups.battery_status{host=edge-lab,source=192.168.10.20}` `gte 3`, severity `down`.
- `ups.charge_percent{…}` `lt 50`, severity `degraded`.
- `switch_if.oper_status{…,ifName=Gi1/0/24}` `equals 2`, missing `fail`, severity `down`.

To get **one public status component per device**, create one agent monitor per device and give each its own `[[outputs.http]]` block with that monitor's URL, routing metrics with `tagpass` (for example `[outputs.http.tagpass] source = ["192.168.10.20"]`). If the edge box itself goes offline, every monitor fed by it goes `down` with `no_data`, which is usually what you want to see.

## Good practice

- **Control cardinality.** Use `fieldinclude` (called `fieldpass` before Telegraf 1.29), `namepass` and `tagpass` / `tagexclude` so each monitor sends tens of keys, not hundreds. Keys beyond `AGENT_MAX_METRICS_PER_MONITOR` (200) are dropped and never show up. Inputs like `processes` per PID, `net` on every veth interface, or `docker` per container grow without bound.
- **Interval of 60 s or more.** The cron evaluates once a minute, so faster collection only spends the rate limit (12 requests per token per minute) without making alerts faster.
- **Always gzip.** `content_encoding = "gzip"` cuts a typical host payload by 5–10×, keeping it far below the 256 KB limit.
- **Treat the URL as a secret.** Anyone with it can push metrics to that monitor (they cannot read anything). Keep it out of the config file and out of version control:
  - Linux (systemd package): put `STATUSCONNECT_INGEST_URL=https://…/ingest/sca_…` in `/etc/default/telegraf`, owned by `root:telegraf`, mode `0640`. Telegraf substitutes `${STATUSCONNECT_INGEST_URL}` at startup.
  - Windows: set it as a machine environment variable (`setx /M STATUSCONNECT_INGEST_URL "…"`) and restart the service, or keep it in `telegraf.conf` and restrict that file's ACL to Administrators and SYSTEM.
  - If it leaks, **Rotate token** on the monitor page and update the agent.
- **Read-only SNMP.** Use SNMPv3 `authPriv` or a read-only v2c community restricted to the edge box's IP on the device.

## Troubleshooting

Run `telegraf --config telegraf.conf --test` to see what would be sent, and check the Telegraf log (`journalctl -u telegraf`, or the Windows Event Log) for `[outputs.http]` errors.

| Status | Meaning | Fix |
|---|---|---|
| `204` | Stored | — |
| `404` | Unknown or rotated token, monitor is not an agent, or `AGENT_INGEST_ENABLED` is off. All look the same on purpose. | Copy the current URL from the monitor page (rotate if lost); check the flag on the server. |
| `400` | Body is not valid line protocol, not valid gzip, or has more than `AGENT_INGEST_MAX_LINES` lines. The response never echoes the payload. | `data_format = "influx"`; with gzip, set `content_encoding = "gzip"` so the header matches; lower `metric_batch_size` in `[agent]`. |
| `413` | Body over `AGENT_INGEST_MAX_BYTES`, or over `AGENT_INGEST_MAX_DECOMPRESSED_BYTES` after gunzip. | Enable gzip, trim fields with `fieldinclude`, or lower `metric_batch_size` in `[agent]`. |
| `415` | Wrong content type (for example protobuf). | Send `Content-Type: text/plain`. |
| `429` | Rate limit per token or per IP; `Retry-After` says when to retry. | Use a 60 s `flush_interval`. Many agents behind one NAT address share the per-IP limit; raise `AGENT_INGEST_RATE_PER_IP_PER_MINUTE` if needed. |

If the metrics table stays empty and Telegraf logs no errors, wait for one `flush_interval` and check that the `outputs.http` block is not filtered out by a `namepass`. The monitor stays `down` with `no_data` until the first push arrives.

## Try it in the dev environment

The compose file has an optional `telegraf` profile (dev and test only; production never runs it):

```bash
# 1. Enable ingestion for the app container
echo "AGENT_INGEST_ENABLED=true" >> .env
./scripts/stc.sh up

# 2. Create an agent monitor at http://localhost:8070/app and copy its token (sca_…)

# 3. Start Telegraf against http://app/ingest/<token>
STATUSCONNECT_AGENT_TOKEN=sca_... docker compose --profile telegraf up -d telegraf

# 4. After a minute, run the cron once and watch the monitor page
./scripts/stc.sh artisan monitor:check-due
```

The config is `docker/telegraf/telegraf.conf`: 60 s interval, host `dev-host`, cpu, mem, disk and system. To see an incident, add the rule `mem.used_percent{host=dev-host} gt 0` (severity `down`) and run `monitor:check-due`. Then `docker compose stop telegraf` and, after interval + grace, run it again: the check goes `down` with `no_data`.

Automated coverage of the same path: `tests/Feature/AgentEndToEndTest.php`.
