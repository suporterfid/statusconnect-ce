# How availability is counted

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

Every check stores one result (`check_results`) with its measured state: `up`, `degraded`, `down`, or a non-counting state such as `blocked`, `skipped` or `no_data`. The minute cron aggregates results into hourly and daily rollups (`check_rollups`). The status page and the dashboard read those rollups, plus the raw results of the current day.

## Only confirmed failures count

Since [#237](https://github.com/suporterfid/statusconnect/issues/237), a failure counts toward availability, downtime and the day color only when it was **confirmed**. A failure is confirmed when its streak of consecutive `down`/`degraded` results reached the monitor's **confirmation threshold**, the same rule that opens an incident.

| Situation | Counts as |
|---|---|
| One failed check, then `up` again before the threshold | `up` (kept as evidence) |
| A streak still below the threshold (in progress) | `up` until it is confirmed |
| A streak that reaches the threshold | `down`/`degraded` for **all** its results, from the first failure |
| A failure while the monitor is already `down`/`degraded` | `down`/`degraded` |
| A voided result (see [`../deployment/voiding-results.md`](../deployment/voiding-results.md)) | nothing |

How it works:

- Each failed result records `failure_confirmed` (`false` or `true`), which the monitor's results API also returns.
- When a streak is confirmed, its earlier results are marked `true` in the same transaction. The cron then rewrites the affected hourly and daily rollups, so a streak that crosses an hour or a day boundary counts in both buckets.
- An unconfirmed failure keeps its measured state, reason and latency in the result history. Its latency still counts in the response-time charts.
- Results recorded before #237 were backfilled from incident windows: a failure inside an automatic incident counts, any other failure does not. Results already rolled up and deleted (older than `retention.check_results_days`) keep their old counts.
- `degraded` checks count as available in the uptime percentage, as before. A confirmed degraded streak still colors the day as degraded.

To go back to counting every failed check, set `STATUS_COUNT_UNCONFIRMED_FAILURES=true` in `.env`.
