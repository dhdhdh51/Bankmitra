package com.lrms.recovery.data.net

import org.json.JSONArray
import org.json.JSONObject

/**
 * Null-safe accessors over org.json.
 *
 * `JSONObject.optString` returns "" for a missing key AND the literal string
 * "null" for a JSON null in some Android versions, which is exactly the kind of
 * thing that turns into a "null" shown to a user. These helpers normalise all of
 * that to a real Kotlin `null`, so no call site needs `!!`.
 */

fun JSONObject.stringOrNull(key: String): String? {
    if (!has(key) || isNull(key)) return null
    val raw = optString(key, "")
    return raw.takeIf { it.isNotEmpty() && it != "null" }
}

fun JSONObject.stringOr(key: String, fallback: String): String = stringOrNull(key) ?: fallback

fun JSONObject.intOr(key: String, fallback: Int): Int =
    if (has(key) && !isNull(key)) optInt(key, fallback) else fallback

fun JSONObject.intOrNull(key: String): Int? =
    if (has(key) && !isNull(key)) optInt(key, 0) else null

fun JSONObject.longOr(key: String, fallback: Long): Long =
    if (has(key) && !isNull(key)) optLong(key, fallback) else fallback

fun JSONObject.doubleOr(key: String, fallback: Double): Double =
    if (has(key) && !isNull(key)) optDouble(key, fallback) else fallback

fun JSONObject.doubleOrNull(key: String): Double? {
    if (!has(key) || isNull(key)) return null
    val v = optDouble(key, Double.NaN)
    return if (v.isNaN()) null else v
}

fun JSONObject.boolOr(key: String, fallback: Boolean): Boolean =
    if (has(key) && !isNull(key)) optBoolean(key, fallback) else fallback

fun JSONObject.objectOrNull(key: String): JSONObject? =
    if (has(key) && !isNull(key)) optJSONObject(key) else null

fun JSONObject.arrayOrNull(key: String): JSONArray? =
    if (has(key) && !isNull(key)) optJSONArray(key) else null

/** Iterate a JSONArray of objects, silently skipping non-object entries. */
inline fun <T> JSONArray?.mapObjects(transform: (JSONObject) -> T): List<T> {
    if (this == null) return emptyList()
    val out = ArrayList<T>(length())
    for (i in 0 until length()) {
        val item = optJSONObject(i) ?: continue
        out.add(transform(item))
    }
    return out
}

/** Flatten `errors` into a plain map; nested structures are stringified. */
fun JSONObject?.toFieldErrors(): Map<String, String> {
    if (this == null) return emptyMap()
    val out = LinkedHashMap<String, String>()
    val it = keys()
    while (it.hasNext()) {
        val key = it.next()
        if (isNull(key)) continue
        val value = when (val raw = opt(key)) {
            is JSONArray -> (0 until raw.length()).joinToString(" ") { i -> raw.optString(i, "") }.trim()
            else -> raw?.toString().orEmpty()
        }
        if (value.isNotEmpty()) out[key] = value
    }
    return out
}
