const PIPELINE_STAGE_CONFIG = [
    {
        key: "submitted",
        title: "Approval Queue",
        subtitle: "Orders waiting for admin approval",
        statuses: ["Submitted"],
        url: "/cargochina/orders.php?status=Submitted",
        accent: "#d97706",
    },
    {
        key: "receiving",
        title: "Receiving Queue",
        subtitle: "Approved or in-transit orders waiting for warehouse action",
        statuses: ["Approved", "InTransitToWarehouse"],
        url: "/cargochina/receiving.php",
        accent: "#2563eb",
    },
    {
        key: "awaiting_confirmation",
        title: "Customer Confirmation",
        subtitle: "Orders blocked on customer approval after receiving",
        statuses: ["AwaitingCustomerConfirmation"],
        url: "/cargochina/orders.php?status=AwaitingCustomerConfirmation",
        accent: "#dc2626",
    },
    {
        key: "consolidation",
        title: "Consolidation & Shipping",
        subtitle: "Ready, drafted, or assigned orders moving into shipment",
        statuses: [
            "ReadyForConsolidation",
            "ConsolidatedIntoShipmentDraft",
            "AssignedToContainer",
        ],
        url: "/cargochina/consolidation.php",
        accent: "#059669",
    },
];

document.addEventListener("DOMContentLoaded", loadPipeline);

async function fetchOrdersByStatuses(statuses) {
    const params = new URLSearchParams();
    statuses.forEach((status) => params.append("status[]", status));
    return api("GET", "/orders?" + params.toString());
}

function renderMetric(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value ?? 0;
}

function renderTaskList(tasks) {
    const el = document.getElementById("pipelineTasksList");
    if (!el) return;
    if (!tasks?.length) {
        el.innerHTML = '<div class="text-muted">No immediate queued tasks for your role.</div>';
        return;
    }
    el.innerHTML = tasks
        .map(
            (task) => `
        <a class="pipeline-stage-item mb-2" href="${task.url}">
          <div class="d-flex justify-content-between align-items-start gap-2">
            <div>
              <div class="fw-semibold">${escapeHtml(task.label)}</div>
              <div class="meta">Open the live queue for action.</div>
            </div>
            <span class="badge bg-primary">${task.count}</span>
          </div>
        </a>`,
        )
        .join("");
}

function renderStageBoard(stagePayloads) {
    const board = document.getElementById("pipelineStageBoard");
    if (!board) return;
    board.innerHTML = stagePayloads
        .map((stage) => {
            const preview = (stage.orders || []).slice(0, 5);
            const listHtml = preview.length
                ? preview
                      .map(
                          (order) => `
                <a class="pipeline-stage-item" href="/cargochina/orders.php?id=${order.id}">
                  <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                    <div class="fw-semibold">#${order.id} ${escapeHtml(order.customer_name || "")}</div>
                    <span class="badge ${statusBadgeClass(order.status)}">${escapeHtml(statusLabel(order.status))}</span>
                  </div>
                  <div class="meta">${escapeHtml(order.supplier_name || "No supplier")}</div>
                  <div class="meta">Expected ready: ${escapeHtml(order.expected_ready_date || "-")}</div>
                </a>`,
                      )
                      .join("")
                : '<div class="text-muted small">Nothing in this queue right now.</div>';
            return `
          <div class="pipeline-stage-card">
            <div class="stage-head" style="border-top:4px solid ${stage.accent}">
              <div class="small text-uppercase text-muted fw-semibold">${escapeHtml(stage.title)}</div>
              <div class="stage-count" style="color:${stage.accent}">${stage.count}</div>
              <div class="small text-muted mt-2">${escapeHtml(stage.subtitle)}</div>
            </div>
            <div class="stage-body">
              <div class="pipeline-stage-list mb-3">${listHtml}</div>
              <a href="${stage.url}" class="btn btn-outline-secondary btn-sm w-100">Open Queue</a>
            </div>
          </div>`;
        })
        .join("");
}

function renderSummaryTable(stats) {
    const stages = [
        { label: "Draft", count: stats.draft ?? 0, url: "/cargochina/orders.php?status=Draft" },
        { label: "Submitted", count: stats.submitted ?? 0, url: "/cargochina/orders.php?status=Submitted" },
        { label: "Pending receiving", count: stats.pending_receiving ?? 0, url: "/cargochina/receiving.php" },
        {
            label: "Awaiting confirmation",
            count: stats.awaiting_confirmation ?? 0,
            url: "/cargochina/orders.php?status=AwaitingCustomerConfirmation",
        },
        {
            label: "Ready for consolidation",
            count: stats.ready_for_consolidation ?? 0,
            url: "/cargochina/consolidation.php",
        },
        {
            label: "In shipment draft",
            count: stats.in_shipment_draft ?? 0,
            url: "/cargochina/consolidation.php",
        },
        {
            label: "Assigned to container",
            count: stats.assigned_to_container ?? 0,
            url: "/cargochina/containers.php",
        },
        { label: "Finalized", count: stats.finalized ?? 0, url: "/cargochina/consolidation.php" },
    ];
    const tbody = document.getElementById("pipelineTableBody");
    if (!tbody) return;
    tbody.innerHTML = stages
        .map(
            (stage) =>
                `<tr><td>${escapeHtml(stage.label)}</td><td>${stage.count}</td><td><a href="${stage.url}">View</a></td></tr>`,
        )
        .join("");
}

function renderShippingSummary(containers) {
    const el = document.getElementById("pipelineShipSummary");
    if (!el) return;
    const rows = containers || [];
    if (!rows.length) {
        el.innerHTML = '<div class="text-muted">No containers found.</div>';
        return;
    }
    const counts = rows.reduce(
        (acc, container) => {
            const key = container.status || "planning";
            acc[key] = (acc[key] || 0) + 1;
            return acc;
        },
        {},
    );
    const hottest = rows
        .filter((container) => (parseFloat(container.fill_pct_cbm) || 0) >= 85)
        .slice(0, 3);
    el.innerHTML = `
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span>Planning / To Go</span><strong>${(counts.planning || 0) + (counts.to_go || 0)}</strong></div>
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span>On Route</span><strong>${counts.on_route || 0}</strong></div>
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span>Arrived / Available</span><strong>${(counts.arrived || 0) + (counts.available || 0)}</strong></div>
      <div class="pt-2">
        <div class="small text-uppercase text-muted fw-semibold mb-2">Most Loaded</div>
        ${
            hottest.length
                ? hottest
                      .map(
                          (container) => `<div class="mb-1">${escapeHtml(container.code || `Container #${container.id}`)} <span class="text-muted">${parseFloat(container.fill_pct_cbm || 0).toFixed(1)}%</span></div>`,
                      )
                      .join("")
                : '<div class="text-muted">No containers above 85% fill.</div>'
        }
      </div>`;
}

async function loadPipeline() {
    try {
        const stageRequests = PIPELINE_STAGE_CONFIG.map((stage) =>
            fetchOrdersByStatuses(stage.statuses),
        );
        const [statsRes, ...stageResponses] = await Promise.all([
            api("GET", "/dashboard/stats"),
            ...stageRequests,
            api("GET", "/containers"),
        ]);

        const containersRes = stageResponses.pop();
        const stats = statsRes.data || {};

        renderMetric("pipeDraft", stats.draft ?? 0);
        renderMetric("pipeToReceive", stats.pending_receiving ?? 0);
        renderMetric("pipeAwaitConfirm", stats.awaiting_confirmation ?? 0);
        renderMetric("pipeFinalized", stats.finalized ?? 0);
        renderMetric("pipelineStaleConfirm", stats.stale_awaiting_confirmation ?? 0);
        renderMetric("pipelineStaleOverdue", stats.stale_overdue ?? 0);

        renderTaskList(stats.my_tasks || []);
        renderSummaryTable(stats);
        renderShippingSummary(containersRes.data || []);

        const stagePayloads = PIPELINE_STAGE_CONFIG.map((stage, index) => {
            const data = stageResponses[index]?.data || [];
            return {
                ...stage,
                orders: data,
                count:
                    stage.key === "submitted"
                        ? stats.submitted ?? data.length
                        : stage.key === "receiving"
                          ? stats.pending_receiving ?? data.length
                          : stage.key === "awaiting_confirmation"
                            ? stats.awaiting_confirmation ?? data.length
                            : (stats.ready_for_consolidation ?? 0) +
                              (stats.in_shipment_draft ?? 0) +
                              (stats.assigned_to_container ?? 0),
            };
        });
        renderStageBoard(stagePayloads);
    } catch (error) {
        const board = document.getElementById("pipelineStageBoard");
        if (board) {
            board.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message || "Failed to load pipeline")}</div>`;
        }
    }
}
