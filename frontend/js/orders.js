let itemIndex = 0;
let orderCustomerAc, orderSupplierAc, filterCustomerAc;
const RECENT_KEY_CUSTOMERS = "cargochina_recent_customers";
const RECENT_KEY_SUPPLIERS = "cargochina_recent_suppliers";
const RECENT_MAX = 8;

function getRecent(key) {
    try {
        const raw = localStorage.getItem(key);
        return raw ? JSON.parse(raw) : [];
    } catch (_) {
        return [];
    }
}

function saveRecent(key, item, max = RECENT_MAX) {
    if (!item?.id) return;
    const list = getRecent(key);
    const entry = { id: item.id, name: item.name || item.code || `#${item.id}` };
    const filtered = list.filter((x) => Number(x.id) !== Number(item.id));
    const updated = [entry, ...filtered].slice(0, max);
    try {
        localStorage.setItem(key, JSON.stringify(updated));
    } catch (_) {}
}

function renderRecentChips() {
    const custEl = document.getElementById("recentCustomers");
    const suppEl = document.getElementById("recentSuppliers");
    if (custEl) {
        const recent = getRecent(RECENT_KEY_CUSTOMERS);
        custEl.innerHTML = recent.length
            ? "Recent: " +
              recent
                  .map(
                      (r) =>
                          `<button type="button" class="btn btn-link btn-sm p-0 me-2 text-decoration-none recent-chip" data-type="customer" data-id="${r.id}" data-name="${escapeHtml(r.name).replace(/"/g, "&quot;")}">${escapeHtml(r.name)}</button>`,
                  )
                  .join("")
            : "";
        custEl.querySelectorAll(".recent-chip[data-type=customer]").forEach(
            (btn) =>
                (btn.onclick = () =>
                    selectRecentCustomer(
                        Number(btn.dataset.id),
                        btn.dataset.name || "",
                    )),
        );
    }
    if (suppEl) {
        const recent = getRecent(RECENT_KEY_SUPPLIERS);
        suppEl.innerHTML = recent.length
            ? "Recent: " +
              recent
                  .map(
                      (r) =>
                          `<button type="button" class="btn btn-link btn-sm p-0 me-2 text-decoration-none recent-chip" data-type="supplier" data-id="${r.id}" data-name="${escapeHtml(r.name).replace(/"/g, "&quot;")}">${escapeHtml(r.name)}</button>`,
                  )
                  .join("")
            : "";
        suppEl.querySelectorAll(".recent-chip[data-type=supplier]").forEach(
            (btn) =>
                (btn.onclick = () =>
                    selectRecentSupplier(
                        Number(btn.dataset.id),
                        btn.dataset.name || "",
                    )),
        );
    }
}

function selectRecentCustomer(id, name) {
    orderCustomerAc?.setValue({ id, name });
}

function selectRecentSupplier(id, name) {
    orderSupplierAc?.setValue({ id, name });
}

document.addEventListener("DOMContentLoaded", () => {
    orderCustomerAc = Autocomplete.init(
        document.getElementById("orderCustomer"),
        {
            resource: "customers",
            placeholder: "Type customer name or code...",
            onSelect: (item) => saveRecent(RECENT_KEY_CUSTOMERS, item),
        },
    );
    orderSupplierAc = Autocomplete.init(
        document.getElementById("orderSupplier"),
        {
            resource: "suppliers",
            placeholder: "Type supplier name or code...",
            onSelect: (item) => saveRecent(RECENT_KEY_SUPPLIERS, item),
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
    const curSel = document.getElementById("orderCurrency");
    if (curSel) curSel.addEventListener("change", updateOrderTotals);
    const statusFromUrl = new URLSearchParams(window.location.search).get(
        "status",
    );
    if (statusFromUrl) {
        const filterStatus = document.getElementById("filterStatus");
        if (filterStatus) filterStatus.value = statusFromUrl;
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
        const submittedCount = rows.filter((r) => r.status === "Submitted").length;
        const bulkBtn = document.getElementById("bulkApproveBtn");
        if (bulkBtn) bulkBtn.classList.toggle("d-none", submittedCount === 0);

        const selectAll = document.getElementById("orderSelectAll");
        if (selectAll) {
            selectAll.checked = false;
            selectAll.onclick = () => {
                const checked = selectAll.checked;
                tbody.querySelectorAll(".order-bulk-cb").forEach((cb) => (cb.checked = checked));
            };
        }

        tbody.innerHTML =
            rows
                .map(
                    (r) => {
                        const canBulk = r.status === "Submitted";
                        return `
      <tr data-order-id="${r.id}" data-status="${escapeHtml(r.status)}">
        <td class="text-center">${canBulk ? `<input type="checkbox" class="form-check-input order-bulk-cb" data-order-id="${r.id}">` : ""}</td>
        <td>${r.id}</td>
        <td>${escapeHtml(r.customer_name)}</td>
        <td>${escapeHtml(r.supplier_name)}</td>
        <td>${r.expected_ready_date}</td>
        <td><span class="badge bg-secondary">${escapeHtml(r.status)}</span></td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-primary" onclick="editOrder(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-secondary" onclick="copyOrder(${r.id})" title="Duplicate as new draft">Copy</button>
          ${r.status === "Draft" ? `<button class="btn btn-sm btn-success" onclick="submitOrder(${r.id})">Submit</button>` : ""}
          ${r.status === "Submitted" ? `<button class="btn btn-sm btn-success" onclick="approveOrder(${r.id})">Approve</button>` : ""}
          ${r.status === "AwaitingCustomerConfirmation" ? `<button class="btn btn-sm btn-warning" onclick="confirmOrder(${r.id})">Confirm</button>` : ""}
        </td>
      </tr>
    `;
                    },
                )
                .join("") ||
            '<tr><td colspan="7" class="text-muted">No orders yet.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function exportOrdersCsv() {
    try {
        const status = document.getElementById("filterStatus").value;
        const customerId = filterCustomerAc?.getSelectedId() || "";
        let path = "/orders?";
        if (status) path += "status=" + encodeURIComponent(status) + "&";
        if (customerId) path += "customer_id=" + encodeURIComponent(customerId);
        const res = await api("GET", path);
        const rows = res.data || [];
        const headers = [
            "ID",
            "Customer",
            "Supplier",
            "Expected Ready",
            "Status",
            "Total CBM",
            "Total Weight",
        ];
        const lines = [headers.join(",")];
        rows.forEach((r) => {
            const cbm = (r.items || []).reduce(
                (s, i) => s + (parseFloat(i.declared_cbm) || 0),
                0,
            );
            const wt = (r.items || []).reduce(
                (s, i) => s + (parseFloat(i.declared_weight) || 0),
                0,
            );
            lines.push(
                [
                    r.id,
                    '"' + (r.customer_name || "").replace(/"/g, '""') + '"',
                    '"' + (r.supplier_name || "").replace(/"/g, '""') + '"',
                    r.expected_ready_date || "",
                    r.status || "",
                    cbm.toFixed(4),
                    wt.toFixed(2),
                ].join(","),
            );
        });
        const csv = lines.join("\n");
        const blob = new Blob(["\ufeff" + csv], {
            type: "text/csv;charset=utf-8",
        });
        const a = document.createElement("a");
        a.href = URL.createObjectURL(blob);
        a.download = "orders_" + new Date().toISOString().slice(0, 10) + ".csv";
        a.click();
        URL.revokeObjectURL(a.href);
        showToast("Exported " + rows.length + " orders");
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
    renderRecentChips();
    loadOrderTemplatesDropdown();
}

async function loadOrderTemplatesDropdown() {
    const sel = document.getElementById("orderTemplateSelect");
    if (!sel) return;
    try {
        const res = await api("GET", "/order-templates");
        const list = res.data || [];
        sel.innerHTML =
            '<option value="">Load template...</option>' +
            list
                .map(
                    (t) =>
                        `<option value="${t.id}">${escapeHtml(t.name)}</option>`,
                )
                .join("");
    } catch (_) {
        sel.innerHTML = '<option value="">Load template...</option>';
    }
}

async function loadOrderTemplate(id) {
    if (!id) return;
    try {
        const res = await api("GET", "/order-templates/" + id);
        const tpl = res.data;
        if (!tpl?.items?.length) {
            showToast("Template has no items", "warning");
            return;
        }
        document.getElementById("orderItemsBody").innerHTML = "";
        itemIndex = 0;
        for (const it of tpl.items) {
            addOrderItem();
            const last = document.querySelector(
                "#orderItemsBody tr:last-child",
            );
            if (!last) continue;
            const cartons = it.cartons ?? 0;
            const qtyPerCtn = it.qty_per_carton ?? 0;
            const qty =
                it.quantity ??
                (cartons > 0 && qtyPerCtn > 0 ? cartons * qtyPerCtn : 0);
            const denom =
                cartons > 0
                    ? qtyPerCtn > 0
                        ? cartons * qtyPerCtn
                        : cartons
                    : qty || 1;
            const cbmPerUnit =
                denom > 0 && it.declared_cbm
                    ? (parseFloat(it.declared_cbm) / denom).toFixed(4)
                    : "";
            last.querySelector(".item-product-id").value = it.product_id || "";
            last.querySelector(".item-item-no").value = it.item_no || "";
            last.querySelector(".item-shipping-code").value =
                it.shipping_code || "";
            last.querySelector(".item-desc").value =
                it.description_cn || it.description_en || "";
            last.querySelector(".item-cartons").value = cartons || "";
            last.querySelector(".item-qty-per-ctn").value = qtyPerCtn || "";
            last.querySelector(".item-qty").value = qty || "";
            last.querySelector(".item-unit-price").value = it.unit_price ?? "";
            last.querySelector(".item-cbm").value = cbmPerUnit;
            last.querySelector(".item-l").value = it.item_length ?? "";
            last.querySelector(".item-w").value = it.item_width ?? "";
            last.querySelector(".item-h").value = it.item_height ?? "";
            last.querySelector(".item-weight").value =
                it.declared_weight ?? "";
            updateItemComputed(last.dataset.idx);
        }
        updateOrderTotals();
        document.getElementById("orderTemplateSelect").value = "";
        showToast("Template loaded");
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function collectItemsForTemplate() {
    const items = [];
    document.querySelectorAll("#orderItemsBody tr").forEach((tr) => {
        const cartons = parseInt(tr.querySelector(".item-cartons")?.value || 0, 10);
        const qtyPerCtn = parseFloat(tr.querySelector(".item-qty-per-ctn")?.value || 0);
        const qtyInput = parseFloat(tr.querySelector(".item-qty")?.value || 0);
        const qty = cartons > 0 && qtyPerCtn > 0 ? cartons * qtyPerCtn : qtyInput;
        if (qty <= 0 && cartons <= 0) return;
        const unit = cartons > 0 ? "cartons" : "pieces";
        const totalCbm = parseFloat(tr.querySelector(".item-total-cbm")?.textContent || 0);
        const totalGw = parseFloat(tr.querySelector(".item-total-gw")?.textContent || 0);
        const desc = tr.querySelector(".item-desc")?.value?.trim();
        const productId = tr.querySelector(".item-product-id")?.value;
        const l = parseFloat(tr.querySelector(".item-l")?.value) || 0;
        const w = parseFloat(tr.querySelector(".item-w")?.value) || 0;
        const h = parseFloat(tr.querySelector(".item-h")?.value) || 0;
        items.push({
            product_id: productId || null,
            item_no: tr.querySelector(".item-item-no")?.value?.trim() || null,
            shipping_code: tr.querySelector(".item-shipping-code")?.value?.trim() || null,
            cartons: cartons || null,
            qty_per_carton: qtyPerCtn || null,
            quantity: qty,
            unit,
            declared_cbm: totalCbm || null,
            declared_weight: totalGw || null,
            item_length: l > 0 ? l : null,
            item_width: w > 0 ? w : null,
            item_height: h > 0 ? h : null,
            unit_price: parseFloat(tr.querySelector(".item-unit-price")?.value || 0) || null,
            total_amount: qty > 0 ? parseFloat(tr.querySelector(".item-total-amount")?.textContent || 0) : null,
            description_cn: desc || null,
            description_en: desc || null,
        });
    });
    return items;
}

async function saveOrderAsTemplate() {
    const items = collectItemsForTemplate();
    if (!items.length) {
        showToast("Add at least one item to save as template", "warning");
        return;
    }
    const name = prompt("Template name:");
    if (!name?.trim()) return;
    const templateItems = items.map((it) => ({
        item_no: it.item_no,
        shipping_code: it.shipping_code,
        product_id: it.product_id,
        description_cn: it.description_cn,
        description_en: it.description_en,
        cartons: it.cartons,
        qty_per_carton: it.qty_per_carton,
        quantity: it.quantity,
        unit: it.unit,
        declared_cbm: it.declared_cbm,
        declared_weight: it.declared_weight,
        item_length: it.item_length,
        item_width: it.item_width,
        item_height: it.item_height,
        unit_price: it.unit_price,
        total_amount: it.total_amount,
    }));
    try {
        await api("POST", "/order-templates", {
            name: name.trim(),
            items: templateItems,
        });
        showToast("Template saved");
        loadOrderTemplatesDropdown();
    } catch (e) {
        showToast(e.message, "danger");
    }
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
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-cbm" min="0" placeholder="CBM" data-idx="${idx}" style="width:70px" title="CBM or use L×W×H below">
        <div class="d-flex gap-1 mt-1"><input type="number" step="0.01" class="form-control form-control-sm item-l" placeholder="L" style="width:45px" title="Length cm"><input type="number" step="0.01" class="form-control form-control-sm item-w" placeholder="W" style="width:45px"><input type="number" step="0.01" class="form-control form-control-sm item-h" placeholder="H" style="width:45px"></div></td>
    <td><span class="item-total-cbm" data-idx="${idx}">0</span></td>
    <td><input type="number" step="0.0001" class="form-control form-control-sm item-weight" min="0" placeholder="0" data-idx="${idx}" style="width:70px" title="Weight per piece (kg)"></td>
    <td><span class="item-total-gw" data-idx="${idx}">0</span></td>
    <td><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove(); updateOrderTotals();">×</button></td>`;
    tbody.appendChild(row);
    row.querySelector(".item-photo-input").addEventListener("change", (e) =>
        handleItemPhoto(e, idx),
    );
    [
        "item-cartons",
        "item-qty-per-ctn",
        "item-unit-price",
        "item-qty",
        "item-cbm",
        "item-weight",
    ].forEach((cls) => {
        const el = row.querySelector(`.${cls}`);
        if (el)
            el.addEventListener("input", () => {
                updateItemComputed(idx);
                updateOrderTotals();
            });
    });
    ["item-l", "item-w", "item-h"].forEach((cls) => {
        const el = row.querySelector(`.${cls}`);
        if (el) {
            el.addEventListener("input", () => {
                const l = parseFloat(row.querySelector(".item-l")?.value) || 0;
                const w = parseFloat(row.querySelector(".item-w")?.value) || 0;
                const h = parseFloat(row.querySelector(".item-h")?.value) || 0;
                if (l > 0 && w > 0 && h > 0) {
                    row.querySelector(".item-cbm").value = (
                        (l * w * h) /
                        1000000
                    ).toFixed(4);
                }
                updateItemComputed(idx);
                updateOrderTotals();
            });
        }
    });
    row.querySelector(".item-cbm")?.addEventListener("input", () => {
        if (parseFloat(row.querySelector(".item-cbm")?.value || 0) > 0) {
            row.querySelector(".item-l").value =
                row.querySelector(".item-w").value =
                row.querySelector(".item-h").value =
                    "";
        }
    });
    updateOrderTotals();
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

    let cbmPerUnit = parseFloat(tr.querySelector(".item-cbm")?.value || 0);
    const l = parseFloat(tr.querySelector(".item-l")?.value) || 0;
    const w = parseFloat(tr.querySelector(".item-w")?.value) || 0;
    const h = parseFloat(tr.querySelector(".item-h")?.value) || 0;
    if (cbmPerUnit <= 0 && l > 0 && w > 0 && h > 0) {
        cbmPerUnit = (l * w * h) / 1000000;
    }
    const totalCbm =
        cbmPerUnit * (cartons > 0 ? cartons : totalQty > 0 ? totalQty : 1);
    tr.querySelector(".item-total-cbm").textContent = totalCbm.toFixed(4);

    const weightPc = parseFloat(tr.querySelector(".item-weight")?.value || 0);
    const totalGw = weightPc * (totalQty > 0 ? totalQty : 0);
    tr.querySelector(".item-total-gw").textContent = totalGw.toFixed(0);

    updateOrderTotals();
}

function updateOrderTotals() {
    let totalAmount = 0,
        totalCbm = 0,
        totalWeight = 0;
    document.querySelectorAll("#orderItemsBody tr[data-idx]").forEach((tr) => {
        totalAmount += parseFloat(
            tr.querySelector(".item-total-amount")?.textContent || 0,
        );
        totalCbm += parseFloat(
            tr.querySelector(".item-total-cbm")?.textContent || 0,
        );
        totalWeight += parseFloat(
            tr.querySelector(".item-total-gw")?.textContent || 0,
        );
    });
    const cur = document.getElementById("orderCurrency")?.value || "USD";
    const sym = cur === "RMB" ? "¥" : "$";
    const elAmount = document.getElementById("orderTotalAmount");
    const elCbm = document.getElementById("orderTotalCbm");
    const elWeight = document.getElementById("orderTotalWeight");
    if (elAmount) elAmount.textContent = sym + totalAmount.toFixed(2);
    if (elCbm) elCbm.textContent = totalCbm.toFixed(4);
    if (elWeight) elWeight.textContent = totalWeight.toFixed(0);
}

async function copyOrder(id) {
    try {
        const res = await api("GET", "/orders/" + id);
        const o = res.data;
        document.getElementById("orderId").value = "";
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
        document.getElementById("orderCurrency").value = o.currency || "USD";
        document.getElementById("orderModalTitle").textContent =
            "Copy of Order #" + id;
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
            const denom =
                (it.cartons || 0) > 0
                    ? it.cartons
                    : (it.quantity || 0) > 0
                      ? it.quantity
                      : 1;
            last.querySelector(".item-cbm").value =
                denom > 0
                    ? ((it.declared_cbm || 0) / denom).toFixed(4)
                    : (it.declared_cbm ?? "");
            last.querySelector(".item-l").value = it.item_length ?? "";
            last.querySelector(".item-w").value = it.item_width ?? "";
            last.querySelector(".item-h").value = it.item_height ?? "";
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
        if (!o.items || o.items.length === 0) addOrderItem();
        new bootstrap.Modal(document.getElementById("orderModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
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
        document.getElementById("orderCurrency").value = o.currency || "USD";
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
            const denom =
                (it.cartons || 0) > 0
                    ? it.cartons
                    : (it.quantity || 0) > 0
                      ? it.quantity
                      : 1;
            last.querySelector(".item-cbm").value =
                denom > 0
                    ? ((it.declared_cbm || 0) / denom).toFixed(4)
                    : (it.declared_cbm ?? "");
            last.querySelector(".item-l").value = it.item_length ?? "";
            last.querySelector(".item-w").value = it.item_width ?? "";
            last.querySelector(".item-h").value = it.item_height ?? "";
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
        const cbmPc = parseFloat(tr.querySelector(".item-cbm")?.value || 0);
        const l = parseFloat(tr.querySelector(".item-l")?.value) || 0;
        const w = parseFloat(tr.querySelector(".item-w")?.value) || 0;
        const h = parseFloat(tr.querySelector(".item-h")?.value) || 0;
        const cbmFromLwh = l > 0 && w > 0 && h > 0 ? (l * w * h) / 1000000 : 0;
        const effectiveCbmPc = cbmPc > 0 ? cbmPc : cbmFromLwh;
        const weightPc = parseFloat(
            tr.querySelector(".item-weight")?.value || 0,
        );
        const totalCbm = parseFloat(
            tr.querySelector(".item-total-cbm")?.textContent || 0,
        );
        const totalGw = parseFloat(
            tr.querySelector(".item-total-gw")?.textContent || 0,
        );
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
        if (cbmPc <= 0 && cbmFromLwh <= 0) {
            showToast("Each item needs CBM or L/W/H (cm)", "danger");
            return null;
        }
        items.push({
            product_id: productId || null,
            item_no: itemNo || null,
            shipping_code: shippingCode || null,
            cartons: cartons || null,
            qty_per_carton: qtyPerCtn || null,
            quantity: qty,
            unit,
            declared_cbm: totalCbm,
            declared_weight: totalGw,
            item_length: l > 0 ? l : null,
            item_width: w > 0 ? w : null,
            item_height: h > 0 ? h : null,
            unit_price: unitPrice || null,
            total_amount: qty > 0 && unitPrice > 0 ? qty * unitPrice : null,
            image_paths: imagePaths.length ? imagePaths : null,
            description_cn: desc || null,
            description_en: desc || null,
        });
    });
    return items;
}

async function saveOrder() {
    const id = document.getElementById("orderId").value;
    const items = collectOrderItems();
    if (!items) return;
    const payload = {
        customer_id: orderCustomerAc?.getSelectedId() || "",
        supplier_id: orderSupplierAc?.getSelectedId() || "",
        expected_ready_date: document.getElementById("orderExpectedDate").value,
        currency: document.getElementById("orderCurrency")?.value || "USD",
        items,
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

async function bulkApproveOrders() {
    const checked = Array.from(
        document.querySelectorAll(".order-bulk-cb:checked"),
    ).map((cb) => Number(cb.dataset.orderId));
    if (checked.length === 0) {
        showToast("Select orders to approve", "warning");
        return;
    }
    try {
        let ok = 0;
        let err = 0;
        for (const id of checked) {
            try {
                await api("POST", "/orders/" + id + "/approve", {});
                ok++;
            } catch (e) {
                err++;
                console.warn("Approve failed for order " + id, e);
            }
        }
        showToast(
            `Approved ${ok} order(s)` + (err ? `; ${err} failed` : ""),
            err ? "warning" : "success",
        );
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
