let allRoles = [];
let allDepartments = [];

document.addEventListener("DOMContentLoaded", async () => {
    setupResetPwToggle();
    await loadRolesAndDepartments();
    loadUsers();
});

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
                            <td><button class="btn btn-sm btn-outline-primary" onclick="editUser(${u.id})">Edit</button></td>
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
