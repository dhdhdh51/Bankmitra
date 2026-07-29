package com.lrms.recovery.ui

import android.content.Intent
import android.os.Bundle
import android.os.CountDownTimer
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.model.InviteInfo
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.net.ErrorCodes
import com.lrms.recovery.databinding.ActivityRegisterBinding
import com.lrms.recovery.sync.SyncScheduler
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.visible
import kotlinx.coroutines.launch

/**
 * Invitation-only registration, in the order docs/API.md requires:
 *
 *   1. `POST /auth/invite/validate` - proves the code exists and reveals the role
 *      and branch the admin assigned, so the user can check they were given the
 *      right code before typing anything else.
 *   2. personal details + `POST /auth/otp/request` with `purpose=register`
 *   3. `POST /auth/register`
 *
 * A `403 account_pending` response is a SUCCESS from the user's point of view -
 * the account was created and is waiting for approval - so it gets its own
 * explanatory dialog and returns to the login screen rather than looking like a
 * failure.
 */
class RegisterActivity : BaseActivity() {

    override val requiresSession = false

    private lateinit var binding: ActivityRegisterBinding
    private var invite: InviteInfo? = null
    private var otpRequested = false
    private var resendTimer: CountDownTimer? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityRegisterBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }
        binding.validateAction.setOnClickListener { validateInvite() }
        binding.sendOtpAction.setOnClickListener { requestOtp() }
        binding.submitAction.setOnClickListener { submit() }
    }

    override fun onDestroy() {
        resendTimer?.cancel()
        super.onDestroy()
    }

    private fun validateInvite() {
        val code = binding.inviteCode.text?.toString()?.trim().orEmpty()
        if (code.isEmpty()) {
            binding.inviteCodeLayout.error = "Enter the invitation code."
            return
        }
        binding.inviteCodeLayout.error = null
        busy(true)
        showInline(null)
        lifecycleScope.launch {
            when (val result = repo.validateInvite(code)) {
                is ApiResult.Success -> {
                    busy(false)
                    invite = result.data
                    showInvite(result.data)
                }

                is ApiResult.Failure -> {
                    busy(false)
                    invite = null
                    binding.inviteCard.visible(false)
                    binding.detailsGroup.visible(false)
                    handleFailure(result, "Invitation code rejected") { showInline(it) }
                }
            }
        }
    }

    private fun showInvite(info: InviteInfo) {
        binding.inviteRole.text = info.roleName.ifBlank { info.role }
        binding.inviteBranch.text = info.branchName ?: "No branch assigned"
        binding.inviteApproval.text = if (info.requiresApproval) {
            "An administrator must approve this account before you can sign in."
        } else {
            "You will be signed in immediately after registering."
        }
        binding.inviteCard.visible(true)
        binding.detailsGroup.visible(true)
    }

    private fun mobile(): String = binding.mobile.text?.toString()?.trim().orEmpty()

    private fun requestOtp() {
        if (!validateDetails(requireOtp = false)) return
        busy(true)
        lifecycleScope.launch {
            when (val result = repo.requestOtp(mobile(), purpose = "register")) {
                is ApiResult.Success -> {
                    busy(false)
                    otpRequested = true
                    binding.otpLayout.visible(true)
                    binding.submitAction.isEnabled = true
                    showInline(result.message, isError = false)
                    startResendCountdown(result.data.resendAfterSeconds)
                }

                is ApiResult.Failure -> {
                    busy(false)
                    handleFailure(result, "Could not send the OTP") { showInline(it) }
                }
            }
        }
    }

    private fun startResendCountdown(seconds: Int) {
        resendTimer?.cancel()
        binding.sendOtpAction.isEnabled = false
        resendTimer = object : CountDownTimer(seconds.coerceAtLeast(1) * 1000L, 1000L) {
            override fun onTick(millisUntilFinished: Long) {
                binding.sendOtpAction.text =
                    getString(R.string.resend_in, (millisUntilFinished / 1000L).toInt())
            }

            override fun onFinish() {
                binding.sendOtpAction.isEnabled = true
                binding.sendOtpAction.setText(R.string.resend_otp)
            }
        }.start()
    }

    private fun validateDetails(requireOtp: Boolean): Boolean {
        if (invite == null) {
            showInline("Validate the invitation code first.")
            return false
        }
        if (binding.fullName.text?.toString()?.trim().isNullOrEmpty()) {
            showInline("Enter your full name.")
            return false
        }
        if (mobile().length < 10) {
            showInline("Enter a valid mobile number.")
            return false
        }
        val password = binding.password.text?.toString().orEmpty()
        if (password.length < 8) {
            showInline("Choose a password of at least 8 characters.")
            return false
        }
        if (requireOtp && binding.otp.text?.toString()?.trim().isNullOrEmpty()) {
            showInline("Enter the OTP you received.")
            return false
        }
        showInline(null)
        return true
    }

    private fun submit() {
        if (!otpRequested) {
            showInline("Request an OTP first.")
            return
        }
        if (!validateDetails(requireOtp = true)) return

        val code = binding.inviteCode.text?.toString()?.trim().orEmpty()
        busy(true)
        lifecycleScope.launch {
            val result = repo.register(
                inviteCode = code,
                fullName = binding.fullName.text?.toString()?.trim().orEmpty(),
                identifier = mobile(),
                otp = binding.otp.text?.toString()?.trim().orEmpty(),
                email = binding.email.text?.toString()?.trim(),
                password = binding.password.text?.toString().orEmpty(),
                employeeCode = binding.employeeCode.text?.toString()?.trim(),
            )
            busy(false)
            when (result) {
                is ApiResult.Success -> {
                    // A token means the invite did not need approval.
                    SyncScheduler.scheduleTracking(
                        this@RegisterActivity,
                        session.config.gpsPingIntervalSeconds,
                    )
                    startActivity(
                        Intent(this@RegisterActivity, DashboardActivity::class.java)
                            .addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP),
                    )
                    finish()
                }

                is ApiResult.Failure -> {
                    if (result.code == ErrorCodes.ACCOUNT_PENDING) {
                        // Not an error: the account exists and awaits approval.
                        showError(result.message, getString(R.string.account_pending_title))
                        binding.submitAction.isEnabled = false
                    } else {
                        handleFailure(result, "Registration failed") { showInline(it) }
                    }
                }
            }
        }
    }

    private fun busy(on: Boolean) {
        binding.progress.visible(on)
        binding.validateAction.isEnabled = !on
        binding.submitAction.isEnabled = !on && otpRequested
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
