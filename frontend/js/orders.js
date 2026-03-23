let itemIndex = 0;
let orderCustomerAc, orderSupplierAc, orderSearchAc, orderDestinationCountryAc;
let orderCustomerCountryShipping = [];
let orderEffectiveShippingCode = "";

/** Round CBM to 6 decimals to avoid floating-point drift (e.g. 0.2 → 0.2 not 0.20000000000000004). */
function roundCbm6(val) {
    const n = parseFloat(val);
    return Number.isNaN(n) ? 0 : Math.round(n * 1e6) / 1e6;
}
const RECENT_KEY_CUSTOMERS = "cargochina_recent_customers";
const RECENT_KEY_SUPPLIERS = "cargochina_recent_suppliers";
const RECENT_MAX = 8;

function getRecent(key) {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : [];
    } catch (_) {
        return [];
    }
}

function saveRecent(key, item, max = RECENT_MAX) {
    if (!item?.id) return;
    const list = getRecent(key);
    const entry = {
        id: item.id,
        name: item.name || item.code || `#${item.id}`,
        ...(key === RECENT_KEY_CUSTOMERS &&
            item.code != null && { code: item.code }),
    };
    const filtered = list.filter((x) => Number(x.id) !== Number(item.id));
    const updated = [entry, ...filtered].slice(0, max);
    try {
        localStorage.setItem(key, JSON.stringify(updated));
    } catch (_) {}
}

function renderRecentChips() {
    // Recent chips removed for compact layout
}

function selectRecentCustomer(id, name, shipCode) {
    orderCustomerAc?.setValue({ id, name, code: shipCode, default_shipping_code: shipCode });
    applyCustomerDefaultShippingCode(shipCode);
    (async () => {
        try {
            const res = await api("GET", "/customers/" + id);
            const cust = res.data || {};
            orderCustomerCountryShipping = cust.country_shipping || [];
            if (orderCustomerCountryShipping.length === 1) {
                const c = orderCustomerCountryShipping[0];
                setOrderDestinationCountry(c.country_id, c.country_name, c.country_code);
                applyCustomerDefaultShippingCode(c.shipping_code || shipCode);
                showOrderDestinationSelect(false);
            } else if (orderCustomerCountryShipping.length > 1) {
                renderOrderDestinationSelect();
                showOrderDestinationSelect(true);
            } else {
                showOrderDestinationSelect(false);
            }
        } catch (_) {
            showOrderDestinationSelect(false);
        }
    })();
}

function setOrderDestinationCountry(countryId, countryName, countryCode) {
    document.getElementById("orderDestinationCountryId").value = countryId || "";
    if (orderDestinationCountryAc) {
        orderDestinationCountryAc.setValue({ id: countryId, name: countryName, code: countryCode });
    }
    const inp = document.getElementById("orderDestinationCountry");
    if (inp) inp.value = (countryName || "") + " (" + (countryCode || "") + ")";
}

function renderOrderDestinationSelect() {
    const sel = document.getElementById("orderDestinationCountrySelect");
    if (!sel) return;
    sel.innerHTML =
        '<option value="">Select country...</option>' +
        orderCustomerCountryShipping
            .map(
                (c) =>
                    `<option value="${c.country_id}">${escapeHtml(c.country_name || "")} (${escapeHtml(c.country_code || "")})</option>`,
            )
            .join("");
}

function showOrderDestinationSelect(show) {
    const wrap = document.getElementById("orderDestinationCountrySelectWrap");
    const inputWrap = document.getElementById("orderDestinationCountryInputWrap");
    if (wrap) wrap.classList.toggle("d-none", !show);
    if (inputWrap) inputWrap.classList.toggle("d-none", show);
}

function selectRecentSupplier(id, name) {
    orderSupplierAc?.setValue({ id, name });
    applySelectedSupplierToItems({ id, name }, { onlyBlank: true });
}

function confirmMissingOrderExpectedReadyDate() {
    return window.confirm(
        "Expected Ready Date is empty. Continue saving this order without it? Date-based reminders, overdue tracking, and date filters will skip it until you fill it later.",
    );
}

function setItemSupplierValue(card, supplierId, supplierName) {
    if (!card) return;
    const supplierInput = card.querySelector(".item-supplier");
    const supplierIdInput = card.querySelector(".item-supplier-id");
    if (supplierIdInput) supplierIdInput.value = supplierId || "";
    if (card._supplierAc && supplierId && supplierName) {
        card._supplierAc.setValue({ id: supplierId, name: supplierName });
        return;
    }
    if (supplierInput) supplierInput.value = supplierName || "";
}

function productAlertTextFromItem(it) {
    const req = it?.product_required_design || it?.required_design;
    const note = it?.product_high_alert_note || it?.high_alert_note || "";
    return (req ? "Required design. " : "") + (note || "");
}

function renderProductAlertHint(card, note) {
    if (!card) return;
    const slot = card.querySelector(".product-alert-slot");
    if (!slot) return;
    if (!note) {
        slot.innerHTML = "";
        return;
    }
    slot.innerHTML = `<div class="product-alert-inline"><strong>Product alert:</strong> ${escapeHtml(note)}</div>`;
}

function applyCustomerDefaultShippingCode(code) {
    if (!code || typeof code !== "string") return;
    orderEffectiveShippingCode = code.trim();
}

/** Fetch next item sequence for customer+shipping_code (global count across all orders). */
async function fetchNextItemNo(customerId, shippingCode) {
    if (!customerId || !shippingCode) return 1;
    try {
        const res = await api("GET", "/customers/" + customerId + "/next-item-no?shipping_code=" + encodeURIComponent(shippingCode));
        return (res.data?.next ?? 1);
    } catch (_) {
        return 1;
    }
}

function applySelectedSupplierToItems(supplier, { onlyBlank = false } = {}) {
    if (!supplier?.id || !supplier?.name) return;
    document
        .querySelectorAll("#orderItemsBody .order-item-card")
        .forEach((card) => {
            const supplierInput = card.querySelector(".item-supplier");
            const supplierIdInput = card.querySelector(".item-supplier-id");
            const hasSupplier =
                supplierIdInput?.value?.trim() || supplierInput?.value?.trim();
            if (onlyBlank && hasSupplier) return;
            setItemSupplierValue(card, supplier.id, supplier.name);
        });
}

function resetOrderItems() {
    itemIndex = 0;
    document.getElementById("orderItemsBody").innerHTML = "";
}

function getItemQuantityFromData(it) {
    const cartons = parseFloat(it?.cartons ?? 0) || 0;
    const qtyPerCtn = parseFloat(it?.qty_per_carton ?? 0) || 0;
    const qty = parseFloat(it?.quantity ?? 0) || 0;
    return cartons > 0 && qtyPerCtn > 0 ? cartons * qtyPerCtn : qty;
}

function getItemWeightPerQty(it) {
    const totalWeight = parseFloat(it?.declared_weight ?? 0) || 0;
    const denom = getItemPerUnitDenom(it);
    return totalWeight > 0 && denom > 0 ? (totalWeight / denom).toFixed(4) : "";
}

/** Returns denominator for per-unit CBM/weight when loading item. Uses dimensions_scope when available. */
function getItemPerUnitDenom(it) {
    const scope = (
        it?.product_dimensions_scope ||
        it?.dimensions_scope ||
        "piece"
    )
        .toString()
        .toLowerCase();
    const cartons = parseFloat(it?.cartons ?? 0) || 0;
    const qty = getItemQuantityFromData(it);
    if (scope === "carton" && cartons > 0) return cartons;
    if (qty > 0) return qty;
    if (cartons > 0) return cartons;
    return 1;
}

function getOrderSupplierDisplay(order) {
    const items = order.items || [];
    const orderSupp = order.supplier_name || "";
    const names = new Set();
    items.forEach((it) => {
        const n = (it.supplier_name || orderSupp || "").trim();
        if (n) names.add(n);
    });
    if (names.size === 0) return orderSupp || "";
    if (names.size === 1) return [...names][0];
    return "Multiple (" + [...names].join(", ") + ")";
}

function getSelectedOrderStatuses() {
    return Array.from(
        document.querySelectorAll(".order-status-filter:checked"),
    ).map((el) => el.value);
}

function orderStatusDisplay(status) {
    return typeof statusLabel === "function" ? statusLabel(status) : status;
}

function truncateOrderSearchText(text, maxLen = 32) {
    const value = String(text || "").trim();
    if (!value) return "";
    return value.length > maxLen ? value.slice(0, maxLen - 3) + "..." : value;
}

function getOrderSearchSupplierText(order) {
    const itemSuppliers = String(order?.item_supplier_names || "").trim();
    if (itemSuppliers) return itemSuppliers;
    return String(order?.supplier_name || "").trim();
}

function renderOrderSearchMatch(order) {
    const parts = [`#${order.id}`, order.customer_name || ""];
    const supplier = truncateOrderSearchText(getOrderSearchSupplierText(order));
    const shippingCode = String(order?.shipping_codes || "")
        .split(",")
        .map((part) => part.trim())
        .filter(Boolean)[0];
    const itemPreview = truncateOrderSearchText(order?.item_preview || "", 26);

    if (supplier) parts.push(supplier);
    if (shippingCode) {
        parts.push(`SC ${shippingCode}`);
    } else if (itemPreview) {
        parts.push(itemPreview);
    }
    if (order.expected_ready_date)
        parts.push(`Ready ${order.expected_ready_date}`);
    if (order.status) parts.push(orderStatusDisplay(order.status));

    return parts.filter(Boolean).join(" — ");
}

function getOrderSearchDisplayValue(order) {
    const parts = [order.id, order.customer_name || ""];
    const shippingCode = String(order?.shipping_codes || "")
        .split(",")
        .map((part) => part.trim())
        .filter(Boolean)[0];
    if (shippingCode) parts.push(shippingCode);
    return parts.filter(Boolean).join(" ");
}

function updateOrderOverview(rows) {
    const list = rows || [];
    const counts = {
        visible: list.length,
        draft: list.filter((r) => r.status === "Draft").length,
        awaiting: list.filter(
            (r) => r.status === "AwaitingCustomerConfirmation",
        ).length,
        ready: list.filter((r) =>
            [
                "ReadyForConsolidation",
                "ConsolidatedIntoShipmentDraft",
                "AssignedToContainer",
            ].includes(r.status),
        ).length,
    };
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };
    setText("orderVisibleCount", counts.visible);
    setText("orderDraftCount", counts.draft);
    setText("orderAwaitingCount", counts.awaiting);
    setText("orderReadyCount", counts.ready);
}

function updateOrderStatusFilterSummary() {
    const summaryEl = document.getElementById("filterStatusSummary");
    if (!summaryEl) return;
    const selected = getSelectedOrderStatuses();
    const mode =
        document.getElementById("filterStatusMode")?.value || "include";
    if (!selected.length) {
        summaryEl.textContent = "All statuses";
        return;
    }
    summaryEl.textContent =
        (mode === "exclude" ? "Excluding: " : "Including: ") +
        selected.map(orderStatusDisplay).join(", ");
}

function setOrderStatusFilter(statuses = [], mode = "include") {
    const selected = new Set((statuses || []).map(String));
    document.querySelectorAll(".order-status-filter").forEach((el) => {
        el.checked = selected.has(el.value);
    });
    const modeEl = document.getElementById("filterStatusMode");
    if (modeEl) modeEl.value = mode === "exclude" ? "exclude" : "include";
    updateOrderStatusFilterSummary();
}

function buildOrderListQuery() {
    const params = new URLSearchParams();
    const statuses = getSelectedOrderStatuses();
    const statusMode =
        document.getElementById("filterStatusMode")?.value || "include";
    const q = (document.getElementById("orderSearch")?.value || "").trim();
    const orderType = (
        document.getElementById("filterOrderType")?.value || ""
    ).trim();
    statuses.forEach((status) => params.append("status[]", status));
    if (statuses.length) params.set("status_mode", statusMode);
    if (q) params.set("q", q);
    if (orderType) params.set("order_type", orderType);
    return params.toString();
}

window.clearOrderStatusFilter = function () {
    setOrderStatusFilter([], "include");
    loadOrders();
};

window.clearOrderSearch = function () {
    if (orderSearchAc) {
        orderSearchAc.setValue(null);
    } else {
        const searchEl = document.getElementById("orderSearch");
        if (searchEl) searchEl.value = "";
    }
    loadOrders();
};

document.addEventListener("DOMContentLoaded", () => {
    orderCustomerAc = Autocomplete.init(
        document.getElementById("orderCustomer"),
        {
            resource: "customers",
            placeholder: "Type customer name or code...",
            onSelect: async (item) => {
                saveRecent(RECENT_KEY_CUSTOMERS, item);
                try {
                    const res = await api("GET", "/customers/" + item.id);
                    const cust = res.data || {};
                    orderCustomerCountryShipping = cust.country_shipping || [];
                    const defShip = cust.default_shipping_code || item.default_shipping_code || "";
                    if (orderCustomerCountryShipping.length === 1) {
                        const c = orderCustomerCountryShipping[0];
                        setOrderDestinationCountry(c.country_id, c.country_name, c.country_code);
                        applyCustomerDefaultShippingCode(c.shipping_code || defShip);
                        showOrderDestinationSelect(false);
                    } else if (orderCustomerCountryShipping.length > 1) {
                        renderOrderDestinationSelect();
                        showOrderDestinationSelect(true);
                        applyCustomerDefaultShippingCode(defShip);
                    } else {
                        showOrderDestinationSelect(false);
                        applyCustomerDefaultShippingCode(defShip);
                    }
                } catch (e) {
                    applyCustomerDefaultShippingCode(item.default_shipping_code);
                    showOrderDestinationSelect(false);
                }
            },
        },
    );
    const destInput = document.getElementById("orderDestinationCountry");
    if (destInput) {
        orderDestinationCountryAc = Autocomplete.init(destInput, {
            resource: "countries",
            searchPath: "/search",
            minChars: 0,
            placeholder: "Search country...",
            displayValue: (c) => (c.name || "") + " (" + (c.code || "") + ")",
            renderItem: (c) => (c.name || "") + " (" + (c.code || "") + ")",
            onSelect: (c) => {
                document.getElementById("orderDestinationCountryId").value = c.id || "";
            },
        });
    }
    const destSelect = document.getElementById("orderDestinationCountrySelect");
    if (destSelect) {
        destSelect.addEventListener("change", function () {
            const val = this.value;
            document.getElementById("orderDestinationCountryId").value = val || "";
            const c = orderCustomerCountryShipping.find((x) => String(x.country_id) === val);
            if (c) applyCustomerDefaultShippingCode(c.shipping_code || "");
        });
    }
    orderSupplierAc = Autocomplete.init(
        document.getElementById("orderSupplier"),
        {
            resource: "suppliers",
            placeholder: "Type supplier name or code...",
            onSelect: (item) => {
                saveRecent(RECENT_KEY_SUPPLIERS, item);
                applySelectedSupplierToItems(item, { onlyBlank: true });
            },
        },
    );
    const orderSearchEl = document.getElementById("orderSearch");
    if (orderSearchEl) {
        orderSearchAc = Autocomplete.init(orderSearchEl, {
            resource: "orders",
            placeholder: "Customer, phone, shipping code, items...",
            renderItem: renderOrderSearchMatch,
            displayValue: getOrderSearchDisplayValue,
            onSelect: () => loadOrders(),
        });
        let searchDebounce;
        orderSearchEl.addEventListener("input", () => {
            clearTimeout(searchDebounce);
            searchDebounce = setTimeout(loadOrders, 200);
        });
        orderSearchEl.addEventListener("keydown", (event) => {
            const dropdownVisible = !!document.querySelector(
                ".autocomplete-dropdown",
            );
            if (event.key === "Enter" && !dropdownVisible) {
                event.preventDefault();
                loadOrders();
            }
        });
    }
    const curSel = document.getElementById("orderCurrency");
    if (curSel) curSel.addEventListener("change", updateOrderTotals);
    const urlParams = new URLSearchParams(window.location.search);
    const statusFromUrl = urlParams.getAll("status[]");
    const legacyStatus = urlParams.get("status");
    const statusMode = urlParams.get("status_mode") || "include";
    const orderTypeFromUrl = urlParams.get("order_type") || "";
    const orderTypeEl = document.getElementById("filterOrderType");
    if (orderTypeEl && orderTypeFromUrl) orderTypeEl.value = orderTypeFromUrl;
    if (statusFromUrl.length) {
        setOrderStatusFilter(statusFromUrl, statusMode);
    } else if (legacyStatus) {
        setOrderStatusFilter([legacyStatus], statusMode);
    } else {
        updateOrderStatusFilterSummary();
    }
    loadOrders();
});

async function loadOrders() {
    try {
        const qs = buildOrderListQuery();
        let path = "/orders";
        if (qs) path += "?" + qs;
        const res = await api("GET", path);
        const rows = res.data || [];
        const tbody = document.querySelector("#ordersTable tbody");
        const submittedCount = rows.filter(
            (r) => r.status === "Submitted",
        ).length;
        const draftCount = rows.filter((r) => r.status === "Draft").length;
        const bulkApproveBtn = document.getElementById("bulkApproveBtn");
        const bulkSubmitBtn = document.getElementById("bulkSubmitBtn");
        if (bulkApproveBtn)
            bulkApproveBtn.classList.toggle("d-none", submittedCount === 0);
        if (bulkSubmitBtn)
            bulkSubmitBtn.classList.toggle("d-none", draftCount === 0);
        updateOrderOverview(rows);

        const selectAll = document.getElementById("orderSelectAll");
        if (selectAll) {
            selectAll.checked = false;
            selectAll.onclick = () => {
                const checked = selectAll.checked;
                tbody
                    .querySelectorAll(".order-bulk-cb")
                    .forEach((cb) => (cb.checked = checked));
            };
        }
        const updateSelectAllState = () => {
            const cbs = tbody.querySelectorAll(".order-bulk-cb");
            const checked = tbody.querySelectorAll(".order-bulk-cb:checked");
            if (selectAll && cbs.length)
                selectAll.checked = checked.length === cbs.length;
        };
        tbody.innerHTML =
            rows
                .map((r) => {
                    const canBulk =
                        r.status === "Submitted" || r.status === "Draft";
                    const suppDisplay = getOrderSupplierDisplay(r);
                    const isDraftBuilder = r.order_type === "draft_procurement";
                    const exportHref = isDraftBuilder
                        ? `${window.API_BASE || "/cargochina/api/v1"}/draft-orders/${r.id}/export`
                        : `${window.API_BASE || "/cargochina/api/v1"}/orders/${r.id}/export`;
                    const exportLabel = isDraftBuilder ? "Draft CSV" : "Export";
                    const exportTitle = isDraftBuilder
                        ? "Export grouped draft order CSV"
                        : "Export as Excel";
                    return `
      <tr data-order-id="${r.id}" data-status="${escapeHtml(r.status)}">
        <td class="text-center">${canBulk ? `<input type="checkbox" class="form-check-input order-bulk-cb" data-order-id="${r.id}" data-status="${escapeHtml(r.status)}">` : ""}</td>
        <td>${r.id}</td>
        <td>${escapeHtml(r.customer_name)}${r.customer_priority_level && r.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escapeHtml(r.customer_priority_note || "")}">${escapeHtml(r.customer_priority_level)}</span>` : ""}</td>
        <td>${escapeHtml(suppDisplay)}</td>
        <td>${r.expected_ready_date || "—"}</td>
        <td><span class="badge ${typeof statusBadgeClass === "function" ? statusBadgeClass(r.status) : "bg-secondary"}">${escapeHtml(typeof statusLabel === "function" ? statusLabel(r.status) : r.status)}</span>${isDraftBuilder ? ' <span class="badge bg-dark-subtle text-dark border">Draft Order</span>' : ""}${r.high_alert_notes ? ' <span class="badge bg-warning text-dark" title="' + escapeHtml(r.high_alert_notes) + '">⚠️</span>' : ""}${r.container_code || r.container_eta ? ' <span class="badge bg-info text-dark ms-1" title="Container ' + escapeHtml(r.container_code || "—") + (r.container_eta ? ", ETA " + escapeHtml(r.container_eta) : "") + '">📦 ' + escapeHtml(r.container_code || "—") + (r.container_eta ? " · " + escapeHtml(r.container_eta) : "") + "</span>" : ""}</td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-info" onclick="showOrderInfo(${r.id})" title="View order details">ℹ</button>
          <button class="btn btn-sm btn-outline-primary" onclick="editOrder(${r.id})">${isDraftBuilder ? "Open Builder" : "Edit"}</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="copyOrder(${r.id})" title="Duplicate as new draft">Copy</button>
          <a class="btn btn-sm btn-outline-success" href="${exportHref}" download title="${exportTitle}">${exportLabel}</a>
          ${r.status === "Draft" ? `<button class="btn btn-sm btn-success" onclick="submitOrder(${r.id})">Submit</button>` : ""}
          ${r.status === "Submitted" ? `<button class="btn btn-sm btn-success" onclick="approveOrder(${r.id})">Approve</button>` : ""}
          ${r.status === "AwaitingCustomerConfirmation" ? `<button class="btn btn-sm btn-warning" onclick="confirmOrder(${r.id})">Confirm</button>` : ""}
          ${r.status === "ReadyForConsolidation" || r.status === "Confirmed" ? `<button class="btn btn-sm btn-outline-primary" onclick="openAssignDraftModal(${r.id}, '${escapeHtml(r.customer_name)}')" title="Assign to Shipment Draft">→ Draft</button>` : ""}
          <button class="btn btn-sm btn-outline-secondary" onclick="showOrderFinance(${r.id})" title="P&amp;L / Finance">$</button>
        </td>
      </tr>
    `;
                })
                .join("") ||
            '<tr><td colspan="7" class="text-muted">No orders yet.</td></tr>';
        tbody.querySelectorAll(".order-bulk-cb").forEach((cb) => {
            cb.addEventListener("change", updateSelectAllState);
        });
    } catch (e) {
        updateOrderOverview([]);
        showToast(e.message, "danger");
    }
}

async function exportOrdersCsv() {
    try {
        const qs = buildOrderListQuery();
        let path = "/orders";
        if (qs) path += "?" + qs;
        const res = await api("GET", path);
        const rows = res.data || [];
        const headers = [
            "ID",
            "Order Type",
            "Customer",
            "Supplier",
            "Expected Ready",
            "Status",
            "Total CBM",
            "Total Weight",
        ];
        const lines = [headers.join(",")];
        rows.forEach((r) => {
            const suppDisplay = getOrderSupplierDisplay(r);
            const cbm = (r.items || []).reduce(
                (s, i) => s + (parseFloat(i.declared_cbm) || 0),
                0,
            );
            const wt = (r.items || []).reduce(
                (s, i) => s + (parseFloat(i.declared_weight) || 0),
                0,
            );
            lines.push(
                [
                    r.id,
                    r.order_type || "standard",
                    '"' + (r.customer_name || "").replace(/"/g, '""') + '"',
                    '"' + (suppDisplay || "").replace(/"/g, '""') + '"',
                    r.expected_ready_date || "",
                    r.status || "",
                    cbm.toFixed(4),
                    wt.toFixed(2),
                ].join(","),
            );
        });
        const csv = lines.join("\n");
        const blob = new Blob(["\ufeff" + csv], {
            type: "text/csv;charset=utf-8",
        });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = "orders_" + new Date().toISOString().slice(0, 10) + ".csv";
        a.click();
        URL.revokeObjectURL(a.href);
        showToast("Exported " + rows.length + " orders");
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function openOrderForm() {
    document.getElementById("orderForm").reset();
    orderCustomerAc?.setValue(null);
    orderSupplierAc?.setValue(null);
    document.getElementById("orderId").value = "";
    document.getElementById("orderDestinationCountryId").value = "";
    orderCustomerCountryShipping = [];
    orderEffectiveShippingCode = "";
    showOrderDestinationSelect(false);
    const destInp = document.getElementById("orderDestinationCountry");
    if (destInp) destInp.value = "";
    document.getElementById("orderModalTitle").textContent = "New Order";
    resetOrderItems();
    addOrderItem();
    renderRecentChips();
    loadOrderTemplatesDropdown();
}

async function loadOrderTemplatesDropdown() {
    const sel = document.getElementById("orderTemplateSelect");
    if (!sel) return;
    try {
        const res = await api("GET", "/order-templates");
        const list = res.data || [];
        sel.innerHTML =
            '<option value="">Load template...</option>' +
            list
                .map(
                    (t) =>
                        `<option value="${t.id}">${escapeHtml(t.name)}</option>`,
                )
                .join("");
    } catch (_) {
        sel.innerHTML = '<option value="">Load template...</option>';
    }
}

async function loadOrderTemplate(id) {
    if (!id) return;
    try {
        const res = await api("GET", "/order-templates/" + id);
        const tpl = res.data;
        if (!tpl?.items?.length) {
            showToast("Template has no items", "warning");
            return;
        }
        resetOrderItems();
        const uniqueSuppliers = [
            ...new Map(
                tpl.items
                    .filter((it) => it.supplier_id && it.supplier_name)
                    .map((it) => [
                        String(it.supplier_id),
                        { id: it.supplier_id, name: it.supplier_name },
                    ]),
            ).values(),
        ];
        if (uniqueSuppliers.length === 1) {
            orderSupplierAc?.setValue(uniqueSuppliers[0]);
        }
        const key = (it) => `${it.product_id || ""}|${it.supplier_id || ""}|${(it.description_cn || it.description_en || "").trim()}`;
        let lastCard = null;
        let lastKey = null;
        for (const it of tpl.items) {
            const k = key(it);
            const cartons = it.cartons ?? 0;
            const qtyPerCtn = it.qty_per_carton ?? 0;
            const qty = it.quantity ?? (cartons > 0 && qtyPerCtn > 0 ? cartons * qtyPerCtn : 0);
            const denom = getItemPerUnitDenom(it);
            const cbmPerUnit = denom > 0 && it.declared_cbm ? roundCbm6(parseFloat(it.declared_cbm) / denom).toFixed(6) : "";
            const rowData = {
                cartons, qtyPerCtn, qty,
                unit_price: it.unit_price ?? "", sell_price: it.sell_price ?? "",
                cbm: cbmPerUnit, l: it.item_length ?? "", w: it.item_width ?? "", h: it.item_height ?? "",
                weight: getItemWeightPerQty(it),
            };
            if (lastCard && k === lastKey) {
                addItemPackagingRow(lastCard);
                const rows = lastCard.querySelectorAll(".order-item-packaging-row");
                const row = rows[rows.length - 1];
                if (row) {
                    row.querySelector(".item-cartons").value = rowData.cartons || "";
                    row.querySelector(".item-qty-per-ctn").value = rowData.qtyPerCtn || "";
                    row.querySelector(".item-qty").value = rowData.qty || "";
                    row.querySelector(".item-unit-price").value = rowData.unit_price;
                    const sp = row.querySelector(".item-sell-price");
                    if (sp) sp.value = rowData.sell_price ?? "";
                    row.querySelector(".item-cbm").value = rowData.cbm;
                    row.querySelector(".item-l").value = rowData.l;
                    row.querySelector(".item-w").value = rowData.w;
                    row.querySelector(".item-h").value = rowData.h;
                    row.querySelector(".item-weight").value = rowData.weight;
                }
            } else {
                addOrderItem();
                lastCard = document.querySelector("#orderItemsBody .order-item-card:last-child");
                if (!lastCard) continue;
                lastKey = k;
                lastCard.querySelector(".item-product-id").value = it.product_id || "";
                if (it.supplier_id) setItemSupplierValue(lastCard, it.supplier_id, it.supplier_name);
                lastCard.querySelector(".item-item-no").value = it.item_no || "";
                lastCard.querySelector(".item-shipping-code").value = it.shipping_code || "";
                lastCard.querySelector(".item-desc").value = it.description_cn || it.description_en || "";
                const firstRow = lastCard.querySelector(".order-item-packaging-row");
                if (firstRow) {
                    firstRow.querySelector(".item-cartons").value = rowData.cartons || "";
                    firstRow.querySelector(".item-qty-per-ctn").value = rowData.qtyPerCtn || "";
                    firstRow.querySelector(".item-qty").value = rowData.qty || "";
                    firstRow.querySelector(".item-unit-price").value = rowData.unit_price;
                    const sp = firstRow.querySelector(".item-sell-price");
                    if (sp) sp.value = rowData.sell_price ?? "";
                    firstRow.querySelector(".item-cbm").value = rowData.cbm;
                    firstRow.querySelector(".item-l").value = rowData.l;
                    firstRow.querySelector(".item-w").value = rowData.w;
                    firstRow.querySelector(".item-h").value = rowData.h;
                    firstRow.querySelector(".item-weight").value = rowData.weight;
                }
                lastCard.dataset.dimensionsScope = (it.product_dimensions_scope || it.dimensions_scope || "piece").toString().toLowerCase();
                renderProductAlertHint(lastCard, productAlertTextFromItem(it));
            }
            updateItemComputed(lastCard?.dataset?.idx);
        }
        updateOrderTotals();
        document.getElementById("orderTemplateSelect").value = "";
        showToast("Template loaded");
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function collectItemsForTemplate() {
    const items = [];
    document
        .querySelectorAll("#orderItemsBody .order-item-card")
        .forEach((tr) => {
            const desc = tr.querySelector(".item-desc")?.value?.trim();
            const productId = tr.querySelector(".item-product-id")?.value;
            const supplierId = tr.querySelector(".item-supplier-id")?.value?.trim() || null;
            const itemNo = tr.querySelector(".item-item-no")?.value?.trim() || null;
            const shippingCode = tr.querySelector(".item-shipping-code")?.value?.trim() || null;
            tr.querySelectorAll(".order-item-packaging-row").forEach((row) => {
                const cartons = parseInt(row.querySelector(".item-cartons")?.value || 0, 10);
                const qtyPerCtn = parseFloat(row.querySelector(".item-qty-per-ctn")?.value || 0);
                const qtyInput = parseFloat(row.querySelector(".item-qty")?.value || 0);
                const qty = cartons > 0 && qtyPerCtn > 0 ? cartons * qtyPerCtn : qtyInput;
                if (qty <= 0 && cartons <= 0) return;
                const unit = cartons > 0 ? "cartons" : "pieces";
                const scope = (tr.dataset.dimensionsScope || "piece").toLowerCase();
                const scopeMult = scope === "carton" ? (cartons > 0 ? cartons : 0) : (qty > 0 ? qty : 0);
                let cbmPc = parseFloat(row.querySelector(".item-cbm")?.value || 0);
                const l = parseFloat(row.querySelector(".item-l")?.value) || 0;
                const w = parseFloat(row.querySelector(".item-w")?.value) || 0;
                const h = parseFloat(row.querySelector(".item-h")?.value) || 0;
                if (cbmPc <= 0 && l > 0 && w > 0 && h > 0) cbmPc = roundCbm6((l * w * h) / 1000000);
                const totalCbm = roundCbm6(cbmPc * scopeMult);
                const weightPc = parseFloat(row.querySelector(".item-weight")?.value || 0);
                const totalGw = weightPc * scopeMult;
                const totalAmountEl = row.querySelector(".item-total-amount");
                const totalAmount = totalAmountEl ? parseFloat(totalAmountEl.textContent || 0) : null;
                items.push({
                    product_id: productId || null,
                    supplier_id: supplierId || null,
                    item_no: itemNo || null,
                    shipping_code: shippingCode || null,
                    cartons: cartons || null,
                    qty_per_carton: qtyPerCtn || null,
                    quantity: qty,
                    unit,
                    declared_cbm: totalCbm || null,
                    declared_weight: totalGw || null,
                    item_length: l > 0 ? l : null,
                    item_width: w > 0 ? w : null,
                    item_height: h > 0 ? h : null,
                    unit_price: parseFloat(row.querySelector(".item-unit-price")?.value || 0) || null,
                    total_amount: qty > 0 && totalAmount ? totalAmount : null,
                    description_cn: desc || null,
                    description_en: desc || null,
                });
            });
        });
    return items;
}

async function saveOrderAsTemplate() {
    const items = collectItemsForTemplate();
    if (!items.length) {
        showToast("Add at least one item to save as template", "warning");
        return;
    }
    const name = prompt("Template name:");
    if (!name?.trim()) return;
    const templateItems = items.map((it) => ({
        item_no: it.item_no,
        shipping_code: it.shipping_code,
        product_id: it.product_id,
        supplier_id: it.supplier_id || null,
        description_cn: it.description_cn,
        description_en: it.description_en,
        cartons: it.cartons,
        qty_per_carton: it.qty_per_carton,
        quantity: it.quantity,
        unit: it.unit,
        declared_cbm: it.declared_cbm,
        declared_weight: it.declared_weight,
        item_length: it.item_length,
        item_width: it.item_width,
        item_height: it.item_height,
        unit_price: it.unit_price,
        total_amount: it.total_amount,
    }));
    try {
        await api("POST", "/order-templates", {
            name: name.trim(),
            items: templateItems,
        });
        showToast("Template saved");
        loadOrderTemplatesDropdown();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function togglePasteCsv() {
    const area = document.getElementById("pasteCsvArea");
    const btn = document.getElementById("togglePasteCsv");
    if (area.classList.contains("d-none")) {
        area.classList.remove("d-none");
        if (btn) btn.textContent = "Hide CSV";
    } else {
        area.classList.add("d-none");
        if (btn) btn.textContent = "Paste CSV";
    }
}

function importOrderItemsFromCsv() {
    const raw = document.getElementById("pasteCsvData")?.value?.trim();
    if (!raw) {
        showToast("Paste CSV data first", "danger");
        return;
    }
    const lines = raw.split(/\r\n|\r|\n/).filter((l) => l.trim());
    if (lines.length === 0) return;
    const firstRow = lines[0].split(",").map((c) => c.trim().toLowerCase());
    const isHeader = firstRow.some((h) =>
        /^(description|qty|cartons|unit_price|cbm|item_no|weight)$/.test(h),
    );
    const dataRows = isHeader ? lines.slice(1) : lines;
    const posMap = {
        description: 0,
        item_no: 1,
        cartons: 2,
        qty_per_carton: 3,
        qty: 4,
        unit_price: 5,
        weight: 6,
        cbm: 7,
    };
    const colMap = {};
    if (isHeader) {
        [
            "description",
            "item_no",
            "cartons",
            "qty_per_carton",
            "qty",
            "unit_price",
            "weight",
            "cbm",
        ].forEach((n) => {
            const i = firstRow.findIndex((h) => h.includes(n) || n.includes(h));
            if (i >= 0) colMap[n] = i;
        });
    }
    const idx = (arr, name) => {
        const i = colMap[name] ?? posMap[name] ?? -1;
        return i >= 0 && arr[i] !== undefined ? String(arr[i]).trim() : "";
    };
    let imported = 0;
    for (const line of dataRows) {
        const row = line.split(",").map((c) => c.trim());
        if (row.length < 2) continue;
        const desc = idx(row, "description") || row[0];
        if (!desc) continue;
        addOrderItem();
        const cards = document.querySelectorAll(
            "#orderItemsBody .order-item-card",
        );
        const card = cards[cards.length - 1];
        if (card) {
            const set = (sel, v) => {
                const el = card.querySelector(sel);
                if (el && v !== "") el.value = v;
            };
            set(".item-desc", desc);
            set(".item-item-no", idx(row, "item_no"));
            set(".item-cartons", idx(row, "cartons"));
            set(".item-qty-per-ctn", idx(row, "qty_per_carton"));
            set(".item-qty", idx(row, "qty"));
            set(".item-unit-price", idx(row, "unit_price"));
            set(".item-weight", idx(row, "weight"));
            set(".item-cbm", idx(row, "cbm"));
            imported++;
        }
    }
    updateOrderTotals();
    document.getElementById("pasteCsvData").value = "";
    document.getElementById("pasteCsvArea").classList.add("d-none");
    const btn = document.getElementById("togglePasteCsv");
    if (btn) btn.textContent = "Paste CSV";
    showToast(`Imported ${imported} item(s)`);
}

function parseOrderItemsCsv() {
    const raw = document.getElementById("pasteCsvData")?.value?.trim();
    if (!raw) return null;
    const lines = raw.split(/\r\n|\r|\n/).filter((l) => l.trim());
    if (lines.length === 0) return null;
    const firstRow = lines[0].split(",").map((c) => c.trim().toLowerCase());
    const isHeader = firstRow.some((h) =>
        /^(description|qty|cartons|unit_price|cbm|item_no|weight)$/.test(h),
    );
    const dataRows = isHeader ? lines.slice(1) : lines;
    const posMap = {
        description: 0,
        item_no: 1,
        cartons: 2,
        qty_per_carton: 3,
        qty: 4,
        unit_price: 5,
        weight: 6,
        cbm: 7,
    };
    const colMap = {};
    if (isHeader) {
        [
            "description",
            "item_no",
            "cartons",
            "qty_per_carton",
            "qty",
            "unit_price",
            "weight",
            "cbm",
        ].forEach((n) => {
            const i = firstRow.findIndex((h) => h.includes(n) || n.includes(h));
            if (i >= 0) colMap[n] = i;
        });
    }
    const idx = (arr, name) => {
        const i = colMap[name] ?? posMap[name] ?? -1;
        return i >= 0 && arr[i] !== undefined ? String(arr[i]).trim() : "";
    };
    const items = [];
    for (const line of dataRows) {
        const row = line.split(",").map((c) => c.trim());
        if (row.length < 2) continue;
        const desc = idx(row, "description") || row[0];
        if (!desc) continue;
        const qty = parseFloat(idx(row, "qty")) || 0;
        const cartons = parseInt(idx(row, "cartons"), 10) || null;
        const qtyPerCtn = parseFloat(idx(row, "qty_per_carton")) || null;
        if (qty <= 0 && (cartons ?? 0) <= 0) continue;
        items.push({
            description_cn: desc,
            description_en: desc,
            item_no: idx(row, "item_no") || null,
            cartons,
            qty_per_carton: qtyPerCtn,
            quantity: qty > 0 ? qty : null,
            unit: "cartons",
            declared_cbm: parseFloat(idx(row, "cbm")) || null,
            declared_weight: parseFloat(idx(row, "weight")) || null,
            unit_price: parseFloat(idx(row, "unit_price")) || null,
            total_amount:
                qty > 0 && parseFloat(idx(row, "unit_price"))
                    ? qty * parseFloat(idx(row, "unit_price"))
                    : null,
        });
    }
    return items;
}

async function saveCsvAsTemplate() {
    const items = parseOrderItemsCsv();
    if (!items || items.length === 0) {
        showToast("Paste CSV data first with at least one row", "danger");
        return;
    }
    const name = prompt("Template name:");
    if (!name?.trim()) return;
    try {
        await api("POST", "/order-templates", {
            name: name.trim(),
            items,
        });
        showToast("Template saved");
        loadOrderTemplatesDropdown();
        document.getElementById("pasteCsvData").value = "";
        document.getElementById("pasteCsvArea").classList.add("d-none");
        const btn = document.getElementById("togglePasteCsv");
        if (btn) btn.textContent = "Paste CSV";
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function addOrderItem() {
    const container = document.getElementById("orderItemsBody");
    const idx = itemIndex++;
    const card = document.createElement("div");
    card.className = "order-item-card";
    card.dataset.idx = idx;
    const selectedSupplier = orderSupplierAc?.getSelected();
    const selectedCustomer = orderCustomerAc?.getSelected();
    const effectiveShipCode = orderEffectiveShippingCode || (selectedCustomer?.default_shipping_code || "").trim();
    const defaultSuppId =
        selectedSupplier?.id || orderSupplierAc?.getSelectedId() || "";
    const defaultSuppName = (
        selectedSupplier?.name ||
        document.getElementById("orderSupplier")?.value?.trim() ||
        ""
    ).replace(/"/g, "&quot;");
    card.innerHTML = `
    <div class="order-item-card-inner">
      <div class="order-item-header d-flex justify-content-between align-items-center py-1">
        <div class="order-item-index">Item #${idx + 1}</div>
        <div class="d-flex gap-1">
          <button type="button" class="btn btn-sm btn-outline-secondary order-item-design d-none" onclick="openOrderItemDesignModal(this.closest('.order-item-card'))" title="Design attachments">Design</button>
          <button type="button" class="btn btn-sm btn-outline-danger order-item-remove" onclick="this.closest('.order-item-card').remove(); updateOrderTotals();" title="Remove">×</button>
        </div>
      </div>
      <div class="row g-2 align-items-start">
        <div class="col-12 col-lg-2">
          <div class="order-item-panel order-item-photo-panel">
            <label class="form-label form-label-sm">Photo</label>
            <div class="item-photos" data-idx="${idx}"></div>
            <input type="file" class="form-control d-none item-photo-input" accept="image/*" multiple data-idx="${idx}">
            <button type="button" class="btn btn-outline-secondary btn-sm w-100 item-photo-btn" onclick="document.querySelector('.item-photo-input[data-idx=\\'${idx}\\']').click()">+ Add</button>
          </div>
        </div>
        <div class="col-12 col-lg-10">
          <div class="row g-2">
            <div class="col-6 col-md-3">
              <label class="form-label form-label-sm">Supplier</label>
              <input type="text" class="form-control form-control-sm item-supplier" placeholder="Supplier..." data-idx="${idx}">
              <input type="hidden" class="item-supplier-id" data-idx="${idx}" value="${defaultSuppId}">
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label form-label-sm">Item No</label>
              <input type="text" class="form-control form-control-sm item-item-no" placeholder="Auto" data-idx="${idx}">
              <input type="hidden" class="item-shipping-code" value="${escapeHtml(effectiveShipCode || "")}">
            </div>
            <div class="col-12 col-md-7">
              <label class="form-label form-label-sm">Description</label>
              <input type="text" class="form-control form-control-sm item-desc" placeholder="Product description" data-idx="${idx}">
              <input type="hidden" class="item-product-id" data-idx="${idx}">
              <small class="product-suggest small text-muted" data-idx="${idx}"></small>
              <div class="product-alert-slot"></div>
            </div>
            <div class="col-12">
              <div class="order-item-packaging-rows" data-idx="${idx}">
                <div class="order-item-packaging-row">
                  <div class="order-item-subgrid">
                    <div class="order-item-subgrid-block">
                      <div class="order-item-subgrid-title">Packaging</div>
                      <div class="row g-2">
                        <div class="col-4">
                          <label class="form-label order-item-label">Cartons</label>
                          <input type="number" class="form-control form-control-sm item-cartons" min="0" placeholder="0" data-idx="${idx}">
                        </div>
                        <div class="col-4">
                          <label class="form-label order-item-label">Qty/Carton</label>
                          <input type="number" step="0.0001" class="form-control form-control-sm item-qty-per-ctn" min="0" placeholder="0" data-idx="${idx}">
                        </div>
                        <div class="col-4">
                          <label class="form-label order-item-label">Total Qty</label>
                          <input type="number" step="0.0001" class="form-control form-control-sm item-qty" min="0" placeholder="0" data-idx="${idx}">
                        </div>
                      </div>
                    </div>
                    <div class="order-item-subgrid-block">
                      <div class="order-item-subgrid-title">Pricing & weight</div>
                      <div class="row g-2">
                        <div class="col-3">
                          <label class="form-label order-item-label">Unit Price</label>
                          <input type="number" step="0.01" class="form-control form-control-sm item-unit-price" placeholder="0" data-idx="${idx}">
                        </div>
                        <div class="col-3">
                          <label class="form-label order-item-label">Sell price</label>
                          <input type="number" step="0.01" class="form-control form-control-sm item-sell-price" placeholder="Export" data-idx="${idx}" title="Customer-facing; falls back to unit price">
                        </div>
                        <div class="col-3">
                          <label class="form-label order-item-label">Weight / Qty (kg)</label>
                          <input type="number" step="0.0001" class="form-control form-control-sm item-weight" min="0" placeholder="0" data-idx="${idx}">
                        </div>
                        <div class="col-3">
                          <label class="form-label order-item-label">Total $</label>
                          <div class="order-item-computed order-item-computed-sm item-total-amount" data-idx="${idx}">0</div>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div class="order-item-volume-panel mt-2">
                    <div class="order-item-subgrid-title">Volume</div>
                    <div class="order-item-volume-fields">
                      <input type="number" step="0.000001" class="form-control form-control-sm item-cbm" min="0" placeholder="CBM" data-idx="${idx}">
                      <span class="order-item-or">or</span>
                      <input type="number" step="0.01" class="form-control form-control-sm item-l" placeholder="L" data-idx="${idx}">
                      <input type="number" step="0.01" class="form-control form-control-sm item-w" placeholder="W" data-idx="${idx}">
                      <input type="number" step="0.01" class="form-control form-control-sm item-h" placeholder="H" data-idx="${idx}">
                    </div>
                  </div>
                </div>
              </div>
              <button type="button" class="btn btn-outline-secondary btn-sm mt-2 order-item-add-packaging" data-idx="${idx}" title="Add another packaging variant (e.g. different carton sizes)">+ Add packaging row</button>
            </div>
            <div class="col-12">
              <div class="order-item-stats" data-idx="${idx}">
                <div class="order-item-stat">
                  <span class="order-item-stat-label">Total CBM</span>
                  <div class="order-item-computed item-total-cbm" data-idx="${idx}">0</div>
                </div>
                <div class="order-item-stat">
                  <span class="order-item-stat-label">Total GW</span>
                  <div class="order-item-computed item-total-gw" data-idx="${idx}">0</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>`;
    container.appendChild(card);
    const itemNoInp = card.querySelector(".item-item-no");
    const customerId = orderCustomerAc?.getSelectedId?.();
    if (effectiveShipCode && customerId && itemNoInp) {
        fetchNextItemNo(customerId, effectiveShipCode).then((next) => {
            if (itemNoInp && !itemNoInp.value?.trim()) {
                itemNoInp.value = effectiveShipCode + "-" + next;
            }
        });
    }
    card.querySelector(".order-item-add-packaging")?.addEventListener("click", () => {
        addItemPackagingRow(card);
    });
    const suppInput = card.querySelector(".item-supplier");
    const suppIdInput = card.querySelector(".item-supplier-id");
    if (defaultSuppName) suppInput.value = defaultSuppName;
    if (typeof Autocomplete !== "undefined" && suppInput) {
        const ac = Autocomplete.init(suppInput, {
            resource: "suppliers",
            placeholder: "Type supplier...",
            onSelect: (item) => {
                if (suppIdInput) suppIdInput.value = item.id;
            },
        });
        suppInput.addEventListener("input", () => {
            if (suppIdInput) suppIdInput.value = "";
        });
        if (ac && defaultSuppId && defaultSuppName)
            ac.setValue({ id: defaultSuppId, name: defaultSuppName });
        card._supplierAc = ac;
    }
    const descInput = card.querySelector(".item-desc");
    const productIdInput = card.querySelector(".item-product-id");
    if (typeof Autocomplete !== "undefined" && descInput) {
        Autocomplete.init(descInput, {
            resource: "products",
            searchPath: "/search",
            placeholder: "Product description — type to search products",
            renderItem: (p) =>
                `${p.description_cn || p.description_en || ""}${p.high_alert_note || p.required_design ? " — Alert" : ""} — ${p.hs_code || ""}`
                    .replace(/^ — | — $/g, "")
                    .trim() || `#${p.id}`,
            onSelect: (p) => {
                if (productIdInput) productIdInput.value = p.id || "";
                card.dataset.dimensionsScope = (p.dimensions_scope || "piece")
                    .toString()
                    .toLowerCase();
                const desc = (
                    p.description_cn ||
                    p.description_en ||
                    ""
                ).trim();
                if (desc) descInput.value = desc;
                if (p.supplier_id && p.supplier_name) {
                    setItemSupplierValue(card, p.supplier_id, p.supplier_name);
                }
                const qtyCtn = card.querySelector(".item-qty-per-ctn");
                if (qtyCtn && p.pieces_per_carton != null)
                    qtyCtn.value = p.pieces_per_carton;
                const unitPrice = card.querySelector(".item-unit-price");
                if (unitPrice && p.unit_price != null)
                    unitPrice.value = p.unit_price;
                const sellPriceEl = card.querySelector(".item-sell-price");
                if (
                    sellPriceEl &&
                    (p.sell_price != null || p.unit_price != null)
                )
                    sellPriceEl.value = p.sell_price ?? p.unit_price ?? "";
                const weight = card.querySelector(".item-weight");
                if (weight && p.weight != null) weight.value = p.weight;
                const cbm = card.querySelector(".item-cbm");
                if (cbm && p.cbm != null)
                    cbm.value = parseFloat(p.cbm).toFixed(6);
                const lEl = card.querySelector(".item-l");
                const wEl = card.querySelector(".item-w");
                const hEl = card.querySelector(".item-h");
                if (lEl && p.length_cm != null) lEl.value = p.length_cm;
                if (wEl && p.width_cm != null) wEl.value = p.width_cm;
                if (hEl && p.height_cm != null) hEl.value = p.height_cm;
                const suggest = card.querySelector(".product-suggest");
                if (suggest)
                    suggest.textContent = p.hs_code
                        ? `From product (HS: ${p.hs_code}) — review and confirm values above.`
                        : "From product — review and confirm values above.";
                renderProductAlertHint(card, productAlertTextFromItem(p));
                const photosContainer = card.querySelector(".item-photos");
                if (photosContainer) {
                    photosContainer
                        .querySelectorAll("[data-from-product]")
                        .forEach((el) => el.remove());
                    let paths = [];
                    if (Array.isArray(p.image_paths)) paths = p.image_paths;
                    else if (
                        typeof p.image_paths === "string" &&
                        p.image_paths
                    ) {
                        try {
                            paths = JSON.parse(p.image_paths);
                        } catch (_) {
                            paths = [];
                        }
                    }
                    const firstPath = paths[0];
                    if (firstPath) {
                        const wrap = document.createElement("div");
                        wrap.className =
                            "d-inline-block me-1 mb-1 border border-success rounded p-1";
                        wrap.style.background = "#e8f5e9";
                        wrap.dataset.path = firstPath;
                        wrap.dataset.fromProduct = "1";
                        wrap.innerHTML = `<small class="text-success fw-semibold d-block mb-1">From product</small><img src="/cargochina/backend/${firstPath}" class="img-thumbnail img-thumbnail-sm" style="max-width:50px" alt=""><button type="button" class="btn-close btn-close-sm" onclick="this.closest('[data-from-product]').remove()"></button>`;
                        photosContainer.insertBefore(
                            wrap,
                            photosContainer.firstChild,
                        );
                    }
                }
                updateItemComputed(idx);
                updateOrderTotals();
            },
        });
    }
    descInput?.addEventListener("input", () => {
        if (productIdInput) productIdInput.value = "";
        delete card.dataset.dimensionsScope;
        renderProductAlertHint(card, "");
    });
    card.querySelector(".item-photo-input").addEventListener("change", (e) =>
        handleItemPhoto(e, idx),
    );
    [
        "item-cartons",
        "item-qty-per-ctn",
        "item-unit-price",
        "item-sell-price",
        "item-qty",
        "item-cbm",
        "item-weight",
    ].forEach((cls) => {
        const el = card.querySelector(`.${cls}`);
        if (el)
            el.addEventListener("input", () => {
                updateItemComputed(idx);
                updateOrderTotals();
            });
    });
    ["item-l", "item-w", "item-h"].forEach((cls) => {
        const el = card.querySelector(`.${cls}`);
        if (el) {
            el.addEventListener("input", () => {
                const l = parseFloat(card.querySelector(".item-l")?.value) || 0;
                const w = parseFloat(card.querySelector(".item-w")?.value) || 0;
                const h = parseFloat(card.querySelector(".item-h")?.value) || 0;
                if (l > 0 && w > 0 && h > 0) {
                    const cbm = roundCbm6((l * w * h) / 1000000);
                    card.querySelector(".item-cbm").value = cbm.toFixed(6);
                }
                updateItemComputed(idx);
                updateOrderTotals();
            });
        }
    });
    card.querySelector(".item-cbm")?.addEventListener("input", () => {
        if (parseFloat(card.querySelector(".item-cbm")?.value || 0) > 0) {
            card.querySelector(".item-l").value =
                card.querySelector(".item-w").value =
                card.querySelector(".item-h").value =
                    "";
        }
    });
    updateOrderTotals();
}

async function handleItemPhoto(e, idx) {
    const files = e.target.files;
    if (!files || !files.length) return;
    const container = document.querySelector(`.item-photos[data-idx="${idx}"]`);
    for (let i = 0; i < files.length; i++) {
        try {
            const path = await uploadFile(files[i]);
            if (path) {
                const div = document.createElement("div");
                div.className = "d-inline-block me-1 mb-1";
                div.dataset.path = path;
                div.innerHTML = `<img src="/cargochina/backend/${path}" class="img-thumbnail img-thumbnail-sm" style="max-width:50px" alt=""><button type="button" class="btn-close btn-close-sm" onclick="this.closest('.d-inline-block').remove()"></button>`;
                container.appendChild(div);
            }
        } catch (err) {
            showToast(err.message, "danger");
        }
    }
    e.target.value = "";
}

function buildPackagingRowHtml(idx, isSubrow) {
    const subClass = isSubrow ? " order-item-packaging-subrow" : "";
    const removeBtn = isSubrow
        ? '<div class="order-item-packaging-row-actions"><button type="button" class="btn btn-sm btn-outline-danger order-item-remove-packaging" title="Remove this row">×</button></div>'
        : "";
    return `
    <div class="order-item-packaging-row${subClass}">
      ${removeBtn}
      <div class="order-item-subgrid">
        <div class="order-item-subgrid-block">
          <div class="order-item-subgrid-title">Packaging</div>
          <div class="row g-2">
            <div class="col-4">
              <label class="form-label order-item-label">Cartons</label>
              <input type="number" class="form-control form-control-sm item-cartons" min="0" placeholder="0" data-idx="${idx}">
            </div>
            <div class="col-4">
              <label class="form-label order-item-label">Qty/Carton</label>
              <input type="number" step="0.0001" class="form-control form-control-sm item-qty-per-ctn" min="0" placeholder="0" data-idx="${idx}">
            </div>
            <div class="col-4">
              <label class="form-label order-item-label">Total Qty</label>
              <input type="number" step="0.0001" class="form-control form-control-sm item-qty" min="0" placeholder="0" data-idx="${idx}">
            </div>
          </div>
        </div>
        <div class="order-item-subgrid-block">
          <div class="order-item-subgrid-title">Pricing & weight</div>
          <div class="row g-2">
            <div class="col-3">
              <label class="form-label order-item-label">Unit Price</label>
              <input type="number" step="0.01" class="form-control form-control-sm item-unit-price" placeholder="0" data-idx="${idx}">
            </div>
            <div class="col-3">
              <label class="form-label order-item-label">Sell price</label>
              <input type="number" step="0.01" class="form-control form-control-sm item-sell-price" placeholder="Export" data-idx="${idx}">
            </div>
            <div class="col-3">
              <label class="form-label order-item-label">Weight / Qty (kg)</label>
              <input type="number" step="0.0001" class="form-control form-control-sm item-weight" min="0" placeholder="0" data-idx="${idx}">
            </div>
            <div class="col-3">
              <label class="form-label order-item-label">Total $</label>
              <div class="order-item-computed order-item-computed-sm item-total-amount" data-idx="${idx}">0</div>
            </div>
          </div>
        </div>
      </div>
      <div class="order-item-volume-panel mt-2">
        <div class="order-item-subgrid-title">Volume</div>
        <div class="order-item-volume-fields">
          <input type="number" step="0.000001" class="form-control form-control-sm item-cbm" min="0" placeholder="CBM" data-idx="${idx}">
          <span class="order-item-or">or</span>
          <input type="number" step="0.01" class="form-control form-control-sm item-l" placeholder="L" data-idx="${idx}">
          <input type="number" step="0.01" class="form-control form-control-sm item-w" placeholder="W" data-idx="${idx}">
          <input type="number" step="0.01" class="form-control form-control-sm item-h" placeholder="H" data-idx="${idx}">
        </div>
      </div>
    </div>`;
}

window.addItemPackagingRow = function (card) {
    const idx = card.dataset.idx;
    if (!idx) return;
    const container = card.querySelector(".order-item-packaging-rows");
    if (!container) return;
    const wrap = document.createElement("div");
    wrap.innerHTML = buildPackagingRowHtml(idx, true).trim();
    const row = wrap.firstElementChild;
    if (!row) return;
    const removeBtn = row.querySelector(".order-item-remove-packaging");
    if (removeBtn) {
        removeBtn.addEventListener("click", () => {
            row.remove();
            updateItemComputed(idx);
            updateOrderTotals();
        });
    }
    container.appendChild(row);
    bindPackagingRowInputs(card, row, idx);
    updateItemComputed(idx);
    updateOrderTotals();
};

function bindPackagingRowInputs(card, row, idx) {
    const inputs = [
        "item-cartons", "item-qty-per-ctn", "item-qty", "item-unit-price",
        "item-sell-price", "item-weight", "item-cbm", "item-l", "item-w", "item-h",
    ];
    inputs.forEach((cls) => {
        const el = row.querySelector(`.${cls}`);
        if (el && el.tagName === "INPUT")
            el.addEventListener("input", () => {
                updateItemComputed(idx);
                updateOrderTotals();
            });
    });
}

function updateItemComputed(idx) {
    const tr = document.querySelector(`.order-item-card[data-idx="${idx}"]`);
    if (!tr) return;
    const rows = tr.querySelectorAll(".order-item-packaging-row");
    let sumAmount = 0;
    let sumCbm = 0;
    let sumGw = 0;
    const scope = (tr.dataset.dimensionsScope || "piece").toLowerCase();
    rows.forEach((row) => {
        const cartons = parseInt(row.querySelector(".item-cartons")?.value || 0, 10);
        const qtyPerCtn = parseFloat(row.querySelector(".item-qty-per-ctn")?.value || 0);
        const unitPrice = parseFloat(row.querySelector(".item-unit-price")?.value || 0);
        const sellPriceRaw = row.querySelector(".item-sell-price")?.value?.trim();
        const sellPrice = sellPriceRaw ? parseFloat(sellPriceRaw) : null;
        const priceForTotal = sellPrice != null && !isNaN(sellPrice) ? sellPrice : unitPrice;
        const totalQty =
            cartons > 0 && qtyPerCtn > 0
                ? cartons * qtyPerCtn
                : parseFloat(row.querySelector(".item-qty")?.value || 0);
        const qtyInput = row.querySelector(".item-qty");
        if (cartons > 0 && qtyPerCtn > 0 && qtyInput) qtyInput.value = totalQty;
        const rowAmount = totalQty > 0 && priceForTotal > 0 ? totalQty * priceForTotal : 0;
        sumAmount += rowAmount;
        const amtEl = row.querySelector(".item-total-amount");
        if (amtEl) amtEl.textContent = rowAmount > 0 ? rowAmount.toFixed(2) : "0";

        let cbmPerUnit = parseFloat(row.querySelector(".item-cbm")?.value || 0);
        const l = parseFloat(row.querySelector(".item-l")?.value) || 0;
        const w = parseFloat(row.querySelector(".item-w")?.value) || 0;
        const h = parseFloat(row.querySelector(".item-h")?.value) || 0;
        if (cbmPerUnit <= 0 && l > 0 && w > 0 && h > 0) {
            cbmPerUnit = roundCbm6((l * w * h) / 1000000);
        }
        const scopeMultiplier =
            scope === "carton"
                ? cartons > 0 ? cartons : 0
                : totalQty > 0 ? totalQty : 0;
        const rowCbm = roundCbm6(cbmPerUnit * scopeMultiplier);
        sumCbm += rowCbm;

        const weightPc = parseFloat(row.querySelector(".item-weight")?.value || 0);
        const rowGw = weightPc * scopeMultiplier;
        sumGw += rowGw;
    });
    tr.querySelector(".item-total-cbm").textContent = sumCbm.toFixed(6);
    tr.querySelector(".item-total-gw").textContent = sumGw.toFixed(0);
    updateOrderTotals();
}

function updateOrderTotals() {
    let totalAmount = 0,
        totalCbm = 0,
        totalWeight = 0;
    document
        .querySelectorAll("#orderItemsBody .order-item-card[data-idx]")
        .forEach((tr) => {
            tr.querySelectorAll(".item-total-amount").forEach((el) => {
                totalAmount += parseFloat(el.textContent || 0);
            });
            totalCbm += parseFloat(
                tr.querySelector(".item-total-cbm")?.textContent || 0,
            );
            totalWeight += parseFloat(
                tr.querySelector(".item-total-gw")?.textContent || 0,
            );
        });
    const cur = document.getElementById("orderCurrency")?.value || "USD";
    const sym = cur === "RMB" ? "¥" : "$";
    const elAmount = document.getElementById("orderTotalAmount");
    const elCbm = document.getElementById("orderTotalCbm");
    const elWeight = document.getElementById("orderTotalWeight");
    if (elAmount) elAmount.textContent = sym + totalAmount.toFixed(2);
    if (elCbm) elCbm.textContent = totalCbm.toFixed(6);
    if (elWeight) elWeight.textContent = totalWeight.toFixed(0);
}

let _orderItemDesignItemId = null;

function openOrderItemDesignModal(card) {
    const itemId = card?.dataset?.itemId;
    if (!itemId) {
        showToast(
            "Save order first to add design attachments for this item",
            "warning",
        );
        return;
    }
    _orderItemDesignItemId = parseInt(itemId, 10);
    const desc = card.querySelector(".item-desc")?.value || "";
    document.getElementById("orderItemDesignLabel").textContent = desc
        ? escapeHtml(desc).substring(0, 30)
        : "#" + itemId;
    loadOrderItemDesignAttachments();
    document.getElementById("orderItemDesignInput").value = "";
    document.getElementById("orderItemDesignInput").onchange =
        handleOrderItemDesignUpload;
    new bootstrap.Modal(document.getElementById("orderItemDesignModal")).show();
}

async function loadOrderItemDesignAttachments() {
    if (!_orderItemDesignItemId) return;
    try {
        const res = await api(
            "GET",
            "/design-attachments?entity_type=order_item&entity_id=" +
                _orderItemDesignItemId,
        );
        renderOrderItemDesignAttachments(res.data || []);
    } catch (e) {
        renderOrderItemDesignAttachments([]);
    }
}

function renderOrderItemDesignAttachments(list) {
    const el = document.getElementById("orderItemDesignList");
    if (!el) return;
    if (!list.length) {
        el.innerHTML =
            '<p class="text-muted small mb-0">No design attachments</p>';
        return;
    }
    const base = (window.API_BASE || "/cargochina/api/v1").replace(
        "/api/v1",
        "",
    );
    el.innerHTML = list
        .map(
            (a) => `
        <div class="d-flex align-items-center gap-2 mb-1">
          <a href="${base}/backend/${a.file_path}" target="_blank" class="small text-truncate" style="max-width:200px">${escapeHtml((a.internal_note || a.file_path || "Attachment").substring(0, 40))}</a>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteOrderItemDesignAttachment(${a.id})">×</button>
        </div>`,
        )
        .join("");
}

async function handleOrderItemDesignUpload(e) {
    const file = e.target?.files?.[0];
    e.target.value = "";
    if (!file || !_orderItemDesignItemId) return;
    try {
        const path = await uploadFile(file);
        if (!path) return;
        await api("POST", "/design-attachments", {
            entity_type: "order_item",
            entity_id: _orderItemDesignItemId,
            file_path: path,
            file_type: (file.name || "").split(".").pop() || null,
        });
        loadOrderItemDesignAttachments();
        showToast("Design attachment added");
    } catch (err) {
        showToast(err.message, "danger");
    }
}

window.deleteOrderItemDesignAttachment = async function (attachmentId) {
    if (!_orderItemDesignItemId) return;
    try {
        await api("DELETE", "/design-attachments/" + attachmentId);
        loadOrderItemDesignAttachments();
        showToast("Attachment removed");
    } catch (e) {
        showToast(e.message, "danger");
    }
};

async function copyOrder(id) {
    try {
        const res = await api("GET", "/orders/" + id);
        const o = res.data;
        document.getElementById("orderId").value = "";
        orderCustomerAc?.setValue({
            id: o.customer_id,
            name: o.customer_name,
            code: "",
        });
        orderSupplierAc?.setValue({
            id: o.supplier_id,
            name: o.supplier_name,
            code: "",
        });
        document.getElementById("orderExpectedDate").value =
            o.expected_ready_date || "";
        document.getElementById("orderCurrency").value = o.currency || "USD";
        document.getElementById("orderHighAlertNotes").value =
            o.high_alert_notes || "";
        const destId = o.destination_country_id;
        const destName = o.destination_country_name;
        const destCode = o.destination_country_code;
        document.getElementById("orderDestinationCountryId").value = destId || "";
        if (destId && destName) {
            if (orderDestinationCountryAc) {
                orderDestinationCountryAc.setValue({ id: destId, name: destName, code: destCode });
            }
            const destInp = document.getElementById("orderDestinationCountry");
            if (destInp) destInp.value = (destName || "") + " (" + (destCode || "") + ")";
        }
        let custRes;
        try {
            custRes = await api("GET", "/customers/" + o.customer_id);
            orderCustomerCountryShipping = custRes.data?.country_shipping || [];
            if (orderCustomerCountryShipping.length > 1) {
                renderOrderDestinationSelect();
                showOrderDestinationSelect(true);
                const sel = document.getElementById("orderDestinationCountrySelect");
                if (sel && destId) sel.value = destId;
            } else {
                showOrderDestinationSelect(false);
            }
        } catch (_) {
            showOrderDestinationSelect(false);
        }
        let shipCode = custRes?.data?.default_shipping_code || "";
        if (destId && orderCustomerCountryShipping?.length > 0) {
            const c = orderCustomerCountryShipping.find((x) => String(x.country_id) === String(destId));
            if (c) shipCode = c.shipping_code || shipCode;
        }
        if (!shipCode && o.items?.[0]?.shipping_code) shipCode = o.items[0].shipping_code;
        applyCustomerDefaultShippingCode(shipCode);
        document.getElementById("orderModalTitle").textContent =
            "Copy of Order #" + id;
        const container = document.getElementById("orderItemsBody");
        resetOrderItems();
        const copyKey = (it) => `${it.product_id || ""}|${it.supplier_id || ""}|${(it.description_cn || it.description_en || "").trim().substring(0, 50)}`;
        let copyLastCard = null;
        let copyLastKey = null;
        (o.items || []).forEach((it) => {
            const k = copyKey(it);
            const denom = getItemPerUnitDenom(it);
            const cbmVal = denom > 0 ? roundCbm6((it.declared_cbm || 0) / denom).toFixed(6) : (it.declared_cbm ?? "");
            const rowData = {
                cartons: it.cartons ?? "", qty_per_carton: it.qty_per_carton ?? "", quantity: it.quantity ?? "",
                unit_price: it.unit_price ?? "", sell_price: it.sell_price ?? "",
                cbm: cbmVal, l: it.item_length ?? "", w: it.item_width ?? "", h: it.item_height ?? "",
                weight: getItemWeightPerQty(it),
            };
            if (copyLastCard && k === copyLastKey) {
                addItemPackagingRow(copyLastCard);
                const rows = copyLastCard.querySelectorAll(".order-item-packaging-row");
                const row = rows[rows.length - 1];
                if (row) {
                    row.querySelector(".item-cartons").value = rowData.cartons;
                    row.querySelector(".item-qty-per-ctn").value = rowData.qty_per_carton;
                    row.querySelector(".item-qty").value = rowData.quantity;
                    row.querySelector(".item-unit-price").value = rowData.unit_price;
                    const sp = row.querySelector(".item-sell-price");
                    if (sp) sp.value = rowData.sell_price ?? "";
                    row.querySelector(".item-cbm").value = rowData.cbm;
                    row.querySelector(".item-l").value = rowData.l;
                    row.querySelector(".item-w").value = rowData.w;
                    row.querySelector(".item-h").value = rowData.h;
                    row.querySelector(".item-weight").value = rowData.weight;
                }
            } else {
                addOrderItem();
                copyLastCard = container.lastElementChild;
                if (!copyLastCard) return;
                copyLastKey = k;
                if (it.supplier_id) setItemSupplierValue(copyLastCard, it.supplier_id, it.supplier_name);
                copyLastCard.querySelector(".item-desc").value = (it.description_cn || it.description_en || "").substring(0, 100);
                copyLastCard.querySelector(".item-product-id").value = it.product_id || "";
                copyLastCard.querySelector(".item-item-no").value = it.item_no || "";
                copyLastCard.querySelector(".item-shipping-code").value = it.shipping_code || "";
                const firstRow = copyLastCard.querySelector(".order-item-packaging-row");
                if (firstRow) {
                    firstRow.querySelector(".item-cartons").value = rowData.cartons;
                    firstRow.querySelector(".item-qty-per-ctn").value = rowData.qty_per_carton;
                    firstRow.querySelector(".item-qty").value = rowData.quantity;
                    firstRow.querySelector(".item-unit-price").value = rowData.unit_price;
                    const sp = firstRow.querySelector(".item-sell-price");
                    if (sp) sp.value = rowData.sell_price ?? "";
                    firstRow.querySelector(".item-cbm").value = rowData.cbm;
                    firstRow.querySelector(".item-l").value = rowData.l;
                    firstRow.querySelector(".item-w").value = rowData.w;
                    firstRow.querySelector(".item-h").value = rowData.h;
                    firstRow.querySelector(".item-weight").value = rowData.weight;
                }
                copyLastCard.dataset.dimensionsScope = (it.product_dimensions_scope || it.dimensions_scope || "piece").toString().toLowerCase();
                renderProductAlertHint(copyLastCard, productAlertTextFromItem(it));
                (it.image_paths || []).forEach((path) => {
                    const div = document.createElement("div");
                    div.className = "d-inline-block me-1 mb-1";
                    div.dataset.path = path;
                    div.innerHTML = `<img src="/cargochina/backend/${path}" class="img-thumbnail img-thumbnail-sm" style="max-width:50px" alt=""><button type="button" class="btn-close btn-close-sm" onclick="this.closest('.d-inline-block').remove()"></button>`;
                    copyLastCard.querySelector(".item-photos").appendChild(div);
                });
            }
            updateItemComputed(copyLastCard?.dataset?.idx);
        });
        if (!o.items || o.items.length === 0) addOrderItem();
        updateOrderTotals();
        new bootstrap.Modal(document.getElementById("orderModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function editOrder(id) {
    try {
        const res = await api("GET", "/orders/" + id);
        const o = res.data;
        if (o.order_type === "draft_procurement") {
            window.location.href =
                `/cargochina/procurement_drafts.php?order_id=${id}`;
            return;
        }
        document.getElementById("orderId").value = o.id;
        orderCustomerAc?.setValue({
            id: o.customer_id,
            name: o.customer_name,
            code: "",
            default_shipping_code: "",
        });
        orderSupplierAc?.setValue({
            id: o.supplier_id,
            name: o.supplier_name,
            code: "",
        });
        document.getElementById("orderExpectedDate").value =
            o.expected_ready_date || "";
        document.getElementById("orderCurrency").value = o.currency || "USD";
        document.getElementById("orderHighAlertNotes").value =
            o.high_alert_notes || "";
        const destId = o.destination_country_id;
        const destName = o.destination_country_name;
        const destCode = o.destination_country_code;
        document.getElementById("orderDestinationCountryId").value = destId || "";
        if (destId && destName) {
            if (orderDestinationCountryAc) {
                orderDestinationCountryAc.setValue({ id: destId, name: destName, code: destCode });
            }
            const destInp = document.getElementById("orderDestinationCountry");
            if (destInp) destInp.value = (destName || "") + " (" + (destCode || "") + ")";
        }
        let custRes;
        try {
            custRes = await api("GET", "/customers/" + o.customer_id);
            orderCustomerCountryShipping = custRes.data?.country_shipping || [];
            if (orderCustomerCountryShipping.length > 1) {
                renderOrderDestinationSelect();
                showOrderDestinationSelect(true);
                const sel = document.getElementById("orderDestinationCountrySelect");
                if (sel && destId) sel.value = destId;
            } else {
                showOrderDestinationSelect(false);
            }
        } catch (_) {
            showOrderDestinationSelect(false);
        }
        let shipCode = custRes?.data?.default_shipping_code || "";
        if (destId && orderCustomerCountryShipping?.length > 0) {
            const c = orderCustomerCountryShipping.find((x) => String(x.country_id) === String(destId));
            if (c) shipCode = c.shipping_code || shipCode;
        }
        if (!shipCode && o.items?.[0]?.shipping_code) shipCode = o.items[0].shipping_code;
        applyCustomerDefaultShippingCode(shipCode);
        document.getElementById("orderModalTitle").textContent =
            "Edit Order #" + o.id;
        const container = document.getElementById("orderItemsBody");
        resetOrderItems();
        const itemKey = (it) => `${it.product_id || ""}|${it.supplier_id || ""}|${(it.description_cn || it.description_en || "").trim().substring(0, 50)}`;
        let lastCard = null;
        let lastKey = null;
        (o.items || []).forEach((it) => {
            const k = itemKey(it);
            const denom = getItemPerUnitDenom(it);
            const cbmVal = denom > 0 ? roundCbm6((it.declared_cbm || 0) / denom).toFixed(6) : (it.declared_cbm ?? "");
            const rowData = {
                cartons: it.cartons ?? "", qty_per_carton: it.qty_per_carton ?? "", quantity: it.quantity ?? "",
                unit_price: it.unit_price ?? "", sell_price: it.sell_price ?? "",
                cbm: cbmVal, l: it.item_length ?? "", w: it.item_width ?? "", h: it.item_height ?? "",
                weight: getItemWeightPerQty(it),
            };
            if (lastCard && k === lastKey) {
                addItemPackagingRow(lastCard);
                const rows = lastCard.querySelectorAll(".order-item-packaging-row");
                const row = rows[rows.length - 1];
                if (row) {
                    row.querySelector(".item-cartons").value = rowData.cartons;
                    row.querySelector(".item-qty-per-ctn").value = rowData.qty_per_carton;
                    row.querySelector(".item-qty").value = rowData.quantity;
                    row.querySelector(".item-unit-price").value = rowData.unit_price;
                    const sp = row.querySelector(".item-sell-price");
                    if (sp) sp.value = rowData.sell_price ?? "";
                    row.querySelector(".item-cbm").value = rowData.cbm;
                    row.querySelector(".item-l").value = rowData.l;
                    row.querySelector(".item-w").value = rowData.w;
                    row.querySelector(".item-h").value = rowData.h;
                    row.querySelector(".item-weight").value = rowData.weight;
                }
            } else {
                addOrderItem();
                lastCard = container.lastElementChild;
                if (!lastCard) return;
                lastKey = k;
                if (it.supplier_id) setItemSupplierValue(lastCard, it.supplier_id, it.supplier_name);
                lastCard.querySelector(".item-desc").value = (it.description_cn || it.description_en || "").substring(0, 100);
                lastCard.querySelector(".item-product-id").value = it.product_id || "";
                lastCard.querySelector(".item-item-no").value = it.item_no || "";
                lastCard.querySelector(".item-shipping-code").value = it.shipping_code || "";
                const firstRow = lastCard.querySelector(".order-item-packaging-row");
                if (firstRow) {
                    firstRow.querySelector(".item-cartons").value = rowData.cartons;
                    firstRow.querySelector(".item-qty-per-ctn").value = rowData.qty_per_carton;
                    firstRow.querySelector(".item-qty").value = rowData.quantity;
                    firstRow.querySelector(".item-unit-price").value = rowData.unit_price;
                    const sp = firstRow.querySelector(".item-sell-price");
                    if (sp) sp.value = rowData.sell_price ?? "";
                    firstRow.querySelector(".item-cbm").value = rowData.cbm;
                    firstRow.querySelector(".item-l").value = rowData.l;
                    firstRow.querySelector(".item-w").value = rowData.w;
                    firstRow.querySelector(".item-h").value = rowData.h;
                    firstRow.querySelector(".item-weight").value = rowData.weight;
                }
                lastCard.dataset.dimensionsScope = (it.product_dimensions_scope || it.dimensions_scope || "piece").toString().toLowerCase();
                renderProductAlertHint(lastCard, productAlertTextFromItem(it));
                (it.image_paths || []).forEach((path) => {
                    const div = document.createElement("div");
                    div.className = "d-inline-block me-1 mb-1";
                    div.dataset.path = path;
                    div.innerHTML = `<img src="/cargochina/backend/${path}" class="img-thumbnail img-thumbnail-sm" style="max-width:50px" alt=""><button type="button" class="btn-close btn-close-sm" onclick="this.closest('.d-inline-block').remove()"></button>`;
                    lastCard.querySelector(".item-photos").appendChild(div);
                });
                if (it.id) {
                    lastCard.dataset.itemId = it.id;
                    const designBtn = lastCard.querySelector(".order-item-design");
                    if (designBtn) designBtn.classList.remove("d-none");
                }
            }
            updateItemComputed(lastCard?.dataset?.idx);
        });
        if (o.items && o.items.length === 0) addOrderItem();
        updateOrderTotals();
        new bootstrap.Modal(document.getElementById("orderModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function collectOrderItems() {
    const items = [];
    let hasError = false;
    document
        .querySelectorAll("#orderItemsBody .order-item-card")
        .forEach((tr) => {
            const desc = tr.querySelector(".item-desc")?.value?.trim();
            const productId = tr.querySelector(".item-product-id")?.value;
            const itemNo = tr.querySelector(".item-item-no")?.value?.trim();
            const shippingCode = tr.querySelector(".item-shipping-code")?.value?.trim() || orderEffectiveShippingCode || "";
            const supplierId = tr.querySelector(".item-supplier-id")?.value?.trim() || null;
            const photoDivs = tr.querySelectorAll(".item-photos [data-path]");
            const imagePaths = Array.from(photoDivs).map((d) => d.dataset.path).filter(Boolean);
            const rows = tr.querySelectorAll(".order-item-packaging-row");
            rows.forEach((row) => {
                const cartons = parseInt(row.querySelector(".item-cartons")?.value || 0, 10);
                const qtyPerCtn = parseFloat(row.querySelector(".item-qty-per-ctn")?.value || 0);
                const qtyInput = parseFloat(row.querySelector(".item-qty")?.value || 0);
                const qty = cartons > 0 && qtyPerCtn > 0 ? cartons * qtyPerCtn : qtyInput;
                if (qty <= 0) return;
                const unit = cartons > 0 ? "cartons" : "pieces";
                const cbmPc = parseFloat(row.querySelector(".item-cbm")?.value || 0);
                const l = parseFloat(row.querySelector(".item-l")?.value) || 0;
                const w = parseFloat(row.querySelector(".item-w")?.value) || 0;
                const h = parseFloat(row.querySelector(".item-h")?.value) || 0;
                const cbmFromLwh = l > 0 && w > 0 && h > 0 ? (l * w * h) / 1000000 : 0;
                if (cbmPc <= 0 && cbmFromLwh <= 0) {
                    showToast("Each packaging row needs CBM or L/W/H (cm)", "danger");
                    hasError = true;
                    return;
                }
                const scope = (tr.dataset.dimensionsScope || "piece").toLowerCase();
                const scopeMultiplier = scope === "carton" ? (cartons > 0 ? cartons : 0) : (qty > 0 ? qty : 0);
                const totalCbm = roundCbm6((cbmPc > 0 ? cbmPc : cbmFromLwh) * scopeMultiplier);
                const weightPc = parseFloat(row.querySelector(".item-weight")?.value || 0);
                const totalGw = weightPc * scopeMultiplier;
                const unitPrice = parseFloat(row.querySelector(".item-unit-price")?.value || 0);
                const sellPriceRaw = row.querySelector(".item-sell-price")?.value?.trim();
                const sellPrice = sellPriceRaw ? parseFloat(sellPriceRaw) : null;
                const priceForTotal = sellPrice != null && !isNaN(sellPrice) ? sellPrice : unitPrice;
                const totalAmountPayload = qty > 0 && priceForTotal > 0 ? qty * priceForTotal : null;
                items.push({
                    product_id: productId || null,
                    supplier_id: supplierId || null,
                    item_no: itemNo || null,
                    shipping_code: shippingCode || null,
                    cartons: cartons || null,
                    qty_per_carton: qtyPerCtn || null,
                    quantity: qty,
                    unit,
                    declared_cbm: totalCbm,
                    declared_weight: totalGw,
                    item_length: l > 0 ? l : null,
                    item_width: w > 0 ? w : null,
                    item_height: h > 0 ? h : null,
                    unit_price: unitPrice || null,
                    sell_price: sellPrice,
                    total_amount: totalAmountPayload,
                    image_paths: imagePaths.length ? imagePaths : null,
                    description_cn: desc || null,
                    description_en: desc || null,
                });
            });
        });
    return hasError ? null : items;
}

async function saveOrder() {
    const id = document.getElementById("orderId").value;
    const items = collectOrderItems();
    if (!items) return;
    const destCountryId = document.getElementById("orderDestinationCountryId")?.value?.trim();
    const payload = {
        customer_id: orderCustomerAc?.getSelectedId() || "",
        supplier_id: orderSupplierAc?.getSelectedId() || "",
        expected_ready_date:
            document.getElementById("orderExpectedDate").value || null,
        currency: document.getElementById("orderCurrency")?.value || "USD",
        high_alert_notes:
            document.getElementById("orderHighAlertNotes")?.value?.trim() ||
            null,
        destination_country_id: destCountryId ? parseInt(destCountryId, 10) : null,
        items,
    };
    if (!payload.customer_id) {
        showToast("Customer is required", "danger");
        return;
    }
    if (payload.items.length === 0) {
        showToast("At least one item is required", "danger");
        return;
    }
    if (!payload.expected_ready_date && !confirmMissingOrderExpectedReadyDate()) {
        return;
    }
    const saveBtn = document.querySelector("#orderModal .btn-primary");
    try {
        setLoading(saveBtn, true);
        let res;
        if (id) {
            res = await api("PUT", "/orders/" + id, payload);
            showToast("Order updated");
        } else {
            res = await api("POST", "/orders", payload);
            showToast("Order created");
        }
        if (res?.warning) showToast(res.warning, "warning");
        bootstrap.Modal.getInstance(
            document.getElementById("orderModal"),
        ).hide();
        loadOrders();
    } catch (e) {
        showToast(e.message || "Request failed", "danger");
    } finally {
        setLoading(saveBtn, false);
    }
}

async function submitOrder(id) {
    try {
        await api("POST", "/orders/" + id + "/submit", {});
        showToast("Order submitted");
        loadOrders();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function approveOrder(id) {
    try {
        await api("POST", "/orders/" + id + "/approve", {});
        showToast("Order approved");
        loadOrders();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function bulkApproveOrders() {
    const checked = Array.from(
        document.querySelectorAll(".order-bulk-cb:checked"),
    ).filter((cb) => cb.dataset.status === "Submitted");
    const ids = checked.map((cb) => Number(cb.dataset.orderId));
    if (ids.length === 0) {
        showToast("Select Submitted orders to approve", "warning");
        return;
    }
    try {
        let ok = 0;
        let err = 0;
        for (const id of ids) {
            try {
                await api("POST", "/orders/" + id + "/approve", {});
                ok++;
            } catch (e) {
                err++;
                console.warn("Approve failed for order " + id, e);
            }
        }
        showToast(
            `Approved ${ok} order(s)` + (err ? `; ${err} failed` : ""),
            err ? "warning" : "success",
        );
        loadOrders();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function bulkSubmitOrders() {
    const checked = Array.from(
        document.querySelectorAll(".order-bulk-cb:checked"),
    ).filter((cb) => cb.dataset.status === "Draft");
    const ids = checked.map((cb) => Number(cb.dataset.orderId));
    if (ids.length === 0) {
        showToast("Select Draft orders to submit", "warning");
        return;
    }
    if (!confirm(`Submit ${ids.length} order(s)?`)) return;
    try {
        let ok = 0;
        let err = 0;
        for (const id of ids) {
            try {
                await api("POST", "/orders/" + id + "/submit", {});
                ok++;
            } catch (e) {
                err++;
                console.warn("Submit failed for order " + id, e);
            }
        }
        showToast(
            `Submitted ${ok} order(s)` + (err ? `; ${err} failed` : ""),
            err ? "warning" : "success",
        );
        loadOrders();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function showOrderFinance(id) {
    const modal = new bootstrap.Modal(document.getElementById("financeModal"));
    document.getElementById("financeOrderId").textContent = "#" + id;
    document.getElementById("financeModalBody").innerHTML =
        '<div class="text-center py-4"><div class="spinner-border spinner-border-sm text-primary"></div> Loading…</div>';
    modal.show();
    try {
        const res = await api("GET", "/orders/" + id);
        const o = res.data || {};
        const items = o.items || [];

        const totalCost = items.reduce(
            (s, it) => s + (parseFloat(it.total_amount) || 0),
            0,
        );
        const totalCbm = items.reduce(
            (s, it) => s + (parseFloat(it.declared_cbm) || 0),
            0,
        );
        const totalWeight = items.reduce(
            (s, it) => s + (parseFloat(it.declared_weight) || 0),
            0,
        );
        const totalCartons = items.reduce(
            (s, it) => s + (parseInt(it.cartons) || 0),
            0,
        );
        const currency = o.currency || "USD";

        const itemRows = items
            .map(
                (it) => `
          <tr>
            <td class="small">${escapeHtml(typeof descText === "function" ? descText(it) : it.description_en || it.description_cn || "—")}</td>
            <td class="small text-muted">${escapeHtml(it.item_no || "—")}</td>
            <td class="text-end small">${it.cartons || "—"}</td>
            <td class="text-end small">${it.quantity || "—"}</td>
            <td class="text-end small">${it.unit_price != null ? parseFloat(it.unit_price).toFixed(2) : "—"}</td>
            <td class="text-end small fw-semibold">${it.total_amount != null ? parseFloat(it.total_amount).toFixed(2) : "—"}</td>
          </tr>`,
            )
            .join("");

        const receipt = o.receipt;
        const receiptHtml = receipt
            ? `
          <div class="row g-2 mb-3">
            <div class="col-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Actual CBM</div><div class="fw-bold text-warning">${parseFloat(receipt.actual_cbm || 0).toFixed(4)}</div></div></div>
            <div class="col-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Actual Weight</div><div class="fw-bold text-warning">${parseFloat(receipt.actual_weight || 0).toFixed(2)} kg</div></div></div>
            <div class="col-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Actual Cartons</div><div class="fw-bold">${receipt.actual_cartons ?? "—"}</div></div></div>
            <div class="col-3"><div class="border rounded p-2 text-center"><div class="small text-muted">Condition</div><div class="fw-bold">${escapeHtml(receipt.receipt_condition || "—")}</div></div></div>
          </div>`
            : "";

        document.getElementById("financeModalBody").innerHTML = `
          <div class="row g-3 mb-3">
            <div class="col-6 col-md-3"><div class="card border-0 bg-primary bg-opacity-10 p-2 text-center"><div class="small text-muted">Total Cost</div><div class="fs-5 fw-bold text-primary">${totalCost > 0 ? totalCost.toFixed(2) + " " + currency : "—"}</div></div></div>
            <div class="col-6 col-md-3"><div class="card border-0 bg-light p-2 text-center"><div class="small text-muted">Total CBM</div><div class="fs-5 fw-bold">${totalCbm.toFixed(4)}</div></div></div>
            <div class="col-6 col-md-3"><div class="card border-0 bg-light p-2 text-center"><div class="small text-muted">Total Weight</div><div class="fs-5 fw-bold">${totalWeight.toFixed(2)} kg</div></div></div>
            <div class="col-6 col-md-3"><div class="card border-0 bg-light p-2 text-center"><div class="small text-muted">Total Cartons</div><div class="fs-5 fw-bold">${totalCartons}</div></div></div>
          </div>
          <div class="d-flex gap-2 mb-2 flex-wrap">
            <span class="badge bg-secondary">${escapeHtml(o.customer_name || "—")}</span>
            <span class="badge bg-secondary">${escapeHtml(o.supplier_name || "—")}</span>
            <span class="badge bg-light text-dark border">${escapeHtml(typeof statusLabel === "function" ? statusLabel(o.status) : o.status || "—")}</span>
            <span class="badge bg-light text-dark border">${escapeHtml(o.expected_ready_date || "—")}</span>
          </div>
          ${receipt ? `<h6 class="mt-3 mb-2 fw-semibold">Warehouse Receipt</h6>${receiptHtml}` : '<p class="text-muted small">No warehouse receipt yet.</p>'}
          <h6 class="mt-3 mb-2 fw-semibold">Items — Supplier Cost Breakdown</h6>
          <div class="table-responsive">
            <table class="table table-sm table-hover">
              <thead class="table-light"><tr><th>Description</th><th>Item No</th><th class="text-end">Cartons</th><th class="text-end">Qty</th><th class="text-end">Unit Price</th><th class="text-end">Total (${escapeHtml(currency)})</th></tr></thead>
              <tbody>${itemRows || '<tr><td colspan="6" class="text-muted text-center">No items</td></tr>'}</tbody>
              <tfoot class="table-light"><tr><td colspan="5" class="text-end fw-semibold">Total Cost</td><td class="text-end fw-bold text-primary">${totalCost > 0 ? totalCost.toFixed(2) : "—"} ${escapeHtml(currency)}</td></tr></tfoot>
            </table>
          </div>
          <p class="text-muted small mt-2 mb-0">Supplier cost only. Customer deposits and P&L margin view coming soon.</p>`;
    } catch (e) {
        document.getElementById("financeModalBody").innerHTML =
            `<div class="alert alert-danger">${escapeHtml(e.message)}</div>`;
    }
}

async function confirmOrder(id) {
    try {
        const res = await api("GET", "/orders/" + id);
        const o = res.data;
        const receipt = o.receipt;
        const showPhotos =
            (o.customer_photo_visibility || "internal-only") ===
            "customer-visible";
        let msg =
            "Confirm acceptance of actual warehouse measurements on behalf of customer?\n\n" +
            "This will move the order to Confirmed. The customer will not need to take any action.";
        if (receipt) {
            msg += `\n\nActual: ${receipt.actual_cbm} CBM, ${receipt.actual_weight} kg, ${receipt.actual_cartons} cartons`;
            if (showPhotos && receipt.photos?.length) {
                msg += `\n(${receipt.photos.length} photo(s) attached)`;
            }
        }
        if (!confirm(msg)) return;
        await api("POST", "/orders/" + id + "/confirm", {});
        showToast("Order confirmed");
        loadOrders();
    } catch (e) {
        showToast(e.message || "Request failed", "danger");
    }
}

async function showOrderInfo(id) {
    const modalEl = document.getElementById("orderInfoModal");
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById("orderInfoBody").innerHTML =
        '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    document.getElementById("orderInfoTitle").textContent = "Order #" + id;
    modal.show();
    try {
        const res = await api("GET", "/orders/" + id);
        const o = res.data || {};
        const items = o.items || [];
        const currency = o.currency || "USD";
        const totalCost = items.reduce(
            (s, it) => s + (parseFloat(it.total_amount) || 0),
            0,
        );
        const totalCbm = items.reduce(
            (s, it) => s + (parseFloat(it.declared_cbm) || 0),
            0,
        );
        const totalWeight = items.reduce(
            (s, it) => s + (parseFloat(it.declared_weight) || 0),
            0,
        );
        const totalCtns = items.reduce(
            (s, it) => s + (parseInt(it.cartons) || 0),
            0,
        );
        const totalQty = items.reduce(
            (s, it) => s + (parseInt(it.quantity) || 0),
            0,
        );
        const receipt = o.receipt;

        const statusCls =
            typeof statusBadgeClass === "function"
                ? statusBadgeClass(o.status)
                : "status-draft";
        const statusLbl =
            typeof statusLabel === "function"
                ? statusLabel(o.status)
                : o.status;

        const imgBase = window.API_BASE
            ? window.API_BASE.replace("/api/v1", "")
            : "/cargochina";

        const itemRows = items
            .map((it) => {
                const imgs = Array.isArray(it.image_paths)
                    ? it.image_paths
                    : [];
                const thumb =
                    imgs.length > 0
                        ? `<img src="${imgBase}/backend/${escapeHtml(imgs[0])}" class="order-info-thumb" onerror="this.style.display='none'">`
                        : `<div class="order-info-thumb-placeholder">📷</div>`;
                const itemNo = escapeHtml(
                    it.item_no || it.shipping_code || "—",
                );
                const desc = escapeHtml(
                    it.description_en || it.description_cn || "—",
                );
                const supplier = escapeHtml(it.supplier_name || "—");
                const productAlert = productAlertTextFromItem(it)
                    ? `<div class="product-alert-badge mt-1" title="${escapeHtml(productAlertTextFromItem(it))}">Alert</div>`
                    : "";
                const scope = (
                    it.product_dimensions_scope ||
                    it.dimensions_scope ||
                    "piece"
                )
                    .toString()
                    .toLowerCase();
                const denom =
                    scope === "carton" && (it.cartons || 0) > 0
                        ? parseFloat(it.cartons) || 1
                        : getItemQuantityFromData(it) || 1;
                const cbmPer =
                    it.declared_cbm && denom > 0
                        ? (parseFloat(it.declared_cbm) / denom).toFixed(4)
                        : "—";
                const gwPer =
                    it.declared_weight && denom > 0
                        ? (parseFloat(it.declared_weight) / denom).toFixed(4)
                        : "—";
                return `<tr>
              <td>${thumb}</td>
              <td class="small fw-semibold">${itemNo}</td>
              <td class="small">${desc}${productAlert}</td>
              <td class="small text-muted">${supplier}</td>
              <td class="text-end small">${it.cartons || "—"} × ${it.qty_per_carton || "—"} = ${it.quantity || "—"}</td>
              <td class="text-end small">${it.unit_price != null ? parseFloat(it.unit_price).toFixed(2) : "—"}</td>
              <td class="text-end small fw-semibold">${it.total_amount != null ? parseFloat(it.total_amount).toFixed(2) : "—"}</td>
              <td class="text-end small">${cbmPer} / ${escapeHtml(String(it.declared_cbm ?? "—"))}</td>
              <td class="text-end small">${gwPer} / ${escapeHtml(String(it.declared_weight ?? "—"))}</td>
            </tr>`;
            })
            .join("");

        const receiptHtml = receipt
            ? `
          <div class="mt-3">
            <h6 class="fw-semibold mb-2">Warehouse Receipt</h6>
            <div class="row g-2 mb-2">
              <div class="col-6 col-md-3"><div class="order-info-stat-card"><div class="label">Actual CBM</div><div class="value text-warning">${parseFloat(receipt.actual_cbm || 0).toFixed(4)}</div></div></div>
              <div class="col-6 col-md-3"><div class="order-info-stat-card"><div class="label">Actual Weight</div><div class="value text-warning">${parseFloat(receipt.actual_weight || 0).toFixed(2)} kg</div></div></div>
              <div class="col-6 col-md-3"><div class="order-info-stat-card"><div class="label">Actual Cartons</div><div class="value">${receipt.actual_cartons ?? "—"}</div></div></div>
              <div class="col-6 col-md-3"><div class="order-info-stat-card"><div class="label">Condition</div><div class="value">${escapeHtml(receipt.receipt_condition || "—")}</div></div></div>
            </div>
            ${receipt.photos?.length ? `<div class="d-flex gap-2 flex-wrap mt-2">${receipt.photos.map((p) => `<img src="${imgBase}/backend/${escapeHtml(p)}" class="order-info-thumb" style="width:72px;height:72px">`).join("")}</div>` : ""}
          </div>`
            : "";

        const container = o.container;
        const containerHtml = container
            ? `
          <div class="mt-3 border rounded p-3 bg-light">
            <h6 class="fw-semibold mb-2">Assigned Container</h6>
            <div class="row g-2 mb-2">
              <div class="col-6 col-md-4"><div class="order-info-stat-card"><div class="label">Container</div><div class="value fw-semibold">${escapeHtml(container.code || "—")}</div></div></div>
              <div class="col-6 col-md-4"><div class="order-info-stat-card"><div class="label">Expected Arrival (ETA)</div><div class="value text-primary">${container.eta_date ? escapeHtml(container.eta_date) : "—"}</div></div></div>
              <div class="col-6 col-md-4"><div class="order-info-stat-card"><div class="label">Status</div><div class="value">${escapeHtml(container.status || "—")}</div></div></div>
              <div class="col-6 col-md-4"><div class="order-info-stat-card"><div class="label">Expected Ship Date</div><div class="value">${container.expected_ship_date ? escapeHtml(container.expected_ship_date) : "—"}</div></div></div>
              <div class="col-6 col-md-4"><div class="order-info-stat-card"><div class="label">Vessel</div><div class="value">${escapeHtml(container.vessel_name || "—")}</div></div></div>
              <div class="col-6 col-md-4"><div class="order-info-stat-card"><div class="label">Destination</div><div class="value">${escapeHtml([container.destination_country, container.destination].filter(Boolean).join(" ") || "—")}</div></div></div>
            </div>
          </div>`
            : "";

        document.getElementById("orderInfoTitle").textContent =
            `Order #${id} — ${escapeHtml(o.customer_name || "")}`;
        const exportHref =
            o.order_type === "draft_procurement"
                ? `${window.API_BASE || "/cargochina/api/v1"}/draft-orders/${id}/export`
                : `${window.API_BASE || "/cargochina/api/v1"}/orders/${id}/export`;
        const exportLabel =
            o.order_type === "draft_procurement" ? "Export Draft CSV" : "Export Excel";

        document.getElementById("orderInfoBody").innerHTML = `
          ${o.high_alert_notes ? `<div class="alert alert-warning py-2 mb-3"><strong>⚠️ High Alert:</strong> ${escapeHtml(o.high_alert_notes)}</div>` : ""}
          <div class="row g-2 mb-3">
            <div class="col-auto"><span class="badge ${statusCls}">${escapeHtml(statusLbl)}</span></div>
            ${o.order_type === "draft_procurement" ? `<div class="col-auto"><span class="badge bg-dark-subtle text-dark border">Draft Order</span></div>` : ""}
            <div class="col-auto"><span class="badge bg-light text-dark border">${escapeHtml(o.customer_name || "—")}</span></div>
            <div class="col-auto"><span class="badge bg-light text-dark border">${escapeHtml(o.supplier_name || "—")}</span></div>
            <div class="col-auto"><span class="badge bg-light text-dark border">📅 ${escapeHtml(o.expected_ready_date || "—")}</span></div>
            <div class="col-auto"><span class="badge bg-light text-dark border">${escapeHtml(currency)}</span></div>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6 col-md-2"><div class="order-info-stat-card"><div class="label">Total Amount</div><div class="value text-primary">${totalCost.toFixed(2)} ${escapeHtml(currency)}</div></div></div>
            <div class="col-6 col-md-2"><div class="order-info-stat-card"><div class="label">Total CBM</div><div class="value">${totalCbm.toFixed(4)}</div></div></div>
            <div class="col-6 col-md-2"><div class="order-info-stat-card"><div class="label">Total Weight</div><div class="value">${totalWeight.toFixed(2)} kg</div></div></div>
            <div class="col-6 col-md-2"><div class="order-info-stat-card"><div class="label">Total Cartons</div><div class="value">${totalCtns}</div></div></div>
            <div class="col-6 col-md-2"><div class="order-info-stat-card"><div class="label">Total Qty</div><div class="value">${totalQty}</div></div></div>
            <div class="col-6 col-md-2"><div class="order-info-stat-card"><div class="label">Items</div><div class="value">${items.length}</div></div></div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover order-info-items">
              <thead class="table-light">
                <tr>
                  <th style="width:56px">Photo</th>
                  <th>Item No</th>
                  <th>Description</th>
                  <th>Supplier</th>
                  <th class="text-end">Ctns × Qty/Ctn = Total</th>
                  <th class="text-end">Unit Price</th>
                  <th class="text-end">Total (${escapeHtml(currency)})</th>
                  <th class="text-end">CBM/unit / Total</th>
                  <th class="text-end">GW/unit / Total</th>
                </tr>
              </thead>
              <tbody>${itemRows || '<tr><td colspan="9" class="text-muted text-center py-3">No items</td></tr>'}</tbody>
            </table>
          </div>
          ${receiptHtml}
          ${containerHtml}
          <div class="d-flex gap-2 mt-3">
            <button class="btn btn-sm btn-outline-primary" onclick="bootstrap.Modal.getOrCreateInstance(document.getElementById('orderInfoModal')).hide(); editOrder(${id})">${o.order_type === "draft_procurement" ? "Open Draft Builder" : "Edit Order"}</button>
            <a class="btn btn-sm btn-outline-success" href="${exportHref}" download>${exportLabel}</a>
          </div>`;
    } catch (e) {
        document.getElementById("orderInfoBody").innerHTML =
            `<div class="alert alert-danger">${escapeHtml(e.message)}</div>`;
    }
}

// ---------------------------------------------------------------------------
// Assign Order to Shipment Draft — from Orders page
// ---------------------------------------------------------------------------
let _assignOrderId = null;

async function openAssignDraftModal(orderId, customerName) {
    _assignOrderId = orderId;
    const modalEl = document.getElementById("assignDraftModal");
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    const labelEl = document.getElementById("assignDraftOrderLabel");
    const warnEl = document.getElementById("assignDraftWarning");
    const listEl = document.getElementById("assignDraftList");
    if (labelEl) labelEl.textContent = `#${orderId} — ${customerName}`;
    if (warnEl) warnEl.classList.add("d-none");
    if (listEl)
        listEl.innerHTML =
            '<div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary"></div></div>';
    modal.show();

    try {
        // Load open shipment drafts + check if order is already in any
        const [draftsRes, orderRes] = await Promise.all([
            api("GET", "/shipment-drafts"),
            api("GET", "/orders/" + orderId),
        ]);
        const drafts = (draftsRes.data || []).filter(
            (d) => d.status !== "finalized",
        );
        const order = orderRes.data || {};

        // Check if already in a draft
        const existingDraftIds = drafts
            .filter((d) => (d.order_ids || []).includes(orderId))
            .map((d) => d.id);
        if (existingDraftIds.length > 0 && warnEl) {
            warnEl.textContent = `⚠ This order is already in Draft #${existingDraftIds.join(", #")}. Assigning again will add it to another draft (allowed for large/split shipments). Please confirm below.`;
            warnEl.classList.remove("d-none");
        }

        if (drafts.length === 0) {
            listEl.innerHTML =
                '<p class="text-muted small">No open shipment drafts. Create a new one below.</p>';
            return;
        }

        listEl.innerHTML = `
            <p class="small text-muted mb-2">Select an open draft to add this order to:</p>
            <div class="list-group">
              ${drafts
                  .map((d) => {
                      const alreadyIn = (d.order_ids || []).includes(orderId);
                      const codeTag = d.container_code
                          ? `<span class="text-muted ms-1">→ ${escapeHtml(d.container_code)}</span>`
                          : "";
                      const badge = alreadyIn
                          ? '<span class="badge bg-warning text-dark ms-1">Already in this draft</span>'
                          : "";
                      const orderList =
                          (d.order_ids || []).length > 0
                              ? `<small class="d-block text-muted">Orders: ${d.order_ids.join(", ")}</small>`
                              : "";
                      return `<button type="button"
                      class="list-group-item list-group-item-action d-flex justify-content-between align-items-start"
                      onclick="confirmAssignToDraft(${d.id}, ${alreadyIn})">
                    <div>
                      <strong>Draft #${d.id}</strong> ${codeTag} ${badge}
                      ${orderList}
                    </div>
                    <span class="badge bg-primary">${(d.order_ids || []).length} orders</span>
                  </button>`;
                  })
                  .join("")}
            </div>`;
    } catch (e) {
        if (listEl)
            listEl.innerHTML = `<div class="alert alert-danger">${escapeHtml(e.message)}</div>`;
    }
}

async function confirmAssignToDraft(draftId, alreadyInThisDraft) {
    const orderId = _assignOrderId;
    if (!orderId) return;

    if (alreadyInThisDraft) {
        if (
            !confirm(
                `Order #${orderId} is already in Draft #${draftId}.\n\nAssign it again to this same draft? (This is allowed for split-container large shipments.)`,
            )
        )
            return;
    }

    try {
        await api("POST", "/shipment-drafts/" + draftId + "/add-orders", {
            order_ids: [orderId],
        });
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById("assignDraftModal"),
        ).hide();
        showToast(`Order #${orderId} added to Draft #${draftId}`);
        loadOrders();
    } catch (e) {
        showToast(e.message || "Failed to assign order", "danger");
    }
}

async function assignOrderToNewDraft() {
    const orderId = _assignOrderId;
    if (!orderId) return;
    try {
        const createRes = await api("POST", "/shipment-drafts");
        const newDraft = createRes.data || {};
        if (!newDraft.id) throw new Error("Failed to create draft");
        await api("POST", "/shipment-drafts/" + newDraft.id + "/add-orders", {
            order_ids: [orderId],
        });
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById("assignDraftModal"),
        ).hide();
        showToast(`Order #${orderId} added to new Draft #${newDraft.id}`);
        loadOrders();
    } catch (e) {
        showToast(e.message || "Failed to create draft", "danger");
    }
}
