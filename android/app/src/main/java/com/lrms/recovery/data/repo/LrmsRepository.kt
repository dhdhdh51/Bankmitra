package com.lrms.recovery.data.repo

import android.content.Context
import com.lrms.recovery.data.model.AttendanceToday
import com.lrms.recovery.data.model.CustomerSummary
import com.lrms.recovery.data.model.Dashboard
import com.lrms.recovery.data.model.Followup
import com.lrms.recovery.data.model.InviteInfo
import com.lrms.recovery.data.model.LoanDetail
import com.lrms.recovery.data.model.OtpChallenge
import com.lrms.recovery.data.model.PingInfo
import com.lrms.recovery.data.model.RecoveryDraft
import com.lrms.recovery.data.model.RecoverySubmitResult
import com.lrms.recovery.data.model.Session
import com.lrms.recovery.data.model.TrackingPing
import com.lrms.recovery.data.model.VisitDraft
import com.lrms.recovery.data.model.VisitSubmitResult
import com.lrms.recovery.data.net.ApiClient
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.net.ErrorCodes
import com.lrms.recovery.data.net.FilePart
import com.lrms.recovery.data.net.discardData
import com.lrms.recovery.data.net.mapArray
import com.lrms.recovery.data.net.mapObject
import com.lrms.recovery.data.net.mapObjects
import com.lrms.recovery.data.prefs.AppPrefs
import com.lrms.recovery.data.prefs.SessionStore
import com.lrms.recovery.util.DeviceInfo
import org.json.JSONArray
import org.json.JSONObject
import java.io.File

/**
 * One place that knows every endpoint in docs/API.md.
 *
 * Activities and Workers only ever talk to this class; nothing else builds a
 * JSON body or a query string. Every method is `suspend` and returns an
 * [ApiResult], so no call site can forget to handle failure.
 */
class LrmsRepository private constructor(context: Context) {

    private val app = context.applicationContext
    private val api = ApiClient.get(app)
    private val session = SessionStore.get(app)
    private val prefs = AppPrefs.get(app)

    companion object {
        @Volatile
        private var instance: LrmsRepository? = null

        fun get(context: Context): LrmsRepository =
            instance ?: synchronized(this) {
                instance ?: LrmsRepository(context).also { instance = it }
            }

        /**
         * Guesses `identifier_type` the way the backend expects it. A value made
         * only of digits (10-15 of them) is a mobile; anything with an `@` is an
         * email; everything else is an employee code, which only `/auth/login`
         * accepts.
         */
        fun identifierType(identifier: String): String = when {
            identifier.contains('@') -> "email"
            identifier.trim().matches(Regex("""\d{10,15}""")) -> "mobile"
            else -> "employee_code"
        }
    }

    /** Device fields every auth request in docs/API.md carries. */
    private fun deviceFields(): JSONObject = JSONObject().apply {
        put("device_id", prefs.deviceId)
        put("device_model", DeviceInfo.model)
        put("os_version", DeviceInfo.osVersion)
        put("app_version", DeviceInfo.appVersion)
    }

    private fun JSONObject.merge(other: JSONObject): JSONObject {
        val it = other.keys()
        while (it.hasNext()) {
            val k = it.next()
            put(k, other.opt(k))
        }
        return this
    }

    // ------------------------------------------------------------------ public

    suspend fun ping(): ApiResult<PingInfo> = api.get("/ping").mapObject { PingInfo.from(it) }

    suspend fun validateInvite(code: String): ApiResult<InviteInfo> =
        api.postJson("/auth/invite/validate", JSONObject().put("code", code.trim()))
            .mapObject { InviteInfo.from(it) }

    suspend fun requestOtp(
        identifier: String,
        purpose: String,
    ): ApiResult<OtpChallenge> {
        val type = identifierType(identifier)
        // Employee codes cannot receive an OTP; fall back to email (the default
        // channel) so the server returns a proper validation error, not a 500.
        val body = JSONObject()
            .put("identifier", identifier.trim())
            .put("identifier_type", if (type == "employee_code") "email" else type)
            .put("purpose", purpose)
        return api.postJson("/auth/otp/request", body).mapObject { OtpChallenge.from(it) }
    }

    suspend fun verifyOtp(
        identifier: String,
        otp: String,
        purpose: String = "login",
    ): ApiResult<Session> {
        val type = identifierType(identifier)
        val body = JSONObject()
            .put("identifier", identifier.trim())
            .put("identifier_type", if (type == "employee_code") "email" else type)
            .put("purpose", purpose)
            .put("otp", otp.trim())
            .merge(deviceFields())
        return api.postJson("/auth/otp/verify", body)
            .mapObject { Session.from(it) }
            .alsoPersistSession()
    }

    suspend fun loginWithPassword(identifier: String, password: String): ApiResult<Session> {
        val body = JSONObject()
            .put("identifier", identifier.trim())
            .put("password", password)
            .merge(deviceFields())
        return api.postJson("/auth/login", body)
            .mapObject { Session.from(it) }
            .alsoPersistSession()
    }

    /**
     * @param identifier the email address the OTP was sent to - this is the
     *                   account identity
     * @param mobile     optional, only used for SMS reminders
     */
    suspend fun register(
        inviteCode: String,
        fullName: String,
        identifier: String,
        otp: String,
        mobile: String?,
        password: String,
        employeeCode: String?,
    ): ApiResult<Session> {
        val type = identifierType(identifier)
        val body = JSONObject()
            .put("invite_code", inviteCode.trim())
            .put("full_name", fullName.trim())
            .put("identifier", identifier.trim())
            .put("identifier_type", if (type == "employee_code") "email" else type)
            .put("otp", otp.trim())
            .put("password", password)
            .merge(deviceFields())
        mobile?.takeIf { it.isNotBlank() }?.let { body.put("mobile", it.trim()) }
        employeeCode?.takeIf { it.isNotBlank() }?.let {
            body.put("employee_code", it.trim())
            body.put("bc_code", it.trim())
        }
        return api.postJson("/auth/register", body)
            .mapObject { Session.from(it) }
            .alsoPersistSession()
    }

    /** Refreshes the cached user + config. Returns the config's Maps key holder. */
    suspend fun me(): ApiResult<Session> =
        api.get("/me").mapObject { json ->
            // /me returns the same user+config pair but no token; keep the
            // existing token so the session is not invalidated locally.
            val merged = JSONObject(json.toString()).put("token", session.token.orEmpty())
            Session.from(merged)
        }.also { result ->
            if (result is ApiResult.Success) {
                session.updateProfile(result.data.rawUserJson, result.data.rawConfigJson)
                prefs.gpsPingIntervalSeconds = result.data.config.gpsPingIntervalSeconds
            }
        }

    suspend fun logout(): ApiResult<Unit> = api.postEmpty("/auth/logout").discardData()

    suspend fun changePassword(current: String, new: String): ApiResult<Unit> =
        api.postJson(
            "/me/password",
            JSONObject().put("current_password", current).put("new_password", new),
        ).discardData()

    suspend fun uploadFcmToken(fcmToken: String): ApiResult<Unit> =
        api.postJson(
            "/me/fcm-token",
            JSONObject().put("fcm_token", fcmToken).put("device_id", prefs.deviceId),
        ).discardData()

    suspend fun dashboard(): ApiResult<Dashboard> =
        api.get("/dashboard").mapObject { Dashboard.from(it) }

    /**
     * `GET /customers`. The [ApiResult.Success.meta] block is preserved so the
     * list screen can page with `meta.pages`.
     */
    suspend fun customers(
        search: String?,
        page: Int,
        perPage: Int = 25,
        village: String? = null,
        status: String? = null,
    ): ApiResult<List<CustomerSummary>> = api.get(
        "/customers",
        mapOf(
            "search" to search,
            "village" to village,
            "status" to status,
            "page" to page.toString(),
            "per_page" to perPage.coerceIn(1, 200).toString(),
        ),
    ).mapArray { CustomerSummary.listFrom(it) }

    suspend fun loanDetail(loanId: Int): ApiResult<LoanDetail> =
        api.get("/loans/$loanId").mapObject { LoanDetail.from(it) }

    /**
     * Submits a visit. Photos are attached as repeated `photos[]` parts and the
     * signature travels as a plain base64 form field, exactly as documented.
     */
    suspend fun submitVisit(draft: VisitDraft): ApiResult<VisitSubmitResult> {
        // photo_types[i] has to line up with photos[i], so the two lists are
        // paired BEFORE dropping missing files. Filtering them separately would
        // shift the tags by one as soon as a single capture had gone missing.
        val pairs = draft.photoPaths
            .mapIndexed { i, path -> File(path) to (draft.photoTypes.getOrNull(i) ?: "house") }
            .filter { (file, _) -> file.exists() && file.length() > 0 }

        return api.postMultipart(
            path = "/visits",
            fields = draft.toFields(),
            files = pairs.map { (file, _) -> FilePart("photos[]", file) },
            repeated = pairs.map { (_, type) -> "photo_types[]" to type },
        ).mapObject { VisitSubmitResult.from(it) }
    }

    suspend fun submitRecovery(draft: RecoveryDraft): ApiResult<RecoverySubmitResult> {
        val files = draft.receiptPhotoPath
            ?.let { File(it) }
            ?.takeIf { it.exists() && it.length() > 0 }
            ?.let { listOf(FilePart("receipt_photo", it)) }
            .orEmpty()
        return api.postMultipart("/recoveries", draft.toFields(), files)
            .mapObject { RecoverySubmitResult.from(it) }
    }

    suspend fun checkIn(
        latitude: Double,
        longitude: Double,
        accuracyM: Double?,
        selfie: File?,
        remarks: String?,
    ): ApiResult<Unit> = api.postMultipart(
        "/attendance/check-in",
        mapOf(
            "latitude" to latitude.toString(),
            "longitude" to longitude.toString(),
            "accuracy_m" to accuracyM?.toString(),
            "remarks" to remarks?.takeIf { it.isNotBlank() },
        ),
        selfie?.takeIf { it.exists() }?.let { listOf(FilePart("selfie", it)) }.orEmpty(),
    ).discardData()

    suspend fun checkOut(
        latitude: Double,
        longitude: Double,
        selfie: File?,
    ): ApiResult<Unit> = api.postMultipart(
        "/attendance/check-out",
        mapOf(
            "latitude" to latitude.toString(),
            "longitude" to longitude.toString(),
        ),
        selfie?.takeIf { it.exists() }?.let { listOf(FilePart("selfie", it)) }.orEmpty(),
    ).discardData()

    suspend fun attendanceToday(): ApiResult<AttendanceToday> =
        api.get("/attendance/today").mapObject { AttendanceToday.from(it) }

    /** Batched tracking pings. Empty batches are not sent. */
    suspend fun trackingPing(pings: List<TrackingPing>): ApiResult<Unit> {
        if (pings.isEmpty()) return ApiResult.Success(Unit, "Nothing to send")
        val arr = JSONArray()
        pings.forEach { arr.put(it.toJson()) }
        return api.postJson("/tracking/ping", JSONObject().put("pings", arr)).discardData()
    }

    suspend fun followups(due: String?, page: Int = 1): ApiResult<List<Followup>> =
        api.get("/followups", mapOf("due" to due, "page" to page.toString()))
            .mapArray { arr -> arr.mapObjects { Followup.from(it) } }

    suspend fun completeFollowup(id: Int, remarks: String?): ApiResult<Unit> =
        api.postJson(
            "/followups/$id/complete",
            JSONObject().put("remarks", remarks.orEmpty()),
        ).discardData()

    // ----------------------------------------------------------------- helpers

    /**
     * Persists a freshly-issued session. Kept private and applied inside the
     * repository so no screen can obtain a token and forget to store it.
     */
    private fun ApiResult<Session>.alsoPersistSession(): ApiResult<Session> {
        if (this is ApiResult.Success && data.token.isNotBlank()) {
            session.save(data)
        }
        return this
    }

    /**
     * Central handling for "the server says our token is gone". Call sites use
     * this to decide whether to bounce the user to the login screen.
     */
    fun handleAuthFailure(failure: ApiResult.Failure): Boolean {
        val gone = failure.code in ErrorCodes.SESSION_GONE
        if (gone) session.clear()
        return gone
    }
}
