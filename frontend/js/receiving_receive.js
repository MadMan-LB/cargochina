/**
 * Receive page - single order, pre-loaded from URL
 */
const ORDER_ID = window.RECEIVE_ORDER_ID || 0;
const API_BASE = "/cargochina/api/v1";
const AREA_BASE = "/cargochina/warehouse";

let receivePhotoPaths = [];
let receiveOrderItems = [];
let receiveItemPhotos = {};
let declaredCbm = 0,
    declaredWeight = 0;
let pendingUploads = 0;

async function api(method, path, body) {
    const opts = {
        method,
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
    };
    if (body && (method === "POST" || method === "PUT"))
        opts.body = JSON.stringify(body);
    const res = await fetch(API_BASE + path, opts);
    const d = await res.json().catch(() => ({}));
    if (!res.ok)
        throw new Error(d.message || d.error?.message || "Request failed");
    return d;
}

async function uploadFile(file) {
    return uploadFileWithProgress(file, { showToast });
}

function escapeHtml(s) {
    const d = document.createElement("div");
    d.textContent = s ?? "";
    return d.innerHTML;
}

function showToast(msg, type = "success") {
    const c =
        document.querySelector(".toast-container") ||
        (() => {
            const x = document.createElement("div");
            x.className = "toast-container position-fixed top-0 end-0 p-3";
            document.body.appendChild(x);
            return x;
        })();
    const t = document.createElement("div");
    t.className = `toast align-items-center text-bg-${type} border-0`;
    t.innerHTML = `<div class="d-flex"><div class="toast-body">${escapeHtml(msg)}</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    c.appendChild(t);
    new bootstrap.Toast(t).show();
    t.addEventListener("hidden.bs.toast", () => t.remove());
}

function setLoading(el, loading) {
    if (!el) return;
    el.disabled = loading;
}

async function loadOrder() {
    const res = await api("GET", "/orders/" + ORDER_ID);
    const o = res.data;
    declaredCbm = (o.items || []).reduce(
        (s, i) => s + (parseFloat(i.declared_cbm) || 0),
        0,
    );
    declaredWeight = (o.items || []).reduce(
        (s, i) => s + (parseFloat(i.declared_weight) || 0),
        0,
    );
    document.getElementById("orderOverviewBody").innerHTML = `
      <p class="mb-1"><strong>Customer:</strong> ${escapeHtml(o.customer_name)}</p>
      <p class="mb-1"><strong>Supplier:</strong> ${escapeHtml(o.supplier_name)}</p>
      <p class="mb-1"><strong>Expected ready:</strong> ${escapeHtml(o.expected_ready_date)}</p>
      <p class="mb-0"><strong>Items:</strong> ${(o.items || []).length} — Declared: ${declaredCbm.toFixed(2)} CBM / ${declaredWeight.toFixed(0)} kg</p>
    `;
    receiveOrderItems = o.items || [];
    const configRes = await fetch(API_BASE + "/config/receiving", {
        credentials: "same-origin",
    })
        .then((r) => r.json())
        .catch(() => ({}));
    const itemLevelEnabled = configRes.data?.item_level_receiving_enabled ?? 0;
    document.getElementById("itemLevelSection").style.display = itemLevelEnabled
        ? "block"
        : "none";
    if (itemLevelEnabled && receiveOrderItems.length) {
        const tbody = document.getElementById("itemLevelBody");
        tbody.innerHTML = receiveOrderItems
            .map(
                (it, i) => `
          <tr data-order-item-id="${it.id}">
            <td>${escapeHtml((it.description_cn || it.description_en || "Item " + (i + 1)).substring(0, 40))}</td>
            <td>${it.declared_cbm || 0} / ${it.declared_weight || 0} kg</td>
            <td><input type="number" class="form-control form-control-sm item-actual-cartons" min="0" placeholder="0"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-actual-cbm" min="0" placeholder="0"></td>
            <td><input type="number" step="0.0001" class="form-control form-control-sm item-actual-weight" min="0" placeholder="0"></td>
            <td><select class="form-select form-select-sm item-condition"><option value="good">Good</option><option value="damaged">Damaged</option><option value="partial">Partial</option></select></td>
            <td><input type="file" class="d-none item-photo-input" accept="image/*" multiple><button type="button" class="btn btn-sm btn-outline-secondary item-add-photo">+</button><div class="item-photo-preview d-inline"></div></td>
          </tr>
        `,
            )
            .join("");
        tbody.querySelectorAll(".item-add-photo").forEach((btn) => {
            const row = btn.closest("tr");
            const oiId = row.dataset.orderItemId;
            const input = row.querySelector(".item-photo-input");
            input.onchange = async (e) => {
                const files = Array.from(e.target.files || []).filter((x) =>
                    x.type.startsWith("image/"),
                );
                pendingUploads += files.length;
                const submitBtn = document.getElementById("submitReceiveBtn");
                if (submitBtn) submitBtn.disabled = true;
                for (const f of files) {
                    try {
                        const p = await uploadFile(f);
                        if (p) {
                            receiveItemPhotos[oiId] =
                                receiveItemPhotos[oiId] || [];
                            receiveItemPhotos[oiId].push(p);
                        }
                    } catch (err) {
                        showToast(err.message, "danger");
                    }
                    pendingUploads--;
                }
                if (submitBtn) submitBtn.disabled = pendingUploads > 0;
                renderItemPhotos(oiId);
                e.target.value = "";
            };
            btn.onclick = () => input.click();
        });
    }
}

function renderItemPhotos(oiId) {
    const row = document.querySelector(`tr[data-order-item-id="${oiId}"]`);
    if (!row) return;
    const paths = receiveItemPhotos[oiId] || [];
    row.querySelector(".item-photo-preview").innerHTML = paths
        .map(
            (p, i) =>
                `<span class="d-inline-block me-1"><img src="/cargochina/backend/${p}" class="img-thumbnail" style="max-width:40px"><button type="button" class="btn-close btn-close-sm" onclick="removeItemPhoto('${oiId}',${i})"></button></span>`,
        )
        .join("");
}

function removeItemPhoto(oiId, i) {
    if (receiveItemPhotos[oiId]) {
        receiveItemPhotos[oiId].splice(i, 1);
        renderItemPhotos(oiId);
    }
}

function updateVarianceAlert() {
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
    document
        .getElementById("variancePhotoAlert")
        .classList.toggle(
            "d-none",
            !hasVariance || receivePhotoPaths.length > 0,
        );
    const vr = document.getElementById("varianceResult");
    const vrb = document.getElementById("varianceResultBody");
    if (hasVariance) {
        vr.style.display = "block";
        vrb.innerHTML =
            '<span class="badge bg-warning">Confirmation required</span> CBM/weight variance or damage detected. Customer confirmation needed.';
    } else {
        vr.style.display = "block";
        vrb.innerHTML =
            '<span class="badge bg-success">Ready for consolidation</span>';
    }
}

document
    .getElementById("actualCbm")
    ?.addEventListener("input", updateVarianceAlert);
document
    .getElementById("condition")
    ?.addEventListener("change", updateVarianceAlert);

document.getElementById("receiveAddPhotoBtn").onclick = () =>
    document.getElementById("receivePhotos").click();
document.getElementById("receivePhotos").onchange = async (e) => {
    const files = Array.from(e.target.files || []).filter((f) =>
        f.type.startsWith("image/"),
    );
    const btn = document.getElementById("receiveAddPhotoBtn");
    const submitBtn = document.getElementById("submitReceiveBtn");
    pendingUploads += files.length;
    if (submitBtn) submitBtn.disabled = true;
    for (let i = 0; i < files.length; i++) {
        try {
            const p = await uploadFileWithProgress(files[i], {
                showToast,
                onProgress: (pct) => {
                    if (btn)
                        btn.textContent = `Uploading ${i + 1}/${files.length} (${pct}%)…`;
                },
            });
            if (p && !receivePhotoPaths.includes(p)) receivePhotoPaths.push(p);
        } catch (err) {
            showToast(err.message, "danger");
        }
        pendingUploads--;
        if (btn)
            btn.textContent = pendingUploads > 0 ? `Uploading…` : "Add Photo";
    }
    if (submitBtn) submitBtn.disabled = pendingUploads > 0;
    if (btn) btn.textContent = "Add Photo";
    e.target.value = "";
    renderPhotoPreview();
    updateVarianceAlert();
};

function renderPhotoPreview() {
    const c = document.getElementById("photoPreview");
    c.innerHTML = receivePhotoPaths
        .map(
            (p, i) =>
                `<div class="position-relative d-inline-block"><img src="/cargochina/backend/${p}" class="img-thumbnail" style="max-width:80px"><button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" onclick="receivePhotoPaths.splice(${i},1);renderPhotoPreview();updateVarianceAlert()">×</button></div>`,
        )
        .join("");
}

document.getElementById("submitReceiveBtn").onclick = async () => {
    const actualCartons = parseInt(
        document.getElementById("actualCartons").value || 0,
    );
    const actualCbm = parseFloat(
        document.getElementById("actualCbm").value || 0,
    );
    const actualWeight = parseFloat(
        document.getElementById("actualWeight").value || 0,
    );
    const condition = document.getElementById("condition").value;
    const notes = document.getElementById("receiveNotes").value;
    const variancePct =
        declaredCbm > 0
            ? (Math.abs(actualCbm - declaredCbm) / declaredCbm) * 100
            : 0;
    const varianceAbs = Math.abs(actualCbm - declaredCbm);
    const hasVariance =
        variancePct >= 10 || varianceAbs >= 0.1 || condition !== "good";
    if (hasVariance && receivePhotoPaths.length === 0) {
        showToast("Evidence photos required when variance or damage", "danger");
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
            );
            const aCbm = parseFloat(
                row.querySelector(".item-actual-cbm")?.value || 0,
            );
            const aWeight = parseFloat(
                row.querySelector(".item-actual-weight")?.value || 0,
            );
            const itPhotos = receiveItemPhotos[it.id] || [];
            if (aCartons > 0 || aCbm > 0 || aWeight > 0 || itPhotos.length) {
                items.push({
                    order_item_id: it.id,
                    actual_cartons: aCartons || null,
                    actual_cbm: aCbm || null,
                    actual_weight: aWeight || null,
                    condition:
                        row.querySelector(".item-condition")?.value || "good",
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
        photo_paths: receivePhotoPaths,
    };
    if (items.length) payload.items = items;
    const btn = document.getElementById("submitReceiveBtn");
    try {
        setLoading(btn, true);
        const res = await api(
            "POST",
            "/orders/" + ORDER_ID + "/receive",
            payload,
        );
        showToast(
            res.data.variance_detected
                ? "Received — confirmation required"
                : "Received successfully",
        );
        window.location.href =
            AREA_BASE + "/receiving/receipt.php?id=" + res.data.receipt_id;
    } catch (e) {
        showToast(e.message || "Request failed", "danger");
    } finally {
        setLoading(btn, false);
    }
};

loadOrder().catch((e) => {
    document.getElementById("orderOverviewBody").innerHTML =
        '<p class="text-danger">' + escapeHtml(e.message) + "</p>";
});
