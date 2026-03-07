let searchTimeout = null;

document.addEventListener("DOMContentLoaded", () => {
    loadCustomers();
    const searchInput = document.getElementById("customerSearch");
    if (searchInput) {
        searchInput.addEventListener("input", () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(loadCustomers, 250);
        });
    }
});

function esc(s) {
    if (s == null || s === undefined) return "";
    const d = document.createElement("div");
    d.textContent = String(s);
    return d.innerHTML;
}

async function loadCustomers() {
    const tbody = document.querySelector("#customersTable tbody");
    if (!tbody) return;
    try {
        const q =
            document.getElementById("customerSearch")?.value?.trim() || "";
        const path = q ? "/customers?q=" + encodeURIComponent(q) : "/customers";
        const res = await api("GET", path);
        const rows = res.data || [];
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
      <tr>
        <td>${esc(r.code)}</td>
        <td>${esc(r.name)}</td>
        <td>${esc(r.phone || "-")}</td>
        <td class="text-truncate" style="max-width:180px" title="${esc(r.address || "")}">${esc((r.address || "-").substring(0, 50))}${(r.address || "").length > 50 ? "…" : ""}</td>
        <td>${esc(r.payment_terms || "-")}</td>
        <td class="table-actions">
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="editCustomer(${r.id})">Edit</button>
          <button type="button" class="btn btn-sm btn-outline-info" onclick="showOrders(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">Orders</button>
          <button type="button" class="btn btn-sm btn-outline-success" onclick="openDepositModal(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">Deposit</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="showBalance(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">Balance</button>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCustomer(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">Del</button>
        </td>
      </tr>
    `,
                )
                .join("") ||
            '<tr><td colspan="6" class="text-muted py-4">No customers found. Add one or search differently.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

let customerPaymentLinks = [];

function addCustomerPaymentLink(name = "", value = "") {
    const id = Date.now();
    customerPaymentLinks.push({ id, name, value });
    renderCustomerPaymentLinks();
}

function removeCustomerPaymentLink(id) {
    customerPaymentLinks = customerPaymentLinks.filter((p) => p.id !== id);
    renderCustomerPaymentLinks();
}

function renderCustomerPaymentLinks() {
    const container = document.getElementById("customerPaymentLinksContainer");
    if (!container) return;
    container.innerHTML = customerPaymentLinks
        .map(
            (p) => `
      <div class="d-flex gap-2 mb-2 align-items-center">
        <input type="text" class="form-control form-control-sm" placeholder="Name (e.g. weeecha)" value="${esc(p.name)}" onchange="updatePaymentLinkName(${p.id}, this.value)">
        <input type="text" class="form-control form-control-sm flex-grow-1" placeholder="Value (e.g. xxx xx xxxx xx)" value="${esc(p.value)}" onchange="updatePaymentLinkValue(${p.id}, this.value)">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeCustomerPaymentLink(${p.id})">×</button>
      </div>
    `,
        )
        .join("");
}

function updatePaymentLinkName(id, v) {
    const p = customerPaymentLinks.find((x) => x.id === id);
    if (p) p.name = v;
}

function updatePaymentLinkValue(id, v) {
    const p = customerPaymentLinks.find((x) => x.id === id);
    if (p) p.value = v;
}

function openCustomerForm() {
    document.getElementById("customerForm").reset();
    document.getElementById("customerId").value = "";
    document.getElementById("customerModalTitle").textContent = "Add Customer";
    customerPaymentLinks = [];
    renderCustomerPaymentLinks();
}

async function editCustomer(id) {
    try {
        const res = await api("GET", "/customers/" + id);
        const d = res.data;
        document.getElementById("customerId").value = d.id;
        document.getElementById("customerCode").value = d.code || "";
        document.getElementById("customerName").value = d.name || "";
        document.getElementById("customerPhone").value = d.phone || "";
        document.getElementById("customerAddress").value = d.address || "";
        document.getElementById("customerPaymentTerms").value =
            d.payment_terms || "";
        customerPaymentLinks = (d.payment_links || []).map((p, i) => ({
            id: Date.now() + i,
            name: p.name || "",
            value: p.value || "",
        }));
        renderCustomerPaymentLinks();
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
        phone: document.getElementById("customerPhone").value.trim() || null,
        address:
            document.getElementById("customerAddress").value.trim() || null,
        payment_terms:
            document.getElementById("customerPaymentTerms").value.trim() ||
            null,
        payment_links: customerPaymentLinks
            .filter((p) => (p.name || "").trim())
            .map((p) => ({
                name: (p.name || "").trim(),
                value: (p.value || "").trim(),
            })),
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
        loadCustomers();
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
        const deposits = depRes.data?.deposits || depRes.data || [];
        const balance = balRes.data || {};
        let balHtml = "";
        Object.entries(balance).forEach(([cur, total]) => {
            balHtml += `<span class="badge bg-primary me-2">${cur}: ${total.toFixed(2)}</span>`;
        });
        document.getElementById("balSummary").innerHTML =
            balHtml || '<span class="text-muted">No deposits</span>';
        const depList = Array.isArray(deposits) ? deposits : [];
        document.getElementById("balHistoryBody").innerHTML = depList.length
            ? depList
                  .map(
                      (d) => `<tr>
                <td>${d.created_at || "-"}</td>
                <td>${d.amount}</td>
                <td>${d.currency}</td>
                <td>${esc(d.payment_method || "-")}</td>
                <td>${esc(d.reference_no || "-")}</td>
                <td>${esc(d.notes || "-")}</td>
              </tr>`,
                  )
                  .join("")
            : '<tr><td colspan="6" class="text-muted">No deposits yet</td></tr>';
        new bootstrap.Modal(document.getElementById("balanceModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

let ordersCache = { customerId: null, data: [] };

async function showOrders(customerId, name) {
    document.getElementById("ordersCustomerName").textContent = name;
    document.getElementById("ordersFilter").value = "";
    document.getElementById("ordersFilter").oninput = () =>
        renderOrders(ordersCache.data);
    try {
        const res = await api("GET", "/orders?customer_id=" + customerId);
        const orders = res.data || [];
        ordersCache = { customerId, data: orders };
        renderOrders(orders);
        new bootstrap.Modal(document.getElementById("ordersModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function renderOrders(orders) {
    const filter =
        document.getElementById("ordersFilter")?.value?.toLowerCase() || "";
    const filtered = orders.filter((o) => {
        if (!filter) return true;
        return (
            String(o.id).includes(filter) ||
            (o.status || "").toLowerCase().includes(filter) ||
            (o.supplier_name || "").toLowerCase().includes(filter)
        );
    });
    const tbody = document.getElementById("ordersModalBody");
    if (!tbody) return;
    let totalCbm = 0,
        totalWeight = 0;
    filtered.forEach((o) => {
        (o.items || []).forEach((it) => {
            totalCbm += parseFloat(it.declared_cbm || 0);
            totalWeight += parseFloat(it.declared_weight || 0);
        });
    });
    tbody.innerHTML =
        filtered.length > 0
            ? filtered
                  .map((o) => {
                      const oCbm = (o.items || []).reduce(
                          (s, i) => s + (parseFloat(i.declared_cbm) || 0),
                          0,
                      );
                      const oWt = (o.items || []).reduce(
                          (s, i) => s + (parseFloat(i.declared_weight) || 0),
                          0,
                      );
                      const statusClass =
                          o.status === "FinalizedAndPushedToTracking"
                              ? "bg-success"
                              : o.status === "Approved" ||
                                  o.status === "Confirmed"
                                ? "bg-info"
                                : o.status === "Draft" ||
                                    o.status === "Submitted"
                                  ? "bg-warning text-dark"
                                  : "bg-secondary";
                      return `<tr>
                <td>${o.id}</td>
                <td>${esc(o.supplier_name || "-")}</td>
                <td>${o.expected_ready_date || "-"}</td>
                <td><span class="badge ${statusClass}">${esc(o.status || "-")}</span></td>
                <td>${oCbm.toFixed(2)}</td>
                <td>${oWt.toFixed(0)} kg</td>
              </tr>`;
                  })
                  .join("")
            : '<tr><td colspan="6" class="text-muted py-4">No orders for this customer.</td></tr>';
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
