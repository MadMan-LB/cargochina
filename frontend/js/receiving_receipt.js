/**
 * Receipt detail page
 */
const RECEIPT_ID = window.RECEIPT_ID || 0;
const API_BASE = "/cargochina/api/v1";
const AREA_BASE = "/cargochina/warehouse";

async function api(path) {
    const res = await fetch(API_BASE + path, { credentials: "same-origin" });
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

async function loadReceipt() {
    const res = await api("/receiving/receipts/" + RECEIPT_ID);
    const r = res.data;
    const statusBadge =
        r.order_status === "AwaitingCustomerConfirmation"
            ? '<span class="badge bg-warning">Confirmation required</span>'
            : '<span class="badge bg-success">' +
              escapeHtml(r.order_status) +
              "</span>";
    let itemsHtml = "";
    if (r.items && r.items.length) {
        itemsHtml = `
          <h6 class="mt-3">Per-Item Actuals</h6>
          <table class="table table-sm">
            <thead><tr><th>Item</th><th>Declared</th><th>Actual</th><th>Condition</th><th>Variance</th><th>Photos</th></tr></thead>
            <tbody>
              ${r.items
                  .map((it) => {
                      const itemPhotos = (it.photos || [])
                          .map(
                              (p) =>
                                  `<img src="/cargochina/backend/${p.file_path}" class="img-thumbnail me-1" style="max-width:48px">`,
                          )
                          .join("");
                      return `
                <tr>
                  <td>${escapeHtml(it.description_en || it.description_cn || "-")}</td>
                  <td>${it.declared_cbm || 0} CBM / ${it.declared_weight || 0} kg</td>
                  <td>${it.actual_cbm ?? "-"} CBM / ${it.actual_weight ?? "-"} kg</td>
                  <td>${escapeHtml(it.receipt_condition || it.condition || "good")}</td>
                  <td>${it.variance_detected ? '<span class="badge bg-warning">Yes</span>' : "-"}</td>
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
            '<h6 class="mt-3">Photos</h6><div class="d-flex flex-wrap gap-2">' +
            r.photos
                .map(
                    (p) =>
                        `<img src="/cargochina/backend/${p.file_path}" class="img-thumbnail" style="max-width:120px">`,
                )
                .join("") +
            "</div>";
    }
    document.getElementById("receiptContent").innerHTML = `
      <div class="card-body">
        <p><strong>Order:</strong> #${r.order_id} ${statusBadge}</p>
        <p><strong>Customer:</strong> ${escapeHtml(r.customer_name)}</p>
        <p><strong>Supplier:</strong> ${escapeHtml(r.supplier_name)}</p>
        <p><strong>Received by:</strong> ${escapeHtml(r.received_by_name || "-")}</p>
        <p><strong>Received at:</strong> ${escapeHtml(r.received_at || "-")}</p>
        <p><strong>Actual totals:</strong> ${r.actual_cartons || 0} cartons, ${parseFloat(r.actual_cbm || 0).toFixed(2)} CBM, ${parseFloat(r.actual_weight || 0).toFixed(0)} kg</p>
        <p><strong>Condition:</strong> ${escapeHtml(r.receipt_condition || r.condition || "good")}</p>
        ${itemsHtml}
        ${photosHtml}
      </div>
    `;
}

loadReceipt().catch((e) => {
    document.getElementById("receiptContent").innerHTML =
        '<div class="card-body"><p class="text-danger">' +
        escapeHtml(e.message) +
        "</p></div>";
});
