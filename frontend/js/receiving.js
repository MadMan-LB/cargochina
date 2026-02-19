document.addEventListener("DOMContentLoaded", loadReceivableOrders);

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
            document
                .getElementById("receiveForm")
                .classList.toggle("d-none", !sel.value);
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
    const fileInput = document.getElementById("receivePhotos");
    const photoPaths = [];

    if (fileInput.files && fileInput.files.length > 0) {
        for (let i = 0; i < fileInput.files.length; i++) {
            const fd = new FormData();
            fd.append("file", fileInput.files[i]);
            try {
                const r = await fetch(
                    API_BASE.replace("/api/v1", "") + "/api/v1/upload",
                    { method: "POST", body: fd },
                );
                const j = await r.json();
                if (j.data && j.data.path) photoPaths.push(j.data.path);
            } catch (e) {
                showToast("Upload failed: " + e.message, "danger");
                return;
            }
        }
    }

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
        return;
    }

    try {
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
        document.getElementById("receivePhotos").value = "";
    } catch (e) {
        showToast(e.message, "danger");
    }
}
