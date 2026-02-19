document.addEventListener("DOMContentLoaded", loadUsers);

async function loadUsers() {
    try {
        const res = await api("GET", "/users");
        const rows = res.data || [];
        document.getElementById("usersBody").innerHTML =
            rows
                .map(
                    (u) =>
                        `<tr><td>${u.id}</td><td>${escapeHtml(u.email)}</td><td>${escapeHtml(u.full_name)}</td><td>${(u.roles || []).join(", ")}</td><td>${u.is_active ? "Yes" : "No"}</td></tr>`,
                )
                .join("") ||
            '<tr><td colspan="5" class="text-muted">No users</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}
