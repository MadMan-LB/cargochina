const CONTAINERS_API_BASE = window.API_BASE || "/cargochina/api/v1";

async function loadContainers() {
    const tbody = document.querySelector("#containersTable tbody");
    if (!tbody) return;
    tbody.innerHTML =
        '<tr><td colspan="5" class="text-center text-muted py-4">Loading...</td></tr>';
    try {
        const res = await fetch(CONTAINERS_API_BASE + "/containers", {
            credentials: "same-origin",
        });
        if (!res.ok)
            throw new Error(
                res.status === 401
                    ? "Please log in"
                    : "Failed to load containers",
            );
        const data = await res.json();
        const rows = data.data || [];
        if (rows.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="5" class="text-center text-muted py-4">No containers yet. Add containers from Consolidation.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map(
                (c) =>
                    `<tr>
            <td>${c.id}</td>
            <td><strong>${escHtml(c.code || "")}</strong></td>
            <td>${escHtml(String(c.max_cbm ?? ""))}</td>
            <td>${escHtml(String(c.max_weight ?? ""))}</td>
            <td class="d-flex gap-1 flex-wrap">
              <button class="btn btn-sm btn-outline-info js-view-container"
                      data-id="${c.id}" data-code="${escHtml(c.code || "")}"
                      title="View orders in this container">View</button>
              <a class="btn btn-sm btn-outline-success" href="${CONTAINERS_API_BASE}/containers/${c.id}/export" download title="Download all orders as Excel">Download</a>
            </td>
          </tr>`,
            )
            .join("");

        tbody.querySelectorAll(".js-view-container").forEach((btn) => {
            btn.addEventListener("click", () =>
                viewContainer(
                    parseInt(btn.dataset.id, 10),
                    btn.dataset.code || "",
                ),
            );
        });
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="text-center text-danger py-4">${escHtml(e.message || "Error loading containers")}</td></tr>`;
    }
}

async function viewContainer(id, code) {
    const modalEl = document.getElementById("containerViewModal");
    if (!modalEl) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById("containerViewTitle").textContent =
        "Container: " + (code || id);
    document.getElementById("containerViewBody").innerHTML =
        '<div class="text-center py-5"><div class="spinner-border text-primary"></div></div>';
    const dlBtn = document.getElementById("containerViewDownload");
    if (dlBtn)
        dlBtn.href = CONTAINERS_API_BASE + "/containers/" + id + "/export";
    modal.show();

    try {
        const res = await fetch(
            CONTAINERS_API_BASE + "/containers/" + id + "/orders",
            { credentials: "same-origin" },
        );
        if (!res.ok) {
            const j = await res.json().catch(() => ({}));
            throw new Error(j.message || "Failed to load container orders");
        }
        const data = (await res.json()).data || {};
        const container = data.container || {};
        const orders = data.orders || [];

        if (orders.length === 0) {
            document.getElementById("containerViewBody").innerHTML =
                '<p class="text-muted py-3">No orders are assigned to this container yet.</p>';
            return;
        }

        const totalCbm = orders.reduce(
            (s, o) => s + (parseFloat(o.total_cbm) || 0),
            0,
        );
        const totalWeight = orders.reduce(
            (s, o) => s + (parseFloat(o.total_weight) || 0),
            0,
        );
        const totalAmt = orders.reduce(
            (s, o) => s + (parseFloat(o.total_amount) || 0),
            0,
        );
        const maxCbm = parseFloat(container.max_cbm) || 1;
        const maxWt = parseFloat(container.max_weight) || 1;
        const cbmPct = Math.min(100, (totalCbm / maxCbm) * 100);
        const wtPct = Math.min(100, (totalWeight / maxWt) * 100);
        const barColor = (pct) =>
            pct >= 100 ? "#dc2626" : pct >= 85 ? "#d97706" : "#16a34a";

        const orderRows = orders
            .map((o) => {
                const sBadge = badgeHtml(o.status);
                return `<tr>
              <td>${o.id}</td>
              <td>${escHtml(o.customer_name || "—")}</td>
              <td>${escHtml(o.supplier_name || "—")}</td>
              <td>${escHtml(o.expected_ready_date || "—")}</td>
              <td>${sBadge}</td>
              <td class="text-end">${o.items || 0}</td>
              <td class="text-end">${parseFloat(o.total_cbm || 0).toFixed(3)}</td>
              <td class="text-end">${parseFloat(o.total_weight || 0).toFixed(2)} kg</td>
              <td class="text-end">${parseFloat(o.total_amount || 0).toFixed(2)} ${escHtml(o.currency || "")}</td>
            </tr>`;
            })
            .join("");

        document.getElementById("containerViewBody").innerHTML = `
          <div class="row g-3 mb-3">
            <div class="col-12 col-md-4">
              <div class="order-info-stat-card"><div class="label">Orders</div><div class="value">${orders.length}</div></div>
            </div>
            <div class="col-12 col-md-4">
              <div class="order-info-stat-card"><div class="label">Total CBM / Max</div><div class="value">${totalCbm.toFixed(3)} / ${container.max_cbm}</div></div>
            </div>
            <div class="col-12 col-md-4">
              <div class="order-info-stat-card"><div class="label">Total Weight / Max</div><div class="value">${totalWeight.toFixed(2)} / ${container.max_weight} kg</div></div>
            </div>
          </div>
          <div class="mb-3">
            <div class="d-flex justify-content-between small text-muted mb-1"><span>CBM Fill</span><span>${cbmPct.toFixed(1)}%</span></div>
            <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;"><div style="height:100%;width:${cbmPct}%;background:${barColor(cbmPct)};border-radius:4px;transition:width .4s;"></div></div>
            <div class="d-flex justify-content-between small text-muted mt-2 mb-1"><span>Weight Fill</span><span>${wtPct.toFixed(1)}%</span></div>
            <div style="height:8px;background:#e2e8f0;border-radius:4px;overflow:hidden;"><div style="height:100%;width:${wtPct}%;background:${barColor(wtPct)};border-radius:4px;transition:width .4s;"></div></div>
          </div>
          <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
              <thead class="table-light">
                <tr>
                  <th>ID</th>
                  <th>Customer</th>
                  <th>Supplier</th>
                  <th>Expected Ready</th>
                  <th>Status</th>
                  <th class="text-end">Items</th>
                  <th class="text-end">Total CBM</th>
                  <th class="text-end">Total Weight</th>
                  <th class="text-end">Total Amount</th>
                </tr>
              </thead>
              <tbody>${orderRows}</tbody>
              <tfoot class="table-light fw-semibold">
                <tr>
                  <td colspan="5" class="text-end">Totals:</td>
                  <td class="text-end">${orders.length}</td>
                  <td class="text-end">${totalCbm.toFixed(3)}</td>
                  <td class="text-end">${totalWeight.toFixed(2)} kg</td>
                  <td class="text-end">${totalAmt.toFixed(2)}</td>
                </tr>
              </tfoot>
            </table>
          </div>`;
    } catch (e) {
        document.getElementById("containerViewBody").innerHTML =
            `<div class="alert alert-danger">${escHtml(e.message)}</div>`;
    }
}

function badgeHtml(status) {
    const labels = {
        Draft: "Draft",
        Submitted: "Submitted",
        Approved: "Approved",
        InTransitToWarehouse: "In Transit",
        ReceivedAtWarehouse: "Received",
        AwaitingCustomerConfirmation: "Awaiting Confirmation",
        Confirmed: "Confirmed",
        ReadyForConsolidation: "Ready",
        ConsolidatedIntoShipmentDraft: "In Draft",
        AssignedToContainer: "Assigned",
        FinalizedAndPushedToTracking: "Finalized",
    };
    const cls =
        typeof statusBadgeClass === "function"
            ? statusBadgeClass(status)
            : "status-draft";
    return `<span class="badge ${cls}">${escHtml(labels[status] || status || "—")}</span>`;
}

function escHtml(s) {
    if (s == null) return "";
    const d = document.createElement("div");
    d.textContent = String(s);
    return d.innerHTML;
}

document.addEventListener("DOMContentLoaded", loadContainers);
