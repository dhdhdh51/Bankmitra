package com.lrms.recovery.sync

import android.content.Context
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit

/** Everything WorkManager-related lives here so scheduling policy is in one file. */
object SyncScheduler {

    private const val WORK_QUEUE_FLUSH = "lrms_queue_flush"
    private const val WORK_TRACKING = "lrms_tracking_ping"

    /**
     * WorkManager's hard floor for periodic work. Documented as a constant rather
     * than a magic number because the server's `config.gps_ping_interval_seconds`
     * is frequently 300 (5 minutes) and the clamp below silently changes the
     * effective behaviour - see [scheduleTracking].
     */
    private const val WORKMANAGER_MIN_PERIOD_MINUTES = 15L

    private val networkRequired = Constraints.Builder()
        .setRequiredNetworkType(NetworkType.CONNECTED)
        .build()

    /**
     * Asks WorkManager to drain the queue as soon as there is a network.
     * Cheap and idempotent: repeated calls coalesce onto the existing request.
     */
    fun requestQueueFlush(context: Context) {
        val request = OneTimeWorkRequestBuilder<QueueFlushWorker>()
            .setConstraints(networkRequired)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(
            WORK_QUEUE_FLUSH,
            // KEEP: if a flush is already queued or running, do not start a second.
            ExistingWorkPolicy.KEEP,
            request,
        )
    }

    /** Used by the "Retry all" button: replaces any pending flush and starts now. */
    fun forceQueueFlush(context: Context) {
        val request = OneTimeWorkRequestBuilder<QueueFlushWorker>()
            .setConstraints(networkRequired)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 30, TimeUnit.SECONDS)
            .build()
        WorkManager.getInstance(context).enqueueUniqueWork(
            WORK_QUEUE_FLUSH,
            ExistingWorkPolicy.REPLACE,
            request,
        )
    }

    /**
     * Schedules the GPS breadcrumb worker.
     *
     * IMPORTANT CLAMP
     * ---------------
     * The admin panel can set `config.gps_ping_interval_seconds` to anything, and
     * 300 (5 minutes) is the documented example. WorkManager REFUSES periodic
     * intervals below 15 minutes - it silently raises them - so a 5-minute setting
     * cannot be honoured by a periodic worker. We clamp explicitly and log the
     * effective value so the behaviour is visible rather than mysterious.
     *
     * If sub-15-minute tracking is ever a real requirement it needs a foreground
     * service with a persistent notification, which is a product decision (battery
     * drain, Play Store policy, and an always-visible "we are tracking you"
     * notice), not something to sneak in here.
     */
    fun scheduleTracking(context: Context, configuredIntervalSeconds: Int) {
        val requestedMinutes = (configuredIntervalSeconds / 60L).coerceAtLeast(1L)
        val effectiveMinutes = requestedMinutes.coerceAtLeast(WORKMANAGER_MIN_PERIOD_MINUTES)

        val request = PeriodicWorkRequestBuilder<TrackingPingWorker>(
            effectiveMinutes,
            TimeUnit.MINUTES,
        )
            .setConstraints(networkRequired)
            .setBackoffCriteria(BackoffPolicy.EXPONENTIAL, 60, TimeUnit.SECONDS)
            .build()

        WorkManager.getInstance(context).enqueueUniquePeriodicWork(
            WORK_TRACKING,
            // UPDATE keeps the existing schedule when the interval has not changed,
            // so signing in does not reset the timer every time.
            ExistingPeriodicWorkPolicy.UPDATE,
            request,
        )
    }

    /** The interval the tracking worker will actually run at, for the Settings screen. */
    fun effectiveTrackingMinutes(configuredIntervalSeconds: Int): Long =
        (configuredIntervalSeconds / 60L).coerceAtLeast(1L).coerceAtLeast(WORKMANAGER_MIN_PERIOD_MINUTES)

    /** Called on logout: stop tracking, but LEAVE the queue flush alone. */
    fun cancelTracking(context: Context) {
        WorkManager.getInstance(context).cancelUniqueWork(WORK_TRACKING)
    }
}
