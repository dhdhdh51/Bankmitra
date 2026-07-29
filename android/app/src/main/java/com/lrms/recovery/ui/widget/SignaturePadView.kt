package com.lrms.recovery.ui.widget

import android.content.Context
import android.graphics.Bitmap
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Path
import android.util.AttributeSet
import android.util.Base64
import android.view.MotionEvent
import android.view.View
import java.io.ByteArrayOutputStream

/**
 * Finger-drawn signature pad.
 *
 * Exports a base64 PNG for the `signature` field of `POST /visits`. The bitmap is
 * rendered white-on-transparent-free (opaque white background) so it prints on a
 * paper receipt without turning into a black rectangle.
 *
 * Strokes are kept as a list of [Path]s rather than being drawn straight onto a
 * bitmap so the view survives a resize (rotation) without losing the signature
 * and so "Clear" is a single list clear.
 */
class SignaturePadView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null,
    defStyleAttr: Int = 0,
) : View(context, attrs, defStyleAttr) {

    private val strokes = ArrayList<Path>()
    private var active: Path? = null

    private val inkPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.BLACK
        style = Paint.Style.STROKE
        strokeWidth = 5f
        strokeCap = Paint.Cap.ROUND
        strokeJoin = Paint.Join.ROUND
    }

    /** True once the user has actually drawn something. */
    val hasSignature: Boolean get() = strokes.isNotEmpty()

    /** Notified when the first stroke lands, so a form can enable a button. */
    var onSignedListener: (() -> Unit)? = null

    init {
        // A pad that scrolls with its parent is useless; claim the touch stream.
        isFocusable = true
        isFocusableInTouchMode = true
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)
        strokes.forEach { canvas.drawPath(it, inkPaint) }
        active?.let { canvas.drawPath(it, inkPaint) }
    }

    override fun onTouchEvent(event: MotionEvent): Boolean {
        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN -> {
                // Stop the enclosing ScrollView from stealing the gesture.
                parent?.requestDisallowInterceptTouchEvent(true)
                active = Path().apply { moveTo(event.x, event.y) }
                invalidate()
                return true
            }

            MotionEvent.ACTION_MOVE -> {
                active?.lineTo(event.x, event.y)
                invalidate()
                return true
            }

            MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                active?.let {
                    val wasEmpty = strokes.isEmpty()
                    strokes.add(it)
                    if (wasEmpty) onSignedListener?.invoke()
                }
                active = null
                parent?.requestDisallowInterceptTouchEvent(false)
                invalidate()
                return true
            }
        }
        return super.onTouchEvent(event)
    }

    fun clear() {
        strokes.clear()
        active = null
        invalidate()
    }

    /**
     * Renders the strokes to a PNG and returns it base64-encoded with NO
     * `data:image/png;base64,` prefix, because docs/API.md specifies the field as
     * a plain "base64 png".
     *
     * Returns null when nothing has been drawn — the caller must then omit the
     * field entirely rather than sending an empty string.
     */
    fun exportBase64Png(): String? {
        if (strokes.isEmpty()) return null
        val w = if (width > 0) width else 600
        val h = if (height > 0) height else 240
        val bitmap = Bitmap.createBitmap(w, h, Bitmap.Config.ARGB_8888)
        val canvas = Canvas(bitmap).apply { drawColor(Color.WHITE) }
        strokes.forEach { canvas.drawPath(it, inkPaint) }
        return try {
            ByteArrayOutputStream().use { out ->
                bitmap.compress(Bitmap.CompressFormat.PNG, 100, out)
                Base64.encodeToString(out.toByteArray(), Base64.NO_WRAP)
            }
        } catch (e: Exception) {
            null
        } finally {
            bitmap.recycle()
        }
    }
}
