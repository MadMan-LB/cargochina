let receivePhotoPaths = [];
let receiveOrderItems = [];
let receiveItemPhotos = {};

document.addEventListener("DOMContentLoaded", () => {
    loadReceivableOrders();
    loadReceivingConfig();
    const input = document.getElementById("receivePhotos");
    if (input) input.onchange = () => handleReceivePhotos(input.files);
    document
        .getElementById("actualCbm")
        ?.addEventListener("input", updateVariancePhotoAlert);
    document
        .getElementById("condition")
        ?.addEventListener("change", updateVariancePhotoAlert);
});

async function loadReceivingConfig() {
    try {
        const res = await api("GET", "/config/receiving");
        const enabled = res.data?.item_level_receiving_enabled ?? 0;
        const section = document.getElementById("itemLevelSection");
        if (section) section.classList.toggle("d-none", !enabled);
    } catch (_) {
        document.getElementById("itemLevelSection")?.classList.add("d-none");
    }
}

async function handleReceivePhotos(files) {
    const filesArr = Array.from(files || []).filter((f) =>
        f.type.startsWith("image/"),
    );
    if (!filesArr.length) return;
    const btn = document.getElementById("receiveAddPhotoBtn");
    const input = document.getElementById("receivePhotos");
    try {
        setLoading(btn, true);
        const paths = await PHOTO_UPLOADER.uploadPhotos(
            filesArr,
            (i, total) => {
                if (btn) btn.textContent = `Uploading ${i}/${total}…`;
            },
        );
        paths.forEach((p) => {
            if (p && !receivePhotoPaths.includes(p)) receivePhotoPaths.push(p);
        });
        renderReceivePhotoPreview();
        updateVariancePhotoAlert();
    } catch (e) {
        showToast("Upload failed: " + (e.message || "Unknown error"), "danger");
    } finally {
        setLoading(btn, false);
        if (btn) btn.textContent = "Add Photo";
    }
    if (input) input.value = "";
}

function renderReceivePhotoPreview() {
    const container = document.getElementById("photoPreview");
    if (!container) return;
    PHOTO_UPLOADER.previewPhotos(
        container,
        receivePhotoPaths,
        "removeReceivePhoto",
    );
}

function removeReceivePhoto(index) {
    receivePhotoPaths.splice(index, 1);
    renderReceivePhotoPreview();
    updateVariancePhotoAlert();
}

function updateVariancePhotoAlert() {
    const alertEl = document.getElementById("variancePhotoAlert");
    if (!alertEl) return;
    const orderId = document.getElementById("receiveOrder").value;
    if (!orderId) {
        alertEl.classList.add("d-none");
        return;
    }
    const opt = document.querySelector(
        `#receiveOrder option[value="${orderId}"]`,
    );
    const declaredCbm = parseFloat(opt?.dataset.declaredCbm || 0);
    const actualCbm = parseFloat(
        document.getElementById("actualCbm")?.value || 0,
    );
    const condition = document.getElementById("condition")?.value || "good";
    const variancePct =
        declaredCbm > 0
            ? (Math.abs(actualCbm - declaredCbm) / declaredCbm) * 100
            : 0;
    const varianceAbs = Math.abs(actualCbm - declaredCbm);
    const hasVariance =
        variancePct >= 10 || varianceAbs >= 0.1 || condition !== "good";
    const needsPhoto = hasVariance && receivePhotoPaths.length === 0;
    alertEl.classList.toggle("d-none", !needsPhoto);
}

async function loadReceivableOrders() {
    try {
        const res = await api("GET", "/orders?status=Approved");
        const res2 = await api("GET", "/orders?status=InTransitToWarehouse");
        const orders = [...(res.data || []), ...(res2.data || [])];
        const sel = document.getElementById("receiveOrder");
        sel.innerHTML =
            '<option value="">— Select order —</option>' +
            orders
                .map(
                    (o) =>
                        `<option value="${o.id}" data-declared-cbm="${(o.items || []).reduce((s, i) => s + (parseFloat(i.declared_cbm) || 0), 0)}" data-declared-weight="${(o.items || []).reduce((s, i) => s + (parseFloat(i.declared_weight) || 0), 0)}">#${o.id} - ${escapeHtml(o.customer_name)} - ${o.expected_ready_date}</option>`,
                )
                .join("");
        sel.onchange = () => {
            const form = document.getElementById("receiveForm");
            form.classList.toggle("d-none", !sel.value);
            if (sel.value) {
                updateVariancePhotoAlert();
                loadOrderForReceive(sel.value);
            } else {
                receiveOrderItems = [];
                receiveItemPhotos = {};
            }
        };
    } catch (e) {
        showToast(e.message, "danger");
    }
}

document.getElementById("toggleItemLevel")?.addEventListener("click", () => {
    const tbl = document.getElementById("itemLevelTable");
    const btn = document.getElementById("toggleItemLevel");
    tbl.classList.toggle("d-none");
    btn.setAttribute(
        "aria-expanded",
        tbl.classList.contains("d-none") ? "false" : "true",
    );
});

async function loadOrderForReceive(orderId) {
    try {
        const res = await api("GET", "/orders/" + orderId);
        receiveOrderItems = res.data?.items || [];
        receiveItemPhotos = {};
        const tbody = document.getElementById("itemLevelBody");
        if (!tbody) return;
        tbody.innerHTML = receiveOrderItems
            .map(
                (it, i) => `
          <tr data-order-item-id="${it.id}">
            <td>${escapeHtml((it.description_cn || it.description_en || "Item " + (i + 1)).substring(0, 40))}</td>
            <td>${it.declared_cbm || 0} CBM / ${it.declared_weight || 0} kg</td>
            <td><input type="number" class="form-control form-control-sm item-actual-cartons" min="0" placeholder="0"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-actual-cbm" min="0" placeholder="0"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-actual-weight" min="0" placeholder="0"></td>
            <td><select class="form-select form-select-sm item-condition"><option value="good">Good</option><option value="damaged">Damaged</option><option value="partial">Partial</option></select></td>
            <td><input type="file" class="d-none item-photo-input" accept="image/*" multiple data-order-item-id="${it.id}"><button type="button" class="btn btn-sm btn-outline-secondary item-add-photo">+</button><div class="item-photo-preview d-inline"></div></td>
          </tr>`,
            )
            .join("");
        tbody.querySelectorAll(".item-add-photo").forEach((btn) => {
            const row = btn.closest("tr");
            const oiId = row.dataset.orderItemId;
            const input = row.querySelector(".item-photo-input");
            input.onchange = async (e) => {
                const files = Array.from(e.target.files || []).filter((f) =>
                    f.type.startsWith("image/"),
                );
                for (const f of files) {
                    try {
                        const path = await uploadFile(f);
                        if (path) {
                            receiveItemPhotos[oiId] =
                                receiveItemPhotos[oiId] || [];
                            receiveItemPhotos[oiId].push(path);
                        }
                    } catch (err) {
                        showToast(err.message, "danger");
                    }
                }
                renderItemPhotoPreview(oiId);
                e.target.value = "";
            };
            btn.onclick = () => input.click();
        });
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function renderItemPhotoPreview(orderItemId) {
    const row = document.querySelector(
        `tr[data-order-item-id="${orderItemId}"]`,
    );
    if (!row) return;
    const container = row.querySelector(".item-photo-preview");
    const paths = receiveItemPhotos[orderItemId] || [];
    container.innerHTML = paths
        .map(
            (p, i) =>
                `<span class="d-inline-block me-1"><img src="/cargochina/backend/${p}" class="img-thumbnail" style="max-width:40px"><button type="button" class="btn-close btn-close-sm" onclick="removeItemPhoto('${orderItemId}',${i})"></button></span>`,
        )
        .join("");
}

function removeItemPhoto(orderItemId, index) {
    if (receiveItemPhotos[orderItemId]) {
        receiveItemPhotos[orderItemId].splice(index, 1);
        renderItemPhotoPreview(orderItemId);
    }
}

async function submitReceive() {
    const orderId = document.getElementById("receiveOrder").value;
    if (!orderId) {
        showToast("Select an order", "danger");
        return;
    }
    const actualCartons =
        parseInt(document.getElementById("actualCartons").value) || 0;
    const actualCbm =
        parseFloat(document.getElementById("actualCbm").value) || 0;
    const actualWeight =
        parseFloat(document.getElementById("actualWeight").value) || 0;
    const condition = document.getElementById("condition").value;
    const notes = document.getElementById("receiveNotes").value;
    const photoPaths = receivePhotoPaths;

    const opt = document.querySelector(
        `#receiveOrder option[value="${orderId}"]`,
    );
    const declaredCbm = parseFloat(opt?.dataset.declaredCbm || 0);
    const declaredWeight = parseFloat(opt?.dataset.declaredWeight || 0);
    const variancePct =
        declaredCbm > 0
            ? (Math.abs(actualCbm - declaredCbm) / declaredCbm) * 100
            : 0;
    const varianceAbs = Math.abs(actualCbm - declaredCbm);
    const hasVariance =
        variancePct >= 10 || varianceAbs >= 0.1 || condition !== "good";
    if (hasVariance && photoPaths.length === 0) {
        showToast(
            "Evidence photos required when variance or damage is present",
            "danger",
        );
        document
            .getElementById("variancePhotoAlert")
            ?.classList.remove("d-none");
        return;
    }

    const items = [];
    const tbody = document.getElementById("itemLevelBody");
    if (tbody && receiveOrderItems.length) {
        receiveOrderItems.forEach((it) => {
            const row = tbody.querySelector(
                `tr[data-order-item-id="${it.id}"]`,
            );
            if (!row) return;
            const aCartons = parseInt(
                row.querySelector(".item-actual-cartons")?.value || 0,
                10,
            );
            const aCbm = parseFloat(
                row.querySelector(".item-actual-cbm")?.value || 0,
            );
            const aWeight = parseFloat(
                row.querySelector(".item-actual-weight")?.value || 0,
            );
            const itCond =
                row.querySelector(".item-condition")?.value || "good";
            const itPhotos = receiveItemPhotos[it.id] || [];
            if (aCartons > 0 || aCbm > 0 || aWeight > 0 || itPhotos.length) {
                items.push({
                    order_item_id: it.id,
                    actual_cartons: aCartons || null,
                    actual_cbm: aCbm || null,
                    actual_weight: aWeight || null,
                    condition: itCond,
                    photo_paths: itPhotos,
                });
            }
        });
    }

    const payload = {
        actual_cartons: actualCartons,
        actual_cbm: actualCbm,
        actual_weight: actualWeight,
        condition,
        notes: notes || null,
        photo_paths: photoPaths,
    };
    if (items.length) payload.items = items;

    const submitBtn = document.getElementById("submitReceiveBtn");
    try {
        setLoading(submitBtn, true);
        const res = await api(
            "POST",
            "/orders/" + orderId + "/receive",
            payload,
        );
        showToast(
            res.data.variance_detected
                ? "Received — customer confirmation required"
                : "Received successfully",
        );
        loadReceivableOrders();
        document.getElementById("receiveForm").classList.add("d-none");
        document.getElementById("receiveOrder").value = "";
        receivePhotoPaths = [];
        receiveItemPhotos = {};
        renderReceivePhotoPreview();
    } catch (e) {
        showToast(e.message || "Request failed", "danger");
    } finally {
        setLoading(submitBtn, false);
    }
}
