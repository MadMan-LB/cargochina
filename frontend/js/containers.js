const CONTAINERS_API_BASE = window.API_BASE || "/cargochina/api/v1";

// Status display config
const CONTAINER_STATUS = {
    planning: { label: "Planning", cls: "bg-secondary" },
    to_go: { label: "To Go", cls: "bg-warning text-dark" },
    on_route: { label: "On Route", cls: "bg-primary" },
    arrived: { label: "Arrived", cls: "bg-success" },
    available: { label: "Available", cls: "bg-info text-dark" },
};

let _allContainers = [];
let _searchTimer = null;
const fmtContainerAmount = (value) =>
    typeof window.formatDisplayAmount === "function"
        ? window.formatDisplayAmount(value)
        : String(parseFloat(value || 0) || 0);
const fmtContainerCbm = (value, maxDecimals = 3) =>
    typeof window.formatDisplayCbm === "function"
        ? window.formatDisplayCbm(value, maxDecimals)
        : String(parseFloat(value || 0) || 0);
const fmtContainerWeight = (value, maxDecimals = 2) =>
    typeof window.formatDisplayWeight === "function"
        ? window.formatDisplayWeight(value, maxDecimals)
        : String(parseFloat(value || 0) || 0);
const fmtContainerQty = (value, maxDecimals = 2) =>
    typeof window.formatDisplayQuantity === "function"
        ? window.formatDisplayQuantity(value, maxDecimals)
        : String(parseFloat(value || 0) || 0);
const fmtContainerPercent = (value, maxDecimals = 1) =>
    typeof window.formatDisplayPercent === "function"
        ? window.formatDisplayPercent(value, maxDecimals)
        : String(parseFloat(value || 0) || 0);

function containerStatusDisplay(status) {
    const label = CONTAINER_STATUS[status]?.label || status || "—";
    return typeof t === "function" ? t(label) : label;
}

function getContainerDestinationCountryId(container) {
    const value = container?.destination_country_id;
    return value != null && value !== "" ? Number(value) : null;
}

function getContainerDestinationDisplay(container) {
    return (
        container?.destination_country_name ||
        container?.destination_country ||
        container?.destination ||
        (typeof t === "function" ? t("No destination") : "No destination")
    );
}

function getOrderDestinationCountryId(order) {
    const value = order?.destination_country_id;
    return value != null && value !== "" ? Number(value) : null;
}

function getOrderDestinationDisplay(order) {
    if (order?.destination_country_name) {
        return order.destination_country_code
            ? `${order.destination_country_name} (${order.destination_country_code})`
            : order.destination_country_name;
    }
    return typeof t === "function" ? t("No destination") : "No destination";
}

function filterAssignableOrdersForContainer(orders, container) {
    const destinationCountryId = getContainerDestinationCountryId(container);
    return (orders || []).filter((order) => {
        if (typeof orderIsShipmentEligible === "function" && !orderIsShipmentEligible(order)) {
            return false;
        }
        if (!destinationCountryId) return true;
        return getOrderDestinationCountryId(order) === destinationCountryId;
    });
}

function updateContainerOverview(rows) {
    const list = rows || [];
    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    };
    setText("containersTotalCount", list.length);
    setText(
        "containersPlanningCount",
        list.filter((c) => c.status === "planning").length,
    );
    setText(
        "containersHighLoadCount",
        list.filter((c) => (parseFloat(c.fill_pct_cbm) || 0) >= 85).length,
    );
    setText(
        "containersAssignedOrders",
        list.reduce((sum, c) => sum + (parseInt(c.order_count, 10) || 0), 0),
    );
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const in7 = new Date(today);
    in7.setDate(in7.getDate() + 7);
    const launchingSoon = list.filter((c) => {
        const sd = c.expected_ship_date;
        if (!sd) return false;
        const d = new Date(sd + "T00:00:00");
        return !isNaN(d.getTime()) && d >= today && d <= in7;
    }).length;
    setText("containersLaunchingSoon", launchingSoon);
}

function getSelectedContainerStatuses() {
    return Array.from(
        document.querySelectorAll(".container-status-filter:checked"),
    ).map((el) => el.value);
}

function updateContainerStatusFilterSummary() {
    const summaryEl = document.getElementById("containerStatusSummary");
    if (!summaryEl) return;
    const selected = getSelectedContainerStatuses();
    const mode =
        document.getElementById("containerStatusMode")?.value || "include";
    if (!selected.length) {
        summaryEl.textContent =
            typeof t === "function" ? t("All statuses") : "All statuses";
        return;
    }
    const prefix =
        mode === "exclude"
            ? typeof t === "function"
                ? t("Excluding")
                : "Excluding"
            : typeof t === "function"
              ? t("Including")
              : "Including";
    summaryEl.textContent = `${prefix}: ${selected
        .map(containerStatusDisplay)
        .join(", ")}`;
}

function setContainerStatusFilter(statuses = [], mode = "include") {
    const selected = new Set((statuses || []).map(String));
    document.querySelectorAll(".container-status-filter").forEach((el) => {
        el.checked = selected.has(el.value);
    });
    const modeEl = document.getElementById("containerStatusMode");
    if (modeEl) modeEl.value = mode === "exclude" ? "exclude" : "include";
    updateContainerStatusFilterSummary();
}

function debounceSearch() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(loadContainers, 200);
}

function resetFilters() {
    document.getElementById("containerSearch").value = "";
    setContainerStatusFilter([], "include");
    document.getElementById("containerFillFilter").value = "";
    loadContainers();
}

window.clearContainerStatusFilter = function () {
    setContainerStatusFilter([], "include");
    loadContainers();
};

async function loadContainers() {
    const tbody = document.getElementById("containersTbody");
    const label = document.getElementById("containerCountLabel");
    if (!tbody) return;
    tbody.innerHTML =
        `<tr><td colspan="10" class="text-center text-muted py-4">${typeof t === "function" ? t("Loading…") : "Loading…"}</td></tr>`;

    const q = document.getElementById("containerSearch")?.value?.trim() || "";
    const statuses = getSelectedContainerStatuses();
    const statusMode =
        document.getElementById("containerStatusMode")?.value || "include";
    let url = CONTAINERS_API_BASE + "/containers";
    const params = new URLSearchParams();
    if (q) params.set("q", q);
    statuses.forEach((status) => params.append("status[]", status));
    if (statuses.length) params.set("status_mode", statusMode);
    const qs = params.toString();
    if (qs) url += "?" + qs;

    try {
        const res = await fetch(url, { credentials: "same-origin" });
        if (!res.ok)
            throw new Error(
                res.status === 401
                    ? (typeof t === "function" ? t("Please log in") : "Please log in")
                    : (typeof t === "function" ? t("Failed to load containers") : "Failed to load containers"),
            );
        const data = await res.json();
        _allContainers = data.data || [];
        applyClientFilters();
    } catch (e) {
        updateContainerOverview([]);
        tbody.innerHTML = `<tr><td colspan="10" class="text-center text-danger py-4">${escHtml(e.message)}</td></tr>`;
    }
}

document.addEventListener("DOMContentLoaded", () => {
    const urlParams = new URLSearchParams(window.location.search);
    const statusFromUrl = urlParams.getAll("status[]");
    const legacyStatus = urlParams.get("status");
    const statusMode = urlParams.get("status_mode") || "include";
    if (statusFromUrl.length) {
        setContainerStatusFilter(statusFromUrl, statusMode);
    } else if (legacyStatus) {
        setContainerStatusFilter([legacyStatus], statusMode);
    } else {
        updateContainerStatusFilterSummary();
    }
    const searchInput = document.getElementById("containerSearch");
    if (searchInput && typeof Autocomplete !== "undefined") {
        Autocomplete.init(searchInput, {
            resource: "containers",
            searchPath: "/search",
            displayValue: (c) => (c?.code || ""),
            renderItem: (c) =>
                c
                    ? `${c.code || ""} — ${containerStatusDisplay(c.status)} (${c.max_cbm || 0} CBM)`
                    : "",
            placeholder:
                typeof t === "function"
                    ? t("Code, customer, phone, shipping code, item description…")
                    : "Code, customer, phone, shipping code, item description…",
            onSelect: () => loadContainers(),
        });
    }
});

function applyClientFilters() {
    const fill = document.getElementById("containerFillFilter")?.value || "";
    const tbody = document.getElementById("containersTbody");
    const label = document.getElementById("containerCountLabel");
    if (!tbody) return;

    let rows = _allContainers;
    if (fill) {
        rows = rows.filter((c) => {
            const pct = c.fill_pct_cbm || 0;
            if (fill === "empty") return pct === 0;
            if (fill === "partial") return pct > 0 && pct < 85;
            if (fill === "almost") return pct >= 85 && pct < 100;
            if (fill === "full") return pct >= 100;
            if (fill === "launching_soon") {
                const sd = c.expected_ship_date;
                if (!sd) return false;
                const d = new Date(sd + "T00:00:00");
                const today = new Date();
                today.setHours(0, 0, 0, 0);
                const in7 = new Date(today);
                in7.setDate(in7.getDate() + 7);
                return !isNaN(d.getTime()) && d >= today && d <= in7;
            }
            return true;
        });
    }

    if (label) label.textContent = rows.length ? `(${rows.length})` : "";
    updateContainerOverview(rows);

    if (rows.length === 0) {
        tbody.innerHTML =
            `<tr><td colspan="10" class="text-center text-muted py-4">${typeof t === "function" ? t("No containers match the current filters.") : "No containers match the current filters."}</td></tr>`;
        return;
    }

    tbody.innerHTML = rows
        .map((c) => {
            const st = CONTAINER_STATUS[c.status] || {
                label: c.status || "—",
                cls: "bg-secondary",
            };
            const pct = c.fill_pct_cbm || 0;
            const wPct =
                c.max_weight > 0
                    ? Math.min(100, (c.used_weight / c.max_weight) * 100)
                    : 0;
            const barC = (p) =>
                p >= 100 ? "#dc2626" : p >= 85 ? "#d97706" : "#16a34a";
            const bar = (p) =>
                `<div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;"><div style="height:100%;width:${Math.min(100, p)}%;background:${barC(p)};border-radius:3px;"></div></div><small class="text-muted">${fmtContainerCbm(c.used_cbm || 0, 2)}/${fmtContainerCbm(c.max_cbm, 2)}</small>`;
            const wBar = (p) =>
                `<div style="height:6px;background:#e2e8f0;border-radius:3px;overflow:hidden;"><div style="height:100%;width:${Math.min(100, p)}%;background:${barC(p)};border-radius:3px;"></div></div><small class="text-muted">${fmtContainerWeight(c.used_weight || 0, 0)}/${fmtContainerWeight(c.max_weight, 0)} kg</small>`;
            const destination = getContainerDestinationDisplay(c);
            const eta = c.eta_date || "";
            const shipDate = c.expected_ship_date || "";
            const vessel = c.vessel_name || "";
            const shipCell = shipDate
                ? `<button class="btn btn-link btn-sm p-0 text-start js-edit-container" data-id="${c.id}" data-code="${escHtml(c.code || "")}" title="Edit schedule">${escHtml(shipDate)}</button>${vessel ? `<div class="small text-muted">${escHtml(vessel)}</div>` : ""}`
                : `<button class="btn btn-link btn-sm p-0 text-muted js-edit-container" data-id="${c.id}" data-code="${escHtml(c.code || "")}" title="Set ship date">Set</button>`;
            return `<tr>
            <td>${c.id}</td>
            <td>
              <div class="fw-semibold">${escHtml(c.code || "")}</div>
              ${
                  destination || eta
                      ? `<div class="small text-muted">${destination ? escHtml(destination) : ""}${destination && eta ? " • " : ""}${eta ? `ETA ${escHtml(eta)}` : ""}</div>`
                      : ""
              }
            </td>
            <td>
              <span class="badge ${st.cls} me-1">${escHtml(st.label)}</span>
              <button class="btn btn-link btn-sm p-0 text-muted js-status-btn" data-id="${c.id}" title="Change status">✎</button>
            </td>
            <td class="small">${shipCell}</td>
            <td>${c.order_count || 0}</td>
            <td style="min-width:100px">${bar(pct)}</td>
            <td style="min-width:100px">${wBar(wPct)}</td>
            <td>${escHtml(String(c.max_cbm ?? ""))}</td>
            <td>${escHtml(String(c.max_weight ?? ""))}</td>
            <td class="d-flex gap-1 flex-wrap">
              <button class="btn btn-sm btn-outline-secondary js-edit-container" data-id="${c.id}" data-code="${escHtml(c.code || "")}" title="Edit container info">Edit</button>
              <button class="btn btn-sm btn-success js-assign-btn" data-id="${c.id}" data-code="${escHtml(c.code || "")}" data-max-cbm="${c.max_cbm}" data-max-weight="${c.max_weight}" data-used-cbm="${parseFloat(c.used_cbm || 0).toFixed(4)}" data-used-weight="${parseFloat(c.used_weight || 0).toFixed(2)}" title="Assign orders to this container">+ Assign</button>
              <button class="btn btn-sm btn-outline-info js-view-container" data-id="${c.id}" data-code="${escHtml(c.code || "")}" title="View orders in this container">View</button>
              <a class="btn btn-sm btn-outline-success" href="${CONTAINERS_API_BASE}/containers/${c.id}/export?format=xlsx" download title="Download XLSX">XLSX</a>
            </td>
          </tr>`;
        })
        .join("");

    // Attach event listeners
    tbody.querySelectorAll(".js-view-container").forEach((btn) => {
        btn.addEventListener("click", () =>
            viewContainer(parseInt(btn.dataset.id, 10), btn.dataset.code || ""),
        );
    });
    tbody.querySelectorAll(".js-status-btn").forEach((btn) => {
        btn.addEventListener("click", () =>
            openStatusModal(parseInt(btn.dataset.id, 10)),
        );
    });
    tbody.querySelectorAll(".js-assign-btn").forEach((btn) => {
        btn.addEventListener("click", () => openAssignOrdersModal(btn.dataset));
    });
    tbody.querySelectorAll(".js-edit-container").forEach((btn) => {
        btn.addEventListener("click", () =>
            openContainerEditModal(
                parseInt(btn.dataset.id, 10),
                btn.dataset.code || "",
            ),
        );
    });
}

function openStatusModal(containerId) {
    document.getElementById("statusModalContainerId").value = containerId;
    bootstrap.Modal.getOrCreateInstance(
        document.getElementById("statusModal"),
    ).show();
}

async function openContainerEditModal(id, code) {
    document.getElementById("containerEditId").value = id;
    const modal = bootstrap.Modal.getOrCreateInstance(
        document.getElementById("containerEditModal"),
    );
    const set = (elId, v) => {
        const el = document.getElementById(elId);
        if (el) el.value = v ?? "";
    };
    set("containerEditCode", "");
    set("containerEditMaxCbm", "");
    set("containerEditMaxWeight", "");
    set("containerEditShipDate", "");
    set("containerEditEta", "");
    set("containerEditActualDep", "");
    set("containerEditActualArr", "");
    set("containerEditVessel", "");
    set("containerEditDestCountry", "");
    set("containerEditDest", "");
    set("containerEditNotes", "");
    modal.show();
    try {
        const res = await fetch(CONTAINERS_API_BASE + "/containers/" + id, {
            credentials: "same-origin",
        });
        if (!res.ok) throw new Error("Failed to load");
        const data = (await res.json()).data || {};
        set("containerEditCode", data.code);
        set("containerEditMaxCbm", data.max_cbm);
        set("containerEditMaxWeight", data.max_weight);
        set("containerEditShipDate", data.expected_ship_date);
        set("containerEditEta", data.eta_date);
        set("containerEditActualDep", data.actual_departure_date);
        set("containerEditActualArr", data.actual_arrival_date);
        set("containerEditVessel", data.vessel_name);
        set("containerEditDestCountry", data.destination_country);
        set("containerEditDest", data.destination);
        set("containerEditNotes", data.notes);
    } catch (e) {
        if (typeof showToast === "function") {
            showToast(e.message || "Failed to load", "danger");
        } else {
            alert("Error: " + e.message);
        }
    }
}

async function saveContainerEdit() {
    const id = parseInt(document.getElementById("containerEditId").value, 10);
    if (!id) return;
    const btn = document.getElementById("containerEditSaveBtn");
    const v = (elId) => document.getElementById(elId)?.value?.trim() || null;
    const vNum = (elId) => {
        const x = parseFloat(document.getElementById(elId)?.value);
        return Number.isFinite(x) ? x : null;
    };
    const payload = {
        code: v("containerEditCode") || null,
        max_cbm: vNum("containerEditMaxCbm"),
        max_weight: vNum("containerEditMaxWeight"),
        expected_ship_date: v("containerEditShipDate") || null,
        eta_date: v("containerEditEta") || null,
        actual_departure_date: v("containerEditActualDep") || null,
        actual_arrival_date: v("containerEditActualArr") || null,
        vessel_name: v("containerEditVessel") || null,
        destination_country: v("containerEditDestCountry") || null,
        destination: v("containerEditDest") || null,
        notes: v("containerEditNotes") || null,
    };
    try {
        if (btn) btn.disabled = true;
        const res = await fetch(CONTAINERS_API_BASE + "/containers/" + id, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify(payload),
        });
        if (!res.ok) {
            const j = await res.json().catch(() => ({}));
            throw new Error(j.message || "Failed to save");
        }
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById("containerEditModal"),
        ).hide();
        loadContainers();
        if (typeof showToast === "function") {
            showToast("Container schedule updated");
        }
    } catch (e) {
        if (typeof showToast === "function") {
            showToast(e.message || "Failed to save", "danger");
        } else {
            alert("Error: " + e.message);
        }
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function setContainerStatus(status) {
    const id = parseInt(
        document.getElementById("statusModalContainerId").value,
        10,
    );
    if (!id) return;
    try {
        const res = await fetch(CONTAINERS_API_BASE + "/containers/" + id, {
            method: "PUT",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ status }),
        });
        if (!res.ok) {
            const j = await res.json().catch(() => ({}));
            throw new Error(j.message || "Failed to update status");
        }
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById("statusModal"),
        ).hide();
        // Update local data without full reload
        const c = _allContainers.find((x) => x.id === id);
        if (c) c.status = status;
        applyClientFilters();
    } catch (e) {
        alert("Error: " + e.message);
    }
}

async function viewContainer(id, code) {
    const modalEl = document.getElementById("containerViewModal");
    if (!modalEl) return;
    let modal;
    try {
        modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    } catch (e) {
        console.error(e);
        return;
    }

    const titleEl = document.getElementById("containerViewTitle");
    const subtitleEl = document.getElementById("containerViewSubtitle");
    const bodyEl = document.getElementById("containerViewBody");
    const dlBtn = document.getElementById("containerViewDownload");

    if (titleEl) titleEl.textContent = "Container: " + (code || "#" + id);
    if (subtitleEl) subtitleEl.textContent = "";
    if (bodyEl)
        bodyEl.innerHTML =
            '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    if (dlBtn)
        dlBtn.href = CONTAINERS_API_BASE + "/containers/" + id + "/export?format=xlsx";
    modal.show();

    try {
        const res = await fetch(
            CONTAINERS_API_BASE + "/containers/" + id + "/orders",
            { credentials: "same-origin" },
        );
        if (!res.ok) {
            const j = await res.json().catch(() => ({}));
            throw new Error(j.message || "Failed to load");
        }
        const data = (await res.json()).data || {};
        const container = data.container || {};
        const orders = data.orders || [];
        const drafts = data.drafts || [];
        const totals = data.totals || {};

        const st = CONTAINER_STATUS[container.status] || {
            label: container.status || "—",
            cls: "bg-secondary",
        };
        const shipInfo = container.expected_ship_date
            ? `Ship: ${container.expected_ship_date}${container.vessel_name ? ` • ${container.vessel_name}` : ""}`
            : "";
        const etaInfo = container.eta_date ? `ETA: ${container.eta_date}` : "";
        const extra = [shipInfo, etaInfo].filter(Boolean).join(" | ");
        if (subtitleEl)
            subtitleEl.innerHTML = `<span class="badge ${st.cls}">${escHtml(st.label)}</span>${extra ? ` <span class="text-muted ms-2">${escHtml(extra)}</span>` : ""}`;

        if (orders.length === 0 && drafts.length === 0) {
            if (bodyEl)
                bodyEl.innerHTML =
                    '<p class="text-muted py-3">No orders are assigned to this container yet.</p>';
            return;
        }

        const totalOrders = parseInt(totals.order_count, 10) || orders.length;
        const totalItems = parseInt(totals.item_count, 10) || 0;
        const totalCartons = parseFloat(totals.cartons || 0);
        const totalQty = parseFloat(totals.quantity || 0);
        const totalCbm = parseFloat(totals.cbm || 0);
        const totalWeight = parseFloat(totals.weight || 0);
        const totalAmt = parseFloat(totals.amount || 0);
        const maxCbm = parseFloat(container.max_cbm) || 1;
        const maxWt = parseFloat(container.max_weight) || 1;
        const cbmPct = Math.min(100, (totalCbm / maxCbm) * 100);
        const wtPct = Math.min(100, (totalWeight / maxWt) * 100);
        const barColor = (p) =>
            p >= 100 ? "#dc2626" : p >= 85 ? "#d97706" : "#16a34a";

        const orderRows = (orders || [])
            .map((o) => {
                const sBadge =
                    typeof statusBadgeClass === "function"
                        ? `<span class="badge ${statusBadgeClass(o.status)}">${escHtml(typeof statusLabel === "function" ? statusLabel(o.status) : o.status || "—")}</span>`
                        : escHtml(o.status || "—");
                return `<tr>
              <td>${o.id}</td>
              <td>${escHtml(o.customer_name || "—")}${o.customer_priority_level && o.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escHtml(o.customer_priority_note || "")}">${escHtml(o.customer_priority_level)}</span>` : ""}${o.high_alert_notes ? ` <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1" title="${escHtml(o.high_alert_notes)}">Alert</span>` : ""}</td>
              <td>${escHtml(o.supplier_name || "—")}</td>
              <td>${escHtml(o.expected_ready_date || "—")}</td>
              <td>${sBadge}</td>
              <td class="text-end">${o.items || 0}</td>
              <td class="text-end">${fmtContainerQty(o.total_ctns || 0, 2)}</td>
              <td class="text-end">${fmtContainerQty(o.total_qty || 0, 2)}</td>
              <td class="text-end">${fmtContainerCbm(o.total_cbm || 0, 3)}</td>
              <td class="text-end">${fmtContainerWeight(o.total_weight || 0, 2)} kg</td>
              <td class="text-end">${fmtContainerAmount(o.total_amount || 0)}</td>
            </tr>`;
            })
            .join("");

        if (bodyEl)
            bodyEl.innerHTML = `
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-2"><div class="order-info-stat-card"><div class="label">Orders</div><div class="value">${totalOrders}</div></div></div>
            <div class="col-12 col-md-2"><div class="order-info-stat-card"><div class="label">Items</div><div class="value">${totalItems}</div></div></div>
            <div class="col-12 col-md-2"><div class="order-info-stat-card"><div class="label">Cartons</div><div class="value">${fmtContainerQty(totalCartons, 2)}</div></div></div>
            <div class="col-12 col-md-2"><div class="order-info-stat-card"><div class="label">Quantity</div><div class="value">${fmtContainerQty(totalQty, 2)}</div></div></div>
            <div class="col-12 col-md-2"><div class="order-info-stat-card"><div class="label">CBM Used</div><div class="value">${fmtContainerCbm(totalCbm, 3)}</div><div class="small text-muted">${fmtContainerCbm(container.max_cbm, 3)} max</div></div></div>
            <div class="col-12 col-md-2"><div class="order-info-stat-card"><div class="label">Weight Used</div><div class="value">${fmtContainerWeight(totalWeight, 2)}</div><div class="small text-muted">${fmtContainerWeight(container.max_weight, 2)} kg max</div></div></div>
          </div>
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-4"><div class="order-info-stat-card"><div class="label">Sell-side amount</div><div class="value">${fmtContainerAmount(totalAmt)}</div></div></div>
          </div>
          <div class="mb-3">
            <div class="d-flex justify-content-between small text-muted mb-1"><span>CBM Fill</span><span>${fmtContainerPercent(cbmPct, 1)}%</span></div>
            <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;"><div style="height:100%;width:${cbmPct}%;background:${barColor(cbmPct)};border-radius:4px;transition:width .4s;"></div></div>
            <div class="d-flex justify-content-between small text-muted mt-2 mb-1"><span>Weight Fill</span><span>${fmtContainerPercent(wtPct, 1)}%</span></div>
            <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;"><div style="height:100%;width:${wtPct}%;background:${barColor(wtPct)};border-radius:4px;transition:width .4s;"></div></div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light"><tr>
                <th>ID</th><th>Customer</th><th>Supplier</th><th>Expected Ready</th>
                <th>Status</th><th class="text-end">Items</th><th class="text-end">Cartons</th><th class="text-end">Qty</th>
                <th class="text-end">CBM</th><th class="text-end">Weight</th><th class="text-end">Amount</th>
              </tr></thead>
              <tbody>${orderRows}</tbody>
              <tfoot class="table-light fw-semibold"><tr>
                <td colspan="5" class="text-end">Totals:</td>
                <td class="text-end">${totalItems}</td>
                <td class="text-end">${fmtContainerQty(totalCartons, 2)}</td>
                <td class="text-end">${fmtContainerQty(totalQty, 2)}</td>
                <td class="text-end">${fmtContainerCbm(totalCbm, 3)}</td>
                <td class="text-end">${fmtContainerWeight(totalWeight, 2)} kg</td>
                <td class="text-end">${fmtContainerAmount(totalAmt)}</td>
              </tr></tfoot>
            </table>
          </div>
          ${
              drafts.length > 0
                  ? `
          <div class="mt-4 pt-3 border-top">
            <h6 class="mb-2">Shipment drafts</h6>
            <p class="small text-muted mb-2">Finalized drafts linked to this container. "Push to tracking" sends shipment data to the external tracking API when you finalize. Edit carrier refs below or <a href="/cargochina/consolidation.php">manage in Consolidation</a>.</p>
            ${drafts
                .map(
                    (d) => `
            <div class="card mb-2" data-draft-id="${d.id}">
              <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-start">
                  <div>
                    <strong>Draft #${d.id}</strong>
                    <span class="badge ${d.status === "finalized" ? "bg-success" : "bg-secondary"} ms-1">${escHtml(d.status || "")}</span>
                    <span class="text-muted small ms-1">${d.order_count || 0} orders</span>
                  </div>
                  <button type="button" class="btn btn-sm btn-outline-primary js-edit-draft-refs" data-draft-id="${d.id}">Edit refs</button>
                </div>
                <div class="small text-muted mt-1">
                  ${d.container_number ? `Container: ${escHtml(d.container_number)}` : ""}
                  ${d.booking_number ? ` • Booking: ${escHtml(d.booking_number)}` : ""}
                  ${d.tracking_url ? ` • <a href="${escHtml(d.tracking_url)}" target="_blank" rel="noopener">Tracking</a>` : ""}
                  ${!d.container_number && !d.booking_number && !d.tracking_url ? "—" : ""}
                </div>
                <div class="draft-edit-form mt-2 d-none" data-draft-id="${d.id}">
                  <div class="row g-2">
                    <div class="col-4"><input type="text" class="form-control form-control-sm draft-edit-container" placeholder="Container #" value="${escHtml(d.container_number || "")}"></div>
                    <div class="col-4"><input type="text" class="form-control form-control-sm draft-edit-booking" placeholder="Booking #" value="${escHtml(d.booking_number || "")}"></div>
                    <div class="col-4"><input type="url" class="form-control form-control-sm draft-edit-tracking" placeholder="Tracking URL" value="${escHtml(d.tracking_url || "")}"></div>
                  </div>
                  <button type="button" class="btn btn-sm btn-success mt-2 js-save-draft-refs" data-draft-id="${d.id}">Save</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary mt-2 ms-1 js-cancel-draft-edit">Cancel</button>
                </div>
              </div>
            </div>
            `,
                )
                .join("")}
          </div>
          `
                  : ""
          }`;
        if (bodyEl && drafts.length > 0) {
            bodyEl.querySelectorAll(".js-edit-draft-refs").forEach((btn) => {
                btn.addEventListener("click", () => {
                    const draftId = btn.dataset.draftId;
                    const form = bodyEl.querySelector(
                        `.draft-edit-form[data-draft-id="${draftId}"]`,
                    );
                    if (form) form.classList.remove("d-none");
                });
            });
            bodyEl.querySelectorAll(".js-cancel-draft-edit").forEach((btn) => {
                btn.addEventListener("click", () => {
                    btn.closest(".draft-edit-form")?.classList.add("d-none");
                });
            });
            bodyEl.querySelectorAll(".js-save-draft-refs").forEach((btn) => {
                btn.addEventListener("click", async () => {
                    const draftId = btn.dataset.draftId;
                    const card = btn.closest("[data-draft-id]");
                    const containerNum =
                        card?.querySelector(".draft-edit-container")?.value ||
                        "";
                    const bookingNum =
                        card?.querySelector(".draft-edit-booking")?.value || "";
                    const trackingUrl =
                        card?.querySelector(".draft-edit-tracking")?.value ||
                        "";
                    try {
                        const res = await fetch(
                            CONTAINERS_API_BASE +
                                "/shipment-drafts/" +
                                draftId,
                            {
                                method: "PUT",
                                headers: {
                                    "Content-Type": "application/json",
                                },
                                credentials: "same-origin",
                                body: JSON.stringify({
                                    container_number: containerNum.trim() || null,
                                    booking_number: bookingNum.trim() || null,
                                    tracking_url: trackingUrl.trim() || null,
                                }),
                            },
                        );
                        if (!res.ok) {
                            const j = await res.json().catch(() => ({}));
                            throw new Error(j.message || "Save failed");
                        }
                        btn.closest(".draft-edit-form")?.classList.add("d-none");
                        viewContainer(id, code);
                    } catch (e) {
                        alert("Error: " + e.message);
                    }
                });
            });
        }
    } catch (e) {
        if (bodyEl)
            bodyEl.innerHTML = `<div class="alert alert-danger">${escHtml(e.message)}</div>`;
    }
}

// ---------------------------------------------------------------------------
// Assign Orders to Container modal
// ---------------------------------------------------------------------------
let _assignContainer = null;
let _assignEligibleOrders = [];

async function openAssignOrdersModal(dataset) {
    const selectedId = parseInt(dataset.id, 10);
    const fullContainer =
        _allContainers.find((container) => Number(container.id) === selectedId) ||
        {};
    _assignContainer = {
        ...fullContainer,
        id: selectedId,
        code: fullContainer.code || dataset.code || "",
        maxCbm: parseFloat(fullContainer.max_cbm ?? dataset.maxCbm) || 0,
        maxWeight:
            parseFloat(fullContainer.max_weight ?? dataset.maxWeight) || 0,
        usedCbm: parseFloat(fullContainer.used_cbm ?? dataset.usedCbm) || 0,
        usedWeight:
            parseFloat(fullContainer.used_weight ?? dataset.usedWeight) || 0,
    };

    const titleEl = document.getElementById("assignOrdersTitle");
    const subtitleEl = document.getElementById("assignOrdersSubtitle");
    const tbody = document.getElementById("assignOrdersTbody");
    if (titleEl)
        titleEl.textContent = "Assign Orders → " + _assignContainer.code;
    if (subtitleEl)
        subtitleEl.textContent = `Capacity: ${_assignContainer.maxCbm} CBM, ${_assignContainer.maxWeight} kg • Destination: ${getContainerDestinationDisplay(_assignContainer)}`;
    if (tbody)
        tbody.innerHTML =
            '<tr><td colspan="7" class="text-center text-muted py-3">Loading eligible orders…</td></tr>';
    document.getElementById("assignSelCount").textContent = "0";
    document.getElementById("assignConfirmBtn").disabled = true;
    document.getElementById("assignCapacityWarning").classList.add("d-none");

    _updateAssignBars(0, 0);
    bootstrap.Modal.getOrCreateInstance(
        document.getElementById("assignOrdersModal"),
    ).show();

    try {
        const [r1, r2] = await Promise.all([
            fetch(
                CONTAINERS_API_BASE + "/orders?status=ReadyForConsolidation",
                { credentials: "same-origin" },
            ),
            fetch(CONTAINERS_API_BASE + "/orders?status=Confirmed", {
                credentials: "same-origin",
            }),
        ]);
        const [d1, d2] = await Promise.all([r1.json(), r2.json()]);
        const raw = [...(d1.data || []), ...(d2.data || [])];
        _assignEligibleOrders = raw.map((o) => {
            const items = o.items || [];
            return {
                ...o,
                total_cbm: items.reduce(
                    (s, it) => s + (parseFloat(it.declared_cbm) || 0),
                    0,
                ),
                total_weight: items.reduce(
                    (s, it) => s + (parseFloat(it.declared_weight) || 0),
                    0,
                ),
            };
        }).filter((order) =>
            typeof orderIsShipmentEligible === "function"
                ? orderIsShipmentEligible(order)
                : true,
        );
        _renderAssignOrders();
    } catch (e) {
        if (tbody)
            tbody.innerHTML = `<tr><td colspan="7" class="text-danger py-3 text-center">${escHtml(e.message)}</td></tr>`;
    }
}

function _renderAssignOrders() {
    const tbody = document.getElementById("assignOrdersTbody");
    if (!tbody) return;
    const rows = filterAssignableOrdersForContainer(
        _assignEligibleOrders,
        _assignContainer,
    );
    if (rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="7" class="text-center text-muted py-3">${
            getContainerDestinationCountryId(_assignContainer)
                ? `No eligible orders match ${escHtml(getContainerDestinationDisplay(_assignContainer))}.`
                : "No eligible orders (must be ReadyForConsolidation or Confirmed with no pending customer review)."
        }</td></tr>`;
        return;
    }
    const statusCls =
        typeof statusBadgeClass === "function"
            ? statusBadgeClass
            : () => "bg-secondary";
    const statusLbl =
        typeof statusLabel === "function" ? statusLabel : (s) => s;
    tbody.innerHTML = rows
        .map(
            (o) => `
        <tr>
          <td><input type="checkbox" class="form-check-input assign-order-cb" data-id="${o.id}" data-cbm="${o.total_cbm}" data-weight="${o.total_weight}" onchange="_onAssignSelChange()"></td>
          <td class="fw-semibold">${o.id}</td>
          <td>${escHtml(o.customer_name || "—")}${o.customer_priority_level && o.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escHtml(o.customer_priority_note || "")}">${escHtml(o.customer_priority_level)}</span>` : ""}${o.high_alert_notes ? ` <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1" title="${escHtml(o.high_alert_notes)}">Alert</span>` : ""}<div class="small text-muted">${escHtml(getOrderDestinationDisplay(o))}</div></td>
          <td class="small text-muted">${escHtml((o.supplier_name || "—").substring(0, 22))}</td>
          <td class="text-end">${fmtContainerCbm(o.total_cbm, 3)}</td>
          <td class="text-end">${fmtContainerWeight(o.total_weight, 2)} kg</td>
          <td><span class="badge ${statusCls(o.status)}">${escHtml(statusLbl(o.status))}</span></td>
        </tr>`,
        )
        .join("");
    _onAssignSelChange();
}

function toggleAssignAll(masterCb) {
    document.querySelectorAll(".assign-order-cb").forEach((cb) => {
        cb.checked = masterCb.checked;
    });
    _onAssignSelChange();
}

function _getAssignSelected() {
    return [...document.querySelectorAll(".assign-order-cb:checked")].map(
        (cb) => ({
            id: parseInt(cb.dataset.id, 10),
            cbm: parseFloat(cb.dataset.cbm) || 0,
            weight: parseFloat(cb.dataset.weight) || 0,
        }),
    );
}

function _onAssignSelChange() {
    const sel = _getAssignSelected();
    document.getElementById("assignSelCount").textContent = sel.length;
    document.getElementById("assignConfirmBtn").disabled = sel.length === 0;

    const addCbm = sel.reduce((s, o) => s + o.cbm, 0);
    const addWeight = sel.reduce((s, o) => s + o.weight, 0);
    _updateAssignBars(addCbm, addWeight);

    const c = _assignContainer;
    if (!c) return;
    const afterCbm = c.usedCbm + addCbm;
    const afterWeight = c.usedWeight + addWeight;
    const warnEl = document.getElementById("assignCapacityWarning");
    const overCbm = afterCbm > c.maxCbm,
        overWt = afterWeight > c.maxWeight;
    if (sel.length > 0 && (overCbm || overWt)) {
        const msgs = [];
        if (overCbm) msgs.push(`CBM ${afterCbm.toFixed(2)} > ${c.maxCbm}`);
        if (overWt)
            msgs.push(
                `Weight ${afterWeight.toFixed(0)} kg > ${c.maxWeight} kg`,
            );
        warnEl.innerHTML = `<strong>⚠ Over capacity:</strong> ${msgs.join(", ")}. You will be asked to confirm.`;
        warnEl.classList.remove("d-none");
    } else {
        warnEl.classList.add("d-none");
    }
    const masterCb = document.getElementById("assignMasterCb");
    const total = document.querySelectorAll(".assign-order-cb").length;
    if (masterCb) {
        masterCb.indeterminate = sel.length > 0 && sel.length < total;
        masterCb.checked = sel.length === total && total > 0;
    }
}

function _updateAssignBars(addCbm, addWeight) {
    const c = _assignContainer;
    if (!c) return;
    const maxCbm = c.maxCbm || 1,
        maxWt = c.maxWeight || 1;
    const afterCbm = c.usedCbm + addCbm,
        afterWt = c.usedWeight + addWeight;
    const cbmPct = Math.min(120, (afterCbm / maxCbm) * 100);
    const wPct = Math.min(120, (afterWt / maxWt) * 100);
    const barC = (p) =>
        p >= 100 ? "#dc2626" : p >= 85 ? "#d97706" : "#16a34a";
    document.getElementById("assignCbmLabel").textContent =
        `${fmtContainerCbm(afterCbm, 2)} / ${fmtContainerCbm(maxCbm, 2)} CBM (${fmtContainerPercent(Math.min(120, cbmPct), 0)}%)`;
    document.getElementById("assignCbmLabel").style.color =
        cbmPct >= 100 ? "#dc2626" : "inherit";
    document.getElementById("assignCbmBar").style.cssText =
        `height:100%;width:${Math.min(100, cbmPct)}%;background:${barC(cbmPct)};border-radius:4px;`;
    document.getElementById("assignWLabel").textContent =
        `${fmtContainerWeight(afterWt, 0)} / ${fmtContainerWeight(maxWt, 0)} kg (${fmtContainerPercent(Math.min(120, wPct), 0)}%)`;
    document.getElementById("assignWLabel").style.color =
        wPct >= 100 ? "#dc2626" : "inherit";
    document.getElementById("assignWBar").style.cssText =
        `height:100%;width:${Math.min(100, wPct)}%;background:${barC(wPct)};border-radius:4px;`;
}

async function confirmAssignOrders() {
    const sel = _getAssignSelected();
    if (!sel.length || !_assignContainer) return;
    const orderIds = sel.map((o) => o.id);
    const btn = document.getElementById("assignConfirmBtn");
    btn.disabled = true;
    btn.textContent = "Assigning…";

    const doRequest = async (force) =>
        fetch(
            CONTAINERS_API_BASE +
                "/containers/" +
                _assignContainer.id +
                "/assign-orders",
            {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ order_ids: orderIds, force }),
            },
        );

    try {
        let res = await doRequest(false);
        let data = await res.json();
        if (res.status === 409 && data.over_capacity) {
            btn.disabled = false;
            btn.textContent = "Assign to Container";
            if (
                !confirm(
                    "⚠ Over capacity!\n\n" +
                        data.message +
                        "\n\nAssign anyway?",
                )
            )
                return;
            btn.disabled = true;
            btn.textContent = "Assigning…";
            res = await doRequest(true);
            data = await res.json();
        }
        if (!res.ok) throw new Error(data.message || "Failed");
        bootstrap.Modal.getOrCreateInstance(
            document.getElementById("assignOrdersModal"),
        ).hide();
        if (typeof showToast === "function")
            showToast(
                `${data.data.orders_added} order(s) assigned to ${_assignContainer.code}${data.data.over_capacity ? " (over capacity!)" : ""}`,
                data.data.over_capacity ? "warning" : "success",
            );
        loadContainers(); // refresh list
    } catch (e) {
        if (typeof showToast === "function") showToast(e.message, "danger");
        else alert(e.message);
    } finally {
        btn.disabled = false;
        btn.textContent = "Assign to Container";
    }
}

function escHtml(s) {
    if (s == null) return "";
    const d = document.createElement("div");
    d.textContent = String(s);
    return d.innerHTML;
}

document.addEventListener("DOMContentLoaded", loadContainers);
