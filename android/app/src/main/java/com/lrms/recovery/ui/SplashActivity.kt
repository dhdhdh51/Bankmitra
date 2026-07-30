package com.lrms.recovery.ui

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.lifecycle.lifecycleScope
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.lrms.recovery.R
import com.lrms.recovery.data.model.PingInfo
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.net.ErrorCodes
import com.lrms.recovery.databinding.ActivitySplashBinding
import com.lrms.recovery.sync.SyncScheduler
import com.lrms.recovery.util.CrashReporter
import com.lrms.recovery.util.DeviceInfo
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.visible
import kotlinx.coroutines.launch

/**
 * First screen. Calls `GET /ping` and decides where the user goes.
 *
 * Routing rules, in priority order:
 *   1. no server URL saved yet          -> Login (so the user can type one)
 *   2. /ping unreachable                -> stay here with a Retry button and a
 *                                          shortcut to Settings for the URL
 *   3. maintenance_mode                 -> stay here, show the operator's message
 *   4. force_update / update_required    -> stay here, offer the APK download
 *   5. signed in                        -> Dashboard
 *   6. otherwise                        -> Login
 *
 * Nothing here is silent: every branch puts a sentence on screen.
 */
class SplashActivity : BaseActivity() {

    override val requiresSession = false

    private lateinit var binding: ActivitySplashBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivitySplashBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.secondaryAction.setOnClickListener {
            // Settings needs a session; without one the URL is edited on Login.
            startActivity(Intent(this, LoginActivity::class.java))
        }

        // If the previous run died, show why before doing anything else. Without
        // this, a crash on a field handset is unreportable: there is no PC to run
        // logcat on. See util/CrashReporter.
        val crash = CrashReporter.pendingReport(this)
        if (crash != null) {
            CrashReporter.clear(this)
            showCrashReport(crash)
        } else {
            checkServer()
        }
    }

    /**
     * Displays the saved crash report with a Share button, then continues into
     * the normal startup path whichever button is used.
     */
    private fun showCrashReport(report: String) {
        binding.progress.visible(false)
        binding.status.text = getString(R.string.crash_report_status)

        MaterialAlertDialogBuilder(this)
            .setTitle(R.string.crash_report_title)
            .setMessage(report)
            .setCancelable(false)
            .setPositiveButton(R.string.crash_report_share) { _, _ ->
                shareCrashReport(report)
                checkServer()
            }
            .setNegativeButton(R.string.continue_label) { _, _ -> checkServer() }
            .show()
    }

    private fun shareCrashReport(report: String) {
        val intent = Intent(Intent.ACTION_SEND).apply {
            type = "text/plain"
            putExtra(Intent.EXTRA_SUBJECT, "LRMS crash report")
            putExtra(Intent.EXTRA_TEXT, report)
        }
        try {
            startActivity(Intent.createChooser(intent, getString(R.string.crash_report_share)))
        } catch (e: Exception) {
            showError(
                "No app on this device can share text. You can still read the report above " +
                    "and photograph it.",
                getString(R.string.crash_report_title),
            )
        }
    }

    private fun checkServer() {
        if (prefs.serverUrl.isBlank()) {
            binding.status.setText(R.string.splash_no_server)
            binding.progress.visible(false)
            goToLogin(null)
            return
        }
        binding.progress.visible(true)
        binding.primaryAction.visible(false)
        binding.secondaryAction.visible(false)
        binding.status.setText(R.string.splash_connecting)

        lifecycleScope.launch {
            when (val result = repo.ping()) {
                is ApiResult.Success -> onPing(result.data)
                is ApiResult.Failure -> onPingFailed(result)
            }
        }
    }

    private fun onPing(info: PingInfo) {
        binding.progress.visible(false)
        binding.organisation.text = info.organisation

        if (info.maintenanceMode) {
            binding.status.text = info.maintenanceMessage.ifBlank {
                getString(R.string.maintenance_title)
            }
            binding.primaryAction.setText(R.string.retry)
            binding.primaryAction.visible(true)
            binding.primaryAction.setOnClickListener { checkServer() }
            return
        }

        // force_update is the server's explicit instruction. We also treat a
        // min_app_version newer than ours as a forced update, because the server
        // will start answering `update_required` anyway.
        val outdated = info.forceUpdate || isOlderThan(DeviceInfo.appVersion, info.minAppVersion)
        if (outdated) {
            showForcedUpdate(info.apkUrl)
            return
        }

        if (session.isSignedIn) {
            // Refresh config so the tracking interval and Maps key are current.
            SyncScheduler.scheduleTracking(this, prefs.gpsPingIntervalSeconds)
            SyncScheduler.requestQueueFlush(this)
            startActivity(Intent(this, DashboardActivity::class.java))
        } else {
            startActivity(Intent(this, LoginActivity::class.java))
        }
        finish()
    }

    private fun onPingFailed(failure: ApiResult.Failure) {
        binding.progress.visible(false)

        // The server can also report these two conditions as failures.
        when (failure.code) {
            ErrorCodes.MAINTENANCE_MODE -> {
                binding.status.text = failure.message
                binding.primaryAction.setText(R.string.retry)
                binding.primaryAction.visible(true)
                binding.primaryAction.setOnClickListener { checkServer() }
                return
            }

            ErrorCodes.UPDATE_REQUIRED -> {
                showForcedUpdate(failure.apkUrl)
                return
            }
        }

        binding.status.text = failure.message
        binding.primaryAction.setText(R.string.retry)
        binding.primaryAction.visible(true)
        binding.primaryAction.setOnClickListener { checkServer() }
        binding.secondaryAction.text = getString(R.string.server_url_label)
        binding.secondaryAction.visible(true)
    }

    private fun showForcedUpdate(apkUrl: String?) {
        binding.status.text = getString(R.string.update_required_body)
        binding.primaryAction.setText(R.string.download_update)
        binding.primaryAction.visible(true)
        binding.primaryAction.setOnClickListener {
            if (apkUrl.isNullOrBlank()) {
                showError(
                    "The server did not provide a download link. Ask your administrator " +
                        "for the latest APK.",
                    getString(R.string.update_required_title),
                )
                return@setOnClickListener
            }
            try {
                startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(apkUrl)))
            } catch (e: Exception) {
                showError(
                    "No app on this device can open the download link:\n$apkUrl",
                    getString(R.string.update_required_title),
                )
            }
        }
    }

    /**
     * Compares dotted version strings numerically ("1.10.0" > "1.9.0").
     * Unparseable or absent values are treated as "not older", so a malformed
     * server value can never lock the field team out of the app.
     */
    private fun isOlderThan(current: String, minimum: String?): Boolean {
        if (minimum.isNullOrBlank()) return false
        val a = current.split('.').mapNotNull { it.trim().toIntOrNull() }
        val b = minimum.split('.').mapNotNull { it.trim().toIntOrNull() }
        if (a.isEmpty() || b.isEmpty()) return false
        for (i in 0 until maxOf(a.size, b.size)) {
            val x = a.getOrElse(i) { 0 }
            val y = b.getOrElse(i) { 0 }
            if (x != y) return x < y
        }
        return false
    }
}
