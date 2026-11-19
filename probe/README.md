# StatusConnect remote probe

A small PHP endpoint that runs one HTTP or TCP check on request and returns the raw result. A StatusConnect checker calls it to confirm a failure from a second location before it opens an incident (#128). With `CHECK_EXECUTION=probe`, the checker also sends its regular HTTP checks here in batches (#222).

- Stateless: no database, no cache, no files written.
- No Composer dependencies. PHP 8.2+ with the curl extension.
- Every request is signed with a shared secret (HMAC-SHA256). Unsigned, wrongly signed or stale requests get `401`.
- It only connects to public addresses. Each host is resolved, every resolved IP is checked, the connection is pinned to a checked IP, and redirects are followed one hop at a time with the same check. Private, loopback, link-local, CGNAT, cloud metadata (`169.254.169.254`) and other reserved addresses are refused with `422`.

The same code runs on Cloud Run, on any container host, or on ordinary PHP shared hosting.

## Files

| Path | Purpose |
|---|---|
| `src/Probe.php` | The probe (class `StatusConnectProbe`). |
| `public/index.php` | Front controller. This folder is the only one that should be web-visible. |
| `public/.htaccess` | Sends every request to `index.php` (Apache). |
| `.htaccess` | For hosts where the document root cannot be set to `public/`. |
| `Dockerfile` | Apache + PHP 8.3 image that listens on `$PORT`. |
| `config.php.example` | Settings file for hosts without environment variables. |

## Settings

| Name | Required | Meaning |
|---|---|---|
| `PROBE_SECRET` | yes | Shared secret, at least 32 characters. Without it the probe answers `503` to every check. |
| `PROBE_LOCATION` | no | Label returned with each result, for example `São Paulo, BR`. Shown in the operator app. |
| `PROBE_DELAY_MAX_MS` | no | Turns on `GET /delay/{ms}`, which answers after `{ms}` milliseconds (capped at this value, at most 10000). It is a slow target for `php artisan monitor:capacity-test` (#214). Unsigned, so leave it unset except while measuring. |

Generate a secret:

```bash
openssl rand -hex 32
```

Put the same value in the StatusConnect server's `.env` (see the main deployment docs) and in the probe. Never commit it.

## Deploy on Cloud Run

Example for `southamerica-east1` (São Paulo). Replace the project ID with yours.

```bash
PROJECT=statusconnect-probe
REGION=southamerica-east1

gcloud config set project "$PROJECT"
gcloud services enable run.googleapis.com artifactregistry.googleapis.com \
  cloudbuild.googleapis.com secretmanager.googleapis.com

# 1. Store the secret in Secret Manager (the value is read from stdin, not the command line).
openssl rand -hex 32 | tr -d '\n' | gcloud secrets create probe-secret --data-file=-

# 2. Let the Cloud Run runtime service account read it.
PROJECT_NUMBER=$(gcloud projects describe "$PROJECT" --format='value(projectNumber)')
gcloud secrets add-iam-policy-binding probe-secret \
  --member="serviceAccount:${PROJECT_NUMBER}-compute@developer.gserviceaccount.com" \
  --role=roles/secretmanager.secretAccessor

# 3. Build and deploy from this folder (run from the repository root).
gcloud run deploy statusconnect-probe \
  --source probe \
  --region "$REGION" \
  --allow-unauthenticated \
  --set-secrets PROBE_SECRET=probe-secret:latest \
  --set-env-vars PROBE_LOCATION="São Paulo, BR" \
  --min-instances 0 \
  --max-instances 2 \
  --memory 256Mi \
  --timeout 40
```

`--allow-unauthenticated` is needed because the StatusConnect checker runs on shared hosting and cannot obtain Google identity tokens. The HMAC signature is what protects the endpoint. `--min-instances 0` keeps the cost near zero: the probe only receives traffic when a check fails, so the occasional cold start (one to two seconds) is acceptable.

The deploy prints the service URL (`https://statusconnect-probe-…-rj.a.run.app`). Give that URL and the secret to the StatusConnect server.

To read the secret back later (for example to configure the server), print it straight into a file with restricted permissions rather than to the terminal:

```bash
umask 077; gcloud secrets versions access latest --secret=probe-secret > probe-secret.txt
```

To rotate it: add a new version with `gcloud secrets versions add probe-secret --data-file=-`, redeploy (or wait for new instances), then update the server.

### Another region

Batch checks can run from more than one region, each monitor choosing its own ([capacity.md](../docs/deployment/capacity.md#probe-regions)). Deploy the same service in the other region, reusing the secret: Secret Manager secrets are global to the project, and the server signs every region's batches with one `CHECK_PROBE_SECRET`.

```bash
gcloud run deploy statusconnect-probe \
  --source probe \
  --region us-east1 \
  --allow-unauthenticated \
  --set-secrets PROBE_SECRET=probe-secret:latest \
  --set-env-vars PROBE_LOCATION="South Carolina, US" \
  --min-instances 0 \
  --max-instances 2 \
  --memory 256Mi \
  --timeout 40
```

Give `PROBE_LOCATION` a distinct label in each region: check results store it, so it tells where each check ran. Then list the regions on the StatusConnect server, the default first:

```
CHECK_PROBE_REGIONS=southamerica-east1=https://statusconnect-probe-…-rj.a.run.app,us-east1=https://statusconnect-probe-…-ue.a.run.app
```

A probe with a different secret answers `401` to every batch, so its region falls back to the server; check the `checker` heartbeat's `probe.regions` after the change.

## Deploy on shared hosting

1. Create a subdomain in another region from your main server, for example `probe.example.com`.
2. Upload the `probe/` folder (at least `src/`, `public/` and `.htaccess`).
3. Point the subdomain's document root to `probe/public`. If the panel does not allow that, point it to `probe/`; the root `.htaccess` then serves `public/` and refuses `src/` and `config.php`.
4. Set the secret, using one of:
   - an environment variable, if the panel offers them;
   - `SetEnv PROBE_SECRET …` and `SetEnv PROBE_LOCATION …` in `public/.htaccess` (only on servers where `.htaccess` files cannot be downloaded, which is the default for Apache);
   - a `config.php` file next to `src/` (never inside `public/`), copied from `config.php.example`, with permissions `600` or `640`.
5. Check that `https://probe.example.com/health` returns `{"ok":true}`. (`/healthz` works too, except on Cloud Run, which reserves paths ending in `z`.)

The probe needs outbound HTTP/HTTPS and TCP from the host. Some shared hosts block outbound connections to ports other than 80 and 443; TCP checks to other ports will then fail on that host.

## Test with curl

```bash
URL=https://statusconnect-probe-xxxx-rj.a.run.app
SECRET=$(cat probe-secret.txt)
BODY='{"v":1,"kind":"http","url":"https://example.com/","method":"GET","headers":{},"body":null,"timeout_ms":10000,"verify_tls":true,"follow_redirects":true}'
TS=$(date +%s)
SIG=$(printf '%s' "$TS.$BODY" | openssl dgst -sha256 -hmac "$SECRET" -r | cut -d' ' -f1)

curl -s -X POST "$URL/" \
  -H "X-StatusConnect-Timestamp: $TS" \
  -H "X-StatusConnect-Signature: sha256=$SIG" \
  --data-binary "$BODY"
```

A private target must be refused:

```bash
BODY='{"v":1,"kind":"http","url":"http://169.254.169.254/","timeout_ms":3000}'
# sign as above: the answer is 422 {"error":"blocked:private_address"}
```

## Protocol

`POST /` with a JSON body and two headers:

- `X-StatusConnect-Timestamp`: Unix time in seconds. Requests more than 60 seconds away from the probe's clock are refused.
- `X-StatusConnect-Signature`: `sha256=` followed by the hex HMAC-SHA256 of `<timestamp>.<raw body>` with the shared secret.

HTTP check:

```json
{"v":1,"kind":"http","url":"https://host/path?q","method":"GET","headers":{"Name":"value"},
 "body":null,"timeout_ms":10000,"verify_tls":true,"follow_redirects":true}
```

TCP check:

```json
{"v":1,"kind":"tcp","host":"example.com","port":443,"timeout_ms":5000}
```

`timeout_ms` is capped at 30000. Methods: GET, HEAD, POST, PUT, PATCH, DELETE, OPTIONS. The `Host`, `Content-Length`, `Connection` and `Transfer-Encoding` request headers are ignored.

Answer `200`:

```json
{"v":1,"location":"São Paulo, BR","kind":"http","ok":true,"status_code":200,"latency_ms":123,
 "headers":{"content-type":"text/html"},"body_base64":"…","tls_expires_at":"2027-01-01T00:00:00Z","error":null}
```

- `ok` is true when an HTTP response was received (any status code) or the TCP connection opened. The checker applies its own assertions to the result.
- `body_base64` holds at most the first 65536 bytes of the body.
- `error` names the failure when `ok` is false: `timeout`, `connection_refused`, `dns_resolution_failed`, `tls_handshake_failed`, `tls_verification_failed`, `connection_reset`, `empty_response`, `too_many_redirects`, `tcp_connect_error` or `curl_error_<n>`.

Batch of HTTP checks (#222): up to 50 checks, each with an `id` (string or integer, at most 64 characters, unique in the batch) and the same fields as a single HTTP check. TCP checks are not accepted in a batch.

```json
{"v":1,"kind":"batch","checks":[
  {"id":"m42","kind":"http","url":"https://a.example/","method":"GET","headers":{},"body":null,"timeout_ms":10000,"verify_tls":true,"follow_redirects":true},
  {"id":"m43","kind":"http","url":"https://b.example/health","timeout_ms":5000}]}
```

The checks run in parallel with `curl_multi`. Redirects are resolved, checked and pinned again before the next hop, as for a single check. The answer, `200`, has one entry per check in request order:

```json
{"v":1,"location":"São Paulo, BR","results":[
  {"id":"m42","v":1,"location":"São Paulo, BR","kind":"http","ok":true,"status_code":200,"latency_ms":120,"headers":{},"body_base64":"…","tls_expires_at":null,"error":null},
  {"id":"m43","blocked":"private_address"}]}
```

A check whose target resolves to a private address answers `{"id":…,"blocked":"private_address"}` instead of failing the whole batch. The batch answers only when its slowest check has finished, so the checker sends several batches at once.

Other answers: `400 {"error":"invalid_request"}`, `401 {"error":"unauthorized"}`, `405` for anything but `POST` (and `GET /health` or `GET /healthz`, which return `{"ok":true}`), `422 {"error":"blocked:private_address"}`, `503 {"error":"not_configured"}`.
