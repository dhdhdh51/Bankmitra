package com.lrms.recovery.ui

import android.content.Intent
import android.os.Bundle
import android.os.CountDownTimer
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.net.ErrorCodes
import com.lrms.recovery.databinding.ActivityLoginBinding
import com.lrms.recovery.sync.SyncScheduler
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.visible
import kotlinx.coroutines.launch

/**
 * Sign-in with either an OTP or a password.
 *
 * The server URL field lives here on purpose: a freshly sideloaded APK has no
 * idea which bank it belongs to, and this is the first screen where a human can
 * tell it. The value is saved to SharedPreferences BEFORE any request is made,
 * because ApiClient reads it from there.
 */
class LoginActivity : BaseActivity() {

    override val requiresSession = false

    // No toolbar here: the pale surface colour runs all the way to the top of the
    // screen, so the status bar icons have to be dark to stay readable.
    override val darkStatusBarBackground = false

    private lateinit var binding: ActivityLoginBinding

    /** true = OTP mode, false = password mode. */
    private var otpMode = true

    /** Set once an OTP has been requested for the current identifier. */
    private var otpRequested = false

    private var resendTimer: CountDownTimer? = null

    companion object {
        const val EXTRA_REASON = "reason"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityLoginBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.serverUrl.setText(prefs.serverUrl)
        binding.identifier.setText(prefs.lastIdentifier)

        intent.getStringExtra(EXTRA_REASON)?.let { showInline(it) }

        binding.modeToggle.check(R.id.modeOtp)
        applyMode(otp = true)
        binding.modeToggle.addOnButtonCheckedListener { _, checkedId, isChecked ->
            if (!isChecked) return@addOnButtonCheckedListener
            applyMode(otp = checkedId == R.id.modeOtp)
        }

        binding.primaryAction.setOnClickListener { onPrimaryAction() }
        binding.resendAction.setOnClickListener { requestOtp(resend = true) }
        binding.registerAction.setOnClickListener {
            if (!saveServerUrl()) return@setOnClickListener
            startActivity(Intent(this, RegisterActivity::class.java))
        }
    }

    override fun onDestroy() {
        resendTimer?.cancel()
        super.onDestroy()
    }

    private fun applyMode(otp: Boolean) {
        otpMode = otp
        otpRequested = false
        resendTimer?.cancel()
        binding.passwordLayout.visible(!otp)
        binding.otpLayout.visible(false)
        binding.resendAction.visible(false)
        binding.primaryAction.setText(if (otp) R.string.request_otp else R.string.sign_in)
        showInline(null)
    }

    /** Persists the URL so ApiClient can see it. Returns false when it is empty. */
    private fun saveServerUrl(): Boolean {
        val url = binding.serverUrl.text?.toString()?.trim().orEmpty()
        if (url.isEmpty()) {
            binding.serverUrlLayout.error = getString(R.string.err_no_server_url)
            return false
        }
        binding.serverUrlLayout.error = null
        prefs.serverUrl = url
        return true
    }

    private fun identifier(): String = binding.identifier.text?.toString()?.trim().orEmpty()

    private fun onPrimaryAction() {
        if (!saveServerUrl()) return
        if (identifier().isEmpty()) {
            binding.identifierLayout.error = "Enter your mobile, email or employee code."
            return
        }
        binding.identifierLayout.error = null
        prefs.lastIdentifier = identifier()

        if (!otpMode) {
            loginWithPassword()
        } else if (!otpRequested) {
            requestOtp(resend = false)
        } else {
            verifyOtp()
        }
    }

    private fun requestOtp(resend: Boolean) {
        busy(true)
        showInline(null)
        lifecycleScope.launch {
            when (val result = repo.requestOtp(identifier(), purpose = "login")) {
                is ApiResult.Success -> {
                    busy(false)
                    otpRequested = true
                    binding.otpLayout.visible(true)
                    binding.primaryAction.setText(R.string.verify_otp)
                    // The server's own message tells the user where the OTP went
                    // (masked). Never construct our own; never log the OTP.
                    showInline(result.message, isError = false)
                    startResendCountdown(result.data.resendAfterSeconds)
                }

                is ApiResult.Failure -> {
                    busy(false)
                    if (result.code == ErrorCodes.OTP_THROTTLED && result.retryAfterSeconds > 0) {
                        otpRequested = true
                        binding.otpLayout.visible(true)
                        binding.primaryAction.setText(R.string.verify_otp)
                        startResendCountdown(result.retryAfterSeconds)
                    }
                    handleFailure(result, "Could not send the OTP") { showInline(it) }
                }
            }
        }
    }

    private fun startResendCountdown(seconds: Int) {
        resendTimer?.cancel()
        val total = seconds.coerceAtLeast(1)
        binding.resendAction.visible(true)
        binding.resendAction.isEnabled = false
        resendTimer = object : CountDownTimer(total * 1000L, 1000L) {
            override fun onTick(millisUntilFinished: Long) {
                val left = (millisUntilFinished / 1000L).toInt()
                binding.resendAction.text = getString(R.string.resend_in, left)
            }

            override fun onFinish() {
                binding.resendAction.isEnabled = true
                binding.resendAction.setText(R.string.resend_otp)
            }
        }.start()
    }

    private fun verifyOtp() {
        val otp = binding.otp.text?.toString()?.trim().orEmpty()
        if (otp.isEmpty()) {
            binding.otpLayout.error = "Enter the OTP you received."
            return
        }
        binding.otpLayout.error = null
        busy(true)
        lifecycleScope.launch {
            when (val result = repo.verifyOtp(identifier(), otp)) {
                is ApiResult.Success -> onSignedIn()
                is ApiResult.Failure -> {
                    busy(false)
                    if (result.code == ErrorCodes.OTP_EXPIRED) {
                        binding.resendAction.isEnabled = true
                        binding.resendAction.setText(R.string.resend_otp)
                        binding.resendAction.visible(true)
                    }
                    handleFailure(result, "Sign-in failed") { showInline(it) }
                }
            }
        }
    }

    private fun loginWithPassword() {
        val password = binding.password.text?.toString().orEmpty()
        if (password.isEmpty()) {
            binding.passwordLayout.error = "Enter your password."
            return
        }
        binding.passwordLayout.error = null
        busy(true)
        lifecycleScope.launch {
            when (val result = repo.loginWithPassword(identifier(), password)) {
                is ApiResult.Success -> onSignedIn()
                is ApiResult.Failure -> {
                    busy(false)
                    if (result.code == ErrorCodes.ACCOUNT_PENDING) {
                        showError(result.message, getString(R.string.account_pending_title))
                    } else {
                        handleFailure(result, "Sign-in failed") { showInline(it) }
                    }
                }
            }
        }
    }

    private fun onSignedIn() {
        busy(false)
        // config.gps_ping_interval_seconds arrived with the session payload.
        SyncScheduler.scheduleTracking(this, session.config.gpsPingIntervalSeconds)
        SyncScheduler.requestQueueFlush(this)
        startActivity(
            Intent(this, DashboardActivity::class.java)
                .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP),
        )
        finish()
    }

    private fun busy(on: Boolean) {
        binding.progress.visible(on)
        binding.primaryAction.isEnabled = !on
        binding.registerAction.isEnabled = !on
    }

    private fun showInline(message: String?, isError: Boolean = true) {
        if (message.isNullOrBlank()) {
            binding.errorText.visible(false)
            return
        }
        binding.errorText.text = message
        binding.errorText.setTextColor(
            getColor(if (isError) R.color.status_bad else R.color.status_ok),
        )
        binding.errorText.visible(true)
    }
}
