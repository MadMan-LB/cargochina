/**
 * Receiving index: queue + history with filters
 */
const API = "/cargochina/api/v1";
const AREA_BASE = "/cargochina/warehouse";

async function api(path) {
    const res = await fetch(API + path, { credentials: "same-origin" });
    const d = await res.json().catch(() => ({}));
    if (!res.ok)
        throw new Error(d.message || d.error?.message || "Request failed");
    return d;
}

function escapeHtml(s) {
    const d = document.createElement("div");
    d.textContent = s ?? "";
    return d.innerHTML;
}

function showSkeleton(id, show) {
    const el = document.getElementById(id);
    if (el) el.classList.toggle("d-none", !show);
}

async function loadQueue() {
    const orderId = document.getElementById("filterOrderId")?.value?.trim();
    const customerId = document.getElementById("filterCustomerId")?.value;
    const supplierId = document.getElementById("filterSupplierId")?.value;
    const dateFrom = document.getElementById("filterDateFrom")?.value;
    const dateTo = document.getElementById("filterDateTo")?.value;
    const shippingCode = document
        .getElementById("filterShippingCode")
        ?.value?.trim();
    let path = "/receiving/queue?status=Approved&status=InTransitToWarehouse";
    if (orderId) path += "&order_id=" + encodeURIComponent(orderId);
    if (customerId) path += "&customer_id=" + encodeURIComponent(customerId);
    if (supplierId) path += "&supplier_id=" + encodeURIComponent(supplierId);
    if (dateFrom) path += "&date_from=" + encodeURIComponent(dateFrom);
    if (dateTo) path += "&date_to=" + encodeURIComponent(dateTo);
    if (shippingCode)
        path += "&shipping_code=" + encodeURIComponent(shippingCode);

    showSkeleton("queueSkeleton", true);
    document.getElementById("queueTable")?.classList.add("d-none");
    document.getElementById("queueEmpty")?.classList.add("d-none");
    try {
        const res = await api(path);
        const rows = res.data || [];
        showSkeleton("queueSkeleton", false);
        document.getElementById("queueTable")?.classList.remove("d-none");
        const tbody = document.getElementById("queueBody");
        if (rows.length === 0) {
            document.getElementById("queueEmpty")?.classList.remove("d-none");
            tbody.innerHTML = "";
        } else {
            document.getElementById("queueEmpty")?.classList.add("d-none");
            tbody.innerHTML = rows
                .map(
                    (o) => `
                <tr>
                    <td><a href="${AREA_BASE}/receiving/receive.php?order_id=${o.id}">#${o.id}</a></td>
                    <td>${escapeHtml(o.customer_name)}</td>
                    <td>${escapeHtml(o.supplier_name)}</td>
                    <td>${escapeHtml(o.expected_ready_date)}</td>
                    <td>${parseFloat(o.declared_cbm || 0).toFixed(2)} CBM / ${parseFloat(o.declared_weight || 0).toFixed(0)} kg</td>
                    <td><a class="btn btn-sm btn-primary" href="${AREA_BASE}/receiving/receive.php?order_id=${o.id}">Receive</a></td>
                </tr>
            `,
                )
                .join("");
        }
    } catch (e) {
        showSkeleton("queueSkeleton", false);
        document.getElementById("queueTable")?.classList.remove("d-none");
        document.getElementById("queueBody").innerHTML =
            '<tr><td colspan="6" class="text-danger">' +
            escapeHtml(e.message) +
            "</td></tr>";
    }
}

async function loadHistory() {
    const orderId = document.getElementById("histOrderId")?.value?.trim();
    const dateFrom = document.getElementById("histDateFrom")?.value;
    const dateTo = document.getElementById("histDateTo")?.value;
    let path = "/receiving/receipts?limit=50";
    if (orderId) path += "&order_id=" + encodeURIComponent(orderId);
    if (dateFrom) path += "&date_from=" + encodeURIComponent(dateFrom);
    if (dateTo) path += "&date_to=" + encodeURIComponent(dateTo);

    showSkeleton("historySkeleton", true);
    document.getElementById("historyTable")?.classList.add("d-none");
    document.getElementById("historyEmpty")?.classList.add("d-none");
    try {
        const res = await api(path);
        const rows = res.data || [];
        showSkeleton("historySkeleton", false);
        document.getElementById("historyTable")?.classList.remove("d-none");
        const tbody = document.getElementById("historyBody");
        if (rows.length === 0) {
            document.getElementById("historyEmpty")?.classList.remove("d-none");
            tbody.innerHTML = "";
        } else {
            document.getElementById("historyEmpty")?.classList.add("d-none");
            tbody.innerHTML = rows
                .map(
                    (r) => `
                <tr>
                    <td><a href="${AREA_BASE}/receiving/receipt.php?id=${r.id}">#${r.id}</a></td>
                    <td>#${r.order_id}</td>
                    <td>${escapeHtml(r.customer_name)}</td>
                    <td>${parseFloat(r.actual_cbm || 0).toFixed(2)} CBM / ${parseFloat(r.actual_weight || 0).toFixed(0)} kg</td>
                    <td>${escapeHtml((r.received_at || "").replace(" ", " "))}</td>
                    <td><a class="btn btn-sm btn-outline-secondary" href="${AREA_BASE}/receiving/receipt.php?id=${r.id}">View</a></td>
                </tr>
            `,
                )
                .join("");
        }
    } catch (e) {
        showSkeleton("historySkeleton", false);
        document.getElementById("historyTable")?.classList.remove("d-none");
        document.getElementById("historyBody").innerHTML =
            '<tr><td colspan="6" class="text-danger">' +
            escapeHtml(e.message) +
            "</td></tr>";
    }
}

async function exportQueueCsv() {
    const orderId = document.getElementById("filterOrderId")?.value?.trim();
    const customerId = document.getElementById("filterCustomerId")?.value;
    const supplierId = document.getElementById("filterSupplierId")?.value;
    const dateFrom = document.getElementById("filterDateFrom")?.value;
    const dateTo = document.getElementById("filterDateTo")?.value;
    const shippingCode = document
        .getElementById("filterShippingCode")
        ?.value?.trim();
    let path = "/receiving/queue?status=Approved&status=InTransitToWarehouse";
    if (orderId) path += "&order_id=" + encodeURIComponent(orderId);
    if (customerId) path += "&customer_id=" + encodeURIComponent(customerId);
    if (supplierId) path += "&supplier_id=" + encodeURIComponent(supplierId);
    if (dateFrom) path += "&date_from=" + encodeURIComponent(dateFrom);
    if (dateTo) path += "&date_to=" + encodeURIComponent(dateTo);
    if (shippingCode)
        path += "&shipping_code=" + encodeURIComponent(shippingCode);
    try {
        const res = await api(path);
        const rows = res.data || [];
        const headers = [
            "Order ID",
            "Customer",
            "Supplier",
            "Expected Ready",
            "Status",
            "Shipping Codes",
            "Total Cartons",
            "Declared CBM",
            "Declared Weight (kg)",
            "Items Summary",
        ];
        const lines = [headers.join(",")];
        rows.forEach((o) => {
            const items = o.items || [];
            const shippingCodes = [
                ...new Set(items.map((i) => i.shipping_code).filter(Boolean)),
            ].join("; ");
            const totalCartons = items.reduce(
                (s, i) => s + (parseInt(i.cartons) || 0),
                0,
            );
            const itemsSummary = items
                .map(
                    (i) =>
                        `${i.shipping_code || "-"} ${i.cartons || 0}ctn HS:${i.hs_code || "-"}`,
                )
                .join("; ");
            lines.push(
                [
                    o.id,
                    '"' + (o.customer_name || "").replace(/"/g, '""') + '"',
                    '"' + (o.supplier_name || "").replace(/"/g, '""') + '"',
                    o.expected_ready_date || "",
                    o.status || "",
                    '"' + (shippingCodes || "").replace(/"/g, '""') + '"',
                    totalCartons,
                    parseFloat(o.declared_cbm || 0).toFixed(4),
                    parseFloat(o.declared_weight || 0).toFixed(2),
                    '"' + (itemsSummary || "").replace(/"/g, '""') + '"',
                ].join(","),
            );
        });
        const csv = lines.join("\n");
        const blob = new Blob(["\ufeff" + csv], {
            type: "text/csv;charset=utf-8",
        });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download =
            "receiving_queue_" + new Date().toISOString().slice(0, 10) + ".csv";
        a.click();
        URL.revokeObjectURL(a.href);
        if (typeof showToast === "function")
            showToast("Exported " + rows.length + " orders");
    } catch (e) {
        if (typeof showToast === "function") showToast(e.message, "danger");
        else alert(e.message);
    }
}

function setupFilterAutocomplete() {
    const supInput = document.getElementById("filterSupplier");
    const supId = document.getElementById("filterSupplierId");
    const custInput = document.getElementById("filterCustomer");
    const custId = document.getElementById("filterCustomerId");
    if (!supInput || !custInput || typeof Autocomplete === "undefined") return;
    Autocomplete.init(supInput, {
        resource: "suppliers",
        placeholder: "Type to search supplier...",
        onSelect: (item) => {
            if (supId) supId.value = item.id;
        },
    });
    supInput.addEventListener("input", () => {
        if (!supInput.value.trim() && supId) supId.value = "";
    });
    Autocomplete.init(custInput, {
        resource: "customers",
        placeholder: "Type to search customer...",
        renderItem: (c) =>
            `${c.name || ""} — ${c.code || ""}`
                .replace(/^ — | — $/g, "")
                .trim() || `#${c.id}`,
        onSelect: (item) => {
            if (custId) custId.value = item.id;
        },
    });
    custInput.addEventListener("input", () => {
        if (!custInput.value.trim() && custId) custId.value = "";
    });
}

document.addEventListener("DOMContentLoaded", () => {
    setupFilterAutocomplete();
    loadQueue();
    document
        .querySelector('[data-bs-toggle="tab"][href="#history-tab"]')
        ?.addEventListener("shown.bs.tab", loadHistory);
});
