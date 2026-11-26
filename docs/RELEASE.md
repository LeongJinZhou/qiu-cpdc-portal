# RELEASE: how to put the portal online

Two places: **Render** (test copy, from GitHub, automatic) and **Exabytes** (production, cPanel upload).
Secrets are typed into Render or the server's `.env` only. Never into GitHub, never into chat.

## 1. Sign-in providers (once)

### Google (students and staff)
Google Cloud Console, the OAuth client of type "Web application" (the founder's existing QIU client can be reused):
- Authorised redirect URIs: `https://<host>/api/auth/google/callback` for every host (Render and Exabytes).
- OAuth consent screen: Internal (QIU Workspace) is best. Scopes: openid, email, profile,
  `.../auth/user.organization.read`, `.../auth/directory.readonly` (same as sld-orientation).
- People API must be enabled in that project.

### LinkedIn (link, and alumni sign-in)
LinkedIn Developer portal, your app:
- Products: **Sign In with LinkedIn using OpenID Connect** (no other product needed).
- Auth, Authorized redirect URLs: `https://<host>/api/auth/linkedin/callback` for every host.
- The old portal's `/api/onboarding/oauth/linkedin/callback` URL is no longer used.

### GitHub (optional)
GitHub, Settings, Developer settings, OAuth Apps: callback `https://<host>/api/auth/github/callback`.

## 2. Database (MySQL 8)
- Render has no MySQL. Use an external MySQL with TLS, e.g. the Aiven free plan (as sld-orientation): create a
  database `qiu_cpdc` and a user; download the CA certificate.
- Exabytes: cPanel, MySQL Databases: create database and user, give the user ALL on that database. Exabytes
  Malaysia runs MariaDB 10.11; CI runs every test on MariaDB 10.11 and MySQL 8.4, so both work.
- Tables are created by `php api/bin/migrate.php` (Render runs it at every start).

## 3. Render test copy, run like Exabytes
Render runs the same upload as Exabytes (`npm run package:exabytes`), in the same layout: `/home/cpdc/cpdc` (the
app, above public_html) and `/home/cpdc/public_html` (web app, `.htaccess`, `index.php`). Routing and security
headers come from `public_html/.htaccess`, as on cPanel. CI starts this image on MariaDB 10.11 and checks it.

Live: Blueprint `qiu-cpdc` (repo `qiu-cpdc-portal`, branch `main`), service https://qiu-cpdc-portal.onrender.com.
Every push to `main` deploys.
1. Render, New, Blueprint, pick `qiu-cpdc-portal` (uses `render.yaml`, Docker). A Blueprint refuses empty values.
2. Fill every value marked "sync: false" (`APP_URL` = the service address, `SUPERADMIN_EMAIL`, Google, LinkedIn,
   `DB_*`). Secret Files: `db-ca.pem` = the database CA certificate. Without it the start log says the file is
   missing and the service never goes live.
3. Deploy. Check `https://<service>.onrender.com/api/health` says `{"status":"ok"}`.
4. Sign in with `ai@qiu.edu.my`: you are super admin.

## 4. Exabytes (cPanel shared hosting, production)
Needs PHP 8.3 (cPanel, Select PHP Version: 8.3 with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `fileinfo`,
`zlib`, `json`, `ctype`; MultiPHP INI Editor: `upload_max_filesize` 6M, `post_max_size` 7M, `memory_limit` 256M).
1. On a laptop: `npm ci && npm run build`, `composer install --no-dev --optimize-autoloader` in `api/`, then
   `npm run package:exabytes`. It makes `dist/exabytes/` with two folders.
2. Upload (File Manager zip upload, or SFTP): `dist/exabytes/cpdc/` to `/home/<user>/cpdc/` (NOT inside
   public_html), and the contents of `dist/exabytes/public_html/` (with `.htaccess`) into `public_html/`.
   `storage/` mode 700.
3. Nothing else to point: `public_html/index.php` hands `/api/*` to `~/cpdc`, exactly as on Render.
4. `.env` in `/home/<user>/cpdc/` (chmod 600): copy `.env.example`, set `APP_ENV=production`, `APP_URL=https://<domain>`,
   `STORAGE_DIR=/home/<user>/cpdc/storage`, the database, Google, LinkedIn values and the production bases
   (`https://accounts.google.com`, `https://people.googleapis.com`, `https://www.linkedin.com`,
   `https://api.linkedin.com`). Leave `DB_SSL_CA` empty for a database on the same server.
5. cPanel Terminal (or a one-off cron): `php /home/<user>/cpdc/api/bin/migrate.php`.
6. Force HTTPS (cPanel, Domains, Force HTTPS Redirect). The session cookie only works over HTTPS.

## 5. After each release
- `/api/health` answers ok; sign in once with Google and once with LinkedIn (an alumni account).
- Never run `api/bin/seed.php` on Render or Exabytes: it deletes users (it refuses when APP_ENV=production).
