let itemIndex = 0;

document.addEventListener("DOMContentLoaded", () => {
    loadCustomers();
    loadSuppliers();
    loadOrders();
});

async function loadCustomers() {
    try {
        const res = await api("GET", "/customers");
        const sel = document.getElementById("orderCustomer");
        const filter = document.getElementById("filterCustomer");
        const opts = (res.data || [])
            .map(
                (c) => `<option value="${c.id}">${escapeHtml(c.name)}</option>`,
            )
            .join("");
        sel.innerHTML = '<option value="">— Select —</option>' + opts;
        filter.innerHTML =
            '<option value="">All customers</option>' +
            (res.data || [])
                .map(
                    (c) =>
                        `<option value="${c.id}">${escapeHtml(c.name)}</option>`,
                )
                .join("");
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function loadSuppliers() {
    try {
        const res = await api("GET", "/suppliers");
        const sel = document.getElementById("orderSupplier");
        sel.innerHTML =
            '<option value="">— Select —</option>' +
            (res.data || [])
                .map(
                    (s) =>
                        `<option value="${s.id}">${escapeHtml(s.name)}</option>`,
                )
                .join("");
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function loadOrders() {
    try {
        const status = document.getElementById("filterStatus").value;
        const customerId = document.getElementById("filterCustomer").value;
        let path = "/orders?";
        if (status) path += "status=" + encodeURIComponent(status) + "&";
        if (customerId) path += "customer_id=" + encodeURIComponent(customerId);
        const res = await api("GET", path);
        const rows = res.data || [];
        const tbody = document.querySelector("#ordersTable tbody");
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
      <tr>
        <td>${r.id}</td>
        <td>${escapeHtml(r.customer_name)}</td>
        <td>${escapeHtml(r.supplier_name)}</td>
        <td>${r.expected_ready_date}</td>
        <td><span class="badge bg-secondary">${escapeHtml(r.status)}</span></td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-primary" onclick="editOrder(${r.id})">Edit</button>
          ${r.status === "Draft" ? `<button class="btn btn-sm btn-success" onclick="submitOrder(${r.id})">Submit</button>` : ""}
          ${r.status === "Submitted" ? `<button class="btn btn-sm btn-success" onclick="approveOrder(${r.id})">Approve</button>` : ""}
          ${r.status === "AwaitingCustomerConfirmation" ? `<button class="btn btn-sm btn-warning" onclick="confirmOrder(${r.id})">Confirm</button>` : ""}
        </td>
      </tr>
    `,
                )
                .join("") ||
            '<tr><td colspan="6" class="text-muted">No orders yet.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function openOrderForm() {
    document.getElementById("orderForm").reset();
    document.getElementById("orderId").value = "";
    document.getElementById("orderModalTitle").textContent = "New Order";
    document.getElementById("orderItemsBody").innerHTML = "";
    addOrderItem();
}

function addOrderItem() {
    const tbody = document.getElementById("orderItemsBody");
    const idx = itemIndex++;
    const row = document.createElement("tr");
    row.dataset.idx = idx;
    row.innerHTML = `
    <td><input type="text" class="form-control form-control-sm item-desc" placeholder="Description CN/EN" data-idx="${idx}">
        <input type="hidden" class="item-product-id" data-idx="${idx}">
        <small class="text-muted product-suggest" data-idx="${idx}"></small></td>
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-qty" required data-idx="${idx}"></td>
    <td><select class="form-select form-select-sm item-unit" data-idx="${idx}"><option value="cartons">Cartons</option><option value="pieces">Pieces</option></select></td>
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-cbm" required data-idx="${idx}"></td>
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-weight" required data-idx="${idx}"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">×</button></td>`;
    tbody.appendChild(row);
}

async function editOrder(id) {
    try {
        const res = await api("GET", "/orders/" + id);
        const o = res.data;
        if (o.status !== "Draft") {
            showToast("Only draft orders can be edited", "danger");
            return;
        }
        document.getElementById("orderId").value = o.id;
        document.getElementById("orderCustomer").value = o.customer_id;
        document.getElementById("orderSupplier").value = o.supplier_id;
        document.getElementById("orderExpectedDate").value =
            o.expected_ready_date;
        document.getElementById("orderModalTitle").textContent =
            "Edit Order #" + o.id;
        const tbody = document.getElementById("orderItemsBody");
        tbody.innerHTML = "";
        (o.items || []).forEach((it) => {
            addOrderItem();
            const last = tbody.lastElementChild;
            last.querySelector(".item-desc").value = (
                it.description_cn ||
                it.description_en ||
                ""
            ).substring(0, 100);
            last.querySelector(".item-product-id").value = it.product_id || "";
            last.querySelector(".item-qty").value = it.quantity;
            last.querySelector(".item-unit").value = it.unit;
            last.querySelector(".item-cbm").value = it.declared_cbm;
            last.querySelector(".item-weight").value = it.declared_weight;
        });
        if (o.items && o.items.length === 0) addOrderItem();
        new bootstrap.Modal(document.getElementById("orderModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function collectOrderItems() {
    const items = [];
    document.querySelectorAll("#orderItemsBody tr").forEach((tr) => {
        const qty = parseFloat(tr.querySelector(".item-qty")?.value);
        const unit = tr.querySelector(".item-unit")?.value;
        const cbm = parseFloat(tr.querySelector(".item-cbm")?.value);
        const weight = parseFloat(tr.querySelector(".item-weight")?.value);
        const desc = tr.querySelector(".item-desc")?.value?.trim();
        const productId = tr.querySelector(".item-product-id")?.value;
        if (qty > 0 && unit && cbm >= 0 && weight >= 0) {
            items.push({
                product_id: productId || null,
                quantity: qty,
                unit,
                declared_cbm: cbm,
                declared_weight: weight,
                description_cn: desc || null,
                description_en: desc || null,
            });
        }
    });
    return items;
}

async function saveOrder() {
    const id = document.getElementById("orderId").value;
    const payload = {
        customer_id: document.getElementById("orderCustomer").value,
        supplier_id: document.getElementById("orderSupplier").value,
        expected_ready_date: document.getElementById("orderExpectedDate").value,
        items: collectOrderItems(),
    };
    if (
        !payload.customer_id ||
        !payload.supplier_id ||
        !payload.expected_ready_date
    ) {
        showToast(
            "Customer, Supplier and Expected Date are required",
            "danger",
        );
        return;
    }
    if (payload.items.length === 0) {
        showToast("At least one item is required", "danger");
        return;
    }
    try {
        if (id) {
            await api("PUT", "/orders/" + id, payload);
            showToast("Order updated");
        } else {
            await api("POST", "/orders", payload);
            showToast("Order created");
        }
        bootstrap.Modal.getInstance(
            document.getElementById("orderModal"),
        ).hide();
        loadOrders();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function submitOrder(id) {
    try {
        await api("POST", "/orders/" + id + "/submit", {});
        showToast("Order submitted");
        loadOrders();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function approveOrder(id) {
    try {
        await api("POST", "/orders/" + id + "/approve", {});
        showToast("Order approved");
        loadOrders();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function confirmOrder(id) {
    try {
        await api("POST", "/orders/" + id + "/confirm", {});
        showToast("Order confirmed");
        loadOrders();
    } catch (e) {
        showToast(e.message, "danger");
    }
}
