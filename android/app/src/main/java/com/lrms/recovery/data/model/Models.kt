package com.lrms.recovery.data.model

import com.lrms.recovery.data.net.arrayOrNull
import com.lrms.recovery.data.net.boolOr
import com.lrms.recovery.data.net.doubleOr
import com.lrms.recovery.data.net.doubleOrNull
import com.lrms.recovery.data.net.intOr
import com.lrms.recovery.data.net.mapObjects
import com.lrms.recovery.data.net.objectOrNull
import com.lrms.recovery.data.net.stringOr
import com.lrms.recovery.data.net.stringOrNull
import org.json.JSONArray
import org.json.JSONObject

/*
 * Plain immutable models parsed straight out of org.json.
 *
 * Every parser is total: a missing or null field becomes a documented default or
 * a Kotlin `null`, never an exception and never `!!`. A server that adds fields
 * cannot break an installed APK, and a server that drops one degrades to a
 * placeholder instead of crashing an agent standing in a field.
 */

/** `data.config` — runtime settings pushed by the admin panel. */
class AppConfig(
    /** Google Maps key delivered at RUNTIME. Never compiled into the APK. */
    val mapsApiKey: String?,
    val gpsPingIntervalSeconds: Int,
    val visitPhotoMin: Int,
    val visitMaxDistanceM: Int,
    val blockMockGps: Boolean,
) {
    companion object {
        val DEFAULT = AppConfig(null, 300, 1, 500, true)

        fun from(json: JSONObject?): AppConfig {
            if (json == null) return DEFAULT
            return AppConfig(
                mapsApiKey = json.stringOrNull("maps_api_key"),
                gpsPingIntervalSeconds = json.intOr("gps_ping_interval_seconds", 300),
                visitPhotoMin = json.intOr("visit_photo_min", 1),
                visitMaxDistanceM = json.intOr("visit_max_distance_m", 500),
                // Fail SAFE: if the server does not say, assume mock GPS is blocked.
                blockMockGps = json.boolOr("block_mock_gps", true),
            )
        }
    }
}

/** `data.user`. */
class UserProfile(
    val id: Int,
    val uuid: String?,
    val fullName: String,
    val employeeCode: String?,
    val role: String,
    val roleName: String,
    val mobileMasked: String?,
    val branchId: Int?,
    val branchName: String?,
    val bcId: Int?,
    val bcCode: String?,
    val photoUrl: String?,
    val mustChangePassword: Boolean,
) {
    companion object {
        fun from(json: JSONObject?): UserProfile? {
            if (json == null) return null
            return UserProfile(
                id = json.intOr("id", 0),
                uuid = json.stringOrNull("uuid"),
                fullName = json.stringOr("full_name", "Unknown user"),
                employeeCode = json.stringOrNull("employee_code"),
                role = json.stringOr("role", ""),
                roleName = json.stringOr("role_name", ""),
                mobileMasked = json.stringOrNull("mobile_masked"),
                branchId = json.optIntOrNull("branch_id"),
                branchName = json.stringOrNull("branch_name"),
                bcId = json.optIntOrNull("bc_id"),
                bcCode = json.stringOrNull("bc_code"),
                photoUrl = json.stringOrNull("photo_url"),
                mustChangePassword = json.boolOr("must_change_password", false),
            )
        }
    }
}

private fun JSONObject.optIntOrNull(key: String): Int? =
    if (has(key) && !isNull(key)) optInt(key, 0) else null

/** The session payload returned by login / otp-verify / register. */
class Session(
    val token: String,
    val tokenType: String,
    val expiresAt: String?,
    val user: UserProfile?,
    val config: AppConfig,
    /** Raw JSON kept so SessionStore can persist without a hand-written serialiser. */
    val rawUserJson: String?,
    val rawConfigJson: String?,
) {
    companion object {
        fun from(json: JSONObject): Session {
            val userJson = json.objectOrNull("user")
            val configJson = json.objectOrNull("config")
            return Session(
                token = json.stringOr("token", ""),
                tokenType = json.stringOr("token_type", "Bearer"),
                expiresAt = json.stringOrNull("expires_at"),
                user = UserProfile.from(userJson),
                config = AppConfig.from(configJson),
                rawUserJson = userJson?.toString(),
                rawConfigJson = configJson?.toString(),
            )
        }
    }
}

/** `GET /ping`. */
class PingInfo(
    val appName: String,
    val organisation: String,
    val serverTime: String?,
    val latestAppVersion: String?,
    val minAppVersion: String?,
    val forceUpdate: Boolean,
    val maintenanceMode: Boolean,
    val maintenanceMessage: String,
    val apkUrl: String?,
    val otpLength: Int,
    val otpExpiryMinutes: Int,
    val otpResendSeconds: Int,
) {
    companion object {
        fun from(json: JSONObject) = PingInfo(
            appName = json.stringOr("app_name", "LRMS"),
            organisation = json.stringOr("organisation", ""),
            serverTime = json.stringOrNull("server_time"),
            latestAppVersion = json.stringOrNull("latest_app_version"),
            minAppVersion = json.stringOrNull("min_app_version"),
            forceUpdate = json.boolOr("force_update", false),
            maintenanceMode = json.boolOr("maintenance_mode", false),
            maintenanceMessage = json.stringOr("maintenance_message", ""),
            apkUrl = json.stringOrNull("apk_url"),
            otpLength = json.intOr("otp_length", 6),
            otpExpiryMinutes = json.intOr("otp_expiry_minutes", 10),
            otpResendSeconds = json.intOr("otp_resend_seconds", 60),
        )
    }
}

/** `POST /auth/invite/validate`. */
class InviteInfo(
    val role: String,
    val roleName: String,
    val branchName: String?,
    val requiresApproval: Boolean,
    val expiresAt: String?,
) {
    companion object {
        fun from(json: JSONObject) = InviteInfo(
            role = json.stringOr("role", ""),
            roleName = json.stringOr("role_name", json.stringOr("role", "")),
            branchName = json.stringOrNull("branch_name"),
            requiresApproval = json.boolOr("requires_approval", true),
            expiresAt = json.stringOrNull("expires_at"),
        )
    }
}

/** `POST /auth/otp/request`. */
class OtpChallenge(
    val expiresInSeconds: Int,
    val resendAfterSeconds: Int,
) {
    companion object {
        fun from(json: JSONObject) = OtpChallenge(
            expiresInSeconds = json.intOr("expires_in_seconds", 600),
            resendAfterSeconds = json.intOr("resend_after_seconds", 60),
        )
    }
}

/** `GET /dashboard`. */
class Dashboard(
    val assignedAccounts: Int,
    val visitedToday: Int,
    val pendingToday: Int,
    val promiseCount: Int,
    val otsCount: Int,
    val recoveryToday: Double,
    val recoveryMonth: Double,
    val monthlyTarget: Double,
    val targetAchievedPct: Double,
    val followupsDue: Int,
    val checkedIn: Boolean,
    val checkInAt: String?,
    val checkedOut: Boolean,
    val unsyncedHint: String?,
) {
    companion object {
        fun from(json: JSONObject): Dashboard {
            val att = json.objectOrNull("attendance")
            return Dashboard(
                assignedAccounts = json.intOr("assigned_accounts", 0),
                visitedToday = json.intOr("visited_today", 0),
                pendingToday = json.intOr("pending_today", 0),
                promiseCount = json.intOr("promise_count", 0),
                otsCount = json.intOr("ots_count", 0),
                recoveryToday = json.doubleOr("recovery_today", 0.0),
                recoveryMonth = json.doubleOr("recovery_month", 0.0),
                monthlyTarget = json.doubleOr("monthly_target", 0.0),
                targetAchievedPct = json.doubleOr("target_achieved_pct", 0.0),
                followupsDue = json.intOr("followups_due", 0),
                checkedIn = att?.boolOr("checked_in", false) ?: false,
                checkInAt = att?.stringOrNull("check_in_at"),
                checkedOut = att?.boolOr("checked_out", false) ?: false,
                unsyncedHint = json.stringOrNull("unsynced_hint"),
            )
        }
    }
}

/** One row of `GET /customers`. */
class CustomerSummary(
    val loanId: Int,
    val accountNumber: String,
    val customerId: Int,
    val cifNumber: String?,
    val fullName: String,
    val guardianName: String?,
    val mobileMasked: String?,
    val village: String?,
    val district: String?,
    val outstandingAmount: Double,
    val overdueAmount: Double,
    val assetClass: String?,
    val dpd: Int,
    val recoveryStatus: String?,
    val lastVisitAt: String?,
    val visitCount: Int,
    val nextFollowupDate: String?,
    val latitude: Double?,
    val longitude: Double?,
    val riskScore: Int,
    val riskBand: String?,
) {
    companion object {
        fun from(json: JSONObject) = CustomerSummary(
            loanId = json.intOr("loan_id", 0),
            accountNumber = json.stringOr("account_number", "-"),
            customerId = json.intOr("customer_id", 0),
            cifNumber = json.stringOrNull("cif_number"),
            fullName = json.stringOr("full_name", "Unknown"),
            guardianName = json.stringOrNull("guardian_name"),
            mobileMasked = json.stringOrNull("mobile_masked"),
            village = json.stringOrNull("village"),
            district = json.stringOrNull("district"),
            outstandingAmount = json.doubleOr("outstanding_amount", 0.0),
            overdueAmount = json.doubleOr("overdue_amount", 0.0),
            assetClass = json.stringOrNull("asset_class"),
            dpd = json.intOr("dpd", 0),
            recoveryStatus = json.stringOrNull("recovery_status"),
            lastVisitAt = json.stringOrNull("last_visit_at"),
            visitCount = json.intOr("visit_count", 0),
            nextFollowupDate = json.stringOrNull("next_followup_date"),
            latitude = json.doubleOrNull("latitude"),
            longitude = json.doubleOrNull("longitude"),
            riskScore = json.intOr("risk_score", 0),
            riskBand = json.stringOrNull("risk_band"),
        )

        fun listFrom(arr: JSONArray?): List<CustomerSummary> = arr.mapObjects { from(it) }
    }
}

/** One row of a loan's visit history. */
class VisitSummary(
    val visitId: Int,
    val visitedAt: String?,
    val visitStatus: String?,
    val metPerson: String?,
    val remarks: String?,
    val promiseAmount: Double?,
    val promiseDate: String?,
) {
    companion object {
        fun from(json: JSONObject) = VisitSummary(
            visitId = json.intOr("visit_id", json.intOr("id", 0)),
            visitedAt = json.stringOrNull("visited_at"),
            visitStatus = json.stringOrNull("visit_status"),
            metPerson = json.stringOrNull("met_person"),
            remarks = json.stringOrNull("remarks"),
            promiseAmount = json.doubleOrNull("promise_amount"),
            promiseDate = json.stringOrNull("promise_date"),
        )
    }
}

/** One row of a loan's recovery history. */
class RecoverySummary(
    val recoveryId: Int,
    val amount: Double,
    val paymentMode: String?,
    val receiptNumber: String?,
    val status: String?,
    val collectedAt: String?,
) {
    companion object {
        fun from(json: JSONObject) = RecoverySummary(
            recoveryId = json.intOr("recovery_id", json.intOr("id", 0)),
            amount = json.doubleOr("amount", 0.0),
            paymentMode = json.stringOrNull("payment_mode"),
            receiptNumber = json.stringOrNull("receipt_number"),
            status = json.stringOrNull("status"),
            collectedAt = json.stringOrNull("collected_at"),
        )
    }
}

/**
 * `GET /loans/{id}`.
 *
 * docs/API.md describes this as "loan, customer, last 20 visits, last 20
 * recoveries, open follow-ups" without pinning the exact nesting, so the parser
 * accepts either `data.loan`/`data.customer` sub-objects OR a flat `data` object
 * carrying the same keys as a `/customers` row. Whichever the backend emits, the
 * screen renders.
 */
class LoanDetail(
    val summary: CustomerSummary,
    /** Unmasked mobile — the whole point of this screen is being able to call. */
    val mobile: String?,
    val address: String?,
    val visits: List<VisitSummary>,
    val recoveries: List<RecoverySummary>,
    val followups: List<Followup>,
) {
    companion object {
        fun from(json: JSONObject): LoanDetail {
            val loan = json.objectOrNull("loan")
            val customer = json.objectOrNull("customer")
            // Merge whichever objects exist into one lookup for the summary parse.
            val merged = JSONObject()
            for (src in listOfNotNull(json, loan, customer)) {
                val it = src.keys()
                while (it.hasNext()) {
                    val k = it.next()
                    if (src.isNull(k)) continue
                    val v = src.opt(k)
                    if (v is JSONObject || v is JSONArray) continue
                    merged.put(k, v)
                }
            }
            return LoanDetail(
                summary = CustomerSummary.from(merged),
                mobile = merged.stringOrNull("mobile")
                    ?: merged.stringOrNull("mobile_number")
                    ?: merged.stringOrNull("phone"),
                address = merged.stringOrNull("address"),
                visits = json.arrayOrNull("visits").mapObjects { VisitSummary.from(it) },
                recoveries = json.arrayOrNull("recoveries").mapObjects { RecoverySummary.from(it) },
                followups = json.arrayOrNull("followups").mapObjects { Followup.from(it) },
            )
        }
    }
}

/** `GET /followups` row. */
class Followup(
    val id: Int,
    val loanId: Int,
    val dueDate: String?,
    val customerName: String?,
    val remarks: String?,
) {
    companion object {
        fun from(json: JSONObject) = Followup(
            id = json.intOr("id", 0),
            loanId = json.intOr("loan_id", 0),
            dueDate = json.stringOrNull("due_date") ?: json.stringOrNull("followup_date"),
            customerName = json.stringOrNull("full_name") ?: json.stringOrNull("customer_name"),
            remarks = json.stringOrNull("remarks"),
        )
    }
}

/** `POST /visits` response. */
class VisitSubmitResult(
    val visitId: Int,
    val visitUid: String?,
    val photosSaved: Int,
    val photoErrors: List<String>,
    val distanceFromCustomerM: Int?,
    /** True when the server recognised the idempotency key — treat as success. */
    val duplicate: Boolean,
    val followupCreated: Boolean,
) {
    companion object {
        fun from(json: JSONObject) = VisitSubmitResult(
            visitId = json.intOr("visit_id", 0),
            visitUid = json.stringOrNull("visit_uid"),
            photosSaved = json.intOr("photos_saved", 0),
            photoErrors = json.arrayOrNull("photo_errors")
                ?.let { arr -> (0 until arr.length()).mapNotNull { arr.optString(it, null) } }
                .orEmpty(),
            distanceFromCustomerM = json.optIntOrNull("distance_from_customer_m"),
            duplicate = json.boolOr("duplicate", false),
            followupCreated = json.boolOr("followup_created", false),
        )
    }
}

/** `POST /recoveries` response. */
class RecoverySubmitResult(
    val recoveryId: Int,
    val receiptNumber: String?,
    val status: String?,
    val duplicate: Boolean,
    val loanOutstandingAfter: Double?,
) {
    companion object {
        fun from(json: JSONObject) = RecoverySubmitResult(
            recoveryId = json.intOr("recovery_id", 0),
            receiptNumber = json.stringOrNull("receipt_number"),
            status = json.stringOrNull("status"),
            duplicate = json.boolOr("duplicate", false),
            loanOutstandingAfter = json.doubleOrNull("loan_outstanding_after"),
        )
    }
}

/** `GET /attendance/today`. */
class AttendanceToday(
    val checkedIn: Boolean,
    val checkInAt: String?,
    val checkedOut: Boolean,
    val checkOutAt: String?,
) {
    companion object {
        fun from(json: JSONObject) = AttendanceToday(
            checkedIn = json.boolOr("checked_in", false),
            checkInAt = json.stringOrNull("check_in_at"),
            checkedOut = json.boolOr("checked_out", false),
            checkOutAt = json.stringOrNull("check_out_at"),
        )
    }
}
