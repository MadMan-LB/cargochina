let receivePhotoPaths = [];
let receiveOrderItems = [];
let receiveItemPhotos = {};
let warehouseQueueData = [];
let calMonth = new Date().getMonth();
let calYear = new Date().getFullYear();

document.addEventListener("DOMContentLoaded", () => {
    setupFilterAutocomplete();
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
function setupReceiveOrderSearch() {
    const searchEl = document.getElementById("receiveOrderSearch");
    const idEl = document.getElementById("receiveOrderId");
    const form = document.getElementById("receiveForm");
    if (!searchEl || !idEl) return;
    if (typeof Autocomplete === "undefined") return;
    Autocomplete.init(searchEl, {
        resource: "receiving",
        searchPath: "/search",
        placeholder:
            "Search: #ID, customer, supplier, phone, shipping code, items — verify then enter actuals",
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
            cbmEl.value = ((l * w * h) / 1000000).toFixed(4);
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
        const enabled = res.data?.item_level_receiving_enabled ?? 0;
        const section = document.getElementById("itemLevelSection");
        if (section) section.classList.toggle("d-none", !enabled);
    } catch (_) {
        document.getElementById("itemLevelSection")?.classList.add("d-none");
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
                if (btn) btn.textContent = `Uploading ${i}/${total}…`;
            },
        );
        paths.forEach((p) => {
            if (p && !receivePhotoPaths.includes(p)) receivePhotoPaths.push(p);
        });
        renderReceivePhotoPreview();
        updateVariancePhotoAlert();
    } catch (e) {
        showToast("Upload failed: " + (e.message || "Unknown error"), "danger");
    } finally {
        setLoading(btn, false);
        if (btn) btn.textContent = "Add Photo";
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

function getFilterParams() {
    const params = new URLSearchParams();
    const s = document.getElementById("filterSupplierId")?.value;
    const c = document.getElementById("filterCustomerId")?.value;
    const df = document.getElementById("filterDateFrom")?.value;
    const dt = document.getElementById("filterDateTo")?.value;
    const sc = document.getElementById("filterShippingCode")?.value?.trim();
    if (s) params.set("supplier_id", s);
    if (c) params.set("customer_id", c);
    if (df) params.set("date_from", df);
    if (dt) params.set("date_to", dt);
    if (sc) params.set("shipping_code", sc);
    return params.toString();
}

async function applyFilters() {
    const listEl = document.getElementById("warehouseList");
    const emptyEl = document.getElementById("warehouseListEmpty");
    const applyBtn = document.getElementById("applyFiltersBtn");
    try {
        if (listEl) listEl.classList.add("opacity-50");
        if (applyBtn) applyBtn.disabled = true;
        const qs = getFilterParams();
        const res = await api("GET", "/receiving/queue?" + qs);
        warehouseQueueData = res.data || [];
        renderWarehouseList();
        renderReceiveDropdown();
        renderCalendar();
        renderSchedule();
        if (emptyEl)
            emptyEl.classList.toggle("d-none", warehouseQueueData.length > 0);
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        if (listEl) listEl.classList.remove("opacity-50");
        if (applyBtn) applyBtn.disabled = false;
    }
}

function exportReceivingCsv() {
    try {
        const rows = warehouseQueueData || [];
        const headers = [
            "Order ID",
            "Customer",
            "Supplier",
            "Supplier Phone",
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
                    '"' + (o.supplier_phone || "").replace(/"/g, '""') + '"',
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
        showToast("Exported " + rows.length + " orders");
    } catch (e) {
        showToast(e.message, "danger");
    }
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
            const productAlerts = [
                ...new Set(
                    items
                        .map((i) => (i.product_high_alert_note || "").trim())
                        .filter(Boolean),
                ),
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
                <h6 class="mb-0">#${o.id} — ${escapeHtml(o.customer_name)}${o.customer_priority_level && o.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escapeHtml(o.customer_priority_note || "")}">${escapeHtml(o.customer_priority_level)}</span>` : ""}</h6>
                <span class="badge ${typeof statusBadgeClass === "function" ? statusBadgeClass(o.status) : "bg-secondary"}">${typeof statusLabel === "function" ? statusLabel(o.status) : escapeHtml(o.status)}</span>
              </div>
              <div class="small text-muted mb-2">${escapeHtml(o.expected_ready_date)}</div>
              ${o.high_alert_notes ? `<div class="alert alert-danger py-2 px-2 small mb-2"><strong>High alert:</strong> ${escapeHtml(o.high_alert_notes)}</div>` : ""}
              ${
                  productAlerts.length
                      ? `<div class="product-alert-inline mb-2"><strong>Product alerts:</strong> ${productAlerts
                            .map((note) => escapeHtml(note))
                            .join(" | ")}</div>`
                      : ""
              }
              <dl class="row mb-0 small">
                <dt class="col-5">Supplier</dt><dd class="col-7">${escapeHtml(o.supplier_name || "-")}</dd>
                <dt class="col-5">Supplier phone</dt><dd class="col-7">${escapeHtml(o.supplier_phone || "-")}</dd>
                <dt class="col-5">Shipping code</dt><dd class="col-7">${escapeHtml(shippingCodes || "-")}</dd>
                <dt class="col-5">Cartons</dt><dd class="col-7">${totalCartons}</dd>
                <dt class="col-5">CBM / Weight</dt><dd class="col-7">${parseFloat(o.declared_cbm || 0).toFixed(4)} / ${parseFloat(o.declared_weight || 0)} kg</dd>
              </dl>
              ${items.length ? `<div class="mt-2 pt-2 border-top"><small class="text-muted">Items:</small> ${items.map((it) => `<span class="badge bg-light text-dark me-1">${escapeHtml(it.shipping_code || "—")} ${it.cartons || 0}ctn ${it.qty_per_carton || ""}/ctn HS:${escapeHtml(it.hs_code || "-")}${it.product_high_alert_note ? " ALERT" : ""}</span>`).join("")}</div>` : ""}
              <div class="mt-2 pt-2">
                <button type="button" class="btn btn-sm btn-primary js-receive-btn" data-order-id="${o.id}">Receive</button>
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
}

function renderCalendar() {
    const grid = document.getElementById("calendarGrid");
    const label = document.getElementById("calMonthLabel");
    if (!grid || !label) return;
    const monthNames = [
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
    label.textContent = `${monthNames[calMonth]} ${calYear}`;
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
    let html =
        "<div class='cal-day-header'>Sun</div><div class='cal-day-header'>Mon</div><div class='cal-day-header'>Tue</div><div class='cal-day-header'>Wed</div><div class='cal-day-header'>Thu</div><div class='cal-day-header'>Fri</div><div class='cal-day-header'>Sat</div>";
    for (let i = 0; i < startPad; i++) {
        const d = prevDays - startPad + i + 1;
        html += `<div class="cal-day other-month"><span class="cal-day-num">${d}</span></div>`;
    }
    for (let d = 1; d <= daysInMonth; d++) {
        const orders = byDate[d] || [];
        html += `<div class="cal-day"><span class="cal-day-num">${d}</span>${orders.map((o) => `<div class="cal-order" title="#${o.id} ${o.customer_name}">#${o.id} ${escapeHtml(o.customer_name)}</div>`).join("")}</div>`;
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
              .map(
                  (d) => `
      <div class="mb-3">
        <h6 class="text-primary border-bottom pb-1">${d}</h6>
        ${byDate[d]
            .map(
                (o) => `
          <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
            <div class="small">#${o.id} — ${escapeHtml(o.customer_name)}</div>
            <div class="small text-muted">${escapeHtml(o.supplier_name || "-")} · ${parseFloat(o.declared_cbm || 0).toFixed(2)} CBM</div>
            <button type="button" class="btn btn-sm btn-outline-primary js-receive-btn" data-order-id="${o.id}">Receive</button>
          </div>
        `,
            )
            .join("")}
      </div>
    `,
              )
              .join("")
        : '<p class="text-muted">No shipments in filtered range.</p>';
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
    btn.setAttribute(
        "aria-expanded",
        tbl.classList.contains("d-none") ? "false" : "true",
    );
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
        if (acbm) acbm.placeholder = "Direct or from L×W×H";
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
    el.textContent = `${cartons} cartons, ${cbm.toFixed(4)} CBM, ${weight.toFixed(2)} kg. Items: ${itemsStr || "—"}`;

    // Set declared values as placeholders on Actual inputs so user can verify before entering
    const ac = document.getElementById("actualCartons");
    const acbm = document.getElementById("actualCbm");
    const aw = document.getElementById("actualWeight");
    if (ac) ac.placeholder = String(cartons || "");
    if (acbm)
        acbm.placeholder = cbm > 0 ? cbm.toFixed(4) : "Direct or from L×W×H";
    if (aw) aw.placeholder = weight > 0 ? weight.toFixed(2) : "";
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
                (it, i) => `
          <tr data-order-item-id="${it.id}">
            <td>${escapeHtml((typeof descText === "function" ? descText(it) : it.description_en || it.description_cn || "Item " + (i + 1)).substring(0, 40))}${it.product_high_alert_note ? `<div class="product-alert-badge mt-1" title="${escapeHtml(it.product_high_alert_note)}">Alert</div>` : ""}</td>
            <td>${it.declared_cbm || 0} CBM / ${it.declared_weight || 0} kg</td>
            <td><input type="number" class="form-control form-control-sm item-actual-cartons" min="0" placeholder="0"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-actual-cbm" min="0" placeholder="0"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-actual-weight" min="0" placeholder="0"></td>
            <td><select class="form-select form-select-sm item-condition"><option value="good">Good</option><option value="damaged">Damaged</option><option value="partial">Partial</option></select></td>
            <td><input type="file" class="d-none item-photo-input" accept="image/*" multiple data-order-item-id="${it.id}"><button type="button" class="btn btn-sm btn-outline-secondary item-add-photo">+</button><div class="item-photo-preview d-inline"></div></td>
          </tr>`,
            )
            .join("");
        tbody.querySelectorAll(".item-add-photo").forEach((btn) => {
            const row = btn.closest("tr");
            const oiId = row.dataset.orderItemId;
            const input = row.querySelector(".item-photo-input");
            input.onchange = async (e) => {
                const files = Array.from(e.target.files || []).filter((f) =>
                    f.type.startsWith("image/"),
                );
                for (const f of files) {
                    try {
                        const path = await uploadFile(f);
                        if (path) {
                            receiveItemPhotos[oiId] =
                                receiveItemPhotos[oiId] || [];
                            receiveItemPhotos[oiId].push(path);
                        }
                    } catch (err) {
                        showToast(err.message, "danger");
                    }
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
                `<span class="d-inline-block me-1"><img src="/cargochina/backend/${p}" class="img-thumbnail" style="max-width:40px"><button type="button" class="btn-close btn-close-sm" onclick="removeItemPhoto('${orderItemId}',${i})"></button></span>`,
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
        showToast("Select an order", "danger");
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
            "Enter Actual CBM directly or L/H/W (cm) to calculate",
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
            "Evidence photos required when variance or damage is present",
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
            const aCbm = parseFloat(
                row.querySelector(".item-actual-cbm")?.value || 0,
            );
            const aWeight = parseFloat(
                row.querySelector(".item-actual-weight")?.value || 0,
            );
            const itCond =
                row.querySelector(".item-condition")?.value || "good";
            const itPhotos = receiveItemPhotos[it.id] || [];
            if (aCartons > 0 || aCbm > 0 || aWeight > 0 || itPhotos.length) {
                items.push({
                    order_item_id: it.id,
                    actual_cartons: aCartons || null,
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
                ? "Received — customer confirmation required"
                : "Received successfully",
        );
        loadReceivableOrders();
        document.getElementById("receiveForm").classList.add("d-none");
        document.getElementById("receiveOrderSearch").value = "";
        document.getElementById("receiveOrderId").value = "";
        receivePhotoPaths = [];
        receiveItemPhotos = {};
        renderReceivePhotoPreview();
    } catch (e) {
        showToast(e.message || "Request failed", "danger");
    } finally {
        setLoading(submitBtn, false);
    }
}
