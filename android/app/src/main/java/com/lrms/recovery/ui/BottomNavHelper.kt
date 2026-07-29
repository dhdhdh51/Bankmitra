package com.lrms.recovery.ui

import android.app.Activity
import android.content.Intent
import com.google.android.material.bottomnavigation.BottomNavigationView
import com.lrms.recovery.R

/**
 * Wires the four-tab bottom bar without pulling in the Navigation component.
 *
 * Each tab is a separate Activity (the screens are independent and mostly
 * top-level), so tab switching is `startActivity` with NO_ANIMATION and
 * REORDER_TO_FRONT, which keeps a single instance of each tab in the task and
 * makes Back behave the way agents expect.
 */
object BottomNavHelper {

    fun attach(activity: Activity, nav: BottomNavigationView, selectedItemId: Int) {
        nav.selectedItemId = selectedItemId
        nav.setOnItemSelectedListener { item ->
            if (item.itemId == selectedItemId) return@setOnItemSelectedListener true
            val target = when (item.itemId) {
                R.id.nav_home -> DashboardActivity::class.java
                R.id.nav_customers -> CustomerListActivity::class.java
                R.id.nav_queue -> SyncQueueActivity::class.java
                R.id.nav_settings -> SettingsActivity::class.java
                else -> return@setOnItemSelectedListener false
            }
            activity.startActivity(
                Intent(activity, target).addFlags(
                    Intent.FLAG_ACTIVITY_REORDER_TO_FRONT or Intent.FLAG_ACTIVITY_NO_ANIMATION,
                ),
            )
            activity.overridePendingTransition(0, 0)
            true
        }
    }
}
