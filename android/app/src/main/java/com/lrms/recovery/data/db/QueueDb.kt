package com.lrms.recovery.data.db

import android.content.ContentValues
import android.content.Context
import android.database.Cursor
import android.database.sqlite.SQLiteDatabase
import android.database.sqlite.SQLiteOpenHelper

/**
 * The offline queue.
 *
 * Plain [SQLiteOpenHelper] on purpose: Room would pull in an annotation
 * processor, and kapt/KSP on top of AGP's built-in Kotlin is one more moving
 * part in a build that field staff depend on. The schema is four columns of
 * substance and one table; hand-written SQL is cheaper than the toolchain.
 *
 * Rows are keyed by the client-generated idempotency UID with a UNIQUE index, so
 * even a double-tap on Submit can only ever create one queue row.
 */
class QueueDb private constructor(context: Context) :
    SQLiteOpenHelper(context.applicationContext, NAME, null, VERSION) {

    companion object {
        private const val NAME = "lrms_queue.db"
        private const val VERSION = 1

        const val TABLE = "queue"
        const val COL_ID = "_id"
        const val COL_KIND = "kind"
        const val COL_UID = "uid"
        const val COL_PAYLOAD = "payload"
        const val COL_STATUS = "status"
        const val COL_ATTEMPTS = "attempts"
        const val COL_ERROR_CODE = "error_code"
        const val COL_ERROR_MSG = "error_msg"
        const val COL_LABEL = "label"
        const val COL_CREATED_AT = "created_at"
        const val COL_UPDATED_AT = "updated_at"

        /** `POST /visits` */
        const val KIND_VISIT = "visit"
        /** `POST /recoveries` */
        const val KIND_RECOVERY = "recovery"
        /** One entry of a batched `POST /tracking/ping` */
        const val KIND_PING = "ping"

        /** Waiting to be sent (or waiting to be retried). */
        const val STATUS_PENDING = "pending"
        /** Server rejected it permanently; the agent has to look at it. */
        const val STATUS_FAILED = "failed"

        @Volatile
        private var instance: QueueDb? = null

        fun get(context: Context): QueueDb =
            instance ?: synchronized(this) {
                instance ?: QueueDb(context).also { instance = it }
            }
    }

    override fun onCreate(db: SQLiteDatabase) {
        db.execSQL(
            """
            CREATE TABLE $TABLE (
                $COL_ID INTEGER PRIMARY KEY AUTOINCREMENT,
                $COL_KIND TEXT NOT NULL,
                $COL_UID TEXT NOT NULL,
                $COL_PAYLOAD TEXT NOT NULL,
                $COL_STATUS TEXT NOT NULL DEFAULT '$STATUS_PENDING',
                $COL_ATTEMPTS INTEGER NOT NULL DEFAULT 0,
                $COL_ERROR_CODE TEXT,
                $COL_ERROR_MSG TEXT,
                $COL_LABEL TEXT,
                $COL_CREATED_AT INTEGER NOT NULL,
                $COL_UPDATED_AT INTEGER NOT NULL
            )
            """.trimIndent(),
        )
        // The idempotency guarantee, enforced locally too.
        db.execSQL("CREATE UNIQUE INDEX idx_queue_uid ON $TABLE ($COL_UID)")
        db.execSQL("CREATE INDEX idx_queue_kind_status ON $TABLE ($COL_KIND, $COL_STATUS)")
    }

    override fun onUpgrade(db: SQLiteDatabase, oldVersion: Int, newVersion: Int) {
        // v1 is the first shipped schema. Future migrations go here; dropping the
        // table would throw away un-submitted field work, so never do that.
    }
}

/** One row of the queue, as the sync screen sees it. */
class QueueItem(
    val id: Long,
    val kind: String,
    val uid: String,
    val payload: String,
    val status: String,
    val attempts: Int,
    val errorCode: String?,
    val errorMessage: String?,
    val label: String?,
    val createdAt: Long,
) {
    val isFailed: Boolean get() = status == QueueDb.STATUS_FAILED

    companion object {
        fun from(c: Cursor) = QueueItem(
            id = c.getLong(c.getColumnIndexOrThrow(QueueDb.COL_ID)),
            kind = c.getString(c.getColumnIndexOrThrow(QueueDb.COL_KIND)).orEmpty(),
            uid = c.getString(c.getColumnIndexOrThrow(QueueDb.COL_UID)).orEmpty(),
            payload = c.getString(c.getColumnIndexOrThrow(QueueDb.COL_PAYLOAD)).orEmpty(),
            status = c.getString(c.getColumnIndexOrThrow(QueueDb.COL_STATUS)).orEmpty(),
            attempts = c.getInt(c.getColumnIndexOrThrow(QueueDb.COL_ATTEMPTS)),
            errorCode = c.getString(c.getColumnIndexOrThrow(QueueDb.COL_ERROR_CODE)),
            errorMessage = c.getString(c.getColumnIndexOrThrow(QueueDb.COL_ERROR_MSG)),
            label = c.getString(c.getColumnIndexOrThrow(QueueDb.COL_LABEL)),
            createdAt = c.getLong(c.getColumnIndexOrThrow(QueueDb.COL_CREATED_AT)),
        )
    }
}

/**
 * All queue reads/writes. Every method is synchronous SQLite work, so callers
 * must be off the main thread (the Workers and `Dispatchers.IO` blocks are).
 */
class QueueStore private constructor(context: Context) {

    private val helper = QueueDb.get(context)

    companion object {
        @Volatile
        private var instance: QueueStore? = null

        fun get(context: Context): QueueStore =
            instance ?: synchronized(this) {
                instance ?: QueueStore(context).also { instance = it }
            }
    }

    /**
     * Inserts a row, or leaves an existing row with the same UID untouched.
     * Returns the row id, or -1 when the insert was ignored as a duplicate.
     */
    fun enqueue(kind: String, uid: String, payload: String, label: String?): Long {
        val now = System.currentTimeMillis()
        val values = ContentValues().apply {
            put(QueueDb.COL_KIND, kind)
            put(QueueDb.COL_UID, uid)
            put(QueueDb.COL_PAYLOAD, payload)
            put(QueueDb.COL_STATUS, QueueDb.STATUS_PENDING)
            put(QueueDb.COL_ATTEMPTS, 0)
            put(QueueDb.COL_LABEL, label)
            put(QueueDb.COL_CREATED_AT, now)
            put(QueueDb.COL_UPDATED_AT, now)
        }
        return helper.writableDatabase.insertWithOnConflict(
            QueueDb.TABLE,
            null,
            values,
            SQLiteDatabase.CONFLICT_IGNORE,
        )
    }

    /** Pending visits and recoveries, oldest first, so the field order is preserved. */
    fun pendingSubmissions(): List<QueueItem> = query(
        selection = "${QueueDb.COL_STATUS} = ? AND ${QueueDb.COL_KIND} IN (?, ?)",
        args = arrayOf(QueueDb.STATUS_PENDING, QueueDb.KIND_VISIT, QueueDb.KIND_RECOVERY),
    )

    /** Every visit/recovery row, pending or failed, for the sync screen. */
    fun allSubmissions(): List<QueueItem> = query(
        selection = "${QueueDb.COL_KIND} IN (?, ?)",
        args = arrayOf(QueueDb.KIND_VISIT, QueueDb.KIND_RECOVERY),
    )

    fun pendingPings(limit: Int = 200): List<QueueItem> = query(
        selection = "${QueueDb.COL_KIND} = ? AND ${QueueDb.COL_STATUS} = ?",
        args = arrayOf(QueueDb.KIND_PING, QueueDb.STATUS_PENDING),
        limit = limit.toString(),
    )

    fun pendingCount(): Int {
        helper.readableDatabase.rawQuery(
            "SELECT COUNT(*) FROM ${QueueDb.TABLE} WHERE ${QueueDb.COL_KIND} IN (?, ?)",
            arrayOf(QueueDb.KIND_VISIT, QueueDb.KIND_RECOVERY),
        ).use { c ->
            return if (c.moveToFirst()) c.getInt(0) else 0
        }
    }

    fun delete(id: Long) {
        helper.writableDatabase.delete(QueueDb.TABLE, "${QueueDb.COL_ID} = ?", arrayOf(id.toString()))
    }

    fun deleteAll(ids: List<Long>) {
        if (ids.isEmpty()) return
        val db = helper.writableDatabase
        db.beginTransaction()
        try {
            ids.forEach {
                db.delete(QueueDb.TABLE, "${QueueDb.COL_ID} = ?", arrayOf(it.toString()))
            }
            db.setTransactionSuccessful()
        } finally {
            db.endTransaction()
        }
    }

    /** Records an attempt outcome. [status] decides whether it will be retried. */
    fun markAttempt(id: Long, status: String, errorCode: String?, errorMessage: String?) {
        val db = helper.writableDatabase
        var attempts = 0
        db.query(
            QueueDb.TABLE,
            arrayOf(QueueDb.COL_ATTEMPTS),
            "${QueueDb.COL_ID} = ?",
            arrayOf(id.toString()),
            null,
            null,
            null,
        ).use { c -> if (c.moveToFirst()) attempts = c.getInt(0) }

        val values = ContentValues().apply {
            put(QueueDb.COL_STATUS, status)
            put(QueueDb.COL_ATTEMPTS, attempts + 1)
            put(QueueDb.COL_ERROR_CODE, errorCode)
            put(QueueDb.COL_ERROR_MSG, errorMessage)
            put(QueueDb.COL_UPDATED_AT, System.currentTimeMillis())
        }
        db.update(QueueDb.TABLE, values, "${QueueDb.COL_ID} = ?", arrayOf(id.toString()))
    }

    /** "Retry all" — puts permanently-failed rows back in the pending pool. */
    fun resetFailedToPending(): Int {
        val values = ContentValues().apply {
            put(QueueDb.COL_STATUS, QueueDb.STATUS_PENDING)
            put(QueueDb.COL_UPDATED_AT, System.currentTimeMillis())
        }
        return helper.writableDatabase.update(
            QueueDb.TABLE,
            values,
            "${QueueDb.COL_STATUS} = ?",
            arrayOf(QueueDb.STATUS_FAILED),
        )
    }

    private fun query(selection: String, args: Array<String>, limit: String? = null): List<QueueItem> {
        val out = ArrayList<QueueItem>()
        helper.readableDatabase.query(
            QueueDb.TABLE,
            null,
            selection,
            args,
            null,
            null,
            "${QueueDb.COL_CREATED_AT} ASC",
            limit,
        ).use { c ->
            while (c.moveToNext()) out.add(QueueItem.from(c))
        }
        return out
    }
}
