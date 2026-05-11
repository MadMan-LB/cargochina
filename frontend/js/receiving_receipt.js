/**
 * Receipt detail page
 */
const RECEIPT_ID = window.RECEIPT_ID || 0;
const RECEIPT_API_BASE = window.API_BASE || "/cargochina/api/v1";
const AREA_BASE = "/cargochina/warehouse";

async function api(path) {
    const res = await fetch(RECEIPT_API_BASE + path, { credentials: "same-origin" });
    const d = await res.json().catch(() => ({}));
    if (!res.ok)
        throw new Error(receiptT(d.message || d.error?.message || "Request failed"));
    return d;
}

function receiptT(text, replacements = null) {
    return typeof window.t === "function" ? window.t(text, replacements) : text;
}

function receiptStatusText(status) {
    return typeof window.statusLabel === "function"
        ? window.statusLabel(status)
        : receiptT(status);
}

function escapeHtml(s) {
    const d = document.createElement("div");
    d.textContent = s ?? "";
    return d.innerHTML;
}

function receiptNumber(value, decimals = 4) {
    const numeric = parseFloat(value);
    return Number.isFinite(numeric)
        ? numeric.toFixed(decimals).replace(/\.?0+$/, "")
        : "0";
}

function receiptItemMetaText(item) {
    const copyNormalRaw = String(item?.copy_normal_goods || "").trim();
    const copyNormalLabel =
        copyNormalRaw.toLowerCase() === "copy"
            ? receiptT("Copy Goods")
            : copyNormalRaw.toLowerCase() === "normal"
              ? receiptT("Normal Goods")
              : copyNormalRaw;
    return [
        item?.what_brand ? `${receiptT("What Brand")}: ${item.what_brand}` : "",
        copyNormalLabel
            ? `${receiptT("Copy / Normal Goods")}: ${copyNormalLabel}`
            : "",
        item?.code ? `${receiptT("Code")}: ${item.code}` : "",
        item?.express_number
            ? `${receiptT("Express Number")}: ${item.express_number}`
            : "",
        item?.size ? `${receiptT("Size")}: ${item.size}` : "",
    ]
        .filter(Boolean)
        .join(" · ");
}

function receiptPackagingSplitsHtml(item) {
    const splits = Array.isArray(item?.packaging_splits)
        ? item.packaging_splits
        : [];
    if (!splits.length) {
        return `${receiptNumber(item.actual_cartons || 0, 4)} × ${receiptNumber(item.actual_pieces_per_carton || 0, 4)} = ${receiptNumber(item.actual_quantity || 0, 4)}`;
    }
    return splits
        .map(
            (split, index) =>
                `<div>${escapeHtml(receiptT("Line {line}", { line: index + 1 }))}: ${receiptNumber(split.cartons || 0, 4)} × ${receiptNumber(split.pieces_per_carton || 0, 4)} = ${receiptNumber(split.quantity || 0, 4)}${split.unit_price != null ? ` · ${receiptNumber(split.unit_price, 4)} / ${receiptNumber(split.total_amount || 0, 4)}` : ""}</div>`,
        )
        .join("");
}

async function loadReceipt() {
    const res = await api("/receiving/receipts/" + RECEIPT_ID);
    const r = res.data;
    let statusBadge =
        '<span class="badge bg-success">' +
        escapeHtml(receiptStatusText(r.order_status)) +
        "</span>";
    if (r.order_status === "AwaitingCustomerConfirmation") {
        statusBadge =
            `<span class="badge bg-secondary">${escapeHtml(receiptT("Legacy Awaiting Confirmation"))}</span>`;
    } else if (r.order_status === "Confirmed") {
        statusBadge =
            `<span class="badge bg-warning text-dark">${escapeHtml(receiptT("Auto-confirmed in stock"))}</span>`;
    } else if (r.order_status === "CustomerDeclinedAfterAutoConfirm") {
        statusBadge =
            `<span class="badge bg-danger">${escapeHtml(receiptT("Declined After Auto-Confirm"))}</span>`;
    }
    let itemsHtml = "";
    if (r.items && r.items.length) {
        itemsHtml = `
          <h6 class="mt-3">${escapeHtml(receiptT("Per-Item Actuals"))}</h6>
          <table class="table table-sm">
            <thead><tr><th>${escapeHtml(receiptT("Item"))}</th><th>${escapeHtml(receiptT("Declared"))}</th><th>${escapeHtml(receiptT("Received Qty"))}</th><th>${escapeHtml(receiptT("Price / Amount"))}</th><th>${escapeHtml(receiptT("Actual CBM / Weight"))}</th><th>${escapeHtml(receiptT("Condition"))}</th><th>${escapeHtml(receiptT("Variance"))}</th><th>${escapeHtml(receiptT("Photos"))}</th></tr></thead>
            <tbody>
              ${r.items
                  .map((it) => {
                      const metaText = receiptItemMetaText(it);
                      const itemPhotos = (it.photos || [])
                          .map(
                              (p) =>
                                  `<a href="${typeof uploadedFileUrl === "function" ? uploadedFileUrl(p.file_path) : `/cargochina/backend/${p.file_path}`}" target="_blank" rel="noopener"><img src="${typeof uploadedThumbUrl === "function" ? uploadedThumbUrl(p.file_path, 48, 48, "cover") : `/cargochina/backend/${p.file_path}`}" class="img-thumbnail me-1" style="max-width:48px" loading="lazy"></a>`,
                          )
                          .join("");
                      return `
                <tr>
                  <td>${escapeHtml(typeof descText === "function" ? descText(it) : it.description_en || it.description_cn || "-")}${metaText ? `<div class="small text-muted">${escapeHtml(metaText)}</div>` : ""}</td>
                  <td>${receiptNumber(it.declared_cbm || 0, 6)} CBM / ${receiptNumber(it.declared_weight || 0, 4)} kg<br><span class="small text-muted">${receiptNumber(it.cartons || 0, 4)} × ${receiptNumber(it.qty_per_carton || 0, 4)} = ${receiptNumber(it.quantity || 0, 4)}</span></td>
                  <td>${receiptPackagingSplitsHtml(it)}<div class="small text-muted">${escapeHtml(receiptT("Item total"))}: ${receiptNumber(it.actual_quantity || 0, 4)}</div></td>
                  <td>${it.unit_price != null ? receiptNumber(it.unit_price, 4) : "-"} / ${it.total_amount != null ? receiptNumber(it.total_amount, 4) : "-"}</td>
                  <td>${it.actual_cbm ?? "-"} CBM / ${it.actual_weight ?? "-"} kg</td>
                  <td>${escapeHtml(receiptStatusText(it.receipt_condition || it.condition || "good"))}</td>
                  <td>${it.variance_detected ? `<span class="badge bg-warning">${escapeHtml(receiptT("Yes"))}</span>` : "-"}</td>
                  <td>${itemPhotos || "-"}</td>
                </tr>
              `;
                  })
                  .join("")}
            </tbody>
          </table>
        `;
    }
    let photosHtml = "";
    if (r.photos && r.photos.length) {
        photosHtml =
            `<h6 class="mt-3">${escapeHtml(receiptT("Photos"))}</h6><div class="d-flex flex-wrap gap-2">` +
            r.photos
                .map(
                    (p) =>
                        `<a href="${typeof uploadedFileUrl === "function" ? uploadedFileUrl(p.file_path) : `/cargochina/backend/${p.file_path}`}" target="_blank" rel="noopener"><img src="${typeof uploadedThumbUrl === "function" ? uploadedThumbUrl(p.file_path, 120, 120, "cover") : `/cargochina/backend/${p.file_path}`}" class="img-thumbnail" style="max-width:120px" loading="lazy"></a>`,
                )
                .join("") +
            "</div>";
    }
    document.getElementById("receiptContent").innerHTML = `
      <div class="card-body">
        <p><strong>${escapeHtml(receiptT("Order:"))}</strong> #${r.order_id} ${statusBadge}</p>
        <p><strong>${escapeHtml(receiptT("Customer:"))}</strong> ${escapeHtml(r.customer_name)}</p>
        <p><strong>${escapeHtml(receiptT("Supplier:"))}</strong> ${escapeHtml(r.supplier_name)}</p>
        <p><strong>${escapeHtml(receiptT("Received by:"))}</strong> ${escapeHtml(r.received_by_name || "-")}</p>
        <p><strong>${escapeHtml(receiptT("Received at:"))}</strong> ${escapeHtml(r.received_at || "-")}</p>
        <p><strong>${escapeHtml(receiptT("Actual totals:"))}</strong> ${r.actual_cartons || 0} ${escapeHtml(receiptT("cartons"))}, ${parseFloat(r.actual_cbm || 0).toFixed(2)} CBM, ${parseFloat(r.actual_weight || 0).toFixed(0)} kg</p>
        <p><strong>${escapeHtml(receiptT("Condition:"))}</strong> ${escapeHtml(receiptStatusText(r.receipt_condition || r.condition || "good"))}</p>
        ${itemsHtml}
        ${photosHtml}
      </div>
    `;
}

loadReceipt().catch((e) => {
    document.getElementById("receiptContent").innerHTML =
        '<div class="card-body"><p class="text-danger">' +
        escapeHtml(receiptT(e.message || "Request failed")) +
        "</p></div>";
});
