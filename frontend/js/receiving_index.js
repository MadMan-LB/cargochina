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
    const dateFrom = document.getElementById("filterDateFrom")?.value;
    const dateTo = document.getElementById("filterDateTo")?.value;
    let path = "/receiving/queue?status=Approved&status=InTransitToWarehouse";
    if (orderId) path += "&order_id=" + encodeURIComponent(orderId);
    if (dateFrom) path += "&date_from=" + encodeURIComponent(dateFrom);
    if (dateTo) path += "&date_to=" + encodeURIComponent(dateTo);

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

document.addEventListener("DOMContentLoaded", () => {
    loadQueue();
    document
        .querySelector('[data-bs-toggle="tab"][href="#history-tab"]')
        ?.addEventListener("shown.bs.tab", loadHistory);
});
