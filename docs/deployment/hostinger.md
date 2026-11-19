# Shared hosting deployment guide (Hostinger and similar)

StatusConnect runs on ordinary shared hosting: PHP 8.2+, MySQL 8.0+, a one-minute cron and a document root you can point at `public/`. This guide uses Hostinger's hPanel names; cPanel hosts have the same pieces under other names.

You build the release on your own machine with Docker (no PHP, Composer or Node on your machine) and upload it.

## 1. Build a release locally

From a checkout of a release tag:

```bash
./scripts/stc.sh composer install --no-dev --optimize-autoloader
# Only if you changed the frontend: the release already contains public/app/.
./scripts/stc.sh npm --prefix frontend ci
./scripts/stc.sh npm run build
```

Upload everything except `.git/`, `node_modules/`, `frontend/node_modules/`, `tests/`, `e2e/`, `docker/` and your local `.env`. `vendor/` must be uploaded.

## 2. One-time hosting setup

1. **PHP version**: hPanel > Advanced > PHP Configuration: PHP 8.2 or later, with `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo` and `json` enabled.
2. **MySQL database**: create one in hPanel > Databases. Hostinger prefixes the database and user names with the account user (`uXXXXXXXXX_statusconnect`). From the app, connect to `localhost`.
3. **SSL**: hPanel > Security > SSL > activate Let's Encrypt for your domain.
4. **Document root**: Laravel's entry point is `public/`, not the project root. Point the domain's document root at the `public/` folder of the upload (for example `public_html/public`). If your plan cannot change the document root, upload the project outside `public_html` and make `public_html` hold the contents of `public/`, with `index.php` adjusted to the project path.
5. **Cron jobs**: see [cron.md](cron.md).

## 3. Configure and migrate

Over SSH (hPanel > Advanced > SSH Access), in the project folder:

```bash
cp .env.example .env
# edit .env: APP_ENV=production, APP_DEBUG=false, APP_URL, DB_*, MAIL_*, SETUP_ALLOWED_EMAILS
php artisan key:generate --force
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Use the full path of the PHP binary hPanel serves the site with when the plain `php` is older (for example `/opt/alt/php83/usr/bin/php`, see [cron.md](cron.md)).

`php artisan storage:link` is not needed: it calls `exec()`, which shared hosts often disable, and nothing uses the `public` disk. Status page logos live on the private `local` disk and are served by `/status/{slug}/logo`.

To upgrade, upload the new release over the old one (keep `.env` and `storage/`), then run `migrate --force` and the three cache commands again.

## 4. File and folder permissions

`storage/` and `bootstrap/cache/` must be writable by PHP: `0775`. Elsewhere, directories `0755` and files `0644`.

## 5. Creating accounts

- **First account on a fresh install:** set `SETUP_ALLOWED_EMAILS` in `.env` to the address that will own the install, then open `/app/setup`. Setup works only while the database has no users, and in production it is refused while `SETUP_ALLOWED_EMAILS` is empty.
- **Day to day:** organization owners and admins manage people under **Members** (`/app/members`); platform admins create organizations, reset passwords and grant platform admin under **Administration** (`/app/administration`).
- **From the command line**, over SSH. The command is idempotent and prints a one-time temporary password for new users, who change it under Settings:

```bash
php artisan statusconnect:provision-account \
  --email=user@example.com --name="User Name" --org="Acme" --slug=acme --role=owner --locale=pt-BR --timezone=America/Sao_Paulo
```

`--slug` is both the organization slug and the public status page address (`/status/acme`). Run it again with another `--email` and the same `--slug` to add a member (`--role=owner|admin|viewer`).
