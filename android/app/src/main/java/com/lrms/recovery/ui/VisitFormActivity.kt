package com.lrms.recovery.ui

import android.app.DatePickerDialog
import android.net.Uri
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.lrms.recovery.R
import com.lrms.recovery.data.db.QueueDb
import com.lrms.recovery.data.db.QueueStore
import com.lrms.recovery.data.model.VisitDraft
import com.lrms.recovery.data.model.newVisitUid
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.net.ErrorCodes
import com.lrms.recovery.databinding.ActivityVisitFormBinding
import com.lrms.recovery.location.LocationGate
import com.lrms.recovery.location.LocationVerdict
import com.lrms.recovery.sync.SyncScheduler
import com.lrms.recovery.ui.adapter.PhotoAdapter
import com.lrms.recovery.util.CodedSpinner
import com.lrms.recovery.util.DeviceInfo
import com.lrms.recovery.util.Formats
import com.lrms.recovery.util.PermissionRequester
import com.lrms.recovery.util.PhotoStore
import com.lrms.recovery.util.confirm
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.visible
import java.io.File
import java.util.Calendar
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * The GPS-mandatory visit form.
 *
 * TWO THINGS IN HERE ARE NOT OBVIOUS
 * ----------------------------------
 * 1. MOCK-GPS / NULL-ISLAND BLOCKING. [LocationGate] judges every fix and this
 *    screen keeps Submit DISABLED unless the verdict is `Usable`. The reason for
 *    blocking client-side, when the server also checks, is that a rejection after
 *    the agent has left the customer's house means the visit has to be redone. The
 *    banner always states which of the three rules is failing.
 *
 * 2. IDEMPOTENCY. `visit_uid` is generated ONCE, on the first Submit press, and
 *    held in [visitUid] for the lifetime of this screen. A retry after a timeout,
 *    and every later retry made by the background worker, reuses that exact value,
 *    so the server can answer `duplicate: true` instead of double-recording the
 *    visit.
 */
class VisitFormActivity : BaseActivity() {

    private lateinit var binding: ActivityVisitFormBinding
    private lateinit var permissions: PermissionRequester
    private lateinit var locationGate: LocationGate
    private lateinit var statusSpinner: CodedSpinner
    private lateinit var possibilitySpinner: CodedSpinner
    private lateinit var photoAdapter: PhotoAdapter

    private var loanId = 0
    private var customerLabel = ""

    private var verdict: LocationVerdict = LocationVerdict.Waiting
    private val photos = ArrayList<File>()
    private var pendingPhoto: File? = null

    /** Generated once; never regenerated. See the class comment. */
    private var visitUid: String? = null
    private var submitting = false

    private val takePicture = registerForActivityResult(
        ActivityResultContracts.TakePicture(),
    ) { saved ->
        val file = pendingPhoto
        pendingPhoto = null
        if (file == null) return@registerForActivityResult
        if (!saved || !file.exists() || file.length() == 0L) {
            PhotoStore.delete(file)
            // Explicit message: a silently missing photo would be discovered only
            // when the server answers `photo_required`.
            showError("The photo was not saved. Try again, or check that there is free storage.")
            return@registerForActivityResult
        }
        lifecycleScope.launch {
            withContext(Dispatchers.IO) { PhotoStore.compressInPlace(file) }
            photos.add(file)
            photoAdapter.submit(photos)
            updatePhotoHeader()
        }
    }

    companion object {
        const val EXTRA_LOAN_ID = "loan_id"
        const val EXTRA_CUSTOMER_LABEL = "customer_label"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!session.isSignedIn) return
        binding = ActivityVisitFormBinding.inflate(layoutInflater)
        setContentView(binding.root)

        loanId = intent.getIntExtra(EXTRA_LOAN_ID, 0)
        customerLabel = intent.getStringExtra(EXTRA_CUSTOMER_LABEL).orEmpty()
        binding.customerLabel.text = customerLabel
        binding.toolbar.setNavigationOnClickListener { confirmDiscard() }

        permissions = PermissionRequester(this)
        locationGate = LocationGate(this)

        statusSpinner = CodedSpinner.attach(
            this,
            binding.visitStatus,
            R.array.visit_status_labels,
            R.array.visit_status_values,
        )
        possibilitySpinner = CodedSpinner.attach(
            this,
            binding.recoveryPossibility,
            R.array.recovery_possibility_labels,
            R.array.recovery_possibility_values,
        )
        // promise_amount / promise_date are required only for visit_status=promise.
        statusSpinner.onChanged { value ->
            binding.promiseGroup.visible(value == "promise")
        }

        photoAdapter = PhotoAdapter { file ->
            photos.remove(file)
            PhotoStore.delete(file)
            photoAdapter.submit(photos)
            updatePhotoHeader()
        }
        binding.photoStrip.layoutManager =
            LinearLayoutManager(this, LinearLayoutManager.HORIZONTAL, false)
        binding.photoStrip.adapter = photoAdapter
        updatePhotoHeader()

        binding.addPhotoAction.setOnClickListener { capturePhoto() }
        binding.clearSignatureAction.setOnClickListener { binding.signaturePad.clear() }
        binding.promiseDate.setOnClickListener { pickPromiseDate() }
        binding.submitAction.setOnClickListener { submit() }

        if (loanId <= 0) {
            showError("This visit has no loan attached and cannot be submitted.")
            binding.submitAction.isEnabled = false
            return
        }

        permissions.ensureLocation { granted ->
            if (granted) {
                startLocation()
            } else {
                applyVerdict(
                    LocationVerdict.Blocked(
                        LocationVerdict.Blocked.Reason.NO_PERMISSION,
                        getString(R.string.err_permission_location),
                    ),
                )
            }
        }
    }

    override fun onDestroy() {
        locationGate.stop()
        super.onDestroy()
    }

    // ------------------------------------------------------------------ GPS

    private fun startLocation() {
        locationGate.start { v -> applyVerdict(v) }
    }

    private fun applyVerdict(v: LocationVerdict) {
        verdict = v
        when (v) {
            LocationVerdict.Waiting -> {
                binding.gpsBanner.setBackgroundResource(R.drawable.bg_banner_warning)
                binding.gpsBanner.setText(R.string.gps_acquiring)
                binding.submitAction.isEnabled = false
            }

            is LocationVerdict.Blocked -> {
                binding.gpsBanner.setBackgroundResource(R.drawable.bg_banner_warning)
                binding.gpsBanner.text = v.message
                binding.submitAction.isEnabled = false
            }

            is LocationVerdict.Usable -> {
                binding.gpsBanner.setBackgroundResource(R.drawable.bg_banner_ok)
                binding.gpsBanner.text = getString(
                    R.string.gps_ok,
                    v.latitude,
                    v.longitude,
                    v.accuracyM ?: 0.0,
                )
                binding.submitAction.isEnabled = !submitting
            }
        }
    }

    // ---------------------------------------------------------------- photos

    private fun updatePhotoHeader() {
        val min = session.config.visitPhotoMin
        binding.photoHeader.text = getString(R.string.photos_section) +
            "  (${photos.size}/${maxOf(min, photos.size)}" +
            (if (min > 0) ", minimum $min" else "") + ")"
    }

    private fun capturePhoto() {
        permissions.ensureCamera { granted ->
            if (!granted) return@ensureCamera
            val file = PhotoStore.newCaptureFile(this, "visit")
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

    private fun pickPromiseDate() {
        val cal = Calendar.getInstance()
        DatePickerDialog(
            this,
            { _, year, month, day ->
                binding.promiseDate.setText(
                    String.format("%04d-%02d-%02d", year, month + 1, day),
                )
            },
            cal.get(Calendar.YEAR),
            cal.get(Calendar.MONTH),
            cal.get(Calendar.DAY_OF_MONTH),
        ).apply {
            // A promise to pay in the past is meaningless.
            datePicker.minDate = System.currentTimeMillis()
        }.show()
    }

    // ---------------------------------------------------------------- submit

    private fun buildDraft(fix: LocationVerdict.Usable): VisitDraft? {
        val status = statusSpinner.selectedValue
        val promiseAmountText = binding.promiseAmount.text?.toString()?.trim().orEmpty()
        val promiseDateText = binding.promiseDate.text?.toString()?.trim().orEmpty()

        if (status == "promise") {
            val amount = promiseAmountText.toDoubleOrNull()
            if (amount == null || amount <= 0.0 || !Formats.isValidWireDate(promiseDateText)) {
                showError(getString(R.string.promise_fields_required), "Missing promise details")
                return null
            }
        }

        val minPhotos = session.config.visitPhotoMin
        if (photos.size < minPhotos) {
            showError(
                getString(R.string.photo_required_msg, minPhotos),
                "Photo required",
            )
            return null
        }

        // The UUID is created here, exactly once per screen instance.
        val uid = visitUid ?: newVisitUid().also { visitUid = it }

        return VisitDraft(
            visitUid = uid,
            loanId = loanId,
            visitedAt = Formats.nowWireDateTime(),
            latitude = fix.latitude,
            longitude = fix.longitude,
            accuracyM = fix.accuracyM,
            // Always false here: a mocked fix never reaches this point because
            // Submit stays disabled. Reported honestly regardless.
            isMockLocation = false,
            visitStatus = status,
            customerAvailable = binding.customerAvailable.isChecked,
            houseLocked = binding.houseLocked.isChecked,
            metPerson = binding.metPerson.text?.toString()?.trim(),
            metRelation = binding.metRelation.text?.toString()?.trim(),
            occupation = binding.occupation.text?.toString()?.trim(),
            recoveryPossibility = possibilitySpinner.selectedValue,
            promiseAmount = promiseAmountText.toDoubleOrNull()?.takeIf { status == "promise" },
            promiseDate = promiseDateText.takeIf { status == "promise" && it.isNotEmpty() },
            recommendation = binding.recommendation.text?.toString()?.trim(),
            remarks = binding.remarks.text?.toString()?.trim(),
            signatureBase64 = binding.signaturePad.exportBase64Png(),
            photoPaths = photos.map { it.absolutePath },
            appVersion = DeviceInfo.appVersion,
            customerLabel = customerLabel,
        )
    }

    private fun submit() {
        val fix = verdict as? LocationVerdict.Usable
        if (fix == null) {
            // Restate the exact blocking reason rather than a generic message.
            val message = (verdict as? LocationVerdict.Blocked)?.message
                ?: getString(R.string.gps_blocked_null)
            showError(message, "Location required")
            return
        }
        val draft = buildDraft(fix) ?: return

        busy(true)
        lifecycleScope.launch {
            if (!DeviceInfo.isOnline(this@VisitFormActivity)) {
                queueAndFinish(draft, "You are offline. ")
                return@launch
            }
            when (val result = repo.submitVisit(draft)) {
                is ApiResult.Success -> {
                    busy(false)
                    val extra = buildString {
                        if (result.data.duplicate) append("Already recorded on the server. ")
                        result.data.distanceFromCustomerM?.let {
                            append("Recorded ${it} m from the customer's saved location. ")
                        }
                        if (result.data.photoErrors.isNotEmpty()) {
                            append("Some photos were rejected: ")
                            append(result.data.photoErrors.joinToString("; "))
                        }
                    }
                    showDoneDialog(result.message + if (extra.isEmpty()) "" else "\n\n$extra")
                }

                is ApiResult.Failure -> {
                    if (result.isTransient) {
                        // Keep the work: same UID, queued for the worker.
                        queueAndFinish(draft, result.message + " ")
                    } else {
                        busy(false)
                        if (result.code == ErrorCodes.DUPLICATE) {
                            showDoneDialog("This visit was already recorded on the server.")
                        } else {
                            handleFailure(result, "Visit was not accepted")
                        }
                    }
                }
            }
        }
    }

    private suspend fun queueAndFinish(draft: VisitDraft, prefix: String) {
        withContext(Dispatchers.IO) {
            QueueStore.get(this@VisitFormActivity).enqueue(
                kind = QueueDb.KIND_VISIT,
                uid = draft.visitUid,
                payload = draft.toJson(),
                label = customerLabel,
            )
        }
        SyncScheduler.requestQueueFlush(this)
        busy(false)
        showDoneDialog(prefix + getString(R.string.queued_offline))
    }

    private fun showDoneDialog(message: String) {
        confirm("Visit saved", message, getString(R.string.close)) { finish() }
    }

    private fun confirmDiscard() {
        confirm(
            "Discard this visit?",
            "Nothing has been submitted yet. Any photos and the signature will be lost.",
            "Discard",
        ) {
            photos.forEach { PhotoStore.delete(it) }
            finish()
        }
    }

    private fun busy(on: Boolean) {
        submitting = on
        binding.progress.visible(on)
        binding.submitAction.isEnabled = !on && verdict is LocationVerdict.Usable
    }
}
