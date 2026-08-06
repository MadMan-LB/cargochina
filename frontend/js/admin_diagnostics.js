document.addEventListener("DOMContentLoaded", () => {
    loadConfigHealth();
    loadBalancesDeploymentHealth();
    loadDeliveryLog();
    document
        .getElementById("excelImageOrderId")
        ?.addEventListener("keydown", (event) => {
            if (event.key === "Enter") loadExcelImageHealth();
        });
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

function diagnosticBadge(ok, label) {
    return `<span class="badge ${ok ? "bg-success" : "bg-danger"}">${escapeHtml(label)}: ${ok ? "OK" : "Check"}</span>`;
}

function diagnosticRoleAccessBadge(role, ok, expectedByDefault) {
    if (ok) {
        return `<span class="badge bg-success">${escapeHtml(role)}: OK</span>`;
    }
    if (!expectedByDefault) {
        return `<span class="badge bg-secondary">${escapeHtml(role)}: No default access</span>`;
    }
    return `<span class="badge bg-danger">${escapeHtml(role)}: Check</span>`;
}

function renderKeyValueTable(rows) {
    return `<div class="table-responsive"><table class="table table-sm mb-0 align-middle"><tbody>${rows
        .map(
            ([key, value]) =>
                `<tr><th class="text-muted" style="width:220px">${escapeHtml(key)}</th><td>${value}</td></tr>`,
        )
        .join("")}</tbody></table></div>`;
}

async function loadBalancesDeploymentHealth() {
    const target = document.getElementById("balancesDeploymentHealth");
    if (!target) return;
    target.innerHTML = '<span class="badge bg-secondary">Loading...</span>';
    try {
        const res = await api("GET", "/diagnostics/balances-deployment");
        const d = res.data || {};
        const requiredColumns = d.balance_transaction_columns || {};
        const missingColumns = Object.entries(requiredColumns)
            .filter(([, ok]) => !ok)
            .map(([name]) => name);
        const missingMigrations = (d.migrations || [])
            .filter((m) => !m.applied)
            .map((m) => m.name);
        const roles = d.role_balance_access || {};
        const expectedRoles = d.role_balance_access_expected_by_default || {};
        const roleMarkup = Object.entries(roles)
            .map(([role, ok]) => diagnosticRoleAccessBadge(role, ok, !!expectedRoles[role]))
            .join(" ");
        const migrationMarkup = (d.migrations || [])
            .map((m) => diagnosticBadge(!!m.applied, m.name))
            .join(" ");
        const tableMarkup = Object.entries(d.tables || {})
            .map(([table, ok]) => diagnosticBadge(!!ok, table))
            .join(" ");
        const rowCounts = Object.entries(d.row_counts || {})
            .map(([table, count]) => `${escapeHtml(table)}: ${count ?? "-"}`)
            .join(" · ");
        const rows = [
            [
                "Current user roles",
                escapeHtml((d.current_user_roles || []).join(", ") || "-"),
            ],
            [
                "Current user can access balances",
                diagnosticBadge(!!d.current_user_can_access_balances, "balances"),
            ],
            ["Registry / route", diagnosticBadge(!!d.registry_has_balances, "registry") + " " + diagnosticBadge(!!d.script_map_has_balances, "script map")],
            ["Role access", roleMarkup || "-"],
            ["Tables", tableMarkup || "-"],
            [
                "Required columns",
                missingColumns.length
                    ? `<span class="text-danger">${escapeHtml(missingColumns.join(", "))}</span>`
                    : '<span class="text-success">All present</span>',
            ],
            [
                "Transaction types",
                diagnosticBadge(!!d.deposit_transaction_type_allowed, "deposit allowed") +
                    " " +
                    diagnosticBadge(!!d.invoice_transaction_type_allowed, "invoice allowed"),
            ],
            [
                "Migrations",
                missingMigrations.length
                    ? migrationMarkup
                    : migrationMarkup || '<span class="text-success">All tracked</span>',
            ],
            ["Row counts", escapeHtml(rowCounts || "-")],
            [
                "Asset mtimes",
                escapeHtml(
                    Object.entries(d.asset_versions || {})
                        .map(([name, ts]) => `${name}: ${ts || "-"}`)
                        .join(" · "),
                ),
            ],
            [
                "Source fingerprints",
                escapeHtml(
                    Object.entries(d.source_fingerprints || {})
                        .map(([name, value]) => `${name}: ${value ?? "-"}`)
                        .join(" · "),
                ),
            ],
        ];
        target.innerHTML = renderKeyValueTable(rows);
    } catch (e) {
        target.innerHTML = `<span class="badge bg-danger">Error: ${escapeHtml(e.message)}</span>`;
    }
}

async function loadExcelImageHealth() {
    const orderId = Number(
        document.getElementById("excelImageOrderId")?.value || 0,
    );
    const target = document.getElementById("excelImageHealth");
    const button = document.getElementById("excelImageHealthBtn");
    if (!target || !Number.isInteger(orderId) || orderId < 1) {
        if (target)
            target.innerHTML =
                '<span class="text-danger">Enter a valid order ID.</span>';
        return;
    }

    target.innerHTML = '<span class="badge bg-secondary">Checking...</span>';
    if (button) button.disabled = true;
    try {
        const res = await api("GET", `/diagnostics/excel-images/${orderId}`);
        const d = res.data || {};
        const summary = d.summary || {};
        const environment = d.environment || {};
        const itemRows = (d.items || [])
            .map((item) => {
                const reasons = Object.entries(item.reason_counts || {})
                    .map(([reason, count]) => `${reason}: ${count}`)
                    .join(", ");
                const fallbacks = Object.entries(item.runtime_fallback_counts || {})
                    .map(([reason, count]) => `${reason}: ${count}`)
                    .join(", ");
                return `<tr>
                    <td>${escapeHtml(String(item.order_item_id || "-"))}</td>
                    <td>${escapeHtml(item.item_no || "-")}</td>
                    <td>${escapeHtml(String(item.product_id || "-"))}</td>
                    <td>${diagnosticBadge(item.status === "ready", item.status === "ready" ? "Ready" : "Unavailable")}</td>
                    <td>${escapeHtml(String(item.provided_candidate_count || 0))}</td>
                    <td>${escapeHtml(String(item.canonical_candidate_count || 0))}</td>
                    <td>${escapeHtml(String(item.source_usable_candidate_count || 0))}</td>
                    <td>${escapeHtml(String(item.usable_candidate_count || 0))}</td>
                    <td>${escapeHtml(reasons || "-")}</td>
                    <td>${escapeHtml(fallbacks || "-")}</td>
                </tr>`;
            })
            .join("");
        const environmentMarkup = [
            diagnosticBadge(!!environment.gd_loaded, "GD"),
            diagnosticBadge(!!environment.zip_loaded, "ZIP"),
            diagnosticBadge(!!environment.fileinfo_available, "Fileinfo"),
            diagnosticBadge(
                !!environment.memory_drawing_available,
                "memory fallback",
            ),
            diagnosticBadge(
                !!environment.upload_directory_exists,
                "uploads exists",
            ),
            diagnosticBadge(
                !!environment.upload_directory_readable,
                "uploads readable",
            ),
            diagnosticBadge(
                !!environment.temporary_directory_writable,
                "temp writable",
            ),
        ].join(" ");
        const fingerprints = Object.entries(d.source_fingerprints || {})
            .map(([name, value]) => `${name}: ${value || "missing"}`)
            .join(" · ");
        target.innerHTML = `
            <div class="d-flex flex-wrap gap-2 mb-2">
                <span class="badge bg-primary">Pipeline ${escapeHtml(environment.pipeline_version || "-")}</span>
                <span class="badge bg-secondary">PHP ${escapeHtml(environment.php_version || "-")}</span>
                <span class="badge ${Number(summary.items_without_usable_images || 0) === 0 ? "bg-success" : "bg-danger"}">${escapeHtml(String(summary.items_with_usable_images || 0))} / ${escapeHtml(String(summary.item_count || 0))} item(s) ready</span>
                <span class="badge ${environment.opcache_enabled ? "bg-warning text-dark" : "bg-secondary"}">OPcache ${environment.opcache_enabled ? "enabled" : "disabled"}</span>
            </div>
            <div class="mb-2">${environmentMarkup}</div>
            <div class="table-responsive mb-2"><table class="table table-sm align-middle mb-0">
                <thead><tr><th>Item ID</th><th>Item No</th><th>Product ID</th><th>Status</th><th>Payload</th><th>Canonical</th><th>Source</th><th>Embeddable</th><th>Failures</th><th>Runtime fallback</th></tr></thead>
                <tbody>${itemRows || '<tr><td colspan="10" class="text-muted">No order items.</td></tr>'}</tbody>
            </table></div>
            <div class="text-muted text-break"><strong>Source fingerprints:</strong> ${escapeHtml(fingerprints || "-")}</div>
            <div class="text-muted"><strong>OPcache:</strong> validate timestamps ${environment.opcache_validate_timestamps ? "on" : "off"}, revalidate every ${escapeHtml(String(environment.opcache_revalidate_freq ?? "-"))} second(s).</div>`;
    } catch (e) {
        target.innerHTML = `<span class="text-danger">${escapeHtml(e.message || "Could not check Excel image health.")}</span>`;
    } finally {
        if (button) button.disabled = false;
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
