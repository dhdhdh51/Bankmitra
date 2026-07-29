package com.lrms.recovery.util

import android.content.Context
import android.net.ConnectivityManager
import android.net.NetworkCapabilities
import android.os.BatteryManager
import android.os.Build
import com.lrms.recovery.BuildConfig
import com.lrms.recovery.data.prefs.AppPrefs

/** Device facts every auth call in docs/API.md wants, plus connectivity/battery. */
object DeviceInfo {

    /** e.g. "Redmi Note 12". Manufacturer is included only when it is not already in the model. */
    val model: String
        get() {
            val manufacturer = Build.MANUFACTURER.orEmpty().trim()
            val model = Build.MODEL.orEmpty().trim()
            return when {
                model.isEmpty() -> manufacturer.ifEmpty { "unknown" }
                manufacturer.isEmpty() -> model
                model.startsWith(manufacturer, ignoreCase = true) -> model
                else -> "$manufacturer $model"
            }
        }

    /** Android release, e.g. "14". */
    val osVersion: String get() = Build.VERSION.RELEASE.orEmpty().ifEmpty { Build.VERSION.SDK_INT.toString() }

    /** Clean version name with no build-type suffix, matching what /ping compares against. */
    val appVersion: String get() = BuildConfig.APP_VERSION_NAME

    fun deviceId(context: Context): String = AppPrefs.get(context).deviceId

    /** True when a network with validated internet is currently available. */
    fun isOnline(context: Context): Boolean {
        val cm = context.getSystemService(Context.CONNECTIVITY_SERVICE) as? ConnectivityManager
            ?: return false
        val network = cm.activeNetwork ?: return false
        val caps = cm.getNetworkCapabilities(network) ?: return false
        return caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET) &&
            caps.hasCapability(NetworkCapabilities.NET_CAPABILITY_VALIDATED)
    }

    /** Battery percentage for `tracking/ping`, or null when unavailable. */
    fun batteryPercent(context: Context): Int? {
        val bm = context.getSystemService(Context.BATTERY_SERVICE) as? BatteryManager ?: return null
        val level = bm.getIntProperty(BatteryManager.BATTERY_PROPERTY_CAPACITY)
        return level.takeIf { it in 0..100 }
    }
}
