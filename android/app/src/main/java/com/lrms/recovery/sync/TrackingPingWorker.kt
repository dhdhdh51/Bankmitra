package com.lrms.recovery.sync

import android.content.Context
import android.util.Log
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import com.lrms.recovery.data.db.QueueDb
import com.lrms.recovery.data.db.QueueStore
import com.lrms.recovery.data.model.TrackingPing
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.prefs.SessionStore
import com.lrms.recovery.data.repo.LrmsRepository
import com.lrms.recovery.location.LocationGate
import com.lrms.recovery.location.LocationVerdict
import com.lrms.recovery.util.DeviceInfo
import com.lrms.recovery.util.Formats
import java.util.UUID

/**
 * Periodic GPS breadcrumb worker.
 *
 * Each run takes ONE fix, stores it in the same SQLite queue used by visits, and
 * then flushes every stored ping as a single batched `POST /tracking/ping`. That
 * batching is why the queue exists here at all: an agent in a village with no
 * signal accumulates pings locally and they all go up in one request when the
 * phone finds a tower.
 *
 * The same three GPS rules as the visit form apply: a null, 0/0 or mocked fix is
 * never recorded (see [LocationGate.judge]).
 */
class TrackingPingWorker(
    context: Context,
    params: WorkerParameters,
) : CoroutineWorker(context, params) {

    override suspend fun doWork(): Result {
        val session = SessionStore.get(applicationContext)
        if (!session.isSignedIn) {
            // Nothing to attribute the pings to. Not an error.
            return Result.success()
        }

        val store = QueueStore.get(applicationContext)

        // 1. Take a fix and record it, if it is trustworthy.
        try {
            when (val verdict = LocationGate(applicationContext).awaitOneFix()) {
                is LocationVerdict.Usable -> {
                    val ping = TrackingPing(
                        latitude = verdict.latitude,
                        longitude = verdict.longitude,
                        accuracyM = verdict.accuracyM,
                        speedKmph = verdict.speedKmph,
                        batteryPct = DeviceInfo.batteryPercent(applicationContext),
                        isMock = false,
                        recordedAt = Formats.nowWireDateTime(),
                    )
                    store.enqueue(
                        kind = QueueDb.KIND_PING,
                        // Pings have no server-side idempotency key of their own, so
                        // a local UUID keeps the UNIQUE index happy.
                        uid = "ping-" + UUID.randomUUID(),
                        payload = ping.toJson().toString(),
                        label = null,
                    )
                }

                is LocationVerdict.Blocked -> Log.i(
                    TAG,
                    "Skipping ping: ${verdict.reason}",
                )

                LocationVerdict.Waiting -> Unit
            }
        } catch (e: Exception) {
            Log.w(TAG, "Could not take a tracking fix: ${e.javaClass.simpleName}")
        }

        // 2. Flush whatever has accumulated, as one batch.
        val rows = store.pendingPings()
        if (rows.isEmpty()) return Result.success()

        val pings = rows.mapNotNull { TrackingPing.fromJson(it.payload) }
        if (pings.isEmpty()) {
            // Unreadable rows would block the queue forever.
            store.deleteAll(rows.map { it.id })
            return Result.success()
        }

        return when (val result = LrmsRepository.get(applicationContext).trackingPing(pings)) {
            is ApiResult.Success -> {
                store.deleteAll(rows.map { it.id })
                Result.success()
            }

            is ApiResult.Failure -> {
                if (result.isTransient) {
                    Log.i(TAG, "Ping batch deferred: ${result.code}")
                    Result.retry()
                } else {
                    // Permanent rejection: drop the batch rather than retry it
                    // forever. Breadcrumbs are not evidence, unlike visits.
                    Log.w(TAG, "Ping batch rejected permanently: ${result.code}")
                    store.deleteAll(rows.map { it.id })
                    Result.success()
                }
            }
        }
    }

    companion object {
        private const val TAG = "TrackingPingWorker"
    }
}
