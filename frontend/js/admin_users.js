let allRoles = [];
let allDepartments = [];
let allUsers = [];
let sidebarAccessRegistry = {};
let sidebarAccessSections = {};
let sidebarAccessAssignable = {};
let sidebarAccessDefaults = {};
let sidebarAccessSettings = {};
let sidebarAccessRoles = [];
let sidebarAccessCollapseState = {};
let sidebarAccessSectionCollapseState = {};

document.addEventListener("DOMContentLoaded", async () => {
    setupResetPwToggle();
    await loadRolesAndDepartments();
    await Promise.all([loadUsers(), loadSidebarAccessConfig()]);
    const createBtn = document.getElementById("createUserBtn");
    if (createBtn) createBtn.addEventListener("click", openCreateUserModal);
    const saveSidebarBtn = document.getElementById("sidebarAccessSaveBtn");
    if (saveSidebarBtn) {
        saveSidebarBtn.addEventListener("click", saveSidebarAccessSettings);
    }
    const resetSidebarBtn = document.getElementById("sidebarAccessDefaultBtn");
    if (resetSidebarBtn) {
        resetSidebarBtn.addEventListener("click", resetAllSidebarAccessToDefault);
    }
});

function openCreateUserModal() {
    document.getElementById("createEmail").value = "";
    document.getElementById("createFullName").value = "";
    document.getElementById("createPassword").value = "";

    const rolesDiv = document.getElementById("createRoles");
    rolesDiv.innerHTML = allRoles
        .map((r) => {
            const code = r.code || r;
            const label = r.name || r.code || r;
            const safeId =
                "createRole_" + String(code).replace(/[^a-zA-Z0-9_-]/g, "_");
            return `<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="${safeId}" value="${escapeHtml(code)}"><label class="form-check-label" for="${safeId}">${escapeHtml(label)}</label></div>`;
        })
        .join("");

    const deptSel = document.getElementById("createDepartments");
    deptSel.innerHTML = allDepartments
        .map((d) => `<option value="${d.id}">${escapeHtml(d.name)}</option>`)
        .join("");
    new bootstrap.Modal(document.getElementById("userCreateModal")).show();
}

async function createUser() {
    const email = document.getElementById("createEmail").value.trim();
    const fullName = document.getElementById("createFullName").value.trim();
    const password = document.getElementById("createPassword").value;
    const roles = allRoles
        .filter(
            (r) =>
                document.getElementById(
                    "createRole_" +
                        (r.code || r).replace(/[^a-zA-Z0-9_-]/g, "_"),
                )?.checked,
        )
        .map((r) => r.code || r);
    const deptSel = document.getElementById("createDepartments");
    const departmentIds = Array.from(deptSel.selectedOptions).map(
        (o) => o.value,
    );

    if (!email) {
        showToast("Email or username is required", "danger");
        return;
    }
    if (!fullName) {
        showToast("Full name is required", "danger");
        return;
    }
    if (!password || password.length < 6) {
        showToast("Password must be at least 6 characters", "danger");
        return;
    }
    if (!roles.length) {
        showToast("At least one role is required", "danger");
        return;
    }

    const btn = document.getElementById("saveCreateUserBtn");
    try {
        setLoading(btn, true);
        await api("POST", "/users", {
            email,
            full_name: fullName,
            password,
            roles,
            department_ids: departmentIds,
        });
        showToast("User created");
        bootstrap.Modal.getInstance(
            document.getElementById("userCreateModal"),
        ).hide();
        loadUsers();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

async function loadRolesAndDepartments() {
    try {
        const [rolesRes, deptRes] = await Promise.all([
            api("GET", "/roles"),
            api("GET", "/departments"),
        ]);
        allRoles = rolesRes.data || [];
        allDepartments = deptRes.data || [];
    } catch (_) {
        allRoles = [];
        allDepartments = [];
    }
}

async function loadUsers() {
    try {
        const res = await api("GET", "/users");
        const rows = res.data || [];
        allUsers = rows;
        document.getElementById("usersBody").innerHTML =
            rows
                .map((u) => {
                    const deptNames =
                        (u.departments || [])
                            .map((d) => d.name || d.code)
                            .join(", ") || "—";
                    return `<tr>
                            <td>${u.id}</td>
                            <td>${escapeHtml(u.email)}</td>
                            <td>${escapeHtml(u.full_name)}</td>
                            <td>${(u.roles || []).join(", ")}</td>
                            <td>${escapeHtml(deptNames)}</td>
                            <td>${u.is_active ? "Yes" : "No"}</td>
                            <td><button class="btn btn-sm btn-outline-primary me-1" onclick="editUser(${u.id})">Edit</button><button class="btn btn-sm btn-outline-secondary" onclick="showUserActivity(${u.id})">Activity</button></td>
                        </tr>`;
                })
                .join("") ||
            '<tr><td colspan="7" class="text-muted">No users</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function editUser(id) {
    try {
        const res = await api("GET", "/users/" + id);
        const u = res.data;
        document.getElementById("editUserId").value = u.id;
        document.getElementById("editActive").checked = !!u.is_active;

        const rolesDiv = document.getElementById("editRoles");
        rolesDiv.innerHTML = allRoles
            .map((r) => {
                const code = r.code || r;
                const label = r.name || r.code || r;
                const safeId =
                    "role_" + String(code).replace(/[^a-zA-Z0-9_-]/g, "_");
                return `<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="${safeId}" value="${escapeHtml(code)}"><label class="form-check-label" for="${safeId}">${escapeHtml(label)}</label></div>`;
            })
            .join("");
        (u.roles || []).forEach((code) => {
            const cb = document.getElementById("role_" + code);
            if (cb) cb.checked = true;
        });

        const deptSel = document.getElementById("editDepartments");
        deptSel.innerHTML = allDepartments
            .map(
                (d) => `<option value="${d.id}">${escapeHtml(d.name)}</option>`,
            )
            .join("");
        Array.from(deptSel.options).forEach((opt) => {
            opt.selected = (u.departments || []).some(
                (ud) => ud.id == opt.value,
            );
        });

        document.getElementById("resetPassword").value = "";
        document.getElementById("resetPassword").type = "password";
        const chk = document.getElementById("toggleResetPw");
        if (chk) chk.checked = false;
        document.getElementById("resetPasswordResult").classList.add("d-none");
        document.getElementById("displayNewPassword").textContent = "";
        new bootstrap.Modal(document.getElementById("userEditModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function setupResetPwToggle() {
    const inp = document.getElementById("resetPassword");
    const chk = document.getElementById("toggleResetPw");
    if (!inp || !chk) return;
    chk.addEventListener("change", function () {
        inp.type = this.checked ? "text" : "password";
    });
}

async function resetPassword() {
    const id = document.getElementById("editUserId").value;
    const password = document.getElementById("resetPassword").value;
    if (!password || password.length < 6) {
        showToast("Password must be at least 6 characters", "danger");
        return;
    }
    const btn = document.getElementById("resetPwBtn");
    try {
        setLoading(btn, true);
        const res = await api("POST", "/users/" + id + "/reset-password", {
            password,
        });
        const data = res.data || {};
        document.getElementById("resetPassword").value = "";
        document.getElementById("displayNewPassword").textContent =
            data.new_password || "";
        document
            .getElementById("resetPasswordResult")
            .classList.remove("d-none");
        showToast("Password reset. Copy and share with the user.");
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

function copyNewPassword() {
    const el = document.getElementById("displayNewPassword");
    const text = el?.textContent || "";
    if (!text) return;
    navigator.clipboard
        ?.writeText(text)
        .then(() => showToast("Copied to clipboard"))
        .catch(() => showToast("Copy failed", "danger"));
}

async function saveUser() {
    const id = document.getElementById("editUserId").value;
    const roles = allRoles
        .filter((r) => {
            const code = r.code || r;
            return document.getElementById("role_" + code)?.checked;
        })
        .map((r) => r.code || r);
    const deptSel = document.getElementById("editDepartments");
    const departmentIds = Array.from(deptSel.selectedOptions).map(
        (o) => o.value,
    );
    const isActive = document.getElementById("editActive").checked ? 1 : 0;

    const btn = document.getElementById("saveUserBtn");
    try {
        setLoading(btn, true);
        await api("PUT", "/users/" + id, {
            roles,
            department_ids: departmentIds,
            is_active: isActive,
        });
        showToast("User updated");
        bootstrap.Modal.getInstance(
            document.getElementById("userEditModal"),
        ).hide();
        loadUsers();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

let activityUserId = null;
let activityOffset = 0;
const activityLimit = 50;

function showUserActivity(userId) {
    activityUserId = userId;
    const u = allUsers.find((x) => x.id == userId);
    const fallbackUser = typeof t === "function" ? t("User") : "User";
    const name = u ? u.full_name || u.email || fallbackUser : fallbackUser;
    document.getElementById("activityPanelTitle").textContent =
        `${typeof t === "function" ? t("Activity") : "Activity"}: ${name} (#${userId})`;
    const panel = document.getElementById("activityPanel");
    panel.style.display = "block";
    panel.scrollIntoView({ behavior: "smooth", block: "start" });
    document.getElementById("activityEntityType").value = "";
    document.getElementById("activityAction").value = "";
    document.getElementById("activityDateFrom").value = "";
    document.getElementById("activityDateTo").value = "";
    loadUserActivity(true);
}

function hideActivityPanel() {
    document.getElementById("activityPanel").style.display = "none";
    activityUserId = null;
}

function clearActivityFilters() {
    document.getElementById("activityEntityType").value = "";
    document.getElementById("activityAction").value = "";
    document.getElementById("activityDateFrom").value = "";
    document.getElementById("activityDateTo").value = "";
    loadUserActivity(true);
}

function getActivityBadgeClass(action) {
    const map = {
        create: "bg-success",
        update: "bg-info text-dark",
        submit: "bg-primary",
        approve: "bg-primary",
        receive: "bg-warning text-dark",
        confirm: "bg-success",
    };
    return map[action] || "bg-secondary";
}

function getActivityEntityLink(entityType, entityId) {
    const base = "/cargochina";
    const id = entityId;
    switch (entityType) {
        case "order":
            return `<a href="${base}/orders.php" onclick="event.stopPropagation()">#${id}</a>`;
        case "shipment_draft":
            return `<a href="${base}/consolidation.php" onclick="event.stopPropagation()">Draft #${id}</a>`;
        case "expense":
            return `<a href="${base}/expenses.php" onclick="event.stopPropagation()">Expense #${id}</a>`;
        case "procurement_draft":
            return `<a href="${base}/procurement_drafts.php" onclick="event.stopPropagation()">Draft #${id}</a>`;
        case "order_template":
            return `<a href="${base}/orders.php" onclick="event.stopPropagation()">Template #${id}</a>`;
        case "customer_deposit":
            return `<a href="${base}/financials.php" onclick="event.stopPropagation()">Deposit #${id}</a>`;
        case "supplier_interaction":
            return `<a href="${base}/suppliers.php" onclick="event.stopPropagation()">Interaction #${id}</a>`;
        case "customer_portal_token":
            return `Token #${id}`;
        case "design_attachment":
            return `Attachment #${id}`;
        default:
            return `${escapeHtml(entityType || "entity")} #${id}`;
    }
}

async function loadUserActivity(reset = true) {
    if (!activityUserId) return;
    if (reset) activityOffset = 0;
    const params = new URLSearchParams();
    params.set("limit", activityLimit);
    params.set("offset", activityOffset);
    const entityType = document.getElementById("activityEntityType")?.value;
    const action = document.getElementById("activityAction")?.value;
    const dateFrom = document.getElementById("activityDateFrom")?.value;
    const dateTo = document.getElementById("activityDateTo")?.value;
    if (entityType) params.set("entity_type", entityType);
    if (action) params.set("action", action);
    if (dateFrom) params.set("date_from", dateFrom);
    if (dateTo) params.set("date_to", dateTo);

    const loadingEl = document.getElementById("activityLoading");
    const contentEl = document.getElementById("activityContent");
    if (loadingEl && contentEl) {
        loadingEl.classList.remove("d-none");
        contentEl.classList.add("d-none");
    }

    try {
        const res = await api(
            "GET",
            "/users/" + activityUserId + "/activity?" + params.toString(),
        );
        const rows = res.data || [];
        const tbody = document.getElementById("activityBody");
        const emptyEl = document.getElementById("activityEmpty");
        const loadMoreBtn = document.getElementById("activityLoadMoreBtn");

        if (loadingEl && contentEl) {
            loadingEl.classList.add("d-none");
            contentEl.classList.remove("d-none");
        }

        if (reset) tbody.innerHTML = "";

        rows.forEach((r) => {
            const tr = document.createElement("tr");
            const entityLink = getActivityEntityLink(
                r.entity_type,
                r.entity_id,
            );
            let details = "";
            if (r.new_value) {
                try {
                    const v =
                        typeof r.new_value === "string"
                            ? JSON.parse(r.new_value)
                            : r.new_value;
                    details = Object.keys(v || {})
                        .slice(0, 3)
                        .map((k) => `${k}: ${String(v[k]).substring(0, 30)}`)
                        .join("; ");
                } catch (_) {
                    details = String(r.new_value).substring(0, 80);
                }
            }
            const badgeClass = getActivityBadgeClass(r.action);
            const timeStr = (r.created_at || "").replace(" ", " ");
            tr.innerHTML = `
                <td class="text-nowrap"><small class="text-muted">${escapeHtml(timeStr)}</small></td>
                <td>${entityLink}</td>
                <td><span class="badge ${badgeClass}">${escapeHtml(r.action)}</span></td>
                <td><small class="text-muted text-break">${escapeHtml(details || "—")}</small></td>
            `;
            tbody.appendChild(tr);
        });

        emptyEl?.classList.toggle(
            "d-none",
            tbody.children.length > 0 || !reset,
        );
        loadMoreBtn.style.display = res.has_more ? "inline-block" : "none";
        activityOffset += rows.length;
    } catch (e) {
        if (loadingEl && contentEl) {
            loadingEl.classList.add("d-none");
            contentEl.classList.remove("d-none");
        }
        showToast(e.message, "danger");
    }
}

function loadMoreActivity() {
    loadUserActivity(false);
}

async function loadSidebarAccessConfig() {
    const loadingEl = document.getElementById("sidebarAccessLoading");
    const gridEl = document.getElementById("sidebarAccessGrid");

    try {
        const res = await api("GET", "/users/sidebar-access");
        const data = res.data || {};
        sidebarAccessRegistry = data.registry || {};
        sidebarAccessSections = data.sections || {};
        sidebarAccessAssignable = data.assignable || {};
        sidebarAccessDefaults = data.defaults || {};
        sidebarAccessSettings = data.settings || {};
        sidebarAccessRoles = data.roles || [];
        sidebarAccessCollapseState = loadSidebarCollapseState();
        sidebarAccessSectionCollapseState = loadSidebarSectionCollapseState();
        renderSidebarAccessGrid();
        if (loadingEl) loadingEl.classList.add("d-none");
        if (gridEl) gridEl.classList.remove("d-none");
    } catch (e) {
        if (loadingEl) {
            loadingEl.textContent = e.message || "Failed to load sidebar access.";
            loadingEl.classList.remove("text-muted");
            loadingEl.classList.add("text-danger");
        }
        if (gridEl) gridEl.classList.add("d-none");
    }
}

function renderSidebarAccessGrid() {
    const gridEl = document.getElementById("sidebarAccessGrid");
    if (!gridEl) return;

    const roles = [...sidebarAccessRoles].sort((a, b) => {
        if (a.code === "SuperAdmin") return -1;
        if (b.code === "SuperAdmin") return 1;
        return String(a.name || a.code).localeCompare(String(b.name || b.code));
    });

    gridEl.innerHTML = roles
        .map((role) => renderSidebarRoleCard(role))
        .join("");

    gridEl.querySelectorAll(".sidebar-page-toggle").forEach((checkbox) => {
        checkbox.addEventListener("change", () => {
            updateSidebarRoleSummary(checkbox.dataset.roleCode);
        });
    });

    gridEl.querySelectorAll("[data-sidebar-action]").forEach((button) => {
        button.addEventListener("click", () => {
            const roleCode = button.dataset.roleCode;
            const action = button.dataset.sidebarAction;
            if (!roleCode || !action) return;
            if (action === "select-all") {
                setSidebarRoleSelection(roleCode, getSidebarAssignablePages(roleCode));
            } else if (action === "clear") {
                setSidebarRoleSelection(roleCode, []);
            } else if (action === "default") {
                setSidebarRoleSelection(roleCode, getSidebarDefaultPages(roleCode));
            }
        });
    });

    gridEl.querySelectorAll("[data-sidebar-collapse-toggle]").forEach((button) => {
        button.addEventListener("click", () => {
            const roleCode = button.dataset.roleCode;
            if (!roleCode || roleCode === "SuperAdmin") return;
            sidebarAccessCollapseState[roleCode] =
                !sidebarAccessCollapseState[roleCode];
            persistSidebarCollapseState();
            renderSidebarAccessGrid();
        });
    });

    gridEl
        .querySelectorAll("[data-sidebar-section-collapse-toggle]")
        .forEach((button) => {
            button.addEventListener("click", () => {
                const roleCode = button.dataset.roleCode;
                const sectionId = button.dataset.sectionId;
                if (!roleCode || !sectionId) return;
                const key = `${roleCode}:${sectionId}`;
                sidebarAccessSectionCollapseState[key] =
                    !sidebarAccessSectionCollapseState[key];
                persistSidebarSectionCollapseState();
                renderSidebarAccessGrid();
            });
        });

    roles.forEach((role) => updateSidebarRoleSummary(role.code));
}

function renderSidebarRoleCard(role) {
    const roleCode = role.code || "";
    const roleName = role.name || roleCode;
    const assignablePageIds = getSidebarAssignablePages(roleCode);
    const assignablePageSet = new Set(assignablePageIds);
    const allPageIds = Object.keys(sidebarAccessRegistry);
    const selectedPageIds = new Set(getSidebarCurrentPages(roleCode));
    const isSuperAdmin = roleCode === "SuperAdmin";
    const isCollapsed = !isSuperAdmin && !!sidebarAccessCollapseState[roleCode];

    const sectionMarkup = Object.entries(sidebarAccessSections)
        .map(([sectionId, sectionLabel]) => {
            const pageIds = allPageIds.filter(
                (pageId) => sidebarAccessRegistry[pageId]?.section === sectionId,
            );
            if (!pageIds.length) return "";

            const sectionKey = `${roleCode}:${sectionId}`;
            const sectionCollapsed = !isSuperAdmin && !!sidebarAccessSectionCollapseState[sectionKey];

            const tiles = pageIds
                .map((pageId) => {
                    const page = sidebarAccessRegistry[pageId];
                    const isAssignable = isSuperAdmin || assignablePageSet.has(pageId);
                    const checked = selectedPageIds.has(pageId) ? "checked" : "";
                    const disabled = !isAssignable ? "disabled" : "";
                    const lockedBadge =
                        !isAssignable && !isSuperAdmin
                            ? '<span class="sidebar-page-lock-badge">SuperAdmin only</span>'
                            : "";
                    return `
                        <label class="sidebar-page-tile ${checked ? "is-selected" : ""} ${!isAssignable ? "is-locked" : ""}" data-role-page="${escapeHtml(roleCode)}:${escapeHtml(pageId)}">
                            <input type="checkbox" class="form-check-input sidebar-page-toggle" data-role-code="${escapeHtml(roleCode)}" data-page-id="${escapeHtml(pageId)}" ${checked} ${disabled}>
                            <div class="sidebar-page-tile-body">
                                <div class="sidebar-page-icon">${page.icon_svg || ""}</div>
                                <div class="sidebar-page-copy">
                                    <div class="sidebar-page-title-row">
                                        <div class="sidebar-page-title">${escapeHtml(page.title || pageId)}</div>
                                        ${lockedBadge}
                                    </div>
                                    <div class="sidebar-page-description">${escapeHtml(page.description || "")}</div>
                                </div>
                            </div>
                        </label>
                    `;
                })
                .join("");

            return `
                <div class="sidebar-role-section">
                    <div class="sidebar-role-section-header">
                        <div class="sidebar-role-section-label">${escapeHtml(sectionLabel)}</div>
                        ${
                            isSuperAdmin
                                ? ""
                                : `<button type="button" class="btn btn-outline-secondary btn-sm sidebar-section-collapse-btn" data-sidebar-section-collapse-toggle="true" data-role-code="${escapeHtml(roleCode)}" data-section-id="${escapeHtml(sectionId)}">${sectionCollapsed ? "Expand" : "Collapse"}</button>`
                        }
                    </div>
                    <div class="sidebar-page-grid ${sectionCollapsed ? "d-none" : ""}">${tiles}</div>
                </div>
            `;
        })
        .join("");
    const contentMarkup =
        sectionMarkup ||
        '<div class="alert alert-light border small mb-0">No assignable sidebar pages are currently registered for this role.</div>';

    return `
        <div class="col-12 col-xl-6">
            <div class="card h-100 sidebar-role-card" data-role-code="${escapeHtml(roleCode)}">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                        <div>
                            <h6 class="mb-1">${escapeHtml(roleName)}</h6>
                            <div class="small text-muted">${escapeHtml(roleCode)}</div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge text-bg-light border sidebar-role-summary" id="sidebarRoleSummary_${escapeHtml(roleCode)}"></span>
                            ${
                                isSuperAdmin
                                    ? ""
                                    : `<button type="button" class="btn btn-outline-secondary btn-sm sidebar-role-collapse-btn" data-sidebar-collapse-toggle="true" data-role-code="${escapeHtml(roleCode)}" aria-expanded="${isCollapsed ? "false" : "true"}">${isCollapsed ? "Expand" : "Collapse"}</button>`
                            }
                        </div>
                    </div>
                    ${
                        isSuperAdmin
                            ? '<div class="alert alert-primary py-2 small mb-0">Super Admin always sees every page and is not restricted by custom sidebar settings.</div>'
                            : `
                                <div class="sidebar-role-card-content ${isCollapsed ? "d-none" : ""}">
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <button type="button" class="btn btn-outline-primary btn-sm" data-sidebar-action="select-all" data-role-code="${escapeHtml(roleCode)}">Select All</button>
                                        <button type="button" class="btn btn-outline-secondary btn-sm" data-sidebar-action="clear" data-role-code="${escapeHtml(roleCode)}">Clear</button>
                                        <button type="button" class="btn btn-outline-dark btn-sm" data-sidebar-action="default" data-role-code="${escapeHtml(roleCode)}">Reset to Default</button>
                                    </div>
                                    ${contentMarkup}
                                    <div class="small text-muted mt-3">All registered CLMS pages are listed here for this role. Super Admin-only administration pages are shown too, but they stay locked for non-SuperAdmin roles.</div>
                                </div>
                            `
                    }
                </div>
            </div>
        </div>
    `;
}

function getSidebarAssignablePages(roleCode) {
    return Array.isArray(sidebarAccessAssignable[roleCode])
        ? [...sidebarAccessAssignable[roleCode]]
        : [];
}

function getSidebarDefaultPages(roleCode) {
    return Array.isArray(sidebarAccessDefaults[roleCode])
        ? [...sidebarAccessDefaults[roleCode]]
        : [];
}

function getSidebarCurrentPages(roleCode) {
    if (roleCode === "SuperAdmin") {
        return Object.keys(sidebarAccessRegistry);
    }
    if (Object.prototype.hasOwnProperty.call(sidebarAccessSettings, roleCode)) {
        return [...(sidebarAccessSettings[roleCode] || [])];
    }
    return getSidebarDefaultPages(roleCode);
}

function setSidebarRoleSelection(roleCode, pageIds) {
    const desired = new Set(pageIds);
    document
        .querySelectorAll(`.sidebar-page-toggle[data-role-code="${cssEscape(roleCode)}"]`)
        .forEach((checkbox) => {
            checkbox.checked = desired.has(checkbox.dataset.pageId);
            checkbox
                .closest(".sidebar-page-tile")
                ?.classList.toggle("is-selected", checkbox.checked);
        });
    updateSidebarRoleSummary(roleCode);
}

function updateSidebarRoleSummary(roleCode) {
    const summaryEl = document.getElementById(`sidebarRoleSummary_${roleCode}`);
    const checkboxes = Array.from(
        document.querySelectorAll(
            `.sidebar-page-toggle[data-role-code="${cssEscape(roleCode)}"]`,
        ),
    );
    if (!summaryEl) return;
    if (roleCode === "SuperAdmin") {
        if (typeof uiLocale === "function" && uiLocale() === "zh-CN") {
            summaryEl.textContent = `${Object.keys(sidebarAccessRegistry).length} 个页面始终可见`;
        } else {
            summaryEl.textContent = `${Object.keys(sidebarAccessRegistry).length} pages always visible`;
        }
        return;
    }

    let checkedCount = 0;
    const assignableCount = getSidebarAssignablePages(roleCode).length;
    checkboxes.forEach((checkbox) => {
        const tile = checkbox.closest(".sidebar-page-tile");
        if (tile) tile.classList.toggle("is-selected", checkbox.checked);
        if (checkbox.checked) checkedCount += 1;
    });
    if (typeof uiLocale === "function" && uiLocale() === "zh-CN") {
        summaryEl.textContent = `${checkedCount} / ${checkboxes.length} 个页面可见（${assignableCount} 个可选）`;
    } else {
        summaryEl.textContent = `${checkedCount} of ${checkboxes.length} pages visible (${assignableCount} selectable)`;
    }
    summaryEl.classList.toggle("text-bg-warning", checkedCount === 0);
    summaryEl.classList.toggle("text-bg-light", checkedCount !== 0);
}

function collectSidebarAccessSettings() {
    const settings = {};

    sidebarAccessRoles.forEach((role) => {
        const roleCode = role.code || "";
        if (!roleCode || roleCode === "SuperAdmin") return;

        const selected = Array.from(
            document.querySelectorAll(
                `.sidebar-page-toggle[data-role-code="${cssEscape(roleCode)}"]:checked`,
            ),
        )
            .map((checkbox) => checkbox.dataset.pageId)
            .filter(Boolean)
            .sort();
        const defaults = getSidebarDefaultPages(roleCode).sort();

        if (!arraysEqual(selected, defaults)) {
            settings[roleCode] = selected;
        }
    });

    return settings;
}

async function saveSidebarAccessSettings() {
    const btn = document.getElementById("sidebarAccessSaveBtn");
    try {
        setLoading(btn, true);
        const settings = collectSidebarAccessSettings();
        const res = await api("PUT", "/users/sidebar-access", { settings });
        sidebarAccessSettings = (res.data && res.data.settings) || settings;
        renderSidebarAccessGrid();
        showToast("Sidebar settings saved");
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

function resetAllSidebarAccessToDefault() {
    sidebarAccessSettings = {};
    renderSidebarAccessGrid();
    showToast("Sidebar selections reset to role defaults");
}

function arraysEqual(a, b) {
    if (a.length !== b.length) return false;
    return a.every((value, index) => value === b[index]);
}

function cssEscape(value) {
    if (window.CSS && typeof window.CSS.escape === "function") {
        return window.CSS.escape(value);
    }
    return String(value).replace(/["\\]/g, "\\$&");
}

function loadSidebarCollapseState() {
    try {
        const raw = window.localStorage.getItem("clmsSidebarRoleCollapse");
        const parsed = raw ? JSON.parse(raw) : {};
        return parsed && typeof parsed === "object" ? parsed : {};
    } catch (_) {
        return {};
    }
}

function persistSidebarCollapseState() {
    try {
        window.localStorage.setItem(
            "clmsSidebarRoleCollapse",
            JSON.stringify(sidebarAccessCollapseState || {}),
        );
    } catch (_) {
        // ignore storage failures
    }
}

function loadSidebarSectionCollapseState() {
    try {
        const raw = window.localStorage.getItem("clmsSidebarSectionCollapse");
        const parsed = raw ? JSON.parse(raw) : {};
        return parsed && typeof parsed === "object" ? parsed : {};
    } catch (_) {
        return {};
    }
}

function persistSidebarSectionCollapseState() {
    try {
        window.localStorage.setItem(
            "clmsSidebarSectionCollapse",
            JSON.stringify(sidebarAccessSectionCollapseState || {}),
        );
    } catch (_) {
        // ignore storage failures
    }
}
