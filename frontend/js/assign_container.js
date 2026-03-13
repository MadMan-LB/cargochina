/* Assign to Container page */
const AC_API = window.API_BASE || "/cargochina/api/v1";

let _orders = []; // all eligible orders
let _containers = []; // all containers with fill stats
let _selContainerId = null;
let _searchTimer = null;

function debounceOrderSearch() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(renderOrders, 250);
}

// ---------------------------------------------------------------------------
async function init() {
    await Promise.all([loadEligibleOrders(), loadContainers()]);
}

async function loadEligibleOrders() {
    const tbody = document.getElementById("eligibleOrdersTbody");
    try {
        // Fetch all orders that are ready/confirmed — not yet finalized
        const res = await fetch(
            AC_API + "/orders?status=ReadyForConsolidation",
            { credentials: "same-origin" },
        );
        const res2 = await fetch(AC_API + "/orders?status=Confirmed", {
            credentials: "same-origin",
        });
        const [d1, d2] = await Promise.all([res.json(), res2.json()]);
        const allOrders = [...(d1.data || []), ...(d2.data || [])];

        // Compute CBM/weight per order from items
        _orders = allOrders.map((o) => {
            const items = o.items || [];
            const cbm = items.reduce(
                (s, it) => s + (parseFloat(it.declared_cbm) || 0),
                0,
            );
            const weight = items.reduce(
                (s, it) => s + (parseFloat(it.declared_weight) || 0),
                0,
            );
            return { ...o, total_cbm: cbm, total_weight: weight };
        });
        renderOrders();
    } catch (e) {
        if (tbody)
            tbody.innerHTML = `<tr><td colspan="8" class="text-danger py-3 text-center">${escHtml(e.message)}</td></tr>`;
    }
}

function renderOrders() {
    const tbody = document.getElementById("eligibleOrdersTbody");
    const q = (
        document.getElementById("orderSearch")?.value || ""
    ).toLowerCase();
    let rows = _orders.filter(
        (o) =>
            !q ||
            (o.customer_name || "").toLowerCase().includes(q) ||
            String(o.id).includes(q),
    );

    const label = document.getElementById("eligibleCountLabel");
    if (label) label.textContent = rows.length ? `(${rows.length})` : "";

    if (rows.length === 0) {
        tbody.innerHTML =
            '<tr><td colspan="8" class="text-center text-muted py-4">No eligible orders found.</td></tr>';
        return;
    }

    const statusCls =
        typeof statusBadgeClass === "function"
            ? statusBadgeClass
            : () => "bg-secondary";
    const statusLbl =
        typeof statusLabel === "function" ? statusLabel : (s) => s;

    tbody.innerHTML = rows
        .map(
            (o) => `
        <tr>
          <td class="text-center"><input type="checkbox" class="form-check-input order-cb" data-id="${o.id}" data-cbm="${o.total_cbm}" data-weight="${o.total_weight}" onchange="onSelectionChange()"></td>
          <td class="fw-semibold">${o.id}</td>
          <td>${escHtml(o.customer_name || "—")}</td>
          <td class="small text-muted">${escHtml((o.supplier_name || "—").substring(0, 25))}</td>
          <td><span class="badge ${statusCls(o.status)}">${escHtml(statusLbl(o.status))}</span></td>
          <td class="text-end">${o.total_cbm.toFixed(3)}</td>
          <td class="text-end">${o.total_weight.toFixed(2)} kg</td>
          <td class="text-end small">${escHtml(o.expected_ready_date || "—")}</td>
        </tr>`,
        )
        .join("");
    onSelectionChange();
}

function toggleAll(masterCb) {
    document.querySelectorAll(".order-cb").forEach((cb) => {
        cb.checked = masterCb.checked;
    });
    onSelectionChange();
}

function selectAllOrders() {
    const cbs = document.querySelectorAll(".order-cb");
    const allChecked = [...cbs].every((cb) => cb.checked);
    cbs.forEach((cb) => {
        cb.checked = !allChecked;
    });
    onSelectionChange();
}

function getSelectedOrders() {
    return [...document.querySelectorAll(".order-cb:checked")].map((cb) => ({
        id: parseInt(cb.dataset.id, 10),
        cbm: parseFloat(cb.dataset.cbm) || 0,
        weight: parseFloat(cb.dataset.weight) || 0,
    }));
}

function getSuggestedContainer(selectedOrders) {
    if (!selectedOrders.length || !_containers.length) return null;
    const addCbm = selectedOrders.reduce((s, o) => s + o.cbm, 0);
    const addWeight = selectedOrders.reduce((s, o) => s + o.weight, 0);
    // Oldest first (lowest id), among those with room
    const withRoom = _containers
        .filter((c) => {
            const usedCbm = parseFloat(c.used_cbm) || 0;
            const usedWt = parseFloat(c.used_weight) || 0;
            const maxCbm = parseFloat(c.max_cbm) || 0;
            const maxWt = parseFloat(c.max_weight) || 0;
            return usedCbm < maxCbm && usedWt < maxWt;
        })
        .sort((a, b) => a.id - b.id);
    for (const c of withRoom) {
        const usedCbm = parseFloat(c.used_cbm) || 0;
        const usedWt = parseFloat(c.used_weight) || 0;
        const maxCbm = parseFloat(c.max_cbm) || 1;
        const maxWt = parseFloat(c.max_weight) || 1;
        if (usedCbm + addCbm <= maxCbm && usedWt + addWeight <= maxWt)
            return c.id;
    }
    // No perfect fit: suggest oldest with any room (user can force)
    return withRoom[0]?.id ?? null;
}

function onSelectionChange() {
    const sel = getSelectedOrders();
    const total = sel.reduce(
        (acc, o) => ({ cbm: acc.cbm + o.cbm, weight: acc.weight + o.weight }),
        { cbm: 0, weight: 0 },
    );
    document.getElementById("selectedCountLabel").textContent = sel.length;
    document.getElementById("selectedCbmLabel").textContent =
        total.cbm.toFixed(3);
    document.getElementById("selectedWeightLabel").textContent =
        total.weight.toFixed(2);
    document.getElementById("masterCheck").indeterminate =
        sel.length > 0 &&
        sel.length < document.querySelectorAll(".order-cb").length;
    document.getElementById("masterCheck").checked =
        sel.length > 0 &&
        sel.length === document.querySelectorAll(".order-cb").length;

    // Auto-suggest: oldest container that hasn't filled yet
    const selEl = document.getElementById("targetContainerSelect");
    if (sel.length === 0) {
        selEl.value = "";
        _selContainerId = null;
    } else if (!selEl.value) {
        const suggestedId = getSuggestedContainer(sel);
        if (suggestedId) {
            selEl.value = suggestedId;
            _selContainerId = suggestedId;
            onContainerChange();
            return;
        }
    }

    updateCapacityPreview();
    updateAssignBtn();
}

// ---------------------------------------------------------------------------
async function loadContainers() {
    try {
        const res = await fetch(AC_API + "/containers", {
            credentials: "same-origin",
        });
        const data = await res.json();
        _containers = data.data || [];
        renderContainerSelect();
        renderContainerSummary();
    } catch (e) {
        /* silent */
    }
}

function renderContainerSelect() {
    const sel = document.getElementById("targetContainerSelect");
    const prev = sel.value;
    sel.innerHTML =
        '<option value="">— Choose a container —</option>' +
        _containers
            .map((c) => {
                const pct = c.fill_pct_cbm || 0;
                const barStr =
                    pct >= 85
                        ? ` ⚠ ${pct}% full`
                        : pct > 0
                          ? ` ${pct}% full`
                          : "";
                return `<option value="${c.id}">${escHtml(c.code)} (max ${c.max_cbm} CBM${barStr})</option>`;
            })
            .join("");
    if (prev) sel.value = prev;
    onContainerChange();
}

function renderContainerSummary() {
    const el = document.getElementById("containerSummaryList");
    if (!el) return;
    const barC = (p) =>
        p >= 100 ? "#dc2626" : p >= 85 ? "#d97706" : "#16a34a";
    el.innerHTML =
        _containers
            .map((c) => {
                const pct = c.fill_pct_cbm || 0;
                const wPct =
                    c.max_weight > 0
                        ? Math.min(100, (c.used_weight / c.max_weight) * 100)
                        : 0;
                const CONTAINER_STATUS = {
                    planning: { label: "Planning", cls: "bg-secondary" },
                    to_go: { label: "To Go", cls: "bg-warning text-dark" },
                    on_route: { label: "On Route", cls: "bg-primary" },
                    arrived: { label: "Arrived", cls: "bg-success" },
                    available: { label: "Available", cls: "bg-info text-dark" },
                };
                const st = CONTAINER_STATUS[c.status] || {
                    label: c.status || "—",
                    cls: "bg-secondary",
                };
                return `<div class="px-3 py-2 border-bottom">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <span class="fw-semibold">${escHtml(c.code)}</span>
            <span class="badge ${st.cls}">${escHtml(st.label)}</span>
          </div>
          <div style="height:5px;background:#e2e8f0;border-radius:3px;overflow:hidden;margin-bottom:2px;">
            <div style="height:100%;width:${Math.min(100, pct)}%;background:${barC(pct)};border-radius:3px;"></div>
          </div>
          <span class="text-muted" style="font-size:.72rem">${parseFloat(c.used_cbm || 0).toFixed(2)}/${c.max_cbm} CBM &bull; ${c.order_count || 0} orders</span>
        </div>`;
            })
            .join("") ||
        '<div class="text-muted text-center py-3">No containers</div>';
}

function onContainerChange() {
    const sel = document.getElementById("targetContainerSelect");
    _selContainerId = sel.value ? parseInt(sel.value, 10) : null;
    const panel = document.getElementById("containerCapacityPanel");
    if (!_selContainerId) {
        panel.classList.add("d-none");
        updateAssignBtn();
        return;
    }
    panel.classList.remove("d-none");
    const c = _containers.find((x) => x.id === _selContainerId);
    if (!c) return;
    const maxCbm = parseFloat(c.max_cbm) || 1;
    const maxWt = parseFloat(c.max_weight) || 1;
    const usedCbm = parseFloat(c.used_cbm) || 0;
    const usedWt = parseFloat(c.used_weight) || 0;
    const cbmPct = Math.min(100, (usedCbm / maxCbm) * 100);
    const wPct = Math.min(100, (usedWt / maxWt) * 100);
    const barC = (p) =>
        p >= 100 ? "#dc2626" : p >= 85 ? "#d97706" : "#16a34a";
    document.getElementById("cbmCurrentLabel").textContent =
        `${usedCbm.toFixed(2)} / ${maxCbm} CBM (${cbmPct.toFixed(0)}%)`;
    document.getElementById("cbmCurrentBar").style.cssText =
        `height:100%;width:${cbmPct}%;background:${barC(cbmPct)};border-radius:4px;`;
    document.getElementById("wCurrentLabel").textContent =
        `${usedWt.toFixed(0)} / ${maxWt} kg (${wPct.toFixed(0)}%)`;
    document.getElementById("wCurrentBar").style.cssText =
        `height:100%;width:${wPct}%;background:${barC(wPct)};border-radius:4px;`;
    updateCapacityPreview();
    updateAssignBtn();
}

function updateCapacityPreview() {
    const sel = getSelectedOrders();
    const afterPanel = document.getElementById("afterSelectionPanel");
    const warnEl = document.getElementById("capacityWarning");
    if (!_selContainerId || sel.length === 0) {
        afterPanel.classList.add("d-none");
        warnEl.classList.add("d-none");
        return;
    }
    const c = _containers.find((x) => x.id === _selContainerId);
    if (!c) return;
    afterPanel.classList.remove("d-none");
    const maxCbm = parseFloat(c.max_cbm) || 1;
    const maxWt = parseFloat(c.max_weight) || 1;
    const addCbm = sel.reduce((s, o) => s + o.cbm, 0);
    const addWeight = sel.reduce((s, o) => s + o.weight, 0);
    const afterCbm = (parseFloat(c.used_cbm) || 0) + addCbm;
    const afterWeight = (parseFloat(c.used_weight) || 0) + addWeight;
    const cbmPct = Math.min(120, (afterCbm / maxCbm) * 100);
    const wPct = Math.min(120, (afterWeight / maxWt) * 100);
    const barC = (p) =>
        p >= 100 ? "#dc2626" : p >= 85 ? "#d97706" : "#16a34a";

    document.getElementById("cbmAfterLabel").textContent =
        `${afterCbm.toFixed(2)} / ${maxCbm} CBM`;
    document.getElementById("cbmAfterLabel").style.color =
        cbmPct >= 100 ? "#dc2626" : "inherit";
    document.getElementById("cbmAfterBar").style.cssText =
        `height:100%;width:${Math.min(100, cbmPct)}%;background:${barC(cbmPct)};border-radius:4px;`;
    document.getElementById("wAfterLabel").textContent =
        `${afterWeight.toFixed(0)} / ${maxWt} kg`;
    document.getElementById("wAfterLabel").style.color =
        wPct >= 100 ? "#dc2626" : "inherit";
    document.getElementById("wAfterBar").style.cssText =
        `height:100%;width:${Math.min(100, wPct)}%;background:${barC(wPct)};border-radius:4px;`;

    const overCbm = afterCbm > maxCbm;
    const overWeight = afterWeight > maxWt;
    if (overCbm || overWeight) {
        const msgs = [];
        if (overCbm)
            msgs.push(
                `CBM would be ${afterCbm.toFixed(2)} / ${maxCbm} (over by ${(afterCbm - maxCbm).toFixed(2)})`,
            );
        if (overWeight)
            msgs.push(
                `Weight would be ${afterWeight.toFixed(0)} / ${maxWt} kg (over by ${(afterWeight - maxWt).toFixed(0)} kg)`,
            );
        warnEl.innerHTML = `<strong>⚠ Over capacity!</strong> ${msgs.join(". ")}. You can still assign and confirm below.`;
        warnEl.classList.remove("d-none");
    } else {
        warnEl.classList.add("d-none");
    }
}

function updateAssignBtn() {
    const btn = document.getElementById("assignBtn");
    const sel = getSelectedOrders();
    btn.disabled = sel.length === 0 || !_selContainerId;
}

async function doAssign() {
    const sel = getSelectedOrders();
    if (sel.length === 0 || !_selContainerId) return;
    const orderIds = sel.map((o) => o.id);

    const btn = document.getElementById("assignBtn");
    const resEl = document.getElementById("assignResult");
    btn.disabled = true;
    btn.textContent = "Assigning…";
    resEl.classList.add("d-none");

    try {
        // First attempt (no force)
        let res = await fetch(
            AC_API + "/containers/" + _selContainerId + "/assign-orders",
            {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                credentials: "same-origin",
                body: JSON.stringify({ order_ids: orderIds }),
            },
        );
        let data = await res.json();

        // If over capacity, ask user to confirm
        if (res.status === 409 && data.over_capacity) {
            btn.disabled = false;
            btn.textContent = "Assign Selected Orders to Container";
            if (
                !confirm(
                    "⚠ Over capacity!\n\n" +
                        data.message +
                        "\n\nAssign anyway?",
                )
            )
                return;
            btn.disabled = true;
            btn.textContent = "Assigning…";
            res = await fetch(
                AC_API + "/containers/" + _selContainerId + "/assign-orders",
                {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    credentials: "same-origin",
                    body: JSON.stringify({ order_ids: orderIds, force: true }),
                },
            );
            data = await res.json();
        }

        if (!res.ok) throw new Error(data.message || "Assignment failed");

        resEl.className = "alert alert-success mt-2";
        resEl.innerHTML = `<strong>✓ Done!</strong> ${data.data.orders_added} order(s) assigned to container (Draft #${data.data.draft_id}).${data.data.over_capacity ? ' <span class="text-danger">Note: container is over capacity.</span>' : ""}`;
        resEl.classList.remove("d-none");

        // Refresh
        await Promise.all([loadEligibleOrders(), loadContainers()]);
    } catch (e) {
        resEl.className = "alert alert-danger mt-2";
        resEl.textContent = e.message;
        resEl.classList.remove("d-none");
    } finally {
        btn.disabled = false;
        btn.textContent = "Assign Selected Orders to Container";
    }
}

function escHtml(s) {
    if (s == null) return "";
    const d = document.createElement("div");
    d.textContent = String(s);
    return d.innerHTML;
}

document.addEventListener("DOMContentLoaded", init);
