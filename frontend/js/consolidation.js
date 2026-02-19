let currentDraftId = null;

document.addEventListener("DOMContentLoaded", () => {
    loadContainers();
    loadShipmentDrafts();
    loadReadyTotals();
});

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
        document.getElementById("readyOrdersCount").textContent = orders.length;
        document.getElementById("readyTotalCbm").textContent =
            totalCbm.toFixed(2);
        document.getElementById("readyTotalWeight").textContent =
            totalWeight.toFixed(0);
    } catch (e) {
        document.getElementById("readyOrdersCount").textContent = "-";
        document.getElementById("readyTotalCbm").textContent = "-";
        document.getElementById("readyTotalWeight").textContent = "-";
    }
}

async function loadContainers() {
    try {
        const res = await api("GET", "/containers");
        const rows = res.data || [];
        document.getElementById("containersBody").innerHTML =
            rows
                .map(
                    (c) =>
                        `<tr><td>${escapeHtml(c.code)}</td><td>${c.max_cbm}</td><td>${c.max_weight}</td></tr>`,
                )
                .join("") ||
            '<tr><td colspan="3" class="text-muted">No containers</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function loadShipmentDrafts() {
    try {
        const res = await api("GET", "/shipment-drafts");
        const rows = res.data || [];
        document.getElementById("shipmentDraftsList").innerHTML =
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
                            ? `<button class="btn btn-sm btn-warning ms-1" onclick="retryPush(${sd.id})">Retry Push</button>`
                            : "";
                    return `
      <div class="border rounded p-2 mb-2">
        <strong>Draft #${sd.id}</strong> ${sd.status} ${sd.container_code ? "→ " + sd.container_code : ""}
        <span class="badge ${badge} ms-1">${pushLabel}</span>${retryBtn}
        <br><small>Orders: ${(sd.order_ids || []).join(", ") || "none"}</small>
        ${sd.push_last_error ? `<br><small class="text-danger">${escapeHtml(sd.push_last_error)}</small>` : ""}
        <button class="btn btn-sm btn-outline-primary ms-2" onclick="openDraftModal(${sd.id})">Manage</button>
      </div>
    `;
                })
                .join("") || '<p class="text-muted">No shipment drafts</p>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function applyContainerPreset(code, maxCbm, maxWeight) {
    document.getElementById("containerCode").value = code;
    document.getElementById("containerMaxCbm").value = maxCbm;
    document.getElementById("containerMaxWeight").value = maxWeight;
}

async function saveContainer() {
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

async function openDraftModal(id) {
    currentDraftId = id;
    document.getElementById("draftModalId").textContent = "#" + id;
    try {
        const [draftRes, ordersRes, containersRes] = await Promise.all([
            api("GET", "/shipment-drafts/" + id),
            api("GET", "/orders?status=ReadyForConsolidation"),
            api("GET", "/containers"),
        ]);
        const res2 = await api("GET", "/orders?status=Confirmed");
        const eligible = [
            ...(ordersRes.data || []),
            ...(res2.data || []),
        ].filter((o) => !(draftRes.data.order_ids || []).includes(o.id));
        document.getElementById("draftAddOrder").innerHTML = eligible
            .map(
                (o) =>
                    `<option value="${o.id}">#${o.id} ${escapeHtml(o.customer_name)}</option>`,
            )
            .join("");
        document.getElementById("draftContainer").innerHTML =
            '<option value="">— Select —</option>' +
            (containersRes.data || [])
                .map(
                    (c) =>
                        `<option value="${c.id}">${escapeHtml(c.code)} (${c.max_cbm} CBM)</option>`,
                )
                .join("");
        document.getElementById("draftOrderList").textContent =
            (draftRes.data.order_ids || []).join(", ") || "none";
        const draftCbm = draftRes.data.total_cbm ?? 0;
        const draftWeight = draftRes.data.total_weight ?? 0;
        document.getElementById("draftTotalCbm").textContent =
            draftCbm.toFixed(2);
        document.getElementById("draftTotalWeight").textContent =
            draftWeight.toFixed(0);
        const orderIds = draftRes.data.order_ids || [];
        const allOrders = [...(ordersRes.data || []), ...(res2.data || [])];
        document.getElementById("draftRemoveOrder").innerHTML = orderIds
            .map((oid) => {
                const o = allOrders.find((x) => x.id == oid);
                return `<option value="${oid}">#${oid} ${escapeHtml(o ? o.customer_name : "")}</option>`;
            })
            .join("");
        new bootstrap.Modal(document.getElementById("draftModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function addOrdersToDraft() {
    const sel = document.getElementById("draftAddOrder");
    const orderIds = Array.from(sel.selectedOptions).map((o) => o.value);
    if (!orderIds.length) {
        showToast("Select orders", "danger");
        return;
    }
    try {
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/add-orders",
            { order_ids: orderIds },
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
    const sel = document.getElementById("draftRemoveOrder");
    const orderIds = Array.from(sel.selectedOptions).map((o) => o.value);
    if (!orderIds.length) {
        showToast("Select orders to remove", "danger");
        return;
    }
    try {
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/remove-orders",
            { order_ids: orderIds },
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
        showToast("Select container", "danger");
        return;
    }
    try {
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/assign-container",
            { container_id: parseInt(containerId) },
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

async function finalizeDraft() {
    if (!confirm("Finalize and push to tracking?")) return;
    try {
        await api(
            "POST",
            "/shipment-drafts/" + currentDraftId + "/finalize",
            {},
        );
        showToast("Finalized and pushed to tracking");
        bootstrap.Modal.getInstance(
            document.getElementById("draftModal"),
        ).hide();
        loadShipmentDrafts();
        loadReadyTotals();
    } catch (e) {
        showToast(e.message, "danger");
    }
}
