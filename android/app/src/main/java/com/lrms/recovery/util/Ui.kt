package com.lrms.recovery.util

import android.app.Activity
import android.content.Context
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Spinner
import androidx.annotation.ArrayRes
import androidx.appcompat.app.AlertDialog
import com.google.android.material.snackbar.Snackbar

/**
 * Small UI helpers.
 *
 * PROJECT RULE: every network / GPS / photo failure must reach the user. These
 * helpers exist so there is never an excuse to swallow an error - showing it is
 * a one-liner.
 */

/** Blocking, acknowledged error. Use when the user's next action depends on it. */
fun Activity.showError(message: String, title: String = "Could not continue") {
    if (isFinishing || isDestroyed) return
    AlertDialog.Builder(this)
        .setTitle(title)
        .setMessage(message)
        .setPositiveButton(android.R.string.ok, null)
        .show()
}

/** Blocking error with a retry action. */
fun Activity.showRetryableError(message: String, title: String = "Something went wrong", onRetry: () -> Unit) {
    if (isFinishing || isDestroyed) return
    AlertDialog.Builder(this)
        .setTitle(title)
        .setMessage(message)
        .setPositiveButton("Retry") { _, _ -> onRetry() }
        .setNegativeButton(android.R.string.cancel, null)
        .show()
}

fun Activity.confirm(title: String, message: String, positive: String, onConfirm: () -> Unit) {
    if (isFinishing || isDestroyed) return
    AlertDialog.Builder(this)
        .setTitle(title)
        .setMessage(message)
        .setPositiveButton(positive) { _, _ -> onConfirm() }
        .setNegativeButton(android.R.string.cancel, null)
        .show()
}

/** Non-blocking confirmation / info. */
fun View.snack(message: String, long: Boolean = true) {
    Snackbar.make(
        this,
        message,
        if (long) Snackbar.LENGTH_LONG else Snackbar.LENGTH_SHORT,
    ).show()
}

fun View.visible(show: Boolean) {
    visibility = if (show) View.VISIBLE else View.GONE
}

/**
 * Binds a Spinner to a label array while keeping a parallel value array for the
 * wire format, so the UI can be translated without changing what the API sees.
 */
class CodedSpinner(
    private val spinner: Spinner,
    private val values: Array<String>,
) {
    val selectedValue: String
        get() = values.getOrElse(spinner.selectedItemPosition) { values.firstOrNull().orEmpty() }

    fun select(value: String) {
        val idx = values.indexOf(value)
        if (idx >= 0) spinner.setSelection(idx)
    }

    fun onChanged(listener: (String) -> Unit) {
        spinner.onItemSelectedListener = object : android.widget.AdapterView.OnItemSelectedListener {
            override fun onItemSelected(
                parent: android.widget.AdapterView<*>?,
                view: View?,
                position: Int,
                id: Long,
            ) = listener(values.getOrElse(position) { "" })

            override fun onNothingSelected(parent: android.widget.AdapterView<*>?) = Unit
        }
    }

    companion object {
        fun attach(
            context: Context,
            spinner: Spinner,
            @ArrayRes labels: Int,
            @ArrayRes wireValues: Int,
        ): CodedSpinner {
            val labelArray = context.resources.getStringArray(labels)
            val valueArray = context.resources.getStringArray(wireValues)
            spinner.adapter = ArrayAdapter(
                context,
                android.R.layout.simple_spinner_dropdown_item,
                labelArray,
            )
            return CodedSpinner(spinner, valueArray)
        }
    }
}
