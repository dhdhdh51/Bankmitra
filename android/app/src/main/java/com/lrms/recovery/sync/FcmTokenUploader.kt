package com.lrms.recovery.sync

import android.content.Context
import android.util.Log
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.prefs.SessionStore
import com.lrms.recovery.data.repo.LrmsRepository
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.launch

/**
 * Push-notification token plumbing for `POST /me/fcm-token`.
 *
 * This is the ONLY piece of FCM wiring in the app, and it is deliberately
 * decoupled from Firebase: nothing here imports a Firebase class, so the project
 * compiles and ships with no `google-services.json` and no google-services Gradle
 * plugin. See the commented block at the bottom of app/build.gradle.kts and the
 * "Optional: push notifications" section of android/README.md for how to turn FCM
 * on later - it is a `FirebaseMessagingService` subclass whose `onNewToken` calls
 * [upload], plus two Gradle lines. No other code changes.
 *
 * Until then this method is simply never called, which is harmless.
 */
object FcmTokenUploader {

    private const val TAG = "FcmTokenUploader"
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.IO)

    fun upload(context: Context, fcmToken: String) {
        if (fcmToken.isBlank()) return
        if (!SessionStore.get(context).isSignedIn) return
        scope.launch {
            when (val result = LrmsRepository.get(context).uploadFcmToken(fcmToken)) {
                is ApiResult.Success ->
                    // Never log the token itself.
                    Log.i(TAG, "Push token registered with the server.")
                is ApiResult.Failure ->
                    Log.w(TAG, "Push token registration failed: ${result.code}")
            }
        }
    }
}
