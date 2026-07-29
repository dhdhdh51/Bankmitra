package com.lrms.recovery.ui

import android.os.Bundle
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.lrms.recovery.R
import com.lrms.recovery.data.db.QueueStore
import com.lrms.recovery.databinding.ActivitySyncQueueBinding
import com.lrms.recovery.sync.QueueFlusher
import com.lrms.recovery.ui.adapter.QueueAdapter
import com.lrms.recovery.util.DeviceInfo
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.snack
import com.lrms.recovery.util.visible
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * The offline queue, made visible.
 *
 * Field agents work in villages with no signal. Anything they submit while
 * offline is written to SQLite with its client-generated UUID and uploaded later
 * by [com.lrms.recovery.sync.QueueFlushWorker]. That is invisible machinery, and
 * invisible machinery is not trusted with someone's day of work - so this screen
 * shows exactly what is still waiting, how many attempts each item has had, and
 * the server's own words when something was rejected.
 *
 * "Retry all" resets permanently-failed rows back to pending and flushes
 * immediately. Because the UUIDs never change, a retry of an item the server
 * already accepted comes back as `duplicate: true` and is simply removed - it
 * cannot create a second visit or double-count money.
 */
class SyncQueueActivity : BaseActivity() {

    private lateinit var binding: ActivitySyncQueueBinding
    private val adapter = QueueAdapter()

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!session.isSignedIn) return

        binding = ActivitySyncQueueBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter

        binding.retryAllAction.setOnClickListener { retryAll() }

        BottomNavHelper.attach(this, binding.bottomNav, R.id.nav_queue)
    }

    override fun onResume() {
        super.onResume()
        load()
    }

    private fun load() {
        lifecycleScope.launch {
            val items = withContext(Dispatchers.IO) {
                QueueStore.get(this@SyncQueueActivity).allSubmissions()
            }
            adapter.submit(items)
            binding.emptyText.visible(items.isEmpty())
            binding.retryAllAction.isEnabled = items.isNotEmpty()

            val failed = items.count { it.isFailed }
            binding.toolbar.subtitle = when {
                items.isEmpty() -> null
                failed > 0 -> "${items.size} waiting, $failed rejected"
                else -> "${items.size} waiting to upload"
            }
        }
    }

    private fun retryAll() {
        if (!DeviceInfo.isOnline(this)) {
            showError(
                "There is no network connection right now. The queue will upload " +
                    "automatically as soon as one is available - nothing is lost.",
                "Still offline",
            )
            return
        }

        binding.retryAllAction.isEnabled = false
        lifecycleScope.launch {
            // Give rejected rows one more chance, then flush in the foreground so
            // the agent sees the outcome instead of waiting for a background job.
            val revived = withContext(Dispatchers.IO) {
                QueueStore.get(this@SyncQueueActivity).resetFailedToPending()
            }

            val report = QueueFlusher.flush(this@SyncQueueActivity)

            val summary = buildString {
                append("Attempted ${report.attempted}, uploaded ${report.succeeded}")
                if (report.duplicates > 0) {
                    append(", ${report.duplicates} already on the server")
                }
                if (report.keptForRetry > 0) {
                    append(", ${report.keptForRetry} kept for another try")
                }
                if (report.permanentlyFailed > 0) {
                    append(", ${report.permanentlyFailed} rejected")
                }
                if (revived > 0) append(" (retried $revived previously rejected)")
            }

            load()

            if (report.permanentlyFailed > 0 && report.firstError != null) {
                showError(
                    summary + "\n\nFirst rejection: " + report.firstError,
                    "Some items were rejected",
                )
            } else {
                binding.root.snack(summary)
            }
            binding.retryAllAction.isEnabled = true
        }
    }
}
