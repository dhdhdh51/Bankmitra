package com.lrms.recovery.util

import android.content.Context
import android.graphics.Bitmap
import android.graphics.BitmapFactory
import android.graphics.Matrix
import android.net.Uri
import androidx.core.content.FileProvider
import java.io.File
import java.io.FileOutputStream
import java.io.IOException
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale

/**
 * Camera capture files and thumbnails.
 *
 * Photos go into `getExternalFilesDir("captures")`, which is app-private scoped
 * storage: no READ/WRITE_EXTERNAL_STORAGE permission is needed on any supported
 * API level, and the files disappear when the app is uninstalled. They are handed
 * to the camera app through a [FileProvider] content:// URI because passing a
 * file:// URI has thrown FileUriExposedException since API 24.
 */
object PhotoStore {

    private const val CAPTURE_DIR = "captures"
    private const val THUMB_MAX_PX = 240
    /** Downscale target for upload: keeps a house photo legible well under 8 MB. */
    private const val UPLOAD_MAX_PX = 1600
    private const val UPLOAD_QUALITY = 82

    /** `${applicationId}.fileprovider` — must match the authority in the manifest. */
    private fun authority(context: Context): String = context.packageName + ".fileprovider"

    private fun captureDir(context: Context): File? {
        val dir = File(context.getExternalFilesDir(null) ?: context.filesDir, CAPTURE_DIR)
        return if (dir.exists() || dir.mkdirs()) dir else null
    }

    /**
     * Creates an empty destination file for ACTION_IMAGE_CAPTURE.
     * Returns null when storage is unavailable — the caller MUST show a message.
     */
    fun newCaptureFile(context: Context, prefix: String): File? {
        val dir = captureDir(context) ?: return null
        val stamp = SimpleDateFormat("yyyyMMdd_HHmmss_SSS", Locale.US).format(Date())
        return try {
            File(dir, "${prefix}_$stamp.jpg").apply { createNewFile() }
        } catch (e: IOException) {
            null
        }
    }

    fun uriFor(context: Context, file: File): Uri? = try {
        FileProvider.getUriForFile(context, authority(context), file)
    } catch (e: IllegalArgumentException) {
        // Path not covered by res/xml/file_paths.xml. A programming error, but it
        // must not crash the visit form mid-field.
        null
    }

    /** Small bitmap for the RecyclerView thumbnail strip. Null when unreadable. */
    fun thumbnail(file: File): Bitmap? {
        if (!file.exists() || file.length() == 0L) return null
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(file.absolutePath, bounds)
        if (bounds.outWidth <= 0 || bounds.outHeight <= 0) return null
        var scale = 1
        while (bounds.outWidth / (scale * 2) >= THUMB_MAX_PX &&
            bounds.outHeight / (scale * 2) >= THUMB_MAX_PX
        ) {
            scale *= 2
        }
        val opts = BitmapFactory.Options().apply { inSampleSize = scale }
        return BitmapFactory.decodeFile(file.absolutePath, opts)
    }

    /**
     * Rewrites [file] in place, downscaled and re-compressed, so uploads stay
     * small on a 2G connection. Returns false if anything went wrong — the caller
     * still uploads the original rather than losing the evidence.
     */
    fun compressInPlace(file: File): Boolean {
        if (!file.exists()) return false
        val bounds = BitmapFactory.Options().apply { inJustDecodeBounds = true }
        BitmapFactory.decodeFile(file.absolutePath, bounds)
        val longest = maxOf(bounds.outWidth, bounds.outHeight)
        if (longest <= 0) return false
        var scale = 1
        while (longest / (scale * 2) >= UPLOAD_MAX_PX) scale *= 2
        val bitmap = BitmapFactory.decodeFile(
            file.absolutePath,
            BitmapFactory.Options().apply { inSampleSize = scale },
        ) ?: return false
        return try {
            FileOutputStream(file).use { out ->
                bitmap.compress(Bitmap.CompressFormat.JPEG, UPLOAD_QUALITY, out)
            }
            true
        } catch (e: IOException) {
            false
        } finally {
            bitmap.recycle()
        }
    }

    /** Rotates a bitmap; used by the signature pad export when needed. */
    fun rotate(bitmap: Bitmap, degrees: Float): Bitmap {
        if (degrees == 0f) return bitmap
        val m = Matrix().apply { postRotate(degrees) }
        return Bitmap.createBitmap(bitmap, 0, 0, bitmap.width, bitmap.height, m, true)
    }

    /** Deletes a capture file, ignoring failure (nothing useful to tell the user). */
    fun delete(file: File) {
        if (file.exists()) file.delete()
    }
}
