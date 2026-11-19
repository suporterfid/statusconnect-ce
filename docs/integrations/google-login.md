# Sign in with Google

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

People can sign in with a Google account, either Gmail or Google Workspace, instead of a password. It uses OpenID Connect with the authorization code flow and only plain HTTPS calls, so it runs on shared hosting. Delivered in [#244](https://github.com/suporterfid/statusconnect/issues/244).

It is off by default. Each step below can be done on its own, in this order.

## How it works

- **Continue with Google** appears on the login page (`/app/login`) and the sign-up page (`/cadastro`) only while it is on.
- `GET /auth/google/redirect` sends the browser to Google with `state`, `nonce`, PKCE (`S256`), `prompt=select_account` and the scopes `openid email profile` only.
- `GET /auth/google/callback` checks the state, exchanges the code at `https://oauth2.googleapis.com/token`, and verifies the ID token's RS256 signature against Google's keys (`https://www.googleapis.com/oauth2/v3/certs`, cached in the application cache), plus `iss`, `aud`, `azp`, `exp` and `nonce`.
- The identity is accepted only when Google says `email_verified` is true.
- **Linking.** The user already linked to that Google account (`users.google_sub`) signs in. Otherwise the user with the same email is linked and signed in. Members added to an organization sign in this way too. A user already linked to another Google account is refused.
- **New addresses** follow the sign-up rules from [`../deployment/saas.md`](../deployment/saas.md#sign-up):
  - with `SIGNUP_MODE=closed` they are refused;
  - otherwise `/cadastro/google` asks for the organization name and acceptance of the terms, under the same abuse limits (disposable domains, per IP, per domain);
  - no confirmation email is sent, because Google verified the address;
  - in `approval` mode the organization still waits for a platform admin.
- Organizations awaiting approval or suspended stay blocked.
- An unconfirmed password sign-up for the same address is linked, and its password is replaced, so whoever typed that password without proving the mailbox loses access.
- Accounts created with Google get a random password. **Forgot password** sets a real one.
- Only the name, email and Google account id are used. The profile picture and the access token are not stored.
- Refusals return to the login page with `?google_error=` and one of `failed`, `cancelled`, `email_unverified`, `no_account`, `pending` or `mismatch`. The log has the detail (`google_login.refused`).

## Settings

| Variable | Value |
|---|---|
| `GOOGLE_CLIENT_ID` | The OAuth client id (`….apps.googleusercontent.com`). When set, the privacy policy and the terms describe Google sign-in, even while the login is off |
| `GOOGLE_CLIENT_SECRET` | The OAuth client secret. Never commit it |
| `GOOGLE_LOGIN_ENABLED` | `true` shows the button and opens the routes. Default `false`; while it is off, every `/auth/google/*` route answers 404 |
| `GOOGLE_REDIRECT_URI` | Optional. Empty uses `APP_URL/auth/google/callback`. It must match the redirect URI registered in Google exactly |

Clear the config cache after changing them (`php artisan config:clear`) if the installation caches it.

## Google Cloud setup

Do this once per installation with a Google account that will own the project. The examples use `app.statusconnect.net`; replace them with your `APP_URL`.

1. **Verify the domain.** In [Google Search Console](https://search.google.com/search-console), add the domain property `statusconnect.net` and verify it with the DNS TXT record. Use the same Google account as the Cloud project, or add that account as an owner. Google requires a verified domain before it accepts the consent screen's links.
2. **Create a project.** In the [Google Cloud console](https://console.cloud.google.com/), create a project (for example `statusconnect-login`). No API needs to be enabled for sign-in.
3. **Branding (OAuth consent screen).** Open **Google Auth Platform → Branding** (older consoles: **APIs & Services → OAuth consent screen**) and fill in:

   | Field | Value |
   |---|---|
   | App name | `StatusConnect` |
   | User support email | a mailbox people can write to, e.g. `suporte@statusconnect.net` |
   | App logo | optional. A logo makes Google review the brand, which takes longer. It can be added later |
   | Application home page | `https://app.statusconnect.net/` |
   | Application privacy policy link | `https://app.statusconnect.net/privacy` (English; `/privacidade` is the Portuguese version) |
   | Application terms of service link | `https://app.statusconnect.net/terms` (English; `/termos` is the Portuguese version) |
   | Authorized domains | `statusconnect.net` |
   | Developer contact information | the email that should receive Google's notices |

4. **Audience.** Under **Audience**, choose **External**, so that any Gmail or Workspace account can sign in. **Internal** would allow only accounts of the project's own Workspace organization.
5. **Data access (scopes).** Under **Data Access**, add only `openid`, `.../auth/userinfo.email` and `.../auth/userinfo.profile`. These are non-sensitive scopes, so Google does not ask for a security assessment.
6. **Create the OAuth client.** Under **Clients** (older consoles: **Credentials → Create credentials → OAuth client ID**):
   - **Application type:** Web application;
   - **Name:** `StatusConnect web`;
   - **Authorized JavaScript origins:** none needed;
   - **Authorized redirect URIs:** `https://app.statusconnect.net/auth/google/callback`.

   Copy the client id and secret.
7. **Show the legal sections.** Set `GOOGLE_CLIENT_ID` in production with `GOOGLE_LOGIN_ENABLED=false`, and deploy. Check that `/privacy` and `/privacidade` now have the **Sign in with Google** / **Login com Google** section, with the Limited Use statement, and that `/terms` and `/termos` mention third-party accounts.
8. **Publish the app.** Under **Audience**, choose **Publish app** to move from *Testing* to *In production*. While in Testing, only the test users listed there can sign in. With only non-sensitive scopes, Google may still verify the brand (name, logo, domain, privacy policy) before it shows the app name on the consent screen. Answer any email from the Trust & Safety team.
9. **Turn it on.** Set `GOOGLE_CLIENT_SECRET` and `GOOGLE_LOGIN_ENABLED=true`, then test with a Gmail account and a Workspace account. Test an existing user, so the account gets linked, and, if sign-up is open, a new address.

## Google Workspace accounts

Workspace administrators can restrict which third-party apps their users may sign in to (**Admin console → Security → Access and data control → API controls → Manage third-party app access**). When a Workspace user sees "This app is blocked" or "Access blocked: your organization's policy", their administrator must allow StatusConnect by its OAuth client id, or mark it as trusted. StatusConnect cannot change this. Such a user can still sign in with email and password.

## Unlinking

The privacy policy offers unlinking through the contact email (`LEGAL_CONTACT_EMAIL`). A platform admin clears the link over SSH:

```bash
php artisan tinker --execute='App\Infrastructure\Persistence\Eloquent\User::where("email","person@example.com")->update(["google_sub" => null]);'
```

The next Google sign-in with the same verified address links the account again. Deleting the user removes the link.
