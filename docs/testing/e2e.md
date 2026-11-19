# Browser tests (Playwright)

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

`stc e2e` runs the Playwright suite in `e2e/` against a real stack: Apache and PHP, MySQL, Mailpit and the `target` service. Everything runs in Docker, on Linux, macOS and Windows.

```bash
./scripts/stc.sh e2e            # Linux / macOS
.\scripts\stc.ps1 e2e           # Windows
./scripts/stc.sh e2e --headed   # extra arguments go to `playwright test`
```

It needs `vendor/`, so run `stc bootstrap` once first. The dev stack can keep running.

## What a run does

1. It starts its own stack under the compose project `statusconnect-e2e`, with `compose.yaml` plus `compose.e2e.yaml`. That stack has its own containers and database volume and publishes no host ports, so it never touches the dev stack or its data.
2. It resets the database with `migrate:fresh` and creates a platform admin (`admin@e2e.test`) with `statusconnect:provision-account`. The admin's temporary password goes to the suite.
3. It runs the suite in the `mcr.microsoft.com/playwright` image, pinned to the same version as `@playwright/test` in `e2e/package.json`.
4. It removes the stack and its volumes. With `E2E_KEEP=1`, it leaves the stack running and prints the command that removes it.

The report goes to `e2e/playwright-report/`. Traces and screenshots of failed tests go to `e2e/test-results/`. Open a trace with `npx playwright show-trace <trace.zip>` or at https://trace.playwright.dev.

## Settings of the test stack

`compose.e2e.yaml` sets these, overriding `.env`:

| Setting | Value | Why |
|---|---|---|
| `SIGNUP_MODE` | `approval` | Exercises sign-up with approval. |
| `LEGAL_REQUIRE_ACCEPTANCE` | `true` | Exercises the terms acceptance. |
| `CACHE_STORE` | `array` | Rate limits start empty on every run. |
| `SECOND_LOCATION_ENABLED` | `false` | Keeps the run off the external network. |
| `TELEGRAM_BOT_TOKEN`, `TELEGRAM_BOT_USERNAME`, `TELEGRAM_WEBHOOK_SECRET` | test values | Turn the Telegram channel on. |
| `TELEGRAM_API_BASE_URL` | `http://target:8080/telegram` | Sends Bot API calls to the fake Telegram. |

## Fake Telegram

`docker/target/fake-telegram.js` answers every Bot API call (`/telegram/bot<token>/<method>`) with `ok` and keeps the `sendMessage` calls, which a test reads at `GET /telegram/_messages`. The test plays Telegram's part itself: it posts the `/start` update to the app's webhook with the secret from `compose.e2e.yaml`.

## Coverage

`e2e/tests/telegram-link.spec.js` signs in as the platform admin and opens **Connect Telegram** on the notification channels page. It checks the two `t.me` links and the QR code. It then sends the group's `/start` to the webhook, waits for the dialog to close and the channel to appear, and sends a test notification through the fake Bot API.

`e2e/tests/platform-admin.spec.js` covers user administration. The platform admin creates a user in **Administration**. The user opens the set-password link from Mailpit, chooses a password and signs in. The admin then blocks the user, typing the email to confirm, and the user's sign-in is refused.

The helper `text()` in `e2e/tests/support.js` matches operator app strings in English or Portuguese from `frontend/src/i18n/`, so the tests do not depend on the user's locale.
