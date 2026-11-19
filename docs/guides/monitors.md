# Monitor recipes

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

Patterns for checks that a plain "is the port open / does it answer 200" monitor misses. They came out of the audit of an IoT platform in [#198](https://github.com/suporterfid/statusconnect/issues/198), where `GET /api/ready` answered `ok` while customers were stuck:
- readers were offline while MQTT was up;
- `/api/health` was green while the `/portal/` web app returned 404.

## Single-page app (SPA) portal

A health endpoint proves the API process is up. It does not prove the web portal is served. A missing build, a broken nginx `location` or a fallback `index.html` from another app can still answer `200`. Check the portal page itself, and require something that only its own HTML contains.

The monitor form in the operator app does not edit assertions yet, so create or update this monitor through the API (`POST` or `PUT …/monitors`, see `/api`):

```json
{
  "name": "Customer portal (Acme)",
  "kind": "http",
  "target": "https://iot.example.com/portal/",
  "egress_profile": "public-crawl",
  "interval_seconds": 60,
  "assertions": [
    { "type": "status_code", "operator": "between", "expected_value": "200..299" },
    { "type": "body_contains", "operator": "contains", "expected_value": "<title>Acme Portal", "case_sensitive": false }
  ]
}
```

- Sending `assertions` replaces the default `200..299` status assertion, so keep it in the list.
- Choose a marker that is in the SPA's `index.html` as served, such as its `<title>`, the id of its root element or the name of its main bundle. Avoid anything rendered by JavaScript: the checker does not run scripts.
- `body_matches` takes a PCRE pattern (up to 500 characters) when a fixed string is not enough, for example `"/<script[^>]+src=\"\\/portal\\/assets\\/index-[^\"]+\\.js\"/"`.
- Add the monitor to the same status page component as the API monitor. The component then goes down when either one fails.

## MQTT brokers and IoT readers

What each check proves:

| Check | Proves | Does not prove |
|---|---|---|
| TCP monitor on the MQTT port (`host:8883`) | something accepts TCP connections on that port | that the broker behind a proxy answers. An nginx `stream` proxy accepts the connection before it opens its upstream, so the check passes while the broker is unreachable. |
| MQTT monitor (kind `mqtt`, CONNECT/CONNACK) | the whole path to the broker: TCP, TLS, the proxy and the broker accepting the credentials | that the readers are connected |
| Readers-online metric from the customer's backend, through agent ingestion (see [Readers-online metric](#readers-online-metric)) | enough readers are connected and reporting | that each reader reads tags |

### MQTT monitor

An MQTT monitor ([#200](https://github.com/suporterfid/statusconnect/issues/200)) opens a connection to the broker and runs these steps every interval:
1. performs the TLS handshake for `mqtts://`, with SNI and certificate verification;
2. sends an MQTT 3.1.1 `CONNECT` with the client id, user name and password;
3. requires `CONNACK` with return code 0 within the monitor's timeout;
4. sends `DISCONNECT`.

Nothing stays open between checks. The check fails, with the reason in the check history, when:
- the connection is refused;
- the TLS handshake fails;
- the proxy accepts but no `CONNACK` comes back in time, which is the `upstream timed out` case;
- the broker answers with a non-zero code, such as `4 (bad user name or password)` or `5 (not authorized)`.

Use a dedicated broker user for monitoring, with no publish or subscribe rights. The check never publishes or subscribes. The password is stored encrypted with the app key and is never returned by the API (`mqtt_password_set` says whether one is stored).

In the operator app, choose **MQTT broker (CONNECT)** as the kind. Through the API:

```json
{
  "name": "MQTT broker (Acme)",
  "kind": "mqtt",
  "target": "mqtts://mqtt.example.com:8883",
  "mqtt_username": "statusconnect-monitor",
  "mqtt_password": "…",
  "interval_seconds": 60,
  "timeout_ms": 8000
}
```

- `target` is `mqtt://host[:port]` (no TLS, default port 1883) or `mqtts://host[:port]` (TLS, default port 8883).
- The port must be in `OUTBOUND_ALLOWED_PORTS`, and private or reserved addresses are refused, as for TCP monitors.
- `verify_tls: false` accepts a self-signed broker certificate.
- `mqtt_client_id` is optional, up to 23 letters, digits, `_` or `-`. Without it the client id is `sc-` plus the end of the monitor id, so it stays the same between checks.

Limits:
- MQTT checks run one after another inside the minute tick, after the HTTP and TCP checks of the same wave. Each one takes up to its timeout when the broker does not answer, so keep timeouts short.
- Client certificates (mutual TLS) and MQTT 5 are not supported yet.
- Second-location confirmation does not apply to MQTT monitors: a failure opens an incident without being repeated elsewhere, like heartbeat monitors.

### TCP check on the MQTT port

A plain TCP monitor on the port is still useful when no broker credentials are available. TCP monitors only connect to ports in `OUTBOUND_ALLOWED_PORTS` (default `80,443,8080,8095`). A monitor on 8883 is refused by the outbound policy until the installation adds the port:

```dotenv
OUTBOUND_ALLOWED_PORTS=80,443,8080,8095,8883
```

Then run `php artisan config:clear` on the server, or `php artisan config:cache` if the configuration is cached, and create the monitor:

```json
{ "name": "MQTT TLS port (Acme)", "kind": "tcp", "target": "mqtt.example.com:8883", "interval_seconds": 60 }
```

Adding a port opens it for every organization on the installation. Keep the list short.

Read a green result as "the proxy is listening". A failure is real, but a pass does not mean readers can connect. On a server where nginx proxies MQTT to a cloud broker, an `upstream timed out` in the nginx error log while this check stays green is exactly the gap the MQTT monitor closes.

### Readers-online metric

The checker cannot reach the customer's database, so the customer's backend pushes the number to an agent monitor ([#201](https://github.com/suporterfid/statusconnect/issues/201)). A small reporter service in the backend can do this. Once a minute, at second 30, it POSTs one line in InfluxDB line protocol:

```text
iot_readers active=12i,online=11i,offline=1i,online_ratio=0.9167
```

- `active` counts the devices not marked inactive.
- `online` uses the dashboard's rule: last seen within the device's `communicationTimeout`, or 300 s by default.
- `online_ratio` is `online / active`, and `1` when there are no active readers.

To set it up:
1. Create an agent monitor with interval 60 and grace 180 s. If no line arrives for that long, the monitor goes down.
2. Add two rules on `iot_readers.online_ratio`:

   | Operator | Expected | Severity |
   |---|---|---|
   | `lt` | `0.5` | down |
   | `lt` | `0.9` | degraded |

3. Rotate the ingestion token and set the full URL in the backend's environment. The URL carries the token, so treat it as a secret:

   ```dotenv
   STATUSCONNECT_INGEST_URL=https://app.statusconnect.net/ingest/sca_...
   ```

4. Deploy the backend, check that the monitor receives data, then enable it and add it to the customer's status page component.

Without `STATUSCONNECT_INGEST_URL` the backend sends nothing. A reader that is switched off on purpose still counts as offline. Mark it inactive in the backend so it leaves the ratio.

#### From Telegraf on the customer's server

The customer's server can also send the metric from the Telegraf agent that already pushes its CPU, memory, disk and network to an agent monitor ([#201](https://github.com/suporterfid/statusconnect/issues/201)). This path needs no backend deploy. It also handles readers retired long ago, which stay registered as `active`: with the backend count above, they would keep the ratio low for good.

What the host runs:
- **A read-only database user.** Each installation has a MongoDB user, `telegraf_readers_<host>`, whose only right is `find` on `<db>.devices`. Its URI is `READERS_MONGO_URI` in `/etc/default/telegraf` (mode 0640, owner `root:telegraf`). The backend's own credentials are not shared with Telegraf.
- **`/usr/local/bin/statusconnect-readers-online`.** It prints one line, `readers online=8i,expected=8i,registered=11i,online_pct=100`:
  - `online` uses the backend's `deviceStatusAt()` rule. The newest contact (`lastSeen`, `lastBackendSeenAt`, `lastConnectedAt` or `lastCommunication`) must be within `communicationTimeout`, 300 s by default, with no later disconnect. The script recomputes this from the timestamps instead of reading `communicationStatus`, so a stopped backend shows up as readers going offline.
  - `expected` counts readers that are not `active: false` and had contact in the last 7 days. A reader that dies drops out of `expected` after a week of alerts, and a retired one never enters it.
  - `registered` counts every document, for reference.
- **A Telegraf input:**

  ```toml
  [[inputs.exec]]
    commands = ["/usr/local/bin/statusconnect-readers-online"]
    timeout = "15s"
    data_format = "influx"
  ```

  Telegraf 1.40 warns that `commands` is deprecated, but keep it: `command = [...]` fails to parse in that version and stops the agent.
- **Rules on the agent monitor**, on `readers.online_pct{host=<host>}`:

  | Operator | Expected | Severity | Missing |
  |---|---|---|---|
  | `lt` | `90` | degraded | `fail` |
  | `lt` | `50` | down | `ignore` |

  With `fail` on the degraded rule, a script that cannot reach the database marks the monitor degraded instead of silently passing.

The script, which uses the backend's own `mongodb` driver from `node_modules`:

```js
#!/usr/bin/env node
// Readers online for the StatusConnect agent monitor (suporterfid/statusconnect#201).
// Prints one InfluxDB line: readers online/expected/registered and online_pct.
// Same rule as the backend's deviceStatusAt(): a reader is online when its newest
// contact (lastSeen, lastBackendSeenAt, lastConnectedAt, lastCommunication) is
// within communicationTimeout (default 300 s) and no later disconnect exists.
// It is recomputed here, so a stopped backend shows up as readers going offline.
// Expected = active readers seen in the last EXPECTED_DAYS days, so readers
// retired long ago do not keep the page degraded.
const { MongoClient } = require('/srv/backend/node_modules/mongodb');
const EXPECTED_DAYS = 7;
(async () => {
  const uri = process.env.READERS_MONGO_URI;
  if (!uri) throw new Error('READERS_MONGO_URI not set');
  const client = await MongoClient.connect(uri, { serverSelectionTimeoutMS: 5000 });
  try {
    const docs = await client.db().collection('devices').find({}, { projection: {
      active: 1, lastSeen: 1, lastBackendSeenAt: 1, lastConnectedAt: 1, lastCommunication: 1,
      lastDisconnectedAt: 1, communicationTimeout: 1 } }).toArray();
    const now = Date.now();
    let expected = 0, online = 0;
    for (const d of docs) {
      const seen = [d.lastSeen, d.lastBackendSeenAt, d.lastConnectedAt, d.lastCommunication]
        .filter(Boolean).map((v) => new Date(v).getTime()).filter((t) => !Number.isNaN(t));
      const last = seen.length ? Math.max(...seen) : 0;
      if (d.active === false || !last || now - last > EXPECTED_DAYS * 86400e3) continue;
      expected++;
      const disconnected = d.lastDisconnectedAt && new Date(d.lastDisconnectedAt).getTime() > last;
      if (!disconnected && now - last <= (d.communicationTimeout || 300) * 1000) online++;
    }
    const pct = expected ? (online / expected) * 100 : 100;
    process.stdout.write(`readers online=${online}i,expected=${expected}i,registered=${docs.length}i,online_pct=${pct.toFixed(2)}\n`);
  } finally {
    await client.close();
  }
})().catch((e) => { process.stderr.write(`readers-online: ${e.message}\n`); process.exit(1); });
```

Test as the Telegraf user before restarting the agent: `set -a; . /etc/default/telegraf; set +a; sudo -E -u telegraf telegraf --config /etc/telegraf/telegraf.conf --test --input-filter exec`.

Use one path per installation. If the backend reporter is turned on later, remove the exec input and its rules first, or the monitor gets the same signal twice under different names.

## Latency from an agent metric

An HTTP monitor measures its own response time. An agent monitor has none, but the agent often reports one, for example `app_latency.query_p95_ms{host=acme}`: the p95 of the real portal requests seen from the customer's server ([#242](https://github.com/suporterfid/statusconnect/issues/242)).

Set **Latency metric (optional)** on the agent monitor form (`agent_latency_metric_key` in the API) to that metric's key, exactly as it appears under **Latest metrics**. On each check:

- the metric's current value, rounded to whole ms, becomes the check result's `latency_ms`;
- a metric that did not arrive in the latest push, a stale ingestion (no data), or a value of 0 or less records no latency (`latency_ms` 0, which rollups skip);
- the latency never changes the state. Use a rule on the same key for that, for example `gt 3000` degraded.

From there it flows like an HTTP monitor's latency: hourly and daily rollups, the response-time charts on the public component page, and the 24 h average next to the component's availability on the main status page.

The value is published as the component's response time, so pick a metric meant for customers to see. Metric keys and tags never appear on the public page. Do not put latencies measured from outside and the portal's own latency in the same component: the page averages its monitors together.
