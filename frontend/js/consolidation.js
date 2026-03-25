let currentDraftId = null;
let eligibleOrders = [];
let draftOrders = [];
let draftCbmForCapacity = 0;
let draftWeightForCapacity = 0;
let draftContainerAc = null;

function renderCapacityBars(cbm, weight, container, hintEl) {
    if (!hintEl) return;
    if (!container) {
        hintEl.innerHTML =
            '<span class="text-muted small">Assign a container to see capacity</span>';
        return;
    }
    const maxCbm = parseFloat(container.max_cbm) || 1;
    const maxWt = parseFloat(container.max_weight) || 1;
    const cbmPct = Math.min(100, (cbm / maxCbm) * 100);
    const wtPct = Math.min(100, (weight / maxWt) * 100);
    const cbmColor =
        cbmPct >= 100 ? "#dc2626" : cbmPct >= 85 ? "#d97706" : "#16a34a";
    const wtColor =
        wtPct >= 100 ? "#dc2626" : wtPct >= 85 ? "#d97706" : "#16a34a";
    hintEl.innerHTML = `
      <div class="mt-2">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <small class="text-muted fw-semibold">CBM Fill</small>
          <small style="color:${cbmColor};font-weight:600">${cbm.toFixed(2)} / ${maxCbm} m³ (${cbmPct.toFixed(0)}%)</small>
        </div>
        <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
          <div style="height:100%;width:${cbmPct}%;background:${cbmColor};border-radius:4px;transition:width .4s;"></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-1 mt-2">
          <small class="text-muted fw-semibold">Weight Fill</small>
          <small style="color:${wtColor};font-weight:600">${weight.toFixed(0)} / ${maxWt} kg (${wtPct.toFixed(0)}%)</small>
        </div>
        <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
          <div style="height:100%;width:${wtPct}%;background:${wtColor};border-radius:4px;transition:width .4s;"></div>
        </div>
        ${cbmPct >= 100 || wtPct >= 100 ? '<div class="text-danger small fw-semibold mt-1">⚠ Capacity exceeded</div>' : cbmPct >= 85 || wtPct >= 85 ? '<div class="text-warning small fw-semibold mt-1">Almost full</div>' : '<div class="text-success small fw-semibold mt-1">✓ Within capacity</div>'}
      </div>`;
}

function canCreateContainers() {
    return (
        document.getElementById("consolidationPage")?.dataset
            ?.canCreateContainer === "1"
    );
}

function esc(s) {
    if (s == null || s === undefined) return "";
    const d = document.createElement("div");
    d.textContent = String(s);
    return d.innerHTML;
}

let containerPresets = {
    CONTAINER_20HQ_CBM: 28,
    CONTAINER_40HQ_CBM: 68,
    CONTAINER_45HQ_CBM: 78,
};

document.addEventListener("DOMContentLoaded", () => {
    try {
        loadContainers();
        loadShipmentDrafts();
        loadReadyTotals();
        loadContainerPresets();
        const docInput = document.getElementById("draftDocInput");
        if (docInput)
            docInput.onchange = () => handleDraftDocUpload(docInput.files);
        const countryInput = el("containerEditDestCountry");
        if (
            countryInput &&
            canCreateContainers() &&
            typeof Autocomplete !== "undefined"
        ) {
            Autocomplete.init(countryInput, {
                resource: "countries",
                displayValue: (c) => c.code || "",
                renderItem: (c) => (c.name ? `${c.name} (${c.code})` : c.code),
                placeholder: "Type country name or code (e.g. LB, Lebanon)",
            });
        }
        const containerSearchInput = el("draftContainerSearch");
        if (containerSearchInput && typeof Autocomplete !== "undefined") {
            draftContainerAc = Autocomplete.init(containerSearchInput, {
                resource: "containers",
                searchPath: "/search",
                displayValue: (c) => c?.code || "",
                renderItem: (c) =>
                    c ? `${c.code || ""} (${c.max_cbm || 0} CBM, ${c.max_weight || 0} kg)` : "",
                placeholder: "Search containers by code...",
                onSelect: (item) => {
                    if (item?.id) {
                        el("draftContainer").value = item.id;
                        renderCapacityBars(
                            draftCbmForCapacity,
                            draftWeightForCapacity,
                            item,
                            el("draftCapacityHint"),
                        );
                    }
                },
            });
            containerSearchInput.addEventListener("input", () => {
                if (!containerSearchInput.value.trim() && el("draftContainer"))
                    el("draftContainer").value = "";
            });
        }
    } catch (e) {
        console.error("Consolidation init:", e);
        if (typeof showToast === "function")
            showToast(e.message || "Load error", "danger");
    }
});

async function loadContainerPresets() {
    try {
        const r = await api("GET", "/config/container-presets");
        const d = r.data || {};
        containerPresets = {
            CONTAINER_20HQ_CBM: parseFloat(d.CONTAINER_20HQ_CBM) || 28,
            CONTAINER_40HQ_CBM: parseFloat(d.CONTAINER_40HQ_CBM) || 68,
            CONTAINER_45HQ_CBM: parseFloat(d.CONTAINER_45HQ_CBM) || 78,
        };
        const btns = document.querySelectorAll("[data-container-preset]");
        btns.forEach((btn) => {
            const code = btn.dataset.containerPreset;
            const cbm = containerPresets["CONTAINER_" + code + "_CBM"] || 28;
            btn.onclick = () => applyContainerPreset(code, cbm, 28000);
        });
    } catch (_) {}
}

function renderDraftDocuments(docs) {
    const list = document.getElementById("draftDocumentsList");
    if (!list) return;
    const docTypeLabel = {
        bol: "BOL",
        booking_confirmation: "Booking Conf.",
        invoice: "Invoice",
        other: "Other",
    };
    const requiredTypes = ["bol", "booking_confirmation"];
    const presentTypes = new Set(docs.map((d) => d.doc_type));
    const missingRequired = requiredTypes.filter((t) => !presentTypes.has(t));

    const completenessHtml =
        missingRequired.length === 0
            ? `<div class="d-inline-flex align-items-center gap-1 mb-2"><span class="badge bg-success">✓ All required documents present</span></div>`
            : `<div class="d-inline-flex align-items-center gap-1 mb-2"><span class="badge bg-warning text-dark">⚠ Missing: ${missingRequired.map((t) => docTypeLabel[t] || t).join(", ")}</span></div>`;

    list.innerHTML =
        completenessHtml +
        (docs.length > 0
            ? docs
                  .map(
                      (d) =>
                          `<div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge bg-light text-dark border">${esc(docTypeLabel[d.doc_type] || d.doc_type)}</span>
                            <a href="/cargochina/backend/${esc(d.file_path)}" target="_blank" class="small text-truncate" style="max-width:200px">${esc(d.file_path.split("/").pop())}</a>
                            <button type="button" class="btn btn-link btn-sm p-0 text-danger ms-auto" onclick="removeDraftDoc(${d.id})">✕</button>
                          </div>`,
                  )
                  .join("")
            : '<div class="text-muted small">No documents attached</div>');
}

async function saveDraftCarrierRefs() {
    if (!currentDraftId) return;
    try {
        await api("PUT", "/shipment-drafts/" + currentDraftId, {
            container_number:
                document.getElementById("draftContainerNumber").value.trim() ||
                null,
            booking_number:
                document.getElementById("draftBookingNumber").value.trim() ||
                null,
            tracking_url:
                document.getElementById("draftTrackingUrl").value.trim() ||
                null,
        });
        showToast("Carrier refs saved");
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function handleDraftDocUpload(files) {
    if (!currentDraftId || !files?.length) return;
    const docType = document.getElementById("draftDocType").value;
    for (const file of files) {
        try {
            const path = await uploadFile(file);
            if (path) {
                await api(
                    "POST",
                    "/shipment-drafts/" + currentDraftId + "/documents",
                    { file_path: path, doc_type: docType },
                );
                const r = await api(
                    "GET",
                    "/shipment-drafts/" + currentDraftId,
                );
                renderDraftDocuments(r.data.documents || []);
                showToast("Document added");
            }
        } catch (e) {
            showToast(e.message, "danger");
        }
    }
    document.getElementById("draftDocInput").value = "";
}

async function removeDraftDoc(docId) {
    if (!currentDraftId) return;
    try {
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/remove-document",
            { document_id: docId },
        );
        const r = await api("GET", "/shipment-drafts/" + currentDraftId);
        renderDraftDocuments(r.data.documents || []);
        showToast("Document removed");
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function el(id) {
    return document.getElementById(id);
}

async function loadReadyTotals() {
    try {
        const [r1, r2] = await Promise.all([
            api("GET", "/orders?status=ReadyForConsolidation"),
            api("GET", "/orders?status=Confirmed"),
        ]);
        const orders = [...(r1.data || []), ...(r2.data || [])].filter((order) =>
            typeof orderIsShipmentEligible === "function"
                ? orderIsShipmentEligible(order)
                : true,
        );
        let totalCbm = 0,
            totalWeight = 0;
        orders.forEach((o) => {
            (o.items || []).forEach((it) => {
                totalCbm += parseFloat(it.declared_cbm || 0);
                totalWeight += parseFloat(it.declared_weight || 0);
            });
        });
        const rc = el("readyOrdersCount"),
            rcbm = el("readyTotalCbm"),
            rw = el("readyTotalWeight");
        if (rc) rc.textContent = orders.length;
        if (rcbm) rcbm.textContent = totalCbm.toFixed(2);
        if (rw) rw.textContent = totalWeight.toFixed(0);
    } catch (e) {
        const rc = el("readyOrdersCount"),
            rcbm = el("readyTotalCbm"),
            rw = el("readyTotalWeight");
        if (rc) rc.textContent = "-";
        if (rcbm) rcbm.textContent = "-";
        if (rw) rw.textContent = "-";
    }
}

async function loadContainers() {
    const tbody = el("containersBody");
    if (!tbody) return;
    try {
        const res = await api("GET", "/containers");
        const rows = res.data || [];
        const emptyHint = canCreateContainers()
            ? '<small>Click "+ Add Container" to create one</small>'
            : "<small>No containers are available yet</small>";
        const colCount = canCreateContainers() ? 6 : 5;
        tbody.innerHTML =
            rows.length > 0
                ? rows
                      .map((c) => {
                          const eta = c.eta_date ? esc(c.eta_date) : "—";
                          const dest =
                              [c.destination_country, c.destination]
                                  .filter(Boolean)
                                  .join(" ") || "—";
                          const editBtn = canCreateContainers()
                              ? `<button type="button" class="btn btn-sm btn-outline-secondary" onclick="openContainerEditModal(${c.id})" title="Edit ETA & destination">Edit</button>`
                              : "";
                          return `<tr><td>${esc(c.code)}</td><td>${c.max_cbm}</td><td>${c.max_weight}</td><td>${eta}</td><td>${dest}</td>${editBtn ? `<td>${editBtn}</td>` : ""}</tr>`;
                      })
                      .join("")
                : `<tr><td colspan="${colCount}" class="text-center py-4 text-muted"><span class="d-block mb-1">No containers yet</span>${emptyHint}</td></tr>`;
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function loadShipmentDrafts() {
    const list = el("shipmentDraftsList");
    if (!list) return;
    try {
        const res = await api("GET", "/shipment-drafts");
        const rows = Array.isArray(res.data) ? res.data : [];
        let html =
            rows
                .map((sd) => {
                    const ps = sd.push_status || "not_pushed";
                    const badge =
                        ps === "success"
                            ? "bg-success"
                            : ps === "failed"
                              ? "bg-danger"
                              : ps === "dry_run"
                                ? "bg-info"
                                : "bg-secondary";
                    const pushLabel =
                        ps === "success"
                            ? "Pushed"
                            : ps === "failed"
                              ? "Failed"
                              : ps === "dry_run"
                                ? "Dry-run"
                                : "Not pushed";
                    const retryBtn =
                        sd.status === "finalized" && ps === "failed"
                            ? `<button type="button" class="btn btn-sm btn-warning ms-1" onclick="retryPush(${sd.id})">Retry Push</button>`
                            : "";
                    const deleteBtn =
                        sd.status !== "finalized"
                            ? `<button type="button" class="btn btn-sm btn-danger" onclick="openDeleteConfirmModal(${sd.id})" title="Delete this draft">Delete</button>`
                            : "";
                    return `
      <div class="consolidation-draft-item border rounded p-3 mb-3 d-flex flex-wrap align-items-center gap-2">
        <div class="flex-grow-1">
          <strong>Draft #${sd.id}</strong> <span class="text-muted">${sd.status}</span>
          ${sd.container_code ? `<span class="text-muted">→ ${esc(sd.container_code)}</span>` : ""}
          <span class="badge ${badge} ms-1">${pushLabel}</span>
          <br><small class="text-muted">Orders: ${(sd.order_ids || []).join(", ") || "none"}</small>
          ${sd.push_last_error ? `<br><small class="text-danger">${esc(sd.push_last_error)}</small>` : ""}
        </div>
        <div class="d-flex gap-1 flex-shrink-0">
          ${deleteBtn}
          ${retryBtn}
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="openDraftModal(${sd.id})">Manage</button>
        </div>
      </div>
    `;
                })
                .join("") ||
            '<p class="text-center py-4 text-muted mb-0"><span class="d-block mb-1">No shipment drafts</span><small>Click "+ New Draft" to create one</small></p>';
        list.innerHTML = html;
    } catch (e) {
        showToast(e.message || "Failed to load drafts", "danger");
        list.innerHTML =
            '<p class="text-center py-4 text-muted mb-0">Failed to load. <a href="javascript:void(0)" onclick="loadShipmentDrafts()">Retry</a></p>';
    }
}

function applyContainerPreset(code, maxCbm, maxWeight) {
    document.getElementById("containerCode").value = code;
    document.getElementById("containerMaxCbm").value = maxCbm;
    document.getElementById("containerMaxWeight").value = maxWeight;
}

async function openContainerEditModal(containerId) {
    if (!canCreateContainers()) return;
    try {
        const r = await api("GET", "/containers/" + containerId);
        const c = r.data;
        el("containerEditId").value = c.id;
        el("containerEditCode").textContent = esc(c.code);
        el("containerEditEtaDate").value = c.eta_date || "";
        el("containerEditDestCountry").value = c.destination_country || "";
        el("containerEditDestination").value = c.destination || "";
        el("containerEditNotes").value = c.notes || "";
        new bootstrap.Modal(el("containerEditModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveContainerEdit() {
    const id = el("containerEditId").value;
    if (!id) return;
    try {
        await api("PUT", "/containers/" + id, {
            eta_date: el("containerEditEtaDate").value || null,
            destination_country:
                el("containerEditDestCountry").value.trim() || null,
            destination: el("containerEditDestination").value.trim() || null,
            notes: el("containerEditNotes").value.trim() || null,
        });
        showToast("Container updated");
        bootstrap.Modal.getInstance(el("containerEditModal")).hide();
        loadContainers();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function suggestEtaFromOffsets() {
    const d = new Date();
    d.setDate(d.getDate() + 70);
    el("containerEditEtaDate").value = d.toISOString().slice(0, 10);
}

async function saveContainer() {
    if (!canCreateContainers()) {
        showToast("Only SuperAdmin can create containers", "danger");
        return;
    }
    const code = document.getElementById("containerCode").value.trim();
    const maxCbm = parseFloat(document.getElementById("containerMaxCbm").value);
    const maxWeight = parseFloat(
        document.getElementById("containerMaxWeight").value,
    );
    if (!code || maxCbm <= 0 || maxWeight <= 0) {
        showToast("Fill all fields", "danger");
        return;
    }
    try {
        await api("POST", "/containers", {
            code,
            max_cbm: maxCbm,
            max_weight: maxWeight,
        });
        showToast("Container created");
        bootstrap.Modal.getInstance(
            document.getElementById("containerModal"),
        ).hide();
        loadContainers();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function createShipmentDraft() {
    try {
        await api("POST", "/shipment-drafts", {});
        showToast("Shipment draft created");
        loadShipmentDrafts();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function orderCbm(o) {
    return (o.items || []).reduce(
        (s, i) => s + (parseFloat(i.declared_cbm) || 0),
        0,
    );
}
function orderWeight(o) {
    return (o.items || []).reduce(
        (s, i) => s + (parseFloat(i.declared_weight) || 0),
        0,
    );
}

async function openDraftModal(id) {
    currentDraftId = id;
    document.getElementById("draftModalId").textContent = "#" + id;
    const deleteBtn = document.getElementById("draftDeleteBtn");
    try {
        const [draftRes, ordersRes, res2, containersRes] = await Promise.all([
            api("GET", "/shipment-drafts/" + id),
            api("GET", "/orders?status=ReadyForConsolidation"),
            api("GET", "/orders?status=Confirmed"),
            api("GET", "/containers"),
        ]);
        const draftOrderIds = draftRes.data.order_ids || [];
        const allEligibleRaw = [
            ...(ordersRes.data || []),
            ...(res2.data || []),
        ];
        const seen = new Set();
        eligibleOrders = allEligibleRaw.filter((o) => {
            if (seen.has(o.id) || draftOrderIds.includes(o.id)) return false;
            if (
                typeof orderIsShipmentEligible === "function" &&
                !orderIsShipmentEligible(o)
            ) {
                return false;
            }
            seen.add(o.id);
            return true;
        });
        draftOrders = [];
        for (const oid of draftOrderIds) {
            try {
                const r = await api("GET", "/orders/" + oid);
                if (r.data) draftOrders.push(r.data);
            } catch (_) {}
        }

        const addBody = el("draftAddOrderBody");
        const removeBody = el("draftRemoveOrderBody");
        const containerHidden = el("draftContainer");
        const containerSearchInput = el("draftContainerSearch");
        if (!addBody || !removeBody || !containerHidden) return;

        if (deleteBtn) deleteBtn.style.display = draftRes.data.status === "finalized" ? "none" : "";
        const finalizeSection = el("draftFinalizeSection");
        const finalizedMessage = el("draftFinalizedMessage");
        const saveRefsBtn = document.querySelector('[onclick="saveDraftCarrierRefs()"]');
        if (finalizeSection) finalizeSection.classList.toggle("d-none", draftRes.data.status === "finalized");
        if (finalizedMessage) finalizedMessage.classList.toggle("d-none", draftRes.data.status !== "finalized");
        if (saveRefsBtn) {
            saveRefsBtn.classList.toggle("btn-primary", draftRes.data.status === "finalized");
            saveRefsBtn.classList.toggle("btn-outline-primary", draftRes.data.status !== "finalized");
            saveRefsBtn.textContent = draftRes.data.status === "finalized" ? "Save changes" : "Save refs";
        }

        addBody.innerHTML =
            eligibleOrders
                .map(
                    (o) =>
                        `<tr><td><input type="checkbox" class="form-check-input draft-add-order-cb" value="${o.id}"></td><td>#${o.id} ${esc(o.customer_name)}${o.customer_priority_level && o.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${esc(o.customer_priority_note || "")}">${esc(o.customer_priority_level)}</span>` : ""}</td><td>${orderCbm(o).toFixed(2)}</td><td>${orderWeight(o).toFixed(0)}</td></tr>`,
                )
                .join("") ||
            "<tr><td colspan='4' class='text-muted text-center py-2'>No orders to add</td></tr>";

        removeBody.innerHTML =
            draftOrders
                .map(
                    (o) =>
                        `<tr><td><input type="checkbox" class="form-check-input draft-remove-order-cb" value="${o.id}"></td><td>#${o.id} ${esc(o.customer_name)}${o.customer_priority_level && o.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${esc(o.customer_priority_note || "")}">${esc(o.customer_priority_level)}</span>` : ""}</td><td>${orderCbm(o).toFixed(2)}</td><td>${orderWeight(o).toFixed(0)}</td></tr>`,
                )
                .join("") ||
            "<tr><td colspan='4' class='text-muted text-center py-2'>No orders in draft</td></tr>";

        const addAll = el("draftAddSelectAll");
        const removeAll = el("draftRemoveSelectAll");
        if (addAll) addAll.checked = false;
        if (removeAll) removeAll.checked = false;

        const containers = containersRes.data || [];
        draftCbmForCapacity = draftRes.data.total_cbm ?? 0;
        draftWeightForCapacity = draftRes.data.total_weight ?? 0;
        if (containerHidden) containerHidden.value = "";
        if (containerSearchInput) containerSearchInput.value = "";
        const containerId = draftRes.data.container_id;
        const containerData = containers?.find((c) => c.id == containerId);
        if (containerData && draftContainerAc) {
            draftContainerAc.setValue(containerData);
            containerHidden.value = containerData.id;
        }

        const draftCbm = draftRes.data.total_cbm ?? 0;
        const draftWeight = draftRes.data.total_weight ?? 0;
        const tcbm = el("draftTotalCbm");
        const twt = el("draftTotalWeight");
        const hintEl = el("draftCapacityHint");
        if (tcbm) tcbm.textContent = draftCbm.toFixed(2);
        if (twt) twt.textContent = draftWeight.toFixed(0);

        el("draftContainerNumber").value = draftRes.data.container_number || "";
        el("draftBookingNumber").value = draftRes.data.booking_number || "";
        el("draftTrackingUrl").value = draftRes.data.tracking_url || "";
        renderDraftDocuments(draftRes.data.documents || []);

        renderCapacityBars(draftCbm, draftWeight, containerData, hintEl);

        new bootstrap.Modal(document.getElementById("draftModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function toggleAddSelectAll() {
    const checked = document.getElementById("draftAddSelectAll").checked;
    document
        .querySelectorAll(".draft-add-order-cb")
        .forEach((cb) => (cb.checked = checked));
}
function toggleRemoveSelectAll() {
    const checked = document.getElementById("draftRemoveSelectAll").checked;
    document
        .querySelectorAll(".draft-remove-order-cb")
        .forEach((cb) => (cb.checked = checked));
}

async function addOrdersToDraft() {
    const ids = Array.from(
        document.querySelectorAll(".draft-add-order-cb:checked"),
    ).map((cb) => cb.value);
    if (!ids.length) {
        showToast("Select orders to add", "danger");
        return;
    }
    try {
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/add-orders",
            {
                order_ids: ids,
            },
        );
        showToast("Orders added");
        openDraftModal(currentDraftId);
        loadShipmentDrafts();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function removeOrdersFromDraft() {
    const ids = Array.from(
        document.querySelectorAll(".draft-remove-order-cb:checked"),
    ).map((cb) => cb.value);
    if (!ids.length) {
        showToast("Select orders to remove", "danger");
        return;
    }
    try {
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/remove-orders",
            {
                order_ids: ids,
            },
        );
        showToast("Orders removed");
        openDraftModal(currentDraftId);
        loadShipmentDrafts();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function assignContainerToDraft() {
    const containerId = document.getElementById("draftContainer").value;
    if (!containerId) {
        showToast("Select a container", "danger");
        return;
    }
    try {
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/assign-container",
            {
                container_id: parseInt(containerId),
            },
        );
        showToast("Container assigned");
        openDraftModal(currentDraftId);
        loadShipmentDrafts();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function retryPush(draftId) {
    try {
        const res = await api(
            "POST",
            "/shipment-drafts/" + draftId + "/push",
            {},
        );
        showToast(res.data?.message || "Push completed");
        loadShipmentDrafts();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function openFinalizeConfirm() {
    bootstrap.Modal.getInstance(document.getElementById("draftModal")).hide();
    new bootstrap.Modal(document.getElementById("finalizeConfirmModal")).show();
}

async function finalizeDraft() {
    const btn = document.getElementById("finalizeConfirmBtn");
    try {
        setLoading(btn, true);
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/finalize",
            {},
        );
        showToast("Finalized and pushed to tracking");
        bootstrap.Modal.getInstance(
            document.getElementById("finalizeConfirmModal"),
        ).hide();
        loadShipmentDrafts();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

function deleteCurrentDraft() {
    if (!currentDraftId) return;
    openDeleteConfirmModal(currentDraftId);
}

function openDeleteConfirmModal(id) {
    const modal = document.getElementById("deleteDraftConfirmModal");
    const btn = document.getElementById("deleteDraftConfirmBtn");
    if (btn) btn.onclick = () => doDeleteDraft(id);
    new bootstrap.Modal(modal).show();
}

async function doDeleteDraft(id) {
    const btn = document.getElementById("deleteDraftConfirmBtn");
    try {
        setLoading(btn, true);
        await api("DELETE", "/shipment-drafts/" + id);
        showToast("Draft deleted");
        bootstrap.Modal.getInstance(
            document.getElementById("deleteDraftConfirmModal"),
        ).hide();
        const dm = document.getElementById("draftModal");
        if (dm) {
            const inst = bootstrap.Modal.getInstance(dm);
            if (inst) inst.hide();
        }
        loadShipmentDrafts();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}
