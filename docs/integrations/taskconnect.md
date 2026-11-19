# TaskConnect Integration

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

StatusConnect provides direct integration with TaskConnect for health monitoring, cron heartbeat verification, and task execution delegation via notification channels.

---

## 1. Monitoring TaskConnect Health

StatusConnect can monitor TaskConnect service availability by polling its platform health endpoint (`GET /v1/platform/health`).

### Configuration via API / Dashboard

Create a Monitor targeted at TaskConnect's health check:

```json
{
  "name": "TaskConnect Health Check",
  "type": "http",
  "url": "https://taskconnect.example.com/v1/platform/health",
  "method": "GET",
  "check_interval_seconds": 60,
  "timeout_seconds": 10,
  "expected_status": 200,
  "headers": {
    "User-Agent": "StatusConnect-Monitor/1.0"
  }
}
```

When `GET /v1/platform/health` fails or returns a 5xx response code, StatusConnect will transition the monitor state to `down` and automatically trigger configured notification channels.

---

## 2. Heartbeat Cron Ping Recipe

TaskConnect scheduled jobs (cron tasks) can send heartbeat pings to StatusConnect to ensure background job execution reliability.

### Step 1: Create a Heartbeat Monitor in StatusConnect

Create a `heartbeat` monitor type with an expected interval (e.g. 5 minutes / 300 seconds):

```json
{
  "name": "TaskConnect Cron Worker Heartbeat",
  "type": "heartbeat",
  "expected_interval_seconds": 300,
  "grace_period_seconds": 60
}
```

StatusConnect generates a unique ping URL for the monitor, e.g.:
`https://status.example.com/api/v1/ping/mon_01h8abcdef123456`

### Step 2: Configure TaskConnect Cron Ping

In your TaskConnect cron job or worker script, invoke the ping endpoint upon successful job completion:

```bash
# Example curl ping from TaskConnect cron runner
curl -fsS -X POST "https://status.example.com/api/v1/ping/mon_01h8abcdef123456" \
  -H "User-Agent: TaskConnect-Worker/1.0"
```

If TaskConnect fails to send a ping within 360 seconds (interval + grace period), StatusConnect marks the monitor as degraded or down and dispatches alerts.

---

## 3. TaskConnect Notification Channel Configuration

StatusConnect can dispatch incident notifications directly into TaskConnect to trigger automated remediation tasks or create tracking issues.

### Channel Configuration

Create a notification channel of type `taskconnect`:

```json
{
  "name": "TaskConnect Incident Receiver",
  "type": "taskconnect",
  "config_json": {
    "url": "https://taskconnect.example.com/v1/tenants/ten_01h8abc/environments/env_01h8def/tasks",
    "api_key": "tc_live_secret_token_here",
    "verify_tls": true
  },
  "enabled": true
}
```

### Automatic Idempotency & Delivery

- **Idempotency Key**: Deliveries automatically include an `X-Idempotency-Key` header computed from `sc_sha256(incident_id:channel_id:event)`. Duplicate events for the same incident and channel will be safely deduplicated by TaskConnect.
- **Outbound Policy Enforcement**: Target URLs are strictly validated against SSRF protection policies (`OutboundPolicy::validateUrl`). Pinned IP routing prevents DNS rebalancing attacks.
- **Graceful Fallback**: If TaskConnect is unreachable or returns HTTP errors, StatusConnect logs the failed delivery with details (`response_code`, `error_excerpt`) while ensuring core monitor execution and incident tracking continue without interruption.
