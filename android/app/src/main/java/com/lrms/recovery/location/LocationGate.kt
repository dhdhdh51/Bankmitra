package com.lrms.recovery.location

import android.Manifest
import android.annotation.SuppressLint
import android.content.Context
import android.content.pm.PackageManager
import android.location.Location
import android.os.Build
import android.os.Looper
import androidx.core.content.ContextCompat
import com.google.android.gms.location.CurrentLocationRequest
import com.google.android.gms.location.FusedLocationProviderClient
import com.google.android.gms.location.LocationCallback
import com.google.android.gms.location.LocationRequest
import com.google.android.gms.location.LocationResult
import com.google.android.gms.location.LocationServices
import com.google.android.gms.location.Priority
import com.google.android.gms.tasks.CancellationTokenSource
import kotlin.coroutines.resume
import kotlinx.coroutines.suspendCancellableCoroutine

/** What the GPS layer thinks of the current fix. */
sealed class LocationVerdict {

    /** No fix yet. Submit must stay disabled. */
    object Waiting : LocationVerdict()

    /** There is a fix, but it must not be used. [message] is user-facing. */
    class Blocked(val reason: Reason, val message: String) : LocationVerdict() {
        enum class Reason { NO_PERMISSION, NO_FIX, NULL_ISLAND, MOCK_PROVIDER }
    }

    /** A real, trustworthy fix. */
    class Usable(
        val latitude: Double,
        val longitude: Double,
        val accuracyM: Double?,
        val speedKmph: Double?,
    ) : LocationVerdict()
}

/**
 * The GPS gate for visits and attendance.
 *
 * WHY A "GATE" AND NOT JUST A LOCATION LISTENER
 * ---------------------------------------------
 * A recovery visit is evidence. docs/API.md rejects a visit outright with
 * `gps_required` when the coordinates are 0,0 and with `mock_location_blocked`
 * when a fake-GPS app is detected. Discovering that server-side, after an agent
 * has filled in a long form and walked away from the customer's house, is the
 * worst possible time. So the client applies the same three rules locally and
 * refuses to enable the Submit button until they pass:
 *
 *   1. no fix at all              -> Waiting / Blocked(NO_FIX)
 *   2. latitude and longitude ~0  -> Blocked(NULL_ISLAND)
 *   3. the OS flags the fix as mocked -> Blocked(MOCK_PROVIDER)
 *
 * Rule 3 uses `Location.isMock` on API 31+ and the older
 * `Location.isFromMockProvider` below that. The result is ALSO reported honestly
 * to the server in `is_mock_location`; the app never lies to make a submission
 * go through.
 */
class LocationGate(context: Context) {

    private val app = context.applicationContext
    private val client: FusedLocationProviderClient =
        LocationServices.getFusedLocationProviderClient(app)

    private var callback: LocationCallback? = null

    companion object {
        /** Anything closer than this to 0,0 is the "null island" the server rejects. */
        private const val NULL_ISLAND_EPSILON = 0.0001
        private const val UPDATE_INTERVAL_MS = 4_000L
        private const val FASTEST_INTERVAL_MS = 2_000L
        private const val ONE_SHOT_TIMEOUT_MS = 20_000L

        fun hasPermission(context: Context): Boolean =
            ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_FINE_LOCATION) ==
                PackageManager.PERMISSION_GRANTED ||
                ContextCompat.checkSelfPermission(context, Manifest.permission.ACCESS_COARSE_LOCATION) ==
                PackageManager.PERMISSION_GRANTED

        /** True when the OS says this fix came from a mock provider. */
        fun isMock(location: Location): Boolean =
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
                location.isMock
            } else {
                @Suppress("DEPRECATION")
                location.isFromMockProvider
            }

        /** Applies the three blocking rules to a raw fix. */
        fun judge(location: Location?): LocationVerdict {
            if (location == null) {
                return LocationVerdict.Blocked(
                    LocationVerdict.Blocked.Reason.NO_FIX,
                    "No GPS fix yet. Step outside, make sure Location is switched on, " +
                        "and wait a few seconds.",
                )
            }
            if (kotlin.math.abs(location.latitude) < NULL_ISLAND_EPSILON &&
                kotlin.math.abs(location.longitude) < NULL_ISLAND_EPSILON
            ) {
                return LocationVerdict.Blocked(
                    LocationVerdict.Blocked.Reason.NULL_ISLAND,
                    "The device reported 0, 0 which is not a real location. The server will " +
                        "reject this visit. Switch Location off and on again.",
                )
            }
            if (isMock(location)) {
                return LocationVerdict.Blocked(
                    LocationVerdict.Blocked.Reason.MOCK_PROVIDER,
                    "A mock (fake GPS) location was detected. Uninstall or disable the fake " +
                        "location app and select \"None\" under Developer options > Mock " +
                        "location app, then try again.",
                )
            }
            return LocationVerdict.Usable(
                latitude = location.latitude,
                longitude = location.longitude,
                accuracyM = if (location.hasAccuracy()) location.accuracy.toDouble() else null,
                speedKmph = if (location.hasSpeed()) location.speed * 3.6 else null,
            )
        }
    }

    /**
     * Starts continuous updates and reports a verdict for every fix.
     * Safe to call twice; the previous request is cancelled first.
     */
    @SuppressLint("MissingPermission") // permission is checked on the line above the call
    fun start(onVerdict: (LocationVerdict) -> Unit) {
        stop()
        if (!hasPermission(app)) {
            onVerdict(
                LocationVerdict.Blocked(
                    LocationVerdict.Blocked.Reason.NO_PERMISSION,
                    "Location permission has not been granted. This screen cannot be used " +
                        "without it.",
                ),
            )
            return
        }
        onVerdict(LocationVerdict.Waiting)

        val request = LocationRequest.Builder(Priority.PRIORITY_HIGH_ACCURACY, UPDATE_INTERVAL_MS)
            .setMinUpdateIntervalMillis(FASTEST_INTERVAL_MS)
            .setWaitForAccurateLocation(true)
            .build()

        val cb = object : LocationCallback() {
            override fun onLocationResult(result: LocationResult) {
                onVerdict(judge(result.lastLocation))
            }
        }
        callback = cb
        try {
            client.requestLocationUpdates(request, cb, Looper.getMainLooper())
        } catch (e: SecurityException) {
            // Permission revoked between the check and the call.
            onVerdict(
                LocationVerdict.Blocked(
                    LocationVerdict.Blocked.Reason.NO_PERMISSION,
                    "Location permission was withdrawn. Grant it again to continue.",
                ),
            )
        }
    }

    fun stop() {
        callback?.let { client.removeLocationUpdates(it) }
        callback = null
    }

    /**
     * One-shot fix for the background tracking worker. Returns [LocationVerdict]
     * so the worker applies exactly the same rules as the visit form.
     */
    @SuppressLint("MissingPermission")
    suspend fun awaitOneFix(): LocationVerdict {
        if (!hasPermission(app)) {
            return LocationVerdict.Blocked(
                LocationVerdict.Blocked.Reason.NO_PERMISSION,
                "Location permission has not been granted.",
            )
        }
        val request = CurrentLocationRequest.Builder()
            .setPriority(Priority.PRIORITY_BALANCED_POWER_ACCURACY)
            .setMaxUpdateAgeMillis(60_000L)
            .setDurationMillis(ONE_SHOT_TIMEOUT_MS)
            .build()
        val cancellation = CancellationTokenSource()
        return suspendCancellableCoroutine { cont ->
            try {
                val task = client.getCurrentLocation(request, cancellation.token)
                task.addOnSuccessListener { location ->
                    if (cont.isActive) cont.resume(judge(location))
                }
                task.addOnFailureListener {
                    if (cont.isActive) {
                        cont.resume(
                            LocationVerdict.Blocked(
                                LocationVerdict.Blocked.Reason.NO_FIX,
                                "Could not obtain a location fix.",
                            ),
                        )
                    }
                }
                cont.invokeOnCancellation { cancellation.cancel() }
            } catch (e: SecurityException) {
                if (cont.isActive) {
                    cont.resume(
                        LocationVerdict.Blocked(
                            LocationVerdict.Blocked.Reason.NO_PERMISSION,
                            "Location permission has not been granted.",
                        ),
                    )
                }
            }
        }
    }
}
