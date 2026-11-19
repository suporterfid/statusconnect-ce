# Second-location confirmation

Issue: [#128](https://github.com/suporterfid/statusconnect/issues/128).

Every check runs from the server that hosts StatusConnect. A network problem between that server and a target looks like an outage of the target. With second-location confirmation turned on, a failure that is about to open an incident is first repeated from somewhere else:

- If the target fails there too, the incident opens. Its summary ends with "(confirmed from <location>)".
- If the target answers there, no incident opens. The check is stored as up, with the local failure in its reason, so uptime is not charged for a problem on this server's side. For the next `SECOND_LOCATION_COOLDOWN_SECONDS` (default 300), further failures of that monitor reuse this verdict instead of asking again.
- If nobody answers in time, the incident opens as it always did. The feature never hides an outage.

Only failures that reach the monitor's confirmation threshold trigger a second check, so the cost stays close to zero. The check history in the operator app shows what the second location saw.

It applies to HTTP and TCP monitors. Heartbeat and agent monitors have no network check to repeat. Checks blocked by the outbound policy on this server are not sent anywhere.

## Providers

Providers are tried in the order of `SECOND_LOCATION_PROVIDERS` (default `probe,globalping`). The first one that answers decides.

### The StatusConnect probe

`probe/` in this repository is a small PHP endpoint that runs one HTTP request or one TCP connect and returns the result. Requests are signed with HMAC-SHA256. It refuses private, loopback, link-local and metadata addresses, including after redirects. It has no dependencies and runs on Cloud Run or on any PHP host. Deployment: [`probe/README.md`](../../probe/README.md).

The probe receives the monitor's full request, including custom headers and body, so it should be a host you control.

```dotenv
SECOND_LOCATION_PROBE_URL=https://statusconnect-probe-xxxx.southamerica-east1.run.app/
SECOND_LOCATION_PROBE_SECRET=<same value as PROBE_SECRET on the probe, at least 32 characters>
SECOND_LOCATION_PROBE_TIMEOUT=12
```

### Globalping

[Globalping](https://globalping.io) is a free public measurement network by jsDelivr. StatusConnect creates one measurement from one probe at `GLOBALPING_LOCATION` (a country code, city or any value Globalping's `magic` location field accepts) and polls it until it finishes or `GLOBALPING_TIMEOUT` runs out.

Globalping measurement results are public to anyone who has the measurement id, and its HTTP test only supports GET, HEAD and OPTIONS with TLS verification on. So only monitors that send a plain GET or HEAD, with no custom headers, no body and TLS verification on, are sent to Globalping. Other monitors rely on the probe.

Without a token Globalping allows 250 measurements per hour per IP address; a free token from https://dash.globalping.io/tokens raises that to 500 per hour.

```dotenv
GLOBALPING_TOKEN=
GLOBALPING_LOCATION=BR
GLOBALPING_TIMEOUT=10
```

Before using Globalping for a paid service, read its Terms of Use. They allow availability checks, but they also say you may not "reuse any portion of the Website, or Globalping without express written permission"; the contact for written permission is `legal@globalping.io`.

## Turning it on

```dotenv
SECOND_LOCATION_ENABLED=true
SECOND_LOCATION_PROVIDERS=probe,globalping
```

When it is on and at least one provider is set up, the landing page's "Limitations" paragraph says failures are confirmed from a second location.

## Limits inside the cron tick

The checker has no daemon, so confirmation runs inside the minute tick:

- `SECOND_LOCATION_MAX_PER_TICK` (default 10): at most this many confirmations per tick. Failures beyond it open without confirmation. When this server loses its network, every monitor fails at once; the cap keeps the tick from spending its whole budget on confirmations.
- `SECOND_LOCATION_RESERVE_SECONDS` (default 3): a confirmation only starts while the tick has more than this many seconds left, and its timeout is cut to fit.

## Privacy

With the feature on, the monitored URL or host and port go to the configured providers: the probe's host (for example Google Cloud) and, for plain GET/HEAD monitors, Globalping. The Privacy Policy at `/privacidade` lists the providers in use automatically while the feature is on; set `SECOND_LOCATION_PROBE_HOST_LABEL` (for example "Google Cloud, em São Paulo") to name the probe's host there.
