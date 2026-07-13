/**
 * Confirmations page - admin confirm orders for customers
 */

let filterCustomerAc, filterSupplierAc, filterOrderAc;

let filterDebounce = null;

function setConfirmMetric(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function countActiveConfirmFilters() {
    return [
        document.getElementById("filterSearch")?.value?.trim(),
        document.getElementById("filterDateFrom")?.value,
        document.getElementById("filterDateTo")?.value,
        filterOrderAc?.getSelectedId?.() ||
            document.getElementById("filterOrderId")?.value?.trim(),
        filterCustomerAc?.getSelectedId?.() ||
            document.getElementById("filterCustomer")?.value?.trim(),
        filterSupplierAc?.getSelectedId?.() ||
            document.getElementById("filterSupplier")?.value?.trim(),
    ].filter(Boolean).length;
}

function parseExpectedDate(value) {
    if (!value) return null;
    const date = new Date(value + "T00:00:00");
    return Number.isNaN(date.getTime()) ? null : date;
}

function updateConfirmOverview(rows) {
    const safeRows = rows || [];
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const dueCutoff = new Date(today);
    dueCutoff.setDate(dueCutoff.getDate() + 7);
    const priorityCount = safeRows.filter(
        (row) =>
            row.customer_priority_level &&
            String(row.customer_priority_level).toLowerCase() !== "normal",
    ).length;
    const dueSoonCount = safeRows.filter((row) => {
        const date = parseExpectedDate(row.expected_ready_date);
        return date && date >= today && date <= dueCutoff;
    }).length;
    const activeFilters = countActiveConfirmFilters();
    const uniqueCustomers = new Set(
        safeRows.map((row) => row.customer_name).filter(Boolean),
    ).size;

    setConfirmMetric("confirmQueueCount", String(safeRows.length));
    setConfirmMetric("confirmPriorityCount", String(priorityCount));
    setConfirmMetric("confirmDueSoonCount", String(dueSoonCount));
    setConfirmMetric(
        "confirmQueueDetail",
        safeRows.length
            ? `${uniqueCustomers} customers in the current review queue.`
            : "No orders are waiting for confirmation.",
    );
    setConfirmMetric(
        "confirmPriorityDetail",
        priorityCount
            ? `${priorityCount} orders belong to flagged customer accounts.`
            : "No priority customers in the visible queue.",
    );
    setConfirmMetric(
        "confirmDueSoonDetail",
        dueSoonCount
            ? `${dueSoonCount} orders have expected-ready dates within 7 days.`
            : "Nothing due in the next 7 days.",
    );
    setConfirmMetric(
        "confirmFilterSummary",
        safeRows.length
            ? `Showing ${safeRows.length} awaiting order(s)${activeFilters ? ` after ${activeFilters} active filter(s)` : ""}.`
            : activeFilters
              ? "No awaiting orders match the active confirmation filters."
              : "Showing the full confirmation queue.",
    );
}

document.addEventListener("DOMContentLoaded", function () {
    filterOrderAc = Autocomplete.init(
        document.getElementById("filterOrderId"),
        {
            resource: "orders",
            searchPath: "/search",
            placeholder: "Type to search order…",
            onSelect: () => applyFiltersDebounced(),
        },
    );
    filterCustomerAc = Autocomplete.init(
        document.getElementById("filterCustomer"),
        {
            resource: "customers",
            placeholder: "Type to search...",
            onSelect: () => applyFiltersDebounced(),
        },
    );
    filterSupplierAc = Autocomplete.init(
        document.getElementById("filterSupplier"),
        {
            resource: "suppliers",
            placeholder: "Type to search...",
            onSelect: () => applyFiltersDebounced(),
        },
    );
    const selectAll = document.getElementById("selectAllConfirm");
    if (selectAll) {
        selectAll.addEventListener("change", function () {
            document
                .querySelectorAll(".confirm-cb")
                .forEach((cb) => (cb.checked = this.checked));
            updateBulkConfirmBtn();
        });
    }
    ["filterDateFrom", "filterDateTo", "filterOrderId"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener("change", applyFiltersDebounced);
    });
    const searchEl = document.getElementById("filterSearch");
    if (searchEl) searchEl.addEventListener("input", applyFiltersDebounced);
    ["filterCustomer", "filterSupplier"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener("input", applyFiltersDebounced);
    });
    readFiltersFromUrl();
    loadConfirmations();
});

function applyFiltersDebounced() {
    clearTimeout(filterDebounce);
    filterDebounce = setTimeout(() => {
        syncFiltersToUrl();
        loadConfirmations();
    }, 200);
}

function readFiltersFromUrl() {
    const params = new URLSearchParams(window.location.search);
    const q = params.get("q");
    const dateFrom = params.get("date_from");
    const dateTo = params.get("date_to");
    const orderId = params.get("order_id");
    if (q) document.getElementById("filterSearch").value = q;
    if (dateFrom) document.getElementById("filterDateFrom").value = dateFrom;
    if (dateTo) document.getElementById("filterDateTo").value = dateTo;
    if (orderId && filterOrderAc?.setValue) {
        filterOrderAc.setValue({
            id: orderId,
            customer_name: "",
            expected_ready_date: "",
            status: "",
        });
    } else if (orderId) {
        document.getElementById("filterOrderId").value = orderId;
    }
}

function syncFiltersToUrl() {
    const q = document.getElementById("filterSearch")?.value?.trim() || "";
    const dateFrom = document.getElementById("filterDateFrom")?.value;
    const dateTo = document.getElementById("filterDateTo")?.value;
    const orderId =
        filterOrderAc?.getSelectedId?.() ||
        document.getElementById("filterOrderId")?.value?.trim() ||
        "";
    const customerId = filterCustomerAc?.getSelectedId() || "";
    const supplierId = filterSupplierAc?.getSelectedId() || "";
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);
    if (orderId) params.set("order_id", orderId);
    if (customerId) params.set("customer_id", customerId);
    if (supplierId) params.set("supplier_id", supplierId);
    const qs = params.toString();
    const url = qs
        ? window.location.pathname + "?" + qs
        : window.location.pathname;
    if (window.history.replaceState) window.history.replaceState({}, "", url);
}

function updateBulkConfirmBtn() {
    const checked = document.querySelectorAll(".confirm-cb:checked");
    const btn = document.getElementById("bulkConfirmBtn");
    const selectedCount = checked.length;
    if (btn) {
        btn.disabled = selectedCount === 0;
        btn.textContent =
            selectedCount > 0
                ? `Confirm Selected (${selectedCount})`
                : "Confirm Selected";
    }
    setConfirmMetric("confirmSelectedCount", String(selectedCount));
    setConfirmMetric(
        "confirmSelectedDetail",
        selectedCount
            ? "Ready for a single bulk confirmation action."
            : "Orders picked for bulk confirmation.",
    );
    setConfirmMetric(
        "confirmSelectionHint",
        selectedCount
            ? `${selectedCount} order(s) selected for bulk confirmation.`
            : "No orders selected yet.",
    );
}

function getSelectedOrderIds() {
    return Array.from(document.querySelectorAll(".confirm-cb:checked")).map(
        (cb) => parseInt(cb.value, 10),
    );
}

function buildConfirmationsPath() {
    const q = document.getElementById("filterSearch")?.value?.trim() || "";
    const dateFrom = document.getElementById("filterDateFrom").value;
    const dateTo = document.getElementById("filterDateTo").value;
    const orderId =
        filterOrderAc?.getSelectedId?.() ||
        document.getElementById("filterOrderId")?.value?.trim() ||
        "";
    const customerId = filterCustomerAc?.getSelectedId() || "";
    const supplierId = filterSupplierAc?.getSelectedId() || "";
    let path = "/orders?status=AwaitingCustomerConfirmation";
    if (q) path += "&q=" + encodeURIComponent(q);
    if (dateFrom) path += "&date_from=" + encodeURIComponent(dateFrom);
    if (dateTo) path += "&date_to=" + encodeURIComponent(dateTo);
    if (orderId) path += "&order_id=" + encodeURIComponent(orderId);
    if (customerId) path += "&customer_id=" + encodeURIComponent(customerId);
    if (supplierId) path += "&supplier_id=" + encodeURIComponent(supplierId);
    return path;
}

async function loadConfirmations() {
    try {
        const res = await api("GET", buildConfirmationsPath());
        const rows = res.data || [];
        const tbody = document.querySelector("#confirmationsTable tbody");
        const suppDisplay = (r) => {
            const items = r.items || [];
            const names = [
                ...new Set(items.map((i) => i.supplier_name).filter(Boolean)),
            ];
            if (names.length > 0) return names.join(", ");
            return r.supplier_name || "-";
        };
        const cbm = (r) =>
            (r.items || []).reduce(
                (s, i) => s + (parseFloat(i.declared_cbm) || 0),
                0,
            );
        const wt = (r) =>
            (r.items || []).reduce(
                (s, i) => s + (parseFloat(i.declared_weight) || 0),
                0,
            );
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
        <tr data-order-id="${r.id}">
          <td><input type="checkbox" class="form-check-input confirm-cb" value="${r.id}" aria-label="Select"></td>
          <td>${r.id}</td>
          <td>${escapeHtml(r.customer_name || "-")}${r.customer_priority_level && r.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escapeHtml(r.customer_priority_note || "")}">${escapeHtml(r.customer_priority_level)}</span>` : ""}</td>
          <td>${escapeHtml(suppDisplay(r))}</td>
          <td>${r.expected_ready_date || "-"}</td>
          <td><span class="badge bg-warning text-dark">${escapeHtml(typeof statusLabel === "function" ? statusLabel(r.status) : r.status)}</span></td>
          <td>${cbm(r).toFixed(2)}</td>
          <td>${wt(r).toFixed(0)} kg</td>
          <td>
            <button class="btn btn-sm btn-success" onclick="confirmOrder(${r.id})">Confirm</button>
          </td>
        </tr>
      `,
                )
                .join("") ||
            '<tr><td colspan="9" class="text-muted">No orders awaiting confirmation.</td></tr>';
        document.querySelectorAll(".confirm-cb").forEach((cb) => {
            cb.addEventListener("change", updateBulkConfirmBtn);
        });
        updateConfirmOverview(rows);
        updateBulkConfirmBtn();
        const selectAll = document.getElementById("selectAllConfirm");
        if (selectAll) selectAll.checked = false;
    } catch (e) {
        updateConfirmOverview([]);
        updateBulkConfirmBtn();
        showToast(e.message || "Failed to load", "danger");
    }
}

async function bulkConfirm() {
    const ids = getSelectedOrderIds();
    if (ids.length === 0) return;
    const msg =
        `Confirm ${ids.length} order(s) on behalf of customer?\n\n` +
        "This accepts the actual warehouse measurements (CBM, weight, cartons) and moves orders to Confirmed. The customer will not need to take any action.";
    if (!confirm(msg)) return;
    const btn = document.getElementById("bulkConfirmBtn");
    try {
        setLoading(btn, true);
        let ok = 0,
            err = 0;
        for (const id of ids) {
            try {
                await api("POST", "/orders/" + id + "/confirm", {});
                ok++;
            } catch (e) {
                err++;
                showToast(
                    "Order #" + id + ": " + (e.message || "Failed"),
                    "danger",
                );
            }
        }
        showToast(`Confirmed ${ok} order(s)` + (err ? `, ${err} failed` : ""));
        loadConfirmations();
    } finally {
        setLoading(btn, false);
    }
}

async function confirmOrder(id) {
    try {
        const res = await api("GET", "/orders/" + id);
        const o = res.data;
        const receipt = o.receipt;
        let msg =
            "Confirm acceptance of actual warehouse measurements on behalf of customer?\n\n" +
            "This will move the order to Confirmed. The customer will not need to take any action.";
        if (receipt) {
            msg += `\n\nActual: ${receipt.actual_cbm} CBM, ${receipt.actual_weight} kg, ${receipt.actual_cartons} cartons`;
        }
        if (!confirm(msg)) return;
        await api("POST", "/orders/" + id + "/confirm", {});
        showToast("Order confirmed");
        loadConfirmations();
    } catch (e) {
        showToast(e.message || "Request failed", "danger");
    }
}

function clearFilters() {
    document.getElementById("filterDateFrom").value = "";
    document.getElementById("filterDateTo").value = "";
    document.getElementById("filterSearch").value = "";
    if (filterOrderAc?.setValue) filterOrderAc.setValue(null);
    else document.getElementById("filterOrderId").value = "";
    filterCustomerAc?.setValue(null);
    filterSupplierAc?.setValue(null);
    syncFiltersToUrl();
    loadConfirmations();
}
