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
        <td>${escapeHtml(r.name)}</td>
        <td>${escapeHtml(r.phone || "-")}</td>
        <td>${escapeHtml(r.factory_location || "-")}</td>
        <td><small class="text-muted">${escapeHtml(addIdsStr)}</small></td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-primary" onclick="editSupplier(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteSupplier(${r.id}, '${escapeHtml(r.name).replace(/'/g, "\\'")}')">Delete</button>
        </td>
      </tr>
    `;
                })
                .join("") ||
            '<tr><td colspan="6" class="text-muted">No suppliers yet.</td></tr>';
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
