# Custom domains for status pages

> *This project is part of the [IROH Initiative](https://github.com/suporterfid/iroh). See the main hub for manifesto, branding, and ecosystem index.*

A status page normally lives at `https://<app-host>/status/<slug>`. With a custom domain the same page is also served at the tenant's own hostname, for example `https://status.example.com`.

## How it works

- The page's **Custom domain** field (operator SPA, Status page screen, or `PUT /v1/tenants/{t}/environments/{e}/status-page` with `custom_domain`) stores a lowercase hostname. The app's own host and its subdomains are refused, and a domain can belong to only one page.
- `App\Http\Middleware\ServeCustomStatusDomain` runs before routing. When the request host matches a page's custom domain it maps:

  | Custom domain path | Served as |
  |---|---|
  | `/` | `/status/<slug>` |
  | `/history`, `/calendar`, `/feed`, `/logo` | `/status/<slug>/…` |
  | `/components/<id>` | `/status/<slug>/components/<id>` |
  | `/status.json` | `/status/<slug>.json` |

  Any other path, and any non-GET request, answers 404 on that host, so the API, login and `/ping` are never reachable through a tenant's domain. Static files under `public/` (for example the built SPA shell at `/app/`) are served by the web server before Laravel runs, so they still load on a parked domain, but every API call they make answers 404 there. Visibility rules are unchanged: a private page is not served to visitors.
- Links on the page are root-relative, so they stay on the custom domain.

## Activating a domain (manual, per tenant)

Shared hosting cannot provision domains or certificates from the application, so each domain needs these steps:

1. **Tenant DNS**: create a `CNAME` record for the hostname pointing at the app host (for example `status.example.com CNAME app.statusconnect.net`). If the DNS provider cannot use a CNAME at that name, use an `A` record with the hosting server's IP.
2. **Hosting (Hostinger)**: add the hostname as a **parked (alias) domain** of the app's website, so it is served from the same document root. In hPanel: Websites > the app's website > Domains > Parked domains. The Hostinger API equivalent is `hosting_websites_add-domain` with `type: parked`. Hostinger only accepts a parked domain that the account owns or that already points at the website, so do this after step 1 has propagated.
3. **SSL**: in hPanel, Security > SSL, install the free certificate for the parked domain. Until it is issued, browsers will warn on `https://`.
4. **StatusConnect**: set the hostname in the page's Custom domain field (an owner/admin of the tenant can do this at any time; the page is served there as soon as steps 1–3 are done).

To remove a domain, clear the field, then remove the parked domain in hPanel.
