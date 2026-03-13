/**
 * Dashboard - load actionable counts, my tasks, stale-order alerts
 */
document.addEventListener("DOMContentLoaded", loadDashboardStats);

async function loadDashboardStats() {
    try {
        const res = await api("GET", "/dashboard/stats");
        const s = res.data || {};
        const el = (id) => document.getElementById(id);

        if (el("statPendingReceiving"))
            el("statPendingReceiving").textContent = s.pending_receiving ?? 0;
        if (el("statAwaitingConfirm"))
            el("statAwaitingConfirm").textContent =
                s.awaiting_confirmation ?? 0;
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
        if ((s.stale_awaiting_confirmation ?? 0) > 0)
            msgs.push(
                `${s.stale_awaiting_confirmation} order${s.stale_awaiting_confirmation > 1 ? "s" : ""} awaiting customer confirmation for more than ${days} day${days > 1 ? "s" : ""}`,
            );
        if ((s.stale_overdue ?? 0) > 0)
            msgs.push(
                `${s.stale_overdue} order${s.stale_overdue > 1 ? "s" : ""} past expected ready date`,
            );
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
