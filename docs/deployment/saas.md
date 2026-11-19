# Running StatusConnect for many organizations

What changes when an installation is open to other people: public sign-up, status page subscriptions, administration and the legal pages. A private installation needs none of it: with the defaults there is no sign-up page and organizations have no limits.

## Sign-up

`SIGNUP_MODE`:

- `closed` (default): no `/cadastro` page; the landing page's sign-up buttons lead to the login.
- `approval`: anyone can sign up; the email must be confirmed and a platform admin approves the organization in **Administration → Organizations**. Platform admins get an email for each sign-up; the owner gets one when approved.
- `open`: the organization is usable once the email is confirmed.

Sign-up needs working SMTP (`MAIL_*`). Disposable email domains are refused (`SIGNUP_BLOCKED_DOMAINS` adds more), the form has a hidden honeypot field, sign-up posts are rate limited per IP, and the login refuses unconfirmed emails.

**Suspension.** In **Administration → Organizations**, **Suspend** blocks an organization: its members cannot sign in or reach it, open sessions end (database sessions), and its monitors are not checked. Nothing is deleted. **Approve** lifts it.

The login refuses unconfirmed emails and organizations awaiting approval with a message that says so. `/esqueci-senha` sends a password reset link (valid 60 minutes).

## Status page subscriptions

Each status page can let visitors subscribe by email (**Status Page → Let visitors subscribe by email**, off by default; private pages never offer it).

- Double opt-in: the form sends a confirmation link (valid `STATUS_PAGE_SUBSCRIBER_CONFIRMATION_HOURS`, 48 by default); confirming takes a button press, so mail scanners that open links do not confirm. Unconfirmed subscriptions are deleted when the link expires.
- The form answers the same way for every address, and no API returns subscriber addresses; operators see only the count.
- Subscribers get an email when an incident on one of the page's public monitors starts or ends, and when an update is published. Turning subscriptions on never mails earlier events.
- `monitor:maintenance` sends at most `STATUS_PAGE_SUBSCRIBER_MAIL_PER_MINUTE` emails per run (20 by default) across all pages and resumes where it stopped. Keep it under the SMTP provider's sending limit.
- Each email has an unsubscribe link and the `List-Unsubscribe` / `List-Unsubscribe-Post` headers for one-click unsubscribe.
- Abuse limits: 5 subscribe requests per IP every 10 minutes, `STATUS_PAGE_SUBSCRIBER_NEW_PER_HOUR` new subscriptions per page per hour, `STATUS_PAGE_SUBSCRIBER_MAX_PER_PAGE` in total.

The `maintenance` heartbeat carries `notifications_created`, `subscriber_emails_sent` and `unconfirmed_purged`.

## Administration

Platform admins manage every user and organization in **Administration** (`/app/administration`); the API is under `/v1/platform/…` and documented in the API reference. Every action below is in the audit log, and the writes share a limit of 30 per minute per admin. Nothing here deletes a user or an organization.

**Users** (**Manage** on a row):

- **New user**: name, email and, optionally, an organization and role. The person gets an email with a link to choose a password (the forgot-password link, valid for `auth.passwords.users.expire` minutes; after that **Esqueci minha senha** sends a new one). Choosing it confirms the address.
- **Name and email.** A new email must be unique, starts unconfirmed with a confirmation link (no sign-in until it is opened) and unlinks Sign in with Google. Admins cannot change their own email here.
- **Block** (typed confirmation): the person cannot sign in by password, Google or GrandpaSSOn, every open session ends and Sanctum personal tokens are revoked. API keys (`sc_…`) belong to an environment and keep working; revoke them under **API keys** if needed. Blocking is per person, separate from suspending an organization. Admins cannot block themselves, and the last active platform admin cannot be blocked or demoted.
- **End all sessions** signs the person out of every browser, also with `SESSION_DRIVER=file`: each session remembers `users.session_version` from its sign-in, and a session older than the current version is refused on its next request. "Remember me" cookies stop working too. The person can sign in again.
- The detail shows organizations and roles, created, last sign-in (`users.last_login_at`, any method) and email confirmation dates, the sign-in methods (password, Google, GrandpaSSOn) and whether the person is a platform admin or blocked.

**Organizations** (**Manage** on a row):

- **Name and address.** The slug follows the creation rule. Changing it (typed confirmation) moves the status pages whose address was the organization slug; the old `/status/{slug}` addresses, including `.json`, history, feed and component pages, answer **301** to the new one (`status_page_slug_redirects`). The same happens when an owner changes the status page address on the **Status page** screen. A page that later takes an old slug wins over the redirect.
- **Members**: add an existing or new user by email, change roles, remove, and **Make owner**, which makes that member the only owner and the other owners admins. An organization never ends without an owner.
- The detail shows members, monitors by kind (running and paused), status pages and their addresses, plan, approval or suspension, sign-up date and the last 20 audit entries.

## Public site

### Terms acceptance

With `LEGAL_REQUIRE_ACCEPTANCE=true`, every signed-in user must accept the current Terms of Use and Privacy Policy before using the operator app or the API (API keys are not asked). The version is `LEGAL_ACCEPTANCE_VERSION`, or `LEGAL_UPDATED_AT` when it is empty: change it when the texts change and everyone is asked again at their next request. For a change that does not need everyone to accept again (a new optional feature described, a translation), move `LEGAL_UPDATED_AT` and set `LEGAL_ACCEPTANCE_VERSION` to the previous date. New acceptances still record the exact new text and its hash.

Each acceptance is kept in `terms_acceptances`: user, email (kept if the user is deleted), version, method (`signup` for the sign-up checkbox, `prompt` for the in-app page), IP address, browser and time. The exact text accepted is kept once per version in `legal_document_versions`, with its SHA-256. Platform admins see each user's last accepted version in **Administration → Users**.

Accepted versions of a user, over SSH:

```bash
php artisan tinker --execute='echo json_encode(DB::table("terms_acceptances")->where("email","person@example.com")->get());'
```

`/` is a plain home page, `/en` its English version, `/termos` and `/privacidade` the legal pages, `/terms` and `/privacy` their English translations (the Portuguese texts prevail, and only they define the accepted version). The `LEGAL_*` variables in `.env.example` fill in the operator's legal name, CNPJ, address, contact email and venue. The texts in `resources/legal/` are templates: have a lawyer review them before opening the service.
