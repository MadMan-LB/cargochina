let currentDraftId = null;

document.addEventListener("DOMContentLoaded", () => {
    loadContainers();
    loadShipmentDrafts();
});

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
                .map(
                    (sd) => `
      <div class="border rounded p-2 mb-2">
        <strong>Draft #${sd.id}</strong> ${sd.status} ${sd.container_code ? "→ " + sd.container_code : ""}
        <br><small>Orders: ${(sd.order_ids || []).join(", ") || "none"}</small>
        <button class="btn btn-sm btn-outline-primary ms-2" onclick="openDraftModal(${sd.id})">Manage</button>
      </div>
    `,
                )
                .join("") || '<p class="text-muted">No shipment drafts</p>';
    } catch (e) {
        showToast(e.message, "danger");
    }
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
    } catch (e) {
        showToast(e.message, "danger");
    }
}
