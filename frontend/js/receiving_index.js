/**
 * Receiving index: queue + history with filters
 */
const API = "/cargochina/api/v1";
const AREA_BASE = "/cargochina/warehouse";
let queueLoadVersion = 0;
let historyLoadVersion = 0;
let queueOffset = 0;
let historyOffset = 0;
const RECEIVING_PAGE_SIZE = 50;

function receivingPager(kind, total, count, offset) {
    const summary=document.getElementById(kind+'PageSummary');
    if(summary) summary.textContent=total ? `${offset+1}–${offset+count} of ${total}` : '0 records';
    const previous=document.getElementById(kind+'Previous');
    const next=document.getElementById(kind+'Next');
    if(previous) previous.disabled=offset===0;
    if(next) next.disabled=offset+count>=total;
}
function receivingChangePage(kind, delta) {
    if(kind==='queue') {queueOffset=Math.max(0,queueOffset+delta*RECEIVING_PAGE_SIZE);loadQueue(true);}
    else {historyOffset=Math.max(0,historyOffset+delta*RECEIVING_PAGE_SIZE);loadHistory(true);}
}

function receivingIndexT(text, replacements = null) {
    return typeof window.t === "function" ? window.t(text, replacements) : text;
}

function receivingIndexStatusText(status) {
    return typeof window.statusLabel === "function"
        ? window.statusLabel(status)
        : receivingIndexT(status);
}

function receivingOrderExcelUrl(orderId) {
    return `${API}/orders/${encodeURIComponent(orderId)}/export?format=xlsx`;
}

async function receivingIndexApi(path) {
    if (typeof window.api === "function") {
        return window.api("GET", path);
    }
    const res = await fetch(API + path, { credentials: "same-origin" });
    const d = await res.json().catch(() => ({}));
    if (!res.ok)
        throw new Error(receivingIndexT(d.message || d.error?.message || "Request failed"));
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

async function loadQueue(keepPage = false) {
    if(keepPage !== true) queueOffset=0;
    const loadVersion = ++queueLoadVersion;
    const orderId =
        filterOrderAc?.getSelectedId?.() ||
        document.getElementById("filterOrderId")?.value?.trim() ||
        "";
    const customerId = document.getElementById("filterCustomerId")?.value;
    const supplierId = document.getElementById("filterSupplierId")?.value;
    const dateFrom = document.getElementById("filterDateFrom")?.value;
    const dateTo = document.getElementById("filterDateTo")?.value;
    const shippingCode = document
        .getElementById("filterShippingCode")
        ?.value?.trim();
    let path = "/receiving/queue?status=Approved,InTransitToWarehouse&limit="+RECEIVING_PAGE_SIZE+"&offset="+queueOffset;
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
        const res = await receivingIndexApi(path);
        if (loadVersion !== queueLoadVersion) return;
        const rows = res.data || [];
        const total=Number(res.meta?.total ?? rows.length);
        if(!rows.length && queueOffset>0 && total<queueOffset+1) {queueOffset=Math.max(0,Math.ceil(total/RECEIVING_PAGE_SIZE)-1)*RECEIVING_PAGE_SIZE;return loadQueue(true);}
        receivingPager('queue',total,rows.length,queueOffset);
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
                    <td>${window.RECEIVING_CAN_RECORD ? `<a href="${AREA_BASE}/receiving/receive.php?order_id=${o.id}">#${o.id}</a>` : `#${o.id}`}</td>
                    <td>${escapeHtml(o.customer_name)}${o.customer_priority_level && o.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escapeHtml(o.customer_priority_note || "")}">${escapeHtml(receivingIndexStatusText(o.customer_priority_level))}</span>` : ""}</td>
                    <td>${escapeHtml(o.supplier_name)}</td>
                    <td>${escapeHtml(o.expected_ready_date)}</td>
                    <td>${parseFloat(o.declared_cbm || 0).toFixed(2)} CBM / ${parseFloat(o.declared_weight || 0).toFixed(0)} kg</td>
                    <td>
                        <div class="d-flex gap-2 flex-wrap">
                            ${window.RECEIVING_CAN_RECORD ? `<a class="btn btn-sm btn-primary" href="${AREA_BASE}/receiving/receive.php?order_id=${o.id}">${escapeHtml(receivingIndexT("Receive"))}</a>` : ''}
                            <a class="btn btn-sm btn-outline-success" href="${receivingOrderExcelUrl(o.id)}">${escapeHtml(receivingIndexT("Download"))}</a>
                        </div>
                    </td>
                </tr>
            `,
                )
                .join("");
        }
    } catch (e) {
        if (loadVersion !== queueLoadVersion) return;
        receivingPager('queue',0,0,0);
        showSkeleton("queueSkeleton", false);
        document.getElementById("queueTable")?.classList.remove("d-none");
        document.getElementById("queueBody").innerHTML =
            '<tr><td colspan="6" class="text-danger">' +
            escapeHtml(receivingIndexT(e.message || "Request failed")) +
            "</td></tr>";
    }
}

async function loadHistory(keepPage = false) {
    if(keepPage !== true) historyOffset=0;
    const loadVersion = ++historyLoadVersion;
    const orderId = document.getElementById("histOrderId")?.value?.trim();
    const dateFrom = document.getElementById("histDateFrom")?.value;
    const dateTo = document.getElementById("histDateTo")?.value;
    let path = "/receiving/receipts?limit="+RECEIVING_PAGE_SIZE+"&offset="+historyOffset;
    if (orderId) path += "&order_id=" + encodeURIComponent(orderId);
    if (dateFrom) path += "&date_from=" + encodeURIComponent(dateFrom);
    if (dateTo) path += "&date_to=" + encodeURIComponent(dateTo);

    showSkeleton("historySkeleton", true);
    document.getElementById("historyTable")?.classList.add("d-none");
    document.getElementById("historyEmpty")?.classList.add("d-none");
    try {
        const res = await receivingIndexApi(path);
        if (loadVersion !== historyLoadVersion) return;
        const rows = res.data || [];
        const total=Number(res.meta?.total ?? rows.length);
        if(!rows.length && historyOffset>0 && total<historyOffset+1) {historyOffset=Math.max(0,Math.ceil(total/RECEIVING_PAGE_SIZE)-1)*RECEIVING_PAGE_SIZE;return loadHistory(true);}
        receivingPager('history',total,rows.length,historyOffset);
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
                    <td><a href="${AREA_BASE}/receiving/receipt.php?id=${r.id}">#${r.id}</a>${r.voided_at ? ' <span class="badge bg-secondary">Voided</span>' : ''}</td>
                    <td>#${r.order_id}</td>
                    <td>${escapeHtml(r.customer_name)}${r.customer_priority_level && r.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escapeHtml(r.customer_priority_note || "")}">${escapeHtml(receivingIndexStatusText(r.customer_priority_level))}</span>` : ""}</td>
                    <td>${Number(r.actual_cbm || 0).toLocaleString(undefined,{maximumFractionDigits:6})} CBM / ${Number(r.actual_weight || 0).toLocaleString(undefined,{maximumFractionDigits:4})} kg</td>
                    <td>${escapeHtml((r.received_at || "").replace(" ", " "))}</td>
                    <td>
                        <div class="d-flex gap-2 flex-wrap">
                            <a class="btn btn-sm btn-outline-secondary" href="${AREA_BASE}/receiving/receipt.php?id=${r.id}">${escapeHtml(receivingIndexT("View"))}</a>
                            <a class="btn btn-sm btn-outline-success" href="${API}/receiving/receipts/${r.id}/export?format=xlsx">${escapeHtml(receivingIndexT("Download"))}</a>
                        </div>
                    </td>
                </tr>
            `,
                )
                .join("");
        }
    } catch (e) {
        if (loadVersion !== historyLoadVersion) return;
        receivingPager('history',0,0,0);
        showSkeleton("historySkeleton", false);
        document.getElementById("historyTable")?.classList.remove("d-none");
        document.getElementById("historyBody").innerHTML =
            '<tr><td colspan="6" class="text-danger">' +
            escapeHtml(receivingIndexT(e.message || "Request failed")) +
            "</td></tr>";
    }
}

function buildQueueExportParams() {
    const orderId =
        filterOrderAc?.getSelectedId?.() ||
        document.getElementById("filterOrderId")?.value?.trim() ||
        "";
    const customerId = document.getElementById("filterCustomerId")?.value;
    const supplierId = document.getElementById("filterSupplierId")?.value;
    const dateFrom = document.getElementById("filterDateFrom")?.value;
    const dateTo = document.getElementById("filterDateTo")?.value;
    const shippingCode = document
        .getElementById("filterShippingCode")
        ?.value?.trim();
    let path = "/receiving/queue?status=Approved,InTransitToWarehouse";
    if (orderId) path += "&order_id=" + encodeURIComponent(orderId);
    if (customerId) path += "&customer_id=" + encodeURIComponent(customerId);
    if (supplierId) path += "&supplier_id=" + encodeURIComponent(supplierId);
    if (dateFrom) path += "&date_from=" + encodeURIComponent(dateFrom);
    if (dateTo) path += "&date_to=" + encodeURIComponent(dateTo);
    if (shippingCode)
        path += "&shipping_code=" + encodeURIComponent(shippingCode);
    return path.replace("/receiving/queue?", "");
}

function exportQueue(format = "xlsx") {
    const params = new URLSearchParams(buildQueueExportParams());
    params.set("format", format);
    window.location.href = API + "/receiving/export/queue?" + params.toString();
}

function exportQueueXlsx() {
    exportQueue("xlsx");
}

function exportQueueCsv() {
    exportQueue("csv");
}

let filterOrderAc;

function setupFilterAutocomplete() {
    const orderInput = document.getElementById("filterOrderId");
    const supInput = document.getElementById("filterSupplier");
    const supId = document.getElementById("filterSupplierId");
    const custInput = document.getElementById("filterCustomer");
    const custId = document.getElementById("filterCustomerId");
    if (typeof Autocomplete === "undefined") return;
    if (orderInput) {
        filterOrderAc = Autocomplete.init(orderInput, {
            resource: "orders",
            searchPath: "/search",
            placeholder: receivingIndexT("Type to search order…"),
            onSelect: () => loadQueue(),
        });
    }
    if (supInput) {
        Autocomplete.init(supInput, {
            resource: "suppliers",
            placeholder: receivingIndexT("Type to search supplier..."),
            onSelect: (item) => {
                if (supId) supId.value = item.id;
            },
        });
        supInput.addEventListener("input", () => {
            if (!supInput.value.trim() && supId) supId.value = "";
        });
    }
    if (custInput) {
        Autocomplete.init(custInput, {
            resource: "customers",
            searchPath: "/lookup",
            placeholder: receivingIndexT("Type to search customer..."),
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
}

document.addEventListener("DOMContentLoaded", () => {
    setupFilterAutocomplete();
    loadQueue();
    document
        .querySelector('[data-bs-toggle="tab"][href="#history-tab"]')
        ?.addEventListener("shown.bs.tab", loadHistory);
});
