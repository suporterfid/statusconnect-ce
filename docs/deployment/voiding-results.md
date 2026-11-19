# Voiding false check results

Sometimes the checker records a failure that the target did not have. Since #237 a single unconfirmed failure no longer counts at all (see [`../guides/availability.md`](../guides/availability.md)); voiding is for false failures that were confirmed, or that you want marked explicitly. For example, on 2026-11-16 TCP checks timed out while the checker itself was busy (#231). Such results lower the availability shown on status pages even though nothing was down.

`monitor:void-results` lets a platform admin take them out of availability **without deleting them**:

- The result keeps its measured state, latency and reason. It also gains `voided_at` and `void_reason`, which the monitor's results API returns.
- Rollups (hour and day), the status page (history bars, uptime, downtime) and the dashboard's 24-hour uptime skip voided results.
- Each run writes one `check_results.voided` entry per monitor to `audit_logs`, with the reason, the window and the result ids.

## Usage

```bash
php artisan monitor:void-results \
  --monitor=25,27 \
  --from="2026-11-16 14:30:00" --to="2026-11-16 16:25:00" \
  --reason="Checker measured tcp_timeout while busy (#231)" \
  --dry-run
```

- **Times:** UTC, as stored in `check_results.checked_at`. The window is inclusive and spans at most 31 days.
- **What gets voided:** only results whose state is not `up` and that are not voided yet.
- **`--result=ID,ID`:** narrows the run to specific result ids inside the window.
- **Incidents:** a result inside an incident of the same monitor (between `started_at` and `resolved_at`) is refused. A confirmed incident is a separate statement, so close or correct it first. `--force` overrides this.
- **Dry run first:** always start with `--dry-run`, which prints the table of what would be voided. Then run again without it. The command then recomputes rollups and refreshes every status page snapshot.

## Limits

- **Retention window:** raw results are kept for `retention.check_results_days` (default 7) days. After that, only the daily rollup remains and a day can no longer be corrected. Void within that window.
- **No undo command:** to restore a result, set `voided_at` and `void_reason` back to `NULL` in the database, then run `php artisan monitor:rollup`.
- **Use it only for measurement errors.** Real outages, including short ones, stay on the record.
