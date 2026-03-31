/**
 * Dashboard - load actionable counts, my tasks, stale-order alerts
 */
document.addEventListener("DOMContentLoaded", loadDashboardStats);

function buildStaleFeedbackMessage(count, days) {
    if (typeof uiLocale === "function" && uiLocale() === "zh-CN") {
        return `${count} 个订单等待客户反馈已超过 ${days} 天`;
    }
    return `${count} order${count > 1 ? "s" : ""} still waiting for customer feedback for more than ${days} day${days > 1 ? "s" : ""}`;
}

function buildStaleOverdueMessage(count) {
    if (typeof uiLocale === "function" && uiLocale() === "zh-CN") {
        return `${count} 个订单已超过预计完成日期`;
    }
    return `${count} order${count > 1 ? "s" : ""} past expected ready date`;
}

async function loadDashboardStats() {
    try {
        const res = await api("GET", "/dashboard/stats");
        const s = res.data || {};
        const el = (id) => document.getElementById(id);

        if (el("statPendingReceiving"))
            el("statPendingReceiving").textContent = s.pending_receiving ?? 0;
        if (el("statAwaitingConfirm"))
            el("statAwaitingConfirm").textContent =
                s.customer_feedback_pending ?? 0;
        if (el("statReadyConsolidate"))
            el("statReadyConsolidate").textContent =
                s.ready_for_consolidation ?? 0;
        if (el("statUnreadNotif"))
            el("statUnreadNotif").textContent = s.unread_notifications ?? 0;

        // Stale-order alert
        const staleAlert = el("staleAlert");
        const staleText = el("staleAlertText");
        const msgs = [];
        const days = s.stale_threshold_days ?? 3;
        if ((s.stale_customer_feedback ?? 0) > 0)
            msgs.push(buildStaleFeedbackMessage(s.stale_customer_feedback, days));
        if ((s.stale_overdue ?? 0) > 0)
            msgs.push(buildStaleOverdueMessage(s.stale_overdue));
        if (staleAlert && staleText && msgs.length > 0) {
            staleText.textContent = msgs.join(" · ");
            staleAlert.classList.remove("d-none");
        }

        // My tasks
        const tasks = s.my_tasks || [];
        const container = el("myTasksContainer");
        const card = el("myTasksCard");
        if (container && tasks.length > 0) {
            card?.classList.remove("d-none");
            container.innerHTML = tasks
                .map(
                    (t) =>
                        `<div class="col-6 col-md-4 col-lg-3">
          <a href="${escapeHtml(t.url)}" class="btn btn-outline-primary btn-sm w-100 text-start d-flex justify-content-between align-items-center">
            <span>${escapeHtml(t.label)}</span>
            <span class="badge bg-primary">${t.count}</span>
          </a>
        </div>`,
                )
                .join("");
        } else if (card) {
            card.classList.add("d-none");
        }
    } catch (_) {}
}
