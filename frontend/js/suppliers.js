document.addEventListener("DOMContentLoaded", () => {
    loadSuppliers();
    registerUnsavedChangesGuard?.("#supplierForm");
    registerUnsavedChangesGuard?.("#paymentModal .modal-body");
    document
        .getElementById("supplierSearch")
        ?.addEventListener("keydown", (e) => {
            if (e.key === "Enter") applySupplierFilters();
        });
    document
        .getElementById("payAccountOption")
        ?.addEventListener("change", syncSupplierPaymentAccountSelection);
    ["supplierPaymentFilter", "supplierSort", "supplierOrder"].forEach((id) => {
        document
            .getElementById(id)
            ?.addEventListener("change", applySupplierFilters);
    });
});

let additionalIdIndex = 0;
let supplierAttachments = [];
let supplierPaymentLinkIndex = 0;
const fmtSupplierAmount = (value) =>
    typeof window.formatDisplayAmount === "function"
        ? window.formatDisplayAmount(value)
        : String(parseFloat(value || 0) || 0);
const fmtSupplierPercent = (value, maxDecimals = 1) =>
    typeof window.formatDisplayPercent === "function"
        ? window.formatDisplayPercent(value, maxDecimals)
        : String(parseFloat(value || 0) || 0);

function isBuyer() {
    const card = document.querySelector(".card[data-is-buyer]");
    return card?.dataset?.isBuyer === "1";
}

function getSupplierParams() {
    const params = new URLSearchParams();
    const q = document.getElementById("supplierSearch")?.value?.trim();
    const payment = document
        .getElementById("supplierPaymentFilter")
        ?.value?.trim();
    const sort = document.getElementById("supplierSort")?.value || "name";
    const order = document.getElementById("supplierOrder")?.value || "asc";
    if (q) params.set("q", q);
    if (payment) params.set("payment_status", payment);
    params.set("sort", sort);
    params.set("order", order);
    return params.toString();
}

function applySupplierFilters() {
    loadSuppliers();
}

async function loadSuppliers() {
    const btn = document.getElementById("supplierApplyBtn");
    try {
        if (btn) btn.disabled = true;
        const qs = getSupplierParams();
        const res = await api("GET", "/suppliers" + (qs ? "?" + qs : ""));
        const rows = res.data || [];
        const tbody = document.querySelector("#suppliersTable tbody");
        const buyer = isBuyer();
        tbody.innerHTML =
            rows
                .map((r) => {
                    const addIds = r.additional_ids || {};
                    const addIdsStr =
                        Object.entries(addIds)
                            .map(([k, v]) => `${k}: ${v}`)
                            .join("; ") || "-";
                    const nameEsc = escapeHtml(r.name).replace(/'/g, "\\'");
                    let actions = `<button class="btn btn-sm btn-outline-secondary" onclick="openVisitModal(${r.id}, '${nameEsc}')">Log visit</button>`;
                    if (buyer) {
                        actions += ` <button class="btn btn-sm btn-outline-primary" onclick="editSupplier(${r.id})">Edit</button>
          <a class="btn btn-sm btn-outline-dark" href="/cargochina/procurement_drafts.php?supplier_id=${r.id}">Draft Order</a>
          <button class="btn btn-sm btn-outline-success" onclick="openPaymentModal(${r.id}, '${nameEsc}')">Pay</button>
          <button class="btn btn-sm btn-outline-info" onclick="showPayHistory(${r.id}, '${nameEsc}')">History</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteSupplier(${r.id}, '${nameEsc}')">Del</button>`;
                    }
                    const scoreHtml =
                        r.reliability_score != null
                            ? `<span class="badge ${r.reliability_score >= 4 ? "bg-success" : r.reliability_score >= 2.5 ? "bg-warning text-dark" : "bg-danger"}" title="Reliability score based on orders, payments, variance rate">${fmtSupplierPercent(r.reliability_score, 1)} ★</span>`
                            : "";
                    const commissionHtml =
                        buyer && r.commission_rate != null
                            ? `<br><small class="text-muted">Commission: ${escapeHtml(String(r.commission_rate))}${r.commission_type === "fixed" ? "" : "%"} on ${escapeHtml(r.commission_applied_on === "sell_value" ? "sell" : "buy")}</small>`
                            : "";
                    const addrTitle = [r.address, r.factory_location]
                        .filter(Boolean)
                        .join(" | ");
                    return `
      <tr>
        <td>${escapeHtml(r.code)}</td>
        <td>${escapeHtml(r.store_id || "-")}</td>
        <td>
          ${escapeHtml(r.name)}
          ${scoreHtml ? `<br><small>${scoreHtml}</small>` : ""}
          ${commissionHtml}
          ${addrTitle ? `<br><small class="text-muted">${escapeHtml(addrTitle.substring(0, 60))}${addrTitle.length > 60 ? "…" : ""}</small>` : ""}
        </td>
        <td>${escapeHtml(r.phone || "-")}${r.fax ? `<br><small class="text-muted">Fax: ${escapeHtml(r.fax)}</small>` : ""}</td>
        <td>${escapeHtml(r.factory_location || "-")}</td>
        <td><small class="text-muted">${escapeHtml(addIdsStr)}</small></td>
        <td class="table-actions">${actions}</td>
      </tr>
    `;
                })
                .join("") ||
            '<tr><td colspan="7" class="text-muted">No suppliers yet.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        if (btn) btn.disabled = false;
    }
}

async function openVisitModal(supplierId, name) {
    document.getElementById("visitSupplierId").value = supplierId;
    document.getElementById("visitSupplierName").textContent = name;
    document.getElementById("visitType").value = "visit";
    document.getElementById("visitContent").value = "";
    try {
        const res = await api("GET", "/suppliers/" + supplierId);
        const ints = res.data?.interactions || [];
        const html =
            ints.length > 0
                ? ints
                      .slice(0, 10)
                      .map((i) => {
                          const c =
                              typeof i.content === "string"
                                  ? i.content
                                  : i.content && typeof i.content === "object"
                                    ? JSON.stringify(i.content)
                                    : "";
                          return `<div class="mb-1"><span class="badge bg-light text-dark">${escapeHtml(i.interaction_type)}</span> ${escapeHtml((i.created_at || "").slice(0, 16))} — ${escapeHtml(c.slice(0, 80))}${c.length > 80 ? "…" : ""} <small>by ${escapeHtml(i.created_by_name || "—")}</small></div>`;
                      })
                      .join("")
                : "<em>No visits logged yet.</em>";
        document.getElementById("visitHistory").innerHTML = html;
    } catch (_) {
        document.getElementById("visitHistory").innerHTML =
            "<em>Could not load history.</em>";
    }
    new bootstrap.Modal(document.getElementById("visitModal")).show();
}

async function submitVisit() {
    const supplierId = document.getElementById("visitSupplierId").value;
    const type = document.getElementById("visitType").value;
    const content = document.getElementById("visitContent").value.trim();
    if (!content) {
        showToast("Please enter notes", "warning");
        return;
    }
    const btn = document.getElementById("visitSubmitBtn");
    try {
        setLoading(btn, true);
        await api("POST", "/suppliers/" + supplierId + "/interactions", {
            interaction_type: type,
            content,
        });
        showToast("Visit logged");
        bootstrap.Modal.getInstance(
            document.getElementById("visitModal"),
        ).hide();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
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

function getSupplierPaymentMethods() {
    return Array.isArray(window.STANDARD_PAYMENT_METHODS)
        ? window.STANDARD_PAYMENT_METHODS
        : ["WeChat", "Alipay", "Bank Transfer"];
}

function renderSupplierPaymentMethodOptions(selected = "") {
    return getSupplierPaymentMethods()
        .map(
            (method) =>
                `<option value="${escapeHtml(method)}"${method === selected ? " selected" : ""}>${escapeHtml(method)}</option>`,
        )
        .join("");
}

function setSupplierPaymentQrPreview(row, qrPath = "", fileName = "") {
    const hidden = row.querySelector(".supplier-payment-link-qr");
    const preview = row.querySelector(".supplier-payment-qr-preview");
    if (!hidden || !preview) return;
    hidden.value = qrPath || "";
    if (!qrPath) {
        preview.classList.add("d-none");
        preview.innerHTML = "";
        return;
    }
    preview.classList.remove("d-none");
    preview.innerHTML = `
      <div class="d-flex align-items-center gap-2 mt-2">
        <a href="${typeof uploadedFileUrl === "function" ? uploadedFileUrl(qrPath) : `/cargochina/backend/${escapeHtml(qrPath)}`}" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-2 text-decoration-none">
          <img src="${typeof uploadedThumbUrl === "function" ? uploadedThumbUrl(qrPath, 48, 48, "cover") : `/cargochina/backend/${escapeHtml(qrPath)}`}" alt="QR" style="width:48px;height:48px;object-fit:cover;border-radius:10px;border:1px solid #dbe4f0;" loading="lazy">
          <span class="small text-muted">${escapeHtml(fileName || "QR saved")}</span>
        </a>
        <button type="button" class="btn btn-sm btn-outline-danger supplier-payment-qr-clear">${escapeHtml("×")}</button>
      </div>
    `;
    preview
        .querySelector(".supplier-payment-qr-clear")
        ?.addEventListener("click", () => setSupplierPaymentQrPreview(row, ""));
}

async function handleSupplierPaymentQrFiles(row, files) {
    const list = Array.from(files || []).filter(Boolean);
    if (!list.length) return;
    const file = list[0];
    const path = await uploadFile(file, { category: "supplier-payment-qr" });
    if (!path) return;
    setSupplierPaymentQrPreview(row, path, file.name || "QR image");
    showToast("Payment QR uploaded");
}

function bindSupplierPaymentRow(row) {
    const fileInput = row.querySelector(".supplier-payment-qr-input");
    const uploadBtn = row.querySelector(".supplier-payment-qr-btn");
    uploadBtn?.addEventListener("click", () => fileInput?.click());
    fileInput?.addEventListener("change", async function () {
        try {
            await handleSupplierPaymentQrFiles(row, this.files || []);
        } catch (e) {
            showToast(e.message, "danger");
        } finally {
            this.value = "";
        }
    });
    bindClipboardImagePaste?.(
        row,
        async (files) => {
            try {
                await handleSupplierPaymentQrFiles(row, files);
            } catch (e) {
                showToast(e.message, "danger");
            }
        },
        {
            requireTargetMatch: true,
            targetMatcher: (target) =>
                !!target.closest(".supplier-payment-link-row"),
        },
    );
}

function addSupplierPaymentLinkRow(rowData = {}) {
    const container = document.getElementById("supplierPaymentLinksContainer");
    if (!container) return;
    const idx = supplierPaymentLinkIndex++;
    const method =
        rowData.method ||
        (typeof normalizePaymentMethodName === "function"
            ? normalizePaymentMethodName(rowData.label || rowData.type || "")
            : "") ||
        "WeChat";
    const currency =
        rowData.currency === "USD" || rowData.currency === "RMB"
            ? rowData.currency
            : "RMB";
    const detail = rowData.value || rowData.link || "";
    const qrPath = rowData.qr_image_path || "";
    const row = document.createElement("div");
    row.className = "border rounded-3 p-2 supplier-payment-link-row";
    row.dataset.idx = idx;
    row.innerHTML = `
      <div class="row g-2 align-items-center">
        <div class="col-12 col-md-3">
          <select class="form-select form-select-sm supplier-payment-link-method">
            ${renderSupplierPaymentMethodOptions(method)}
          </select>
        </div>
        <div class="col-12 col-md-2">
          <select class="form-select form-select-sm supplier-payment-link-currency">
            <option value="RMB"${currency === "RMB" ? " selected" : ""}>RMB</option>
            <option value="USD"${currency === "USD" ? " selected" : ""}>USD</option>
          </select>
        </div>
        <div class="col-12 col-md-5">
          <input type="text" class="form-control form-control-sm supplier-payment-link-value" placeholder="Account / number / URL / account detail" value="${escapeHtml(detail)}">
          <input type="hidden" class="supplier-payment-link-qr" value="${escapeHtml(qrPath)}">
          <input type="file" class="d-none supplier-payment-qr-input" accept="image/*,.jpg,.jpeg,.png,.webp,.jfif,.gif">
        </div>
        <div class="col-8 col-md-1">
          <button type="button" class="btn btn-sm btn-outline-secondary w-100 supplier-payment-qr-btn">QR</button>
        </div>
        <div class="col-4 col-md-1 text-end">
          <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest('.supplier-payment-link-row').remove()">×</button>
        </div>
      </div>
      <div class="supplier-payment-qr-preview ${qrPath ? "" : "d-none"}"></div>
    `;
    container.appendChild(row);
    bindSupplierPaymentRow(row);
    setSupplierPaymentQrPreview(row, qrPath, rowData.file_name || "");
}

function collectSupplierPaymentLinks() {
    const rows = [];
    document
        .querySelectorAll("#supplierPaymentLinksContainer .supplier-payment-link-row")
        .forEach((row) => {
            const method = row
                .querySelector(".supplier-payment-link-method")
                ?.value?.trim();
            const value = row
                .querySelector(".supplier-payment-link-value")
                ?.value?.trim();
            const currency = row
                .querySelector(".supplier-payment-link-currency")
                ?.value?.trim();
            const qrImagePath = row
                .querySelector(".supplier-payment-link-qr")
                ?.value?.trim();
            if (!method && !value && !qrImagePath) return;
            rows.push({
                method: method || "Bank Transfer",
                label: method || "Bank Transfer",
                account_label: method || "Bank Transfer",
                value: value || "",
                currency: currency === "USD" ? "USD" : "RMB",
                qr_image_path: qrImagePath || null,
            });
        });
    return rows;
}

function formatSupplierPaymentLinks(links) {
    const rows = Array.isArray(links) ? links : [];
    if (!rows.length) return "No stored payment links yet.";
    return rows
        .map((row) => {
            const method =
                row?.method ||
                (typeof normalizePaymentMethodName === "function"
                    ? normalizePaymentMethodName(row?.label || row?.type || "")
                    : "") ||
                "Bank Transfer";
            const value = row?.value || row?.link || "—";
            const currency = row?.currency || "RMB";
            return `${method} (${currency}): ${value}`;
        })
        .join(" | ");
}

function populateSupplierPaymentAccountOptions(links) {
    const select = document.getElementById("payAccountOption");
    if (!select) return;
    const rows = Array.isArray(links) ? links : [];
    select.innerHTML = '<option value="">Choose saved account...</option>';
    rows.forEach((row, index) => {
        const option = document.createElement("option");
        const method =
            row?.method ||
            (typeof normalizePaymentMethodName === "function"
                ? normalizePaymentMethodName(row?.label || row?.type || "")
                : "") ||
            "Bank Transfer";
        option.value = String(index);
        option.dataset.method = method;
        option.dataset.detail = row?.value || row?.link || "";
        option.dataset.currency = row?.currency || "RMB";
        option.dataset.qr = row?.qr_image_path || "";
        option.dataset.label = row?.account_label || row?.label || method;
        option.textContent = `${method} (${option.dataset.currency}) - ${option.dataset.detail || "—"}`;
        select.appendChild(option);
    });
    if (rows.length === 1) {
        select.value = "0";
    }
    syncSupplierPaymentAccountSelection();
}

function syncSupplierPaymentAccountSelection() {
    const select = document.getElementById("payAccountOption");
    const detailInput = document.getElementById("payAccountDetail");
    const qrWrap = document.getElementById("payAccountQrWrap");
    if (!select || !detailInput || !qrWrap) return;
    const option = select.selectedOptions?.[0] || null;
    if (!option || !option.value) {
        detailInput.value = "";
        qrWrap.classList.add("d-none");
        qrWrap.innerHTML = "";
        return;
    }
    detailInput.value = option.dataset.detail || "";
    const channelEl = document.getElementById("payChannel");
    const currencyEl = document.getElementById("payCurrency");
    if (channelEl && option.dataset.method) {
        channelEl.value = option.dataset.method;
    }
    if (currencyEl && option.dataset.currency) {
        currencyEl.value = option.dataset.currency;
    }
    if (!option.dataset.qr) {
        qrWrap.classList.add("d-none");
        qrWrap.innerHTML = "";
        return;
    }
    qrWrap.classList.remove("d-none");
    qrWrap.innerHTML = `
      <div class="fw-semibold mb-1">Saved QR image</div>
      <a href="${typeof uploadedFileUrl === "function" ? uploadedFileUrl(option.dataset.qr) : `/cargochina/backend/${escapeHtml(option.dataset.qr)}`}" target="_blank" rel="noopener"><img src="${typeof uploadedThumbUrl === "function" ? uploadedThumbUrl(option.dataset.qr, 180, 180, "contain") : `/cargochina/backend/${escapeHtml(option.dataset.qr)}`}" alt="Payment QR" class="img-thumbnail" style="max-width: 180px;" loading="lazy"></a>
    `;
}

function openSupplierForm() {
    document.getElementById("supplierForm").reset();
    document.getElementById("supplierId").value = "";
    document.getElementById("supplierModalTitle").textContent = "Add Supplier";
    document.getElementById("additionalIdsContainer").innerHTML = "";
    document.getElementById("supplierFax").value = "";
    document.getElementById("supplierAddress").value = "";
    document.getElementById("supplierCommissionRate").value = "";
    document.getElementById("supplierCommissionType").value = "percentage";
    document.getElementById("supplierCommissionAppliedOn").value = "buy_value";
    document.getElementById("supplierPaymentFacilityDays").value = "";
    document.getElementById("supplierPaymentLinksContainer").innerHTML = "";
    const attachmentInput = document.getElementById("supplierAttachmentInput");
    if (attachmentInput) attachmentInput.value = "";
    supplierAttachments = [];
    renderSupplierAttachments();
    addAdditionalIdRow();
    addSupplierPaymentLinkRow();
    refreshUnsavedBaseline?.(document.getElementById("supplierForm"));
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
        document.getElementById("supplierFax").value = d.fax || "";
        document.getElementById("supplierFactory").value =
            d.factory_location || "";
        document.getElementById("supplierAddress").value = d.address || "";
        document.getElementById("supplierCommissionRate").value =
            d.commission_rate ?? "";
        document.getElementById("supplierCommissionType").value =
            d.commission_type || "percentage";
        document.getElementById("supplierCommissionAppliedOn").value =
            d.commission_applied_on || "buy_value";
        document.getElementById("supplierPaymentFacilityDays").value =
            d.payment_facility_days ?? "";
        document.getElementById("supplierNotes").value = d.notes || "";
        document.getElementById("supplierModalTitle").textContent =
            "Edit Supplier";
        document.getElementById("additionalIdsContainer").innerHTML = "";
        document.getElementById("supplierPaymentLinksContainer").innerHTML = "";
        const addIds = d.additional_ids || {};
        if (Object.keys(addIds).length) {
            Object.entries(addIds).forEach(([k, v]) =>
                addAdditionalIdRow(k, v),
            );
        } else {
            addAdditionalIdRow();
        }
        if ((d.payment_links || []).length) {
            d.payment_links.forEach((row) => addSupplierPaymentLinkRow(row));
        } else {
            addSupplierPaymentLinkRow();
        }
        await loadSupplierAttachments(id);
        refreshUnsavedBaseline?.(document.getElementById("supplierForm"));
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
        fax: document.getElementById("supplierFax").value.trim() || null,
        factory_location:
            document.getElementById("supplierFactory").value.trim() || null,
        address:
            document.getElementById("supplierAddress").value.trim() || null,
        commission_rate: document.getElementById("supplierCommissionRate").value
            ? parseFloat(
                  document.getElementById("supplierCommissionRate").value,
              )
            : null,
        commission_type:
            document.getElementById("supplierCommissionType").value ||
            "percentage",
        commission_applied_on:
            document.getElementById("supplierCommissionAppliedOn").value ||
            "buy_value",
        payment_facility_days:
            document.getElementById("supplierPaymentFacilityDays").value || null,
        payment_links: collectSupplierPaymentLinks(),
        notes: document.getElementById("supplierNotes").value.trim() || null,
        additional_ids: collectAdditionalIds(),
    };
    if (!payload.code || !payload.name) {
        showToast("Code and Name are required", "danger");
        return;
    }
    try {
        setLoading(btn, true);
        let res;
        if (id) {
            res = await api("PUT", "/suppliers/" + id, payload);
            showToast("Supplier updated");
        } else {
            res = await api("POST", "/suppliers", payload);
            const newId = res?.data?.id;
            if (newId) {
                document.getElementById("supplierId").value = newId;
                document.getElementById("supplierModalTitle").textContent =
                    "Edit Supplier";
                await loadSupplierAttachments(newId);
            }
            showToast("Supplier created. You can now add documents and photos.");
        }
        if (id) {
            refreshUnsavedBaseline?.(document.getElementById("supplierForm"));
            bootstrap.Modal.getInstance(
                document.getElementById("supplierModal"),
            ).hide();
        } else {
            refreshUnsavedBaseline?.(document.getElementById("supplierForm"));
        }
        loadSuppliers();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

function supplierAttachmentKind(fileType, url) {
    const kind = (fileType || url || "").toLowerCase();
    return /png|jpg|jpeg|gif|webp/.test(kind) ? "image" : "file";
}

function renderSupplierAttachments() {
    const listEl = document.getElementById("supplierAttachmentList");
    const supplierId = document.getElementById("supplierId")?.value || "";
    if (!listEl) return;
    if (!supplierId) {
        listEl.innerHTML =
            '<p class="text-muted small mb-0">Save supplier first to add documents and photos.</p>';
        return;
    }
    if (!supplierAttachments.length) {
        listEl.innerHTML =
            '<p class="text-muted small mb-0">No supplier documents uploaded yet.</p>';
        return;
    }
    listEl.innerHTML = supplierAttachments
        .map((attachment) => {
            const isImage =
                supplierAttachmentKind(
                    attachment.file_type,
                    attachment.file_path || attachment.url,
                ) === "image";
            return `<div class="d-flex align-items-center gap-2 border rounded-3 px-2 py-2 mb-2">
          ${
              isImage
                  ? `<img src="${escapeHtml(attachment.url)}" alt="Supplier attachment" style="width:52px;height:52px;object-fit:cover;border-radius:10px;border:1px solid #e2e8f0;">`
                  : `<div class="d-flex align-items-center justify-content-center bg-light border rounded-3" style="width:52px;height:52px;min-width:52px;">PDF</div>`
          }
          <div class="flex-grow-1 min-w-0">
            <a href="${escapeHtml(attachment.url)}" target="_blank" rel="noopener" class="small fw-semibold text-decoration-none d-block text-truncate">${escapeHtml(attachment.internal_note || attachment.file_type || "Supplier document")}</a>
            <div class="text-muted small">${escapeHtml((attachment.uploaded_at || "").replace("T", " ").slice(0, 16) || "")}</div>
          </div>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteSupplierAttachment(${attachment.id})">×</button>
        </div>`;
        })
        .join("");
}

async function loadSupplierAttachments(supplierId) {
    if (!supplierId) {
        supplierAttachments = [];
        renderSupplierAttachments();
        return;
    }
    try {
        const res = await api(
            "GET",
            `/design-attachments?entity_type=supplier&entity_id=${supplierId}`,
        );
        supplierAttachments = res.data || [];
    } catch (_) {
        supplierAttachments = [];
    }
    renderSupplierAttachments();
}

window.deleteSupplierAttachment = async function deleteSupplierAttachment(
    attachmentId,
) {
    const supplierId = document.getElementById("supplierId")?.value;
    if (!supplierId) return;
    try {
        await api("DELETE", "/design-attachments/" + attachmentId);
        showToast("Attachment removed");
        await loadSupplierAttachments(supplierId);
    } catch (e) {
        showToast(e.message, "danger");
    }
};

document.addEventListener("DOMContentLoaded", () => {
    const input = document.getElementById("supplierAttachmentInput");
    const section = document.getElementById("supplierAttachmentSection");
    if (!input) return;
    input.addEventListener("change", async function () {
        const supplierId = document.getElementById("supplierId")?.value;
        if (!supplierId) {
            showToast(
                "Save supplier first to add documents and photos",
                "warning",
            );
            this.value = "";
            return;
        }
        const files = Array.from(this.files || []);
        for (const file of files) {
            try {
                const path = await uploadFile(file);
                if (!path) continue;
                await api("POST", "/design-attachments", {
                    entity_type: "supplier",
                    entity_id: parseInt(supplierId, 10),
                    file_path: path,
                    file_type:
                        (file.name || "").split(".").pop()?.toLowerCase() ||
                        null,
                    internal_note: file.name || "Supplier attachment",
                });
            } catch (e) {
                showToast(e.message, "danger");
            }
        }
        await loadSupplierAttachments(supplierId);
        if (files.length) {
            showToast("Supplier attachments updated");
        }
        this.value = "";
    });
    bindClipboardImagePaste?.(
        section || input,
        async (files) => {
            const supplierId = document.getElementById("supplierId")?.value;
            if (!supplierId) {
                showToast(
                    "Save supplier first to add documents and photos",
                    "warning",
                );
                return;
            }
            for (const file of Array.from(files || [])) {
                try {
                    const path = await uploadFile(file);
                    if (!path) continue;
                    await api("POST", "/design-attachments", {
                        entity_type: "supplier",
                        entity_id: parseInt(supplierId, 10),
                        file_path: path,
                        file_type:
                            (file.name || "").split(".").pop()?.toLowerCase() ||
                            null,
                        internal_note: file.name || "Supplier attachment",
                    });
                } catch (e) {
                    showToast(e.message, "danger");
                }
            }
            await loadSupplierAttachments(supplierId);
            showToast("Supplier attachments updated");
        },
        {
            requireTargetMatch: true,
            targetMatcher: (target) =>
                !!target.closest("#supplierAttachmentSection"),
        },
    );
});

function openPaymentModal(supplierId, name) {
    document.getElementById("paymentSupplierId").value = supplierId;
    document.getElementById("paymentSupplierName").textContent = name;
    document.getElementById("payInvoiceAmount").value = "";
    document.getElementById("payAmount").value = "";
    document.getElementById("payCurrency").value = "RMB";
    document.getElementById("payChannel").value = "";
    document.getElementById("payAccountOption").innerHTML =
        '<option value="">Choose saved account...</option>';
    document.getElementById("payAccountDetail").value = "";
    document.getElementById("payAccountQrWrap").classList.add("d-none");
    document.getElementById("payAccountQrWrap").innerHTML = "";
    document.getElementById("payOrderId").value = "";
    document.getElementById("payNotes").value = "";
    document.getElementById("paySettlementNote").value = "";
    document.getElementById("payMarkedFull").checked = false;
    document.getElementById("payDiscountInfo").classList.add("d-none");
    document.getElementById("paySettlementNoteWrap").classList.add("d-none");
    document.getElementById("paymentSupplierContext").textContent =
        "Loading supplier payment options…";
    api("GET", "/suppliers/" + supplierId)
        .then((res) => {
            const supplier = res.data || {};
            const facility = supplier.payment_facility_days
                ? `Facility ${supplier.payment_facility_days} day(s)`
                : "No payment facility saved";
            const links = formatSupplierPaymentLinks(supplier.payment_links);
            document.getElementById("paymentSupplierContext").textContent =
                `${facility} | ${links}`;
            populateSupplierPaymentAccountOptions(supplier.payment_links || []);
            refreshUnsavedBaseline?.(
                document.querySelector("#paymentModal .modal-body"),
            );
        })
        .catch(() => {
            document.getElementById("paymentSupplierContext").textContent =
                "Could not load supplier payment options.";
            refreshUnsavedBaseline?.(
                document.querySelector("#paymentModal .modal-body"),
            );
        });
    new bootstrap.Modal(document.getElementById("paymentModal")).show();
}

document
    .getElementById("payInvoiceAmount")
    ?.addEventListener("input", updateDiscountPreview);
document
    .getElementById("payAmount")
    ?.addEventListener("input", updateDiscountPreview);
document
    .getElementById("payMarkedFull")
    ?.addEventListener("change", updateDiscountPreview);
function updateDiscountPreview() {
    const inv = parseFloat(
        document.getElementById("payInvoiceAmount")?.value || 0,
    );
    const paid = parseFloat(document.getElementById("payAmount")?.value || 0);
    const info = document.getElementById("payDiscountInfo");
    const markedFull =
        document.getElementById("payMarkedFull")?.checked || false;
    const noteWrap = document.getElementById("paySettlementNoteWrap");
    if (inv > 0 && paid > 0 && inv > paid) {
        const disc = inv - paid;
        const pct = fmtSupplierPercent((disc / inv) * 100, 1);
        info.textContent = markedFull
            ? `Settlement delta: ${fmtSupplierAmount(disc)} (${pct}%) will close the balance as fully settled by agreement.`
            : `Short-paid amount: ${fmtSupplierAmount(disc)} (${pct}%) will remain outstanding unless you mark it fully settled.`;
        info.classList.remove("d-none");
        noteWrap?.classList.toggle("d-none", !markedFull);
    } else {
        info.classList.add("d-none");
        noteWrap?.classList.add("d-none");
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
        payment_channel:
            document.getElementById("payChannel").value || null,
        payment_account_label:
            document.getElementById("payAccountOption")?.selectedOptions?.[0]
                ?.dataset?.label || null,
        payment_account_value:
            document.getElementById("payAccountOption")?.selectedOptions?.[0]
                ?.dataset?.detail || null,
        payment_account_qr_path:
            document.getElementById("payAccountOption")?.selectedOptions?.[0]
                ?.dataset?.qr || null,
        order_id: document.getElementById("payOrderId").value || null,
        marked_full_payment: document.getElementById("payMarkedFull").checked,
        payment_type: document.getElementById("payMarkedFull").checked
            ? "full"
            : "partial",
        notes: document.getElementById("payNotes").value || null,
        settlement_note:
            document.getElementById("paySettlementNote").value || null,
    };
    if (payload.marked_full_payment && payload.invoice_amount && amount < payload.invoice_amount) {
        payload.settlement_mode = "fully_settled_by_agreement";
    }
    const btn = document.getElementById("paySubmitBtn");
    try {
        setLoading(btn, true);
        await api("POST", "/suppliers/" + supplierId + "/payments", payload);
        showToast("Payment recorded");
        refreshUnsavedBaseline?.(
            document.querySelector("#paymentModal .modal-body"),
        );
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
            balHtml += `<span class="badge bg-secondary me-2">${cur}: Paid ${fmtSupplierAmount(b.total_paid)} / Invoiced ${fmtSupplierAmount(b.total_invoiced)} | Discount ${fmtSupplierAmount(b.total_discount)} | Outstanding ${fmtSupplierAmount(b.outstanding)}</span>`;
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
                <td>${(parseFloat(p.settlement_delta || p.discount_amount || 0) > 0 ? fmtSupplierAmount(p.settlement_delta || p.discount_amount) : "-")}${p.marked_full_payment ? ' <span class="badge bg-success">Full</span>' : ""}</td>
                <td>${p.currency}</td>
                <td>${escapeHtml([p.payment_type, p.payment_channel].filter(Boolean).join(" / ") || "-")}</td>
                <td>${p.order_id ? "#" + p.order_id : "-"}</td>
                <td>${escapeHtml([p.notes, p.settlement_mode ? `Settlement: ${p.settlement_mode}` : "", p.settlement_note].filter(Boolean).join(" | ") || "-")}</td>
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

window.openImportModal = function (entity) {
    window._importEntity = entity || "suppliers";
    window._importOnSuccess = loadSuppliers;
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
    const entity = window._importEntity || "suppliers";
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
