document.addEventListener("DOMContentLoaded", loadSuppliers);

async function loadSuppliers() {
    try {
        const res = await api("GET", "/suppliers");
        const rows = res.data || [];
        const tbody = document.querySelector("#suppliersTable tbody");
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
      <tr>
        <td>${escapeHtml(r.code)}</td>
        <td>${escapeHtml(r.name)}</td>
        <td>${escapeHtml(r.factory_location || "-")}</td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-primary" onclick="editSupplier(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteSupplier(${r.id}, '${escapeHtml(r.name)}')">Delete</button>
        </td>
      </tr>
    `,
                )
                .join("") ||
            '<tr><td colspan="4" class="text-muted">No suppliers yet.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function openSupplierForm() {
    document.getElementById("supplierForm").reset();
    document.getElementById("supplierId").value = "";
    document.getElementById("supplierModalTitle").textContent = "Add Supplier";
}

async function editSupplier(id) {
    try {
        const res = await api("GET", "/suppliers/" + id);
        const d = res.data;
        document.getElementById("supplierId").value = d.id;
        document.getElementById("supplierCode").value = d.code;
        document.getElementById("supplierName").value = d.name;
        document.getElementById("supplierFactory").value =
            d.factory_location || "";
        document.getElementById("supplierNotes").value = d.notes || "";
        document.getElementById("supplierModalTitle").textContent =
            "Edit Supplier";
        new bootstrap.Modal(document.getElementById("supplierModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveSupplier() {
    const id = document.getElementById("supplierId").value;
    const payload = {
        code: document.getElementById("supplierCode").value.trim(),
        name: document.getElementById("supplierName").value.trim(),
        factory_location:
            document.getElementById("supplierFactory").value.trim() || null,
        notes: document.getElementById("supplierNotes").value.trim() || null,
    };
    if (!payload.code || !payload.name) {
        showToast("Code and Name are required", "danger");
        return;
    }
    try {
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
