# Telegram notifications

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

StatusConnect can send incident alerts to a Telegram private chat or group. An installation has one bot. Operators link chats to an environment with a one-time link, so they never handle a bot token or a chat id. Design: [`docs/specs/telegram-notification-channel.md`](../specs/telegram-notification-channel.md), epic [#188](https://github.com/suporterfid/statusconnect/issues/188).

> Delivered in [#189](https://github.com/suporterfid/statusconnect/issues/189) (sending), [#190](https://github.com/suporterfid/statusconnect/issues/190) (linking API and webhook) and [#191](https://github.com/suporterfid/statusconnect/issues/191) (**Connect Telegram** in the operator app).

## Installation setup

1. In Telegram, talk to [@BotFather](https://t.me/BotFather), send `/newbot` and choose a name and a username ending in `bot`. BotFather answers with the token.
2. Set these in the installation's `.env`. Never commit them.

   | Variable | Value |
   |---|---|
   | `TELEGRAM_BOT_TOKEN` | The token from BotFather |
   | `TELEGRAM_BOT_USERNAME` | The bot's username, without `@` |
   | `TELEGRAM_WEBHOOK_SECRET` | A random string of 32 or more characters (for example `openssl rand -hex 32`) |

3. Clear the config cache if the installation caches it (`php artisan config:clear`).
4. Register the webhook. Telegram calls it when someone opens a link or removes the bot from a chat:

   ```bash
   php artisan telegram:webhook set    # needs an https APP_URL
   php artisan telegram:webhook info   # shows the URL (secret hidden), pending updates and the last error
   php artisan telegram:webhook delete
   ```

   The webhook is `POST /telegram/webhook/<secret>`. Telegram also sends the secret in the `X-Telegram-Bot-Api-Secret-Token` header, and a request without both is answered 404. It only receives updates, so it needs no process running.

Without `TELEGRAM_BOT_TOKEN` the installation offers no Telegram channel. Existing Telegram channels then fail each delivery with "Telegram is not configured on this installation.", and every other channel works as before.

## Linking a chat

In the operator app, open **Notification channels** and choose **Connect Telegram**. The button appears only for owners and admins, and only when the installation has a bot. The dialog has three ways to start:
- **Open in Telegram** links a private chat;
- **Add to a group** lets you pick a group;
- the QR code links a private chat from a phone.

When Telegram confirms, the dialog closes and the new channel shows up in the list. A link that expires before it is used can be replaced from the same dialog.

Through the API:

1. An owner or admin of the organization (or an API key with `secrets:manage`) calls `POST /v1/tenants/{tenantId}/environments/{environmentId}/notification-channels/telegram-links`. The answer has two links, both valid for 15 minutes and usable once:
   - `start_url` opens a private chat with the bot;
   - `startgroup_url` asks for a group, adds the bot and starts it there.
2. Opening a link sends `/start <code>` to the bot. StatusConnect creates a `telegram` channel in that environment for the chat, and the bot confirms in the chat.
3. `GET …/telegram-links/{linkId}` reports `pending`, `linked` (with the channel) or `expired`.

Linking a chat that is already linked to the environment enables the existing channel again instead of creating a second one. Removing the bot from a group, or blocking it in a private chat, disables the channels of that chat. A link opened after it expired, or a second time, creates nothing; the bot answers in the chat that the link is no longer valid.

## What is sent

Each message is short HTML, in the language and time zone of the environment's status page:

- **Incident opened:** monitor name, start time, severity, the failure summary and a link to the operator app.
- **Incident resolved:** monitor name, how long it was down, start and end times.
- **Test:** sent by the channel's **Test** button.

Messages are sent by the minute cron, like the other channels, with a 5-second timeout (`TELEGRAM_TIMEOUT_SECONDS`). A failure is recorded in the channel's delivery history with the Telegram error, such as a blocked bot, a chat not found or a rate limit. There is no automatic retry. When a group is upgraded to a supergroup, the channel follows the new chat id by itself.

The bot token is part of every Bot API URL. StatusConnect removes it from any error text before storing or returning it.
