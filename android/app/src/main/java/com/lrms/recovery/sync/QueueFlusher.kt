package com.lrms.recovery.sync

import android.content.Context
import android.util.Log
import com.lrms.recovery.data.db.QueueDb
import com.lrms.recovery.data.db.QueueStore
import com.lrms.recovery.data.model.RecoveryDraft
import com.lrms.recovery.data.model.VisitDraft
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.net.ErrorCodes
import com.lrms.recovery.data.prefs.SessionStore
import com.lrms.recovery.data.repo.LrmsRepository
import com.lrms.recovery.util.PhotoStore
import java.io.File

/** Outcome of one flush pass, used by both the Worker and the "Retry all" button. */
class FlushReport(
    val attempted: Int,
    val succeeded: Int,
    val duplicates: Int,
    val keptForRetry: Int,
    val permanentlyFailed: Int,
    /** First user-facing error of the pass, if any. */
    val firstError: String?,
) {
    val hasWorkLeft: Boolean get() = keptForRetry > 0
}

/**
 * Drains the offline queue.
 *
 * THE IDEMPOTENCY CONTRACT
 * ------------------------
 * The `visit_uid` / `recovery_uid` in each queue row was generated once, when the
 * agent pressed Submit, and is stored inside the row's JSON payload. This class
 * deserialises the payload and re-sends it byte-for-byte. It NEVER creates a new
 * UUID. That is what makes a lost response harmless: the server recognises the
 * UID and answers `duplicate: true` (or HTTP 409 `duplicate`) instead of
 * inserting a second visit, and we then delete the row exactly as if it were a
 * first-time success.
 *
 * RETRY POLICY
 * ------------
 * - success (including duplicate) -> delete the row, delete its photos
 * - transient failure (no network, timeout, 5xx, maintenance, rate limit)
 *   -> row stays `pending`, attempts += 1, retried on the next pass
 * - anything else (validation_failed, gps_required, forbidden, ...)
 *   -> row becomes `failed` and stops retrying, because retrying a rejected
 *      payload forever would just burn the agent's data allowance. It stays
 *      visible on the sync screen with the server's own message.
 */
object QueueFlusher {

    private const val TAG = "QueueFlusher"

    suspend fun flush(context: Context): FlushReport {
        val store = QueueStore.get(context)
        val repo = LrmsRepository.get(context)
        val session = SessionStore.get(context)

        // No token means nothing can be submitted; leave the queue untouched so
        // the work survives until the agent signs in again.
        if (!session.isSignedIn) {
            return FlushReport(0, 0, 0, 0, 0, "Not signed in - queued items are waiting.")
        }

        val items = store.pendingSubmissions()
        var succeeded = 0
        var duplicates = 0
        var kept = 0
        var failed = 0
        var firstError: String? = null

        for (item in items) {
            val result: ApiResult<Boolean> = when (item.kind) {
                QueueDb.KIND_VISIT -> {
                    val draft = VisitDraft.fromJson(item.payload)
                    if (draft == null) {
                        // Corrupt row: nothing can ever make it succeed.
                        store.markAttempt(
                            item.id,
                            QueueDb.STATUS_FAILED,
                            ErrorCodes.CLIENT_BAD_RESPONSE,
                            "The saved visit could not be read back and cannot be submitted.",
                        )
                        failed++
                        continue
                    }
                    when (val r = repo.submitVisit(draft)) {
                        is ApiResult.Success -> ApiResult.Success(r.data.duplicate, r.message)
                        is ApiResult.Failure -> r
                    }
                }

                QueueDb.KIND_RECOVERY -> {
                    val draft = RecoveryDraft.fromJson(item.payload)
                    if (draft == null) {
                        store.markAttempt(
                            item.id,
                            QueueDb.STATUS_FAILED,
                            ErrorCodes.CLIENT_BAD_RESPONSE,
                            "The saved recovery could not be read back and cannot be submitted.",
                        )
                        failed++
                        continue
                    }
                    when (val r = repo.submitRecovery(draft)) {
                        is ApiResult.Success -> ApiResult.Success(r.data.duplicate, r.message)
                        is ApiResult.Failure -> r
                    }
                }

                else -> continue
            }

            when (result) {
                is ApiResult.Success -> {
                    if (result.data) duplicates++ else succeeded++
                    deleteAttachments(item.kind, item.payload)
                    store.delete(item.id)
                }

                is ApiResult.Failure -> {
                    // HTTP 409 / code=duplicate is a SUCCESS for us: the server
                    // already has this record. Drop the row.
                    if (result.code == ErrorCodes.DUPLICATE) {
                        duplicates++
                        deleteAttachments(item.kind, item.payload)
                        store.delete(item.id)
                    } else if (result.isTransient) {
                        kept++
                        if (firstError == null) firstError = result.message
                        store.markAttempt(
                            item.id,
                            QueueDb.STATUS_PENDING,
                            result.code,
                            result.message,
                        )
                    } else {
                        failed++
                        if (firstError == null) firstError = result.message
                        store.markAttempt(
                            item.id,
                            QueueDb.STATUS_FAILED,
                            result.code,
                            result.message,
                        )
                        // If the session died, stop the pass: every remaining item
                        // would fail the same way.
                        if (repo.handleAuthFailure(result)) {
                            Log.w(TAG, "Session rejected by server; stopping flush pass.")
                            break
                        }
                    }
                }
            }
        }

        return FlushReport(
            attempted = items.size,
            succeeded = succeeded,
            duplicates = duplicates,
            keptForRetry = kept,
            permanentlyFailed = failed,
            firstError = firstError,
        )
    }

    /** Frees the captured photos once the server has the record. */
    private fun deleteAttachments(kind: String, payload: String) {
        when (kind) {
            QueueDb.KIND_VISIT ->
                VisitDraft.fromJson(payload)?.photoPaths?.forEach { PhotoStore.delete(File(it)) }
            QueueDb.KIND_RECOVERY ->
                RecoveryDraft.fromJson(payload)?.receiptPhotoPath
                    ?.let { PhotoStore.delete(File(it)) }
        }
    }
}
