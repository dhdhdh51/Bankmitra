package com.lrms.recovery.data.prefs

import android.content.Context
import android.content.SharedPreferences
import java.util.UUID

/**
 * Non-secret device-scoped settings.
 *
 * The server base URL is deliberately NOT compiled into the APK: every bank that
 * installs LRMS has its own host, and the same APK has to work for all of them.
 * The user types it on the login screen (or edits it in Settings) and it lives
 * here.
 */
class AppPrefs private constructor(context: Context) {

    private val prefs: SharedPreferences =
        context.applicationContext.getSharedPreferences(FILE, Context.MODE_PRIVATE)

    companion object {
        private const val FILE = "lrms_prefs"
        private const val KEY_SERVER_URL = "server_url"
        private const val KEY_DEVICE_ID = "device_id"
        private const val KEY_LAST_IDENTIFIER = "last_identifier"
        private const val KEY_GPS_PING_INTERVAL = "gps_ping_interval_seconds"

        @Volatile
        private var instance: AppPrefs? = null

        fun get(context: Context): AppPrefs =
            instance ?: synchronized(this) {
                instance ?: AppPrefs(context).also { instance = it }
            }
    }

    /** Empty by default — the login screen refuses to submit until it is set. */
    var serverUrl: String
        get() = prefs.getString(KEY_SERVER_URL, "").orEmpty()
        set(value) = prefs.edit().putString(KEY_SERVER_URL, value.trim()).apply()

    /**
     * Stable installation UUID sent as `X-Device-Id` and used for the server's
     * device-binding check. Generated once, on first access, and never changes
     * for the life of the install.
     */
    val deviceId: String
        get() {
            prefs.getString(KEY_DEVICE_ID, null)?.takeIf { it.isNotBlank() }?.let { return it }
            val fresh = UUID.randomUUID().toString()
            prefs.edit().putString(KEY_DEVICE_ID, fresh).apply()
            return fresh
        }

    /** Pre-fills the login field so agents do not retype their mobile daily. */
    var lastIdentifier: String
        get() = prefs.getString(KEY_LAST_IDENTIFIER, "").orEmpty()
        set(value) = prefs.edit().putString(KEY_LAST_IDENTIFIER, value.trim()).apply()

    /**
     * From `config.gps_ping_interval_seconds`. Cached outside the session store
     * so the tracking worker can still schedule itself after a cold start.
     */
    var gpsPingIntervalSeconds: Int
        get() = prefs.getInt(KEY_GPS_PING_INTERVAL, 300)
        set(value) = prefs.edit().putInt(KEY_GPS_PING_INTERVAL, value).apply()
}
