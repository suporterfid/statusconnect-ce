# Server metrics with the OpenTelemetry Collector or Grafana Alloy (OTLP push)

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

Agent monitors also accept **OTLP/HTTP with JSON encoding**, the open OpenTelemetry protocol. Use it when your servers already run the [OpenTelemetry Collector](https://opentelemetry.io/docs/collector/) or [Grafana Alloy](https://grafana.com/docs/alloy/), or when you prefer them to Telegraf. Everything else works as described in [`telegraf.md`](telegraf.md): enabling the flag, creating the monitor, the one-time ingestion URL, rules, limits and no-data handling.

This is phase 3 of epic [#67](https://github.com/suporterfid/statusconnect/issues/67).

## Endpoint

```
POST https://status.example.com/ingest/sca_<token>/v1/metrics
Content-Type: application/json
Content-Encoding: gzip        (optional)
```

The `otlphttp` exporter appends `/v1/metrics` to its `endpoint`, so **use the ingestion URL shown on the monitor page as the endpoint, unchanged**.

What is supported:

| | |
|---|---|
| Transport | OTLP/HTTP only. gRPC is not supported (shared hosting cannot serve it). |
| Encoding | **JSON only** (`encoding: json`). Protobuf gets `415` with a message saying so. |
| Metric types | **Gauge** and **Sum**: the latest data point of each series is kept. |
| Ignored and counted | Histogram, ExponentialHistogram and Summary points, points with no value, non-finite values (NaN, Inf), points with more than `AGENT_OTLP_MAX_ATTRIBUTES` attributes, keys longer than `AGENT_METRIC_KEY_MAX_LENGTH`, and new keys over `AGENT_MAX_METRICS_PER_MONITOR`. |
| Success response | `200` with an `ExportMetricsServiceResponse`. That is `{}` when everything was stored, or `partialSuccess.rejectedDataPoints` with the count of points that were left out. |
| Errors | `400` (invalid JSON or gzip, or more than `AGENT_INGEST_MAX_LINES` data points), `404` (token), `413` (size), `415` (encoding), `429` (rate limit, with `Retry-After`). Error bodies are `{"code": …, "message": …}`. |

## Metric keys

A key is the metric name, then the data point attributes and selected resource attributes, sorted, in braces:

```
system.memory.utilization{host.name=web1,state=used}
system.filesystem.utilization{device=/dev/sda1,host.name=web1,mode=rw,mountpoint=/,type=ext4}
system.cpu.load_average.1m{host.name=web1}
```

Resource attributes copied into keys are listed in `AGENT_OTLP_RESOURCE_ATTRIBUTES` (default `host.name,service.name`). Others, such as `os.type`, are left out so keys stay short and stable. Attribute values that are arrays, maps or bytes are dropped from the key.

OpenTelemetry utilization metrics are **ratios from 0 to 1**, not percentages, so a "disk 90 % full" rule is `system.filesystem.utilization{…} gte 0.9`.

## OpenTelemetry Collector

Use the **contrib** distribution (`otelcol-contrib`), which includes the `hostmetrics` receiver and the `resourcedetection` processor. Packages for Linux and Windows are on the [releases page](https://github.com/open-telemetry/opentelemetry-collector-releases/releases).

`/etc/otelcol-contrib/config.yaml`:

```yaml
receivers:
  hostmetrics:
    collection_interval: 60s
    scrapers:
      cpu:
        metrics:
          system.cpu.time: { enabled: false }          # cumulative, one series per CPU and state
          system.cpu.utilization: { enabled: true }
      memory:
        metrics:
          system.memory.utilization: { enabled: true }
      load:
      filesystem:
        include_mount_points: { mount_points: ["/"], match_type: strict }
        metrics:
          system.filesystem.utilization: { enabled: true }

processors:
  resourcedetection:
    detectors: [system]           # sets host.name

exporters:
  otlphttp/statusconnect:
    endpoint: ${env:STATUSCONNECT_INGEST_URL}    # https://…/ingest/sca_… (no /v1/metrics)
    encoding: json
    compression: gzip

service:
  pipelines:
    metrics:
      receivers: [hostmetrics]
      processors: [resourcedetection]
      exporters: [otlphttp/statusconnect]
```

Put `STATUSCONNECT_INGEST_URL=…` in `/etc/otelcol-contrib/otelcol-contrib.conf` (the environment file the package's systemd unit reads), owned by root and not world-readable, then `systemctl restart otelcol-contrib`.

The `system.cpu.utilization` series count grows with CPU count (one per CPU and state, eight states). On machines with many cores, add a `filter` processor, or keep `system.cpu.load_average.*` and drop CPU utilization, so the monitor stays under 200 keys.

If you already run a Collector that sends elsewhere, add `otlphttp/statusconnect` as a **second exporter** in a pipeline of its own with only the few metrics you want alerts on.

## Grafana Alloy

Alloy collects host metrics through its built-in node_exporter (`prometheus.exporter.unix`). It converts them to OTLP and sends them with `otelcol.exporter.otlphttp`. node_exporter exposes hundreds of series, so keep only the ones you need before converting.

`/etc/alloy/config.alloy`:

```alloy
prometheus.exporter.unix "host" {
  set_collectors = ["cpu", "meminfo", "filesystem", "loadavg"]
  filesystem {
    mount_points_exclude = "^/(dev|proc|run|sys|var/lib/docker/.+)($|/)"
  }
}

prometheus.scrape "host" {
  targets         = prometheus.exporter.unix.host.targets
  scrape_interval = "60s"
  forward_to      = [prometheus.relabel.keep.receiver]
}

// Keep a handful of series: the monitor stores at most 200 keys.
prometheus.relabel "keep" {
  forward_to = [otelcol.receiver.prometheus.host.receiver]

  rule {
    source_labels = ["__name__"]
    regex         = "node_load1|node_load5|node_memory_MemAvailable_bytes|node_memory_MemTotal_bytes|node_filesystem_avail_bytes|node_filesystem_size_bytes"
    action        = "keep"
  }
  rule {
    source_labels = ["mountpoint"]
    regex         = "|/"
    action        = "keep"
  }
  // Name the machine in every key (node_exporter series carry no host label).
  rule {
    target_label = "host"
    replacement  = constants.hostname
  }
}

otelcol.receiver.prometheus "host" {
  output {
    metrics = [otelcol.exporter.otlphttp.statusconnect.input]
  }
}

otelcol.exporter.otlphttp "statusconnect" {
  encoding = "json"
  client {
    endpoint    = sys.env("STATUSCONNECT_INGEST_URL")
    compression = "gzip"
  }
}
```

Set `STATUSCONNECT_INGEST_URL` in `/etc/default/alloy` (Debian/Ubuntu) or `/etc/sysconfig/alloy` (RHEL), owned by root and not world-readable, and restart Alloy.

Keys then look like `node_load5{host=web1,service.name=integrations/unix}` and `node_filesystem_avail_bytes{device=/dev/sda1,fstype=ext4,host=web1,mountpoint=/,service.name=integrations/unix}`. The scrape job becomes the `service.name` resource attribute. node_exporter reports bytes, so alert on available bytes (`lt 5000000000`) rather than a percentage. Inside a container, node_exporter skips `overlay` filesystems by default, so run Alloy on the host to get disk metrics.

## Troubleshooting

- **`415 Protobuf is not supported`**: set `encoding: json` (Collector) or `encoding = "json"` (Alloy). The default is protobuf.
- **`404`**: wrong or rotated token, monitor is not an agent, or `AGENT_INGEST_ENABLED` is off. Use the ingestion URL exactly as shown, without adding `/v1/metrics`.
- **`partialSuccess` in the Collector log** (`otlphttp` logs it at warn level): some points were left out. The usual causes are histograms, or more keys than the monitor allows. Filter metrics before the exporter.
- **`429`**: the Collector retries with `Retry-After`. Keep `collection_interval` at 60 s or more.
- Debug locally with the Collector's `debug` exporter (`verbosity: detailed`) in the same pipeline to see exactly what is sent.

## Try it in the dev environment

The compose file has an optional `otelcol` profile (dev and test only), using `docker/otelcol/config.yaml`:

```bash
echo "AGENT_INGEST_ENABLED=true" >> .env && ./scripts/stc.sh up
# create an agent monitor at http://localhost:8070/app and copy its token
STATUSCONNECT_AGENT_TOKEN=sca_... docker compose --profile otelcol up -d otelcol
./scripts/stc.sh artisan monitor:check-due     # after the first export
```

Automated coverage: `tests/Unit/Agent/OtlpJsonMetricsParserTest.php` and `tests/Feature/AgentOtlpIngestTest.php`. Both use a payload captured from `otelcol-contrib` 0.115 (`tests/Fixtures/otel/hostmetrics.json`).
