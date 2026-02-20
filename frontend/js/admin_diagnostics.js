document.addEventListener("DOMContentLoaded", () => {
    loadConfigHealth();
    loadDeliveryLog();
});

async function loadConfigHealth() {
    try {
        const res = await api("GET", "/diagnostics/config-health");
        const d = res.data || {};
        const items = [
            ["email_configured", "Email", d.email_configured],
            ["whatsapp_configured", "WhatsApp", d.whatsapp_configured],
            [
                "item_level_enabled",
                "Item-level receiving",
                d.item_level_enabled,
            ],
            ["retry_configured", "Retry policy", d.retry_configured],
        ];
        document.getElementById("configHealth").innerHTML = items
            .map(
                ([k, label, ok]) =>
                    `<span class="badge ${ok ? "bg-success" : "bg-warning"}" title="${k}">${label}: ${ok ? "OK" : "Not configured"}</span>`,
            )
            .join("");
    } catch (e) {
        document.getElementById("configHealth").innerHTML =
            `<span class="badge bg-danger">Error: ${escapeHtml(e.message)}</span>`;
    }
}

async function loadDeliveryLog() {
    const status = document.getElementById("filterStatus").value;
    const channel = document.getElementById("filterChannel").value;
    const dateFrom = document.getElementById("filterDateFrom").value;
    const dateTo = document.getElementById("filterDateTo").value;
    let path = "/diagnostics/notification-delivery-log?limit=100";
    if (status) path += "&status=" + encodeURIComponent(status);
    if (channel) path += "&channel=" + encodeURIComponent(channel);
    if (dateFrom) path += "&date_from=" + encodeURIComponent(dateFrom);
    if (dateTo) path += "&date_to=" + encodeURIComponent(dateTo);
    try {
        const res = await api("GET", path);
        const rows = res.data || [];
        const tbody = document.getElementById("deliveryLogBody");
        tbody.innerHTML = rows
            .map(
                (r) =>
                    `<tr>
          <td>${r.id}</td>
          <td>#${r.notification_id}</td>
          <td>${escapeHtml(r.channel)}</td>
          <td>${escapeHtml(r.event_type || "-")}</td>
          <td><span class="badge ${r.status === "sent" ? "bg-success" : "bg-danger"}">${escapeHtml(r.status)}</span></td>
          <td>${r.attempts}</td>
          <td class="small text-break" style="max-width:200px">${escapeHtml((r.last_error || "").substring(0, 80))}${(r.last_error || "").length > 80 ? "…" : ""}</td>
          <td>${escapeHtml(r.created_at || "")}</td>
          <td>${r.status === "failed" && ["email", "whatsapp"].includes(r.channel) ? `<button type="button" class="btn btn-sm btn-outline-primary" onclick="retryDelivery(${r.id})">Retry</button>` : "-"}</td>
        </tr>`,
            )
            .join("");
        if (rows.length === 0)
            tbody.innerHTML =
                "<tr><td colspan='9' class='text-muted'>No rows</td></tr>";
    } catch (e) {
        document.getElementById("deliveryLogBody").innerHTML =
            `<tr><td colspan='9' class='text-danger'>${escapeHtml(e.message)}</td></tr>`;
    }
}

async function retryDelivery(logId) {
    try {
        const res = await api(
            "POST",
            "/diagnostics/retry-delivery/" + logId,
            {},
        );
        const d = res.data || {};
        if (d.success) {
            showToast("Retry succeeded");
        } else {
            showToast(d.error || "Retry failed", "danger");
        }
        loadDeliveryLog();
        loadConfigHealth();
    } catch (e) {
        showToast(e.message || "Retry failed", "danger");
    }
}
