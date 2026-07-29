package com.lrms.recovery.ui

import android.net.Uri
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.model.AttendanceToday
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.databinding.ActivityAttendanceBinding
import com.lrms.recovery.location.LocationGate
import com.lrms.recovery.location.LocationVerdict
import com.lrms.recovery.util.Formats
import com.lrms.recovery.util.PermissionRequester
import com.lrms.recovery.util.PhotoStore
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.snack
import com.lrms.recovery.util.visible
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * GPS + selfie attendance.
 *
 * Attendance is a duty record, so - like a visit - it is gated on a usable GPS
 * fix: check in / check out stay disabled until [LocationGate] returns `Usable`.
 * The selfie is optional (a broken camera should not stop an agent starting
 * work) and the screen says so rather than failing mysteriously.
 *
 * Attendance is intentionally NOT queued offline. A check-in timestamp recorded
 * on the handset and uploaded hours later would be misleading, so the screen
 * requires a live connection and says exactly that when there is none.
 */
class AttendanceActivity : BaseActivity() {

    private lateinit var binding: ActivityAttendanceBinding
    private lateinit var permissions: PermissionRequester
    private lateinit var locationGate: LocationGate

    private var verdict: LocationVerdict = LocationVerdict.Waiting
    private var today: AttendanceToday? = null
    private var selfie: File? = null
    private var pendingPhoto: File? = null
    private var busy = false

    private val takePicture = registerForActivityResult(
        ActivityResultContracts.TakePicture(),
    ) { saved ->
        val file = pendingPhoto
        pendingPhoto = null
        if (file == null) return@registerForActivityResult

        if (!saved || !file.exists() || file.length() == 0L) {
            PhotoStore.delete(file)
            showError(getString(R.string.err_photo_save))
            return@registerForActivityResult
        }

        lifecycleScope.launch {
            withContext(Dispatchers.IO) { PhotoStore.compressInPlace(file) }
            selfie?.let { PhotoStore.delete(it) }
            selfie = file
            val thumb = withContext(Dispatchers.IO) { PhotoStore.thumbnail(file) }
            if (thumb != null) {
                binding.selfieThumb.setImageBitmap(thumb)
                binding.selfieThumb.visible(true)
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!session.isSignedIn) return

        binding = ActivityAttendanceBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.toolbar.setNavigationOnClickListener { finish() }

        permissions = PermissionRequester(this)
        locationGate = LocationGate(this)

        binding.selfieAction.setOnClickListener { captureSelfie() }
        binding.checkInAction.setOnClickListener { checkIn() }
        binding.checkOutAction.setOnClickListener { checkOut() }

        permissions.ensureLocation { granted ->
            if (granted) {
                locationGate.start { v -> applyVerdict(v) }
            } else {
                applyVerdict(
                    LocationVerdict.Blocked(
                        LocationVerdict.Blocked.Reason.NO_PERMISSION,
                        getString(R.string.err_permission_location),
                    ),
                )
            }
        }

        load()
    }

    override fun onDestroy() {
        locationGate.stop()
        super.onDestroy()
    }

    private fun load() {
        binding.progress.visible(true)
        lifecycleScope.launch {
            when (val result = repo.attendanceToday()) {
                is ApiResult.Success -> {
                    binding.progress.visible(false)
                    today = result.data
                    render(result.data)
                }

                is ApiResult.Failure -> {
                    binding.progress.visible(false)
                    // Show the reason but leave the buttons usable: a failed
                    // status read must not stop someone marking attendance.
                    binding.statusText.text =
                        "Today's attendance could not be read: " + result.message
                    handleFailure(result, "Could not load attendance")
                    refreshButtons()
                }
            }
        }
    }

    private fun render(data: AttendanceToday) {
        binding.statusText.text = when {
            data.checkedOut -> "Checked out at " + Formats.prettyDateTime(data.checkOutAt) +
                "\nYour day is closed."
            data.checkedIn -> "Checked in at " + Formats.prettyDateTime(data.checkInAt) +
                "\nYou are on duty."
            else -> "Not checked in today."
        }
        refreshButtons()
    }

    private fun applyVerdict(v: LocationVerdict) {
        verdict = v
        when (v) {
            LocationVerdict.Waiting -> {
                binding.gpsBanner.setBackgroundResource(R.drawable.bg_banner_warning)
                binding.gpsBanner.setText(R.string.gps_acquiring)
            }

            is LocationVerdict.Blocked -> {
                binding.gpsBanner.setBackgroundResource(R.drawable.bg_banner_warning)
                binding.gpsBanner.text = v.message
            }

            is LocationVerdict.Usable -> {
                binding.gpsBanner.setBackgroundResource(R.drawable.bg_banner_ok)
                binding.gpsBanner.text = getString(
                    R.string.gps_ok,
                    v.latitude,
                    v.longitude,
                    v.accuracyM ?: 0.0,
                )
            }
        }
        refreshButtons()
    }

    private fun refreshButtons() {
        val fixed = verdict is LocationVerdict.Usable && !busy
        val state = today
        binding.checkInAction.isEnabled = fixed && (state == null || !state.checkedIn)
        binding.checkOutAction.isEnabled = fixed && state != null &&
            state.checkedIn && !state.checkedOut
    }

    private fun captureSelfie() {
        permissions.ensureCamera { granted ->
            if (!granted) return@ensureCamera

            val file = PhotoStore.newCaptureFile(this, "selfie")
            if (file == null) {
                showError(getString(R.string.err_photo_save))
                return@ensureCamera
            }
            val uri: Uri? = PhotoStore.uriFor(this, file)
            if (uri == null) {
                PhotoStore.delete(file)
                showError(getString(R.string.err_photo_save))
                return@ensureCamera
            }

            pendingPhoto = file
            try {
                takePicture.launch(uri)
            } catch (e: Exception) {
                pendingPhoto = null
                PhotoStore.delete(file)
                showError(getString(R.string.err_no_camera_app))
            }
        }
    }

    private fun checkIn() {
        val fix = requireFix() ?: return
        if (!requireOnline()) return

        setBusy(true)
        lifecycleScope.launch {
            val result = repo.checkIn(
                latitude = fix.latitude,
                longitude = fix.longitude,
                accuracyM = fix.accuracyM,
                selfie = selfie,
                remarks = binding.remarks.text?.toString()?.trim(),
            )
            setBusy(false)
            when (result) {
                is ApiResult.Success -> {
                    binding.root.snack(result.message)
                    selfie = null
                    binding.selfieThumb.visible(false)
                    load()
                }
                is ApiResult.Failure -> handleFailure(result, "Check-in failed")
            }
        }
    }

    private fun checkOut() {
        val fix = requireFix() ?: return
        if (!requireOnline()) return

        setBusy(true)
        lifecycleScope.launch {
            val result = repo.checkOut(
                latitude = fix.latitude,
                longitude = fix.longitude,
                selfie = selfie,
            )
            setBusy(false)
            when (result) {
                is ApiResult.Success -> {
                    binding.root.snack(result.message)
                    selfie = null
                    binding.selfieThumb.visible(false)
                    load()
                }
                is ApiResult.Failure -> handleFailure(result, "Check-out failed")
            }
        }
    }

    private fun requireFix(): LocationVerdict.Usable? {
        val fix = verdict as? LocationVerdict.Usable
        if (fix == null) {
            showError(
                (verdict as? LocationVerdict.Blocked)?.message
                    ?: getString(R.string.gps_required_message),
                "Location required",
            )
        }
        return fix
    }

    private fun requireOnline(): Boolean {
        if (com.lrms.recovery.util.DeviceInfo.isOnline(this)) return true
        showError(
            "Attendance needs a live connection so the server records the real time. " +
                "Connect to a network and try again.",
            "No connection",
        )
        return false
    }

    private fun setBusy(on: Boolean) {
        busy = on
        binding.progress.visible(on)
        refreshButtons()
    }
}
