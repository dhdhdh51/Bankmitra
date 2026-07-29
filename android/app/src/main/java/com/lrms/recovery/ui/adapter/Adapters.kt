package com.lrms.recovery.ui.adapter

import android.graphics.Color
import android.view.LayoutInflater
import android.view.ViewGroup
import androidx.recyclerview.widget.RecyclerView
import com.lrms.recovery.data.db.QueueDb
import com.lrms.recovery.data.db.QueueItem
import com.lrms.recovery.data.model.CustomerSummary
import com.lrms.recovery.data.model.RecoveryDraft
import com.lrms.recovery.data.model.VisitDraft
import com.lrms.recovery.data.model.VisitSummary
import com.lrms.recovery.databinding.ItemCustomerBinding
import com.lrms.recovery.databinding.ItemPhotoBinding
import com.lrms.recovery.databinding.ItemQueueBinding
import com.lrms.recovery.databinding.ItemTileBinding
import com.lrms.recovery.databinding.ItemVisitBinding
import com.lrms.recovery.util.Formats
import com.lrms.recovery.util.PhotoStore
import com.lrms.recovery.util.visible
import java.io.File

/** One dashboard tile: a big number and a label. */
class Tile(val label: String, val value: String)

class TileAdapter(private var items: List<Tile> = emptyList()) :
    RecyclerView.Adapter<TileAdapter.VH>() {

    class VH(val binding: ItemTileBinding) : RecyclerView.ViewHolder(binding.root)

    fun submit(newItems: List<Tile>) {
        items = newItems
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = VH(
        ItemTileBinding.inflate(LayoutInflater.from(parent.context), parent, false),
    )

    override fun onBindViewHolder(holder: VH, position: Int) {
        val item = items[position]
        holder.binding.tileValue.text = item.value
        holder.binding.tileLabel.text = item.label
    }

    override fun getItemCount() = items.size
}

/** Customer list rows. Paging is handled by the Activity; this just renders. */
class CustomerAdapter(
    private val onClick: (CustomerSummary) -> Unit,
) : RecyclerView.Adapter<CustomerAdapter.VH>() {

    private val items = ArrayList<CustomerSummary>()

    class VH(val binding: ItemCustomerBinding) : RecyclerView.ViewHolder(binding.root)

    fun replaceAll(newItems: List<CustomerSummary>) {
        items.clear()
        items.addAll(newItems)
        notifyDataSetChanged()
    }

    fun append(newItems: List<CustomerSummary>) {
        if (newItems.isEmpty()) return
        val start = items.size
        items.addAll(newItems)
        notifyItemRangeInserted(start, newItems.size)
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = VH(
        ItemCustomerBinding.inflate(LayoutInflater.from(parent.context), parent, false),
    )

    override fun onBindViewHolder(holder: VH, position: Int) {
        val c = items[position]
        val b = holder.binding
        b.name.text = c.fullName
        b.account.text = listOfNotNull(
            c.accountNumber.takeIf { it.isNotBlank() },
            c.cifNumber,
        ).joinToString("  \u2022  ")
        b.village.text = listOfNotNull(c.village, c.district, c.mobileMasked)
            .joinToString("  \u2022  ")
        b.outstanding.text = "O/S " + Formats.money(c.outstandingAmount)
        b.dpd.text = "DPD ${c.dpd}"
        b.dpd.setTextColor(
            when {
                c.dpd >= 90 -> Color.parseColor("#B3261E")
                c.dpd >= 30 -> Color.parseColor("#B26A00")
                else -> Color.parseColor("#1B7F3B")
            },
        )
        b.statusChip.text = (c.recoveryStatus ?: c.assetClass ?: "").uppercase()
        b.lastVisit.text = if (c.lastVisitAt == null) {
            "Never visited"
        } else {
            "Last visit ${Formats.prettyDateTime(c.lastVisitAt)}  \u2022  ${c.visitCount} total"
        }
        b.root.setOnClickListener { onClick(c) }
    }

    override fun getItemCount() = items.size
}

/** Visit history on the customer detail screen. */
class VisitAdapter(private var items: List<VisitSummary> = emptyList()) :
    RecyclerView.Adapter<VisitAdapter.VH>() {

    class VH(val binding: ItemVisitBinding) : RecyclerView.ViewHolder(binding.root)

    fun submit(newItems: List<VisitSummary>) {
        items = newItems
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = VH(
        ItemVisitBinding.inflate(LayoutInflater.from(parent.context), parent, false),
    )

    override fun onBindViewHolder(holder: VH, position: Int) {
        val v = items[position]
        val b = holder.binding
        b.visitStatus.text = (v.visitStatus ?: "visit").replace('_', ' ').uppercase()
        b.visitDate.text = Formats.prettyDateTime(v.visitedAt)
        b.visitMeta.text = listOfNotNull(
            v.metPerson?.let { "Met: $it" },
            v.promiseAmount?.let { "Promised ${Formats.money(it)}" },
            v.promiseDate?.let { "by ${Formats.prettyDate(it)}" },
        ).joinToString("  \u2022  ")
        b.visitMeta.visible(b.visitMeta.text.isNotEmpty())
        b.visitRemarks.text = v.remarks.orEmpty()
        b.visitRemarks.visible(!v.remarks.isNullOrBlank())
    }

    override fun getItemCount() = items.size
}

/** Sync queue rows. */
class QueueAdapter(private var items: List<QueueItem> = emptyList()) :
    RecyclerView.Adapter<QueueAdapter.VH>() {

    class VH(val binding: ItemQueueBinding) : RecyclerView.ViewHolder(binding.root)

    fun submit(newItems: List<QueueItem>) {
        items = newItems
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = VH(
        ItemQueueBinding.inflate(LayoutInflater.from(parent.context), parent, false),
    )

    override fun onBindViewHolder(holder: VH, position: Int) {
        val item = items[position]
        val b = holder.binding
        b.kind.text = when (item.kind) {
            QueueDb.KIND_VISIT -> "Visit"
            QueueDb.KIND_RECOVERY -> "Recovery"
            else -> item.kind
        }
        b.status.text = if (item.isFailed) "REJECTED" else "WAITING"
        b.status.setTextColor(
            if (item.isFailed) Color.parseColor("#B3261E") else Color.parseColor("#B26A00"),
        )
        // Prefer the label saved with the row; fall back to reading the payload.
        b.label.text = item.label?.takeIf { it.isNotBlank() } ?: describePayload(item)
        b.meta.text = "Saved ${Formats.prettyEpoch(item.createdAt)}" +
            "  \u2022  ${item.attempts} attempt(s)"
        val error = item.errorMessage
        b.error.text = error.orEmpty()
        b.error.visible(!error.isNullOrBlank())
    }

    private fun describePayload(item: QueueItem): String = when (item.kind) {
        QueueDb.KIND_VISIT -> VisitDraft.fromJson(item.payload)
            ?.let { "Loan #${it.loanId} \u2014 ${it.visitStatus}" }
            ?: "Unreadable visit"
        QueueDb.KIND_RECOVERY -> RecoveryDraft.fromJson(item.payload)
            ?.let { "Loan #${it.loanId} \u2014 ${Formats.money(it.amount)}" }
            ?: "Unreadable recovery"
        else -> item.uid
    }

    override fun getItemCount() = items.size
}

/** Horizontal thumbnail strip on the visit form. */
class PhotoAdapter(
    private val onRemove: (File) -> Unit,
) : RecyclerView.Adapter<PhotoAdapter.VH>() {

    private val files = ArrayList<File>()

    class VH(val binding: ItemPhotoBinding) : RecyclerView.ViewHolder(binding.root)

    fun submit(newFiles: List<File>) {
        files.clear()
        files.addAll(newFiles)
        notifyDataSetChanged()
    }

    override fun onCreateViewHolder(parent: ViewGroup, viewType: Int) = VH(
        ItemPhotoBinding.inflate(LayoutInflater.from(parent.context), parent, false),
    )

    override fun onBindViewHolder(holder: VH, position: Int) {
        val file = files[position]
        // Decoding on the main thread is acceptable here: thumbnails are at most
        // 240 px and there are rarely more than a handful of photos per visit.
        holder.binding.thumb.setImageBitmap(PhotoStore.thumbnail(file))
        holder.binding.removeAction.setOnClickListener { onRemove(file) }
    }

    override fun getItemCount() = files.size
}
