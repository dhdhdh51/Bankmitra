package com.lrms.recovery.ui

import android.content.Intent
import android.os.Bundle
import android.view.Menu
import androidx.appcompat.widget.SearchView
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import androidx.recyclerview.widget.RecyclerView
import com.lrms.recovery.R
import com.lrms.recovery.data.net.ApiResult
import com.lrms.recovery.databinding.ActivityCustomerListBinding
import com.lrms.recovery.ui.adapter.CustomerAdapter
import com.lrms.recovery.util.visible
import kotlinx.coroutines.Job
import kotlinx.coroutines.delay
import kotlinx.coroutines.launch

/**
 * `GET /customers` with search, pull-to-refresh and paging.
 *
 * Paging uses `meta.pages` from the envelope: when the user scrolls near the
 * bottom and `page < pages`, the next page is fetched and appended. There is no
 * Paging library dependency - a single Int and a boolean flag are enough for a
 * list of a few hundred loans.
 *
 * Search is debounced by 350 ms so typing "Ramesh" is one request, not six.
 */
class CustomerListActivity : BaseActivity() {

    private lateinit var binding: ActivityCustomerListBinding
    private lateinit var adapter: CustomerAdapter

    private var page = 1
    private var totalPages = 1
    private var loading = false
    private var query: String? = null
    private var searchJob: Job? = null

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        if (!session.isSignedIn) return
        binding = ActivityCustomerListBinding.inflate(layoutInflater)
        setContentView(binding.root)
        setSupportActionBar(binding.toolbar)

        adapter = CustomerAdapter { customer ->
            startActivity(
                Intent(this, CustomerDetailActivity::class.java)
                    .putExtra(CustomerDetailActivity.EXTRA_LOAN_ID, customer.loanId)
                    .putExtra(CustomerDetailActivity.EXTRA_NAME, customer.fullName),
            )
        }
        val layoutManager = LinearLayoutManager(this)
        binding.list.layoutManager = layoutManager
        binding.list.adapter = adapter
        binding.list.addOnScrollListener(object : RecyclerView.OnScrollListener() {
            override fun onScrolled(rv: RecyclerView, dx: Int, dy: Int) {
                if (dy <= 0 || loading || page >= totalPages) return
                val lastVisible = layoutManager.findLastVisibleItemPosition()
                if (lastVisible >= adapter.itemCount - 5) loadPage(page + 1, replace = false)
            }
        })

        binding.swipeRefresh.setOnRefreshListener { loadPage(1, replace = true) }
        BottomNavHelper.attach(this, binding.bottomNav, R.id.nav_customers)

        loadPage(1, replace = true)
    }

    override fun onCreateOptionsMenu(menu: Menu): Boolean {
        menuInflater.inflate(R.menu.menu_customer_list, menu)
        val searchItem = menu.findItem(R.id.action_search)
        (searchItem?.actionView as? SearchView)?.apply {
            queryHint = getString(R.string.search_hint)
            setOnQueryTextListener(object : SearchView.OnQueryTextListener {
                override fun onQueryTextSubmit(text: String?): Boolean {
                    applySearch(text)
                    return true
                }

                override fun onQueryTextChange(text: String?): Boolean {
                    // Debounce: only the last keystroke in a 350 ms window fires.
                    searchJob?.cancel()
                    searchJob = lifecycleScope.launch {
                        delay(350)
                        applySearch(text)
                    }
                    return true
                }
            })
        }
        return true
    }

    private fun applySearch(text: String?) {
        val trimmed = text?.trim()?.takeIf { it.isNotEmpty() }
        if (trimmed == query) return
        query = trimmed
        loadPage(1, replace = true)
    }

    private fun loadPage(target: Int, replace: Boolean) {
        if (loading) return
        loading = true
        if (!replace) binding.pageProgress.visible(true)

        lifecycleScope.launch {
            val result = repo.customers(search = query, page = target)
            loading = false
            binding.swipeRefresh.isRefreshing = false
            binding.pageProgress.visible(false)

            when (result) {
                is ApiResult.Success -> {
                    page = result.meta?.page ?: target
                    totalPages = result.meta?.pages ?: 1
                    if (replace) adapter.replaceAll(result.data) else adapter.append(result.data)
                    binding.emptyText.visible(adapter.itemCount == 0)
                }

                is ApiResult.Failure -> {
                    // Surface it, and if we have nothing at all show it in-place.
                    if (adapter.itemCount == 0) {
                        binding.emptyText.text = result.message
                        binding.emptyText.visible(true)
                    }
                    handleFailure(result, "Could not load customers")
                }
            }
        }
    }
}
