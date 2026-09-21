document.addEventListener("DOMContentLoaded", () => loadPushLog());
let pushLogOffset=0;
let pushLogLoadVersion=0;
function changePushLogPage(direction){pushLogOffset=Math.max(0,pushLogOffset+direction*50);loadPushLog(false);}

async function loadPushLog(reset=true) {
    if(reset)pushLogOffset=0;
    const version=++pushLogLoadVersion;
    try {
        const failedOnly = document.getElementById("filterFailed").checked;
        let path = `/tracking-push-log?entity_type=shipment_draft&limit=50&offset=${pushLogOffset}`;
        const draftId=document.getElementById('filterDraft')?.value?.trim();if(draftId)path+='&entity_id='+encodeURIComponent(draftId);
        if (failedOnly) path += "&failed_only=1";
        const res = await api("GET", path);
        if(version!==pushLogLoadVersion)return;
        const previous=document.getElementById('pushLogPrevious'),next=document.getElementById('pushLogNext'),label=document.getElementById('pushLogPage');
        if(previous)previous.disabled=pushLogOffset===0;if(next)next.disabled=!res.meta?.has_more;if(label)label.textContent=`Page ${Math.floor(pushLogOffset/50)+1} · ${res.meta?.total??0} entries`;
        const rows = res.data || [];
        const tbody = document.getElementById("pushLogBody");
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
        <tr>
          <td>${r.id}</td>
          <td>#${r.entity_id}</td>
          <td><span class="badge ${r.status === "success" ? "bg-success" : r.status === "failed" ? "bg-danger" : r.status === "dry_run" ? "bg-info" : "bg-secondary"}">${escapeHtml(typeof t === "function" ? t(r.status) : r.status)}</span></td>
          <td>${r.response_code ?? "-"}</td>
          <td>${r.attempt_count ?? 0}</td>
          <td><small class="text-danger">${escapeHtml((r.last_error || "").substring(0, 80))}${(r.last_error || "").length > 80 ? "…" : ""}</small></td>
          <td>${r.updated_at || r.created_at || "-"}</td>
          <td>${["pending", "failed", "disabled", "dry_run"].includes(r.status) ? `<button class="btn btn-sm btn-warning" onclick="retryPush(${r.entity_id})">Retry</button>` : ""} ${r.last_error ? `<button class="btn btn-sm btn-outline-secondary" onclick="showError(this)" data-error="${escapeHtml((r.last_error || "").replace(/"/g, "&quot;"))}">View</button>` : ""}</td>
        </tr>`,
                )
                .join("") ||
            "<tr><td colspan='8' class='text-muted'>No entries</td></tr>";
    } catch (e) {
        if(version!==pushLogLoadVersion)return;
        const next=document.getElementById('pushLogNext');if(next)next.disabled=true;
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
        const res = await api("POST", "/shipment-drafts/" + draftId + "/push", {});
        showToast(res.data?.message || "Tracking push was not confirmed", res.data?.success === true ? "success" : "warning");
        loadPushLog();
    } catch (e) {
        showToast(e.message, "danger");
        loadPushLog();
    }
}
