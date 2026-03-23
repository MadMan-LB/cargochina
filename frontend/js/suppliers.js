document.addEventListener("DOMContentLoaded", () => {
    loadSuppliers();
    document
        .getElementById("supplierSearch")
        ?.addEventListener("keydown", (e) => {
            if (e.key === "Enter") applySupplierFilters();
        });
    ["supplierPaymentFilter", "supplierSort", "supplierOrder"].forEach((id) => {
        document
            .getElementById(id)
            ?.addEventListener("change", applySupplierFilters);
    });
});

let additionalIdIndex = 0;

function isBuyer() {
    const card = document.querySelector(".card[data-is-buyer]");
    return card?.dataset?.isBuyer === "1";
}

function getSupplierParams() {
    const params = new URLSearchParams();
    const q = document.getElementById("supplierSearch")?.value?.trim();
    const payment = document
        .getElementById("supplierPaymentFilter")
        ?.value?.trim();
    const sort = document.getElementById("supplierSort")?.value || "name";
    const order = document.getElementById("supplierOrder")?.value || "asc";
    if (q) params.set("q", q);
    if (payment) params.set("payment_status", payment);
    params.set("sort", sort);
    params.set("order", order);
    return params.toString();
}

function applySupplierFilters() {
    loadSuppliers();
}

async function loadSuppliers() {
    const btn = document.getElementById("supplierApplyBtn");
    try {
        if (btn) btn.disabled = true;
        const qs = getSupplierParams();
        const res = await api("GET", "/suppliers" + (qs ? "?" + qs : ""));
        const rows = res.data || [];
        const tbody = document.querySelector("#suppliersTable tbody");
        const buyer = isBuyer();
        tbody.innerHTML =
            rows
                .map((r) => {
                    const addIds = r.additional_ids || {};
                    const addIdsStr =
                        Object.entries(addIds)
                            .map(([k, v]) => `${k}: ${v}`)
                            .join("; ") || "-";
                    const nameEsc = escapeHtml(r.name).replace(/'/g, "\\'");
                    let actions = `<button class="btn btn-sm btn-outline-secondary" onclick="openVisitModal(${r.id}, '${nameEsc}')">Log visit</button>`;
                    if (buyer) {
                        actions += ` <button class="btn btn-sm btn-outline-primary" onclick="editSupplier(${r.id})">Edit</button>
          <a class="btn btn-sm btn-outline-dark" href="/cargochina/procurement_drafts.php?supplier_id=${r.id}">Draft Order</a>
          <button class="btn btn-sm btn-outline-success" onclick="openPaymentModal(${r.id}, '${nameEsc}')">Pay</button>
          <button class="btn btn-sm btn-outline-info" onclick="showPayHistory(${r.id}, '${nameEsc}')">History</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteSupplier(${r.id}, '${nameEsc}')">Del</button>`;
                    }
                    const scoreHtml =
                        r.reliability_score != null
                            ? `<span class="badge ${r.reliability_score >= 4 ? "bg-success" : r.reliability_score >= 2.5 ? "bg-warning text-dark" : "bg-danger"}" title="Reliability score based on orders, payments, variance rate">${parseFloat(r.reliability_score).toFixed(1)} ★</span>`
                            : "";
                    const commissionHtml =
                        buyer && r.commission_rate != null
                            ? `<br><small class="text-muted">Commission: ${escapeHtml(String(r.commission_rate))}${r.commission_type === "fixed" ? "" : "%"} on ${escapeHtml(r.commission_applied_on === "sell_value" ? "sell" : "buy")}</small>`
                            : "";
                    const addrTitle = [r.address, r.factory_location]
                        .filter(Boolean)
                        .join(" | ");
                    return `
      <tr>
        <td>${escapeHtml(r.code)}</td>
        <td>${escapeHtml(r.store_id || "-")}</td>
        <td>
          ${escapeHtml(r.name)}
          ${scoreHtml ? `<br><small>${scoreHtml}</small>` : ""}
          ${commissionHtml}
          ${addrTitle ? `<br><small class="text-muted">${escapeHtml(addrTitle.substring(0, 60))}${addrTitle.length > 60 ? "…" : ""}</small>` : ""}
        </td>
        <td>${escapeHtml(r.phone || "-")}${r.fax ? `<br><small class="text-muted">Fax: ${escapeHtml(r.fax)}</small>` : ""}</td>
        <td>${escapeHtml(r.factory_location || "-")}</td>
        <td><small class="text-muted">${escapeHtml(addIdsStr)}</small></td>
        <td class="table-actions">${actions}</td>
      </tr>
    `;
                })
                .join("") ||
            '<tr><td colspan="7" class="text-muted">No suppliers yet.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function openVisitModal(supplierId, name) {
    document.getElementById("visitSupplierId").value = supplierId;
    document.getElementById("visitSupplierName").textContent = name;
    document.getElementById("visitType").value = "visit";
    document.getElementById("visitContent").value = "";
    try {
        const res = await api("GET", "/suppliers/" + supplierId);
        const ints = res.data?.interactions || [];
        const html =
            ints.length > 0
                ? ints
                      .slice(0, 10)
                      .map((i) => {
                          const c =
                              typeof i.content === "string"
                                  ? i.content
                                  : i.content && typeof i.content === "object"
                                    ? JSON.stringify(i.content)
                                    : "";
                          return `<div class="mb-1"><span class="badge bg-light text-dark">${escapeHtml(i.interaction_type)}</span> ${escapeHtml((i.created_at || "").slice(0, 16))} — ${escapeHtml(c.slice(0, 80))}${c.length > 80 ? "…" : ""} <small>by ${escapeHtml(i.created_by_name || "—")}</small></div>`;
                      })
                      .join("")
                : "<em>No visits logged yet.</em>";
        document.getElementById("visitHistory").innerHTML = html;
    } catch (_) {
        document.getElementById("visitHistory").innerHTML =
            "<em>Could not load history.</em>";
    }
    new bootstrap.Modal(document.getElementById("visitModal")).show();
}

async function submitVisit() {
    const supplierId = document.getElementById("visitSupplierId").value;
    const type = document.getElementById("visitType").value;
    const content = document.getElementById("visitContent").value.trim();
    if (!content) {
        showToast("Please enter notes", "warning");
        return;
    }
    const btn = document.getElementById("visitSubmitBtn");
    try {
        setLoading(btn, true);
        await api("POST", "/suppliers/" + supplierId + "/interactions", {
            interaction_type: type,
            content,
        });
        showToast("Visit logged");
        bootstrap.Modal.getInstance(
            document.getElementById("visitModal"),
        ).hide();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

function addAdditionalIdRow(key = "", value = "") {
    const container = document.getElementById("additionalIdsContainer");
    const idx = additionalIdIndex++;
    const row = document.createElement("div");
    row.className = "row g-1 mb-1 align-items-center";
    row.dataset.idx = idx;
    row.innerHTML = `
    <div class="col-5"><input type="text" class="form-control form-control-sm add-id-key" placeholder="Key (e.g. Tax ID)" value="${escapeHtml(key)}"></div>
    <div class="col-5"><input type="text" class="form-control form-control-sm add-id-val" placeholder="Value" value="${escapeHtml(value)}"></div>
    <div class="col-2"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove()">×</button></div>`;
    container.appendChild(row);
}

function collectAdditionalIds() {
    const obj = {};
    document.querySelectorAll("#additionalIdsContainer .row").forEach((row) => {
        const k = row.querySelector(".add-id-key")?.value?.trim();
        const v = row.querySelector(".add-id-val")?.value?.trim();
        if (k && v) obj[k] = v;
    });
    return Object.keys(obj).length ? obj : null;
}

function openSupplierForm() {
    document.getElementById("supplierForm").reset();
    document.getElementById("supplierId").value = "";
    document.getElementById("supplierModalTitle").textContent = "Add Supplier";
    document.getElementById("additionalIdsContainer").innerHTML = "";
    document.getElementById("supplierFax").value = "";
    document.getElementById("supplierAddress").value = "";
    document.getElementById("supplierCommissionRate").value = "";
    document.getElementById("supplierCommissionType").value = "percentage";
    document.getElementById("supplierCommissionAppliedOn").value = "buy_value";
    addAdditionalIdRow();
}

async function editSupplier(id) {
    try {
        const res = await api("GET", "/suppliers/" + id);
        const d = res.data;
        document.getElementById("supplierId").value = d.id;
        document.getElementById("supplierCode").value = d.code;
        document.getElementById("supplierStoreId").value = d.store_id || "";
        document.getElementById("supplierName").value = d.name;
        document.getElementById("supplierPhone").value = d.phone || "";
        document.getElementById("supplierFax").value = d.fax || "";
        document.getElementById("supplierFactory").value =
            d.factory_location || "";
        document.getElementById("supplierAddress").value = d.address || "";
        document.getElementById("supplierCommissionRate").value =
            d.commission_rate ?? "";
        document.getElementById("supplierCommissionType").value =
            d.commission_type || "percentage";
        document.getElementById("supplierCommissionAppliedOn").value =
            d.commission_applied_on || "buy_value";
        document.getElementById("supplierNotes").value = d.notes || "";
        document.getElementById("supplierModalTitle").textContent =
            "Edit Supplier";
        document.getElementById("additionalIdsContainer").innerHTML = "";
        const addIds = d.additional_ids || {};
        if (Object.keys(addIds).length) {
            Object.entries(addIds).forEach(([k, v]) =>
                addAdditionalIdRow(k, v),
            );
        } else {
            addAdditionalIdRow();
        }
        new bootstrap.Modal(document.getElementById("supplierModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveSupplier() {
    const btn = document.getElementById("supplierSaveBtn");
    const id = document.getElementById("supplierId").value;
    const payload = {
        code: document.getElementById("supplierCode").value.trim(),
        store_id:
            document.getElementById("supplierStoreId").value.trim() || null,
        name: document.getElementById("supplierName").value.trim(),
        phone: document.getElementById("supplierPhone").value.trim() || null,
        fax: document.getElementById("supplierFax").value.trim() || null,
        factory_location:
            document.getElementById("supplierFactory").value.trim() || null,
        address:
            document.getElementById("supplierAddress").value.trim() || null,
        commission_rate: document.getElementById("supplierCommissionRate").value
            ? parseFloat(
                  document.getElementById("supplierCommissionRate").value,
              )
            : null,
        commission_type:
            document.getElementById("supplierCommissionType").value ||
            "percentage",
        commission_applied_on:
            document.getElementById("supplierCommissionAppliedOn").value ||
            "buy_value",
        notes: document.getElementById("supplierNotes").value.trim() || null,
        additional_ids: collectAdditionalIds(),
    };
    if (!payload.code || !payload.name) {
        showToast("Code and Name are required", "danger");
        return;
    }
    try {
        setLoading(btn, true);
        if (id) {
            await api("PUT", "/suppliers/" + id, payload);
            showToast("Supplier updated");
        } else {
            await api("POST", "/suppliers", payload);
            showToast("Supplier created");
        }
        bootstrap.Modal.getInstance(
            document.getElementById("supplierModal"),
        ).hide();
        loadSuppliers();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

function openPaymentModal(supplierId, name) {
    document.getElementById("paymentSupplierId").value = supplierId;
    document.getElementById("paymentSupplierName").textContent = name;
    document.getElementById("payInvoiceAmount").value = "";
    document.getElementById("payAmount").value = "";
    document.getElementById("payOrderId").value = "";
    document.getElementById("payNotes").value = "";
    document.getElementById("payMarkedFull").checked = false;
    document.getElementById("payDiscountInfo").classList.add("d-none");
    new bootstrap.Modal(document.getElementById("paymentModal")).show();
}

document
    .getElementById("payInvoiceAmount")
    ?.addEventListener("input", updateDiscountPreview);
document
    .getElementById("payAmount")
    ?.addEventListener("input", updateDiscountPreview);
function updateDiscountPreview() {
    const inv = parseFloat(
        document.getElementById("payInvoiceAmount")?.value || 0,
    );
    const paid = parseFloat(document.getElementById("payAmount")?.value || 0);
    const info = document.getElementById("payDiscountInfo");
    if (inv > 0 && paid > 0 && inv > paid) {
        const disc = inv - paid;
        const pct = ((disc / inv) * 100).toFixed(1);
        info.textContent = `Discount: ${disc.toFixed(2)} (${pct}%)`;
        info.classList.remove("d-none");
    } else {
        info.classList.add("d-none");
    }
}

async function submitPayment() {
    const supplierId = document.getElementById("paymentSupplierId").value;
    const amount = parseFloat(document.getElementById("payAmount").value || 0);
    if (amount <= 0) {
        showToast("Amount must be positive", "danger");
        return;
    }
    const payload = {
        amount,
        invoice_amount:
            parseFloat(document.getElementById("payInvoiceAmount").value) ||
            null,
        currency: document.getElementById("payCurrency").value,
        order_id: document.getElementById("payOrderId").value || null,
        marked_full_payment: document.getElementById("payMarkedFull").checked,
        payment_type: document.getElementById("payMarkedFull").checked
            ? "full"
            : "partial",
        notes: document.getElementById("payNotes").value || null,
    };
    const btn = document.getElementById("paySubmitBtn");
    try {
        setLoading(btn, true);
        await api("POST", "/suppliers/" + supplierId + "/payments", payload);
        showToast("Payment recorded");
        bootstrap.Modal.getInstance(
            document.getElementById("paymentModal"),
        ).hide();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

async function showPayHistory(supplierId, name) {
    document.getElementById("histSupplierName").textContent = name;
    try {
        const [detailRes, balRes] = await Promise.all([
            api("GET", "/suppliers/" + supplierId),
            api("GET", "/suppliers/" + supplierId + "/balance"),
        ]);
        const payments = detailRes.data?.payments || [];
        const balance = balRes.data || {};
        let balHtml = "";
        Object.entries(balance).forEach(([cur, b]) => {
            balHtml += `<span class="badge bg-secondary me-2">${cur}: Paid ${b.total_paid.toFixed(2)} / Invoiced ${b.total_invoiced.toFixed(2)} | Discount ${b.total_discount.toFixed(2)} | Outstanding ${b.outstanding.toFixed(2)}</span>`;
        });
        document.getElementById("balanceSummary").innerHTML =
            balHtml || '<span class="text-muted">No payments yet</span>';
        document.getElementById("payHistoryBody").innerHTML = payments.length
            ? payments
                  .map(
                      (p) => `<tr>
                <td>${p.created_at}</td>
                <td>${p.invoice_amount ?? "-"}</td>
                <td>${p.amount}</td>
                <td>${p.discount_amount > 0 ? p.discount_amount : "-"}${p.marked_full_payment ? ' <span class="badge bg-success">Full</span>' : ""}</td>
                <td>${p.currency}</td>
                <td>${p.payment_type}</td>
                <td>${p.order_id ? "#" + p.order_id : "-"}</td>
                <td>${escapeHtml(p.notes || "-")}</td>
              </tr>`,
                  )
                  .join("")
            : '<tr><td colspan="8" class="text-muted">No payments</td></tr>';
        new bootstrap.Modal(document.getElementById("payHistoryModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function deleteSupplier(id, name) {
    if (!confirm('Delete supplier "' + name + '"?')) return;
    try {
        await api("DELETE", "/suppliers/" + id);
        showToast("Supplier deleted");
        loadSuppliers();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

window.openImportModal = function (entity) {
    window._importEntity = entity || "suppliers";
    window._importOnSuccess = loadSuppliers;
    const ta = document.getElementById("importCsvData");
    const resultEl = document.getElementById("importResult");
    const fileInput = document.getElementById("importCsvFile");
    if (ta) ta.value = "";
    if (fileInput) fileInput.value = "";
    if (resultEl) {
        resultEl.classList.add("d-none");
        resultEl.textContent = "";
    }
};

window.doImport = async function () {
    const entity = window._importEntity || "suppliers";
    const csv = document.getElementById("importCsvData")?.value?.trim();
    if (!csv) {
        showToast("Paste CSV data first", "danger");
        return;
    }
    const btn = document.getElementById("importBtn");
    const resultEl = document.getElementById("importResult");
    try {
        setLoading(btn, true);
        if (resultEl) {
            resultEl.classList.add("d-none");
            resultEl.textContent = "";
        }
        const res = await api("POST", "/" + entity + "/import", { csv });
        const d = res.data;
        let msg = `Created: ${d.created}, Skipped: ${d.skipped}`;
        if (d.errors?.length) msg += `; Errors: ${d.errors.join("; ")}`;
        if (resultEl) {
            resultEl.textContent = msg;
            resultEl.className =
                "alert alert-" +
                (d.errors?.length ? "warning" : "success") +
                " mt-2";
            resultEl.classList.remove("d-none");
        }
        showToast(msg);
        if (d.created > 0 && window._importOnSuccess) window._importOnSuccess();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
};
