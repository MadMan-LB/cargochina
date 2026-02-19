let receivePhotoPaths = [];

document.addEventListener("DOMContentLoaded", () => {
    loadReceivableOrders();
    const input = document.getElementById("receivePhotos");
    if (input) input.onchange = () => handleReceivePhotos(input.files);
    document
        .getElementById("actualCbm")
        ?.addEventListener("input", updateVariancePhotoAlert);
    document
        .getElementById("condition")
        ?.addEventListener("change", updateVariancePhotoAlert);
});

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
            if (sel.value) updateVariancePhotoAlert();
        };
    } catch (e) {
        showToast(e.message, "danger");
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

    const submitBtn = document.getElementById("submitReceiveBtn");
    try {
        setLoading(submitBtn, true);
        const res = await api("POST", "/orders/" + orderId + "/receive", {
            actual_cartons: actualCartons,
            actual_cbm: actualCbm,
            actual_weight: actualWeight,
            condition,
            notes: notes || null,
            photo_paths: photoPaths,
        });
        showToast(
            res.data.variance_detected
                ? "Received — customer confirmation required"
                : "Received successfully",
        );
        loadReceivableOrders();
        document.getElementById("receiveForm").classList.add("d-none");
        document.getElementById("receiveOrder").value = "";
        receivePhotoPaths = [];
        renderReceivePhotoPreview();
    } catch (e) {
        showToast(e.message || "Request failed", "danger");
    } finally {
        setLoading(submitBtn, false);
    }
}
