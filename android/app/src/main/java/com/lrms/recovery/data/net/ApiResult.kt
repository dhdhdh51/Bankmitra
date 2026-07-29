package com.lrms.recovery.data.net

/**
 * Pagination block from the response envelope (`meta`).
 *
 * `pages` is what the customer list uses to decide whether another page exists,
 * exactly as documented in docs/API.md.
 */
class Meta(
    val total: Int,
    val page: Int,
    val perPage: Int,
    val pages: Int,
)

/**
 * Every API call resolves to exactly one of these.
 *
 * [Failure] always carries the STABLE `code` string from docs/API.md (never a
 * localised message) so call sites can branch on behaviour, plus the
 * server-supplied human message which is what we actually show the user.
 */
sealed class ApiResult<out T> {

    class Success<out T>(
        val data: T,
        val message: String,
        val meta: Meta? = null,
    ) : ApiResult<T>()

    class Failure(
        /** One of [ErrorCodes]. Never empty. */
        val code: String,
        /** Human-readable, safe to show in a dialog. Never empty. */
        val message: String,
        /** Field-level errors from `errors`, keyed by field name. */
        val fieldErrors: Map<String, String> = emptyMap(),
        /** HTTP status, or 0 when the request never reached the server. */
        val httpStatus: Int = 0,
        /** From `data.retry_after_seconds` on throttled responses. */
        val retryAfterSeconds: Int = 0,
        /** From `data.apk_url` on `update_required`. */
        val apkUrl: String? = null,
    ) : ApiResult<Nothing>()

    /** True when retrying later could plausibly succeed (keeps queue rows alive). */
    val isTransient: Boolean
        get() = this is Failure && code in ErrorCodes.TRANSIENT
}

/**
 * Stable `code` values from docs/API.md section 1, plus three client-only codes
 * for failures that never reach the server. Client codes are prefixed so they
 * can never collide with a future server code.
 */
object ErrorCodes {
    const val VALIDATION_FAILED = "validation_failed"
    const val UNAUTHENTICATED = "unauthenticated"
    const val TOKEN_EXPIRED = "token_expired"
    const val DEVICE_MISMATCH = "device_mismatch"
    const val ACCOUNT_PENDING = "account_pending"
    const val ACCOUNT_SUSPENDED = "account_suspended"
    const val FORBIDDEN = "forbidden"
    const val NOT_FOUND = "not_found"
    const val OTP_INVALID = "otp_invalid"
    const val OTP_EXPIRED = "otp_expired"
    const val OTP_THROTTLED = "otp_throttled"
    const val OTP_DELIVERY_FAILED = "otp_delivery_failed"
    const val RATE_LIMITED = "rate_limited"
    const val GPS_REQUIRED = "gps_required"
    const val MOCK_LOCATION_BLOCKED = "mock_location_blocked"
    const val PHOTO_REQUIRED = "photo_required"
    const val DUPLICATE = "duplicate"
    const val MAINTENANCE_MODE = "maintenance_mode"
    const val UPDATE_REQUIRED = "update_required"
    const val CSRF_INVALID = "csrf_invalid"
    const val SERVER_ERROR = "server_error"

    /** Client-side: the device could not reach the server at all. */
    const val CLIENT_NETWORK = "client_network"
    /** Client-side: connect/read timeout. */
    const val CLIENT_TIMEOUT = "client_timeout"
    /** Client-side: response was not the documented envelope. */
    const val CLIENT_BAD_RESPONSE = "client_bad_response"
    /** Client-side: no server URL configured yet. */
    const val CLIENT_NO_SERVER_URL = "client_no_server_url"

    /**
     * Failures where the offline queue should KEEP the row and retry later.
     * Everything else is a permanent rejection and the row is moved to `failed`
     * so the agent can see it on the sync screen instead of it retrying forever.
     */
    val TRANSIENT: Set<String> = setOf(
        CLIENT_NETWORK,
        CLIENT_TIMEOUT,
        SERVER_ERROR,
        MAINTENANCE_MODE,
        RATE_LIMITED,
    )

    /** Failures that mean "the session is gone, go back to the login screen". */
    val SESSION_GONE: Set<String> = setOf(UNAUTHENTICATED, TOKEN_EXPIRED)
}
