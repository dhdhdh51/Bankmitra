package com.lrms.recovery.data.net

import android.content.Context
import android.util.Log
import com.lrms.recovery.BuildConfig
import com.lrms.recovery.data.prefs.AppPrefs
import com.lrms.recovery.data.prefs.SessionStore
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.json.JSONException
import org.json.JSONObject
import java.io.BufferedOutputStream
import java.io.File
import java.io.FileInputStream
import java.io.IOException
import java.io.InputStream
import java.net.HttpURLConnection
import java.net.MalformedURLException
import java.net.SocketTimeoutException
import java.net.URL
import java.net.URLEncoder
import java.util.UUID

/** The decoded `data` member of the envelope. It may be an object, an array, or absent. */
class ApiData(private val raw: Any?) {
    val asObject: JSONObject? get() = raw as? JSONObject
    val asArray: org.json.JSONArray? get() = raw as? org.json.JSONArray
}

/** A file to attach to a multipart request. */
class FilePart(
    /** Wire field name, e.g. `photos[]`, `selfie`, `receipt_photo`. */
    val field: String,
    val file: File,
    val mimeType: String = "image/jpeg",
)

/**
 * The whole network layer: HttpURLConnection + org.json, nothing else.
 *
 * Rationale for not using Retrofit/OkHttp/Gson: this APK is sideloaded onto
 * low-end handsets over patchy rural connections, the API surface is small and
 * hand-written, and every dependency we skip is one fewer thing that can break
 * a build the field team is waiting on.
 *
 * Threading: every public method is `suspend` and switches to [Dispatchers.IO].
 * There is no way to accidentally perform a request on the main thread.
 */
class ApiClient private constructor(context: Context) {

    private val app = context.applicationContext
    private val prefs = AppPrefs.get(app)
    private val session = SessionStore.get(app)

    companion object {
        private const val TAG = "ApiClient"
        private const val CONNECT_TIMEOUT_MS = 15_000
        private const val READ_TIMEOUT_MS = 30_000
        private const val API_SUFFIX = "/api/v1"

        @Volatile
        private var instance: ApiClient? = null

        fun get(context: Context): ApiClient =
            instance ?: synchronized(this) {
                instance ?: ApiClient(context).also { instance = it }
            }
    }

    // ---------------------------------------------------------------- public

    suspend fun get(path: String, query: Map<String, String?> = emptyMap()): ApiResult<ApiData> =
        withContext(Dispatchers.IO) {
            val qs = query.entries
                .filter { !it.value.isNullOrBlank() }
                .joinToString("&") { enc(it.key) + "=" + enc(it.value.orEmpty()) }
            val fullPath = if (qs.isEmpty()) path else "$path?$qs"
            execute("GET", fullPath) { /* no body */ }
        }

    suspend fun postJson(path: String, body: JSONObject): ApiResult<ApiData> =
        withContext(Dispatchers.IO) {
            val bytes = body.toString().toByteArray(Charsets.UTF_8)
            execute("POST", path) { conn ->
                conn.doOutput = true
                conn.setRequestProperty("Content-Type", "application/json; charset=utf-8")
                conn.setFixedLengthStreamingMode(bytes.size)
                BufferedOutputStream(conn.outputStream).use { it.write(bytes) }
            }
        }

    /** POST with no body (e.g. `/auth/logout`, `/followups/{id}/complete` with empty remarks). */
    suspend fun postEmpty(path: String): ApiResult<ApiData> = postJson(path, JSONObject())

    /**
     * multipart/form-data POST used by `/visits`, `/recoveries` and the two
     * `/attendance` endpoints. Streams the files straight off disk so a handful
     * of 8 MB photos never has to fit in memory at once.
     */
    suspend fun postMultipart(
        path: String,
        fields: Map<String, String?>,
        files: List<FilePart> = emptyList(),
    ): ApiResult<ApiData> = withContext(Dispatchers.IO) {
        val boundary = "----LRMSBoundary" + UUID.randomUUID().toString().replace("-", "")
        execute("POST", path) { conn ->
            conn.doOutput = true
            conn.setRequestProperty("Content-Type", "multipart/form-data; boundary=$boundary")
            conn.setChunkedStreamingMode(0)
            BufferedOutputStream(conn.outputStream).use { out ->
                val w = { s: String -> out.write(s.toByteArray(Charsets.UTF_8)) }
                for ((key, value) in fields) {
                    if (value == null) continue
                    w("--$boundary\r\n")
                    w("Content-Disposition: form-data; name=\"$key\"\r\n")
                    w("Content-Type: text/plain; charset=utf-8\r\n\r\n")
                    w(value)
                    w("\r\n")
                }
                for (part in files) {
                    if (!part.file.exists()) continue
                    w("--$boundary\r\n")
                    w(
                        "Content-Disposition: form-data; name=\"${part.field}\"; " +
                            "filename=\"${part.file.name}\"\r\n",
                    )
                    w("Content-Type: ${part.mimeType}\r\n\r\n")
                    FileInputStream(part.file).use { it.copyTo(out) }
                    w("\r\n")
                }
                w("--$boundary--\r\n")
            }
        }
    }

    // --------------------------------------------------------------- private

    private fun enc(s: String): String = URLEncoder.encode(s, "UTF-8")

    /** Builds the absolute URL, or null when no usable server URL is configured. */
    private fun buildUrl(path: String): URL? {
        val raw = prefs.serverUrl.trim().trimEnd('/')
        if (raw.isEmpty()) return null
        var base = raw
        if (!base.startsWith("http://", true) && !base.startsWith("https://", true)) {
            // Default to TLS. A user typing a bare hostname gets https, not http.
            base = "https://$base"
        }
        // Tolerate the admin pasting either "https://host" or "https://host/api/v1".
        if (!base.endsWith(API_SUFFIX)) base += API_SUFFIX
        return try {
            URL(base + path)
        } catch (e: MalformedURLException) {
            null
        }
    }

    private fun execute(
        method: String,
        path: String,
        writeBody: (HttpURLConnection) -> Unit,
    ): ApiResult<ApiData> {
        val url = buildUrl(path)
            ?: return ApiResult.Failure(
                code = ErrorCodes.CLIENT_NO_SERVER_URL,
                message = "No server URL is configured. Open Settings and enter the address " +
                    "given to you by your administrator.",
            )

        var conn: HttpURLConnection? = null
        return try {
            conn = (url.openConnection() as HttpURLConnection).apply {
                requestMethod = method
                connectTimeout = CONNECT_TIMEOUT_MS
                readTimeout = READ_TIMEOUT_MS
                useCaches = false
                setRequestProperty("Accept", "application/json")
                // Both auth headers are always sent: some shared hosts strip
                // `Authorization` before PHP sees it, and docs/API.md documents
                // `X-Auth-Token` as the fallback for exactly that case.
                session.token?.let { token ->
                    setRequestProperty("Authorization", "Bearer $token")
                    setRequestProperty("X-Auth-Token", token)
                }
                setRequestProperty("X-Device-Id", prefs.deviceId)
                setRequestProperty("X-App-Version", BuildConfig.APP_VERSION_NAME)
            }
            writeBody(conn)

            val status = conn.responseCode
            val body = readBody(conn)
            if (BuildConfig.DEBUG) {
                // Deliberately logs only the verb, path and status. Request and
                // response bodies are NEVER logged: they carry OTPs, passwords,
                // bearer tokens and customer PII.
                Log.d(TAG, "$method $path -> HTTP $status")
            }
            parseEnvelope(status, body)
        } catch (e: SocketTimeoutException) {
            ApiResult.Failure(
                code = ErrorCodes.CLIENT_TIMEOUT,
                message = "The server did not respond in time. Check your signal and try again.",
            )
        } catch (e: IOException) {
            ApiResult.Failure(
                code = ErrorCodes.CLIENT_NETWORK,
                message = "Could not reach the server. Check your internet connection and that " +
                    "the server URL is correct.",
            )
        } finally {
            conn?.disconnect()
        }
    }

    private fun readBody(conn: HttpURLConnection): String {
        // Non-2xx responses arrive on the error stream; the envelope is still there.
        val stream: InputStream? = try {
            conn.inputStream
        } catch (e: IOException) {
            conn.errorStream
        }
        return stream?.use { it.readBytes().toString(Charsets.UTF_8) }.orEmpty()
    }

    private fun parseEnvelope(status: Int, body: String): ApiResult<ApiData> {
        if (body.isBlank()) {
            return ApiResult.Failure(
                code = codeForStatus(status),
                message = "The server returned an empty response (HTTP $status).",
                httpStatus = status,
            )
        }
        val root = try {
            JSONObject(body)
        } catch (e: JSONException) {
            return ApiResult.Failure(
                code = ErrorCodes.CLIENT_BAD_RESPONSE,
                message = "The server sent a response the app could not understand " +
                    "(HTTP $status). Check that the server URL points at the LRMS API.",
                httpStatus = status,
            )
        }

        val success = root.boolOr("success", status in 200..299)
        val data = root.objectOrNull("data")

        if (success) {
            return ApiResult.Success(
                data = ApiData(if (root.has("data") && !root.isNull("data")) root.opt("data") else null),
                message = root.stringOr("message", "OK"),
                meta = root.objectOrNull("meta")?.let {
                    Meta(
                        total = it.intOr("total", 0),
                        page = it.intOr("page", 1),
                        perPage = it.intOr("perPage", 0),
                        pages = it.intOr("pages", 1),
                    )
                },
            )
        }

        val code = root.stringOrNull("code") ?: codeForStatus(status)
        // `maintenance_mode` puts the operator's text in `data.message`; prefer it.
        val message = when {
            code == ErrorCodes.MAINTENANCE_MODE && data?.stringOrNull("message") != null ->
                data.stringOr("message", "")
            else -> root.stringOrNull("message") ?: defaultMessageFor(code, status)
        }
        return ApiResult.Failure(
            code = code,
            message = message,
            fieldErrors = root.objectOrNull("errors").toFieldErrors(),
            httpStatus = status,
            retryAfterSeconds = data?.intOr("retry_after_seconds", 0) ?: 0,
            apkUrl = data?.stringOrNull("apk_url"),
        )
    }

    /** Best-effort mapping when the server did not send a `code`. */
    private fun codeForStatus(status: Int): String = when (status) {
        401 -> ErrorCodes.UNAUTHENTICATED
        403 -> ErrorCodes.FORBIDDEN
        404 -> ErrorCodes.NOT_FOUND
        409 -> ErrorCodes.DUPLICATE
        419 -> ErrorCodes.CSRF_INVALID
        422 -> ErrorCodes.VALIDATION_FAILED
        426 -> ErrorCodes.UPDATE_REQUIRED
        429 -> ErrorCodes.RATE_LIMITED
        503 -> ErrorCodes.MAINTENANCE_MODE
        in 500..599 -> ErrorCodes.SERVER_ERROR
        else -> ErrorCodes.CLIENT_BAD_RESPONSE
    }

    private fun defaultMessageFor(code: String, status: Int): String = when (code) {
        ErrorCodes.UNAUTHENTICATED, ErrorCodes.TOKEN_EXPIRED ->
            "Your session has expired. Please sign in again."
        ErrorCodes.DEVICE_MISMATCH ->
            "This account is registered on another device. Ask an administrator to reset it."
        ErrorCodes.FORBIDDEN -> "You do not have permission to do that."
        ErrorCodes.NOT_FOUND -> "That record could not be found on the server."
        ErrorCodes.SERVER_ERROR -> "The server hit an error. Please try again shortly."
        ErrorCodes.MAINTENANCE_MODE -> "The server is under maintenance. Please try again later."
        ErrorCodes.RATE_LIMITED -> "Too many attempts. Please wait a moment and try again."
        else -> "Request failed (HTTP $status)."
    }
}
