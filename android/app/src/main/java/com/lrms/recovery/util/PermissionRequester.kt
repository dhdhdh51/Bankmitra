package com.lrms.recovery.util

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.net.Uri
import android.os.Build
import android.provider.Settings
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AlertDialog
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat

/**
 * Runtime permissions, with the "permanently denied" case handled properly.
 *
 * Android gives no callback that says "the user ticked Don't ask again"; the
 * request simply returns denied without showing a dialog. The heuristic used here
 * is the standard one: if the permission is denied AND
 * `shouldShowRequestPermissionRationale` is false AFTER we have asked at least
 * once, the user has to be sent to system Settings. We say so explicitly instead
 * of leaving them tapping a dead button.
 *
 * MUST be constructed during `onCreate` - `registerForActivityResult` refuses to
 * register once the Activity has started.
 */
class PermissionRequester(private val activity: AppCompatActivity) {

    private var pendingCallback: ((Boolean) -> Unit)? = null
    private var pendingPermissions: Array<String> = emptyArray()
    private var pendingRationale: String = ""
    private var hasAsked = false

    private val launcher = activity.registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions(),
    ) { result ->
        val granted = result.isNotEmpty() && result.values.all { it }
        val callback = pendingCallback
        pendingCallback = null
        if (granted) {
            callback?.invoke(true)
            return@registerForActivityResult
        }
        // Denied: work out whether it is recoverable in-app.
        val permanentlyDenied = pendingPermissions.none {
            activity.shouldShowRequestPermissionRationale(it)
        }
        if (permanentlyDenied && hasAsked) {
            showSettingsDialog(pendingRationale)
        } else {
            activity.showError(pendingRationale, "Permission needed")
        }
        callback?.invoke(false)
    }

    private val notificationLauncher = activity.registerForActivityResult(
        ActivityResultContracts.RequestPermission(),
    ) { /* Notifications are a nice-to-have; no blocking message if declined. */ }

    /** Fine + coarse location. Required by the visit form and attendance. */
    fun ensureLocation(onResult: (Boolean) -> Unit) = ensure(
        permissions = arrayOf(
            Manifest.permission.ACCESS_FINE_LOCATION,
            Manifest.permission.ACCESS_COARSE_LOCATION,
        ),
        rationale = "LRMS records where each visit happened. Without location " +
            "permission the server will reject the visit, so this screen cannot be used.",
        onResult = onResult,
    )

    fun ensureCamera(onResult: (Boolean) -> Unit) = ensure(
        permissions = arrayOf(Manifest.permission.CAMERA),
        rationale = "The camera is needed to attach visit photos, selfies and receipt " +
            "images. Nothing is uploaded until you press Submit.",
        onResult = onResult,
    )

    /**
     * POST_NOTIFICATIONS only exists on API 33+. Asked once, quietly: it is only
     * used for sync progress, so a refusal is not fatal.
     */
    fun requestNotificationsIfNeeded() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        val granted = ContextCompat.checkSelfPermission(
            activity,
            Manifest.permission.POST_NOTIFICATIONS,
        ) == PackageManager.PERMISSION_GRANTED
        if (!granted) notificationLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
    }

    private fun ensure(
        permissions: Array<String>,
        rationale: String,
        onResult: (Boolean) -> Unit,
    ) {
        val allGranted = permissions.all {
            ContextCompat.checkSelfPermission(activity, it) == PackageManager.PERMISSION_GRANTED
        }
        if (allGranted) {
            onResult(true)
            return
        }
        pendingCallback = onResult
        pendingPermissions = permissions
        pendingRationale = rationale
        hasAsked = true
        launcher.launch(permissions)
    }

    private fun showSettingsDialog(message: String) {
        if (activity.isFinishing) return
        AlertDialog.Builder(activity)
            .setTitle("Permission blocked")
            .setMessage(
                message + "\n\nYou previously chose \"Don't ask again\", so it has to be " +
                    "enabled from Android Settings.",
            )
            .setPositiveButton("Open Settings") { _, _ ->
                val intent = Intent(
                    Settings.ACTION_APPLICATION_DETAILS_SETTINGS,
                    Uri.fromParts("package", activity.packageName, null),
                )
                try {
                    activity.startActivity(intent)
                } catch (e: Exception) {
                    activity.showError("Could not open Android Settings on this device.")
                }
            }
            .setNegativeButton(android.R.string.cancel, null)
            .show()
    }
}
