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
        <td>${esc(r.default_shipping_code || r.code || "-")}</td>
        <td>${esc(r.name)}${r.priority_level && r.priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${esc(r.priority_note || "")}">${esc(r.priority_level)}</span>` : ""}${r.default_shipping_code ? `<br><small class="text-muted">Ship code: ${esc(r.default_shipping_code)}</small>` : ""}</td>
        <td>${esc(r.phone || "-")}</td>
        <td class="text-truncate" style="max-width:180px" title="${esc(r.address || "")}">${esc((r.address || "-").substring(0, 50))}${(r.address || "").length > 50 ? "…" : ""}</td>
        <td>${esc(r.payment_terms || "-")}</td>
        <td class="table-actions">
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="editCustomer(${r.id})">Edit</button>
          <button type="button" class="btn btn-sm btn-outline-info" onclick="showOrders(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">Orders</button>
          <button type="button" class="btn btn-sm btn-outline-success" onclick="openDepositModal(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">Deposit</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="showBalance(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">Balance</button>
          <button type="button" class="btn btn-sm btn-outline-info" onclick="generatePortalLink(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')" title="One-time portal link">Portal</button>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openMessagesModal(${r.id}, '${esc(r.name).replace(/'/g, "\\'")}')">Messages</button>
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
let customerCountryShipping = [];

window.addCustomerPaymentLink = function addCustomerPaymentLink(
    name = "",
    value = "",
) {
    const id = Date.now();
    customerPaymentLinks.push({ id, name, value });
    renderCustomerPaymentLinks();
};

window.removeCustomerPaymentLink = function removeCustomerPaymentLink(id) {
    customerPaymentLinks = customerPaymentLinks.filter((p) => p.id !== id);
    renderCustomerPaymentLinks();
};

window.addCustomerCountryShipping = function addCustomerCountryShipping(countryId = null, countryName = "", countryCode = "", shippingCode = "") {
    const id = Date.now() + Math.random();
    customerCountryShipping.push({ id, country_id: countryId, country_name: countryName, country_code: countryCode, shipping_code: shippingCode });
    renderCustomerCountryShipping();
    setTimeout(initCountryShippingAutocompletes, 50);
};

window.removeCustomerCountryShipping = function removeCustomerCountryShipping(id) {
    customerCountryShipping = customerCountryShipping.filter((c) => c.id !== id);
    renderCustomerCountryShipping();
};

function renderCustomerCountryShipping() {
    const container = document.getElementById("customerCountryShippingContainer");
    if (!container) return;
    countryShippingAcInstances = {};
    container.innerHTML = customerCountryShipping
        .map(
            (c) => `
      <div class="d-flex gap-2 mb-2 align-items-center" data-country-shipping-id="${esc(String(c.id))}">
        <input type="text" class="form-control form-control-sm flex-grow-1 js-country-input" placeholder="Search country..." data-id="${esc(String(c.id))}" value="${esc(c.country_name || "")}" autocomplete="off">
        <input type="hidden" class="js-country-id" data-id="${esc(String(c.id))}" value="${c.country_id || ""}">
        <input type="text" class="form-control form-control-sm js-shipping-code-input" style="min-width:120px" placeholder="Shipping code" data-id="${esc(String(c.id))}" value="${esc(c.shipping_code || "")}">
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeCustomerCountryShipping('${esc(String(c.id))}')">×</button>
      </div>
    `,
        )
        .join("");
    document.querySelectorAll(".js-shipping-code-input").forEach((inp) => {
        inp.addEventListener("input", () => {
            const c = customerCountryShipping.find((x) => String(x.id) === inp.dataset.id);
            if (c) c.shipping_code = inp.value;
        });
    });
}


let countryShippingAcInstances = {};

function initCountryShippingAutocompletes() {
    document.querySelectorAll(".js-country-input").forEach((inp) => {
        const dataId = inp.dataset.id;
        if (countryShippingAcInstances[dataId]) return;
        countryShippingAcInstances[dataId] = Autocomplete.init(inp, {
            resource: "countries",
            searchPath: "/search",
            minChars: 0,
            placeholder: "Search country...",
            displayValue: (c) => c.name + " (" + c.code + ")",
            renderItem: (c) => c.name + " (" + c.code + ")",
            onSelect: (item) => {
                const row = customerCountryShipping.find((x) => String(x.id) === dataId);
                if (row) {
                    row.country_id = item.id;
                    row.country_name = item.name;
                    row.country_code = item.code;
                }
                document.querySelectorAll(".js-country-id").forEach((hid) => {
                    if (hid.dataset.id === dataId) hid.value = item.id;
                });
            },
        });
    });
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

window.updatePaymentLinkName = function updatePaymentLinkName(id, v) {
    const p = customerPaymentLinks.find((x) => x.id === id);
    if (p) p.name = v;
};

window.updatePaymentLinkValue = function updatePaymentLinkValue(id, v) {
    const p = customerPaymentLinks.find((x) => x.id === id);
    if (p) p.value = v;
};

function openCustomerForm() {
    document.getElementById("customerForm").reset();
    document.getElementById("customerId").value = "";
    document.getElementById("customerModalTitle").textContent = "Add Customer";
    customerPaymentLinks = [];
    customerCountryShipping = [];
    countryShippingAcInstances = {};
    renderCustomerPaymentLinks();
    renderCustomerCountryShipping();
    const passportList = document.getElementById("customerPassportList");
    if (passportList) passportList.innerHTML = '<p class="text-muted small mb-0">Save customer first to add passport/ID attachments</p>';
}

async function editCustomer(id) {
    try {
        const res = await api("GET", "/customers/" + id);
        const d = res.data;
        document.getElementById("customerId").value = d.id;
        document.getElementById("customerName").value = d.name || "";
        document.getElementById("customerPhone").value = d.phone || "";
        const emailEl = document.getElementById("customerEmail");
        if (emailEl) emailEl.value = d.email || "";
        document.getElementById("customerAddress").value = d.address || "";
        document.getElementById("customerPaymentTerms").value =
            d.payment_terms || "";
        document.getElementById("customerPriorityLevel").value =
            d.priority_level || "normal";
        document.getElementById("customerPriorityNote").value =
            d.priority_note || "";
        document.getElementById("customerDefaultShippingCode").value =
            d.default_shipping_code || "";
        customerPaymentLinks = (d.payment_links || []).map((p, i) => ({
            id: Date.now() + i,
            name: p.name || "",
            value: p.value || "",
        }));
        customerCountryShipping = (d.country_shipping || []).map((cs, i) => ({
            id: Date.now() + i + 1000,
            country_id: cs.country_id,
            country_name: cs.country_name || "",
            country_code: cs.country_code || "",
            shipping_code: cs.shipping_code || "",
        }));
        countryShippingAcInstances = {};
        renderCustomerPaymentLinks();
        renderCustomerCountryShipping();
        document.getElementById("customerModalTitle").textContent =
            "Edit Customer";
        new bootstrap.Modal(document.getElementById("customerModal")).show();
        setTimeout(initCountryShippingAutocompletes, 100);
        loadCustomerPassportAttachments(id);
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveCustomer() {
    const id = document.getElementById("customerId").value;
    const payload = {
        name: document.getElementById("customerName").value.trim(),
        phone: document.getElementById("customerPhone").value.trim() || null,
        email: document.getElementById("customerEmail")?.value?.trim() || null,
        address:
            document.getElementById("customerAddress").value.trim() || null,
        payment_terms:
            document.getElementById("customerPaymentTerms").value.trim() ||
            null,
        priority_level:
            document.getElementById("customerPriorityLevel").value || "normal",
        priority_note:
            document.getElementById("customerPriorityNote").value.trim() ||
            null,
        default_shipping_code:
            document
                .getElementById("customerDefaultShippingCode")
                .value.trim() || null,
        payment_links: customerPaymentLinks
            .filter((p) => (p.name || "").trim())
            .map((p) => ({
                name: (p.name || "").trim(),
                value: (p.value || "").trim(),
            })),
        country_shipping: customerCountryShipping
            .filter((c) => c.country_id)
            .map((c) => ({
                country_id: c.country_id,
                shipping_code: (c.shipping_code || "").trim() || null,
            })),
    };
    if (!payload.name || !payload.default_shipping_code) {
        showToast("Name and Default Shipping Code are required", "danger");
        return;
    }
    try {
        if (id) {
            const res = await api("PUT", "/customers/" + id, payload);
            showToast("Customer updated");
            if (res?.warning) showToast(res.warning, "warning");
        } else {
            const res = await api("POST", "/customers", payload);
            showToast("Customer created");
            if (res?.warning) showToast(res.warning, "warning");
        }
        bootstrap.Modal.getInstance(
            document.getElementById("customerModal"),
        ).hide();
        loadCustomers();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function loadCustomerPassportAttachments(customerId) {
    const listEl = document.getElementById("customerPassportList");
    if (!listEl) return;
    try {
        const res = await api("GET", "/design-attachments?entity_type=customer&entity_id=" + customerId);
        const list = res.data || [];
        const base = (window.API_BASE || "/cargochina/api/v1").replace("/api/v1", "");
        listEl.innerHTML = list.length
            ? list
                  .map(
                      (a) => `
        <div class="d-flex align-items-center gap-2 mb-1">
          <a href="${base}/backend/${a.file_path}" target="_blank" class="small text-truncate" style="max-width:200px">${esc(a.internal_note || "Passport/ID")}</a>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCustomerPassport(${a.id}, ${customerId})">×</button>
        </div>`,
                  )
                  .join("")
            : '<p class="text-muted small mb-0">No attachments</p>';
    } catch (e) {
        listEl.innerHTML = '<p class="text-muted small mb-0">No attachments</p>';
    }
}

window.deleteCustomerPassport = async function (attachmentId, customerId) {
    try {
        await api("DELETE", "/design-attachments/" + attachmentId);
        showToast("Attachment removed");
        if (customerId) loadCustomerPassportAttachments(customerId);
    } catch (e) {
        showToast(e.message, "danger");
    }
};

document.addEventListener("DOMContentLoaded", () => {
    const passportInput = document.getElementById("customerPassportInput");
    if (passportInput) {
        passportInput.addEventListener("change", async function () {
            const customerId = document.getElementById("customerId")?.value;
            if (!customerId) {
                showToast("Save customer first to add passport/ID attachments", "warning");
                return;
            }
            const files = this.files;
            if (!files?.length) return;
            for (let i = 0; i < files.length; i++) {
                try {
                    const path = await uploadFile(files[i]);
                    if (path) {
                        await api("POST", "/design-attachments", {
                            entity_type: "customer",
                            entity_id: parseInt(customerId, 10),
                            file_path: path,
                            file_type: (files[i].name || "").split(".").pop() || null,
                            internal_note: "Passport",
                        });
                        loadCustomerPassportAttachments(customerId);
                        showToast("Attachment added");
                    }
                } catch (e) {
                    showToast(e.message, "danger");
                }
            }
            this.value = "";
        });
    }
});

let depOrderAc = null;

function openDepositModal(customerId, name) {
    document.getElementById("depCustomerId").value = customerId;
    document.getElementById("depCustomerName").textContent = name;
    document.getElementById("depAmount").value = "";
    document.getElementById("depMethod").value = "";
    document.getElementById("depReference").value = "";
    document.getElementById("depNotes").value = "";
    const orderInput = document.getElementById("depOrderId");
    if (orderInput) orderInput.value = "";
    if (depOrderAc && typeof depOrderAc.setValue === "function") depOrderAc.setValue(null);
    if (typeof Autocomplete !== "undefined" && orderInput) {
        depOrderAc = Autocomplete.init(orderInput, {
            resource: "orders",
            searchPath: "/search",
            placeholder: "Type to search order (optional)…",
            extraParams: () => ({ customer_id: document.getElementById("depCustomerId")?.value || "" }),
            minChars: 0,
        });
    }
    new bootstrap.Modal(document.getElementById("depositModal")).show();
}

async function submitDeposit() {
    const customerId = document.getElementById("depCustomerId").value;
    const amount = parseFloat(document.getElementById("depAmount").value || 0);
    if (amount <= 0) {
        showToast("Amount must be positive", "danger");
        return;
    }
    const orderVal = (depOrderAc?.getSelectedId?.() || document.getElementById("depOrderId").value?.trim() || "").replace(/^#/, "");
    const orderId = orderVal && /^\d+$/.test(String(orderVal)) ? parseInt(orderVal, 10) : null;
    const payload = {
        amount,
        currency: document.getElementById("depCurrency").value,
        payment_method: document.getElementById("depMethod").value || null,
        reference_no: document.getElementById("depReference").value || null,
        notes: document.getElementById("depNotes").value || null,
        order_id: orderId,
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
                <td><span class="badge ${statusClass}">${esc(typeof statusLabel === "function" ? statusLabel(o.status) : o.status || "-")}</span></td>
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

window.generatePortalLink = function (customerId, name) {
    window._portalCustomerId = customerId;
    document.getElementById("portalCustomerName").textContent = name;
    document.getElementById("portalLinkResult").classList.add("d-none");
    document.getElementById("portalLinkInput").value = "";
    new bootstrap.Modal(document.getElementById("portalModal")).show();
};

window.doGeneratePortalLink = async function () {
    const customerId = window._portalCustomerId;
    const hours = parseInt(document.getElementById("portalHours").value) || 24;
    const btn = document.getElementById("portalGenBtn");
    try {
        setLoading(btn, true);
        const res = await api("POST", "/customer-portal-tokens", {
            customer_id: customerId,
            hours,
        });
        const link = res.data?.link || "";
        document.getElementById("portalLinkInput").value = link;
        document.getElementById("portalLinkResult").classList.remove("d-none");
        showToast("Link generated");
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
};

window.copyPortalLink = function () {
    const inp = document.getElementById("portalLinkInput");
    inp.select();
    document.execCommand("copy");
    showToast("Copied to clipboard");
};

window.openMessagesModal = function (customerId, name) {
    window._messagesCustomerId = customerId;
    document.getElementById("messagesCustomerName").textContent = name;
    document.getElementById("messageBody").value = "";
    loadMessages();
    new bootstrap.Modal(document.getElementById("messagesModal")).show();
};

async function loadMessages() {
    const customerId = window._messagesCustomerId;
    if (!customerId) return;
    try {
        const res = await api(
            "GET",
            "/internal-messages?customer_id=" + customerId,
        );
        const msgs = res.data || [];
        const list = document.getElementById("messagesList");
        list.innerHTML = msgs.length
            ? msgs
                  .map(
                      (m) =>
                          `<div class="mb-2 p-2 rounded ${m.sender_id ? "bg-light" : ""}"><small class="text-muted">${esc(m.sender_name || "System")} — ${m.created_at || ""}</small><div>${esc(m.body || "")}</div></div>`,
                  )
                  .join("")
            : '<p class="text-muted small">No messages yet.</p>';
        list.scrollTop = list.scrollHeight;
    } catch (e) {
        document.getElementById("messagesList").innerHTML =
            '<p class="text-danger small">Failed to load messages.</p>';
    }
}

window.sendMessage = async function () {
    const customerId = window._messagesCustomerId;
    const body = document.getElementById("messageBody").value.trim();
    if (!body) return;
    const btn = document.getElementById("messageSendBtn");
    try {
        setLoading(btn, true);
        await api("POST", "/internal-messages", {
            customer_id: customerId,
            body,
        });
        document.getElementById("messageBody").value = "";
        loadMessages();
        showToast("Message sent");
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
};

window.openImportModal = function (entity) {
    window._importEntity = entity || "customers";
    window._importOnSuccess = loadCustomers;
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
    const entity = window._importEntity || "customers";
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
