document.addEventListener("DOMContentLoaded", loadCustomers);

async function loadCustomers() {
    try {
        const res = await api("GET", "/customers");
        const rows = res.data || [];
        const tbody = document.querySelector("#customersTable tbody");
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
      <tr>
        <td>${escapeHtml(r.code)}</td>
        <td>${escapeHtml(r.name)}</td>
        <td>${escapeHtml(r.payment_terms || "-")}</td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-primary" onclick="editCustomer(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteCustomer(${r.id}, '${escapeHtml(r.name)}')">Delete</button>
        </td>
      </tr>
    `,
                )
                .join("") ||
            '<tr><td colspan="4" class="text-muted">No customers yet. Add one to get started.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function openCustomerForm(id) {
    document.getElementById("customerForm").reset();
    document.getElementById("customerId").value = "";
    document.getElementById("customerModalTitle").textContent = "Add Customer";
}

async function editCustomer(id) {
    try {
        const res = await api("GET", "/customers/" + id);
        const d = res.data;
        document.getElementById("customerId").value = d.id;
        document.getElementById("customerCode").value = d.code;
        document.getElementById("customerName").value = d.name;
        document.getElementById("customerPaymentTerms").value =
            d.payment_terms || "";
        document.getElementById("customerModalTitle").textContent =
            "Edit Customer";
        new bootstrap.Modal(document.getElementById("customerModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveCustomer() {
    const id = document.getElementById("customerId").value;
    const payload = {
        code: document.getElementById("customerCode").value.trim(),
        name: document.getElementById("customerName").value.trim(),
        payment_terms:
            document.getElementById("customerPaymentTerms").value.trim() ||
            null,
    };
    if (!payload.code || !payload.name) {
        showToast("Code and Name are required", "danger");
        return;
    }
    try {
        if (id) {
            await api("PUT", "/customers/" + id, payload);
            showToast("Customer updated");
        } else {
            await api("POST", "/customers", payload);
            showToast("Customer created");
        }
        bootstrap.Modal.getInstance(
            document.getElementById("customerModal"),
        ).hide();
        loadCustomers();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function deleteCustomer(id, name) {
    if (!confirm('Delete customer "' + name + '"?')) return;
    try {
        await api("DELETE", "/customers/" + id);
        showToast("Customer deleted");
        loadCustomers();
    } catch (e) {
        showToast(e.message, "danger");
    }
}
