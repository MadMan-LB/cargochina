/**
 * Receive page - single order, pre-loaded from URL
 */
const ORDER_ID = window.RECEIVE_ORDER_ID || 0;
const RECEIVE_API_BASE = window.API_BASE || "/cargochina/api/v1";
const AREA_BASE = "/cargochina/warehouse";

let receivePhotoPaths = [];
let receiveOrderItems = [];
let receiveItemPhotos = {};
let declaredCbm = 0,
    declaredWeight = 0;
let pendingUploads = 0;
let receiveCurrentOrderCurrency = "USD";

function receiveT(text, replacements = null) {
    return typeof window.t === "function" ? window.t(text, replacements) : text;
}

function receiveStatusText(status) {
    return typeof window.statusLabel === "function"
        ? window.statusLabel(status)
        : receiveT(status);
}

function receiveReceiptFeeCurrency() {
    return String(receiveCurrentOrderCurrency || "USD").trim().toUpperCase() || "USD";
}

function updateReceiptFeeCurrency() {
    const currency = receiveReceiptFeeCurrency();
    const target = document.getElementById("receiptFeesCurrency");
    if (target) target.textContent = currency;
    document.querySelectorAll(".receipt-fee-currency").forEach((el) => {
        el.textContent = currency;
    });
}

function addReceiptFeeRow(fee = {}) {
    const rows = document.getElementById("receiptFeesRows");
    if (!rows) return;
    const row = document.createElement("div");
    row.className = "receipt-fee-row row g-2 align-items-end";
    row.innerHTML = `
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1">${escapeHtml(receiveT("Fee"))}</label>
            <input type="text" class="form-control form-control-sm receipt-fee-label" placeholder="${escapeHtml(receiveT("Pallet fee"))}" value="${escapeHtml(fee.label || fee.fee_label || "")}">
        </div>
        <div class="col-8 col-md-3">
            <label class="form-label small mb-1">${escapeHtml(receiveT("Amount"))} <span class="text-muted receipt-fee-currency">${escapeHtml(receiveReceiptFeeCurrency())}</span></label>
            <input type="number" class="form-control form-control-sm receipt-fee-amount" min="0" step="0.0001" placeholder="0" value="${escapeHtml(fee.amount ?? "")}">
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1">${escapeHtml(receiveT("Notes"))}</label>
            <input type="text" class="form-control form-control-sm receipt-fee-notes" placeholder="${escapeHtml(receiveT("Optional"))}" value="${escapeHtml(fee.notes || "")}">
        </div>
        <div class="col-4 col-md-1 d-grid">
            <button type="button" class="btn btn-outline-danger btn-sm receipt-fee-remove">${escapeHtml(receiveT("Remove"))}</button>
        </div>`;
    row.querySelector(".receipt-fee-remove")?.addEventListener("click", () => row.remove());
    rows.appendChild(row);
    updateReceiptFeeCurrency();
}

function resetReceiptFees() {
    const rows = document.getElementById("receiptFeesRows");
    if (!rows) return;
    rows.innerHTML = "";
    addReceiptFeeRow();
    updateReceiptFeeCurrency();
}

function collectReceiptFees() {
    const rows = Array.from(document.querySelectorAll(".receipt-fee-row"));
    const fees = [];
    for (let index = 0; index < rows.length; index++) {
        const row = rows[index];
        const label = row.querySelector(".receipt-fee-label")?.value.trim() || "";
        const notes = row.querySelector(".receipt-fee-notes")?.value.trim() || "";
        const amountRaw = row.querySelector(".receipt-fee-amount")?.value.trim() || "";
        if (!label && !notes && !amountRaw) continue;
        const amount = Number(String(amountRaw).replace(/,/g, ""));
        if (!Number.isFinite(amount)) {
            return {
                fees: [],
                error: receiveT("Fee row {row}: amount must be numeric", { row: index + 1 }),
            };
        }
        if (amount < 0) {
            return {
                fees: [],
                error: receiveT("Fee row {row}: amount must be zero or positive", { row: index + 1 }),
            };
        }
        if (amount <= 0) continue;
        fees.push({
            label: label || receiveT("Warehouse fee"),
            amount,
            currency: receiveReceiptFeeCurrency(),
            notes: notes || null,
        });
    }
    return { fees };
}

async function api(method, path, body) {
    const opts = {
        method,
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
    };
    if (body && (method === "POST" || method === "PUT"))
        opts.body = JSON.stringify(body);
    const res = await fetch(RECEIVE_API_BASE + path, opts);
    const d = await res.json().catch(() => ({}));
    if (!res.ok)
        throw new Error(receiveT(d.message || d.error?.message || "Request failed"));
    return d;
}

async function uploadFile(file) {
    return uploadFileWithProgress(file, { showToast });
}

function escapeHtml(s) {
    const d = document.createElement("div");
    d.textContent = s ?? "";
    return d.innerHTML;
}

function showToast(msg, type = "success") {
    const c =
        document.querySelector(".toast-container") ||
        (() => {
            const x = document.createElement("div");
            x.className = "toast-container position-fixed top-0 end-0 p-3";
            document.body.appendChild(x);
            return x;
        })();
    const toast = document.createElement("div");
    toast.className = `toast align-items-center text-bg-${type} border-0`;
    toast.innerHTML = `<div class="d-flex"><div class="toast-body">${escapeHtml(receiveT(msg))}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    c.appendChild(toast);
    new bootstrap.Toast(toast).show();
    toast.addEventListener("hidden.bs.toast", () => toast.remove());
}

function setLoading(el, loading) {
    if (!el) return;
    el.disabled = loading;
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

function receiveItemDimensionValue(item, actualKey, declaredKey) {
    const value = item?.[actualKey] ?? item?.[declaredKey] ?? "";
    return formatReceiveInputNumber(value, 4);
}

function receiveCalculateDimensionCbm(cartons, height, width, length) {
    const ctn = parseFloat(cartons) || 0;
    const h = parseFloat(height) || 0;
    const w = parseFloat(width) || 0;
    const l = parseFloat(length) || 0;
    if (ctn <= 0 || h <= 0 || w <= 0 || l <= 0) return 0;
    return Math.round(((h * w * l * ctn) / 1000000) * 1e6) / 1e6;
}

function receiveDeclaredWeightPerCarton(item = {}) {
    const cartons = parseFloat(item.cartons || 0) || 0;
    const weight = parseFloat(item.declared_weight || 0) || 0;
    return cartons > 0 && weight > 0 ? weight / cartons : 0;
}

function receiveDimensionInputs(item = {}) {
    return `
        <div class="input-group input-group-sm">
            <input type="number" class="form-control item-actual-height" min="0" step="0.0001" placeholder="${escapeHtml(receiveT("H"))}" title="${escapeHtml(receiveT("Height"))}" value="${escapeHtml(receiveItemDimensionValue(item, "actual_height", "item_height") || receiveItemDimensionValue(item, "actual_height", "height"))}">
            <input type="number" class="form-control item-actual-width" min="0" step="0.0001" placeholder="${escapeHtml(receiveT("W"))}" title="${escapeHtml(receiveT("Width"))}" value="${escapeHtml(receiveItemDimensionValue(item, "actual_width", "item_width") || receiveItemDimensionValue(item, "actual_width", "width"))}">
            <input type="number" class="form-control item-actual-length" min="0" step="0.0001" placeholder="${escapeHtml(receiveT("L"))}" title="${escapeHtml(receiveT("Length"))}" value="${escapeHtml(receiveItemDimensionValue(item, "actual_length", "item_length") || receiveItemDimensionValue(item, "actual_length", "length"))}">
        </div>`;
}

function formatReceiveDisplayNumber(value, decimals = 4) {
    if (typeof window.formatDisplayNumber === "function") {
        return window.formatDisplayNumber(value, { maxDecimals: decimals }) || "0";
    }
    const numeric = parseFloat(value);
    return Number.isFinite(numeric)
        ? numeric.toFixed(decimals).replace(/\.?0+$/, "")
        : "0";
}

function getReceiveItemMetaText(item) {
    const copyNormalRaw = String(item?.copy_normal_goods || "").trim();
    const copyNormalLabel =
        copyNormalRaw.toLowerCase() === "copy"
            ? receiveT("Copy Goods")
            : copyNormalRaw.toLowerCase() === "dangerous"
              ? receiveT("Dangerous Goods")
            : copyNormalRaw.toLowerCase() === "normal"
              ? receiveT("Normal Goods")
              : copyNormalRaw;
    return [
        item?.what_brand ? `${receiveT("What Brand")}: ${item.what_brand}` : "",
        copyNormalLabel
            ? `${receiveT("Good Type")}: ${copyNormalLabel}`
            : "",
        item?.code ? `${receiveT("Code")}: ${item.code}` : "",
        item?.express_number
            ? `${receiveT("Express Number")}: ${item.express_number}`
            : "",
        item?.size ? `${receiveT("Size")}: ${item.size}` : "",
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
    const cbmInput = row.querySelector(".item-actual-cbm");
    const weightPerCartonInput = row.querySelector(".item-weight-per-carton");
    const weightInput = row.querySelector(".item-actual-weight");

    if (qtyInput && source !== "quantity" && cartons > 0 && pieces > 0) {
        qtyInput.value = formatReceiveInputNumber(cartons * pieces, 4);
    }

    const quantity = parseFloat(qtyInput?.value || 0);
    const unitPrice = parseFloat(unitInput?.value || 0);
    if (amountInput && source !== "amount" && quantity > 0 && unitPrice > 0) {
        amountInput.value = formatReceiveInputNumber(quantity * unitPrice, 4);
    }
    if (cbmInput && source !== "cbm") {
        const cbm = receiveCalculateDimensionCbm(
            cartons,
            row.querySelector(".item-actual-height")?.value || 0,
            row.querySelector(".item-actual-width")?.value || 0,
            row.querySelector(".item-actual-length")?.value || 0,
        );
        if (cbm > 0) cbmInput.value = formatReceiveInputNumber(cbm, 6);
    }
    if (weightInput && source !== "weight") {
        const weightPerCarton = parseFloat(weightPerCartonInput?.value || 0) || 0;
        if (cartons > 0 && weightPerCarton > 0) {
            weightInput.value = formatReceiveInputNumber(cartons * weightPerCarton, 4);
        }
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
    const totals = getReceiveItemRows(orderItemId).reduce(
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
        target.textContent = receiveT("{cartons} cartons · {qty} pcs · {amount} amount", {
            cartons: formatReceiveDisplayNumber(totals.cartons, 4),
            qty: formatReceiveDisplayNumber(totals.qty, 4),
            amount: formatReceiveDisplayNumber(totals.amount, 4),
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
    row.querySelector(".item-weight-per-carton")?.addEventListener("input", () => {
        markDirty();
        recalcReceiveItemRow(row, "weight_per_carton");
    });
    row.querySelector(".item-actual-weight")?.addEventListener("input", () => {
        markDirty();
        updateReceiveOrderLevelTotals();
    });
    [
        ".item-actual-height",
        ".item-actual-width",
        ".item-actual-length",
    ].forEach((selector) => {
        row.querySelector(selector)?.addEventListener("input", () => {
            markDirty();
            recalcReceiveItemRow(row, "dimensions");
        });
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
            <td><input type="number" class="form-control form-control-sm item-total-amount" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(split.total_amount || 0, 4))}">${removable ? `<button type="button" class="btn btn-sm btn-outline-danger mt-1 item-remove-split-line">${escapeHtml(receiveT("Remove split"))}</button>` : ""}</td>`;
}

function addReceivePackagingSplitLine(orderItemId, split = {}) {
    const parentRow = document.querySelector(`tr[data-order-item-id="${orderItemId}"]`);
    if (!parentRow) return;
    const splitRow = document.createElement("tr");
    splitRow.className = "item-packaging-split-row";
    splitRow.dataset.parentOrderItemId = String(orderItemId);
    splitRow.innerHTML = `
            <td class="small text-muted ps-4">${escapeHtml(receiveT("Packaging split"))}</td>
            <td></td>
            ${receivePackagingSplitCells(split, true)}
            <td></td>
            <td></td>
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

async function loadOrder() {
    const res = await api("GET", "/orders/" + ORDER_ID);
    const o = res.data;
    receiveCurrentOrderCurrency = o.currency || "USD";
    resetReceiptFees();
    declaredCbm = (o.items || []).reduce(
        (s, i) => s + (parseFloat(i.declared_cbm) || 0),
        0,
    );
    declaredWeight = (o.items || []).reduce(
        (s, i) => s + (parseFloat(i.declared_weight) || 0),
        0,
    );
    let custInfoHtml = "";
    try {
        const custRes = await api("GET", "/customers/" + o.customer_id + "/lookup");
        const c = custRes.data;
        const shippingCodes = [];
        if (c.default_shipping_code) {
            shippingCodes.push(c.default_shipping_code);
        }
        (c.country_shipping || []).forEach((row) => {
            if (row.shipping_code) shippingCodes.push(row.shipping_code);
        });
        const uniqueShippingCodes = [...new Set(shippingCodes.filter(Boolean))];
        if (uniqueShippingCodes.length) {
            custInfoHtml +=
                '<p class="mb-1 small text-muted"><strong>' + escapeHtml(receiveT("Shipping codes:")) + "</strong> " +
                escapeHtml(uniqueShippingCodes.join(", ")) +
                "</p>";
        }
    } catch (e) {}
    const curSymbol = o.currency === "RMB" ? "¥" : "$";
    document.getElementById("orderOverviewBody").innerHTML = `
      <p class="mb-1"><strong>${escapeHtml(receiveT("Customer:"))}</strong> ${escapeHtml(o.customer_name)}${o.customer_priority_level && o.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escapeHtml(o.customer_priority_note || "")}">${escapeHtml(receiveStatusText(o.customer_priority_level))}</span>` : ""}</p>
      ${o.high_alert_notes ? `<div class="alert alert-danger py-2 px-3 small mt-2 mb-2"><strong>${escapeHtml(receiveT("High alert:"))}</strong> ${escapeHtml(o.high_alert_notes)}</div>` : ""}
      ${custInfoHtml}
      <p class="mb-1"><strong>${escapeHtml(receiveT("Supplier:"))}</strong> ${escapeHtml(o.supplier_name)}</p>
      <p class="mb-1"><strong>${escapeHtml(receiveT("Expected ready:"))}</strong> ${escapeHtml(o.expected_ready_date)} | <strong>${escapeHtml(receiveT("Currency:"))}</strong> ${escapeHtml(o.currency || "USD")}</p>
      <p class="mb-0"><strong>${escapeHtml(receiveT("Items:"))}</strong> ${(o.items || []).length} — ${escapeHtml(receiveT("Declared:"))} ${declaredCbm.toFixed(2)} CBM / ${declaredWeight.toFixed(0)} kg</p>
    `;
    receiveOrderItems = o.items || [];
    const configRes = await fetch(RECEIVE_API_BASE + "/config/receiving", {
        credentials: "same-origin",
    })
        .then((r) => r.json())
        .catch(() => ({}));
    const itemLevelSection = document.getElementById("itemLevelSection");
    if (itemLevelSection) {
        itemLevelSection.style.display = "block";
        itemLevelSection.dataset.itemLevelRequired = String(
            configRes.data?.item_level_receiving_enabled ?? 0,
        );
    }
    if (receiveOrderItems.length) {
        const tbody = document.getElementById("itemLevelBody");
        tbody.innerHTML = receiveOrderItems
            .map(
                (it, i) => {
                    const metaText = getReceiveItemMetaText(it);
                    return `
          <tr data-order-item-id="${it.id}">
            <td>${escapeHtml((it.description_cn || it.description_en || "Item " + (i + 1)).substring(0, 40))}${metaText ? `<div class="small text-muted">${escapeHtml(metaText)}</div>` : ""}<div class="small text-muted item-split-total mt-1"></div><button type="button" class="btn btn-sm btn-outline-primary mt-1 item-add-split-line" data-order-item-id="${it.id}">+ ${escapeHtml(receiveT("Split"))}</button></td>
            <td>${formatReceiveDisplayNumber(it.declared_cbm || 0, 6)} CBM / ${formatReceiveDisplayNumber(it.declared_weight || 0, 4)} kg<br><span class="small text-muted">${formatReceiveDisplayNumber(it.cartons || 0, 4)} ${escapeHtml(receiveT("cartons"))} × ${formatReceiveDisplayNumber(it.qty_per_carton || 0, 4)} = ${formatReceiveDisplayNumber(it.quantity || 0, 4)}</span></td>
            <td><input type="number" class="form-control form-control-sm item-actual-cartons" min="0" step="1" value="${escapeHtml(formatReceiveInputNumber(it.cartons || 0, 0))}" placeholder="${escapeHtml(String(it.cartons || 0))}"></td>
            <td><input type="number" class="form-control form-control-sm item-actual-pieces-per-carton" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(it.qty_per_carton || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-actual-quantity" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(it.quantity || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-unit-price" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(it.unit_price || 0, 4))}"></td>
            <td><input type="number" class="form-control form-control-sm item-total-amount" min="0" step="0.0001" value="${escapeHtml(formatReceiveInputNumber(it.total_amount || 0, 4))}"></td>
            <td><input type="number" step="0.000001" class="form-control form-control-sm item-actual-cbm" min="0" placeholder="${escapeHtml(formatReceiveDisplayNumber(it.declared_cbm || 0, 6))}"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-weight-per-carton" min="0" value="${escapeHtml(formatReceiveInputNumber(receiveDeclaredWeightPerCarton(it), 4))}"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-actual-weight" min="0" placeholder="${escapeHtml(formatReceiveDisplayNumber(it.declared_weight || 0, 4))}"></td>
            <td>${receiveDimensionInputs(it)}</td>
            <td><select class="form-select form-select-sm item-condition"><option value="good">${escapeHtml(receiveT("Good"))}</option><option value="damaged">${escapeHtml(receiveT("Damaged"))}</option><option value="partial">${escapeHtml(receiveT("Partial"))}</option></select></td>
            <td><input type="file" class="d-none item-photo-input" accept="image/*" multiple><button type="button" class="btn btn-sm btn-outline-secondary item-add-photo">+</button><div class="item-photo-preview d-inline"></div></td>
          </tr>
        `;
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
                const files = Array.from(e.target.files || []).filter((x) =>
                    x.type.startsWith("image/"),
                );
                pendingUploads += files.length;
                const submitBtn = document.getElementById("submitReceiveBtn");
                if (submitBtn) submitBtn.disabled = true;
                for (const f of files) {
                    try {
                        const p = await uploadFile(f);
                        if (p) {
                            receiveItemPhotos[oiId] =
                                receiveItemPhotos[oiId] || [];
                            receiveItemPhotos[oiId].push(p);
                        }
                    } catch (err) {
                        showToast(err.message, "danger");
                    }
                    pendingUploads--;
                }
                if (submitBtn) submitBtn.disabled = pendingUploads > 0;
                renderItemPhotos(oiId);
                e.target.value = "";
            };
            btn.onclick = () => input.click();
        });
    }
}

function renderItemPhotos(oiId) {
    const row = document.querySelector(`tr[data-order-item-id="${oiId}"]`);
    if (!row) return;
    const paths = receiveItemPhotos[oiId] || [];
    row.querySelector(".item-photo-preview").innerHTML = paths
        .map(
            (p, i) =>
                `<span class="d-inline-block me-1"><a href="${typeof uploadedFileUrl === "function" ? uploadedFileUrl(p) : `/cargochina/backend/${p}`}" target="_blank" rel="noopener"><img src="${typeof uploadedThumbUrl === "function" ? uploadedThumbUrl(p, 40, 40, "cover") : `/cargochina/backend/${p}`}" class="img-thumbnail" style="max-width:40px" loading="lazy"></a><button type="button" class="btn-close btn-close-sm" onclick="removeItemPhoto('${oiId}',${i})"></button></span>`,
        )
        .join("");
}

function removeItemPhoto(oiId, i) {
    if (receiveItemPhotos[oiId]) {
        receiveItemPhotos[oiId].splice(i, 1);
        renderItemPhotos(oiId);
    }
}

function updateVarianceAlert() {
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
    document
        .getElementById("variancePhotoAlert")
        .classList.toggle(
            "d-none",
            !hasVariance || receivePhotoPaths.length > 0,
        );
    const vr = document.getElementById("varianceResult");
    const vrb = document.getElementById("varianceResultBody");
    if (hasVariance) {
        vr.style.display = "block";
        vrb.innerHTML =
            `<span class="badge bg-warning">${escapeHtml(receiveT("Customer follow-up"))}</span> ${escapeHtml(receiveT("CBM/weight variance or damage detected. The order will still enter stock and a customer review link will be sent."))}`;
    } else {
        vr.style.display = "block";
        vrb.innerHTML =
            `<span class="badge bg-success">${escapeHtml(receiveT("Ready for consolidation"))}</span>`;
    }
}

document.getElementById("actualCbm")?.addEventListener("input", () => {
    updateVarianceAlert();
});
document
    .getElementById("condition")
    ?.addEventListener("change", updateVarianceAlert);

document.getElementById("receiveAddPhotoBtn").onclick = () =>
    document.getElementById("receivePhotos").click();
document.getElementById("receivePhotos").onchange = async (e) => {
    const files = Array.from(e.target.files || []).filter((f) =>
        f.type.startsWith("image/"),
    );
    const btn = document.getElementById("receiveAddPhotoBtn");
    const submitBtn = document.getElementById("submitReceiveBtn");
    pendingUploads += files.length;
    if (submitBtn) submitBtn.disabled = true;
    for (let i = 0; i < files.length; i++) {
        try {
            const p = await uploadFileWithProgress(files[i], {
                showToast,
                onProgress: (pct) => {
                    if (btn)
                        btn.textContent = receiveT("Uploading {current}/{total} ({percent}%)…", {
                            current: i + 1,
                            total: files.length,
                            percent: pct,
                        });
                },
            });
            if (p && !receivePhotoPaths.includes(p)) receivePhotoPaths.push(p);
        } catch (err) {
            showToast(err.message, "danger");
        }
        pendingUploads--;
        if (btn)
            btn.textContent = pendingUploads > 0 ? receiveT("Uploading…") : receiveT("Add Photo");
    }
    if (submitBtn) submitBtn.disabled = pendingUploads > 0;
    if (btn) btn.textContent = receiveT("Add Photo");
    e.target.value = "";
    renderPhotoPreview();
    updateVarianceAlert();
};

function renderPhotoPreview() {
    const c = document.getElementById("photoPreview");
    c.innerHTML = receivePhotoPaths
        .map(
            (p, i) =>
                `<div class="position-relative d-inline-block"><a href="${typeof uploadedFileUrl === "function" ? uploadedFileUrl(p) : `/cargochina/backend/${p}`}" target="_blank" rel="noopener"><img src="${typeof uploadedThumbUrl === "function" ? uploadedThumbUrl(p, 80, 80, "cover") : `/cargochina/backend/${p}`}" class="img-thumbnail" style="max-width:80px" loading="lazy"></a><button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="receivePhotoPaths.splice(${i},1);renderPhotoPreview();updateVarianceAlert()">×</button></div>`,
        )
        .join("");
}

document.getElementById("submitReceiveBtn").onclick = async () => {
    const actualCartons = parseInt(
        document.getElementById("actualCartons").value || 0,
    );
    const actualCbm = parseFloat(document.getElementById("actualCbm").value) || 0;
    const actualWeight = parseFloat(
        document.getElementById("actualWeight").value || 0,
    );
    if (actualCbm <= 0) {
        showToast(
            receiveT("Enter Total CBM or item dimensions to calculate it"),
            "danger",
        );
        return;
    }
    const condition = document.getElementById("condition").value;
    const notes = document.getElementById("receiveNotes").value;
    const feeResult = collectReceiptFees();
    if (feeResult.error) {
        showToast(feeResult.error, "danger");
        return;
    }
    const variancePct =
        declaredCbm > 0
            ? (Math.abs(actualCbm - declaredCbm) / declaredCbm) * 100
            : 0;
    const varianceAbs = Math.abs(actualCbm - declaredCbm);
    const hasVariance =
        variancePct >= 10 || varianceAbs >= 0.1 || condition !== "good";
    if (hasVariance && receivePhotoPaths.length === 0) {
        showToast(receiveT("Evidence photos required when variance or damage"), "danger");
        return;
    }
    const items = [];
    const tbody = document.getElementById("itemLevelBody");
    let itemValidationError = "";
    if (tbody && receiveOrderItems.length) {
        receiveOrderItems.forEach((it) => {
            if (itemValidationError) return;
            const row = tbody.querySelector(
                `tr[data-order-item-id="${it.id}"]`,
            );
            if (!row) return;
            const aCartons = parseInt(
                row.querySelector(".item-actual-cartons")?.value || 0,
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
            const aWeightPerCarton = parseFloat(
                row.querySelector(".item-weight-per-carton")?.value || 0,
            );
            const aHeight = parseFloat(
                row.querySelector(".item-actual-height")?.value || 0,
            );
            const aWidth = parseFloat(
                row.querySelector(".item-actual-width")?.value || 0,
            );
            const aLength = parseFloat(
                row.querySelector(".item-actual-length")?.value || 0,
            );
            if (aHeight < 0 || aWidth < 0 || aLength < 0 || aWeightPerCarton < 0) {
                itemValidationError = receiveT("Item dimensions and weight per carton must be zero or positive");
                return;
            }
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
            const itPhotos = receiveItemPhotos[it.id] || [];
            const itemDirty = row.dataset.itemDirty === "1";
            if (
                itemDirty ||
                packagingSplits.length > 1 ||
                aCartons > 0 ||
                aCbm > 0 ||
                aWeight > 0 ||
                aWeightPerCarton > 0 ||
                aHeight > 0 ||
                aWidth > 0 ||
                aLength > 0 ||
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
                    weight_per_carton: aWeightPerCarton || null,
                    actual_height: aHeight || null,
                    actual_width: aWidth || null,
                    actual_length: aLength || null,
                    condition:
                        row.querySelector(".item-condition")?.value || "good",
                    photo_paths: itPhotos,
                });
            }
        });
    }
    if (itemValidationError) {
        showToast(itemValidationError, "danger");
        return;
    }
    const payload = {
        actual_cartons: actualCartons,
        actual_cbm: actualCbm,
        actual_weight: actualWeight,
        condition,
        notes: notes || null,
        photo_paths: receivePhotoPaths,
    };
    if (items.length) payload.items = items;
    if (feeResult.fees.length) payload.fees = feeResult.fees;
    const btn = document.getElementById("submitReceiveBtn");
    try {
        setLoading(btn, true);
        const res = await api(
            "POST",
            "/orders/" + ORDER_ID + "/receive",
            payload,
        );
        showToast(
            res.data.variance_detected
                ? receiveT("Received — auto-confirmed, customer follow-up sent")
                : receiveT("Received successfully"),
        );
        window.location.href =
            AREA_BASE + "/receiving/receipt.php?id=" + res.data.receipt_id;
    } catch (e) {
        showToast(e.message || receiveT("Request failed"), "danger");
    } finally {
        setLoading(btn, false);
    }
};

document
    .getElementById("addReceiptFeeBtn")
    ?.addEventListener("click", () => addReceiptFeeRow());
resetReceiptFees();

loadOrder().catch((e) => {
    document.getElementById("orderOverviewBody").innerHTML =
        '<p class="text-danger">' + escapeHtml(receiveT(e.message || "Request failed")) + "</p>";
});
