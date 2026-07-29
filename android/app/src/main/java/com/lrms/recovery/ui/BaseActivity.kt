package com.lrms.recovery.ui

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.net.ErrorCodes
import com.lrms.recovery.data.prefs.AppPrefs
import com.lrms.recovery.data.prefs.SessionStore
import com.lrms.recovery.data.repo.LrmsRepository
import com.lrms.recovery.util.showError

/**
 * Shared plumbing for every screen.
 *
 * The important part is [handleFailure]: it is the ONE place that decides what a
 * failed API call looks like to the user. Every screen routes failures through it,
 * which is how the project rule "no silent catch" is actually enforced rather than
 * just hoped for.
 */
abstract class BaseActivity : AppCompatActivity() {

    protected val repo: LrmsRepository by lazy { LrmsRepository.get(this) }
    protected val session: SessionStore by lazy { SessionStore.get(this) }
    protected val prefs: AppPrefs by lazy { AppPrefs.get(this) }

    /** Set true on screens that must not be reachable without a token. */
    protected open val requiresSession: Boolean = true

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (requiresSession && !session.isSignedIn) {
            goToLogin("Please sign in to continue.")
        }
    }

    /**
     * Shows a failure to the user and returns true when it was handled by
     * bouncing to the login screen (in which case the caller should stop).
     *
     * @param title dialog title, e.g. "Could not load customers"
     * @param inline optional hook to also render the message inside the screen
     */
    protected fun handleFailure(
        failure: ApiResult.Failure,
        title: String = "Something went wrong",
        inline: ((String) -> Unit)? = null,
    ): Boolean {
        if (failure.code in ErrorCodes.SESSION_GONE) {
            session.clear()
            goToLogin(failure.message)
            return true
        }
        if (failure.code == ErrorCodes.DEVICE_MISMATCH) {
            showError(failure.message, "Device not recognised")
            return false
        }
        // Field errors are more useful than the generic message when present.
        val detail = if (failure.fieldErrors.isEmpty()) {
            failure.message
        } else {
            failure.message + "\n\n" + failure.fieldErrors.entries.joinToString("\n") {
                "\u2022 ${it.value}"
            }
        }
        if (inline != null) inline(detail) else showError(detail, title)
        return false
    }

    protected fun goToLogin(reason: String?) {
        val intent = Intent(this, LoginActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP or Intent.FLAG_ACTIVITY_NEW_TASK)
            reason?.let { putExtra(LoginActivity.EXTRA_REASON, it) }
        }
        startActivity(intent)
        finish()
    }
}
