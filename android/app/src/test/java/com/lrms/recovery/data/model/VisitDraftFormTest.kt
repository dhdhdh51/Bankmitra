package com.lrms.recovery.data.model

import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * The Central Bank form fields have to survive the offline queue.
 *
 * A visit recorded with no signal is written to SQLite as JSON and replayed by a
 * background worker, possibly days later. If [VisitDraft.toJson] and
 * [VisitDraft.fromJson] disagree about a field, the agent's answer is silently
 * lost and the bank files an incomplete report - with no error anywhere, because
 * the upload itself succeeds.
 *
 * Runs under Robolectric because the classes in android.jar are signature-only
 * stubs: calling org.json.JSONObject.put() in a plain JVM test throws
 * "Method put in org.json.JSONObject not mocked". Robolectric supplies the real
 * implementation, which is the whole point when the thing under test is the JSON
 * the offline queue actually stores.
 */
@RunWith(RobolectricTestRunner::class)
@Config(sdk = [34], application = android.app.Application::class)
class VisitDraftFormTest {

    private val formFields = mapOf(
        "loan_type" to "ckcc",
        "loan_type_other" to null,
        "account_status" to "npa",
        "account_status_other" to null,
        "rc_issued" to "1",
        "contact_status" to "family",
        "contact_mobile" to "9998887776",
        "borrower_alive" to "1",
        "residence_status" to "same",
        "income_source" to "agri",
        "income_source_other" to null,
        "willing_to_pay" to "1",
        "payment_plan" to "krm_ots",
        "nonpayment_reasons" to "crop_failure,illness",
        "nonpayment_other" to "Tractor repair",
        "recommendations" to "followup_needed,krm_ots",
    )

    private fun draft() = VisitDraft(
        visitUid = "abcd1234-abcd-1234-abcd-1234abcd1234",
        loanId = 86,
        visitedAt = "2026-07-30 11:22:33",
        latitude = 25.5514,
        longitude = 84.8783,
        accuracyM = 9.0,
        isMockLocation = false,
        visitStatus = "promise",
        customerAvailable = true,
        houseLocked = false,
        metPerson = "रमेश प्रसाद",
        metRelation = "पुत्र",
        occupation = "agri",
        recoveryPossibility = "high",
        promiseAmount = 25000.0,
        promiseDate = "2026-08-19",
        recommendation = null,
        remarks = "बाढ़ से फसल खराब हुई।",
        signatureBase64 = "QUdFTlQ=",
        borrowerSignatureBase64 = "Qk9SUk9XRVI=",
        formFields = formFields,
        photoPaths = listOf("/a/one.jpg", "/a/two.jpg"),
        photoTypes = listOf("customer", "house"),
        appVersion = "1.0.0",
        customerLabel = "सुरेश प्रसाद — 38291047561",
    )

    @Test
    fun `every form field reaches the wire body`() {
        val fields = draft().toFields()

        // Names must match what VisitApiController reads, exactly.
        for ((key, value) in formFields) {
            if (value == null) {
                assertTrue("blank $key should be omitted, not sent empty", fields[key] == null)
            } else {
                assertEquals("wire field $key", value, fields[key])
            }
        }

        assertEquals("promise", fields["visit_status"])
        assertEquals("86", fields["loan_id"])
        assertEquals("QUdFTlQ=", fields["signature"])
        assertEquals("Qk9SUk9XRVI=", fields["borrower_signature"])
        assertEquals("रमेश प्रसाद", fields["met_person"])
    }

    @Test
    fun `offline queue round trip keeps every form field`() {
        val restored = VisitDraft.fromJson(draft().toJson())
        assertNotNull("draft did not survive JSON", restored)
        restored!!

        // The idempotency key above all: a regenerated uid would double-record.
        assertEquals("abcd1234-abcd-1234-abcd-1234abcd1234", restored.visitUid)

        for ((key, value) in formFields) {
            if (value != null) {
                assertEquals("form field $key lost in the queue", value, restored.formFields[key])
            }
        }

        assertEquals("Qk9SUk9XRVI=", restored.borrowerSignatureBase64)
        assertEquals(listOf("customer", "house"), restored.photoTypes)
        assertEquals(listOf("/a/one.jpg", "/a/two.jpg"), restored.photoPaths)
        assertEquals("बाढ़ से फसल खराब हुई।", restored.remarks)
        assertEquals(25000.0, restored.promiseAmount!!, 0.001)

        // And the replayed body must be identical to the original one.
        assertEquals(draft().toFields(), restored.toFields())
    }

    @Test
    fun `a draft from an older app version still loads`() {
        // No form_fields, no photo_types, no borrower_signature: exactly what a
        // visit queued before this feature looks like. It must still replay.
        val legacy = """
            {"visit_uid":"11111111-2222-3333-4444-555555555555","loan_id":7,
             "visited_at":"2026-07-01 09:00:00","latitude":25.5,"longitude":84.8,
             "is_mock_location":false,"visit_status":"visited",
             "remarks":"old visit","signature":"Uw==",
             "photos":["/old/a.jpg"],"app_version":"0.9.0","customer_label":"X"}
        """.trimIndent()

        val restored = VisitDraft.fromJson(legacy)
        assertNotNull("a pre-existing queued visit must still load", restored)
        restored!!

        assertEquals("11111111-2222-3333-4444-555555555555", restored.visitUid)
        assertEquals(listOf("/old/a.jpg"), restored.photoPaths)
        assertTrue("no form fields expected", restored.formFields.isEmpty())
        assertTrue("no photo types expected", restored.photoTypes.isEmpty())
        assertNull(restored.borrowerSignatureBase64)

        // Nothing empty must be sent, or the server would store blanks.
        val fields = restored.toFields()
        assertNull(fields["loan_type"])
        assertNull(fields["borrower_signature"])
        assertEquals("old visit", fields["remarks"])
    }

    @Test
    fun `unanswered fields are omitted rather than sent blank`() {
        val bare = draft().let { d ->
            VisitDraft(
                visitUid = d.visitUid, loanId = d.loanId, visitedAt = d.visitedAt,
                latitude = d.latitude, longitude = d.longitude, accuracyM = null,
                isMockLocation = false, visitStatus = "visited",
                customerAvailable = null, houseLocked = null,
                metPerson = null, metRelation = null, occupation = null,
                recoveryPossibility = null, promiseAmount = null, promiseDate = null,
                recommendation = null, remarks = "x", signatureBase64 = "Uw==",
                borrowerSignatureBase64 = null,
                // Everything unanswered, the way a spinner left on "चुनें" reads.
                formFields = mapOf(
                    "loan_type" to "",
                    "account_status" to "",
                    "contact_status" to "",
                    "borrower_alive" to "",
                    "nonpayment_reasons" to null,
                ),
                photoPaths = listOf("/a/one.jpg"), photoTypes = listOf("house"),
                appVersion = "1.0.0", customerLabel = "X",
            )
        }

        val fields = bare.toFields()
        for (key in listOf("loan_type", "account_status", "contact_status", "borrower_alive", "nonpayment_reasons")) {
            assertNull("$key must be omitted when unanswered", fields[key])
        }
    }
}
