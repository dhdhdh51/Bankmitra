<div align="center">
  <img src="branding/logo.svg" width="96" alt="LRMS logo">
  <h1>LRMS — Loan Recovery Management System</h1>
  <p><strong>Shared Hosting Edition</strong></p>
  <p>Recovery agent (BC) field-visit tracking, GPS-verified visits, customer and loan management, and recovery reporting.</p>
</div>

---

## What this is

A complete recovery-management system that runs on ordinary **cPanel shared
hosting** — no Composer, no npm, no Docker, no queue worker, no cloud services.

| Part | Technology |
|---|---|
| Admin panel | Core PHP 8 (MVC), Bootstrap 5.3.8 + Chart.js 4.5.1 from CDN |
| REST API | versioned at `/api/v1` |
| Mobile app | Android, Kotlin, XML views (no Compose) |
| Database | MySQL 5.7+ / MariaDB 10.4+ |
| Email | native PHP socket SMTP (no PHPMailer) |
| PDF + QR | written from scratch in PHP (no mPDF/TCPDF) |
| Excel | read and written from scratch (no PhpSpreadsheet) |
| Scheduling | one cPanel cron job |
| Files | local `uploads/` folder |
| APK builds | GitHub Actions |

**Every integration key is set from the admin panel**, stored AES-256 encrypted,
and takes effect immediately. Nothing is hardcoded and nothing needs re-uploading
when a key changes.

---

## Start here

| I want to… | Read |
|---|---|
| Put this on my hosting | **[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)** |
| Get the Android APK | **[android/README.md](android/README.md)** |
| Call the API | **[docs/API.md](docs/API.md)** |

### Uploading from a phone? Use the `hosting` branch

There is a second branch, **[`hosting`](../../tree/hosting)**, that contains
*only* the files that belong on your hosting — with the root of the branch being
the root of `public_html`. Download it as a ZIP, extract it into `public_html`,
done. No folder shuffling, no Android source to wade through.

Direct ZIP: `https://github.com/dhdhdh51/Bankmitra/archive/refs/heads/hosting.zip`

`deploy/public_html` is an older name for the same thing, kept as a mirror at the
same commit so existing bookmarks keep working. New uploads should use `hosting`.

Everything else (Android app, APK workflow, docs) stays on this branch.

Default sign-in after importing the schema: **`ADMIN001` / `Admin@12345`** —
you are forced to change it on first use.

---

## Repository layout

```
backend/            ← upload the CONTENTS of this folder into public_html
  index.php           admin panel front controller
  api/v1/index.php    REST API front controller
  app/                Core, Controllers, Services, Views   (blocked from the web)
  lib/                Crypto, Pdf, QrCode, Mailer, SMS, FCM, spreadsheets
  config/             config.sample.php → copy to config.php
  cron/run.php        the single scheduled-job entry point
  assets/  uploads/  storage/
database/
  schema.sql          phpMyAdmin import-ready, re-import safe
  rollback.sql        full teardown
android/              Android Studio project (Gradle 9.6.1 / AGP 9.3.1)
docs/
  API.md              endpoint reference with curl examples
  DEPLOYMENT.md       step-by-step cPanel guide
branding/logo.svg     placeholder logo (replace with the bank's artwork)
.github/workflows/    APK build pipeline
```

---

## Features

**Roles** — Super Admin, Regional Office (read-only, region-scoped), Branch
Manager (own branch), BC Agent (own allocated accounts). Row-level scoping is
applied in SQL, not just hidden in the UI.

**Access** — mobile or email OTP **and** password login. Open sign-up is disabled:
new accounts need an invitation code that carries a pre-assigned role, branch,
use count and expiry. One account is bound to one device; admins can reset it.
Per-account and per-IP lockout, configurable auto-logout.

**Portfolio** — Excel/CSV import that recognises ~36 column aliases and updates
balances on re-upload instead of duplicating. Auto-allocation by `BC_CODE`, or
equal distribution to the lightest-loaded agent in each branch. Rejected rows come
back as a downloadable CSV.

**Field work** — GPS is mandatory for a visit: 0,0 coordinates, mock-GPS and
being too far from the customer's recorded position are all rejected, on the
device *and* on the server. Photos are watermarked at capture with agent name,
timestamp and lat/long, hashed with SHA-256, and thumbnailed. Signature pad.
Full digital visit form. Collections in cash / transfer / UPI / cheque / DD with
receipt numbers, and a verify-or-reject step that reverses the balance on
rejection.

**Offline** — visits, collections and GPS pings queue in SQLite on the handset and
upload when signal returns. Client-generated UUIDs make retries idempotent, so a
collection can never be counted twice.

**Reporting** — 11 report types (BC, branch, district, village, NPA, recovery,
visit, GPS, photo, attendance, follow-up), each exportable to **.xlsx / CSV / PDF**.
PDF visit reports, loan statements and receipts each carry a QR code that resolves
to a public verification page — which confirms the document is genuine without
revealing any customer data.

**Operations** — audit log with before/after diffs and masked secrets, live agent
tracking from plain GPS pings, GPS + selfie attendance with branch geofence
flagging, explainable rule-based recovery-probability scoring, and nightly
in-database backups.

---

## What was actually verified, and how

Nothing below is "should work".

**Database** — `schema.sql` imported into a real MariaDB 10.5: 28 tables, seeds
correct, re-import is a no-op, `rollback.sql` drops everything, re-import after
rollback works.

**Backend** — the full panel was driven over HTTP with a real login session: all
44 pages returned 200 with a clean error log; PDF, xlsx, CSV and template
downloads produced correct file types; create/verify/allocate/settings POSTs all
succeeded and a bad CSRF token was rejected; an `.xlsx` built with openpyxl
imported with the expected insert/skip/allocate counts; all five cron jobs ran.

**API** — exercised end to end with curl: login via the encrypted-mobile blind
index, device-binding rejection, and the visit endpoint refusing GPS 0,0, mock
GPS, a missing photo and a 919 km distance — then accepting a valid visit with two
watermarked photos, a signature and an auto-created follow-up. Re-sending the same
`visit_uid` and `recovery_uid` returned `duplicate: true` with no second row and
no double-counted money.

**QR encoder** — format and version BCH values checked against the published
tables for all 8 masks; 71 generated codes (1–213 bytes, including UTF-8
Devanagari) all decoded correctly with OpenCV.

**PDF writer** — output opens in pypdf and PyMuPDF, text extracts, JPEGs embed,
and the vector QR still decodes from the rendered page.

**Android** — `./gradlew assembleDebug` **succeeds**: a 9.4 MB APK with all 10
activities and all 10 launcher-icon densities inside it. Both CI paths were run
locally: `assembleRelease` without a keystore produces an unsigned 2.4 MB APK
without failing, and with a keystore `apksigner verify` reports *Verifies, v2
scheme, 1 signer*.

Eight real bugs were found by this testing and fixed — including a transposed
QR format-information layout, a MySQL reserved word (`load`) breaking two pages,
a cron expression closing a PHP block comment, and `androidx.core-ktx` 1.19.0
forcing `compileSdk` to 37.

---

## Known limitations

* **Hindi/Devanagari in PDFs** is transliterated to ASCII. The built-in PDF writer
  uses the standard Helvetica fonts, which have no Devanagari glyphs. The panel,
  the app and Excel exports are full Unicode. Real Hindi PDFs would need an
  embedded TrueType font.
* **The Android app has been compiled and signature-verified, not run on a
  handset.** GPS, camera and dialer behaviour needs a real device.
* **No automated test suite.** Verification was done by exercising the running
  system, as described above.
* **GPS tracking runs every 15 minutes**, not the 5 minutes the setting suggests —
  that is WorkManager's floor for periodic work, and the app shows both numbers
  rather than hiding the difference.
* `androidx.security-crypto` 1.1.0 is deprecated upstream; it still works and the
  app falls back to plain preferences when a device keystore is unusable.
* Push notifications and Google Maps are optional and inactive until you add keys
  in the admin panel; the dashboard tells you what is missing.
* The logo in `branding/` is a placeholder. Replace it with the bank's artwork —
  keep the filename and the 512×512 viewBox and nothing else needs changing.

---

## Security notes

* Mobile numbers, email addresses, Aadhaar and PAN are stored **AES-256-CBC
  encrypted** with encrypt-then-MAC. Exact-match lookups use an HMAC-SHA256 blind
  index, so search still works without decrypting.
* OTPs are stored only as password hashes, are never returned by the API, and are
  never written to a log.
* API tokens are stored only as SHA-256 hashes; a database dump yields no usable
  sessions.
* All SQL goes through prepared statements. Every state-changing form is CSRF
  protected. Uploads are served as inert files and scripts there cannot execute.
* The audit log masks anything that looks like a secret before writing.
* `config/config.php` (credentials and the encryption key) and the Android
  keystore are git-ignored and must never be committed.

> Change `app_key` in `config/config.php` and every already-encrypted column
> becomes unreadable. Back it up before importing real data.
