let receivePhotoPaths = [];
let receiveOrderItems = [];
let receiveItemPhotos = {};
let warehouseQueueData = [];
let warehouseQueueSourceData = [];
let calMonth = new Date().getMonth();
let calYear = new Date().getFullYear();
const RECEIVING_DEFAULT_STATUSES = ["Approved", "InTransitToWarehouse"];

function receivingT(text, replacements = null) {
    return typeof t === "function" ? t(text, replacements) : text;
}

function fmtReceivingNumber(value, maxDecimals = 6) {
    if (typeof window.formatDisplayNumber === "function") {
        return window.formatDisplayNumber(value, { maxDecimals }) || "0";
    }
    const numeric = parseFloat(value);
    return Number.isFinite(numeric) ? String(numeric) : "0";
}

function setReceivingMetric(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function orderHasPriority(order) {
    return (
        order?.customer_priority_level &&
        String(order.customer_priority_level).toLowerCase() !== "normal"
    );
}

function itemHasReceivingAlert(item) {
    return (
        (item?.product_required_design &&
            String(item.product_required_design) !== "0") ||
        String(item?.product_high_alert_note || "").trim() !== ""
    );
}

function orderHasReceivingAlert(order) {
    if (String(order?.high_alert_notes || "").trim()) return true;
    return (order?.items || []).some(itemHasReceivingAlert);
}

function getSelectedReceivingStatuses() {
    return Array.from(
        document.querySelectorAll(".receiving-status-filter:checked"),
    ).map((el) => el.value);
}

function isDefaultReceivingStatusSelection(
    statuses = getSelectedReceivingStatuses(),
) {
    if (statuses.length !== RECEIVING_DEFAULT_STATUSES.length) return false;
    const selected = new Set(statuses.map(String));
    return RECEIVING_DEFAULT_STATUSES.every((status) => selected.has(status));
}

function setReceivingStatusFilter(statuses = RECEIVING_DEFAULT_STATUSES) {
    const selected = new Set((statuses || []).map(String));
    document.querySelectorAll(".receiving-status-filter").forEach((el) => {
        el.checked = selected.has(el.value);
    });
}

function getReceivingFocusFilters() {
    return {
        priorityOnly: !!document.getElementById("filterPriorityOnly")?.checked,
        alertsOnly: !!document.getElementById("filterAlertsOnly")?.checked,
    };
}

function updateReceivingStatusSummary() {
    const summaryEl = document.getElementById("receiveStatusSummary");
    if (!summaryEl) return;

    const statuses = getSelectedReceivingStatuses();
    const { priorityOnly, alertsOnly } = getReceivingFocusFilters();
    const focus = [];
    if (priorityOnly) focus.push(receivingT("priority accounts only"));
    if (alertsOnly) focus.push(receivingT("high-alert orders only"));

    let text;
    if (!statuses.length) {
        text = receivingT("No intake statuses selected.");
    } else {
        const labels = statuses
            .map((status) =>
                typeof statusLabel === "function" ? statusLabel(status) : status,
            )
            .join(", ");
        text = isDefaultReceivingStatusSelection(statuses)
            ? receivingT("Core intake statuses: {labels}.", { labels })
            : receivingT("Statuses: {labels}.", { labels });
    }

    if (focus.length) {
        text += ` ${receivingT("Focus: {focus}.", { focus: focus.join(", ") })}`;
    }

    summaryEl.textContent = text;
}

function countActiveReceivingFilters() {
    let count = [
        document.getElementById("filterSupplierId")?.value,
        document.getElementById("filterCustomerId")?.value,
        document.getElementById("filterDateFrom")?.value,
        document.getElementById("filterDateTo")?.value,
        document.getElementById("filterShippingCode")?.value?.trim(),
    ].filter(Boolean).length;

    if (!isDefaultReceivingStatusSelection()) count += 1;
    const { priorityOnly, alertsOnly } = getReceivingFocusFilters();
    if (priorityOnly) count += 1;
    if (alertsOnly) count += 1;
    return count;
}

function updateReceivingOverview() {
    const rows = warehouseQueueData || [];
    const visibleCount = rows.length;
    const priorityCount = rows.filter(orderHasPriority).length;
    const totalCartons = rows.reduce(
        (sum, order) =>
            sum +
            (order.items || []).reduce(
                (itemSum, item) => itemSum + (parseInt(item.cartons, 10) || 0),
                0,
            ),
        0,
    );
    const totalCbm = rows.reduce(
        (sum, order) => sum + (parseFloat(order.declared_cbm) || 0),
        0,
    );
    const alertCount = rows.filter(orderHasReceivingAlert).length;
    const uniqueSuppliers = new Set(
        rows.map((order) => order.supplier_name).filter(Boolean),
    ).size;
    const uniqueDates = new Set(
        rows.map((order) => order.expected_ready_date).filter(Boolean),
    ).size;
    const activeFilters = countActiveReceivingFilters();

    setReceivingMetric("receiveVisibleCount", String(visibleCount));
    setReceivingMetric("receivePriorityCount", String(priorityCount));
    setReceivingMetric("receiveCartonCount", String(totalCartons));
    setReceivingMetric("receiveAlertCount", String(alertCount));

    setReceivingMetric(
        "receiveVisibleDetail",
        visibleCount
            ? receivingT("{suppliers} suppliers across {dates} planned dates.", {
                  suppliers: uniqueSuppliers,
                  dates: uniqueDates,
              })
            : receivingT("No orders match the current filters."),
    );
    setReceivingMetric(
        "receivePriorityDetail",
        priorityCount
            ? receivingT("{count} orders come from flagged customer accounts.", {
                  count: priorityCount,
              })
            : receivingT("No priority customers in the current queue."),
    );
    setReceivingMetric(
        "receiveCartonDetail",
        visibleCount
            ? receivingT("{cbm} declared CBM across the visible queue.", {
                  cbm:
                      typeof formatDisplayCbm === "function"
                          ? formatDisplayCbm(totalCbm, 2)
                          : totalCbm.toFixed(2),
              })
            : receivingT("Carton totals will appear once orders are visible."),
    );
    setReceivingMetric(
        "receiveAlertDetail",
        alertCount
            ? receivingT("{count} orders need extra handling attention.", {
                  count: alertCount,
              })
            : receivingT("No high-alert receiving notes in this view."),
    );
    setReceivingMetric(
        "receiveFilterSummary",
        visibleCount
            ? activeFilters
                ? receivingT(
                      "Showing {orders} order(s) with {cartons} cartons after {filters} active filter(s).",
                      {
                          orders: visibleCount,
                          cartons: totalCartons,
                          filters: activeFilters,
                      },
                  )
                : receivingT("Showing {orders} order(s) with {cartons} cartons.", {
                      orders: visibleCount,
                      cartons: totalCartons,
                  })
            : activeFilters
              ? receivingT("No orders match the active receiving filters.")
              : receivingT("Showing the full inbound receiving queue."),
    );
}

document.addEventListener("DOMContentLoaded", () => {
    registerUnsavedChangesGuard?.("#receiveForm");
    setupFilterAutocomplete();
    setupReceivingFilterControls();
    setReceivingStatusFilter(RECEIVING_DEFAULT_STATUSES);
    updateReceivingStatusSummary();
    loadReceivingConfig();
    applyFilters();
    const handleReceiveClick = (e) => {
        const btn = e.target.closest(".js-receive-btn");
        if (btn) receiveOrderById(parseInt(btn.dataset.orderId, 10));
    };
    document
        .getElementById("warehouseList")
        ?.addEventListener("click", handleReceiveClick);
    document
        .getElementById("scheduleList")
        ?.addEventListener("click", handleReceiveClick);
    const input = document.getElementById("receivePhotos");
    if (input) input.onchange = () => handleReceivePhotos(input.files);
    bindClipboardImagePaste?.(
        document.getElementById("photoPreview") ||
            document.getElementById("receiveForm"),
        async (files) => {
            await handleReceivePhotos(files);
        },
    );
    bindClipboardImagePaste?.(
        document.getElementById("itemLevelBody"),
        async (files, event) => {
            const row = event?.target?.closest?.("tr[data-order-item-id]");
            if (!row) return;
            const orderItemId = row.dataset.orderItemId;
            await appendReceiveItemPhotos(orderItemId, files);
            renderItemPhotoPreview(orderItemId);
        },
        {
            requireTargetMatch: true,
            targetMatcher: (target) =>
                !!target.closest("tr[data-order-item-id]"),
        },
    );
    document
        .getElementById("actualCbm")
        ?.addEventListener("input", updateVariancePhotoAlert);
    document
        .getElementById("condition")
        ?.addEventListener("change", updateVariancePhotoAlert);
    setupReceiveDimensionInputs();
    document.getElementById("calPrev")?.addEventListener("click", () => {
        calMonth--;
        if (calMonth < 0) {
            calMonth = 11;
            calYear--;
        }
        renderCalendar();
    });
    document.getElementById("calNext")?.addEventListener("click", () => {
        calMonth++;
        if (calMonth > 11) {
            calMonth = 0;
            calYear++;
        }
        renderCalendar();
    });
    document.querySelectorAll('[data-bs-toggle="tab"]').forEach((t) =>
        t.addEventListener("shown.bs.tab", (e) => {
            if (e.target.getAttribute("href") === "#tabCalendar")
                renderCalendar();
            if (e.target.getAttribute("href") === "#tabSchedule")
                renderSchedule();
        }),
    );
    setupReceiveOrderSearch();
});

function setupReceivingFilterControls() {
    document.querySelectorAll(".receiving-status-filter").forEach((el) => {
        el.addEventListener("change", () => {
            updateReceivingStatusSummary();
            applyFilters();
        });
    });
    document.querySelectorAll(".receiving-focus-filter").forEach((el) => {
        el.addEventListener("change", () => {
            updateReceivingStatusSummary();
            applyLocalReceivingFilters();
        });
    });
    ["filterDateFrom", "filterDateTo"].forEach((id) => {
        document.getElementById(id)?.addEventListener("change", applyFilters);
    });
    document
        .getElementById("filterShippingCode")
        ?.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                applyFilters();
            }
        });
}

function setupReceiveOrderSearch() {
    const searchEl = document.getElementById("receiveOrderSearch");
    const idEl = document.getElementById("receiveOrderId");
    const form = document.getElementById("receiveForm");
    if (!searchEl || !idEl) return;
    if (typeof Autocomplete === "undefined") return;
    Autocomplete.init(searchEl, {
        resource: "receiving",
        searchPath: "/search",
        placeholder: receivingT(
            "Search: #ID, customer, supplier, phone, shipping code, items — verify then enter actuals",
        ),
        renderItem: (o) => {
            const items = (o.items || [])
                .map((i) => `${i.shipping_code || "-"} ${i.cartons || 0}ctn`)
                .join("; ");
            const base = `#${o.id} — ${o.customer_name || ""} — ${o.supplier_name || ""} — ${o.expected_ready_date || ""}`;
            return items ? `${base} | ${items}` : base.replace(/\s*—\s*$/g, "");
        },
        onSelect: (item) => {
            idEl.value = String(item.id);
            searchEl.dataset.declaredCbm = String(item.declared_cbm || 0);
            searchEl.dataset.declaredWeight = String(item.declared_weight || 0);
            form.classList.remove("d-none");
            updateVariancePhotoAlert();
            loadOrderForReceive(item.id);
        },
    });
    searchEl.addEventListener("input", () => {
        if (!searchEl.value.trim()) {
            idEl.value = "";
            delete searchEl.dataset.declaredCbm;
            delete searchEl.dataset.declaredWeight;
            form.classList.add("d-none");
            receiveOrderItems = [];
            receiveItemPhotos = {};
            updateDeclaredSummary(null);
        }
    });
}

function setupReceiveDimensionInputs() {
    const cbmEl = document.getElementById("actualCbm");
    const lEl = document.getElementById("actualLength");
    const wEl = document.getElementById("actualWidth");
    const hEl = document.getElementById("actualHeight");
    if (!cbmEl || !lEl || !wEl || !hEl) return;

    const calcCbmFromLwh = () => {
        const l = parseFloat(lEl.value) || 0;
        const w = parseFloat(wEl.value) || 0;
        const h = parseFloat(hEl.value) || 0;
        if (l > 0 && w > 0 && h > 0) {
            const cbm = Math.round(((l * w * h) / 1000000) * 1e6) / 1e6;
            cbmEl.value = fmtReceivingNumber(cbm, 6);
            updateVariancePhotoAlert();
        }
    };
    [lEl, wEl, hEl].forEach((el) =>
        el.addEventListener("input", calcCbmFromLwh),
    );

    cbmEl.addEventListener("input", () => {
        if (parseFloat(cbmEl.value) > 0) {
            lEl.value = wEl.value = hEl.value = "";
        }
    });
}

async function loadReceivingConfig() {
    try {
        const res = await api("GET", "/config/receiving");
        const section = document.getElementById("itemLevelSection");
        if (section) {
            section.classList.remove("d-none");
            section.dataset.itemLevelRequired = String(
                res.data?.item_level_receiving_enabled ?? 0,
            );
        }
    } catch (_) {
        document.getElementById("itemLevelSection")?.classList.remove("d-none");
    }
}

async function handleReceivePhotos(files) {
    const filesArr = Array.from(files || []).filter((f) =>
        f.type.startsWith("image/"),
    );
    if (!filesArr.length) return;
    const btn = document.getElementById("receiveAddPhotoBtn");
    const input = document.getElementById("receivePhotos");
    try {
        setLoading(btn, true);
        const paths = await PHOTO_UPLOADER.uploadPhotos(
            filesArr,
            (i, total) => {
                if (btn) btn.textContent = receivingT("Uploading {current}/{total}…", { current: i, total });
            },
        );
        paths.forEach((p) => {
            if (p && !receivePhotoPaths.includes(p)) receivePhotoPaths.push(p);
        });
        renderReceivePhotoPreview();
        updateVariancePhotoAlert();
    } catch (e) {
        showToast(
            receivingT("Upload failed: {message}", {
                message: e.message || receivingT("Unknown error"),
            }),
            "danger",
        );
    } finally {
        setLoading(btn, false);
        if (btn) btn.textContent = receivingT("Add Photo");
    }
    if (input) input.value = "";
}

function renderReceivePhotoPreview() {
    const container = document.getElementById("photoPreview");
    if (!container) return;
    PHOTO_UPLOADER.previewPhotos(
        container,
        receivePhotoPaths,
        "removeReceivePhoto",
    );
}

function removeReceivePhoto(index) {
    receivePhotoPaths.splice(index, 1);
    renderReceivePhotoPreview();
    updateVariancePhotoAlert();
}

function updateVariancePhotoAlert() {
    const alertEl = document.getElementById("variancePhotoAlert");
    if (!alertEl) return;
    const orderId = document.getElementById("receiveOrderId")?.value;
    if (!orderId) {
        alertEl.classList.add("d-none");
        return;
    }
    const searchEl = document.getElementById("receiveOrderSearch");
    const declaredCbm = parseFloat(searchEl?.dataset.declaredCbm || 0);
    const actualCbm = parseFloat(
        document.getElementById("actualCbm")?.value || 0,
    );
    const condition = document.getElementById("condition")?.value || "good";
    const variancePct =
        declaredCbm > 0
            ? (Math.abs(actualCbm - declaredCbm) / declaredCbm) * 100
            : 0;
    const varianceAbs = Math.abs(actualCbm - declaredCbm);
    const hasVariance =
        variancePct >= 10 || varianceAbs >= 0.1 || condition !== "good";
    const needsPhoto = hasVariance && receivePhotoPaths.length === 0;
    alertEl.classList.toggle("d-none", !needsPhoto);
}

function setupFilterAutocomplete() {
    const supInput = document.getElementById("filterSupplier");
    const supId = document.getElementById("filterSupplierId");
    const custInput = document.getElementById("filterCustomer");
    const custId = document.getElementById("filterCustomerId");
    if (!supInput || !custInput) return;
    if (typeof Autocomplete === "undefined") return;
    Autocomplete.init(supInput, {
        resource: "suppliers",
        placeholder: receivingT("Type to search supplier..."),
        onSelect: (item) => {
            if (supId) supId.value = item.id;
            applyFilters();
        },
    });
    supInput.addEventListener("input", () => {
        if (!supInput.value.trim() && supId) supId.value = "";
    });
    Autocomplete.init(custInput, {
        resource: "customers",
        placeholder: receivingT("Type to search customer..."),
        renderItem: (c) =>
            `${c.name || ""} — ${c.code || ""}`
                .replace(/^ — | — $/g, "")
                .trim() || `#${c.id}`,
        onSelect: (item) => {
            if (custId) custId.value = item.id;
            applyFilters();
        },
    });
    custInput.addEventListener("input", () => {
        if (!custInput.value.trim() && custId) custId.value = "";
    });
}

function getFilterParams() {
    const params = new URLSearchParams();
    const s = document.getElementById("filterSupplierId")?.value;
    const c = document.getElementById("filterCustomerId")?.value;
    const df = document.getElementById("filterDateFrom")?.value;
    const dt = document.getElementById("filterDateTo")?.value;
    const sc = document.getElementById("filterShippingCode")?.value?.trim();
    const statuses = getSelectedReceivingStatuses();
    if (statuses.length) {
        statuses.forEach((status) => params.append("status[]", status));
    } else {
        params.append("status[]", "__none__");
    }
    if (s) params.set("supplier_id", s);
    if (c) params.set("customer_id", c);
    if (df) params.set("date_from", df);
    if (dt) params.set("date_to", dt);
    if (sc) params.set("shipping_code", sc);
    return params.toString();
}

async function appendReceiveItemPhotos(orderItemId, files) {
    const list = Array.from(files || []).filter((f) =>
        (f.type || "").startsWith("image/"),
    );
    if (!list.length) return;
    for (const file of list) {
        const path = await uploadFile(file);
        if (path) {
            receiveItemPhotos[orderItemId] = receiveItemPhotos[orderItemId] || [];
            receiveItemPhotos[orderItemId].push(path);
        }
    }
}

function applyLocalReceivingFilters() {
    const { priorityOnly, alertsOnly } = getReceivingFocusFilters();
    warehouseQueueData = (warehouseQueueSourceData || []).filter((order) => {
        if (priorityOnly && !orderHasPriority(order)) return false;
        if (alertsOnly && !orderHasReceivingAlert(order)) return false;
        return true;
    });

    renderWarehouseList();
    renderReceiveDropdown();
    renderCalendar();
    renderSchedule();
    updateReceivingOverview();
    document
        .getElementById("warehouseListEmpty")
        ?.classList.toggle("d-none", warehouseQueueData.length > 0);
}

async function applyFilters() {
    const listEl = document.getElementById("warehouseList");
    const applyBtn = document.getElementById("applyFiltersBtn");
    try {
        if (listEl) listEl.classList.add("opacity-50");
        if (applyBtn) applyBtn.disabled = true;
        const qs = getFilterParams();
        const res = await api("GET", "/receiving/queue?" + qs);
        warehouseQueueSourceData = res.data || [];
        applyLocalReceivingFilters();
    } catch (e) {
        warehouseQueueSourceData = [];
        warehouseQueueData = [];
        renderWarehouseList();
        renderCalendar();
        renderSchedule();
        updateReceivingOverview();
        document
            .getElementById("warehouseListEmpty")
            ?.classList.remove("d-none");
        showToast(e.message, "danger");
    } finally {
        if (listEl) listEl.classList.remove("opacity-50");
        if (applyBtn) applyBtn.disabled = false;
    }
}

function clearReceivingFilters() {
    [
        "filterSupplier",
        "filterCustomer",
        "filterDateFrom",
        "filterDateTo",
        "filterShippingCode",
    ].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = "";
    });
    ["filterSupplierId", "filterCustomerId"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.value = "";
    });
    setReceivingStatusFilter(RECEIVING_DEFAULT_STATUSES);
    ["filterPriorityOnly", "filterAlertsOnly"].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.checked = false;
    });
    updateReceivingStatusSummary();
    applyFilters();
}

function exportReceivingQueue(format = "xlsx") {
    const params = new URLSearchParams(getFilterParams());
    params.set("format", format);
    window.location.href =
        `${window.API_BASE || "/cargochina/api/v1"}/receiving/export/queue?` +
        params.toString();
}

function exportReceivingXlsx() {
    exportReceivingQueue("xlsx");
}

function exportReceivingCsv() {
    exportReceivingQueue("csv");
}

function renderWarehouseList() {
    const container = document.getElementById("warehouseList");
    if (!container) return;
    container.innerHTML = warehouseQueueData
        .map((o) => {
            const items = o.items || [];
            const shippingCodes = [
                ...new Set(items.map((i) => i.shipping_code).filter(Boolean)),
            ].join(", ");
            function itemAlertText(it) {
                return (
                    (it.product_required_design
                        ? `${receivingT("Required design.")} `
                        : "") +
                    (it.product_high_alert_note || "")
                ).trim();
            }
            const productAlerts = [
                ...new Set(items.map((i) => itemAlertText(i)).filter(Boolean)),
            ];
            const totalCartons = items.reduce(
                (s, i) => s + (parseInt(i.cartons) || 0),
                0,
            );
            return `
        <div class="col-12 col-md-6 col-lg-4">
          <div class="card warehouse-record-card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <h6 class="mb-0">#${o.id} — ${escapeHtml(o.customer_name)}${o.customer_priority_level && o.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escapeHtml(o.customer_priority_note || "")}">${escapeHtml(typeof statusLabel === "function" ? statusLabel(o.customer_priority_level) : receivingT(o.customer_priority_level))}</span>` : ""}</h6>
                <span class="badge ${typeof statusBadgeClass === "function" ? statusBadgeClass(o.status) : "bg-secondary"}">${typeof statusLabel === "function" ? statusLabel(o.status) : escapeHtml(o.status)}</span>
              </div>
              <div class="small text-muted mb-2">${escapeHtml(o.expected_ready_date)}</div>
              ${o.high_alert_notes ? `<div class="alert alert-danger py-2 px-2 small mb-2"><strong>${escapeHtml(receivingT("High Alert:"))}</strong> ${escapeHtml(o.high_alert_notes)}</div>` : ""}
              ${
                  productAlerts.length
                      ? `<div class="product-alert-inline mb-2"><strong>${escapeHtml(receivingT("Product alerts:"))}</strong> ${productAlerts
                            .map((note) => escapeHtml(note))
                            .join(" | ")}</div>`
                      : ""
              }
              <dl class="row mb-0 small">
                <dt class="col-5">${escapeHtml(receivingT("Supplier"))}</dt><dd class="col-7">${escapeHtml(o.supplier_name || "-")}</dd>
                <dt class="col-5">${escapeHtml(receivingT("Supplier phone"))}</dt><dd class="col-7">${escapeHtml(o.supplier_phone || "-")}</dd>
                <dt class="col-5">${escapeHtml(receivingT("Shipping Code"))}</dt><dd class="col-7">${escapeHtml(shippingCodes || "-")}</dd>
                <dt class="col-5">${escapeHtml(receivingT("Cartons"))}</dt><dd class="col-7">${totalCartons}</dd>
                <dt class="col-5">${escapeHtml(receivingT("CBM / Weight"))}</dt><dd class="col-7">${typeof formatDisplayCbm === "function" ? formatDisplayCbm(parseFloat(o.declared_cbm || 0), 6) : parseFloat(o.declared_cbm || 0).toFixed(6)} / ${typeof formatDisplayWeight === "function" ? formatDisplayWeight(parseFloat(o.declared_weight || 0), 2) : parseFloat(o.declared_weight || 0)} kg</dd>
              </dl>
              ${items.length ? `<div class="mt-2 pt-2 border-top"><small class="text-muted">${escapeHtml(receivingT("Items"))}:</small> ${items.map((it) => `<span class="badge bg-light text-dark me-1">${escapeHtml(it.shipping_code || "—")} ${it.cartons || 0}ctn ${it.qty_per_carton || ""}/ctn HS:${escapeHtml(it.hs_code || "-")}${it.product_high_alert_note || it.product_required_design ? ` ${escapeHtml(receivingT("ALERT"))}` : ""}</span>`).join("")}</div>` : ""}
              <div class="mt-2 pt-2">
                <button type="button" class="btn btn-sm btn-primary js-receive-btn" data-order-id="${o.id}">${escapeHtml(receivingT("Receive"))}</button>
              </div>
            </div>
          </div>
        </div>`;
        })
        .join("");
}

function renderReceiveDropdown() {
    // Receiving no longer uses a dropdown; order selection is via search autocomplete
}

function receiveOrderById(orderId) {
    const order = warehouseQueueData.find((o) => o.id == orderId);
    const searchEl = document.getElementById("receiveOrderSearch");
    const idEl = document.getElementById("receiveOrderId");
    const form = document.getElementById("receiveForm");
    if (!searchEl || !idEl) return;
    let dcbm = 0,
        dw = 0,
        display = `#${orderId}`;
    if (order) {
        dcbm = (order.items || []).reduce(
            (s, i) => s + (parseFloat(i.declared_cbm) || 0),
            0,
        );
        dw = (order.items || []).reduce(
            (s, i) => s + (parseFloat(i.declared_weight) || 0),
            0,
        );
        display =
            `#${order.id} — ${order.customer_name || ""} — ${order.supplier_name || ""} — ${order.expected_ready_date || ""}`.replace(
                /\s*—\s*$/g,
                "",
            );
    }
    searchEl.value = display;
    searchEl.dataset.selectedId = String(orderId);
    searchEl.dataset.declaredCbm = String(dcbm);
    searchEl.dataset.declaredWeight = String(dw);
    idEl.value = String(orderId);
    form?.classList.remove("d-none");
    form?.scrollIntoView({ behavior: "smooth", block: "start" });
    updateVariancePhotoAlert();
    loadOrderForReceive(orderId);
}

function showReceiveForm() {
    document.getElementById("receiveForm")?.classList.remove("d-none");
    refreshUnsavedBaseline?.(document.getElementById("receiveForm"));
}

function renderCalendar() {
    const grid = document.getElementById("calendarGrid");
    const label = document.getElementById("calMonthLabel");
    if (!grid || !label) return;
    const isZh = typeof uiLocale === "function" && uiLocale() === "zh-CN";
    const monthNames = isZh
        ? [
              "1月",
              "2月",
              "3月",
              "4月",
              "5月",
              "6月",
              "7月",
              "8月",
              "9月",
              "10月",
              "11月",
              "12月",
          ]
        : [
              "Jan",
              "Feb",
              "Mar",
              "Apr",
              "May",
              "Jun",
              "Jul",
              "Aug",
              "Sep",
              "Oct",
              "Nov",
              "Dec",
          ];
    label.textContent = isZh
        ? `${calYear}年 ${monthNames[calMonth]}`
        : `${monthNames[calMonth]} ${calYear}`;
    const first = new Date(calYear, calMonth, 1);
    const last = new Date(calYear, calMonth + 1, 0);
    const startPad = first.getDay();
    const daysInMonth = last.getDate();
    const prevMonth = new Date(calYear, calMonth, 0);
    const prevDays = prevMonth.getDate();
    const byDate = {};
    warehouseQueueData.forEach((o) => {
        const d = o.expected_ready_date;
        if (!d) return;
        const [y, m] = d.split("-").map(Number);
        if (y === calYear && m === calMonth + 1) {
            const day = parseInt(d.split("-")[2], 10);
            if (!byDate[day]) byDate[day] = [];
            byDate[day].push(o);
        }
    });
    const dayHeaders = isZh
        ? ["日", "一", "二", "三", "四", "五", "六"]
        : ["Sun", "Mon", "Tue", "Wed", "Thu", "Fri", "Sat"];
    let html = dayHeaders
        .map((day) => `<div class='cal-day-header'>${day}</div>`)
        .join("");
    const today = new Date();
    const todayYear = today.getFullYear();
    const todayMonth = today.getMonth();
    const todayDate = today.getDate();
    for (let i = 0; i < startPad; i++) {
        const d = prevDays - startPad + i + 1;
        html += `<div class="cal-day other-month"><span class="cal-day-num">${d}</span></div>`;
    }
    for (let d = 1; d <= daysInMonth; d++) {
        const orders = byDate[d] || [];
        const isToday =
            calYear === todayYear && calMonth === todayMonth && d === todayDate;
        html += `<div class="cal-day${isToday ? " today" : ""}"><span class="cal-day-num">${d}</span>${orders.map((o) => `<div class="cal-order" title="#${o.id} ${o.customer_name}">#${o.id} ${escapeHtml(o.customer_name)}</div>`).join("")}</div>`;
    }
    const remaining = 42 - startPad - daysInMonth;
    for (let i = 0; i < remaining; i++) {
        html += `<div class="cal-day other-month"><span class="cal-day-num">${i + 1}</span></div>`;
    }
    grid.innerHTML = html;
}

function renderSchedule() {
    const container = document.getElementById("scheduleList");
    if (!container) return;
    const byDate = {};
    warehouseQueueData.forEach((o) => {
        const d = o.expected_ready_date || "-";
        if (!byDate[d]) byDate[d] = [];
        byDate[d].push(o);
    });
    const dates = Object.keys(byDate).sort();
    container.innerHTML = dates.length
        ? dates
              .map((d) => {
                  const dayOrders = byDate[d];
                  const dayCartons = dayOrders.reduce(
                      (sum, order) =>
                          sum +
                          (order.items || []).reduce(
                              (itemSum, item) =>
                                  itemSum + (parseInt(item.cartons, 10) || 0),
                              0,
                          ),
                      0,
                  );
                  const dayCbm = dayOrders.reduce(
                      (sum, order) =>
                          sum + (parseFloat(order.declared_cbm) || 0),
                      0,
                  );
                  return `
      <div class="filter-toolbar-card">
        <div class="filter-toolbar-head">
          <div>
            <h6>${d}</h6>
                <div class="filter-toolbar-subtext">${escapeHtml(receivingT("{orders} order(s) · {cartons} cartons · {cbm} CBM", {
                    orders: dayOrders.length,
                    cartons: dayCartons,
                    cbm:
                        typeof formatDisplayCbm === "function"
                            ? formatDisplayCbm(dayCbm, 2)
                            : dayCbm.toFixed(2),
                }))}</div>
          </div>
        </div>
        <div class="stack-card-list">
          ${dayOrders
              .map(
                  (o) => `
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 px-3 py-2 bg-white">
              <div>
                <div class="fw-semibold">#${o.id} — ${escapeHtml(o.customer_name)}</div>
                <div class="small text-muted">${escapeHtml(o.supplier_name || "-")} · ${typeof formatDisplayCbm === "function" ? formatDisplayCbm(parseFloat(o.declared_cbm || 0), 2) : parseFloat(o.declared_cbm || 0).toFixed(2)} CBM</div>
              </div>
              <button type="button" class="btn btn-sm btn-outline-primary js-receive-btn" data-order-id="${o.id}">${escapeHtml(receivingT("Receive"))}</button>
            </div>
          `,
              )
              .join("")}
        </div>
      </div>
    `;
              })
              .join("")
        : `<div class="filter-toolbar-card"><p class="text-muted mb-0">${escapeHtml(receivingT("No shipments in the filtered range."))}</p></div>`;
}

async function loadReceivableOrders() {
    try {
        const qs = getFilterParams();
        const res = await api("GET", "/receiving/queue?" + qs);
        warehouseQueueData = res.data || [];
        renderReceiveDropdown();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

document.getElementById("toggleItemLevel")?.addEventListener("click", () => {
    const tbl = document.getElementById("itemLevelTable");
    const btn = document.getElementById("toggleItemLevel");
    tbl.classList.toggle("d-none");
    const hidden = tbl.classList.contains("d-none");
    btn.setAttribute("aria-expanded", hidden ? "false" : "true");
    btn.textContent = hidden
        ? receivingT("Show item details")
        : receivingT("Hide item details");
});

function updateDeclaredSummary(order) {
    const el = document.getElementById("receiveDeclaredText");
    if (!el) return;
    if (!order) {
        el.textContent = "—";
        const ac = document.getElementById("actualCartons");
        const acbm = document.getElementById("actualCbm");
        const aw = document.getElementById("actualWeight");
        if (ac) ac.placeholder = "";
        if (acbm) acbm.placeholder = receivingT("Direct or from L×W×H");
        if (aw) aw.placeholder = "";
        return;
    }
    const items = order.items || [];
    const cartons = items.reduce((s, i) => s + (parseInt(i.cartons) || 0), 0);
    const cbm = items.reduce(
        (s, i) => s + (parseFloat(i.declared_cbm) || 0),
        0,
    );
    const weight = items.reduce(
        (s, i) => s + (parseFloat(i.declared_weight) || 0),
        0,
    );
    const itemsStr = items
        .map(
            (i) =>
                `${i.shipping_code || "-"} ${i.cartons || 0}ctn ${i.qty_per_carton || ""}/ctn`,
        )
        .join("; ");
    el.textContent = receivingT("{cartons} cartons, {cbm} CBM, {weight} kg. Items: {items}", {
        cartons: fmtReceivingNumber(cartons, 4),
        cbm: fmtReceivingNumber(cbm, 6),
        weight: fmtReceivingNumber(weight, 4),
        items: itemsStr || "—",
    });

    // Set declared values as placeholders on Actual inputs so user can verify before entering
    const ac = document.getElementById("actualCartons");
    const acbm = document.getElementById("actualCbm");
    const aw = document.getElementById("actualWeight");
    if (ac) ac.placeholder = String(cartons || "");
    if (acbm)
        acbm.placeholder = cbm > 0 ? fmtReceivingNumber(cbm, 6) : receivingT("Direct or from L×W×H");
    if (aw) aw.placeholder = weight > 0 ? fmtReceivingNumber(weight, 4) : "";
}

function formatReceiveInputNumber(value, decimals = 4) {
    const numeric = parseFloat(value);
    if (!Number.isFinite(numeric) || numeric <= 0) return "";
    if (decimals <= 0) return numeric.toFixed(0);
    return numeric
        .toFixed(decimals)
        .replace(/(\.\d*?[1-9])0+$/, "$1")
        .replace(/\.0+$/, "");
}

function getReceivingItemMetaText(item) {
    const copyNormalRaw = String(item?.copy_normal_goods || "").trim();
    const copyNormalLabel =
        copyNormalRaw.toLowerCase() === "copy"
            ? receivingT("Copy Goods")
            : copyNormalRaw.toLowerCase() === "normal"
              ? receivingT("Normal Goods")
              : copyNormalRaw;
    return [
        item?.what_brand ? `${receivingT("What Brand")}: ${item.what_brand}` : "",
        copyNormalLabel
            ? `${receivingT("Copy / Normal Goods")}: ${copyNormalLabel}`
            : "",
        item?.code ? `${receivingT("Code")}: ${item.code}` : "",
        item?.express_number
            ? `${receivingT("Express Number")}: ${item.express_number}`
            : "",
        item?.size ? `${receivingT("Size")}: ${item.size}` : "",
    ]
        .filter(Boolean)
        .join(" · ");
}

function recalcReceiveItemRow(row, source = "auto") {
    if (!row) return;
    const cartons = parseFloat(
        row.querySelector(".item-actual-cartons")?.value || 0,
    );
    const pieces = parseFloat(
        row.querySelector(".item-actual-pieces-per-carton")?.value || 0,
    );
    const qtyInput = row.querySelector(".item-actual-quantity");
    const unitInput = row.querySelector(".item-unit-price");
    const amountInput = row.querySelector(".item-total-amount");

    if (qtyInput && source !== "quantity" && cartons > 0 && pieces > 0) {
        qtyInput.value = formatReceiveInputNumber(cartons * pieces, 4);
    }

    const quantity = parseFloat(qtyInput?.value || 0);
    const unitPrice = parseFloat(unitInput?.value || 0);
    if (amountInput && source !== "amount" && quantity > 0 && unitPrice > 0) {
        amountInput.value = formatReceiveInputNumber(quantity * unitPrice, 4);
    }
    updateReceiveItemSplitTotals(row.dataset.orderItemId || row.dataset.parentOrderItemId);
}

function getReceiveItemRows(orderItemId) {
    return Array.from(
        document.querySelectorAll(
            `tr[data-order-item-id="${orderItemId}"], tr[data-parent-order-item-id="${orderItemId}"]`,
        ),
    );
}

function updateReceiveItemSplitTotals(orderItemId) {
    if (!orderItemId) return;
    const parentRow = document.querySelector(`tr[data-order-item-id="${orderItemId}"]`);
    if (!parentRow) return;
    const rows = getReceiveItemRows(orderItemId);
    const totals = rows.reduce(
        (acc, row) => {
            acc.cartons += parseFloat(row.querySelector(".item-actual-cartons")?.value || 0) || 0;
            acc.qty += parseFloat(row.querySelector(".item-actual-quantity")?.value || 0) || 0;
            acc.amount += parseFloat(row.querySelector(".item-total-amount")?.value || 0) || 0;
            return acc;
        },
        { cartons: 0, qty: 0, amount: 0 },
    );
    const target = parentRow.querySelector(".item-split-total");
    if (target) {
        target.textContent = receivingT("{cartons} cartons · {qty} pcs · {amount} amount", {
            cartons: fmtReceivingNumber(totals.cartons, 4),
            qty: fmtReceivingNumber(totals.qty, 4),
            amount: fmtReceivingNumber(totals.amount, 4),
        });
    }
    updateReceiveOrderLevelTotals();
}

function updateReceiveOrderLevelTotals() {
    const itemRows = Array.from(document.querySelectorAll("tr[data-order-item-id]"));
    if (!itemRows.length) return;
    const totals = itemRows.reduce(
        (acc, row) => {
            const orderItemId = row.dataset.orderItemId;
            getReceiveItemRows(orderItemId).forEach((line) => {
                acc.cartons += parseFloat(line.querySelector(".item-actual-cartons")?.value || 0) || 0;
            });
            acc.cbm += parseFloat(row.querySelector(".item-actual-cbm")?.value || 0) || 0;
            acc.weight += parseFloat(row.querySelector(".item-actual-weight")?.value || 0) || 0;
            return acc;
        },
        { cartons: 0, cbm: 0, weight: 0 },
    );
    const cartonsInput = document.getElementById("actualCartons");
    const cbmInput = document.getElementById("actualCbm");
    const weightInput = document.getElementById("actualWeight");
    if (cartonsInput && totals.cartons > 0) cartonsInput.value = formatReceiveInputNumber(totals.cartons, 0);
    if (cbmInput && totals.cbm > 0) cbmInput.value = formatReceiveInputNumber(totals.cbm, 6);
    if (weightInput && totals.weight > 0) weightInput.value = formatReceiveInputNumber(totals.weight, 4);
}

function bindReceiveItemCalculation(row) {
    const markDirty = () => {
        const parentId = row.dataset.parentOrderItemId;
        const target = parentId
            ? document.querySelector(`tr[data-order-item-id="${parentId}"]`)
            : row;
        if (target) target.dataset.itemDirty = "1";
        row.dataset.itemDirty = "1";
    };
    row.querySelector(".item-actual-cartons")?.addEventListener("input", () => {
        markDirty();
        recalcReceiveItemRow(row, "cartons");
    });
    row
        .querySelector(".item-actual-pieces-per-carton")
        ?.addEventListener("input", () => {
            markDirty();
            recalcReceiveItemRow(row, "pieces");
        });
    row.querySelector(".item-actual-quantity")?.addEventListener("input", () => {
        markDirty();
        recalcReceiveItemRow(row, "quantity");
    });
    row.querySelector(".item-unit-price")?.addEventListener("input", () => {
        markDirty();
        recalcReceiveItemRow(row, "unit_price");
    });
    row.querySelector(".item-total-amount")?.addEventListener("input", markDirty);
    row.querySelector(".item-actual-cbm")?.addEventListener("input", () => {
        markDirty();
        updateReceiveOrderLevelTotals();
    });
    row.querySelector(".item-actual-weight")?.addEventListener("input", () => {
        markDirty();
        updateReceiveOrderLevelTotals();
    });
    row.querySelector(".item-condition")?.addEventListener("change", markDirty);
    recalcReceiveItemRow(row);
}

function receivePackagingSplitCells(split = {}, removable = false) {
    return `
            <td><input type="number" class="form-control form-control-sm item-actual-cartons" min="0" step="1" value="${escapeHtml(formatReceiveInputNumber(split.cartons || 0, 0))}"></td>
            <td><input type="number" class="form-control form-control-sm item-actual-pieces-per-carton" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(split.pieces_per_carton || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-actual-quantity" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(split.quantity || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-unit-price" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(split.unit_price || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-total-amount" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(split.total_amount || 0, 4))}">${removable ? `<button type="button" class="btn btn-sm btn-outline-danger mt-1 item-remove-split-line">${escapeHtml(receivingT("Remove split"))}</button>` : ""}</td>`;
}

function addReceivePackagingSplitLine(orderItemId, split = {}) {
    const parentRow = document.querySelector(`tr[data-order-item-id="${orderItemId}"]`);
    if (!parentRow) return;
    const splitRow = document.createElement("tr");
    splitRow.className = "item-packaging-split-row";
    splitRow.dataset.parentOrderItemId = String(orderItemId);
    splitRow.innerHTML = `
            <td class="small text-muted ps-4">${escapeHtml(receivingT("Packaging split"))}</td>
            <td></td>
            ${receivePackagingSplitCells(split, true)}
            <td></td>
            <td></td>
            <td></td>
            <td></td>`;
    let insertAfter = parentRow;
    while (insertAfter.nextElementSibling?.dataset?.parentOrderItemId === String(orderItemId)) {
        insertAfter = insertAfter.nextElementSibling;
    }
    insertAfter.after(splitRow);
    bindReceiveItemCalculation(splitRow);
    splitRow.querySelector(".item-remove-split-line")?.addEventListener("click", () => {
        parentRow.dataset.itemDirty = "1";
        splitRow.remove();
        updateReceiveItemSplitTotals(orderItemId);
    });
    parentRow.dataset.itemDirty = "1";
    updateReceiveItemSplitTotals(orderItemId);
}

function collectReceivePackagingSplits(orderItemId) {
    return getReceiveItemRows(orderItemId)
        .map((row) => {
            const cartons = parseFloat(row.querySelector(".item-actual-cartons")?.value || 0) || 0;
            const pieces = parseFloat(row.querySelector(".item-actual-pieces-per-carton")?.value || 0) || 0;
            const quantity = parseFloat(row.querySelector(".item-actual-quantity")?.value || 0) || 0;
            const unitPrice = parseFloat(row.querySelector(".item-unit-price")?.value || 0) || 0;
            const totalAmount = parseFloat(row.querySelector(".item-total-amount")?.value || 0) || 0;
            return {
                cartons: cartons || null,
                pieces_per_carton: pieces || null,
                quantity: quantity || null,
                unit_price: unitPrice || null,
                total_amount: totalAmount || null,
            };
        })
        .filter((split) =>
            Object.values(split).some((value) => value !== null && value !== ""),
        );
}

async function loadOrderForReceive(orderId) {
    try {
        const res = await api("GET", "/orders/" + orderId);
        const order = res.data;
        receiveOrderItems = order?.items || [];
        receiveItemPhotos = {};
        updateDeclaredSummary(order);
        const tbody = document.getElementById("itemLevelBody");
        if (!tbody) return;
        tbody.innerHTML = receiveOrderItems
            .map(
                (it, i) => {
                    const metaText = getReceivingItemMetaText(it);
                    return `
          <tr data-order-item-id="${it.id}">
            <td>${escapeHtml((typeof descText === "function" ? descText(it) : it.description_en || it.description_cn || "Item " + (i + 1)).substring(0, 40))}${metaText ? `<div class="small text-muted">${escapeHtml(metaText)}</div>` : ""}${it.product_high_alert_note || it.product_required_design ? `<div class="product-alert-badge mt-1" title="${escapeHtml((it.product_required_design ? receivingT("Required design.") + " " : "") + (it.product_high_alert_note || ""))}">${escapeHtml(receivingT("Alert"))}</div>` : ""}<div class="small text-muted item-split-total mt-1"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1 item-add-split-line" data-order-item-id="${it.id}">+ ${escapeHtml(receivingT("Split"))}</button></td>
            <td>${fmtReceivingNumber(it.declared_cbm || 0, 6)} CBM / ${fmtReceivingNumber(it.declared_weight || 0, 4)} kg<br><span class="small text-muted">${fmtReceivingNumber(it.cartons || 0, 4)} ${escapeHtml(receivingT("cartons"))} × ${fmtReceivingNumber(it.qty_per_carton || 0, 4)} = ${fmtReceivingNumber(it.quantity || 0, 4)}</span></td>
            <td><input type="number" class="form-control form-control-sm item-actual-cartons" min="0" step="1" value="${escapeHtml(formatReceiveInputNumber(it.cartons || 0, 0))}" placeholder="${escapeHtml(String(it.cartons || 0))}"></td>
            <td><input type="number" class="form-control form-control-sm item-actual-pieces-per-carton" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(it.qty_per_carton || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-actual-quantity" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(it.quantity || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-unit-price" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(it.unit_price || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-total-amount" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(it.total_amount || 0, 4))}"></td>
            <td><input type="number" step="0.000001" class="form-control form-control-sm item-actual-cbm" min="0" placeholder="0"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-actual-weight" min="0" placeholder="0"></td>
            <td><select class="form-select form-select-sm item-condition"><option value="good">${escapeHtml(receivingT("Good"))}</option><option value="damaged">${escapeHtml(receivingT("Damaged"))}</option><option value="partial">${escapeHtml(receivingT("Partial"))}</option></select></td>
            <td><input type="file" class="d-none item-photo-input" accept="image/*" multiple data-order-item-id="${it.id}"><button type="button" class="btn btn-sm btn-outline-secondary item-add-photo">+</button><div class="item-photo-preview d-inline"></div></td>
          </tr>`;
                },
            )
            .join("");
        tbody.querySelectorAll("tr[data-order-item-id]").forEach((row) => {
            bindReceiveItemCalculation(row);
        });
        tbody.querySelectorAll(".item-add-split-line").forEach((btn) => {
            btn.addEventListener("click", () => {
                addReceivePackagingSplitLine(btn.dataset.orderItemId);
            });
        });
        tbody.querySelectorAll(".item-add-photo").forEach((btn) => {
            const row = btn.closest("tr");
            const oiId = row.dataset.orderItemId;
            const input = row.querySelector(".item-photo-input");
            input.onchange = async (e) => {
                try {
                    await appendReceiveItemPhotos(oiId, e.target.files || []);
                } catch (err) {
                    showToast(err.message, "danger");
                }
                renderItemPhotoPreview(oiId);
                e.target.value = "";
            };
            btn.onclick = () => input.click();
        });
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function renderItemPhotoPreview(orderItemId) {
    const row = document.querySelector(
        `tr[data-order-item-id="${orderItemId}"]`,
    );
    if (!row) return;
    const container = row.querySelector(".item-photo-preview");
    const paths = receiveItemPhotos[orderItemId] || [];
    container.innerHTML = paths
        .map(
            (p, i) =>
                `<span class="d-inline-block me-1"><a href="${typeof uploadedFileUrl === "function" ? uploadedFileUrl(p) : `/cargochina/backend/${p}`}" target="_blank" rel="noopener"><img src="${typeof uploadedThumbUrl === "function" ? uploadedThumbUrl(p, 40, 40, "cover") : `/cargochina/backend/${p}`}" class="img-thumbnail" style="max-width:40px" loading="lazy"></a><button type="button" class="btn-close btn-close-sm" onclick="removeItemPhoto('${orderItemId}',${i})"></button></span>`,
        )
        .join("");
}

function removeItemPhoto(orderItemId, index) {
    if (receiveItemPhotos[orderItemId]) {
        receiveItemPhotos[orderItemId].splice(index, 1);
        renderItemPhotoPreview(orderItemId);
    }
}

async function submitReceive() {
    const orderId = document.getElementById("receiveOrderId")?.value;
    if (!orderId) {
        showToast(receivingT("Select an order"), "danger");
        return;
    }
    const actualCartons =
        parseInt(document.getElementById("actualCartons").value) || 0;
    const cbmRaw = document.getElementById("actualCbm").value;
    const l = parseFloat(document.getElementById("actualLength")?.value) || 0;
    const w = parseFloat(document.getElementById("actualWidth")?.value) || 0;
    const h = parseFloat(document.getElementById("actualHeight")?.value) || 0;
    const actualCbm =
        parseFloat(cbmRaw) ||
        (l > 0 && w > 0 && h > 0 ? (l * w * h) / 1000000 : 0);
    const actualWeight =
        parseFloat(document.getElementById("actualWeight").value) || 0;

    if (actualCbm <= 0) {
        showToast(
            receivingT("Enter Actual CBM directly or L/H/W (cm) to calculate"),
            "danger",
        );
        return;
    }
    const condition = document.getElementById("condition").value;
    const notes = document.getElementById("receiveNotes").value;
    const photoPaths = receivePhotoPaths;

    const searchEl = document.getElementById("receiveOrderSearch");
    const declaredCbm = parseFloat(searchEl?.dataset.declaredCbm || 0);
    const declaredWeight = parseFloat(searchEl?.dataset.declaredWeight || 0);
    const variancePct =
        declaredCbm > 0
            ? (Math.abs(actualCbm - declaredCbm) / declaredCbm) * 100
            : 0;
    const varianceAbs = Math.abs(actualCbm - declaredCbm);
    const hasVariance =
        variancePct >= 10 || varianceAbs >= 0.1 || condition !== "good";
    if (hasVariance && photoPaths.length === 0) {
        showToast(
            receivingT("Evidence photos required when variance or damage is present"),
            "danger",
        );
        document
            .getElementById("variancePhotoAlert")
            ?.classList.remove("d-none");
        return;
    }

    const items = [];
    const tbody = document.getElementById("itemLevelBody");
    if (tbody && receiveOrderItems.length) {
        receiveOrderItems.forEach((it) => {
            const row = tbody.querySelector(
                `tr[data-order-item-id="${it.id}"]`,
            );
            if (!row) return;
            const aCartons = parseInt(
                row.querySelector(".item-actual-cartons")?.value || 0,
                10,
            );
            const packagingSplits = collectReceivePackagingSplits(it.id);
            const splitTotals = packagingSplits.reduce(
                (acc, split) => {
                    acc.cartons += parseFloat(split.cartons || 0) || 0;
                    acc.quantity += parseFloat(split.quantity || 0) || 0;
                    acc.amount += parseFloat(split.total_amount || 0) || 0;
                    return acc;
                },
                { cartons: 0, quantity: 0, amount: 0 },
            );
            const aCbm = parseFloat(
                row.querySelector(".item-actual-cbm")?.value || 0,
            );
            const aWeight = parseFloat(
                row.querySelector(".item-actual-weight")?.value || 0,
            );
            const aPiecesPerCarton = parseFloat(
                row.querySelector(".item-actual-pieces-per-carton")?.value || 0,
            );
            const aQuantity = parseFloat(
                row.querySelector(".item-actual-quantity")?.value || 0,
            );
            const aUnitPrice = parseFloat(
                row.querySelector(".item-unit-price")?.value || 0,
            );
            const aTotalAmount = parseFloat(
                row.querySelector(".item-total-amount")?.value || 0,
            );
            const itCond =
                row.querySelector(".item-condition")?.value || "good";
            const itPhotos = receiveItemPhotos[it.id] || [];
            const itemDirty = row.dataset.itemDirty === "1";
            if (
                itemDirty ||
                packagingSplits.length > 1 ||
                aCartons > 0 ||
                aCbm > 0 ||
                aWeight > 0 ||
                itPhotos.length
            ) {
                items.push({
                    order_item_id: it.id,
                    actual_cartons: splitTotals.cartons || aCartons || null,
                    actual_pieces_per_carton: aPiecesPerCarton || null,
                    actual_quantity: splitTotals.quantity || aQuantity || null,
                    unit_price: aUnitPrice || null,
                    total_amount: splitTotals.amount || aTotalAmount || null,
                    packaging_splits: packagingSplits,
                    actual_cbm: aCbm || null,
                    actual_weight: aWeight || null,
                    condition: itCond,
                    photo_paths: itPhotos,
                });
            }
        });
    }

    const payload = {
        actual_cartons: actualCartons,
        actual_cbm: actualCbm,
        actual_weight: actualWeight,
        condition,
        notes: notes || null,
        photo_paths: photoPaths,
    };
    if (items.length) payload.items = items;

    const submitBtn = document.getElementById("submitReceiveBtn");
    try {
        setLoading(submitBtn, true);
        const res = await api(
            "POST",
            "/orders/" + orderId + "/receive",
            payload,
        );
        showToast(
            res.data.variance_detected
                ? receivingT(
                      "Received — auto-confirmed, customer follow-up sent",
                  )
                : receivingT("Received successfully"),
        );
        loadReceivableOrders();
        document.getElementById("receiveForm").classList.add("d-none");
        document.getElementById("receiveOrderSearch").value = "";
        document.getElementById("receiveOrderId").value = "";
        receivePhotoPaths = [];
        receiveItemPhotos = {};
        renderReceivePhotoPreview();
        refreshUnsavedBaseline?.(document.getElementById("receiveForm"));
    } catch (e) {
        showToast(e.message || receivingT("Request failed"), "danger");
    } finally {
        setLoading(submitBtn, false);
    }
}
