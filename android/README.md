# LRMS Android app

The BC field agent app: GPS-verified visits, watermarked photo evidence,
collections, attendance and an offline queue.

---

## Getting an APK without installing anything

Every push to this repository builds an APK.

1. GitHub → **Actions** → open the newest **Build Android APK** run
2. Scroll to **Artifacts** and download `LRMS-1.0.0-debug-NN.apk`
3. Install it on the handset (allow *unknown sources*)

The run summary also prints the variant, version and file size, so you can check
a build from the GitHub mobile app without downloading anything.

Pushing a tag such as `v1.0.0` additionally creates a GitHub **Release** with the
APK attached.

### Debug vs signed release

Without signing secrets the workflow builds a **debug** APK. It installs and runs
normally — it is just signed with Android's throwaway debug key, so it cannot go
to the Play Store and cannot upgrade an app that was installed from a release
build.

To get a **signed release**, add four repository secrets
(*Settings → Secrets and variables → Actions*):

| Secret | Value |
|---|---|
| `KEYSTORE_BASE64` | your `.jks` file, base64, on one line |
| `KEY_STORE_PASSWORD` | keystore password |
| `KEY_ALIAS` | key alias |
| `KEY_PASSWORD` | key password |

Create the keystore once, on any machine with a JDK:

```bash
keytool -genkeypair -v -keystore lrms.jks -alias lrms \
        -keyalg RSA -keysize 2048 -validity 10000
base64 -w 0 lrms.jks > lrms.jks.b64     # macOS: base64 -i lrms.jks -o lrms.jks.b64
```

Paste the contents of `lrms.jks.b64` into `KEYSTORE_BASE64`.

> **Keep `lrms.jks` safe and offline.** Losing it means you can never update an
> app already installed from a release built with it. It is git-ignored here and
> the workflow deletes it from the runner after every build.

The workflow decides which variant to build by checking whether all four secrets
are present. **Missing secrets never fail the build.**

---

## Building locally

Open `android/` in Android Studio (Ladybug or newer), or from the command line:

```bash
cd android
echo "sdk.dir=/path/to/your/Android/sdk" > local.properties
./gradlew assembleDebug
# APK: app/build/outputs/apk/debug/app-debug.apk
```

`local.properties` is git-ignored — it is machine specific.

---

## Toolchain

These versions were checked against Google's Maven repository, Maven Central and
`services.gradle.org` rather than copied from a template, and the build was
actually run.

| Component | Version | Why |
|---|---|---|
| Gradle | 9.6.1 | current stable |
| Android Gradle Plugin | 9.3.1 | current stable |
| Kotlin | supplied by AGP (2.3.21) | AGP 9.x has **built-in Kotlin**; the `kotlin-android` plugin is deliberately *not* applied, and adding it back would clash |
| JDK | 17 | AGP 9 requires 17 |
| `compileSdk` | **37** | forced: `androidx.core-ktx:1.19.0` refuses to compile against anything lower |
| `targetSdk` | 36 | runtime behaviour; can lag `compileSdk` safely |
| `minSdk` | 24 (Android 7.0) | covers the low-cost handsets agents actually carry |

Dependencies: `core-ktx 1.19.0`, `appcompat 1.7.1`, `material 1.14.0`,
`constraintlayout 2.2.1`, `activity-ktx 1.13.0`, `lifecycle-*-ktx 2.11.0`,
`recyclerview 1.4.0`, `swiperefreshlayout 1.2.0`, `work-runtime-ktx 2.11.2`,
`security-crypto 1.1.0`, `play-services-location 21.4.0`,
`play-services-maps 20.0.0`.

**Deliberately absent:** Retrofit, OkHttp, Gson, Room, Jetpack Compose, Hilt.
Networking is `HttpURLConnection` + `org.json`; the offline queue is
`SQLiteOpenHelper`; the UI is XML + ViewBinding. Both are part of the platform,
which keeps the APK small and removes most of the ways an Android build breaks.

Measured output: debug APK ≈ 9.4 MB, minified release APK ≈ 2.4 MB.

---

## What you must configure

### 1. Server URL — on the device

Nothing is hardcoded. Each bank has its own host, so the agent types the server
URL on the login screen (or later in **Settings**) and it is saved on the device.

Must be `https://` for any real server. `http://` is only permitted to
`localhost`, `127.0.0.1` and `10.0.2.2` (the emulator's host alias) — see
`res/xml/network_security_config.xml`. cPanel AutoSSL is free; use it.

### 2. Google Maps key — from the admin panel

There is **no Maps key in the APK**. The server sends
`config.maps_api_key` at sign-in, from **Settings → Google Maps**. Without a key
the app still works; the map is simply not drawn.

### 3. Firebase push — optional, off by default

The app builds and runs with no `google-services.json`. To enable push:

1. Firebase console → add an Android app with package `com.lrms.recovery`
2. Put `google-services.json` in `android/app/`
3. Uncomment the `com.google.gms.google-services` plugin in
   `app/build.gradle.kts`, plus the two `FIREBASE` dependency lines
4. Uncomment the `FirebaseMessagingService` block in `AndroidManifest.xml`
5. Paste the service-account JSON into the panel at **Settings → Firebase (Push)**

Reminders still reach agents without Firebase — the server falls back to SMS, and
notifications are always recorded in-app.

---

## Screens

| Screen | Notes |
|---|---|
| Splash | calls `GET /ping`; handles maintenance mode and force-update |
| Login | server URL + OTP **or** password, with a resend countdown |
| Register | invitation code first, then profile, then OTP. Open sign-up is disabled |
| Dashboard | live counters, attendance check in/out, bottom navigation |
| Accounts | search, pull-to-refresh, paging |
| Account detail | profile, dial button, visit history, *Start visit* / *Record recovery* |
| Visit form | **GPS mandatory**, camera photos, signature pad, promise fields |
| Recovery | amount, mode, reference (required unless cash), receipt photo |
| Attendance | GPS + selfie check in/out |
| Sync queue | everything still waiting, attempts, and the server's own rejection text |
| Settings | server URL, diagnostics, sign out |

---

## Three things worth knowing before you read the code

### GPS is gated on the client, not just the server

`location/LocationGate.kt` judges every fix and **Submit stays disabled** unless
the verdict is `Usable`. It applies the same three rules the API applies:

1. no fix → `Waiting` / `Blocked(NO_FIX)`
2. latitude and longitude both ≈ 0 → `Blocked(NULL_ISLAND)`
3. the OS flags the fix as mocked → `Blocked(MOCK_PROVIDER)`

Rule 3 uses `Location.isMock` on API 31+ and `isFromMockProvider` below that. The
value is also reported honestly to the server in `is_mock_location` — the app
never lies to push a submission through.

The server rechecks all of it. The client check exists because discovering the
problem *after* the agent has filled in a long form and walked away from the
customer's house means redoing the visit.

### The offline queue cannot double-count money

`visit_uid` and `recovery_uid` are generated **once**, when the record is first
submitted, and reused on every retry — including retries made hours later by
`sync/QueueFlushWorker`. The server answers `duplicate: true` instead of writing
a second row. Regenerating the UUID on retry would let one ₹25,000 collection be
recorded twice.

`ui/SyncQueueActivity` exists so this machinery is visible: agents can see what
is still waiting and why anything was rejected.

### GPS tracking really runs every 15 minutes, not every 5

The panel's `gps_ping_interval_seconds` defaults to 300 (5 minutes), but
**WorkManager will not schedule periodic work more often than 15 minutes**.
`sync/SyncScheduler.kt` clamps explicitly and the Settings screen shows both the
requested and the effective interval, rather than letting the number quietly mean
something else.

Genuine sub-15-minute tracking would need a foreground service with a permanent
notification — a product decision (battery, Play policy, and telling agents
plainly that they are being tracked), not something to slip in silently.

---

## Known limitations

* **`androidx.security-crypto` 1.1.0 is deprecated upstream.**
  `EncryptedSharedPreferences` and `MasterKey` produce deprecation warnings.
  They still work, and `SessionStore` already falls back to plain
  `SharedPreferences` when a device's keystore is unusable (and the Settings
  screen says so). Migrating away is future work.
* `Activity.overridePendingTransition` in `BottomNavHelper` is deprecated on
  API 34+; the tab switch still works.
* Maps are display-only. There is no offline map tile cache.
* No automated tests. The build is verified by compiling; the screens have been
  reviewed but not instrumented.
* Not tested on a physical handset in this environment — only compiled, packaged
  and signature-verified. GPS, camera and dialer paths need a real device.
