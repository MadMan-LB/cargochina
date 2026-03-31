function pipelineT(text, replacements = null) {
    return typeof t === "function" ? t(text, replacements) : text;
}

function buildPipelineMoreOrdersText(count) {
    if ((typeof uiLocale === "function" ? uiLocale() : "en") === "zh-CN") {
        return `${count} 个订单仍在完整队列中等待处理。`;
    }
    return `${count} more order${count === 1 ? "" : "s"} are waiting in the full queue.`;
}

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
        key: "customer_feedback",
        title: "Customer Feedback",
        subtitle: "Auto-confirmed receipts still waiting for customer review",
        query: "/orders?customer_feedback=pending",
        url: "/cargochina/orders.php?customer_feedback=pending",
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

const PIPELINE_STAGE_PREVIEW_LIMIT = 3;

async function fetchOrdersByStatuses(statuses) {
    const params = new URLSearchParams();
    statuses.forEach((status) => params.append("status[]", status));
    return api("GET", "/orders?" + params.toString());
}

async function fetchPipelineStage(stage) {
    if (stage.query) {
        return api("GET", stage.query);
    }
    return fetchOrdersByStatuses(stage.statuses || []);
}

function renderMetric(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value ?? 0;
}

function renderTaskList(tasks) {
    const el = document.getElementById("pipelineTasksList");
    if (!el) return;
    if (!tasks?.length) {
        el.innerHTML = `<div class="text-muted">${escapeHtml(
            pipelineT("No immediate queued tasks for your role."),
        )}</div>`;
        return;
    }
    el.innerHTML = tasks
        .map(
            (task) => `
        <a class="pipeline-focus-item" href="${task.url}">
          <div class="pipeline-focus-copy">
            <div class="pipeline-focus-title">${escapeHtml(task.label)}</div>
            <div class="pipeline-focus-meta">${escapeHtml(
                pipelineT("Open the live queue for action."),
            )}</div>
          </div>
          <span class="pipeline-focus-count">${task.count}</span>
        </a>`,
        )
        .join("");
}

function renderStageBoard(stagePayloads) {
    const board = document.getElementById("pipelineStageBoard");
    if (!board) return;
    board.innerHTML = stagePayloads
        .map((stage) => {
            const preview = (stage.orders || []).slice(0, PIPELINE_STAGE_PREVIEW_LIMIT);
            const hiddenCount = Math.max((stage.count || 0) - preview.length, 0);
            const listHtml = preview.length
                ? preview
                      .map(
                          (order) => `
                <a class="pipeline-stage-item" href="/cargochina/orders.php?id=${order.id}">
                  <div class="pipeline-stage-item-head">
                    <div class="pipeline-stage-order-ref">#${order.id}</div>
                    <span class="badge ${statusBadgeClass(order.status)} pipeline-stage-status">${escapeHtml(statusLabel(order.status))}</span>
                  </div>
                  <div class="pipeline-stage-primary">${escapeHtml(order.customer_name || pipelineT("Unnamed customer"))}</div>
                  <div class="pipeline-stage-secondary">${escapeHtml(order.supplier_name || pipelineT("No supplier linked yet"))}</div>
                  <div class="pipeline-stage-meta-row">
                    <span>${escapeHtml(pipelineT("Expected ready"))}</span>
                    <strong>${escapeHtml(order.expected_ready_date || "-")}</strong>
                  </div>
                </a>`,
                      )
                      .join("")
                : `<div class="text-muted small">${escapeHtml(
                      pipelineT("Nothing in this queue right now."),
                  )}</div>`;
            return `
          <div class="pipeline-stage-card">
            <div class="stage-head" style="--stage-accent:${stage.accent}">
              <div class="pipeline-stage-topline">
                <div>
                  <div class="small text-uppercase text-muted fw-semibold">${escapeHtml(
                      pipelineT(stage.title),
                  )}</div>
                  <div class="small text-muted mt-2">${escapeHtml(
                      pipelineT(stage.subtitle),
                  )}</div>
                </div>
                <div class="stage-count-pill">${stage.count}</div>
              </div>
              <div class="pipeline-stage-inline-metrics">
                <div class="pipeline-stage-inline-metric">
                  <span class="label">${escapeHtml(
                      pipelineT("Previewing"),
                  )}</span>
                  <strong>${preview.length}</strong>
                </div>
                <div class="pipeline-stage-inline-metric">
                  <span class="label">${escapeHtml(
                      pipelineT("Still waiting"),
                  )}</span>
                  <strong>${hiddenCount}</strong>
                </div>
              </div>
            </div>
            <div class="stage-body">
              <div class="pipeline-stage-list">${listHtml}</div>
              ${
                  hiddenCount
                      ? `<div class="pipeline-stage-more">${escapeHtml(buildPipelineMoreOrdersText(hiddenCount))}</div>`
                      : ""
              }
              <a href="${stage.url}" class="btn btn-outline-secondary btn-sm w-100 pipeline-stage-cta">${escapeHtml(
                  pipelineT("Open Queue"),
              )}</a>
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
            label: "Customer feedback pending",
            count: stats.customer_feedback_pending ?? 0,
            url: "/cargochina/orders.php?customer_feedback=pending",
        },
        {
            label: "Declined after auto-confirm",
            count: stats.declined_after_auto_confirm ?? 0,
            url: "/cargochina/orders.php?customer_feedback=declined_after_auto_confirm",
        },
        { label: "Ready for consolidation", count: stats.ready_for_consolidation ?? 0, url: "/cargochina/consolidation.php" },
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
                `<tr><td>${escapeHtml(pipelineT(stage.label))}</td><td>${stage.count}</td><td><a href="${stage.url}">${escapeHtml(
                    pipelineT("View"),
                )}</a></td></tr>`,
        )
        .join("");
}

function renderShippingSummary(containers) {
    const el = document.getElementById("pipelineShipSummary");
    if (!el) return;
    const rows = containers || [];
    if (!rows.length) {
        el.innerHTML = `<div class="text-muted">${escapeHtml(
            pipelineT("No containers found."),
        )}</div>`;
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
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span>${escapeHtml(pipelineT("Planning / To Go"))}</span><strong>${(counts.planning || 0) + (counts.to_go || 0)}</strong></div>
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span>${escapeHtml(pipelineT("On Route"))}</span><strong>${counts.on_route || 0}</strong></div>
      <div class="d-flex justify-content-between align-items-center py-2 border-bottom"><span>${escapeHtml(pipelineT("Arrived / Available"))}</span><strong>${(counts.arrived || 0) + (counts.available || 0)}</strong></div>
      <div class="pt-2">
        <div class="small text-uppercase text-muted fw-semibold mb-2">${escapeHtml(
            pipelineT("Most Loaded"),
        )}</div>
        ${
            hottest.length
                ? hottest
                      .map(
                          (container) => `<div class="mb-1">${escapeHtml(container.code || pipelineT("Container #{id}", { id: container.id }))} <span class="text-muted">${typeof formatDisplayPercent === "function" ? formatDisplayPercent(parseFloat(container.fill_pct_cbm || 0), 1) : parseFloat(container.fill_pct_cbm || 0).toFixed(1)}%</span></div>`,
                      )
                      .join("")
                : `<div class="text-muted">${escapeHtml(
                      pipelineT("No containers above 85% fill."),
                  )}</div>`
        }
      </div>`;
}

async function loadPipeline() {
    try {
        const stageRequests = PIPELINE_STAGE_CONFIG.map((stage) =>
            fetchPipelineStage(stage),
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
        renderMetric("pipeAwaitConfirm", stats.customer_feedback_pending ?? 0);
        renderMetric("pipeFinalized", stats.finalized ?? 0);
        renderMetric("pipelineStaleConfirm", stats.stale_customer_feedback ?? 0);
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
                          : stage.key === "customer_feedback"
                            ? stats.customer_feedback_pending ?? data.length
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
