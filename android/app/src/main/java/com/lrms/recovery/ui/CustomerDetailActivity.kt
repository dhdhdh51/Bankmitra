package com.lrms.recovery.ui

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import com.lrms.recovery.data.model.LoanDetail
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.databinding.ActivityCustomerDetailBinding
import com.lrms.recovery.ui.adapter.VisitAdapter
import com.lrms.recovery.util.Formats
import com.lrms.recovery.util.showError
import com.lrms.recovery.util.visible
import kotlinx.coroutines.launch

/** `GET /loans/{id}`: profile, call button, visit history and the entry to a visit. */
class CustomerDetailActivity : BaseActivity() {

    private lateinit var binding: ActivityCustomerDetailBinding
    private val visitAdapter = VisitAdapter()

    private var loanId = 0
    private var detail: LoanDetail? = null

    companion object {
        const val EXTRA_LOAN_ID = "loan_id"
        const val EXTRA_NAME = "name"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!session.isSignedIn) return
        binding = ActivityCustomerDetailBinding.inflate(layoutInflater)
        setContentView(binding.root)

        loanId = intent.getIntExtra(EXTRA_LOAN_ID, 0)
        binding.toolbar.title = intent.getStringExtra(EXTRA_NAME) ?: "Customer"
        binding.toolbar.setNavigationOnClickListener { finish() }

        binding.visitList.layoutManager = LinearLayoutManager(this)
        binding.visitList.adapter = visitAdapter
        binding.swipeRefresh.setOnRefreshListener { load(pull = true) }

        binding.callAction.setOnClickListener { call() }
        binding.startVisitAction.setOnClickListener { startVisit() }
        binding.recoveryAction.setOnClickListener { recordRecovery() }

        if (loanId <= 0) {
            showError("This customer record is missing its loan id and cannot be opened.")
            return
        }
        load(pull = false)
    }

    override fun onResume() {
        super.onResume()
        // A visit or recovery may have been submitted since we were last visible.
        if (loanId > 0 && detail != null) load(pull = true)
    }

    private fun load(pull: Boolean) {
        if (!pull) binding.progress.visible(true)
        lifecycleScope.launch {
            val result = repo.loanDetail(loanId)
            binding.progress.visible(false)
            binding.swipeRefresh.isRefreshing = false
            when (result) {
                is ApiResult.Success -> render(result.data)
                is ApiResult.Failure -> handleFailure(result, "Could not load this customer")
            }
        }
    }

    private fun render(data: LoanDetail) {
        detail = data
        val s = data.summary
        binding.toolbar.title = s.fullName
        binding.name.text = s.fullName
        binding.guardian.text = s.guardianName?.let { "S/o, D/o or W/o $it" }.orEmpty()
        binding.guardian.visible(!s.guardianName.isNullOrBlank())
        binding.account.text = listOfNotNull(
            "A/c ${s.accountNumber}",
            s.cifNumber?.let { "CIF $it" },
        ).joinToString("   ")
        binding.address.text = listOfNotNull(data.address, s.village, s.district)
            .joinToString(", ")

        binding.outstanding.text = "Outstanding " + Formats.money(s.outstandingAmount)
        binding.overdue.text = "Overdue " + Formats.money(s.overdueAmount)
        binding.classification.text = listOfNotNull(
            s.assetClass?.let { "Asset class $it" },
            "DPD ${s.dpd}",
            s.riskBand?.let { "Risk $it (${s.riskScore})" },
            s.nextFollowupDate?.let { "Follow-up ${Formats.prettyDate(it)}" },
        ).joinToString("  \u2022  ")

        // /loans/{id} returns the UNMASKED mobile; that is the whole point.
        val callable = data.mobile ?: s.mobileMasked
        binding.callAction.isEnabled = !data.mobile.isNullOrBlank()
        binding.callAction.text = if (data.mobile.isNullOrBlank()) {
            "No number on file"
        } else {
            "Call $callable"
        }

        visitAdapter.submit(data.visits)
        binding.noVisits.visible(data.visits.isEmpty())
    }

    private fun call() {
        val number = detail?.mobile
        if (number.isNullOrBlank()) {
            showError("This customer has no phone number on file.")
            return
        }
        // ACTION_DIAL, not ACTION_CALL: no CALL_PHONE permission needed and the
        // agent still sees the number before it is dialled.
        val intent = Intent(Intent.ACTION_DIAL, Uri.parse("tel:$number"))
        try {
            startActivity(intent)
        } catch (e: Exception) {
            showError("No dialer app is available on this device.")
        }
    }

    private fun startVisit() {
        val s = detail?.summary
        if (s == null) {
            showError("Wait for the customer details to load before starting a visit.")
            return
        }
        startActivity(
            Intent(this, VisitFormActivity::class.java)
                .putExtra(VisitFormActivity.EXTRA_LOAN_ID, s.loanId)
                .putExtra(VisitFormActivity.EXTRA_CUSTOMER_LABEL, "${s.fullName} \u2014 ${s.accountNumber}"),
        )
    }

    private fun recordRecovery() {
        val s = detail?.summary
        if (s == null) {
            showError("Wait for the customer details to load first.")
            return
        }
        startActivity(
            Intent(this, RecoveryActivity::class.java)
                .putExtra(RecoveryActivity.EXTRA_LOAN_ID, s.loanId)
                .putExtra(RecoveryActivity.EXTRA_CUSTOMER_LABEL, "${s.fullName} \u2014 ${s.accountNumber}"),
        )
    }
}
