# LRMS — hosting files (upload branch)

This branch contains **only** the files that go on your cPanel hosting.
Nothing else — no Android source, no GitHub workflow, no build tooling.

**The root of this branch *is* your `public_html`.** Whatever you see here goes
straight inside `public_html` (or inside a subfolder like `public_html/lrms/` if
you want LRMS on a sub-path).

The Android app source, the workflow that builds the APK, and the long-form
deployment guide live on the `feat/lrms-initial-implementation` branch.

`deploy/public_html` is an older name for this same branch and is kept at the
same commit, so either one works. Prefer `hosting`.

---

## What is in here

| Path | Public? | What it is |
|---|---|---|
| `index.php` | yes | admin panel front controller — the only entry point |
| `api/v1/` | yes | REST API the Android app talks to |
| `assets/` | yes | CSS, JS, logo |
| `uploads/` | yes (images only) | visit photos, selfies, imported sheets |
| `.htaccess`, `.user.ini` | — | rewrite rules, security headers, PHP limits |
| `app/` | **403** | controllers, views, services |
| `lib/` | **403** | PDF, QR, SMTP, spreadsheet, crypto helpers |
| `config/` | **403** | your DB credentials and `app_key` |
| `cron/` | **403** | the scheduled job runner |
| `storage/` | **403** | logs, cache, DB backups |
| `database/` | **403** | `schema.sql` to import, `rollback.sql` to undo |

The `403` rows are blocked by `.htaccess`, so **upload them anyway** — PHP reads
them from disk, the browser cannot.

`config/config.php` is deliberately **not** in this branch (it holds secrets).
You create it in step 3 from `config/config.sample.php`.

---

## Getting the files onto the server (phone-friendly)

### Option A — ZIP from GitHub, extract in cPanel *(easiest, no PC needed)*

1. On GitHub, switch to branch **`hosting`** — or skip straight to the ZIP:
   `https://github.com/dhdhdh51/Bankmitra/archive/refs/heads/hosting.zip`
2. Green **Code** button → **Download ZIP**
3. cPanel → **File Manager** → open `public_html` → **Upload** → pick the ZIP
4. Back in `public_html`, select the ZIP → **Extract**
5. It extracts into a folder named `Bankmitra-hosting`.
   Open that folder → **Select All** → **Move** → target `/public_html`
6. Delete the now-empty folder and the ZIP
7. File Manager → **Settings** (top right) → tick **Show Hidden Files**, then
   confirm `.htaccess` and `.user.ini` are present in `public_html`

> Step 7 matters. Some extractors skip dotfiles. Without `.htaccess` you get
> 404s on every page **and** the protected folders become publicly readable.

### Option B — git clone over SSH *(if your plan has SSH)*

```bash
cd ~
git clone --depth 1 --branch hosting https://github.com/dhdhdh51/Bankmitra.git lrms-src
cd ~/public_html
cp -a ~/lrms-src/. .
rm -rf .git
```

Keeping `.git` is harmless (`.htaccess` blocks dotfiles) but pointless in
`public_html`, so remove it.

---

## Then follow the numbered setup

Do these in order. The full guide with troubleshooting is
`docs/DEPLOYMENT.md` on the `feat/lrms-initial-implementation` branch; the short
version is:

**1. Create the database** — cPanel → *MySQL Databases* → create a DB, create a
user, add the user to the DB with **All Privileges**. Note all three values.

**2. Upload** — Option A or B above.

**3. Configure** — generate a 64-hex-character key:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

No SSH? Create `public_html/key.php` containing
`<?php echo bin2hex(random_bytes(32));`, open it in your browser, copy the
output, then **delete `key.php`**.

Copy `config/config.sample.php` to `config/config.php` and fill in `db.host`,
`db.name`, `db.user`, `db.pass`, `app_key`, and `cron_key`. Leave
`base_url` as `'auto'`.

> **Save your `app_key` somewhere safe before importing any data.** Mobile
> numbers, Aadhaar-type fields and stored API keys are encrypted with it.
> Change it later and all of that becomes unreadable.

**4. Import the schema** — phpMyAdmin → your DB → **Import** →
`database/schema.sql`. You should end up with **28 tables**, 2 views, and seed
data (4 roles, 36 permissions, 54 settings, 1 admin). Re-importing is safe —
every statement uses `IF NOT EXISTS` / `INSERT IGNORE`.

Once imported you may delete `public_html/database/` if you prefer.

**5. Permissions** — `755` on directories, `644` on files, then:

```bash
chmod -R 755 uploads storage
chmod 600 config/config.php
```

**6. Turn on HTTPS** — cPanel → *SSL/TLS Status* → **Run AutoSSL**. Then edit
`.htaccess` and uncomment the *Force HTTPS* block in section 4.

**The Android app refuses plain HTTP**, so this step is required, not optional.

**7. First sign-in** — open `https://your-domain.com/`, sign in with
`ADMIN001` / `Admin@12345`. You are forced to change the password immediately.

**8. Fill in your keys** — **Settings → Integrations**: SMTP, SMS gateway,
Google Maps, Firebase, app version. Nothing is hardcoded anywhere in the source,
and changes take effect instantly — no re-upload. The dashboard shows a
**⚠ Missing Configuration** banner listing whatever is still blank, and every
integration has a **Send test** button that surfaces the real gateway error.

**9. Cron job** — cPanel → *Cron Jobs* → **Every 15 minutes**:

```
/usr/local/bin/php /home/CPANELUSER/public_html/cron/run.php all >/dev/null 2>&1
```

Replace `CPANELUSER`. If that PHP path is wrong, use the one cPanel shows for
your PHP version (e.g. `/opt/cpanel/ea-php82/root/usr/bin/php`).

**10. Install the app** — download the APK from GitHub → **Actions** → newest
*Build Android APK* run → **Artifacts**. On the login screen enter your server
URL (`https://your-domain.com`).

---

## Quick sanity checks

| Check | Expected |
|---|---|
| `https://your-domain.com/` | login page with logo |
| `https://your-domain.com/config/config.php` | **403 Forbidden** |
| `https://your-domain.com/app/bootstrap.php` | **403 Forbidden** |
| `https://your-domain.com/database/schema.sql` | **403 Forbidden** |
| `https://your-domain.com/api/v1/ping` | JSON starting `{"success":true,...}` |

If any of the `403` rows returns actual file content, `.htaccess` did not upload.
Fix that before putting real data in.

---

## If something breaks

Errors are written to `storage/logs/` (open via File Manager) and the panel shows
the real message rather than a blank page. Common ones:

| Symptom | Cause |
|---|---|
| 404 on every page except `/` | `.htaccess` missing, or `RewriteBase` needed for a subfolder install |
| 500 on every page | `php_value` lines in `.htaccess` on a CGI/FPM host — delete section 7 of `.htaccess`, use `.user.ini` |
| `Connection refused` on login | wrong DB credentials, or host needs `'unix_socket' => '/var/lib/mysql/mysql.sock'` |
| App says "cannot reach server" | HTTPS not enabled yet, or wrong URL entered in the app |
| Photos fail to upload | `uploads/` not `755`, or `upload_max_filesize` too low |
