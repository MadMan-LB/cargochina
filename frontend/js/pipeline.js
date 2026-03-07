/**
 * Pipeline view - end-to-end order stages with counts and links
 */
document.addEventListener("DOMContentLoaded", loadPipeline);

async function loadPipeline() {
    try {
        const res = await api("GET", "/dashboard/stats");
        const s = res.data || {};
        const el = (id) => document.getElementById(id);
        if (el("pipeDraft")) el("pipeDraft").textContent = s.draft ?? 0;
        if (el("pipeSubmitted"))
            el("pipeSubmitted").textContent = s.submitted ?? 0;
        if (el("pipeToReceive"))
            el("pipeToReceive").textContent = s.pending_receiving ?? 0;
        if (el("pipeAwaitConfirm"))
            el("pipeAwaitConfirm").textContent = s.awaiting_confirmation ?? 0;
        if (el("pipeReady"))
            el("pipeReady").textContent = s.ready_for_consolidation ?? 0;
        if (el("pipeFinalized"))
            el("pipeFinalized").textContent = s.finalized ?? 0;

        const stages = [
            {
                label: "Draft",
                count: s.draft ?? 0,
                url: "/cargochina/orders.php?status=Draft",
            },
            {
                label: "Submitted",
                count: s.submitted ?? 0,
                url: "/cargochina/orders.php?status=Submitted",
            },
            {
                label: "To receive",
                count: s.pending_receiving ?? 0,
                url: "/cargochina/receiving.php",
            },
            {
                label: "Awaiting confirmation",
                count: s.awaiting_confirmation ?? 0,
                url: "/cargochina/orders.php?status=AwaitingCustomerConfirmation",
            },
            {
                label: "Ready for consolidation",
                count: s.ready_for_consolidation ?? 0,
                url: "/cargochina/consolidation.php",
            },
            {
                label: "In shipment draft",
                count: s.in_shipment_draft ?? 0,
                url: "/cargochina/consolidation.php",
            },
            {
                label: "Assigned to container",
                count: s.assigned_to_container ?? 0,
                url: "/cargochina/consolidation.php",
            },
            {
                label: "Finalized",
                count: s.finalized ?? 0,
                url: "/cargochina/consolidation.php",
            },
        ];
        const tbody = document.getElementById("pipelineTableBody");
        if (tbody) {
            tbody.innerHTML = stages
                .map(
                    (st) =>
                        `<tr><td>${escapeHtml(st.label)}</td><td>${st.count}</td><td><a href="${st.url}">View</a></td></tr>`,
                )
                .join("");
        }
    } catch (_) {}
}
