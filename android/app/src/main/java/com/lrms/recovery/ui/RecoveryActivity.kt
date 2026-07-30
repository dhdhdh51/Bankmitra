package com.lrms.recovery.ui

import android.net.Uri
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.lifecycle.lifecycleScope
import com.lrms.recovery.R
import com.lrms.recovery.data.db.QueueDb
import com.lrms.recovery.data.db.QueueStore
import com.lrms.recovery.data.model.RecoveryDraft
import com.lrms.recovery.data.model.newRecoveryUid
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.data.net.ErrorCodes
import com.lrms.recovery.databinding.ActivityRecoveryBinding
import com.lrms.recovery.location.LocationGate
import com.lrms.recovery.location.LocationVerdict
import com.lrms.recovery.sync.SyncScheduler
import com.lrms.recovery.util.CodedSpinner
import com.lrms.recovery.util.DeviceInfo
import com.lrms.recovery.util.Formats
import com.lrms.recovery.util.PermissionRequester
import com.lrms.recovery.util.PhotoStore
import com.lrms.recovery.util.confirm
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.visible
import java.io.File
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

/**
 * Cash / transfer / UPI collection capture.
 *
 * MONEY MAKES THE IDEMPOTENCY RULE CRITICAL
 * -----------------------------------------
 * `recovery_uid` is generated once, on the first Submit press, and reused for
 * every retry - including retries made much later by the background worker. If a
 * new UUID were generated on retry the server would happily record the same
 * Rs.25,000 twice and the borrower's balance would be wrong. See docs/API.md.
 *
 * Unlike a visit, GPS here is a nice-to-have: the server does not require it, so
 * a collection is never blocked by a poor fix. The coordinates are attached when
 * available and the screen says plainly when they are not.
 */
class RecoveryActivity : BaseActivity() {

    private lateinit var binding: ActivityRecoveryBinding
    private lateinit var permissions: PermissionRequester
    private lateinit var locationGate: LocationGate
    private lateinit var modeSpinner: CodedSpinner

    private var loanId = 0
    private var visitId = 0
    private var customerLabel = ""

    private var verdict: LocationVerdict = LocationVerdict.Waiting
    private var receiptPhoto: File? = null
    private var pendingPhoto: File? = null

    /** Created once; never regenerated. See the class comment. */
    private var recoveryUid: String? = null
    private var submitting = false

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
            receiptPhoto?.let { PhotoStore.delete(it) }
            receiptPhoto = file
            val thumb = withContext(Dispatchers.IO) { PhotoStore.thumbnail(file) }
            if (thumb != null) {
                binding.receiptThumb.setImageBitmap(thumb)
                binding.receiptThumb.visible(true)
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!session.isSignedIn) return

        binding = ActivityRecoveryBinding.inflate(layoutInflater)
        setContentView(binding.root)

        loanId = intent.getIntExtra(EXTRA_LOAN_ID, 0)
        visitId = intent.getIntExtra(EXTRA_VISIT_ID, 0)
        customerLabel = intent.getStringExtra(EXTRA_CUSTOMER_LABEL).orEmpty()

        binding.customerLabel.text = customerLabel
        binding.toolbar.setNavigationOnClickListener { confirmDiscard() }

        permissions = PermissionRequester(this)
        locationGate = LocationGate(this)

        modeSpinner = CodedSpinner.attach(
            this,
            binding.paymentMode,
            R.array.payment_mode_labels,
            R.array.payment_mode_values,
        )
        // A transaction reference is mandatory for everything except cash, so the
        // field is hidden for cash rather than silently ignored.
        modeSpinner.onChanged { mode -> applyModeRules(mode) }
        applyModeRules(modeSpinner.selectedValue)

        binding.capturePhotoAction.setOnClickListener { capturePhoto() }
        binding.submitAction.setOnClickListener { submit() }

        if (loanId <= 0) {
            showError("This collection has no loan attached and cannot be saved.")
            binding.submitAction.isEnabled = false
            return
        }

        binding.gpsNote.setText(R.string.gps_acquiring)
        permissions.ensureLocation { granted ->
            if (granted) {
                locationGate.start { v -> applyVerdict(v) }
            } else {
                binding.gpsNote.text = getString(R.string.err_permission_location) +
                    " The collection can still be saved without coordinates."
            }
        }
    }

    override fun onDestroy() {
        locationGate.stop()
        super.onDestroy()
    }

    private fun applyModeRules(mode: String) {
        val needsReference = mode != "cash"
        binding.txnReferenceLayout.visible(needsReference)
        binding.txnReferenceLayout.helperText =
            if (needsReference) getString(R.string.txn_reference_help) else null
    }

    private fun applyVerdict(v: LocationVerdict) {
        verdict = v
        binding.gpsNote.text = when (v) {
            LocationVerdict.Waiting -> getString(R.string.gps_acquiring)
            is LocationVerdict.Blocked ->
                v.message + " The collection can still be saved without coordinates."
            is LocationVerdict.Usable -> getString(
                R.string.gps_ok,
                v.latitude,
                v.longitude,
                v.accuracyM ?: 0.0,
            )
        }
    }

    private fun capturePhoto() {
        permissions.ensureCamera { granted ->
            if (!granted) return@ensureCamera

            val file = PhotoStore.newCaptureFile(this, "receipt")
            if (file == null) {
                showError(getString(R.string.err_photo_save))
                return@ensureCamera
            }
            val uri: Uri? = PhotoStore.uriFor(this, file)
            if (uri == null) {
                PhotoStore.delete(file)
                showError(getString(R.string.err_photo_share))
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

    private fun buildDraft(): RecoveryDraft? {
        val amount = binding.amount.text?.toString()?.trim()?.toDoubleOrNull()
        if (amount == null || amount <= 0.0) {
            binding.amountLayout.error = "Enter the amount collected"
            showError("Enter a collection amount greater than zero.", "Amount missing")
            return null
        }
        binding.amountLayout.error = null

        val mode = modeSpinner.selectedValue
        val reference = binding.txnReference.text?.toString()?.trim().orEmpty()
        if (mode != "cash" && reference.isEmpty()) {
            binding.txnReferenceLayout.error = "Required for ${mode.uppercase()}"
            showError(
                "A transaction reference (UTR, UPI reference or cheque number) is required for " +
                    mode.uppercase() + " collections.",
                "Reference missing",
            )
            return null
        }
        binding.txnReferenceLayout.error = null

        val fix = verdict as? LocationVerdict.Usable
        val uid = recoveryUid ?: newRecoveryUid().also { recoveryUid = it }

        return RecoveryDraft(
            recoveryUid = uid,
            loanId = loanId,
            visitId = visitId.takeIf { it > 0 },
            amount = amount,
            paymentMode = mode,
            txnReference = reference.takeIf { it.isNotEmpty() },
            receiptNumber = binding.receiptNumber.text?.toString()?.trim()?.takeIf { it.isNotEmpty() },
            collectedAt = Formats.nowWireDateTime(),
            latitude = fix?.latitude,
            longitude = fix?.longitude,
            remarks = binding.remarks.text?.toString()?.trim(),
            receiptPhotoPath = receiptPhoto?.absolutePath,
            customerLabel = customerLabel,
        )
    }

    private fun submit() {
        if (submitting) return
        val draft = buildDraft() ?: return

        busy(true)
        lifecycleScope.launch {
            if (!DeviceInfo.isOnline(this@RecoveryActivity)) {
                queueAndFinish(draft, "You are offline. ")
                return@launch
            }

            when (val result = repo.submitRecovery(draft)) {
                is ApiResult.Success -> {
                    busy(false)
                    val data = result.data
                    val message = buildString {
                        append(result.message)
                        if (data.duplicate) {
                            append("\n\nThis receipt was already recorded on the server; it has not been counted twice.")
                        }
                        data.receiptNumber?.let { append("\n\nReceipt number: $it") }
                        data.loanOutstandingAfter?.let {
                            append("\nOutstanding after payment: ${Formats.money(it)}")
                        }
                        append("\n\nThe branch still has to verify this collection.")
                    }
                    showDoneDialog(message)
                }

                is ApiResult.Failure -> {
                    if (result.isTransient) {
                        // Keep the work with the SAME uid so a retry cannot double count.
                        queueAndFinish(draft, result.message + " ")
                    } else {
                        busy(false)
                        if (result.code == ErrorCodes.DUPLICATE) {
                            showDoneDialog("This receipt was already recorded on the server.")
                        } else {
                            handleFailure(result, "Collection was not accepted")
                        }
                    }
                }
            }
        }
    }

    private suspend fun queueAndFinish(draft: RecoveryDraft, prefix: String) {
        withContext(Dispatchers.IO) {
            QueueStore.get(this@RecoveryActivity).enqueue(
                kind = QueueDb.KIND_RECOVERY,
                uid = draft.recoveryUid,
                payload = draft.toJson(),
                label = "$customerLabel  ${Formats.money(draft.amount)}",
            )
        }
        SyncScheduler.requestQueueFlush(this)
        busy(false)
        showDoneDialog(
            prefix + getString(R.string.queued_offline) +
                "\n\nDo NOT re-enter this collection: it is saved and will upload once.",
        )
    }

    private fun showDoneDialog(message: String) {
        confirm("Collection saved", message, getString(R.string.close)) { finish() }
    }

    private fun confirmDiscard() {
        val hasInput = !binding.amount.text.isNullOrBlank() || receiptPhoto != null
        if (!hasInput) {
            finish()
            return
        }
        confirm(
            "Discard this collection?",
            "Nothing has been submitted yet. The amount and receipt photo will be lost.",
            "Discard",
        ) {
            receiptPhoto?.let { PhotoStore.delete(it) }
            finish()
        }
    }

    private fun busy(on: Boolean) {
        submitting = on
        binding.progress.visible(on)
        binding.submitAction.isEnabled = !on
    }

    companion object {
        const val EXTRA_LOAN_ID = "loan_id"
        const val EXTRA_VISIT_ID = "visit_id"
        const val EXTRA_CUSTOMER_LABEL = "customer_label"
    }
}
