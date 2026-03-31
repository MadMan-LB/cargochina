/**
 * CLMS Frontend - API helpers and common UI
 */

const API_BASE = "/cargochina/api/v1";
const CLMS_UI = window.CLMS_UI || { locale: "en", strings: {}, statuses: {} };

function uiLocale() {
    return CLMS_UI.locale || "en";
}

function uiStrings() {
    return CLMS_UI.strings || {};
}

function uiStatuses() {
    return CLMS_UI.statuses || {};
}

function t(text, replacements = null) {
    if (text === null || text === undefined) return "";
    const original = String(text);
    const translated = uiStrings()[original] || original;
    if (!replacements || typeof replacements !== "object") {
        return translated;
    }
    return Object.entries(replacements).reduce((acc, [key, value]) => {
        return acc.replaceAll(`{${key}}`, String(value));
    }, translated);
}

function translateTextNode(node) {
    if (!node || node.nodeType !== Node.TEXT_NODE) return;
    const parent = node.parentElement;
    if (
        !parent ||
        parent.closest("[data-no-translate]") ||
        parent.matches("script, style, textarea, code, pre, option[data-no-translate]")
    ) {
        return;
    }
    const shouldTranslateParent = parent.matches(
        "h1, h2, h3, h4, h5, h6, p, label, th, button, a, option, legend, strong, small, dt, .card-header, .eyebrow, .sidebar-section-label, .sidebar-link, .topbar-title, .modal-title, .modal-subtitle, .form-text, .text-muted, .badge, .btn, .alert, .detail, .title, .subtitle, .summary-text, .filter-toolbar-subtext, .fw-semibold, .small, .label, td.text-muted, td.text-center, [data-i18n]",
    );
    if (!shouldTranslateParent) {
        return;
    }
    const raw = node.nodeValue || "";
    const trimmed = raw.trim();
    if (!trimmed) return;
    const translated = uiStrings()[trimmed];
    if (!translated || translated === trimmed) return;
    const prefixLength = raw.indexOf(trimmed);
    const suffixLength = raw.length - prefixLength - trimmed.length;
    node.nodeValue =
        raw.slice(0, prefixLength) + translated + raw.slice(raw.length - suffixLength);
}

function translateElementAttributes(element) {
    if (!(element instanceof HTMLElement) || element.closest("[data-no-translate]")) {
        return;
    }
    ["placeholder", "title", "aria-label", "alt", "data-bs-original-title"].forEach(
        (attribute) => {
            const value = element.getAttribute(attribute);
            if (!value) return;
            const translated = uiStrings()[value];
            if (translated && translated !== value) {
                element.setAttribute(attribute, translated);
            }
        },
    );
    if (
        element instanceof HTMLInputElement &&
        ["button", "submit", "reset"].includes((element.type || "").toLowerCase())
    ) {
        const translated = uiStrings()[element.value];
        if (translated && translated !== element.value) {
            element.value = translated;
        }
    }
}

function translateTree(root = document.body) {
    if (uiLocale() === "en" || !root) return;
    const container =
        root instanceof Document
            ? root.body
            : root instanceof HTMLElement
              ? root
              : root.parentElement;
    if (!container) return;

    if (container.nodeType === Node.TEXT_NODE) {
        translateTextNode(container);
        return;
    }

    translateElementAttributes(container);
    const walker = document.createTreeWalker(
        container,
        NodeFilter.SHOW_TEXT | NodeFilter.SHOW_ELEMENT,
        null,
    );
    let current;
    while ((current = walker.nextNode())) {
        if (current.nodeType === Node.TEXT_NODE) {
            translateTextNode(current);
        } else if (current.nodeType === Node.ELEMENT_NODE) {
            translateElementAttributes(current);
        }
    }
}

let translationObserverStarted = false;
function startUiTranslationObserver() {
    if (translationObserverStarted || uiLocale() === "en" || !document.body) return;
    translationObserverStarted = true;
    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            if (mutation.type === "characterData") {
                translateTextNode(mutation.target);
                return;
            }
            if (mutation.type === "attributes") {
                translateElementAttributes(mutation.target);
                return;
            }
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType === Node.TEXT_NODE) {
                    translateTextNode(node);
                } else if (node.nodeType === Node.ELEMENT_NODE) {
                    translateTree(node);
                }
            });
        });
    });
    observer.observe(document.body, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: [
            "placeholder",
            "title",
            "aria-label",
            "alt",
            "data-bs-original-title",
            "value",
        ],
    });
}

/** Humanized order status labels (backend values unchanged) */
const ORDER_STATUS_LABELS = {
    Draft: "Draft",
    Submitted: "Submitted",
    Approved: "Approved",
    InTransitToWarehouse: "In Transit",
    ReceivedAtWarehouse: "Received",
    AwaitingCustomerConfirmation: "Legacy Awaiting Confirmation",
    CustomerDeclined: "Customer Declined",
    CustomerDeclinedAfterAutoConfirm: "Declined After Auto-Confirm",
    Confirmed: "Confirmed",
    ReadyForConsolidation: "Ready for Consolidation",
    ConsolidatedIntoShipmentDraft: "In Shipment Draft",
    AssignedToContainer: "Assigned to Container",
    FinalizedAndPushedToTracking: "Finalized",
};
function statusLabel(s) {
    if (!s) return t("—");
    const localized = uiStatuses()[s];
    if (localized) return localized;
    return t(ORDER_STATUS_LABELS[s] || s);
}
function statusBadgeClass(s) {
    const map = {
        Draft: "status-draft",
        Submitted: "status-submitted",
        Approved: "status-approved",
        InTransitToWarehouse: "status-in-transit",
        ReceivedAtWarehouse: "status-received",
        AwaitingCustomerConfirmation: "status-awaiting",
        CustomerDeclined: "status-declined",
        CustomerDeclinedAfterAutoConfirm: "status-declined",
        Confirmed: "status-confirmed",
        ReadyForConsolidation: "status-ready",
        ConsolidatedIntoShipmentDraft: "status-consolidated",
        AssignedToContainer: "status-assigned",
        FinalizedAndPushedToTracking: "status-finalized",
    };
    return (s && map[s]) || "status-draft";
}
function orderHasPendingCustomerReview(order) {
    return !!String(order?.confirmation_token || "").trim();
}
function orderIsOperationallyConfirmed(order) {
    return (order?.status || "") === "Confirmed" && !orderHasPendingCustomerReview(order);
}
function orderIsShipmentEligible(order) {
    const status = order?.status || "";
    return status === "ReadyForConsolidation" || orderIsOperationallyConfirmed(order);
}
function formatDisplayNumber(value, options = {}) {
    const {
        maxDecimals = 2,
        minDecimals = 0,
        useGrouping = false,
    } = options || {};
    if (value === null || value === undefined || value === "") return "";
    const num = Number(value);
    if (!Number.isFinite(num)) {
        return String(value);
    }
    const safeMax = Math.max(0, Number(maxDecimals) || 0);
    const safeMin = Math.max(0, Math.min(safeMax, Number(minDecimals) || 0));
    const epsilon = safeMax > 0 ? Math.pow(10, -safeMax) / 2 : 0.5;
    const normalized = Math.abs(num) < epsilon ? 0 : num;
    return normalized.toLocaleString(undefined, {
        minimumFractionDigits: safeMin,
        maximumFractionDigits: safeMax,
        useGrouping: !!useGrouping,
    });
}
function formatDisplayAmount(value, options = {}) {
    return formatDisplayNumber(value, {
        maxDecimals: 2,
        ...options,
    });
}
function formatDisplayCbm(value, maxDecimals = 6, options = {}) {
    return formatDisplayNumber(value, {
        maxDecimals,
        ...options,
    });
}
function formatDisplayWeight(value, maxDecimals = 2, options = {}) {
    return formatDisplayNumber(value, {
        maxDecimals,
        ...options,
    });
}
function formatDisplayPercent(value, maxDecimals = 1, options = {}) {
    return formatDisplayNumber(value, {
        maxDecimals,
        ...options,
    });
}
function formatDisplayQuantity(value, maxDecimals = 4, options = {}) {
    return formatDisplayNumber(value, {
        maxDecimals,
        ...options,
    });
}
function isSearchLikeInput(el) {
    if (!el) return false;
    const type = (el.getAttribute("type") || "").toLowerCase();
    if (type === "search") return true;
    const haystack = [
        el.name,
        el.id,
        el.className,
        el.getAttribute("placeholder"),
        el.getAttribute("aria-label"),
    ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();
    return /(search|filter|query|lookup)/.test(haystack);
}
function isElementVisible(el) {
    if (!el) return false;
    return !!(
        el.offsetWidth ||
        el.offsetHeight ||
        el.getClientRects?.().length
    );
}
function isEnterNavigationDisabled(el) {
    return !!el?.closest(
        "[data-enter-submit='true'], .js-allow-enter-submit, [data-enter-navigation='off']",
    );
}
function getEnterNavigationScope(el) {
    return (
        el.closest("[data-enter-scope]") ||
        el.closest(".modal.show .modal-content") ||
        el.closest(".offcanvas.show") ||
        el.closest("form") ||
        el.closest(".card, .panel, .accordion-item") ||
        document
    );
}
function isNavigableEnterField(el) {
    if (!(el instanceof HTMLElement)) return false;
    if (!isElementVisible(el)) return false;
    if (el.matches("textarea, button, [type='button'], [type='submit'], [type='reset'], [type='hidden'], [type='file'], [type='checkbox'], [type='radio']")) {
        return false;
    }
    if (el.matches("[disabled], [readonly], [aria-disabled='true']")) {
        return false;
    }
    if (isSearchLikeInput(el)) return false;
    if (isEnterNavigationDisabled(el)) return false;
    if (el.closest(".autocomplete-dropdown")) return false;
    return el.matches("input, select, [contenteditable='true']");
}
function getNextEnterField(current) {
    const scope = getEnterNavigationScope(current);
    const fields = Array.from(
        scope.querySelectorAll("input, select, textarea, [contenteditable='true'], button"),
    ).filter(isNavigableEnterField);
    const currentIndex = fields.indexOf(current);
    if (currentIndex === -1) return null;
    return fields[currentIndex + 1] || null;
}
function focusNextEnterField(current) {
    const next = getNextEnterField(current);
    if (!next) return false;
    next.focus({ preventScroll: false });
    if (typeof next.select === "function" && next.matches("input:not([type='date']):not([type='time']):not([type='datetime-local'])")) {
        next.select();
    }
    return true;
}
function closeActiveModalOrPanel(source) {
    const modalEl = source?.closest?.(".modal");
    if (modalEl && typeof bootstrap !== "undefined" && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(modalEl).hide();
        return true;
    }
    const collapseToggle = source?.closest?.("[data-collapse-target]");
    const targetSelector = collapseToggle?.getAttribute?.("data-collapse-target");
    if (targetSelector) {
        const target = document.querySelector(targetSelector);
        if (target && typeof bootstrap !== "undefined" && bootstrap.Collapse) {
            bootstrap.Collapse.getOrCreateInstance(target).hide();
            return true;
        }
    }
    return false;
}
/** Description language: 'en' or 'cn'. Stored in localStorage. Default 'en'. */
function descLang() {
    return (
        (typeof localStorage !== "undefined" &&
            localStorage.getItem("clms_desc_lang")) ||
        (uiLocale() === "zh-CN" ? "cn" : "en")
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
    window.uiLocale = uiLocale;
    window.t = t;
    window.statusLabel = statusLabel;
    window.statusBadgeClass = statusBadgeClass;
    window.API_BASE = API_BASE;
    window.descLang = descLang;
    window.descText = descText;
    window.setDescLang = setDescLang;
    window.orderHasPendingCustomerReview = orderHasPendingCustomerReview;
    window.orderIsOperationallyConfirmed = orderIsOperationallyConfirmed;
    window.orderIsShipmentEligible = orderIsShipmentEligible;
    window.formatDisplayNumber = formatDisplayNumber;
    window.formatDisplayAmount = formatDisplayAmount;
    window.formatDisplayCbm = formatDisplayCbm;
    window.formatDisplayWeight = formatDisplayWeight;
    window.formatDisplayPercent = formatDisplayPercent;
    window.formatDisplayQuantity = formatDisplayQuantity;
    window.closeActiveModalOrPanel = closeActiveModalOrPanel;
    window.translateTree = translateTree;
}

if (typeof window !== "undefined") {
    if (typeof localStorage !== "undefined") {
        localStorage.setItem("clms_ui_lang", uiLocale());
        localStorage.setItem("clms_desc_lang", uiLocale() === "zh-CN" ? "cn" : "en");
    }
    if (!window.__clmsTranslatedDialogsPatched) {
        const originalConfirm = window.confirm?.bind(window);
        const originalAlert = window.alert?.bind(window);
        if (originalConfirm) {
            window.confirm = function (message) {
                return originalConfirm(t(String(message)));
            };
        }
        if (originalAlert) {
            window.alert = function (message) {
                return originalAlert(t(String(message)));
            };
        }
        window.__clmsTranslatedDialogsPatched = true;
    }
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
    const translatedMessage = t(message);
    const toast = document.createElement("div");
    toast.className = `toast align-items-center text-bg-${type} border-0`;
    toast.setAttribute("role", "alert");
    toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${escapeHtml(translatedMessage)}</div>
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

document.addEventListener("keydown", function (e) {
    if (
        e.key !== "Enter" ||
        e.defaultPrevented ||
        e.isComposing ||
        e.shiftKey ||
        e.ctrlKey ||
        e.altKey ||
        e.metaKey
    ) {
        return;
    }

    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.matches("textarea")) return;
    if (!isNavigableEnterField(target)) return;
    if (target.closest("[data-enter-submit='true'], .js-allow-enter-submit")) return;

    const next = getNextEnterField(target);
    if (!next) return;

    e.preventDefault();
    focusNextEnterField(target);
});

document.addEventListener("DOMContentLoaded", function () {
    translateTree(document);
    startUiTranslationObserver();
    document.documentElement.lang = uiLocale();
    document.title = t(document.title);
});
