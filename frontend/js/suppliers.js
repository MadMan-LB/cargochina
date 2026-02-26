document.addEventListener("DOMContentLoaded", loadSuppliers);

let additionalIdIndex = 0;

async function loadSuppliers() {
    try {
        const res = await api("GET", "/suppliers");
        const rows = res.data || [];
        const tbody = document.querySelector("#suppliersTable tbody");
        tbody.innerHTML =
            rows
                .map((r) => {
                    const addIds = r.additional_ids || {};
                    const addIdsStr =
                        Object.entries(addIds)
                            .map(([k, v]) => `${k}: ${v}`)
                            .join("; ") || "-";
                    return `
      <tr>
        <td>${escapeHtml(r.code)}</td>
        <td>${escapeHtml(r.store_id || "-")}</td>
        <td>${escapeHtml(r.name)}</td>
        <td>${escapeHtml(r.phone || "-")}</td>
        <td>${escapeHtml(r.factory_location || "-")}</td>
        <td><small class="text-muted">${escapeHtml(addIdsStr)}</small></td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-primary" onclick="editSupplier(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-success" onclick="openPaymentModal(${r.id}, '${escapeHtml(r.name).replace(/'/g, "\\'")}')">Pay</button>
          <button class="btn btn-sm btn-outline-info" onclick="showPayHistory(${r.id}, '${escapeHtml(r.name).replace(/'/g, "\\'")}')">History</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteSupplier(${r.id}, '${escapeHtml(r.name).replace(/'/g, "\\'")}')">Del</button>
        </td>
      </tr>
    `;
                })
                .join("") ||
            '<tr><td colspan="7" class="text-muted">No suppliers yet.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
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
        document.getElementById("supplierFactory").value =
            d.factory_location || "";
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
        factory_location:
            document.getElementById("supplierFactory").value.trim() || null,
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
