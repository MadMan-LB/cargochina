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
          <button class="btn btn-sm btn-outline-success" onclick="openDepositModal(${r.id}, '${escapeHtml(r.name).replace(/'/g, "\\'")}')">Deposit</button>
          <button class="btn btn-sm btn-outline-info" onclick="showBalance(${r.id}, '${escapeHtml(r.name).replace(/'/g, "\\'")}')">Balance</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteCustomer(${r.id}, '${escapeHtml(r.name)}')">Del</button>
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

function openDepositModal(customerId, name) {
    document.getElementById("depCustomerId").value = customerId;
    document.getElementById("depCustomerName").textContent = name;
    document.getElementById("depAmount").value = "";
    document.getElementById("depMethod").value = "";
    document.getElementById("depReference").value = "";
    document.getElementById("depNotes").value = "";
    new bootstrap.Modal(document.getElementById("depositModal")).show();
}

async function submitDeposit() {
    const customerId = document.getElementById("depCustomerId").value;
    const amount = parseFloat(document.getElementById("depAmount").value || 0);
    if (amount <= 0) {
        showToast("Amount must be positive", "danger");
        return;
    }
    const payload = {
        amount,
        currency: document.getElementById("depCurrency").value,
        payment_method: document.getElementById("depMethod").value || null,
        reference_no: document.getElementById("depReference").value || null,
        notes: document.getElementById("depNotes").value || null,
    };
    const btn = document.getElementById("depSubmitBtn");
    try {
        setLoading(btn, true);
        await api("POST", "/customers/" + customerId + "/deposits", payload);
        showToast("Deposit recorded");
        bootstrap.Modal.getInstance(
            document.getElementById("depositModal"),
        ).hide();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

async function showBalance(customerId, name) {
    document.getElementById("balCustomerName").textContent = name;
    try {
        const [depRes, balRes] = await Promise.all([
            api("GET", "/customers/" + customerId + "/deposits"),
            api("GET", "/customers/" + customerId + "/balance"),
        ]);
        const deposits = depRes.data?.deposits || [];
        const balance = balRes.data || {};
        let balHtml = "";
        Object.entries(balance).forEach(([cur, total]) => {
            balHtml += `<span class="badge bg-primary me-2">${cur}: ${total.toFixed(2)}</span>`;
        });
        document.getElementById("balSummary").innerHTML =
            balHtml || '<span class="text-muted">No deposits</span>';
        document.getElementById("balHistoryBody").innerHTML = deposits.length
            ? deposits
                  .map(
                      (d) => `<tr>
                <td>${d.created_at}</td>
                <td>${d.amount}</td>
                <td>${d.currency}</td>
                <td>${escapeHtml(d.payment_method || "-")}</td>
                <td>${escapeHtml(d.reference_no || "-")}</td>
                <td>${escapeHtml(d.notes || "-")}</td>
              </tr>`,
                  )
                  .join("")
            : '<tr><td colspan="6" class="text-muted">No deposits yet</td></tr>';
        new bootstrap.Modal(document.getElementById("balanceModal")).show();
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
