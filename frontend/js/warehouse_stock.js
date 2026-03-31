/**
 * Warehouse Stock page
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let stockCustomerAc = null;
    let stockSupplierAc = null;

    function stockT(text, replacements = null) {
        return typeof t === "function" ? t(text, replacements) : text;
    }

    function formatStockCbm(value, maxDecimals = 2) {
        if (typeof formatDisplayCbm === "function") {
            return formatDisplayCbm(value, maxDecimals) || "0";
        }
        return String(parseFloat(value || 0) || 0);
    }

    function buildStockOrderTitle(orderId) {
        if ((typeof uiLocale === "function" ? uiLocale() : "en") === "zh-CN") {
            return `订单 #${orderId}`;
        }
        return `Order #${orderId}`;
    }

    function getSelectedStockStatuses() {
        return Array.from(
            document.querySelectorAll(".stock-status-filter:checked"),
        ).map((el) => el.value);
    }

    function stockStatusDisplay(status) {
        return typeof statusLabel === "function" ? statusLabel(status) : status;
    }

    window.updateStockStatusFilterSummary = function () {
        const summaryEl = document.getElementById("filterStatusSummary");
        if (!summaryEl) return;
        const selected = getSelectedStockStatuses();
        const mode = document.getElementById("filterStatusMode")?.value || "include";
        if (!selected.length) {
            summaryEl.textContent = stockT("All statuses");
            return;
        }
        summaryEl.textContent =
            (mode === "exclude"
                ? stockT("Excluding:")
                : stockT("Including:")) +
            " " +
            selected.map(stockStatusDisplay).join(", ");
    };

    function setStockStatusFilter(statuses = [], mode = "include") {
        const selected = new Set((statuses || []).map(String));
        document.querySelectorAll(".stock-status-filter").forEach((el) => {
            el.checked = selected.has(el.value);
        });
        const modeEl = document.getElementById("filterStatusMode");
        if (modeEl) modeEl.value = mode === "exclude" ? "exclude" : "include";
        window.updateStockStatusFilterSummary();
    }

    window.clearStockStatusFilter = function () {
        setStockStatusFilter([], "include");
        loadStock();
    };

    async function api(path) {
        const r = await fetch(API + path, { credentials: "same-origin" });
        const d = await r.json();
        if (!r.ok || d.error)
            throw new Error(d.message || stockT("Request failed"));
        return d;
    }

    window.loadStock = async function () {
        const params = new URLSearchParams();
        const cid = document.getElementById("filterCustomerId").value;
        const sid = document.getElementById("filterSupplierId").value;
        const statuses = getSelectedStockStatuses();
        const statusMode =
            document.getElementById("filterStatusMode")?.value || "include";
        const q = document.getElementById("filterQ").value.trim();
        if (cid) params.set("customer_id", cid);
        if (sid) params.set("supplier_id", sid);
        statuses.forEach((status) => params.append("status[]", status));
        if (statuses.length) params.set("status_mode", statusMode);
        if (q) params.set("q", q);
        try {
            const d = await api("/warehouse-stock?" + params.toString());
            renderStock(d.data);
        } catch (e) {
            alert(e.message || stockT("Failed to load stock"));
        }
    };

    function renderStock(rows) {
        const tbody = document.getElementById("stockTableBody");
        if (!rows || rows.length === 0) {
            tbody.innerHTML =
                `<tr><td colspan="9" class="text-center text-muted py-4">${escapeHtml(
                    stockT("No stock found."),
                )}</td></tr>`;
            return;
        }
        tbody.innerHTML = rows
            .map(
                (r) => `
            <tr>
                <td><a href="/cargochina/orders.php?id=${r.order_id}">#${r.order_id}</a></td>
                <td>${escapeHtml(r.customer_name || "")}</td>
                <td>${escapeHtml(r.supplier_name || "—")}</td>
                <td><span class="badge bg-secondary">${escapeHtml(stockStatusDisplay(r.status || ""))}</span></td>
                <td>${escapeHtml(r.description_en || r.description_cn || r.product_desc_en || r.product_desc_cn || "—")}</td>
                <td>${r.quantity || "—"}</td>
                <td>${r.declared_cbm != null ? formatStockCbm(r.declared_cbm, 2) : "—"}</td>
                <td>${r.order_actual_cbm != null ? formatStockCbm(r.order_actual_cbm, 2) : "—"}</td>
                <td><button type="button" class="btn btn-sm btn-outline-info" onclick="openStockOrderInfo(${Number(r.order_id)})" title="${escapeHtml(stockT("View full order details"))}">${escapeHtml(stockT("Info"))}</button></td>
            </tr>
        `,
            )
            .join("");
    }

    function renderStatusBadge(status) {
        const badgeClass =
            typeof statusBadgeClass === "function"
                ? statusBadgeClass(status)
                : "bg-secondary";
        const label =
            typeof statusLabel === "function" ? statusLabel(status) : status;
        return `<span class="badge ${badgeClass}">${escapeHtml(
            label || "—",
        )}</span>`;
    }

    function renderStockOrderInfo(order) {
        const attachments = (order.attachments || [])
            .map((attachment) => {
                const filePath = attachment.file_path || "";
                const fileName = filePath.split("/").pop() || stockT("Attachment");
                return `<a class="btn btn-sm btn-outline-secondary me-2 mb-2" target="_blank" rel="noopener" href="/cargochina/backend/${escapeHtml(filePath)}">${escapeHtml(fileName)}</a>`;
            })
            .join("");
        const photos = (order.receipt?.photos || [])
            .map((photo) => {
                const filePath = photo.file_path || "";
                return `<img src="/cargochina/backend/${escapeHtml(filePath)}" alt="${escapeHtml(stockT("Receipt evidence"))}" class="img-thumbnail me-2 mb-2" style="max-width:120px;">`;
            })
            .join("");
        const itemsHtml = (order.items || [])
            .map(
                (item) => `
                    <tr>
                        <td>${escapeHtml(item.description_en || item.description_cn || "—")}</td>
                        <td>${escapeHtml(item.shipping_code || "—")}</td>
                        <td>${escapeHtml(item.item_no || "—")}</td>
                        <td>${escapeHtml(item.supplier_name || order.supplier_name || "—")}</td>
                        <td>${item.quantity != null ? escapeHtml(String(item.quantity)) : "—"}</td>
                        <td>${item.declared_cbm != null ? formatStockCbm(item.declared_cbm || 0, 3) : "—"}</td>
                    </tr>
                `,
            )
            .join("");

        return `
            <div class="row g-3">
                <div class="col-lg-4">
                    <div class="border rounded p-3 h-100">
                        <div><strong>${escapeHtml(stockT("Customer:"))}</strong> ${escapeHtml(order.customer_name || "—")}</div>
                        <div><strong>${escapeHtml(stockT("Supplier:"))}</strong> ${escapeHtml(order.supplier_name || "—")}</div>
                        <div><strong>${escapeHtml(stockT("Status:"))}</strong> ${renderStatusBadge(order.status || "")}</div>
                        <div><strong>${escapeHtml(stockT("Expected Ready:"))}</strong> ${escapeHtml(order.expected_ready_date || "—")}</div>
                        <div><strong>${escapeHtml(stockT("Destination:"))}</strong> ${escapeHtml(order.destination_country_name || "—")}</div>
                        <div><strong>${escapeHtml(stockT("Shipping Code:"))}</strong> ${escapeHtml(order.default_shipping_code || order.shipping_code || "—")}</div>
                        <div><strong>${escapeHtml(stockT("High Alert:"))}</strong> ${escapeHtml(order.high_alert_notes || "—")}</div>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div class="border rounded p-3 h-100">
                        <div class="fw-semibold mb-2">${escapeHtml(stockT("Items"))}</div>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>${escapeHtml(stockT("Item"))}</th>
                                        <th>${escapeHtml(stockT("Shipping"))}</th>
                                        <th>${escapeHtml(stockT("Item No"))}</th>
                                        <th>${escapeHtml(stockT("Supplier"))}</th>
                                        <th>${escapeHtml(stockT("Qty"))}</th>
                                        <th>${escapeHtml(stockT("CBM"))}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHtml || `<tr><td colspan="6" class="text-center text-muted py-3">${escapeHtml(stockT("No items found."))}</td></tr>`}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2">${escapeHtml(stockT("Attachments"))}</div>
                        ${attachments || `<div class="text-muted small">${escapeHtml(stockT("No order attachments."))}</div>`}
                    </div>
                </div>
                <div class="col-12">
                    <div class="border rounded p-3">
                        <div class="fw-semibold mb-2">${escapeHtml(stockT("Receipt Photos"))}</div>
                        ${photos || `<div class="text-muted small">${escapeHtml(stockT("No receipt photos."))}</div>`}
                    </div>
                </div>
            </div>
        `;
    }

    window.openStockOrderInfo = async function (orderId) {
        const titleEl = document.getElementById("stockOrderInfoTitle");
        const bodyEl = document.getElementById("stockOrderInfoBody");
        const modalEl = document.getElementById("stockOrderInfoModal");
        if (!titleEl || !bodyEl || !modalEl) return;

        titleEl.textContent = buildStockOrderTitle(orderId);
        bodyEl.innerHTML =
            `<div class="text-center py-4 text-muted">${escapeHtml(
                stockT("Loading order details…"),
            )}</div>`;
        bootstrap.Modal.getOrCreateInstance(modalEl).show();

        try {
            const response = await api(`/orders/${orderId}`);
            bodyEl.innerHTML = renderStockOrderInfo(response.data || {});
        } catch (error) {
            bodyEl.innerHTML = `<div class="alert alert-danger mb-0">${escapeHtml(
                error.message || stockT("Failed to load order details"),
            )}</div>`;
        }
    };

    function escapeHtml(s) {
        if (!s) return "";
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    document.addEventListener("DOMContentLoaded", function () {
        const urlParams = new URLSearchParams(window.location.search);
        const statusFromUrl = urlParams.getAll("status[]");
        const legacyStatus = urlParams.get("status");
        const statusMode = urlParams.get("status_mode") || "include";
        if (statusFromUrl.length) {
            setStockStatusFilter(statusFromUrl, statusMode);
        } else if (legacyStatus) {
            setStockStatusFilter([legacyStatus], statusMode);
        } else {
            window.updateStockStatusFilterSummary();
        }

        if (typeof Autocomplete !== "undefined") {
            stockCustomerAc = Autocomplete.init(
                document.getElementById("filterCustomerSearch"),
                {
                    resource: "customers",
                    searchPath: "/search",
                    placeholder: stockT("Type to search customer..."),
                    onSelect: (item) => {
                        document.getElementById("filterCustomerId").value =
                            item.id || "";
                    },
                },
            );
            stockSupplierAc = Autocomplete.init(
                document.getElementById("filterSupplierSearch"),
                {
                    resource: "suppliers",
                    searchPath: "/search",
                    placeholder: stockT("Type to search supplier..."),
                    onSelect: (item) => {
                        document.getElementById("filterSupplierId").value =
                            item.id || "";
                    },
                },
            );
            document
                .getElementById("filterCustomerSearch")
                ?.addEventListener("input", () => {
                    document.getElementById("filterCustomerId").value = "";
                });
            document
                .getElementById("filterSupplierSearch")
                ?.addEventListener("input", () => {
                    document.getElementById("filterSupplierId").value = "";
                });
        }
        loadStock();
    });
})();
