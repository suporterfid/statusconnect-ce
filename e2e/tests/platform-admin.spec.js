// Platform administration of users (#248): an admin creates a user, who sets
// the password from the emailed link and signs in; the admin then blocks the
// user with the typed confirmation, and sign-in is refused.
const { test, expect } = require('@playwright/test');
const { text, mailpit } = require('./support');

const ADMIN_EMAIL = process.env.E2E_ADMIN_EMAIL;
const ADMIN_PASSWORD = process.env.E2E_ADMIN_PASSWORD;

const person = {
  name: 'Pessoa Convidada',
  email: `convidada.${Date.now()}@e2e.test`,
  password: 'senha-convidada-123',
};

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

test.describe.configure({ mode: 'serial' });

// Each identity gets its own browser context: the operator app keeps state beyond the cookies.
async function freshPage(browser) {
  const context = await browser.newContext({ baseURL: process.env.BASE_URL || 'http://app', locale: 'pt-BR' });
  return context.newPage();
}

test('a platform admin creates a user who chooses a password from the email', async ({ page, browser }) => {
  await signIn(page, ADMIN_EMAIL, ADMIN_PASSWORD);
  await page.goto('/app/administration');

  await page.getByRole('button', { name: text('admin.new_user') }).click();
  await page.locator('#new-user-name').fill(person.name);
  await page.locator('#new-user-email').fill(person.email);
  await page.getByRole('button', { name: text('admin.create_user') }).click();
  await expect(page.locator('tr', { hasText: person.email })).toBeVisible();

  const guest = await freshPage(browser);
  const link = await mailpit.findLink(person.email, /\/redefinir-senha\/[^\s"'<>]+/);
  await guest.goto(link);
  await guest.locator('#password').fill(person.password);
  await guest.locator('#password_confirmation').fill(person.password);
  await guest.locator('form button[type="submit"]').click();
  await expect(guest.locator('body')).not.toContainText(/inválid|invalid|expirad|expired/i);

  await signIn(guest, person.email, person.password);
  await expect(guest.getByText(person.email)).toBeVisible();
  await guest.context().close();
});

test('a blocked user cannot sign in', async ({ page, browser }) => {
  await signIn(page, ADMIN_EMAIL, ADMIN_PASSWORD);
  await page.goto('/app/administration');

  const row = page.locator('tr', { hasText: person.email });
  await row.getByRole('button', { name: text('admin.manage') }).click();
  const dialog = page.getByRole('dialog', { name: person.name });
  await expect(dialog.getByText(text('admin.methods.password'))).toBeVisible();
  await dialog.getByRole('button', { name: text('admin.block') }).click();

  // Typed confirmation: Confirm stays disabled until the email is typed.
  const confirm = page.getByRole('button', { name: 'Confirm' });
  await expect(confirm).toBeDisabled();
  await page.locator('#confirm-typed').fill(person.email);
  await confirm.click();
  await expect(dialog.getByRole('button', { name: text('admin.unblock') })).toBeVisible();

  const guest = await freshPage(browser);
  await guest.goto('/app/login');
  await guest.locator('input[type="email"]').fill(person.email);
  await guest.locator('input[type="password"]').fill(person.password);
  await guest.getByRole('button', { name: text('auth.login_btn') }).click();
  await expect(guest.locator('.alert-danger')).toContainText(/bloquead|blocked/i);
  await expect(guest).toHaveURL(/\/login$/);
  await guest.context().close();
});
