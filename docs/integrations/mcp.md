# MCP server for AI clients

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

StatusConnect speaks the [Model Context Protocol](https://modelcontextprotocol.io). Claude Code, Cursor and other MCP clients can ask it what is down, read a monitor's recent checks, list incidents and see what the public status page shows. With a key that allows it, they can also pause and resume monitors, create monitors and schedule maintenance.

The server is one endpoint, `POST /v1/mcp`, on your StatusConnect address (for example `https://app.statusconnect.net/v1/mcp`). It uses the Streamable HTTP transport without streams: each request gets one JSON answer and nothing stays open, so it runs on shared hosting like the rest of the app. Epic [#183](https://github.com/suporterfid/statusconnect/issues/183), issues [#184](https://github.com/suporterfid/statusconnect/issues/184) (read tools) and [#185](https://github.com/suporterfid/statusconnect/issues/185) (write tools).

## 1. Create an API key

In the operator app, open **API Keys** (**Chaves de API**) and create a key with the `monitors:read` permission only. Copy it: it starts with `sc_` and is shown once.

- The key belongs to the environment it was created in. The tools see only that environment's monitors, incidents and status page; they take no tenant or environment argument.
- A key with `monitors:write`, `monitors:operate`, `secrets:manage` or `*` can read too. Prefer a read-only key for AI clients: an AI client acts on what it is asked and on what it reads, so give it write permissions only when you want it to change monitors.
- `monitors:operate` adds `pause_monitor` and `resume_monitor`. `monitors:write` adds those and `create_monitor` and `create_maintenance_window` (see [Write tools](#write-tools)).
- Revoke the key on the same page to cut the client off at once. Keys can also carry an expiry date.

Sessions from the browser and GrandpaSSOn tokens are not accepted on this endpoint.

## 2. Connect a client

Replace `sc_...` with your key and the host with your installation.

### Claude Code

```bash
claude mcp add --transport http statusconnect https://app.statusconnect.net/v1/mcp \
  --header "Authorization: Bearer sc_..."
```

Then ask, for example, "which StatusConnect monitors are down?".

### Cursor

`.cursor/mcp.json` in the project, or `~/.cursor/mcp.json` for every project:

```json
{
  "mcpServers": {
    "statusconnect": {
      "url": "https://app.statusconnect.net/v1/mcp",
      "headers": { "Authorization": "Bearer sc_..." }
    }
  }
}
```

### Claude Desktop

Custom connectors added under **Settings → Connectors** sign in with OAuth, which StatusConnect does not offer yet. Use the `mcp-remote` bridge in `claude_desktop_config.json` instead (it needs Node.js on the desktop machine):

```json
{
  "mcpServers": {
    "statusconnect": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://app.statusconnect.net/v1/mcp", "--header", "Authorization:${STATUSCONNECT_AUTH}"],
      "env": { "STATUSCONNECT_AUTH": "Bearer sc_..." }
    }
  }
}
```

### Any other client

Send JSON-RPC 2.0 messages with `POST`, one per request, with `Authorization: Bearer sc_...` and `Content-Type: application/json`:

```bash
curl -s https://app.statusconnect.net/v1/mcp \
  -H "Authorization: Bearer sc_..." -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"list_monitors","arguments":{"state":"down"}}}'
```

## 3. Tools

`tools/list` shows only the tools the key may call. A read-only key sees the four read tools.

### Read tools

All four are read-only and need `monitors:read` (or a permission that implies it).

| Tool | Arguments | Returns |
|---|---|---|
| `list_monitors` | `state` (optional: `pending`, `up`, `degraded`, `down`, `blocked`, `skipped`, `paused`, `maintenance`), `limit` (1–200, default 50) | Monitors with id, name, kind, target (HTTP and TCP only), state, interval, last check, latency, down since, TLS expiry; plus the total |
| `get_monitor` | `monitor_id` (from `list_monitors`) | The monitor and its last 20 checks: time, state, latency, HTTP status, failure reason |
| `list_incidents` | `status` (`open` by default, `resolved`, `all`), `limit` (1–100, default 20) | Incidents newest first, with monitor, start, end, duration and latest update |
| `get_status_page` | none | Overall state and uptime, components with state and uptime, maintenance, incidents and the five latest notices, as the public page shows them |

Each result comes as `structuredContent` and as the same JSON in a `text` item. Request headers, request bodies and heartbeat tokens are never returned.

A tool that cannot answer (unknown monitor id, missing permission, invalid argument) returns `isError: true` with a message. A monitor id from another environment reads exactly like one that does not exist.

### Write tools

They act on the key's environment only and go through the same code as the REST API: the same validation, plan limits and outbound (egress) policy. When the plan or the policy refuses a change, the tool returns `isError: true` with the message the API would give, and nothing changes. A key without the permission gets `isError: true` even if it calls the tool by name without listing it.

| Tool | Permission | Arguments | Effect |
|---|---|---|---|
| `pause_monitor` | `monitors:operate` or `monitors:write` | `monitor_id` | Stops checking the monitor: its state becomes `paused`, its failure streak is cleared and its open automatic incident is closed (manual incidents stay open). The status page shows it as no data. Pausing a paused monitor changes nothing. |
| `resume_monitor` | `monitors:operate` or `monitors:write` | `monitor_id` | Starts checking it again, fresh: state `pending`, checked at once. Resuming a running monitor changes nothing. Fails when the plan already has as many enabled monitors as it allows. |
| `create_monitor` | `monitors:write` | `name`, `kind` (`http` default, `tcp`, `heartbeat`), `target` (URL for http, `host:port` for tcp; not used for heartbeat), `interval_seconds` (optional), `egress_profile` (optional: `internal` default, `public-crawl`, `api`) | Creates an enabled monitor, like `POST …/monitors`. Without `interval_seconds` it uses the plan minimum, and at least 60 s. A heartbeat monitor's result includes its `ping_url`. Agent monitors cannot be created here. |
| `create_maintenance_window` | `monitors:write` | `title`, `starts_at`, `ends_at` (ISO 8601, end after start), `monitor_ids` (optional) | Schedules maintenance with notifications suppressed, like `POST …/maintenance-windows`. Ids outside the environment are ignored; the result lists the monitors the window covers. |

None of them deletes anything. Deleting monitors, editing notification channels and managing API keys are not available over MCP.

## 4. Protocol details

- Methods: `initialize`, `ping`, `tools/list`, `tools/call`. Notifications get `202` with an empty body. Other methods get JSON-RPC error `-32601`.
- Protocol versions `2025-11-25`, `2025-06-18` and `2025-03-26`; any other requested version is answered with `2025-06-18`.
- No `Mcp-Session-Id`: the server keeps no session. `GET` and `DELETE` answer `405`, since there is no stream to open or session to end.
- Batches (a JSON array) answer `-32600`; malformed JSON answers `-32700`.
- Missing, revoked or expired key: `401` with `WWW-Authenticate: Bearer`. A key created without an environment, or an organization still waiting for approval: `403`.
- A request carrying an `Origin` header from another host is refused with `403` (DNS rebinding guard). Desktop and CLI clients send no `Origin`.
- Rate limit: 120 requests per minute per IP address.
