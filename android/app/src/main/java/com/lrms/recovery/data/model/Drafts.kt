package com.lrms.recovery.data.model

import com.lrms.recovery.data.net.boolOr
import com.lrms.recovery.data.net.doubleOr
import com.lrms.recovery.data.net.doubleOrNull
import com.lrms.recovery.data.net.intOr
import com.lrms.recovery.data.net.intOrNull
import com.lrms.recovery.data.net.stringOrNull
import org.json.JSONArray
import org.json.JSONObject
import java.util.UUID

/**
 * The two records the agent creates in the field, and which therefore have to
 * survive being written with no signal.
 *
 * IDEMPOTENCY - the single most important rule in this file
 * --------------------------------------------------------
 * `visitUid` / `recoveryUid` are generated exactly ONCE, by [newVisitUid] /
 * [newRecoveryUid], at the moment the agent presses Submit. The value is written
 * into the SQLite queue with the rest of the draft and is reused verbatim on
 * every retry, forever. That is what lets the server answer `duplicate: true`
 * instead of creating a second visit when a response is lost after the request
 * was already processed (docs/API.md section "Idempotency").
 *
 * Never call `UUID.randomUUID()` on a retry path.
 */

fun newVisitUid(): String = UUID.randomUUID().toString()

fun newRecoveryUid(): String = UUID.randomUUID().toString()

/** Everything `POST /visits` accepts, plus the local photo paths. */
class VisitDraft(
    val visitUid: String,
    val loanId: Int,
    val visitedAt: String,
    val latitude: Double,
    val longitude: Double,
    val accuracyM: Double?,
    val isMockLocation: Boolean,
    val visitStatus: String,
    val customerAvailable: Boolean?,
    val houseLocked: Boolean?,
    val metPerson: String?,
    val metRelation: String?,
    val occupation: String?,
    val recoveryPossibility: String?,
    val promiseAmount: Double?,
    val promiseDate: String?,
    val recommendation: String?,
    val remarks: String?,
    /** Signature pad export, `data:`-free base64 PNG. */
    val signatureBase64: String?,
    /** The borrower's own signature or thumb impression (section 13). */
    val borrowerSignatureBase64: String? = null,
    /**
     * The Central Bank form's remaining fields, as wire name -> value.
     *
     * A bag rather than eighteen more constructor parameters: the fields are all
     * short strings that go straight onto the multipart body, the queue can
     * round-trip them through JSON without eighteen more put/get pairs, and
     * adding a field later does not break every call site or any visit already
     * sitting in the offline queue.
     */
    val formFields: Map<String, String?> = emptyMap(),
    /** Absolute paths of captured photos, uploaded as repeated `photos[]` parts. */
    val photoPaths: List<String>,
    /** `photo_types[i]` tags `photos[i]` for section 12. Same size as photoPaths. */
    val photoTypes: List<String> = emptyList(),
    val appVersion: String,
    /** Display label for the queue screen. Not sent to the server. */
    val customerLabel: String,
) {
    /** Wire fields for the multipart body. Nulls are skipped by ApiClient. */
    fun toFields(): Map<String, String?> = linkedMapOf(
        "visit_uid" to visitUid,
        "loan_id" to loanId.toString(),
        "visited_at" to visitedAt,
        "latitude" to latitude.toString(),
        "longitude" to longitude.toString(),
        "accuracy_m" to accuracyM?.toString(),
        "is_mock_location" to if (isMockLocation) "1" else "0",
        "visit_status" to visitStatus,
        "customer_available" to customerAvailable?.let { if (it) "1" else "0" },
        "house_locked" to houseLocked?.let { if (it) "1" else "0" },
        "met_person" to metPerson?.takeIf { it.isNotBlank() },
        "met_relation" to metRelation?.takeIf { it.isNotBlank() },
        "occupation" to occupation?.takeIf { it.isNotBlank() },
        "recovery_possibility" to recoveryPossibility?.takeIf { it.isNotBlank() },
        "promise_amount" to promiseAmount?.toString(),
        "promise_date" to promiseDate?.takeIf { it.isNotBlank() },
        "recommendation" to recommendation?.takeIf { it.isNotBlank() },
        "remarks" to remarks?.takeIf { it.isNotBlank() },
        "signature" to signatureBase64?.takeIf { it.isNotBlank() },
        "borrower_signature" to borrowerSignatureBase64?.takeIf { it.isNotBlank() },
        "app_version" to appVersion,
    ) + formFields.filterValues { !it.isNullOrBlank() }

    fun toJson(): String = JSONObject().apply {
        put("visit_uid", visitUid)
        put("loan_id", loanId)
        put("visited_at", visitedAt)
        put("latitude", latitude)
        put("longitude", longitude)
        accuracyM?.let { put("accuracy_m", it) }
        put("is_mock_location", isMockLocation)
        put("visit_status", visitStatus)
        customerAvailable?.let { put("customer_available", it) }
        houseLocked?.let { put("house_locked", it) }
        put("met_person", metPerson)
        put("met_relation", metRelation)
        put("occupation", occupation)
        put("recovery_possibility", recoveryPossibility)
        promiseAmount?.let { put("promise_amount", it) }
        put("promise_date", promiseDate)
        put("recommendation", recommendation)
        put("remarks", remarks)
        put("signature", signatureBase64)
        put("borrower_signature", borrowerSignatureBase64)
        put("photos", JSONArray(photoPaths))
        put("photo_types", JSONArray(photoTypes))
        // Nested so a future field cannot collide with a top-level key.
        put("form_fields", JSONObject().apply {
            formFields.forEach { (k, v) -> if (!v.isNullOrBlank()) put(k, v) }
        })
        put("app_version", appVersion)
        put("customer_label", customerLabel)
    }.toString()

    companion object {
        fun fromJson(raw: String): VisitDraft? {
            val json = try {
                JSONObject(raw)
            } catch (e: Exception) {
                return null
            }
            val uid = json.stringOrNull("visit_uid") ?: return null
            val photos = json.optJSONArray("photos")
            return VisitDraft(
                // Reused, NOT regenerated. See the note at the top of this file.
                visitUid = uid,
                loanId = json.intOr("loan_id", 0),
                visitedAt = json.stringOrNull("visited_at").orEmpty(),
                latitude = json.doubleOr("latitude", 0.0),
                longitude = json.doubleOr("longitude", 0.0),
                accuracyM = json.doubleOrNull("accuracy_m"),
                isMockLocation = json.boolOr("is_mock_location", false),
                visitStatus = json.stringOrNull("visit_status").orEmpty(),
                customerAvailable = if (json.has("customer_available")) json.optBoolean("customer_available") else null,
                houseLocked = if (json.has("house_locked")) json.optBoolean("house_locked") else null,
                metPerson = json.stringOrNull("met_person"),
                metRelation = json.stringOrNull("met_relation"),
                occupation = json.stringOrNull("occupation"),
                recoveryPossibility = json.stringOrNull("recovery_possibility"),
                promiseAmount = json.doubleOrNull("promise_amount"),
                promiseDate = json.stringOrNull("promise_date"),
                recommendation = json.stringOrNull("recommendation"),
                remarks = json.stringOrNull("remarks"),
                signatureBase64 = json.stringOrNull("signature"),
                borrowerSignatureBase64 = json.stringOrNull("borrower_signature"),
                formFields = json.optJSONObject("form_fields")?.let { obj ->
                    buildMap {
                        val keys = obj.keys()
                        while (keys.hasNext()) {
                            val k = keys.next()
                            val v = obj.optString(k, "")
                            if (v.isNotEmpty()) put(k, v)
                        }
                    }
                } ?: emptyMap(),
                photoTypes = json.optJSONArray("photo_types")?.let { arr ->
                    buildList {
                        for (i in 0 until arr.length()) {
                            add(arr.optString(i, "house"))
                        }
                    }
                } ?: emptyList(),
                photoPaths = buildList {
                    if (photos != null) {
                        for (i in 0 until photos.length()) {
                            val p = photos.optString(i, "")
                            if (p.isNotEmpty()) add(p)
                        }
                    }
                },
                appVersion = json.stringOrNull("app_version").orEmpty(),
                customerLabel = json.stringOrNull("customer_label").orEmpty(),
            )
        }
    }
}

/** Everything `POST /recoveries` accepts, plus the local receipt photo path. */
class RecoveryDraft(
    val recoveryUid: String,
    val loanId: Int,
    val visitId: Int?,
    val amount: Double,
    val paymentMode: String,
    val txnReference: String?,
    val receiptNumber: String?,
    val collectedAt: String,
    val latitude: Double?,
    val longitude: Double?,
    val remarks: String?,
    val receiptPhotoPath: String?,
    val customerLabel: String,
) {
    fun toFields(): Map<String, String?> = linkedMapOf(
        "recovery_uid" to recoveryUid,
        "loan_id" to loanId.toString(),
        "visit_id" to visitId?.takeIf { it > 0 }?.toString(),
        "amount" to amount.toString(),
        "payment_mode" to paymentMode,
        "txn_reference" to txnReference?.takeIf { it.isNotBlank() },
        "receipt_number" to receiptNumber?.takeIf { it.isNotBlank() },
        "collected_at" to collectedAt,
        "latitude" to latitude?.toString(),
        "longitude" to longitude?.toString(),
        "remarks" to remarks?.takeIf { it.isNotBlank() },
    )

    fun toJson(): String = JSONObject().apply {
        put("recovery_uid", recoveryUid)
        put("loan_id", loanId)
        visitId?.let { put("visit_id", it) }
        put("amount", amount)
        put("payment_mode", paymentMode)
        put("txn_reference", txnReference)
        put("receipt_number", receiptNumber)
        put("collected_at", collectedAt)
        latitude?.let { put("latitude", it) }
        longitude?.let { put("longitude", it) }
        put("remarks", remarks)
        put("receipt_photo", receiptPhotoPath)
        put("customer_label", customerLabel)
    }.toString()

    companion object {
        fun fromJson(raw: String): RecoveryDraft? {
            val json = try {
                JSONObject(raw)
            } catch (e: Exception) {
                return null
            }
            val uid = json.stringOrNull("recovery_uid") ?: return null
            return RecoveryDraft(
                recoveryUid = uid,
                loanId = json.intOr("loan_id", 0),
                visitId = json.intOrNull("visit_id"),
                amount = json.doubleOr("amount", 0.0),
                paymentMode = json.stringOrNull("payment_mode").orEmpty(),
                txnReference = json.stringOrNull("txn_reference"),
                receiptNumber = json.stringOrNull("receipt_number"),
                collectedAt = json.stringOrNull("collected_at").orEmpty(),
                latitude = json.doubleOrNull("latitude"),
                longitude = json.doubleOrNull("longitude"),
                remarks = json.stringOrNull("remarks"),
                receiptPhotoPath = json.stringOrNull("receipt_photo"),
                customerLabel = json.stringOrNull("customer_label").orEmpty(),
            )
        }
    }
}

/** One entry of the batched `POST /tracking/ping` payload. */
class TrackingPing(
    val latitude: Double,
    val longitude: Double,
    val accuracyM: Double?,
    val speedKmph: Double?,
    val batteryPct: Int?,
    val isMock: Boolean,
    val recordedAt: String,
) {
    fun toJson(): JSONObject = JSONObject().apply {
        put("latitude", latitude)
        put("longitude", longitude)
        accuracyM?.let { put("accuracy_m", it) }
        speedKmph?.let { put("speed_kmph", it) }
        batteryPct?.let { put("battery_pct", it) }
        put("is_mock", isMock)
        put("recorded_at", recordedAt)
    }

    companion object {
        fun fromJson(raw: String): TrackingPing? {
            val json = try {
                JSONObject(raw)
            } catch (e: Exception) {
                return null
            }
            return TrackingPing(
                latitude = json.doubleOr("latitude", 0.0),
                longitude = json.doubleOr("longitude", 0.0),
                accuracyM = json.doubleOrNull("accuracy_m"),
                speedKmph = json.doubleOrNull("speed_kmph"),
                batteryPct = json.intOrNull("battery_pct"),
                isMock = json.boolOr("is_mock", false),
                recordedAt = json.stringOrNull("recorded_at").orEmpty(),
            )
        }
    }
}
