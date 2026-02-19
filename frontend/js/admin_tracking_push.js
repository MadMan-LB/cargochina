document.addEventListener("DOMContentLoaded", loadPushLog);

async function loadPushLog() {
    try {
        const failedOnly = document.getElementById("filterFailed").checked;
        let path = "/tracking-push-log?entity_type=shipment_draft";
        if (failedOnly) path += "&failed_only=1";
        const res = await api("GET", path);
        const rows = res.data || [];
        const tbody = document.getElementById("pushLogBody");
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
        <tr>
          <td>${r.id}</td>
          <td>#${r.entity_id}</td>
          <td><span class="badge ${r.status === "success" ? "bg-success" : r.status === "failed" ? "bg-danger" : r.status === "dry_run" ? "bg-info" : "bg-secondary"}">${r.status}</span></td>
          <td>${r.response_code ?? "-"}</td>
          <td>${r.attempt_count ?? 0}</td>
          <td><small class="text-danger">${escapeHtml((r.last_error || "").substring(0, 80))}${(r.last_error || "").length > 80 ? "…" : ""}</small></td>
          <td>${r.updated_at || r.created_at || "-"}</td>
          <td>${r.status === "failed" ? `<button class="btn btn-sm btn-warning" onclick="retryPush(${r.entity_id})">Retry</button>` : ""} ${r.last_error ? `<button class="btn btn-sm btn-outline-secondary" onclick="showError(this)" data-error="${escapeHtml((r.last_error || "").replace(/"/g, "&quot;"))}">View</button>` : ""}</td>
        </tr>`,
                )
                .join("") ||
            "<tr><td colspan='8' class='text-muted'>No entries</td></tr>";
    } catch (e) {
        document.getElementById("pushLogBody").innerHTML =
            "<tr><td colspan='8' class='text-danger'>" +
            escapeHtml(e.message) +
            "</td></tr>";
    }
}

function showError(btn) {
    document.getElementById("errorModalBody").textContent =
        btn.getAttribute("data-error") || "";
    new bootstrap.Modal(document.getElementById("errorModal")).show();
}

async function retryPush(draftId) {
    try {
        await api("POST", "/shipment-drafts/" + draftId + "/push", {});
        showToast("Push retried");
        loadPushLog();
    } catch (e) {
        showToast(e.message, "danger");
    }
}
