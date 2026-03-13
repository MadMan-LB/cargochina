/**
 * CLMS Frontend - API helpers and common UI
 */

const API_BASE = "/cargochina/api/v1";

/** Humanized order status labels (backend values unchanged) */
const ORDER_STATUS_LABELS = {
    Draft: "Draft",
    Submitted: "Submitted",
    Approved: "Approved",
    InTransitToWarehouse: "In Transit",
    ReceivedAtWarehouse: "Received",
    AwaitingCustomerConfirmation: "Awaiting Confirmation",
    Confirmed: "Confirmed",
    ReadyForConsolidation: "Ready for Consolidation",
    ConsolidatedIntoShipmentDraft: "In Shipment Draft",
    AssignedToContainer: "Assigned to Container",
    FinalizedAndPushedToTracking: "Finalized",
};
function statusLabel(s) {
    return (s && ORDER_STATUS_LABELS[s]) || s || "—";
}
function statusBadgeClass(s) {
    const map = {
        Draft: "status-draft",
        Submitted: "status-submitted",
        Approved: "status-approved",
        InTransitToWarehouse: "status-in-transit",
        ReceivedAtWarehouse: "status-received",
        AwaitingCustomerConfirmation: "status-awaiting",
        Confirmed: "status-confirmed",
        ReadyForConsolidation: "status-ready",
        ConsolidatedIntoShipmentDraft: "status-consolidated",
        AssignedToContainer: "status-assigned",
        FinalizedAndPushedToTracking: "status-finalized",
    };
    return (s && map[s]) || "status-draft";
}
/** Description language: 'en' or 'cn'. Stored in localStorage. Default 'en'. */
function descLang() {
    return (
        (typeof localStorage !== "undefined" &&
            localStorage.getItem("clms_desc_lang")) ||
        "en"
    );
}
function descText(item) {
    if (!item) return "—";
    const lang = descLang();
    const en = item.description_en || item.description_cn;
    const cn = item.description_cn || item.description_en;
    return (lang === "cn" ? cn : en) || "—";
}
function setDescLang(lang) {
    if (typeof localStorage !== "undefined")
        localStorage.setItem("clms_desc_lang", lang);
}

if (typeof window !== "undefined") {
    window.statusLabel = statusLabel;
    window.statusBadgeClass = statusBadgeClass;
    window.API_BASE = API_BASE;
    window.descLang = descLang;
    window.descText = descText;
    window.setDescLang = setDescLang;
}

const UPLOAD_BASE = "/cargochina/api/v1/upload";

/** In-memory cache for read-heavy GET endpoints (departments, roles, config). TTL 60s. */
const _apiCache = { data: {}, ts: {} };
const _cachePaths = ["/departments", "/roles", "/config/receiving"];
const _cacheTtlMs = 60000;

async function api(method, path, body = null) {
    const cacheKey = method + " " + path;
    if (
        method === "GET" &&
        _cachePaths.some((p) => path === p || path.startsWith(p + "?"))
    ) {
        const now = Date.now();
        if (
            _apiCache.data[cacheKey] &&
            _apiCache.ts[cacheKey] &&
            now - _apiCache.ts[cacheKey] < _cacheTtlMs
        ) {
            return _apiCache.data[cacheKey];
        }
    }
    const opts = {
        method,
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
    };
    if (body && (method === "POST" || method === "PUT")) {
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(API_BASE + path, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const err = data.error === true ? data : data.error || {};
        const msg =
            err.message || data.message || res.statusText || "Request failed";
        const reqId = err.request_id || data.request_id;
        throw new Error(msg + (reqId ? ` (ref: ${reqId})` : ""));
    }
    if (
        method === "GET" &&
        _cachePaths.some((p) => path === p || path.startsWith(p + "?"))
    ) {
        _apiCache.data[cacheKey] = data;
        _apiCache.ts[cacheKey] = Date.now();
    }
    return data;
}

function showToast(message, type = "success") {
    const container =
        document.querySelector(".toast-container") || createToastContainer();
    const toast = document.createElement("div");
    toast.className = `toast align-items-center text-bg-${type} border-0`;
    toast.setAttribute("role", "alert");
    toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${escapeHtml(message)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    toast.addEventListener("hidden.bs.toast", () => toast.remove());
}

function createToastContainer() {
    const c = document.createElement("div");
    c.className = "toast-container position-fixed top-0 end-0 p-3";
    document.body.appendChild(c);
    return c;
}

function escapeHtml(s) {
    const div = document.createElement("div");
    div.textContent = s;
    return div.innerHTML;
}

function setLoading(el, loading) {
    if (!el) return;
    if (loading) {
        el.classList.add("btn-loading");
        el.disabled = true;
    } else {
        el.classList.remove("btn-loading");
        el.disabled = false;
    }
}

async function uploadFile(file, opts = {}) {
    if (typeof uploadFileWithProgress === "function") {
        return uploadFileWithProgress(file, {
            showToast,
            ...opts,
        });
    }
    const fd = new FormData();
    fd.append("file", file);
    const res = await fetch("/cargochina/api/v1/upload", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    const text = await res.text();
    let j = {};
    try {
        j = text ? JSON.parse(text) : {};
    } catch (_) {
        const snippet = text.substring(0, 200).replace(/</g, "&lt;");
        throw new Error(
            "Server returned invalid response (not JSON). " +
                (snippet ? "Response: " + snippet : ""),
        );
    }
    if (!res.ok) {
        const err = j.error || {};
        const msg = err.message || j.message || "Upload failed";
        const reqId = err.request_id ? ` (ref: ${err.request_id})` : "";
        throw new Error(msg + reqId);
    }
    return (
        j.data?.path ||
        (j.data?.url ? j.data.url.replace("/cargochina/backend/", "") : null)
    );
}

document.addEventListener("change", function (e) {
    if (e.target.id === "importCsvFile" && e.target.files?.[0]) {
        const f = e.target.files[0];
        const r = new FileReader();
        r.onload = function () {
            const ta = document.getElementById("importCsvData");
            if (ta) ta.value = r.result;
        };
        r.readAsText(f);
    }
});
