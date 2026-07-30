package com.lrms.recovery.util

import android.content.Context
import android.os.Build
import com.lrms.recovery.BuildConfig
import java.io.File
import java.io.PrintWriter
import java.io.StringWriter
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Writes the last fatal crash to a file so it can be read ON THE HANDSET.
 *
 * WHY THIS EXISTS
 * ---------------
 * The people who deploy and run LRMS do it from a phone. `adb logcat` needs a
 * PC, so "the app crashed" is otherwise the entire bug report. On the next
 * launch the app shows the saved stack trace with a Share button, which turns
 * an unactionable complaint into something fixable.
 *
 * WHAT IT DELIBERATELY DOES NOT DO
 * --------------------------------
 * It does not phone home. Nothing is uploaded anywhere; the report stays in
 * app-private storage until the user chooses to share it. A stack trace holds
 * no session token, OTP or password - none of those are ever placed in an
 * exception message - but the report is still treated as private data.
 *
 * LIMITATION worth knowing: a crash that happens while the SYSTEM inflates the
 * window background (before any Activity code runs) is caught here, but the
 * report can only be displayed once the app is able to start at all.
 */
object CrashReporter {

    private const val FILE_NAME = "last_crash.txt"
    private const val MAX_BYTES = 64 * 1024

    /** Call once, first thing in Application.onCreate(). */
    fun install(context: Context) {
        val appContext = context.applicationContext
        val previous = Thread.getDefaultUncaughtExceptionHandler()

        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            // Wrapped in its own try/catch: a failure to record the crash must
            // never replace the real crash with a confusing second one.
            try {
                write(appContext, thread, throwable)
            } catch (ignored: Throwable) {
                // Nothing useful left to do here.
            }
            // Always let the platform handler finish the job so the process dies
            // normally and Play/vendor crash reporting still sees it.
            previous?.uncaughtException(thread, throwable)
        }
    }

    private fun write(context: Context, thread: Thread, throwable: Throwable) {
        val stack = StringWriter().also { sw ->
            PrintWriter(sw).use { throwable.printStackTrace(it) }
        }.toString()

        val report = buildString {
            appendLine("LRMS crash report")
            appendLine("=================")
            appendLine("When         : " + SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.US).format(Date()))
            appendLine("App version  : ${BuildConfig.APP_VERSION_NAME} (${BuildConfig.BUILD_TYPE})")
            appendLine("Package      : ${BuildConfig.APPLICATION_ID}")
            appendLine("Device       : ${Build.MANUFACTURER} ${Build.MODEL}")
            appendLine("Android      : ${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})")
            appendLine("Thread       : ${thread.name}")
            appendLine()
            appendLine("Cause: ${rootCause(throwable)}")
            appendLine()
            appendLine(stack)
        }

        file(context).writeText(report.take(MAX_BYTES))
    }

    /** The innermost exception, which is nearly always the useful one. */
    private fun rootCause(throwable: Throwable): String {
        var current: Throwable = throwable
        while (true) {
            val next = current.cause ?: break
            if (next === current) break
            current = next
        }
        return "${current.javaClass.name}: ${current.message ?: "(no message)"}"
    }

    private fun file(context: Context): File = File(context.filesDir, FILE_NAME)

    /** The saved report, or null when the last run exited cleanly. */
    fun pendingReport(context: Context): String? {
        val f = file(context)
        if (!f.isFile || f.length() == 0L) return null
        return try {
            f.readText()
        } catch (e: Throwable) {
            null
        }
    }

    /** Called once the user has seen the report. */
    fun clear(context: Context) {
        try {
            file(context).delete()
        } catch (ignored: Throwable) {
            // A stale report is harmless; it will simply be shown again.
        }
    }
}
