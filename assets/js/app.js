/* =====================================================================
   LRMS admin panel - progressive enhancement only.
   Every page works without this file; it just adds convenience.
   No build step, no bundler, no framework.
   ===================================================================== */
(function () {
    'use strict';

    var base = window.LRMS_BASE || '/';

    // ------------------------------------------------------------------
    // Confirm destructive actions (any form with data-confirm)
    // ------------------------------------------------------------------
    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) { return; }

        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        // Stop double submission (a slow shared host + an impatient click is
        // exactly how duplicate records get created).
        var submitters = form.querySelectorAll('[type="submit"]');
        window.setTimeout(function () {
            submitters.forEach(function (button) {
                button.disabled = true;
                if (button.tagName === 'BUTTON' && !button.dataset.originalHtml) {
                    button.dataset.originalHtml = button.innerHTML;
                    button.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Working...';
                }
            });
        }, 0);
    });

    // ------------------------------------------------------------------
    // Auto-submit filter forms when a select changes
    // ------------------------------------------------------------------
    document.querySelectorAll('form[data-auto-submit] select').forEach(function (select) {
        select.addEventListener('change', function () {
            select.form.submit();
        });
    });

    // ------------------------------------------------------------------
    // Dashboard charts
    // ------------------------------------------------------------------
    var chartHost = document.getElementById('lrmsTrendChart');
    if (chartHost && window.Chart) {
        fetch(base + 'dashboard/charts', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin'
        })
            .then(function (response) {
                if (!response.ok) { throw new Error('HTTP ' + response.status); }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.success) {
                    throw new Error((payload && payload.message) || 'Chart data unavailable');
                }
                renderTrend(chartHost, payload.data.trend);
                renderAssetSplit(payload.data.asset_split);
            })
            .catch(function (error) {
                // Never fail silently - tell the operator what happened.
                var holder = chartHost.parentElement;
                if (holder) {
                    holder.innerHTML =
                        '<div class="alert alert-warning mb-0 small">' +
                        'Charts could not be loaded: ' + escapeHtml(error.message) +
                        '. The figures above are still accurate.</div>';
                }
            });
    }

    function renderTrend(canvas, trend) {
        if (!trend) { return; }
        new window.Chart(canvas, {
            type: 'line',
            data: {
                labels: trend.labels,
                datasets: [
                    {
                        label: 'Visits',
                        data: trend.visits,
                        borderColor: '#0d5c46',
                        backgroundColor: 'rgba(13,92,70,.12)',
                        tension: 0.32,
                        fill: true,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Recovery (Rs.)',
                        data: trend.recoveries,
                        borderColor: '#0d6efd',
                        backgroundColor: 'rgba(13,110,253,.10)',
                        tension: 0.32,
                        fill: true,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { position: 'bottom' } },
                scales: {
                    y: { beginAtZero: true, position: 'left', title: { display: true, text: 'Visits' } },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'Recovery' },
                        ticks: { callback: function (value) { return shortMoney(value); } }
                    }
                }
            }
        });
    }

    function renderAssetSplit(split) {
        var canvas = document.getElementById('lrmsAssetChart');
        if (!canvas || !split || !split.labels || split.labels.length === 0) { return; }

        new window.Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: split.labels,
                datasets: [{
                    data: split.values,
                    backgroundColor: [
                        '#0d5c46', '#ffc107', '#fd7e14', '#dc3545',
                        '#6f42c1', '#0dcaf0', '#20c997', '#6c757d', '#212529'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                cutout: '58%'
            }
        });
    }

    // ------------------------------------------------------------------
    // Live tracking map (only when a Google Maps key is configured)
    // ------------------------------------------------------------------
    window.lrmsInitTrackingMap = function (config) {
        var host = document.getElementById('lrmsTrackingMap');
        if (!host || !window.google || !window.google.maps) { return; }

        var map = new window.google.maps.Map(host, {
            center: { lat: config.lat, lng: config.lng },
            zoom: config.zoom || 8,
            mapTypeControl: false
        });

        var info = new window.google.maps.InfoWindow();

        function load() {
            fetch(base + 'tracking/data', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin'
            })
                .then(function (r) { return r.json(); })
                .then(function (payload) {
                    if (!payload.success) { return; }
                    (payload.data.agents || []).forEach(function (agent) {
                        var marker = new window.google.maps.Marker({
                            position: { lat: agent.latitude, lng: agent.longitude },
                            map: map,
                            title: agent.name
                        });
                        marker.addListener('click', function () {
                            info.setContent(
                                '<div style="font-size:13px">' +
                                '<strong>' + escapeHtml(agent.name) + '</strong><br>' +
                                escapeHtml(agent.bc_code || '') + '<br>' +
                                'Last seen: ' + escapeHtml(agent.recorded_at) + '<br>' +
                                'Battery: ' + escapeHtml(String(agent.battery_pct == null ? '-' : agent.battery_pct)) + '%' +
                                '</div>'
                            );
                            info.open(map, marker);
                        });
                    });
                })
                .catch(function () { /* the page already shows a fallback list */ });
        }

        load();
        window.setInterval(load, 60000);
    };

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------
    function shortMoney(value) {
        if (value >= 10000000) { return (value / 10000000).toFixed(1) + ' Cr'; }
        if (value >= 100000) { return (value / 100000).toFixed(1) + ' L'; }
        if (value >= 1000) { return (value / 1000).toFixed(0) + ' K'; }
        return value;
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }
})();
