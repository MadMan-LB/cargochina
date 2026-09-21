let currentDraftId = null;
let currentDraftRevision = null;
function trackingFinalizationPresentation(mode) {
    if(mode==='disabled')return {label:'Finalize (tracking disabled)',hint:'Tracking is disabled. Finalization is local; no external request will be sent.'};
    if(mode==='dry_run')return {label:'Finalize (tracking dry-run)',hint:'Tracking is in dry-run mode. No external request will be sent.'};
    return {label:mode==='live'?'Finalize & Push to Tracking':'Finalize',hint:'Finalize when orders are added and a container is assigned. Tracking delivery is recorded separately.'};
}
let eligibleOrders = [];
let draftOrders = [];
let draftCbmForCapacity = 0;
let draftWeightForCapacity = 0;
let draftCapacityKnown = true;
let draftContainerAc = null;
let draftLoadVersion = 0;
let draftAssignedContainerId = null;

function renderCapacityBars(cbm, weight, container, hintEl) {
    if (!hintEl) return;
    if(!draftCapacityKnown){hintEl.innerHTML='<span class="text-warning">Historical cargo measurements require reconciliation.</span>';return;}
    if (!container) {
        hintEl.innerHTML =
            `<span class="text-muted small">${typeof t === "function" ? t("Assign a container to see capacity") : "Assign a container to see capacity"}</span>`;
        return;
    }
    if (container.capacity_known === false) {
        hintEl.innerHTML = '<span class="text-warning">Historical cargo measurements require reconciliation.</span>';
        return;
    }
    const maxCbm = parseFloat(container.max_cbm) || 1;
    const maxWt = parseFloat(container.max_weight) || 1;
    if (container.id && String(container.id) === String(draftAssignedContainerId)) {
        cbm = Number(container.used_cbm || 0);
        weight = Number(container.used_weight || 0);
    } else {
        cbm += Number(container.used_cbm || 0);
        weight += Number(container.used_weight || 0);
    }
    const cbmPct = Math.min(100, (cbm / maxCbm) * 100);
    const wtPct = Math.min(100, (weight / maxWt) * 100);
    const cbmColor =
        cbmPct >= 100 ? "#dc2626" : cbmPct >= 85 ? "#d97706" : "#16a34a";
    const wtColor =
        wtPct >= 100 ? "#dc2626" : wtPct >= 85 ? "#d97706" : "#16a34a";
    hintEl.innerHTML = `
        <div class="mt-2">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <small class="text-muted fw-semibold">${typeof t === "function" ? t("CBM Fill") : "CBM Fill"}</small>
          <small style="color:${cbmColor};font-weight:600">${cbm.toFixed(2)} / ${maxCbm} m³ (${cbmPct.toFixed(0)}%)</small>
        </div>
        <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
          <div style="height:100%;width:${cbmPct}%;background:${cbmColor};border-radius:4px;transition:width .4s;"></div>
        </div>
        <div class="d-flex justify-content-between align-items-center mb-1 mt-2">
          <small class="text-muted fw-semibold">${typeof t === "function" ? t("Weight Fill") : "Weight Fill"}</small>
          <small style="color:${wtColor};font-weight:600">${weight.toFixed(0)} / ${maxWt} kg (${wtPct.toFixed(0)}%)</small>
        </div>
        <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;">
          <div style="height:100%;width:${wtPct}%;background:${wtColor};border-radius:4px;transition:width .4s;"></div>
        </div>
        ${cbm > maxCbm + 0.00000001 || weight > maxWt + 0.00000001 ? `<div class="text-danger small fw-semibold mt-1">${typeof t === "function" ? t("Capacity exceeded") : "Capacity exceeded"}</div>` : cbmPct >= 100 || wtPct >= 100 ? `<div class="text-success small fw-semibold mt-1">${typeof t === "function" ? t("Full — within capacity") : "Full — within capacity"}</div>` : cbmPct >= 85 || wtPct >= 85 ? `<div class="text-warning small fw-semibold mt-1">${typeof t === "function" ? t("Almost full") : "Almost full"}</div>` : `<div class="text-success small fw-semibold mt-1">${typeof t === "function" ? t("Within capacity") : "Within capacity"}</div>`}
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
    el("draftModal")?.addEventListener("hide.bs.modal", () => {
        // A completed request must not reopen a dialog the employee closed.
        draftLoadVersion++;
    });
    try {
        loadContainers();
        loadShipmentDrafts();
        loadReadyTotals();
        loadContainerPresets();
        const exactDraftId = new URLSearchParams(window.location.search).get("shipment_draft_id");
        if (exactDraftId && /^\d+$/.test(exactDraftId)) {
            openDraftModal(parseInt(exactDraftId, 10));
        }
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
                placeholder:
                    typeof t === "function"
                        ? t("Type country name or code (e.g. LB, Lebanon)")
                        : "Type country name or code (e.g. LB, Lebanon)",
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
                placeholder:
                    typeof t === "function"
                        ? t("Search containers by code...")
                        : "Search containers by code...",
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
            showToast(
                e.message ||
                    (typeof t === "function" ? t("Load error") : "Load error"),
                "danger",
            );
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
        const result = await api("PUT", "/shipment-drafts/" + currentDraftId, {
            revision: currentDraftRevision,
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
        currentDraftRevision = result.data.revision;
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
        const orders = await loadShipmentEligibleOrders();
        let totalCbm = 0,
            totalWeight = 0;
        orders.forEach((o) => {
            totalCbm += orderCbm(o);
            totalWeight += orderWeight(o);
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
        const rows = await loadAssignmentContainers();
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

let shipmentListOffset=0;
let shipmentListVersion=0;
function changeShipmentPage(direction){shipmentListOffset=Math.max(0,shipmentListOffset+direction*50);loadShipmentDrafts(false);}
async function loadShipmentDrafts(reset=true) {
    if(reset)shipmentListOffset=0;
    const version=++shipmentListVersion;
    const list = el("shipmentDraftsList");
    if (!list) return;
    try {
        const q=el('shipmentFilter')?.value?.trim()||'';
        const res = await api("GET", `/shipment-drafts?limit=50&offset=${shipmentListOffset}&q=${encodeURIComponent(q)}`);
        if(version!==shipmentListVersion)return;
        const previous=el('shipmentPrevious'),next=el('shipmentNext'),label=el('shipmentPage');
        if(previous)previous.disabled=shipmentListOffset===0;if(next)next.disabled=!res.meta?.has_more;if(label)label.textContent=`Page ${Math.floor(shipmentListOffset/50)+1} · ${res.meta?.total??0} drafts`;
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
                        sd.status === "finalized" && ps !== "success"
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
        if(version!==shipmentListVersion)return;
        const next=el('shipmentNext');if(next)next.disabled=true;
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

let consolidationContainerEditRevision = null;
let consolidationContainerEditRequest = 0;
async function openContainerEditModal(containerId) {
    if (!canCreateContainers()) return;
    const request = ++consolidationContainerEditRequest;
    try {
        const r = await api("GET", "/containers/" + containerId);
        if(request!==consolidationContainerEditRequest)return;
        const c = r.data;
        consolidationContainerEditRevision = c.revision;
        el("containerEditId").value = c.id;
        el("containerEditCode").textContent = esc(c.code);
        el("containerEditEtaDate").value = c.eta_date || "";
        el("containerEditDestCountry").value = c.destination_country || "";
        el("containerEditDestination").value = c.destination || "";
        el('containerEditDestCountry').disabled = !!c.assignment_locked;
        el('containerEditDestination').disabled = !!c.assignment_locked;
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
        const payload = {
            revision: consolidationContainerEditRevision,
            eta_date: el("containerEditEtaDate").value || null,
            destination_country:
                el("containerEditDestCountry").value.trim() || null,
            destination: el("containerEditDestination").value.trim() || null,
            notes: el("containerEditNotes").value.trim() || null,
        };
        if (el('containerEditDestCountry').disabled) delete payload.destination_country;
        if (el('containerEditDestination').disabled) delete payload.destination;
        await api("PUT", "/containers/" + id, payload);
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

let containerCreateRequestKey = null;
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
            idempotency_key: containerCreateRequestKey || (containerCreateRequestKey = `container:${globalThis.crypto?.randomUUID?.() || Date.now().toString(36)+Math.random().toString(36).slice(2)}`),
            code,
            max_cbm: maxCbm,
            max_weight: maxWeight,
        });
        showToast("Container created");
        containerCreateRequestKey = null;
        bootstrap.Modal.getInstance(
            document.getElementById("containerModal"),
        ).hide();
        loadContainers();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

let shipmentCreateRequestKey = null;
async function createShipmentDraft() {
    try {
        await api("POST", "/shipment-drafts", {idempotency_key: shipmentCreateRequestKey || (shipmentCreateRequestKey = `shipment:${globalThis.crypto?.randomUUID?.() || Date.now().toString(36)+Math.random().toString(36).slice(2)}`)});
        shipmentCreateRequestKey = null;
        showToast("Shipment draft created");
        loadShipmentDrafts();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function orderCbm(o) {
    if (o.cargo_totals) return Number(o.cargo_totals.cbm || 0);
    return (o.items || []).reduce(
        (s, i) => s + (parseFloat(i.cargo_cbm ?? i.declared_cbm) || 0),
        0,
    );
}
function orderWeight(o) {
    if (o.cargo_totals) return Number(o.cargo_totals.weight || 0);
    return (o.items || []).reduce(
        (s, i) => s + (parseFloat(i.cargo_weight ?? i.declared_weight) || 0),
        0,
    );
}

async function openDraftModal(id, refreshOnly = false) {
    const modalEl = el("draftModal");
    if (refreshOnly && (currentDraftId !== id || !modalEl?.classList.contains("show"))) return;
    const version = ++draftLoadVersion;
    currentDraftId = id;
    document.getElementById("draftModalId").textContent = "#" + id;
    const deleteBtn = document.getElementById("draftDeleteBtn");
    try {
        const [draftRes, allEligibleRaw, containersRes] = await Promise.all([
            api("GET", "/shipment-drafts/" + id),
            loadShipmentEligibleOrders(),
            loadAssignmentContainers(),
        ]);
        if (version !== draftLoadVersion) return;
        const draftOrderIds = draftRes.data.order_ids || [];
        const seen = new Set();
        const loadedEligibleOrders = allEligibleRaw.filter((o) => {
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
        const loadedOrders = [];
        for (const oid of draftOrderIds) {
            try {
                const r = await api("GET", "/orders/" + oid);
                if (r.data) loadedOrders.push(r.data);
            } catch (_) {}
        }
        if (version !== draftLoadVersion) return;
        eligibleOrders = loadedEligibleOrders;
        draftOrders = loadedOrders;

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
            saveRefsBtn.textContent = "Save refs";
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

        const containers = containersRes;
        draftCapacityKnown = draftRes.data.capacity_known !== false;
        draftCbmForCapacity = draftRes.data.total_cbm ?? 0;
        draftWeightForCapacity = draftRes.data.total_weight ?? 0;
        if (containerHidden) containerHidden.value = "";
        if (containerSearchInput) containerSearchInput.value = "";
        const containerId = draftRes.data.container_id;
        draftAssignedContainerId = containerId;
        const containerData = containers?.find((c) => c.id == containerId);
        const membershipLocked=draftRes.data.status==='finalized' || !!containerData?.assignment_locked;
        modalEl.querySelectorAll('.draft-add-order-cb,.draft-remove-order-cb,#draftAddSelectAll,#draftRemoveSelectAll,[onclick="addOrdersToDraft()"],[onclick="removeOrdersFromDraft()"],[onclick="assignContainerToDraft()"]')
            .forEach(control=>control.disabled=membershipLocked);
        if(deleteBtn)deleteBtn.disabled=membershipLocked;
        if (containerData && draftContainerAc) {
            draftContainerAc.setValue(containerData);
            containerHidden.value = containerData.id;
        }

        const draftCbm = draftRes.data.total_cbm ?? 0;
        const draftWeight = draftRes.data.total_weight ?? 0;
        const tcbm = el("draftTotalCbm");
        const twt = el("draftTotalWeight");
        const hintEl = el("draftCapacityHint");
        if (tcbm) tcbm.textContent = draftCapacityKnown?draftCbm.toFixed(2):'—';
        if (twt) twt.textContent = draftCapacityKnown?draftWeight.toFixed(0):'—';

        el("draftContainerNumber").value = draftRes.data.container_number || "";
        el("draftBookingNumber").value = draftRes.data.booking_number || "";
        el("draftTrackingUrl").value = draftRes.data.tracking_url || "";
        currentDraftRevision = draftRes.data.revision;
        const trackingMode=trackingFinalizationPresentation(draftRes.data.tracking_mode);
        const trackingHint=el('draftTrackingMode'),finalizeButton=el('draftFinalizeButton');
        if(trackingHint)trackingHint.textContent=trackingMode.hint;
        if(finalizeButton)finalizeButton.textContent=trackingMode.label;
        renderDraftDocuments(draftRes.data.documents || []);
        modalEl.querySelectorAll('#draftContainerNumber,#draftBookingNumber,#draftTrackingUrl,#draftDocInput,#draftDocType,[onclick="saveDraftCarrierRefs()"],[onclick*="draftDocInput"],[onclick^="removeDraftDoc"]')
            .forEach(control => control.disabled = membershipLocked);

        renderCapacityBars(draftCbm, draftWeight, containerData, hintEl);

        if (!refreshOnly) bootstrap.Modal.getOrCreateInstance(modalEl).show();
    } catch (e) {
        if (version === draftLoadVersion) showToast(e.message, "danger");
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
    const draftId = currentDraftId;
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
            "/shipment-drafts/" + draftId + "/add-orders",
            {
                order_ids: ids,
            },
        );
        showToast("Orders added");
        await openDraftModal(draftId, true);
        loadShipmentDrafts();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function removeOrdersFromDraft() {
    const draftId = currentDraftId;
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
            "/shipment-drafts/" + draftId + "/remove-orders",
            {
                order_ids: ids,
            },
        );
        showToast("Orders removed");
        await openDraftModal(draftId, true);
        loadShipmentDrafts();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function assignContainerToDraft() {
    const draftId = currentDraftId;
    const containerId = document.getElementById("draftContainer").value;
    if (!containerId) {
        showToast("Select a container", "danger");
        return;
    }
    try {
        await api(
            "POST",
            "/shipment-drafts/" + draftId + "/assign-container",
            {
                container_id: parseInt(containerId),
            },
        );
        showToast("Container assigned");
        await openDraftModal(draftId, true);
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
        showToast(res.data?.message || "Tracking push was not confirmed", res.data?.success === true ? "success" : "warning");
        loadShipmentDrafts();
    } catch (e) {
        showToast(e.message, "danger");
        loadShipmentDrafts();
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
        const res = await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/finalize",
            {},
        );
        const push = res.data?.tracking_result;
        showToast(push?.success === true
            ? "Shipment finalized. " + (push.message || "Pushed to tracking")
            : "Shipment finalized. " + (push?.message || "Not pushed to tracking"),
            push?.success === true ? "success" : "warning");
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
