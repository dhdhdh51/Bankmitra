package com.lrms.recovery.ui

import android.os.Bundle
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.db.QueueStore
import com.lrms.recovery.databinding.ActivitySettingsBinding
import com.lrms.recovery.sync.SyncScheduler
import com.lrms.recovery.util.DeviceInfo
import com.lrms.recovery.util.confirm
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.snack
import com.lrms.recovery.util.visible
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Device settings and diagnostics.
 *
 * This screen exists mainly so a support call can be answered in one minute:
 * it shows the server URL in use, the device id the server binds the account to,
 * the app version, whether the token is in hardware-backed encrypted storage,
 * whether the server actually sent a Maps key, the real tracking interval after
 * WorkManager's 15-minute clamp, and how many records are still queued.
 */
class SettingsActivity : BaseActivity() {

    private lateinit var binding: ActivitySettingsBinding

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!session.isSignedIn) return

        binding = ActivitySettingsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.serverUrl.setText(prefs.serverUrl)
        binding.saveServerUrlAction.setOnClickListener { saveServerUrl() }
        binding.logoutAction.setOnClickListener { confirmLogout() }

        BottomNavHelper.attach(this, binding.bottomNav, R.id.nav_settings)

        renderProfile()
        renderDiagnostics()
    }

    override fun onResume() {
        super.onResume()
        renderQueue()
    }

    private fun renderProfile() {
        val user = session.user
        binding.signedInAs.text = user?.fullName ?: "Signed in"
        binding.signedInDetail.text = listOfNotNull(
            user?.roleName,
            user?.bcCode?.let { "BC $it" },
            user?.branchName,
            user?.mobileMasked,
        ).joinToString("  •  ")
    }

    private fun renderDiagnostics() {
        binding.appVersion.text = getString(R.string.app_version) + ": " +
            DeviceInfo.appVersion + " (" + DeviceInfo.model + ", Android " + DeviceInfo.osVersion + ")"

        binding.deviceId.text = getString(R.string.device_id) + ": " + DeviceInfo.deviceId(this)

        val mapsKey = session.config.mapsApiKey
        binding.mapsKeyStatus.text = if (mapsKey.isNullOrBlank()) {
            "Maps key: not sent by the server. Maps are disabled; " +
                "an administrator can add one in Settings → Google Maps."
        } else {
            "Maps key: supplied by the server at sign-in (not stored in the APK)."
        }

        val configured = session.config.gpsPingIntervalSeconds
        val effective = SyncScheduler.effectiveTrackingMinutes(configured)
        binding.trackingStatus.text = buildString {
            append("GPS tracking: server asks for every ")
            append(configured / 60)
            append(" min; Android runs it every ")
            append(effective)
            append(" min")
            if (effective > configured / 60L) {
                append(" (WorkManager will not schedule periodic work more often than 15 min)")
            }
            append('.')
        }

        binding.storageStatus.text = if (session.usingEncryptedStorage) {
            "Session storage: encrypted (hardware-backed keystore)."
        } else {
            "Session storage: plain preferences — this device's keystore was unavailable, " +
                "so the token is stored unencrypted. Sign out when you finish for the day."
        }
    }

    private fun renderQueue() {
        lifecycleScope.launch {
            val pending = withContext(Dispatchers.IO) {
                QueueStore.get(this@SettingsActivity).pendingCount()
            }
            binding.queueStatus.text = if (pending == 0) {
                "Sync queue: empty — everything has reached the server."
            } else {
                "Sync queue: $pending record(s) waiting. Open the Queue tab to review them."
            }
        }
    }

    private fun saveServerUrl() {
        val raw = binding.serverUrl.text?.toString()?.trim().orEmpty()
        if (raw.isEmpty()) {
            showError(getString(R.string.err_no_server_url))
            return
        }
        if (!raw.startsWith("http://", true) && !raw.startsWith("https://", true)) {
            showError("The server URL must start with https:// (or http:// for local testing).")
            return
        }
        if (raw.startsWith("http://", true) &&
            !raw.contains("localhost") && !raw.contains("127.0.0.1") && !raw.contains("10.0.2.2")
        ) {
            // network_security_config only permits cleartext to loopback, so this
            // would fail at request time with a confusing error. Say it now.
            showError(
                "Plain http:// is only allowed for localhost during development. " +
                    "Use https:// — cPanel provides a free AutoSSL certificate.",
                "HTTPS required",
            )
            return
        }

        prefs.serverUrl = raw
        binding.root.snack("Server URL saved. Sign out and back in if you changed servers.")
    }

    private fun confirmLogout() {
        lifecycleScope.launch {
            val pending = withContext(Dispatchers.IO) {
                QueueStore.get(this@SettingsActivity).pendingCount()
            }

            val message = if (pending > 0) {
                "There are still $pending record(s) waiting to upload. Signing out keeps them " +
                    "on this device, but they cannot be sent until you sign in again. Sign out anyway?"
            } else {
                "You will need your password or an OTP to sign in again."
            }

            confirm(getString(R.string.logout), message, getString(R.string.logout)) { logout() }
        }
    }

    private fun logout() {
        binding.progress.visible(true)
        lifecycleScope.launch {
            // Best effort: revoke server-side, but always clear locally even if
            // the call fails (the user asked to sign out).
            repo.logout()
            session.clear()
            SyncScheduler.cancelTracking(this@SettingsActivity)
            binding.progress.visible(false)
            goToLogin(null)
        }
    }
}
