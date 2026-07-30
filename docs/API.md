# LRMS REST API — v1

Base URL: `https://YOUR-DOMAIN/api/v1/`

Everything is versioned under `/api/v1/` so a future `/api/v2/` can ship
without breaking installed copies of the Android app.

---

## 1. Conventions

### Request

| Aspect | Rule |
|---|---|
| Content type | `application/json` for normal calls, `multipart/form-data` when files are attached |
| Auth | `Authorization: Bearer <token>` |
| Fallback auth header | `X-Auth-Token: <token>` — used automatically when the host strips `Authorization` |
| Device header | `X-Device-Id: <installation-uuid>` on every authenticated call |
| App version header | `X-App-Version: 1.0.0` |

### Response envelope

Every endpoint returns the same shape. The Android app only ever has to look at
`success`, `code` and `message`.

Success:

```json
{
  "success": true,
  "message": "OK",
  "data": { },
  "meta": { "total": 120, "page": 1, "perPage": 25, "pages": 5 }
}
```

Failure:

```json
{
  "success": false,
  "code": "otp_invalid",
  "message": "The OTP you entered is incorrect. 3 attempts left.",
  "errors": { "otp": "The OTP you entered is incorrect." }
}
```

### Stable error codes

| `code` | HTTP | Meaning / what the app should do |
|---|---|---|
| `validation_failed` | 422 | Show field errors from `errors` |
| `unauthenticated` | 401 | Token missing/expired → send user to login |
| `token_expired` | 401 | Same as above |
| `device_mismatch` | 403 | Show "registered on another device, contact admin" |
| `account_pending` | 403 | Registration awaiting admin approval |
| `account_suspended` | 403 | Blocked account |
| `forbidden` | 403 | Role not allowed |
| `not_found` | 404 | Record missing |
| `otp_invalid` | 400 | Wrong OTP |
| `otp_expired` | 400 | Expired OTP → offer resend |
| `otp_throttled` | 429 | Show the cooldown from `data.retry_after_seconds` |
| `otp_delivery_failed` | 502 | Gateway not configured / rejected |
| `rate_limited` | 429 | Too many attempts |
| `gps_required` | 422 | GPS was off or coordinates were 0,0 |
| `mock_location_blocked` | 422 | Fake GPS detected |
| `photo_required` | 422 | Minimum photo count not met |
| `duplicate` | 409 | Idempotency hit — treat as success and drop the queued item |
| `maintenance_mode` | 503 | Show the maintenance screen with `data.message` |
| `update_required` | 426 | Force update — send user to `data.apk_url` |
| `csrf_invalid` | 419 | Web only |
| `server_error` | 500 | Show "try again", keep the item queued |

### Idempotency (offline queue)

`POST /visits`, `POST /recoveries` and `POST /tracking/ping` all accept a
client-generated UUID (`visit_uid`, `recovery_uid`). Re-sending the same UUID
returns `success: true` with `data.duplicate: true` instead of creating a second
row. **The Android app must generate the UUID once, when the record is created
offline, and never regenerate it on retry.**

---

## 2. Public endpoints

### `GET /ping`

Health check plus the values the app needs before login.

```json
{
  "success": true,
  "message": "OK",
  "data": {
    "app_name": "LRMS",
    "organisation": "Example Gramin Bank",
    "server_time": "2026-07-29T17:42:11+05:30",
    "latest_app_version": "1.0.0",
    "min_app_version": "1.0.0",
    "force_update": false,
    "maintenance_mode": false,
    "maintenance_message": "",
    "apk_url": "",
    "otp_length": 6,
    "otp_expiry_minutes": 10,
    "otp_resend_seconds": 60
  }
}
```

### `POST /auth/invite/validate`

Open signup is disabled; the app must validate the invitation code first.

Request
```json
{ "code": "K7M2QP9XZR" }
```

Response
```json
{
  "success": true,
  "message": "Invitation code is valid.",
  "data": {
    "role": "bc_agent",
    "role_name": "BC Agent",
    "branch_name": "Bihta Branch",
    "requires_approval": true,
    "expires_at": "2026-08-05 23:59:59"
  }
}
```

### `POST /auth/otp/request`

```json
{
  "identifier": "ramesh@example.com",
  "identifier_type": "email",
  "purpose": "login"
}
```

* `identifier_type`: `email` (default) | `mobile`
* `purpose`: `login` | `register` | `reset_password`

Email is the default channel because email is the mandatory identifier for every
account; a mobile number is optional. Send `identifier_type: mobile` only when
you actually want an SMS, and note that it fails with `otp_delivery_failed` if no
SMS gateway is configured in the admin panel.

Response (the OTP itself is **never** returned):
```json
{
  "success": true,
  "message": "OTP sent to 98****3210.",
  "data": { "expires_in_seconds": 600, "resend_after_seconds": 60 }
}
```

### `POST /auth/otp/verify`

```json
{
  "identifier": "ramesh@example.com",
  "identifier_type": "email",
  "purpose": "login",
  "otp": "483920",
  "device_id": "a1b2c3d4e5f6a7b8",
  "device_model": "Redmi Note 12",
  "os_version": "14",
  "app_version": "1.0.0"
}
```

Response — see [session payload](#session-payload).

### `POST /auth/login` (password)

```json
{
  "identifier": "9876543210",
  "password": "Secret@123",
  "device_id": "a1b2c3d4e5f6a7b8",
  "device_model": "Redmi Note 12",
  "os_version": "14",
  "app_version": "1.0.0"
}
```

`identifier` accepts email, mobile **or** employee code.

Response — see [session payload](#session-payload).

### `POST /auth/register`

Two-step: request an OTP with `purpose: register` first, then:

```json
{
  "invite_code": "K7M2QP9XZR",
  "full_name": "Ramesh Kumar",
  "identifier": "ramesh@example.com",
  "identifier_type": "email",
  "otp": "483920",
  "mobile": "9876543210",
  "password": "Secret@123",
  "employee_code": "BC0142",
  "bc_code": "BC0142",
  "device_id": "a1b2c3d4e5f6a7b8",
  "device_model": "Redmi Note 12",
  "os_version": "14",
  "app_version": "1.0.0"
}
```

If the invitation requires approval the response is `403 account_pending` with a
friendly message — the account row exists but is `status = pending`.
Otherwise a full session payload is returned.

### Session payload

```json
{
  "success": true,
  "message": "Signed in successfully.",
  "data": {
    "token": "8f3a2b1c...",
    "token_type": "Bearer",
    "expires_at": "2026-08-28 17:42:11",
    "user": {
      "id": 42,
      "uuid": "1f0c...",
      "full_name": "Ramesh Kumar",
      "employee_code": "BC0142",
      "role": "bc_agent",
      "role_name": "BC Agent",
      "mobile_masked": "98****3210",
      "branch_id": 7,
      "branch_name": "Bihta Branch",
      "bc_id": 15,
      "bc_code": "BC0142",
      "photo_url": null,
      "must_change_password": false
    },
    "config": {
      "maps_api_key": "AIza...",
      "gps_ping_interval_seconds": 300,
      "visit_photo_min": 1,
      "visit_max_distance_m": 500,
      "block_mock_gps": true
    }
  }
}
```

> `maps_api_key` is delivered at runtime from the admin panel — it is **not**
> compiled into the APK.

---

## 3. Authenticated endpoints

### `GET /me`
Returns the same `user` + `config` objects as the session payload.

### `POST /me/fcm-token`
```json
{ "fcm_token": "d9Xk...", "device_id": "a1b2c3d4e5f6a7b8" }
```

### `POST /me/password`
```json
{ "current_password": "Secret@123", "new_password": "Better@4567" }
```

### `POST /auth/logout`
Revokes the current token. Body may be empty.

### `GET /dashboard`
```json
{
  "success": true,
  "data": {
    "assigned_accounts": 184,
    "visited_today": 7,
    "pending_today": 12,
    "promise_count": 23,
    "ots_count": 4,
    "recovery_today": 18500.00,
    "recovery_month": 342750.00,
    "monthly_target": 500000.00,
    "target_achieved_pct": 68.55,
    "attendance": { "checked_in": true, "check_in_at": "2026-07-29 09:14:02", "checked_out": false },
    "followups_due": 5,
    "unsynced_hint": "Submit pending visits from the queue screen."
  }
}
```

### `GET /customers`
Query: `search`, `village`, `status`, `page`, `per_page` (max 200).

`search` matches account number, CIF, name, village, or mobile.

```json
{
  "success": true,
  "data": [
    {
      "loan_id": 901,
      "account_number": "38291047561",
      "customer_id": 512,
      "cif_number": "CIF0099213",
      "full_name": "Ramesh Kumar",
      "guardian_name": "Mahesh Kumar",
      "mobile_masked": "98****3210",
      "village": "Bihta",
      "district": "Patna",
      "outstanding_amount": 245780.00,
      "overdue_amount": 61200.00,
      "asset_class": "SMA2",
      "dpd": 78,
      "recovery_status": "promise",
      "last_visit_at": "2026-07-21 11:03:00",
      "visit_count": 3,
      "next_followup_date": "2026-08-15",
      "latitude": 25.5512,
      "longitude": 84.8781,
      "risk_score": 62,
      "risk_band": "medium"
    }
  ],
  "meta": { "total": 184, "page": 1, "perPage": 25, "pages": 8 }
}
```

### `GET /loans/{id}`
Full profile: loan, customer (with **unmasked** mobile — the agent needs to call
them), last 20 visits, last 20 recoveries, open follow-ups.

### `POST /visits` — `multipart/form-data`

GPS is mandatory. Submission is rejected when GPS is off, coordinates are 0,0,
or a mock provider is detected (when `block_mock_gps` is on).

| Field | Type | Required | Notes |
|---|---|---|---|
| `visit_uid` | uuid | yes | generated on the device, idempotency key |
| `loan_id` | int | yes | |
| `visited_at` | datetime | yes | `YYYY-MM-DD HH:MM:SS`, device local time |
| `latitude` / `longitude` | float | yes | rejected if both ~0 |
| `accuracy_m` | float | no | |
| `is_mock_location` | bool | no | app must report honestly |
| `visit_status` | enum | yes | `visited,not_available,promise,paid,ots,legal,skip,untraceable` |
| `customer_available` | bool | no | |
| `house_locked` | bool | no | |
| `met_person`, `met_relation` | string | no | |
| `occupation` | string | no | |
| `recovery_possibility` | enum | no | `high,medium,low,nil` |
| `promise_amount` | decimal | no | required when `visit_status=promise` |
| `promise_date` | date | no | required when `visit_status=promise` |
| `recommendation`, `remarks` | text | no | |
| `signature` | base64 png | no | signature pad output |
| `photos[]` | file | see config | JPG/PNG/WebP, max 8 MB each |
| `app_version` | string | no | |

Response
```json
{
  "success": true,
  "message": "Visit saved.",
  "data": {
    "visit_id": 3391,
    "visit_uid": "9b1c...",
    "photos_saved": 2,
    "photo_errors": [],
    "distance_from_customer_m": 41,
    "duplicate": false,
    "followup_created": true
  }
}
```

### `GET /visits`
Query: `date`, `from`, `to`, `status`, `page`.

### `POST /recoveries`

| Field | Required | Notes |
|---|---|---|
| `recovery_uid` | yes | idempotency key |
| `loan_id` | yes | |
| `visit_id` | no | link to the visit it was collected during |
| `amount` | yes | > 0 |
| `payment_mode` | yes | `cash,transfer,upi,cheque,dd,other` |
| `txn_reference` | conditional | required for everything except `cash` |
| `receipt_number` | no | server generates `RCP-YYYYMMDD-XXXX` when omitted |
| `collected_at` | yes | |
| `latitude`, `longitude` | no | |
| `receipt_photo` | no | file |
| `remarks` | no | |

Response
```json
{
  "success": true,
  "message": "Recovery of Rs.25,000.00 recorded. Receipt RCP-20260729-0042.",
  "data": {
    "recovery_id": 771,
    "receipt_number": "RCP-20260729-0042",
    "status": "pending",
    "duplicate": false,
    "loan_outstanding_after": 220780.00
  }
}
```

`status` starts as `pending` — a Branch Manager verifies it in the admin panel.

### `POST /attendance/check-in` — `multipart/form-data`
Fields: `latitude`, `longitude`, `accuracy_m`, `selfie` (file), `remarks`.

### `POST /attendance/check-out`
Fields: `latitude`, `longitude`, `selfie` (file, optional).

### `GET /attendance/today`

### `POST /tracking/ping`
Batched so the app can hold pings while offline.

```json
{
  "pings": [
    { "latitude": 25.59, "longitude": 85.13, "accuracy_m": 12.4, "speed_kmph": 18.2, "battery_pct": 64, "is_mock": false, "recorded_at": "2026-07-29 10:15:00" }
  ]
}
```
Response: `{ "data": { "accepted": 1, "rejected": 0 } }`

### `GET /followups`
Query: `due` (`today` | `overdue` | `week`), `page`.

### `POST /followups/{id}/complete`
```json
{ "remarks": "Customer paid Rs.5,000" }
```

### `GET /notifications`
Query: `unread_only=1`, `page`. Also `POST /notifications/{id}/read`.

### `GET /sync/bootstrap`
One call that returns everything the app caches for offline use: the assigned
loan list, open follow-ups and the config block. Intended for use right after
login and on a manual "Sync now".

```json
{
  "success": true,
  "data": {
    "generated_at": "2026-07-29 17:42:11",
    "config": { },
    "loans": [ ],
    "followups": [ ],
    "counts": { "loans": 184, "followups": 5 }
  }
}
```

---

## 4. Curl examples

```bash
BASE=https://your-domain.com/api/v1

# 1. health
curl -s $BASE/ping | python3 -m json.tool

# 2. request an OTP
curl -s -X POST $BASE/auth/otp/request \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"ramesh@example.com","identifier_type":"email","purpose":"login"}'

# 3. verify and get a token
TOKEN=$(curl -s -X POST $BASE/auth/otp/verify \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"ramesh@example.com","identifier_type":"email","purpose":"login",
       "otp":"483920","device_id":"testdevice001","device_model":"curl",
       "os_version":"0","app_version":"1.0.0"}' | python3 -c 'import sys,json;print(json.load(sys.stdin)["data"]["token"])')

# 4. dashboard
curl -s $BASE/dashboard -H "Authorization: Bearer $TOKEN"

# 5. submit a GPS visit with a photo
curl -s -X POST $BASE/visits \
  -H "Authorization: Bearer $TOKEN" \
  -F visit_uid=$(uuidgen) \
  -F loan_id=901 \
  -F "visited_at=$(date '+%Y-%m-%d %H:%M:%S')" \
  -F latitude=25.5512 -F longitude=84.8781 -F accuracy_m=8.5 \
  -F visit_status=promise -F promise_amount=25000 -F promise_date=2026-08-15 \
  -F "remarks=Customer met at residence" \
  -F "photos[]=@house.jpg"
```

---

## 5. Rate limits and security

* OTP requests are throttled per identifier by `security.otp_resend_seconds`.
* Failed logins are counted per account **and** per IP; after
  `security.max_login_attempts` the account is locked for
  `security.lockout_minutes`.
* Tokens are stored only as SHA-256 hashes (`api_tokens.token_hash`) — a
  database dump does not yield usable sessions.
* One account is bound to one `device_id` while `security.device_binding` is on.
  An admin clears it from **Users → Reset Device**.
* OTPs are stored as password hashes and are never logged or returned.
* All requests and their outcomes are written to `audit_logs` with
  `channel = api`.
