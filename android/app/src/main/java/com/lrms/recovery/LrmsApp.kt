package com.lrms.recovery

import android.app.Application
import android.app.NotificationChannel
import android.app.NotificationManager
import android.os.Build
import androidx.appcompat.app.AppCompatDelegate
import com.lrms.recovery.data.prefs.AppPrefs
import com.lrms.recovery.data.prefs.SessionStore
import com.lrms.recovery.sync.SyncScheduler
import com.lrms.recovery.util.CrashReporter

/**
 * Application entry point.
 *
 * Two jobs only, both of which must happen even when the user never opens a
 * particular screen:
 *
 *  1. Re-arm the background workers after a reboot or a process death. WorkManager
 *     persists its own queue, but the tracking interval comes from the server
 *     config and is cached in [AppPrefs], so the schedule is refreshed here.
 *  2. Create the notification channel the FCM payloads target (see
 *     `android:channel_id` "lrms_default" used by the backend's Fcm.php), so a
 *     push that arrives before any Activity has run is still shown.
 */
class LrmsApp : Application() {

    override fun onCreate() {
        super.onCreate()

        // First thing, before anything that could fail: capture fatal crashes to
        // a file so they can be read on the handset without a PC and logcat.
        CrashReporter.install(this)

        // The app ships one light palette and no values-night/ counterpart, and
        // many layouts name @color/text_primary and @color/surface directly. If
        // AppCompat is allowed to follow the system into dark mode, those stay
        // light while every Material attribute we did not override flips dark,
        // which produced dark text on dark surfaces on any handset with dark mode
        // enabled. Pin it. Theme.Lrms is also Light rather than DayNight.
        AppCompatDelegate.setDefaultNightMode(AppCompatDelegate.MODE_NIGHT_NO)

        createNotificationChannel()

        // Nudge the offline queue: if the device died mid-sync, anything still
        // queued should go out as soon as there is a network.
        SyncScheduler.requestQueueFlush(this)

        // Only track a signed-in agent. A logged-out install must not ping.
        if (SessionStore.get(this).isSignedIn) {
            SyncScheduler.scheduleTracking(this, AppPrefs.get(this).gpsPingIntervalSeconds)
        } else {
            SyncScheduler.cancelTracking(this)
        }
    }

    private fun createNotificationChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return

        val channel = NotificationChannel(
            CHANNEL_DEFAULT,
            getString(R.string.app_name),
            NotificationManager.IMPORTANCE_DEFAULT,
        ).apply {
            description = "Follow-up reminders and recovery alerts"
            enableVibration(true)
        }

        getSystemService(NotificationManager::class.java)?.createNotificationChannel(channel)
    }

    companion object {
        /** Must match the channel_id sent by the server in Lib\Fcm. */
        const val CHANNEL_DEFAULT = "lrms_default"
    }
}
