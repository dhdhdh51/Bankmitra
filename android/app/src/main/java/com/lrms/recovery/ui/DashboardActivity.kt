package com.lrms.recovery.ui

import android.content.Intent
import android.os.Bundle
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.GridLayoutManager
import com.lrms.recovery.R
import com.lrms.recovery.data.db.QueueStore
import com.lrms.recovery.data.model.Dashboard
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.databinding.ActivityDashboardBinding
import com.lrms.recovery.sync.SyncScheduler
import com.lrms.recovery.ui.adapter.Tile
import com.lrms.recovery.ui.adapter.TileAdapter
import com.lrms.recovery.util.Formats
import com.lrms.recovery.util.PermissionRequester
import com.lrms.recovery.util.visible
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/** Home screen: counters from `GET /dashboard`, attendance shortcut, bottom nav. */
class DashboardActivity : BaseActivity() {

    private lateinit var binding: ActivityDashboardBinding
    private val tileAdapter = TileAdapter()
    private lateinit var permissions: PermissionRequester

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!session.isSignedIn) return
        binding = ActivityDashboardBinding.inflate(layoutInflater)
        setContentView(binding.root)

        permissions = PermissionRequester(this)
        // Android 13+ needs an explicit grant before any notification (including
        // WorkManager foreground notices) can be shown.
        permissions.requestNotificationsIfNeeded()

        binding.toolbar.subtitle = session.user?.let {
            listOfNotNull(it.fullName, it.branchName).joinToString(" \u2022 ")
        }

        binding.tiles.layoutManager = GridLayoutManager(this, 2)
        binding.tiles.adapter = tileAdapter

        binding.swipeRefresh.setOnRefreshListener { load(pullToRefresh = true) }
        binding.checkInAction.setOnClickListener { openAttendance() }
        binding.checkOutAction.setOnClickListener { openAttendance() }
        binding.queueHint.setOnClickListener {
            startActivity(Intent(this, SyncQueueActivity::class.java))
        }

        BottomNavHelper.attach(this, binding.bottomNav, R.id.nav_home)
    }

    override fun onResume() {
        super.onResume()
        if (session.isSignedIn) {
            load(pullToRefresh = false)
            SyncScheduler.requestQueueFlush(this)
        }
    }

    private fun openAttendance() {
        startActivity(Intent(this, AttendanceActivity::class.java))
    }

    private fun load(pullToRefresh: Boolean) {
        if (!pullToRefresh) binding.progress.visible(true)
        lifecycleScope.launch {
            val queued = withContext(Dispatchers.IO) {
                QueueStore.get(this@DashboardActivity).pendingCount()
            }
            val result = repo.dashboard()
            binding.progress.visible(false)
            binding.swipeRefresh.isRefreshing = false

            when (result) {
                is ApiResult.Success -> render(result.data, queued)
                is ApiResult.Failure -> {
                    renderQueueHint(queued, null)
                    // Never fail silently, but do not block the screen either:
                    // the tiles simply keep their previous values.
                    handleFailure(result, "Could not load the dashboard")
                }
            }
        }
    }

    private fun render(data: Dashboard, queuedCount: Int) {
        binding.attendanceStatus.text = when {
            data.checkedOut -> "Checked out for today"
            data.checkedIn -> "Checked in"
            else -> "Not checked in"
        }
        binding.attendanceDetail.text = data.checkInAt
            ?.let { "Since ${Formats.prettyDateTime(it)}" }
            .orEmpty()
        binding.checkInAction.isEnabled = !data.checkedIn
        binding.checkOutAction.isEnabled = data.checkedIn && !data.checkedOut

        tileAdapter.submit(
            listOf(
                Tile(getString(R.string.tile_assigned), data.assignedAccounts.toString()),
                Tile(getString(R.string.tile_visited_today), data.visitedToday.toString()),
                Tile(getString(R.string.tile_pending_today), data.pendingToday.toString()),
                Tile(getString(R.string.tile_followups), data.followupsDue.toString()),
                Tile(getString(R.string.tile_promises), data.promiseCount.toString()),
                Tile(getString(R.string.tile_ots), data.otsCount.toString()),
                Tile(getString(R.string.tile_recovery_today), Formats.money(data.recoveryToday)),
                Tile(getString(R.string.tile_recovery_month), Formats.money(data.recoveryMonth)),
                Tile(getString(R.string.tile_target), Formats.money(data.monthlyTarget)),
                Tile(getString(R.string.tile_target_pct), Formats.percent(data.targetAchievedPct)),
            ),
        )
        renderQueueHint(queuedCount, data.unsyncedHint)
    }

    private fun renderQueueHint(queuedCount: Int, serverHint: String?) {
        if (queuedCount <= 0) {
            binding.queueHint.visible(false)
            return
        }
        binding.queueHint.text = "$queuedCount item(s) waiting to sync. " +
            (serverHint ?: "Tap to open the sync queue.")
        binding.queueHint.visible(true)
    }
}
