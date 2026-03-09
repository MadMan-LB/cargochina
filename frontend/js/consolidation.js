let currentDraftId = null;
let eligibleOrders = [];
let draftOrders = [];

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

document.addEventListener("DOMContentLoaded", () => {
    try {
        loadContainers();
        loadShipmentDrafts();
        loadReadyTotals();
        const docInput = document.getElementById("draftDocInput");
        if (docInput)
            docInput.onchange = () => handleDraftDocUpload(docInput.files);
    } catch (e) {
        console.error("Consolidation init:", e);
        if (typeof showToast === "function")
            showToast(e.message || "Load error", "danger");
    }
});

function renderDraftDocuments(docs) {
    const list = document.getElementById("draftDocumentsList");
    if (!list) return;
    list.innerHTML =
        docs.length > 0
            ? docs
                  .map(
                      (d) =>
                          `<div class="d-flex align-items-center gap-2 mb-1"><a href="/cargochina/backend/${esc(d.file_path)}" target="_blank" class="small">${esc(d.doc_type)}</a><button type="button" class="btn btn-link btn-sm p-0 text-danger" onclick="removeDraftDoc(${d.id})">×</button></div>`,
                  )
                  .join("")
            : '<span class="text-muted">No documents</span>';
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
        const orders = [...(r1.data || []), ...(r2.data || [])];
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
        tbody.innerHTML =
            rows.length > 0
                ? rows
                      .map(
                          (c) =>
                              `<tr><td>${esc(c.code)}</td><td>${c.max_cbm}</td><td>${c.max_weight}</td></tr>`,
                      )
                      .join("")
                : `<tr><td colspan="3" class="text-center py-4 text-muted"><span class="d-block mb-1">No containers yet</span>${emptyHint}</td></tr>`;
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
    if (deleteBtn) deleteBtn.style.display = "";
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
        const containerSel = el("draftContainer");
        if (!addBody || !removeBody || !containerSel) return;

        addBody.innerHTML =
            eligibleOrders
                .map(
                    (o) =>
                        `<tr><td><input type="checkbox" class="form-check-input draft-add-order-cb" value="${o.id}"></td><td>#${o.id} ${esc(o.customer_name)}</td><td>${orderCbm(o).toFixed(2)}</td><td>${orderWeight(o).toFixed(0)}</td></tr>`,
                )
                .join("") ||
            "<tr><td colspan='4' class='text-muted text-center py-2'>No orders to add</td></tr>";

        removeBody.innerHTML =
            draftOrders
                .map(
                    (o) =>
                        `<tr><td><input type="checkbox" class="form-check-input draft-remove-order-cb" value="${o.id}"></td><td>#${o.id} ${esc(o.customer_name)}</td><td>${orderCbm(o).toFixed(2)}</td><td>${orderWeight(o).toFixed(0)}</td></tr>`,
                )
                .join("") ||
            "<tr><td colspan='4' class='text-muted text-center py-2'>No orders in draft</td></tr>";

        const addAll = el("draftAddSelectAll");
        const removeAll = el("draftRemoveSelectAll");
        if (addAll) addAll.checked = false;
        if (removeAll) removeAll.checked = false;

        const containers = containersRes.data || [];
        containerSel.innerHTML =
            '<option value="">— Select container —</option>' +
            containers
                .map(
                    (c) =>
                        `<option value="${c.id}" ${draftRes.data.container_id == c.id ? "selected" : ""}>${esc(c.code)} (${c.max_cbm} CBM, ${c.max_weight} kg)</option>`,
                )
                .join("");

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

        const containerId = draftRes.data.container_id;
        const containerData = containers?.find((c) => c.id == containerId);
        let hint = "";
        if (containerData) {
            const okCbm = draftCbm <= containerData.max_cbm;
            const okWt = draftWeight <= containerData.max_weight;
            hint =
                okCbm && okWt
                    ? `<span class="text-success">Within capacity</span>`
                    : `<span class="text-danger">Exceeds capacity</span>`;
        }
        if (hintEl) hintEl.innerHTML = hint;

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
