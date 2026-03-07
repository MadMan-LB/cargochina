/**
 * Audit log viewer - filter and paginate
 */
let offset = 0;
const limit = 50;

async function loadAuditLog(reset = true) {
    if (reset) offset = 0;
    const params = new URLSearchParams();
    params.set("limit", limit);
    params.set("offset", offset);
    const entityType = document.getElementById("filterEntityType")?.value;
    const entityId = document.getElementById("filterEntityId")?.value?.trim();
    const userId = document.getElementById("filterUserId")?.value?.trim();
    const dateFrom = document.getElementById("filterDateFrom")?.value;
    const dateTo = document.getElementById("filterDateTo")?.value;
    if (entityType) params.set("entity_type", entityType);
    if (entityId) params.set("entity_id", entityId);
    if (userId) params.set("user_id", userId);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);

    try {
        const res = await api("GET", "/audit-log?" + params.toString());
        const rows = res.data || [];
        const tbody = document.getElementById("auditBody");
        const emptyEl = document.getElementById("auditEmpty");
        const loadMoreBtn = document.getElementById("loadMoreBtn");

        if (reset) {
            tbody.innerHTML = "";
        }

        rows.forEach((r) => {
            const tr = document.createElement("tr");
            const entityLink =
                r.entity_type === "order"
                    ? `<a href="/cargochina/orders.php" onclick="event.stopPropagation()">#${r.entity_id}</a>`
                    : r.entity_type === "shipment_draft"
                      ? `<a href="/cargochina/consolidation.php" onclick="event.stopPropagation()">Draft #${r.entity_id}</a>`
                      : `${r.entity_type} #${r.entity_id}`;
            let details = "";
            if (r.new_value) {
                try {
                    const v = typeof r.new_value === "string" ? JSON.parse(r.new_value) : r.new_value;
                    details = Object.keys(v || {})
                        .slice(0, 3)
                        .map((k) => `${k}: ${String(v[k]).substring(0, 30)}`)
                        .join("; ");
                } catch (_) {
                    details = String(r.new_value).substring(0, 80);
                }
            }
            tr.innerHTML = `
        <td><small>${escapeHtml((r.created_at || "").replace(" ", " ")}</small></td>
        <td>${escapeHtml(r.user_name || "—")} ${r.user_id ? `(#${r.user_id})` : ""}</td>
        <td>${entityLink}</td>
        <td><span class="badge bg-secondary">${escapeHtml(r.action)}</span></td>
        <td><small class="text-muted">${escapeHtml(details || "—")}</small></td>
      `;
            tbody.appendChild(tr);
        });

        emptyEl?.classList.toggle("d-none", tbody.children.length > 0 || !reset);
        loadMoreBtn.style.display = res.has_more ? "inline-block" : "none";
        offset += rows.length;
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function loadMore() {
    loadAuditLog(false);
}

document.addEventListener("DOMContentLoaded", () => loadAuditLog());
