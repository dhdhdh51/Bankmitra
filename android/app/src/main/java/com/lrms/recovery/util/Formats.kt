package com.lrms.recovery.util

import java.text.NumberFormat
import java.text.ParseException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Date/number formatting.
 *
 * java.time is avoided on purpose: minSdk is 24 and enabling core library
 * desugaring just for two formatters would add a build step for no user-visible
 * benefit. SimpleDateFormat with Locale.US is used for every WIRE format so the
 * server never receives locale-translated digits or month names.
 */
object Formats {

    /** The `YYYY-MM-DD HH:MM:SS` shape every datetime field in docs/API.md uses. */
    private const val WIRE_DATETIME = "yyyy-MM-dd HH:mm:ss"
    private const val WIRE_DATE = "yyyy-MM-dd"

    private fun wireDateTime() = SimpleDateFormat(WIRE_DATETIME, Locale.US)
    private fun wireDate() = SimpleDateFormat(WIRE_DATE, Locale.US)

    /** Device local time, in the wire format. Used for `visited_at`, `collected_at`. */
    fun nowWireDateTime(): String = wireDateTime().format(Date())

    fun nowWireDate(): String = wireDate().format(Date())

    fun parseWireDateTime(value: String?): Date? {
        if (value.isNullOrBlank()) return null
        return try {
            wireDateTime().parse(value)
        } catch (e: ParseException) {
            try {
                wireDate().parse(value)
            } catch (e2: ParseException) {
                null
            }
        }
    }

    /** "21 Jul 2026, 11:03" — display only, uses the device locale. */
    fun prettyDateTime(value: String?): String {
        val date = parseWireDateTime(value) ?: return "\u2014"
        return SimpleDateFormat("d MMM yyyy, HH:mm", Locale.getDefault()).format(date)
    }

    /** "21 Jul 2026, 11:03" from a millisecond timestamp (queue row created_at). */
    fun prettyEpoch(millis: Long): String =
        SimpleDateFormat("d MMM yyyy, HH:mm", Locale.getDefault()).format(Date(millis))

    /** "21 Jul 2026" — display only. */
    fun prettyDate(value: String?): String {
        val date = parseWireDateTime(value) ?: return "\u2014"
        return SimpleDateFormat("d MMM yyyy", Locale.getDefault()).format(date)
    }

    /** True when `YYYY-MM-DD` parses. Used to validate the promise date field. */
    fun isValidWireDate(value: String?): Boolean {
        if (value.isNullOrBlank()) return false
        if (!value.matches(Regex("""\d{4}-\d{2}-\d{2}"""))) return false
        return try {
            wireDate().apply { isLenient = false }.parse(value) != null
        } catch (e: ParseException) {
            false
        }
    }

    /** "Rs.2,45,780" — grouped with the device locale, prefixed to stay ASCII-safe. */
    fun money(amount: Double): String {
        val nf = NumberFormat.getNumberInstance(Locale.getDefault()).apply {
            maximumFractionDigits = 2
            minimumFractionDigits = 0
        }
        return "Rs." + nf.format(amount)
    }

    fun percent(value: Double): String = String.format(Locale.getDefault(), "%.1f%%", value)
}
