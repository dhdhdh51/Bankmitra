package com.lrms.recovery.util

import android.content.Context
import androidx.test.core.app.ApplicationProvider
import org.junit.Assert.assertEquals
import org.junit.Assert.assertNotNull
import org.junit.Assert.assertTrue
import org.junit.Test
import org.junit.runner.RunWith
import org.robolectric.RobolectricTestRunner
import org.robolectric.annotation.Config

/**
 * Regression test for the camera hand-off.
 *
 * PhotoStore writes captures into getExternalFilesDir()/captures and then hands
 * the camera app a content:// URI for that file. res/xml/file_paths.xml has to
 * declare a root covering that directory, otherwise
 * FileProvider.getUriForFile() throws IllegalArgumentException,
 * PhotoStore.uriFor() returns null, and every screen reports "the photo could
 * not be saved" as though the handset were out of storage.
 *
 * That is exactly what shipped: file_paths.xml declared "Pictures" while
 * PhotoStore wrote into "captures". The build was clean, because nothing checks
 * that those two agree until a user taps the shutter.
 *
 * ---------------------------------------------------------------------------
 * Why every FileProvider assertion lives in ONE test method
 *
 * FileProvider keeps a private static cache of authority -> PathStrategy, and a
 * PathStrategy holds ABSOLUTE root directories. Robolectric gives each test
 * method its own temporary data dir. So the first method to touch FileProvider
 * populates the cache with its own temp paths, and every later method then asks
 * about files under a different temp root and gets IllegalArgumentException -
 * a test-isolation artifact that looks exactly like the production bug.
 * Keeping the FileProvider work in a single method avoids relying on reflection
 * to reset a private static field.
 *
 * sdk = 34 rather than compileSdk: path matching is identical on every level,
 * and this keeps the test off Robolectric's newest image, which needs JDK 21.
 */
@RunWith(RobolectricTestRunner::class)
@Config(
    sdk = [34],
    // A plain Application, not LrmsApp. LrmsApp.onCreate() calls
    // WorkManager.getInstance(), initialised in the real app by androidx.startup
    // but not under Robolectric, so it would throw IllegalStateException.
    application = android.app.Application::class,
)
class PhotoStoreFileProviderTest {

    private val context: Context get() = ApplicationProvider.getApplicationContext()

    @Test
    fun `capture files are created in the captures directory`() {
        val file = PhotoStore.newCaptureFile(context, "visit")
        assertNotNull("newCaptureFile returned null - storage unavailable", file)
        assertTrue("capture file does not exist on disk", file!!.exists())
        assertEquals(
            "capture file is not in the directory file_paths.xml is written for",
            "captures",
            file.parentFile?.name,
        )
    }

    @Test
    fun `FileProvider can share every kind of capture`() {
        // visit = VisitFormActivity, selfie = AttendanceActivity,
        // receipt = RecoveryActivity. All three go through the same directory,
        // so all three break or work together.
        for (prefix in listOf("visit", "selfie", "receipt")) {
            val file = PhotoStore.newCaptureFile(context, prefix)
            assertNotNull("newCaptureFile($prefix) returned null", file)

            val uri = PhotoStore.uriFor(context, file!!)

            // The assertion the shipped bug failed.
            assertNotNull(
                "uriFor() returned null for $prefix: res/xml/file_paths.xml does " +
                    "not cover ${file.parent}. This is the defect that surfaced " +
                    "as \"the photo could not be saved\".",
                uri,
            )
            assertEquals("content", uri!!.scheme)
            assertEquals(
                "authority must match the manifest's \${applicationId}.fileprovider",
                context.packageName + ".fileprovider",
                uri.authority,
            )
        }
    }
}
