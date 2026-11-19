// Telegram linking from the operator app (#191). The Telegram Bot API is the
// fake in docker/target/fake-telegram.js; the test plays Telegram's part by
// posting the /start update to the app's webhook, as Telegram would after
// someone opens the link.
const { test, expect } = require('@playwright/test');
const { text } = require('./support');

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL;
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD;
const BASE_URL = process.env.BASE_URL || 'http://app';
const TARGET_URL = process.env.TARGET_URL || 'http://target:8080';
const WEBHOOK_SECRET = process.env.TELEGRAM_WEBHOOK_SECRET;

async function signIn(page, email, password) {
  await page.goto('/app/login');
  await page.locator('input[type="email"]').fill(email);
  await page.locator('input[type="password"]').fill(password);
  await page.getByRole('button', { name: text('auth.login_btn') }).click();
  await page.waitForURL((url) => !url.pathname.endsWith('/login'));

  if (new URL(page.url()).pathname.endsWith('/terms')) {
    await page.getByRole('checkbox').check();
    await page.getByRole('button', { name: text('terms.accept') }).click();
    await page.waitForURL((url) => !url.pathname.endsWith('/terms'));
  }
}

async function telegramMessages() {
  const res = await fetch(`${TARGET_URL}/telegram/_messages`);
  return (await res.json()).messages;
}

test('an owner links a Telegram group from the notification channels page', async ({ page }) => {
  expect(WEBHOOK_SECRET, 'TELEGRAM_WEBHOOK_SECRET is set by compose.e2e.yaml').toBeTruthy();
  await signIn(page, ADMIN_EMAIL, ADMIN_PASSWORD);

  await page.goto('/app/notification-channels');
  await page.getByRole('button', { name: text('notifications.connect_telegram') }).click();

  const dialog = page.getByRole('dialog');
  await expect(dialog.getByRole('heading', { name: text('notifications.telegram_title') })).toBeVisible();
  const startLink = dialog.locator('[data-telegram-start]');
  await expect(startLink).toHaveAttribute('href', /^https:\/\/t\.me\/StatusConnectE2EBot\?start=[A-Za-z0-9_-]{22}$/);
  await expect(dialog.locator('[data-telegram-group]')).toHaveAttribute('href', /\?startgroup=[A-Za-z0-9_-]{22}$/);
  await expect(dialog.locator('.qr svg')).toBeVisible();
  const code = new URL(await startLink.getAttribute('href')).searchParams.get('start');

  // Telegram delivers the /start of the group to the webhook.
  const chatId = -1000000000000 - Math.floor(Math.random() * 1_000_000);
  const webhook = await fetch(`${BASE_URL}/telegram/webhook/${WEBHOOK_SECRET}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Telegram-Bot-Api-Secret-Token': WEBHOOK_SECRET },
    body: JSON.stringify({
      update_id: 1,
      message: {
        message_id: 1,
        from: { id: 7, is_bot: false, first_name: 'Ana', language_code: 'pt-br' },
        chat: { id: chatId, type: 'supergroup', title: 'Plantão E2E' },
        date: Math.floor(Date.now() / 1000),
        text: `/start@StatusConnectE2EBot ${code}`,
      },
    }),
  });
  expect(webhook.status).toBe(200);

  // The dialog sees the link used, closes, and the channel is listed.
  await expect(dialog).toBeHidden();
  const row = page.locator('tr', { hasText: 'Telegram — Plantão E2E' });
  await expect(row).toBeVisible();
  await expect(row).toContainText('telegram');

  await expect.poll(async () => (await telegramMessages()).some((m) => m.chat_id === chatId && /Conectado|Connected/.test(m.text))).toBe(true);

  // The channel's test button sends through the bot.
  await row.getByRole('button', { name: text('notifications.send_test') }).click();
  await expect.poll(async () => (await telegramMessages()).some((m) => m.chat_id === chatId && /StatusConnect/.test(m.text) && /Production/.test(m.text))).toBe(true);
});
