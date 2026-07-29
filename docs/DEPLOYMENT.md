# LRMS — cPanel Deployment Guide

Step-by-step, copy-paste. No SSH is required for any step, but SSH commands are
given where they are faster.

**Requirements on the host**

| Item | Minimum | Notes |
|---|---|---|
| PHP | 8.0 (8.1 or 8.2 recommended) | set in cPanel → **Select PHP Version** |
| PHP extensions | `pdo_mysql`, `openssl`, `mbstring`, `gd`, `zip`, `json` | `curl` recommended, `exif` optional |
| MySQL / MariaDB | MySQL 5.7+ or MariaDB 10.4+ | tested against MariaDB 10.5 |
| Disk | ~200 MB + photo storage | budget ~200 KB per visit photo |
| SSL | free cPanel AutoSSL | **required** — the Android app refuses plain HTTP |

There is **no Composer step and no `npm install`**. Everything is plain PHP.

---

## 1. Create the database

cPanel → **MySQL® Databases**

1. **Create New Database**: `lrms` → becomes something like `cpuser_lrms`
2. **Add New User**: e.g. `lrmsapp` with a long generated password → `cpuser_lrmsapp`
3. **Add User To Database** → tick **ALL PRIVILEGES**

Write down all three values; you need them in step 3.

---

## 2. Upload the files

The `backend/` folder in this repository **is** the web root. Upload its
*contents* (not the folder itself).

* To serve LRMS at `https://your-domain.com/` → upload into `public_html/`
* To serve it at `https://your-domain.com/lrms/` → upload into `public_html/lrms/`

**Easiest route (cPanel File Manager, works from a phone):**

1. Download this repository as a ZIP from GitHub (**Code → Download ZIP**)
2. cPanel → **File Manager** → open `public_html` → **Upload** the ZIP
3. Select the ZIP → **Extract**
4. Open the extracted `Bankmitra-main/backend` folder → **Select All** → **Move**
   → destination `/public_html` (or `/public_html/lrms`)
5. Delete the leftover `Bankmitra-main` folder and the ZIP

**Or with SSH:**

```bash
cd ~
git clone https://github.com/dhdhdh51/Bankmitra.git lrms-src
cp -a lrms-src/backend/. public_html/
# hidden files matter - confirm they arrived:
ls -la public_html/.htaccess public_html/.user.ini
```

After this, `public_html` should contain:

```
index.php  .htaccess  .user.ini  dev-server.php
api/  app/  assets/  config/  cron/  lib/  storage/  uploads/
```

---

## 3. Configure

Generate the encryption key. Any one of these works:

```bash
# SSH
php -r "echo bin2hex(random_bytes(32));"
```

No SSH? cPanel → **Terminal**, or temporarily create `public_html/key.php` with
`<?php echo bin2hex(random_bytes(32));`, open it in a browser, copy the value,
then **delete the file**.

Now create the config:

```bash
cd ~/public_html
cp config/config.sample.php config/config.php
```

Edit `config/config.php` in File Manager (**Edit**, not *Code Edit*, if the
editor misbehaves) and set:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'cpuser_lrms',        // from step 1
    'user' => 'cpuser_lrmsapp',
    'pass' => 'the-password-you-made',
],
'app_key'  => 'paste-the-64-hex-characters-here',
'base_url' => 'auto',               // leave as 'auto'
'env'      => 'production',
'cron_key' => 'another-random-string-for-cron',
```

> **`app_key` warning.** Mobile numbers, Aadhaar and API keys are encrypted with
> it. If you change it later, everything already encrypted becomes unreadable.
> Save a copy somewhere safe **before** you import any data.

Getting `Connection refused` with `host = localhost`? A few hosts only expose a
unix socket. Ask support for the path and set `'unix_socket' => '/var/lib/mysql/mysql.sock'`.

---

## 4. Import the schema

cPanel → **phpMyAdmin** → select your database → **Import** tab →
**Choose File** → `database/schema.sql` → **Import**.

Expected result: **28 tables**, plus 2 views, plus seed data
(4 roles, 34 permissions, 54 settings, 1 admin user).

The file is safe to re-import — every statement uses `IF NOT EXISTS` /
`INSERT IGNORE`, so nothing is destroyed.

Via SSH instead:

```bash
mysql -u cpuser_lrmsapp -p cpuser_lrms < ~/lrms-src/database/schema.sql
```

To wipe everything and start over, import `database/rollback.sql` first.
**That deletes all data.**

---

## 5. Set permissions

cPanel File Manager → select folder → **Permissions**, or via SSH:

```bash
cd ~/public_html
find . -type d -exec chmod 755 {} \;
find . -type f -exec chmod 644 {} \;

# these must be writable by PHP
chmod -R 755 uploads storage
chmod 600 config/config.php     # only PHP needs to read it
```

Quick check: **755** on `uploads/` and `storage/`, **600** or **644** on
`config/config.php`.

---

## 6. First sign-in

Open `https://your-domain.com/` (or `/lrms/`).

| | |
|---|---|
| Sign in with | `ADMIN001` |
| Password | `Admin@12345` |

You are **forced** to set a new password immediately. Do it.

Then go to **Settings → Integrations** and fill in what you need. The dashboard
shows a **⚠ Missing Configuration** banner listing anything still empty.
Nothing is hardcoded in the source and nothing needs re-uploading after a change.

| Settings group | Fill in | Needed for |
|---|---|---|
| Organisation | bank name, support contacts | panel + PDF headers |
| Email (SMTP) | host, port, encryption, username, password, from address | email OTP |
| SMS Gateway | API URL template, API key, sender ID, DLT template | mobile OTP, reminders |
| Google Maps | API key | maps in the panel and app |
| Firebase (Push) | service-account JSON, project id | optional push |
| Mobile App | version, min version, force update, maintenance mode, APK URL | app gating |
| Invitations | expiry, default role, approval | new user onboarding |
| Security | OTP length/expiry, lockout, session timeout, device binding, retention | policy |

SMTP and SMS have **Send test** buttons that show the real gateway error instead
of failing silently.

### SMS gateway URL template

Any HTTP gateway works. Paste a URL with placeholders:

```
https://api.example.com/send?authkey={api_key}&mobiles={mobile_91}&message={message}&sender={sender_id}&route=4
```

Available placeholders: `{api_key}` `{api_secret}` `{sender_id}` `{mobile}`
`{mobile_91}` `{message}` (URL-encoded) `{message_raw}` `{dlt_template_id}`.
For a JSON gateway set **Method** to `POST (JSON)` and fill the body template.

---

## 7. Set up the cron job

cPanel → **Cron Jobs** → *Add New Cron Job* → **Every 15 minutes**:

```
/usr/local/bin/php /home/CPANELUSER/public_html/cron/run.php all >/dev/null 2>&1
```

Replace `CPANELUSER` with your account name. If `/usr/local/bin/php` is wrong,
run `which php` in Terminal, or use the version-specific binary cPanel shows
(e.g. `/opt/cpanel/ea-php82/root/usr/bin/php`).

This job does five things:

| Job | What it does |
|---|---|
| `reminders` | push/SMS reminders for follow-ups due today |
| `risk` | recomputes the recovery-probability score for every open account |
| `attendance` | auto-closes attendance left open overnight |
| `cleanup` | purges expired OTPs, old GPS pings, old audit rows per your retention settings |
| `backup` | writes a full `.sql` backup into `storage/backups/` (keeps the last 7) |

The dashboard warns you if cron has not run in 36 hours, so a silently broken
cron does not go unnoticed.

No CLI cron on your plan? Use a URL cron instead — but you must first let the
web server reach `/cron/`: edit `.htaccess` and remove `cron` from the
`RedirectMatch 403` lines. Then:

```
/usr/bin/curl -s "https://your-domain.com/cron/run.php?job=all&key=YOUR_CRON_KEY" >/dev/null
```

---

## 8. Turn on HTTPS

cPanel → **SSL/TLS Status** → **Run AutoSSL**. Once the padlock works, edit
`.htaccess` and uncomment the *Force HTTPS* block (section 4), and optionally
the `Strict-Transport-Security` header in section 5.

**The Android app will not talk to a plain-HTTP server** (cleartext is only
permitted to `localhost` for development), so this step is not optional.

---

## 9. Load your portfolio

**Settings first**: create your branches (or let the import create them), then
your BC agents.

Create BC agent accounts either way:

* **Users → Add user** — you set the password and hand it over, or
* **Invitation Codes → Generate code** — the agent self-registers in the app with
  the code, and you approve them. Open sign-up is disabled by design.

The **BC code** must match the `BC_CODE` column in your spreadsheet so accounts
are allocated automatically.

Then **Excel Upload**:

1. **Download template** → fill it in (or use your own file, headers are matched
   by alias — `ACCOUNT NO`, `Outstanding`, `Arrears` etc. all work)
2. Choose an allocation strategy:
   * **BC_CODE column** — recommended, authoritative
   * **Equal distribution** — spreads each branch's accounts over its active BC
     agents, lightest workload first
   * **Import only** — allocate later
3. Upload

Only `ACCOUNT_NUMBER` and `CUSTOMER_NAME` are mandatory. Re-uploading the same
file **updates** balances instead of creating duplicates (matched on
`ACCOUNT_NUMBER` and `CIF_NUMBER`). Rejected rows are downloadable as a CSV from
the import history.

Big file timing out? Raise the limits in `.user.ini` (already shipped with
sensible values), or split the file.

---

## 10. Install the Android app

The APK is built by GitHub Actions on every push — see
[`android/README.md`](../android/README.md).

1. GitHub → **Actions** → newest run → **Artifacts** → download the `.apk`
2. On the handset, allow installing from unknown sources and install it
3. Open the app and enter your **server URL** (`https://your-domain.com/`) on the
   login screen — the APK is not tied to any one deployment
4. Sign in with a BC agent account, or register with an invitation code

Optionally paste the release URL into **Settings → Mobile App → APK URL** so the
app can point users at an update.

---

## Verifying the install

| Check | URL / place | Expected |
|---|---|---|
| App runs | `https://your-domain.com/health` | JSON with `"success": true` |
| API runs | `https://your-domain.com/api/v1/ping` | JSON with app name and version |
| Code is protected | `https://your-domain.com/config/config.php` | **403 Forbidden** |
| Code is protected | `https://your-domain.com/app/bootstrap.php` | **403 Forbidden** |
| Uploads inert | `https://your-domain.com/uploads/` | 403, and `.php` there never executes |
| Cron ran | Dashboard | no "cron looks stale" notice |
| PDFs work | any visit → **Report PDF** | a PDF opens with a QR code |
| QR works | scan the QR on the PDF | public page saying *Genuine document* |

---

## Troubleshooting

**500 Internal Server Error, blank page**
Read `storage/logs/app-YYYY-MM-DD.log` — the real error is always there. Set
`'env' => 'development'` in `config/config.php` to see it in the browser too,
then set it back.

**"LRMS is not configured yet"**
`config/config.php` is missing. Redo step 3.

**"Database connection failed"**
Wrong credentials, or the user was never added to the database with ALL
PRIVILEGES. Note the db name and user are prefixed with your cPanel username.

**Everything 404s except the home page**
`mod_rewrite` is not applying. Confirm `.htaccess` uploaded (it is a hidden file —
enable *Show Hidden Files* in File Manager). In a subfolder install, uncomment
and set `RewriteBase /lrms/`.

**500 error only after uploading `.htaccess`**
Your host runs PHP as CGI/FPM and rejects `php_value`. Delete the
`<IfModule mod_php.c>` block; `.user.ini` already covers those settings.

**Photos fail to upload**
`uploads/` is not writable (755) or `upload_max_filesize` is too low. The error
message on screen names which one.

**OTP never arrives**
Use the **Send test** button in Settings → SMTP / SMS. Shared hosts often block
outbound port 25/587 — try port 465 with SSL, or your host's own mail server.

**`.xlsx` upload rejected**
The `zip` PHP extension is off (enable it in *Select PHP Version → Extensions*),
or the file is really an old `.xls` — open it in Excel and *Save As* `.xlsx`.

**App says "registered on another device"**
Expected: one account is bound to one handset. Clear it in
**Users → Manage → Reset device**.

**Hindi text in PDFs shows as `?`**
Known limitation. The built-in PDF writer uses the standard Helvetica fonts,
which have no Devanagari glyphs, so non-Latin text is transliterated. Names in
the panel, the app and Excel exports are full Unicode and unaffected.

---

## Backups

Two layers, use both:

1. **cPanel → Backup → Download a Full Account Backup** before any upgrade.
2. The cron `backup` job writes `storage/backups/lrms-YYYY-MM-DD-HHMMSS.sql`
   every run and keeps the last 7. Download them from File Manager, and include
   `storage/backups/` and `uploads/` in whatever off-site copy you keep.

Restore: import the `.sql` in phpMyAdmin (run `database/rollback.sql` first to
clear the old tables), then restore the `uploads/` folder.

---

## Upgrading later

```bash
cd ~
git -C lrms-src pull
cp -a lrms-src/backend/. public_html/
```

`config/config.php`, `uploads/` and `storage/` are never overwritten by this
copy. Re-import `database/schema.sql` afterwards — it only adds what is missing.
Take a full backup first.
