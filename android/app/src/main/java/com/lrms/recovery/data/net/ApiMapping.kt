package com.lrms.recovery.data.net

import org.json.JSONArray
import org.json.JSONObject

/**
 * Turns an [ApiResult] carrying a raw envelope into one carrying a typed model,
 * without any call site having to `when` over the sealed class or unwrap a
 * nullable `data`.
 *
 * A success whose `data` is not the expected JSON shape is downgraded to a
 * [ErrorCodes.CLIENT_BAD_RESPONSE] failure rather than throwing, because a
 * server/app version mismatch must show the user a message, not crash.
 */

private const val SHAPE_MSG =
    "The server sent an unexpected response shape. The app and the server may be " +
        "different versions."

fun <R> ApiResult<ApiData>.mapObject(transform: (JSONObject) -> R): ApiResult<R> = when (this) {
    is ApiResult.Failure -> this
    is ApiResult.Success -> {
        val obj = data.asObject
        if (obj == null) {
            ApiResult.Failure(ErrorCodes.CLIENT_BAD_RESPONSE, SHAPE_MSG)
        } else {
            ApiResult.Success(transform(obj), message, meta)
        }
    }
}

fun <R> ApiResult<ApiData>.mapArray(transform: (JSONArray) -> R): ApiResult<R> = when (this) {
    is ApiResult.Failure -> this
    is ApiResult.Success -> {
        val arr = data.asArray
        if (arr == null) {
            ApiResult.Failure(ErrorCodes.CLIENT_BAD_RESPONSE, SHAPE_MSG)
        } else {
            ApiResult.Success(transform(arr), message, meta)
        }
    }
}

/** For endpoints where only success/failure matters (logout, fcm-token, ...). */
fun ApiResult<ApiData>.discardData(): ApiResult<Unit> = when (this) {
    is ApiResult.Failure -> this
    is ApiResult.Success -> ApiResult.Success(Unit, message, meta)
}

/** Convenience for the common "show the message, whatever happened" pattern. */
val ApiResult<*>.userMessage: String
    get() = when (this) {
        is ApiResult.Success -> message
        is ApiResult.Failure -> message
    }
