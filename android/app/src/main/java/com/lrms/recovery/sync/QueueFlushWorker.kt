package com.lrms.recovery.sync

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters

/**
 * Drains the offline queue when connectivity comes back.
 *
 * Scheduled by [SyncScheduler] with a `NetworkType.CONNECTED` constraint, so
 * WorkManager itself is what "waits for the network" - the app never polls.
 *
 * Returning [Result.retry] hands the backoff schedule to WorkManager (exponential,
 * starting at 30 s) instead of reinventing it.
 */
class QueueFlushWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val report = try {
            QueueFlusher.flush(applicationContext)
        } catch (e: Exception) {
            // A worker crash is invisible to the user, so log it and let
            // WorkManager retry rather than silently losing field work.
            Log.e(TAG, "Queue flush pass threw ${e.javaClass.simpleName}", e)
            return Result.retry()
        }

        Log.i(
            TAG,
            "Flush: attempted=${report.attempted} ok=${report.succeeded} " +
                "duplicate=${report.duplicates} retry=${report.keptForRetry} " +
                "failed=${report.permanentlyFailed}",
        )
        return if (report.hasWorkLeft) Result.retry() else Result.success()
    }

    companion object {
        private const val TAG = "QueueFlushWorker"
    }
}
