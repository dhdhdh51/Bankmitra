package com.lrms.recovery.ui

import android.content.Intent
import android.os.Bundle
import android.view.View
import android.view.ViewGroup
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.ViewCompat
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import com.lrms.recovery.R
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

    /**
     * Whether the area behind the status bar is dark, which decides if the clock
     * and battery icons are drawn light or dark.
     *
     * True for every screen with a toolbar (colorPrimary is dark green) and for
     * the splash screen. LoginActivity overrides it because its background is the
     * pale surface colour - white-on-white icons would be invisible.
     */
    protected open val darkStatusBarBackground: Boolean = true

    override fun onCreate(savedInstanceState: Bundle?) {
        // Declare edge-to-edge explicitly on EVERY api level rather than letting
        // Android 15+ force it on us only on new handsets. Without this the same
        // build behaves differently depending on the phone, which is exactly the
        // kind of thing that is impossible to debug from a field report.
        WindowCompat.setDecorFitsSystemWindows(window, false)
        super.onCreate(savedInstanceState)
        if (requiresSession && !session.isSignedIn) {
            goToLogin("Please sign in to continue.")
        }
    }

    /**
     * Called by the framework once setContentView() has finished, which makes it
     * the one hook that runs for every screen without any of them having to
     * remember to call it.
     */
    override fun onContentChanged() {
        super.onContentChanged()
        applyWindowInsets()
    }

    private var insetsBound = false

    /**
     * Keeps content out from under the status bar, the navigation bar and the
     * keyboard.
     *
     * Needed because targetSdk 36 opts the app into edge-to-edge: the window now
     * spans the whole screen and `android:statusBarColor` is ignored, so a
     * toolbar placed at the top of a layout is drawn UNDERNEATH the status bar.
     *
     * The top inset is handed to the toolbar rather than to the root so that the
     * toolbar's own colorPrimary background extends up behind the status bar -
     * that reproduces the pre-edge-to-edge look instead of leaving a bare strip.
     * This is why the toolbars use layout_height="wrap_content" with
     * minHeight="?attr/actionBarSize": with a fixed height, extra top padding
     * would squash the title instead of making the bar taller.
     */
    private fun applyWindowInsets() {
        if (insetsBound) return
        val container = findViewById<ViewGroup>(android.R.id.content) ?: return
        val root = container.getChildAt(0) ?: return
        insetsBound = true

        val toolbar: View? = root.findViewById(R.id.toolbar)
        val bottomNav: View? = root.findViewById(R.id.bottomNav)

        // android:windowLightStatusBar in the theme cannot vary per screen, and
        // it is ignored once the window is edge-to-edge, so set it here.
        WindowCompat.getInsetsController(window, root).isAppearanceLightStatusBars =
            !darkStatusBarBackground

        // Remember the paddings authored in XML. The listener fires repeatedly
        // (rotation, keyboard, gesture-nav changes) and must not accumulate.
        val baseLeft = root.paddingLeft
        val baseRight = root.paddingRight
        val baseTop = root.paddingTop
        val baseBottom = root.paddingBottom
        val baseToolbarTop = toolbar?.paddingTop ?: 0
        val baseNavBottom = bottomNav?.paddingBottom ?: 0

        ViewCompat.setOnApplyWindowInsetsListener(root) { view, windowInsets ->
            val bars = windowInsets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            val ime = windowInsets.getInsets(WindowInsetsCompat.Type.ime()).bottom

            toolbar?.updatePadding(top = baseToolbarTop + bars.top)
            bottomNav?.updatePadding(bottom = baseNavBottom + bars.bottom)

            view.updatePadding(
                left = baseLeft + bars.left,
                right = baseRight + bars.right,
                // Only the root can own the top inset when there is no toolbar.
                top = if (toolbar == null) baseTop + bars.top else baseTop,
                bottom = when {
                    // Keyboard open: lift everything, including the bottom bar.
                    ime > 0 -> baseBottom + ime
                    // Otherwise the bottom bar already absorbed the nav bar.
                    bottomNav != null -> baseBottom
                    else -> baseBottom + bars.bottom
                },
            )
            WindowInsetsCompat.CONSUMED
        }

        ViewCompat.requestApplyInsets(root)
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
