let allRoles = [];
let allDepartments = [];
let allUsers = [];

document.addEventListener("DOMContentLoaded", async () => {
    setupResetPwToggle();
    await loadRolesAndDepartments();
    loadUsers();
    const createBtn = document.getElementById("createUserBtn");
    if (createBtn) createBtn.addEventListener("click", openCreateUserModal);
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
        showToast("Email is required", "danger");
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
    const password = document.getElementById("resetPassword").value.trim();
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
    const name = u ? u.full_name || u.email || "User" : "User";
    document.getElementById("activityPanelTitle").textContent =
        `Activity: ${name} (#${userId})`;
    document.getElementById("activityPanel").style.display = "block";
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

    try {
        const res = await api(
            "GET",
            "/users/" + activityUserId + "/activity?" + params.toString(),
        );
        const rows = res.data || [];
        const tbody = document.getElementById("activityBody");
        const emptyEl = document.getElementById("activityEmpty");
        const loadMoreBtn = document.getElementById("activityLoadMoreBtn");

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
            tr.innerHTML = `
                <td><small>${escapeHtml((r.created_at || "").replace(" ", " "))}</small></td>
                <td>${entityLink}</td>
                <td><span class="badge bg-secondary">${escapeHtml(r.action)}</span></td>
                <td><small class="text-muted">${escapeHtml(details || "—")}</small></td>
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
        showToast(e.message, "danger");
    }
}

function loadMoreActivity() {
    loadUserActivity(false);
}
