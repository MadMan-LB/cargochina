let itemIndex = 0;
let orderCustomerAc, orderSupplierAc, filterCustomerAc;

document.addEventListener("DOMContentLoaded", () => {
    orderCustomerAc = Autocomplete.init(
        document.getElementById("orderCustomer"),
        {
            resource: "customers",
            placeholder: "Type customer name or code...",
            onSelect: () => {},
        },
    );
    orderSupplierAc = Autocomplete.init(
        document.getElementById("orderSupplier"),
        {
            resource: "suppliers",
            placeholder: "Type supplier name or code...",
            onSelect: () => {},
        },
    );
    filterCustomerAc = Autocomplete.init(
        document.getElementById("filterCustomer"),
        {
            resource: "customers",
            placeholder: "Filter by customer (type to search)",
            onSelect: () => loadOrders(),
        },
    );
    const filterInput = document.getElementById("filterCustomer");
    if (filterInput) {
        filterInput.addEventListener("blur", () => {
            if (!filterInput.value.trim()) loadOrders();
        });
    }
    loadOrders();
});

async function loadOrders() {
    try {
        const status = document.getElementById("filterStatus").value;
        const customerId = filterCustomerAc?.getSelectedId() || "";
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
    <td><div class="item-photos" data-idx="${idx}"></div>
        <input type="file" class="form-control form-control-sm d-none item-photo-input" accept="image/*" multiple data-idx="${idx}">
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelector('.item-photo-input[data-idx=\\'${idx}\\']').click()">+ Photo</button></td>
    <td><input type="text" class="form-control form-control-sm item-item-no" placeholder="Item No" data-idx="${idx}"></td>
    <td><input type="text" class="form-control form-control-sm item-shipping-code" placeholder="Agent code" data-idx="${idx}"></td>
    <td><input type="text" class="form-control form-control-sm item-desc" placeholder="Description CN/EN" data-idx="${idx}">
        <input type="hidden" class="item-product-id" data-idx="${idx}">
        <small class="text-muted product-suggest" data-idx="${idx}"></small></td>
    <td><input type="number" class="form-control form-control-sm item-cartons" min="0" placeholder="0" data-idx="${idx}"></td>
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-qty-per-ctn" min="0" placeholder="0" data-idx="${idx}"></td>
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-qty" min="0" placeholder="0" data-idx="${idx}" title="Total qty (auto from CTNS×Qty/Ctn or enter for pieces)"></td>
    <td><input type="number" step="0.01" class="form-control form-control-sm item-unit-price" placeholder="0" data-idx="${idx}"></td>
    <td><span class="item-total-amount" data-idx="${idx}">0</span></td>
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-cbm" required min="0" placeholder="0" data-idx="${idx}"></td>
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-weight" required min="0" placeholder="0" data-idx="${idx}"></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">×</button></td>`;
    tbody.appendChild(row);
    row.querySelector(".item-photo-input").addEventListener("change", (e) =>
        handleItemPhoto(e, idx),
    );
    ["item-cartons", "item-qty-per-ctn", "item-unit-price", "item-qty"].forEach(
        (cls) => {
            const el = row.querySelector(`.${cls}`);
            if (el) el.addEventListener("input", () => updateItemComputed(idx));
        },
    );
}

async function handleItemPhoto(e, idx) {
    const files = e.target.files;
    if (!files || !files.length) return;
    const container = document.querySelector(`.item-photos[data-idx="${idx}"]`);
    for (let i = 0; i < files.length; i++) {
        try {
            const path = await uploadFile(files[i]);
            if (path) {
                const div = document.createElement("div");
                div.className = "d-inline-block me-1 mb-1";
                div.dataset.path = path;
                div.innerHTML = `<img src="/cargochina/backend/${path}" class="img-thumbnail img-thumbnail-sm" style="max-width:50px" alt=""><button type="button" class="btn-close btn-close-sm" onclick="this.closest('.d-inline-block').remove()"></button>`;
                container.appendChild(div);
            }
        } catch (err) {
            showToast(err.message, "danger");
        }
    }
    e.target.value = "";
}

function updateItemComputed(idx) {
    const tr = document.querySelector(`tr[data-idx="${idx}"]`);
    if (!tr) return;
    const cartons = parseInt(tr.querySelector(".item-cartons")?.value || 0, 10);
    const qtyPerCtn = parseFloat(
        tr.querySelector(".item-qty-per-ctn")?.value || 0,
    );
    const unitPrice = parseFloat(
        tr.querySelector(".item-unit-price")?.value || 0,
    );
    const totalQty =
        cartons > 0 && qtyPerCtn > 0
            ? cartons * qtyPerCtn
            : parseFloat(tr.querySelector(".item-qty")?.value || 0);
    const totalAmount =
        totalQty > 0 && unitPrice > 0 ? (totalQty * unitPrice).toFixed(2) : "0";
    const qtyInput = tr.querySelector(".item-qty");
    if (cartons > 0 && qtyPerCtn > 0) qtyInput.value = totalQty;
    tr.querySelector(".item-total-amount").textContent = totalAmount;
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
        orderCustomerAc?.setValue({
            id: o.customer_id,
            name: o.customer_name,
            code: "",
        });
        orderSupplierAc?.setValue({
            id: o.supplier_id,
            name: o.supplier_name,
            code: "",
        });
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
            last.querySelector(".item-item-no").value = it.item_no || "";
            last.querySelector(".item-shipping-code").value =
                it.shipping_code || "";
            last.querySelector(".item-cartons").value = it.cartons ?? "";
            last.querySelector(".item-qty-per-ctn").value =
                it.qty_per_carton ?? "";
            last.querySelector(".item-qty").value = it.quantity ?? "";
            last.querySelector(".item-unit-price").value = it.unit_price ?? "";
            last.querySelector(".item-cbm").value = it.declared_cbm ?? "";
            last.querySelector(".item-weight").value = it.declared_weight ?? "";
            updateItemComputed(last.dataset.idx);
            (it.image_paths || []).forEach((path) => {
                const div = document.createElement("div");
                div.className = "d-inline-block me-1 mb-1";
                div.dataset.path = path;
                div.innerHTML = `<img src="/cargochina/backend/${path}" class="img-thumbnail img-thumbnail-sm" style="max-width:50px" alt=""><button type="button" class="btn-close btn-close-sm" onclick="this.closest('.d-inline-block').remove()"></button>`;
                last.querySelector(".item-photos").appendChild(div);
            });
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
        const cartons = parseInt(
            tr.querySelector(".item-cartons")?.value || 0,
            10,
        );
        const qtyPerCtn = parseFloat(
            tr.querySelector(".item-qty-per-ctn")?.value || 0,
        );
        const qtyInput = parseFloat(tr.querySelector(".item-qty")?.value || 0);
        const qty =
            cartons > 0 && qtyPerCtn > 0 ? cartons * qtyPerCtn : qtyInput;
        if (qty <= 0) return;
        const unit = cartons > 0 ? "cartons" : "pieces";
        const cbm = parseFloat(tr.querySelector(".item-cbm")?.value || 0);
        const weight = parseFloat(tr.querySelector(".item-weight")?.value || 0);
        const desc = tr.querySelector(".item-desc")?.value?.trim();
        const productId = tr.querySelector(".item-product-id")?.value;
        const itemNo = tr.querySelector(".item-item-no")?.value?.trim();
        const shippingCode = tr
            .querySelector(".item-shipping-code")
            ?.value?.trim();
        const unitPrice = parseFloat(
            tr.querySelector(".item-unit-price")?.value || 0,
        );
        const photoDivs = tr.querySelectorAll(".item-photos [data-path]");
        const imagePaths = Array.from(photoDivs)
            .map((d) => d.dataset.path)
            .filter(Boolean);
        if (cbm >= 0 && weight >= 0) {
            items.push({
                product_id: productId || null,
                item_no: itemNo || null,
                shipping_code: shippingCode || null,
                cartons: cartons || null,
                qty_per_carton: qtyPerCtn || null,
                quantity: qty,
                unit,
                declared_cbm: cbm,
                declared_weight: weight,
                unit_price: unitPrice || null,
                total_amount: qty > 0 && unitPrice > 0 ? qty * unitPrice : null,
                image_paths: imagePaths.length ? imagePaths : null,
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
        customer_id: orderCustomerAc?.getSelectedId() || "",
        supplier_id: orderSupplierAc?.getSelectedId() || "",
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
    const saveBtn = document.querySelector("#orderModal .btn-primary");
    try {
        setLoading(saveBtn, true);
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
        showToast(e.message || "Request failed", "danger");
    } finally {
        setLoading(saveBtn, false);
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
        const res = await api("GET", "/orders/" + id);
        const o = res.data;
        const receipt = o.receipt;
        const showPhotos =
            (o.customer_photo_visibility || "internal-only") ===
            "customer-visible";
        let msg = "Confirm acceptance of actual measures?";
        if (receipt) {
            msg += `\n\nActual: ${receipt.actual_cbm} CBM, ${receipt.actual_weight} kg, ${receipt.actual_cartons} cartons`;
            if (showPhotos && receipt.photos?.length) {
                msg += `\n(${receipt.photos.length} photo(s) attached)`;
            }
        }
        if (!confirm(msg)) return;
        await api("POST", "/orders/" + id + "/confirm", {});
        showToast("Order confirmed");
        loadOrders();
    } catch (e) {
        showToast(e.message || "Request failed", "danger");
    }
}
