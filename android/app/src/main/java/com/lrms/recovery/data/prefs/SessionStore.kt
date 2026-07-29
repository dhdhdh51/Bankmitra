package com.lrms.recovery.data.prefs

import android.content.Context
import android.content.SharedPreferences
import android.util.Log
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey
import com.lrms.recovery.data.model.AppConfig
import com.lrms.recovery.data.model.Session
import com.lrms.recovery.data.model.UserProfile
import org.json.JSONException
import org.json.JSONObject

/**
 * Holds the bearer token, the signed-in user and the runtime config block.
 *
 * SECURITY
 * --------
 * The token is written to [EncryptedSharedPreferences] (AES-256, key held in the
 * Android Keystore). Some OEM builds ship a broken/absent keystore and
 * `EncryptedSharedPreferences.create` throws there; rather than making the app
 * unusable on those handsets we fall back to a plain SharedPreferences file and
 * record that we did. The fallback is a deliberate, documented trade-off:
 * an unusable app is worse than a token at rest in app-private storage, which is
 * still unreadable without root.
 *
 * Nothing in this class is ever logged. Not the token, not the OTP (which is
 * never stored at all), not the password (never stored at all).
 */
class SessionStore private constructor(context: Context) {

    private val app = context.applicationContext
    private val prefs: SharedPreferences
    /** True when we had to drop to unencrypted storage. Surfaced on the Settings screen. */
    val usingEncryptedStorage: Boolean

    init {
        var encrypted = true
        val store = try {
            val masterKey = MasterKey.Builder(app)
                .setKeyScheme(MasterKey.KeyScheme.AES256_GCM)
                .build()
            EncryptedSharedPreferences.create(
                app,
                FILE_ENCRYPTED,
                masterKey,
                EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
                EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
            )
        } catch (e: Exception) {
            // Broken/absent Android Keystore. Log the FACT, never the contents.
            Log.w(TAG, "Encrypted preferences unavailable, using plain storage: ${e.javaClass.simpleName}")
            encrypted = false
            app.getSharedPreferences(FILE_PLAIN, Context.MODE_PRIVATE)
        }
        prefs = store
        usingEncryptedStorage = encrypted
    }

    companion object {
        private const val TAG = "SessionStore"
        private const val FILE_ENCRYPTED = "lrms_session"
        private const val FILE_PLAIN = "lrms_session_plain"
        private const val KEY_TOKEN = "token"
        private const val KEY_EXPIRES_AT = "expires_at"
        private const val KEY_USER = "user_json"
        private const val KEY_CONFIG = "config_json"

        @Volatile
        private var instance: SessionStore? = null

        fun get(context: Context): SessionStore =
            instance ?: synchronized(this) {
                instance ?: SessionStore(context).also { instance = it }
            }
    }

    val token: String?
        get() = prefs.getString(KEY_TOKEN, null)?.takeIf { it.isNotBlank() }

    val isSignedIn: Boolean get() = token != null

    val expiresAt: String? get() = prefs.getString(KEY_EXPIRES_AT, null)

    val user: UserProfile?
        get() = parse(prefs.getString(KEY_USER, null))?.let { UserProfile.from(it) }

    /** Falls back to [AppConfig.DEFAULT] so callers never have to null-check. */
    val config: AppConfig
        get() = AppConfig.from(parse(prefs.getString(KEY_CONFIG, null)))

    fun save(session: Session) {
        prefs.edit()
            .putString(KEY_TOKEN, session.token)
            .putString(KEY_EXPIRES_AT, session.expiresAt)
            .putString(KEY_USER, session.rawUserJson)
            .putString(KEY_CONFIG, session.rawConfigJson)
            .apply()
        // Mirror the ping interval where the tracking worker can read it after a
        // cold start without unlocking the encrypted store.
        AppPrefs.get(app).gpsPingIntervalSeconds = session.config.gpsPingIntervalSeconds
    }

    /** `GET /me` returns the same user+config pair; refresh without touching the token. */
    fun updateProfile(userJson: String?, configJson: String?) {
        prefs.edit().apply {
            if (userJson != null) putString(KEY_USER, userJson)
            if (configJson != null) putString(KEY_CONFIG, configJson)
        }.apply()
    }

    /**
     * Wipes the session. Called on explicit logout AND whenever the server
     * answers `unauthenticated` / `token_expired`.
     */
    fun clear() {
        prefs.edit().clear().apply()
    }

    private fun parse(raw: String?): JSONObject? {
        if (raw.isNullOrBlank()) return null
        return try {
            JSONObject(raw)
        } catch (e: JSONException) {
            null
        }
    }
}
