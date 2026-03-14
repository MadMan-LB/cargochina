const AC_API = window.API_BASE || "/cargochina/api/v1";

let _orders = [];
let _containers = [];
let _selContainerId = null;
let _searchTimer = null;
let targetContainerAc = null;

function escHtml(s) {
    if (s == null) return "";
    const d = document.createElement("div");
    d.textContent = String(s);
    return d.innerHTML;
}

function containerStatusDisplay(status) {
    const labels = {
        planning: "Planning",
        to_go: "To Go",
        on_route: "On Route",
        arrived: "Arrived",
        available: "Available",
    };
    return labels[status] || status || "-";
}

function debounceOrderSearch() {
    clearTimeout(_searchTimer);
    _searchTimer = setTimeout(renderOrders, 180);
}

function setMetricText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function setContainerSelection(containerId, seedItem = null) {
    const hiddenEl = document.getElementById("targetContainerId");
    const inputEl = document.getElementById("targetContainerSearch");
    const container =
        seedItem || _containers.find((c) => Number(c.id) === Number(containerId));

    if (!container) {
        _selContainerId = null;
        if (hiddenEl) hiddenEl.value = "";
        if (inputEl) inputEl.value = "";
        targetContainerAc?.setValue?.(null);
        updateSelectedContainerMeta(null);
        onContainerChange();
        return;
    }

    _selContainerId = Number(container.id);
    if (hiddenEl) hiddenEl.value = String(container.id);
    if (targetContainerAc?.setValue) {
        targetContainerAc.setValue(container);
    } else if (inputEl) {
        inputEl.value = container.code || `#${container.id}`;
    }
    updateSelectedContainerMeta(container);
    onContainerChange();
}

function updateSelectedContainerMeta(container) {
    const metaEl = document.getElementById("selectedContainerMeta");
    if (!metaEl) return;
    if (!container) {
        metaEl.textContent =
            "Search for a container to preview current fill and assignment impact.";
        return;
    }
    const destination = container.destination || container.destination_country || "No destination";
    const eta = container.eta_date ? ` ETA ${container.eta_date}` : "";
    metaEl.textContent = `${container.code || `Container #${container.id}`} • ${containerStatusDisplay(container.status)} • ${destination}${eta}`;
}

function getOrderStatusClass(status) {
    return typeof statusBadgeClass === "function"
        ? statusBadgeClass(status)
        : "bg-secondary";
}

function getOrderStatusLabel(status) {
    return typeof statusLabel === "function" ? statusLabel(status) : status;
}

function getOrderSearchText(order) {
    const items = (order.items || [])
        .map((item) => {
            return [
                item.shipping_code,
                item.item_no,
                item.description_cn,
                item.description_en,
            ]
                .filter(Boolean)
                .join(" ");
        })
        .join(" ");
    return [
        order.id,
        order.customer_name,
        order.supplier_name,
        order.status,
        items,
    ]
        .filter(Boolean)
        .join(" ")
        .toLowerCase();
}

async function init() {
    initContainerAutocomplete();
    await Promise.all([loadEligibleOrders(), loadContainers()]);
}

function initContainerAutocomplete() {
    const inputEl = document.getElementById("targetContainerSearch");
    const hiddenEl = document.getElementById("targetContainerId");
    if (!inputEl || !hiddenEl || typeof Autocomplete === "undefined") return;

    targetContainerAc = Autocomplete.init(inputEl, {
        resource: "containers",
        searchPath: "/search",
        placeholder: "Type container code or ID...",
        renderItem: (item) => {
            return [
                item.code || `#${item.id}`,
                containerStatusDisplay(item.status),
                item.max_cbm ? `${item.max_cbm} CBM` : "",
            ]
                .filter(Boolean)
                .join(" - ");
        },
        onSelect: (item) => {
            hiddenEl.value = item.id || "";
            setContainerSelection(item.id, item);
        },
    });

    inputEl.addEventListener("input", () => {
        hiddenEl.value = "";
        _selContainerId = null;
        updateSelectedContainerMeta(null);
        onContainerChange();
    });
}

async function loadEligibleOrders() {
    const tbody = document.getElementById("eligibleOrdersTbody");
    try {
        const responses = await Promise.all([
            fetch(AC_API + "/orders?status=Confirmed", {
                credentials: "same-origin",
            }),
            fetch(AC_API + "/orders?status=ReadyForConsolidation", {
                credentials: "same-origin",
            }),
        ]);
        const payloads = await Promise.all(responses.map((res) => res.json()));
        const deduped = new Map();
        payloads.forEach((payload) => {
            (payload.data || []).forEach((order) => {
                if (!deduped.has(order.id)) deduped.set(order.id, order);
            });
        });

        _orders = [...deduped.values()].map((order) => {
            const items = order.items || [];
            const totalCbm = items.reduce(
                (sum, item) => sum + (parseFloat(item.declared_cbm) || 0),
                0,
            );
            const totalWeight = items.reduce(
                (sum, item) => sum + (parseFloat(item.declared_weight) || 0),
                0,
            );
            return { ...order, total_cbm: totalCbm, total_weight: totalWeight };
        });
        renderOrders();
    } catch (e) {
        setMetricText("assignEligibleCount", 0);
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="8" class="text-danger py-3 text-center">${escHtml(e.message)}</td></tr>`;
        }
    }
}

function renderOrders() {
    const tbody = document.getElementById("eligibleOrdersTbody");
    const q = (document.getElementById("orderSearch")?.value || "")
        .trim()
        .toLowerCase();
    const rows = _orders.filter((order) => !q || getOrderSearchText(order).includes(q));

    const label = document.getElementById("eligibleCountLabel");
    if (label) label.textContent = rows.length ? `(${rows.length})` : "";
    setMetricText("assignEligibleCount", rows.length);

    if (!rows.length) {
        tbody.innerHTML =
            '<tr><td colspan="8" class="text-center text-muted py-4">No eligible orders found.</td></tr>';
        updateSelectionMetrics([]);
        return;
    }

    tbody.innerHTML = rows
        .map(
            (order) => `
        <tr>
          <td class="text-center"><input type="checkbox" class="form-check-input order-cb" data-id="${order.id}" data-cbm="${order.total_cbm}" data-weight="${order.total_weight}" onchange="onSelectionChange()"></td>
          <td class="fw-semibold">${order.id}</td>
          <td>
            ${escHtml(order.customer_name || "-")}
            ${order.customer_priority_level && order.customer_priority_level !== "normal" ? ` <span class="badge bg-warning text-dark ms-1" title="${escHtml(order.customer_priority_note || "")}">${escHtml(order.customer_priority_level)}</span>` : ""}
            ${order.high_alert_notes ? ` <span class="badge bg-danger-subtle text-danger border border-danger-subtle ms-1" title="${escHtml(order.high_alert_notes)}">Alert</span>` : ""}
          </td>
          <td class="small text-muted">${escHtml((order.supplier_name || "-").substring(0, 36))}</td>
          <td><span class="badge ${getOrderStatusClass(order.status)}">${escHtml(getOrderStatusLabel(order.status))}</span></td>
          <td class="text-end">${order.total_cbm.toFixed(3)}</td>
          <td class="text-end">${order.total_weight.toFixed(2)} kg</td>
          <td class="text-end small">${escHtml(order.expected_ready_date || "-")}</td>
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
    const checkboxes = document.querySelectorAll(".order-cb");
    const allChecked = [...checkboxes].length > 0 && [...checkboxes].every((cb) => cb.checked);
    checkboxes.forEach((cb) => {
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

function updateSelectionMetrics(selectedOrders) {
    const selected = selectedOrders || [];
    const totals = selected.reduce(
        (sum, order) => ({
            cbm: sum.cbm + order.cbm,
            weight: sum.weight + order.weight,
        }),
        { cbm: 0, weight: 0 },
    );
    setMetricText("assignSelectedCountStat", selected.length);
    setMetricText("assignSelectedCbmStat", totals.cbm.toFixed(3));
    setMetricText("assignSelectedWeightStat", totals.weight.toFixed(2));
    const countEl = document.getElementById("selectedCountLabel");
    const cbmEl = document.getElementById("selectedCbmLabel");
    const weightEl = document.getElementById("selectedWeightLabel");
    if (countEl) countEl.textContent = selected.length;
    if (cbmEl) cbmEl.textContent = totals.cbm.toFixed(3);
    if (weightEl) weightEl.textContent = totals.weight.toFixed(2);
}

function getSuggestedContainer(selectedOrders) {
    if (!selectedOrders.length || !_containers.length) return null;
    const addCbm = selectedOrders.reduce((sum, order) => sum + order.cbm, 0);
    const addWeight = selectedOrders.reduce(
        (sum, order) => sum + order.weight,
        0,
    );
    const withRoom = _containers
        .filter((container) => {
            const usedCbm = parseFloat(container.used_cbm) || 0;
            const usedWeight = parseFloat(container.used_weight) || 0;
            const maxCbm = parseFloat(container.max_cbm) || 0;
            const maxWeight = parseFloat(container.max_weight) || 0;
            return usedCbm < maxCbm && usedWeight < maxWeight;
        })
        .sort((a, b) => a.id - b.id);

    for (const container of withRoom) {
        const usedCbm = parseFloat(container.used_cbm) || 0;
        const usedWeight = parseFloat(container.used_weight) || 0;
        const maxCbm = parseFloat(container.max_cbm) || 1;
        const maxWeight = parseFloat(container.max_weight) || 1;
        if (usedCbm + addCbm <= maxCbm && usedWeight + addWeight <= maxWeight) {
            return container.id;
        }
    }
    return withRoom[0]?.id ?? null;
}

function onSelectionChange() {
    const selected = getSelectedOrders();
    updateSelectionMetrics(selected);

    const totalCheckboxes = document.querySelectorAll(".order-cb").length;
    const masterCheck = document.getElementById("masterCheck");
    if (masterCheck) {
        masterCheck.indeterminate =
            selected.length > 0 && selected.length < totalCheckboxes;
        masterCheck.checked =
            totalCheckboxes > 0 && selected.length === totalCheckboxes;
    }

    if (selected.length > 0 && !_selContainerId) {
        const suggestedId = getSuggestedContainer(selected);
        if (suggestedId) {
            setContainerSelection(suggestedId);
            return;
        }
    }

    updateCapacityPreview();
    updateAssignBtn();
}

async function loadContainers() {
    try {
        const res = await fetch(AC_API + "/containers", {
            credentials: "same-origin",
        });
        const data = await res.json();
        _containers = data.data || [];
        renderContainerSummary();
        if (_selContainerId && !_containers.find((c) => c.id === _selContainerId)) {
            setContainerSelection(null);
        } else if (_selContainerId) {
            updateSelectedContainerMeta(
                _containers.find((c) => c.id === _selContainerId) || null,
            );
            onContainerChange();
        }
    } catch (_) {
        renderContainerSummary();
    }
}

function renderContainerSummary() {
    const el = document.getElementById("containerSummaryList");
    if (!el) return;
    const barColor = (pct) =>
        pct >= 100 ? "#dc2626" : pct >= 85 ? "#d97706" : "#16a34a";

    el.innerHTML =
        _containers
            .map((container) => {
                const pct = parseFloat(container.fill_pct_cbm) || 0;
                const status = containerStatusDisplay(container.status);
                const destination = container.destination || container.destination_country || "No destination";
                return `
          <div class="px-3 py-3 border-bottom">
            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
              <div>
                <div class="fw-semibold">${escHtml(container.code || `Container #${container.id}`)}</div>
                <div class="small text-muted">${escHtml(destination)}${container.eta_date ? ` • ETA ${escHtml(container.eta_date)}` : ""}</div>
              </div>
              <button type="button" class="btn btn-outline-primary btn-sm" onclick="pickSummaryContainer(${container.id})">Use</button>
            </div>
            <div class="d-flex justify-content-between small text-muted mb-1"><span>${escHtml(status)}</span><span>${pct.toFixed(1)}%</span></div>
            <div class="capacity-meter mb-2"><span style="width:${Math.min(100, pct)}%;background:${barColor(pct)}"></span></div>
            <div class="small text-muted">${parseFloat(container.used_cbm || 0).toFixed(2)}/${container.max_cbm} CBM • ${container.order_count || 0} orders</div>
          </div>`;
            })
            .join("") ||
        '<div class="text-muted text-center py-3">No containers available</div>';
}

window.pickSummaryContainer = function (containerId) {
    setContainerSelection(containerId);
};

function onContainerChange() {
    const hiddenEl = document.getElementById("targetContainerId");
    _selContainerId = hiddenEl?.value ? parseInt(hiddenEl.value, 10) : _selContainerId;
    const panel = document.getElementById("containerCapacityPanel");
    if (!_selContainerId) {
        panel?.classList.add("d-none");
        updateAssignBtn();
        return;
    }

    const container = _containers.find((item) => item.id === _selContainerId);
    if (!container) {
        panel?.classList.add("d-none");
        updateAssignBtn();
        return;
    }

    panel?.classList.remove("d-none");
    updateSelectedContainerMeta(container);

    const maxCbm = parseFloat(container.max_cbm) || 1;
    const maxWeight = parseFloat(container.max_weight) || 1;
    const usedCbm = parseFloat(container.used_cbm) || 0;
    const usedWeight = parseFloat(container.used_weight) || 0;
    const cbmPct = Math.min(100, (usedCbm / maxCbm) * 100);
    const weightPct = Math.min(100, (usedWeight / maxWeight) * 100);
    const barColor = (pct) =>
        pct >= 100 ? "#dc2626" : pct >= 85 ? "#d97706" : "#16a34a";

    document.getElementById("cbmCurrentLabel").textContent =
        `${usedCbm.toFixed(2)} / ${maxCbm} CBM (${cbmPct.toFixed(0)}%)`;
    document.getElementById("cbmCurrentBar").style.cssText =
        `height:100%;width:${cbmPct}%;background:${barColor(cbmPct)};border-radius:4px;`;
    document.getElementById("wCurrentLabel").textContent =
        `${usedWeight.toFixed(0)} / ${maxWeight} kg (${weightPct.toFixed(0)}%)`;
    document.getElementById("wCurrentBar").style.cssText =
        `height:100%;width:${weightPct}%;background:${barColor(weightPct)};border-radius:4px;`;

    updateCapacityPreview();
    updateAssignBtn();
}

function updateCapacityPreview() {
    const selected = getSelectedOrders();
    const afterPanel = document.getElementById("afterSelectionPanel");
    const warnEl = document.getElementById("capacityWarning");
    if (!_selContainerId || !selected.length) {
        afterPanel?.classList.add("d-none");
        warnEl?.classList.add("d-none");
        return;
    }

    const container = _containers.find((item) => item.id === _selContainerId);
    if (!container) return;

    afterPanel?.classList.remove("d-none");
    const maxCbm = parseFloat(container.max_cbm) || 1;
    const maxWeight = parseFloat(container.max_weight) || 1;
    const addCbm = selected.reduce((sum, order) => sum + order.cbm, 0);
    const addWeight = selected.reduce((sum, order) => sum + order.weight, 0);
    const afterCbm = (parseFloat(container.used_cbm) || 0) + addCbm;
    const afterWeight = (parseFloat(container.used_weight) || 0) + addWeight;
    const cbmPct = Math.min(120, (afterCbm / maxCbm) * 100);
    const weightPct = Math.min(120, (afterWeight / maxWeight) * 100);
    const barColor = (pct) =>
        pct >= 100 ? "#dc2626" : pct >= 85 ? "#d97706" : "#16a34a";

    document.getElementById("cbmAfterLabel").textContent =
        `${afterCbm.toFixed(2)} / ${maxCbm} CBM`;
    document.getElementById("cbmAfterLabel").style.color =
        cbmPct >= 100 ? "#dc2626" : "inherit";
    document.getElementById("cbmAfterBar").style.cssText =
        `height:100%;width:${Math.min(100, cbmPct)}%;background:${barColor(cbmPct)};border-radius:4px;`;
    document.getElementById("wAfterLabel").textContent =
        `${afterWeight.toFixed(0)} / ${maxWeight} kg`;
    document.getElementById("wAfterLabel").style.color =
        weightPct >= 100 ? "#dc2626" : "inherit";
    document.getElementById("wAfterBar").style.cssText =
        `height:100%;width:${Math.min(100, weightPct)}%;background:${barColor(weightPct)};border-radius:4px;`;

    const overCbm = afterCbm > maxCbm;
    const overWeight = afterWeight > maxWeight;
    if (overCbm || overWeight) {
        const messages = [];
        if (overCbm) {
            messages.push(
                `CBM would be ${afterCbm.toFixed(2)} / ${maxCbm} (over by ${(afterCbm - maxCbm).toFixed(2)})`,
            );
        }
        if (overWeight) {
            messages.push(
                `Weight would be ${afterWeight.toFixed(0)} / ${maxWeight} kg (over by ${(afterWeight - maxWeight).toFixed(0)} kg)`,
            );
        }
        warnEl.innerHTML = `<strong>Over capacity:</strong> ${messages.join(". ")}. You can still confirm the assignment if needed.`;
        warnEl.classList.remove("d-none");
    } else {
        warnEl.classList.add("d-none");
    }
}

function updateAssignBtn() {
    const btn = document.getElementById("assignBtn");
    if (!btn) return;
    btn.disabled = getSelectedOrders().length === 0 || !_selContainerId;
}

async function doAssign() {
    const selected = getSelectedOrders();
    if (!selected.length || !_selContainerId) return;
    const orderIds = selected.map((order) => order.id);

    const btn = document.getElementById("assignBtn");
    const resEl = document.getElementById("assignResult");
    btn.disabled = true;
    btn.textContent = "Assigning...";
    resEl.classList.add("d-none");

    try {
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

        if (res.status === 409 && data.over_capacity) {
            btn.disabled = false;
            btn.textContent = "Assign Selected Orders to Container";
            if (!confirm("Over capacity!\n\n" + data.message + "\n\nAssign anyway?")) {
                return;
            }
            btn.disabled = true;
            btn.textContent = "Assigning...";
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
        resEl.innerHTML = `<strong>Done.</strong> ${data.data.orders_added} order(s) assigned to container (Draft #${data.data.draft_id}).${data.data.over_capacity ? ' <span class="text-danger">Container is now over capacity.</span>' : ""}`;
        resEl.classList.remove("d-none");

        await Promise.all([loadEligibleOrders(), loadContainers()]);
        document.querySelectorAll(".order-cb").forEach((cb) => {
            cb.checked = false;
        });
        onSelectionChange();
    } catch (e) {
        resEl.className = "alert alert-danger mt-2";
        resEl.textContent = e.message;
        resEl.classList.remove("d-none");
    } finally {
        btn.disabled = false;
        btn.textContent = "Assign Selected Orders to Container";
    }
}

document.addEventListener("DOMContentLoaded", init);
