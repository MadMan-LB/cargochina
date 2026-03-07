let allRoles = [];
let allDepartments = [];

document.addEventListener("DOMContentLoaded", () => {
    loadRolesAndDepartments();
    loadUsers();
});

async function loadRolesAndDepartments() {
    allRoles = [
        "SuperAdmin",
        "ChinaAdmin",
        "LebanonAdmin",
        "WarehouseStaff",
        "ChinaEmployee",
        "FieldStaff",
    ];
    try {
        const deptRes = await api("GET", "/departments");
        allDepartments = deptRes.data || [];
    } catch (_) {
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
            .map(
                (code) =>
                    `<div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="role_${code}" value="${code}"><label class="form-check-label" for="role_${code}">${escapeHtml(code)}</label></div>`,
            )
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

        new bootstrap.Modal(document.getElementById("userEditModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveUser() {
    const id = document.getElementById("editUserId").value;
    const roles = allRoles.filter(
        (code) => document.getElementById("role_" + code)?.checked,
    );
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
