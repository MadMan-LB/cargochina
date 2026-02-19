document.addEventListener("DOMContentLoaded", loadNotifications);

async function loadNotifications() {
    try {
        const res = await api("GET", "/notifications");
        const rows = res.data || [];
        document.getElementById("notificationsList").innerHTML = rows.length
            ? rows
                  .map(
                      (n) => `
        <div class="border rounded p-2 mb-2 ${n.read_at ? "" : "bg-light"}">
          <strong>${escapeHtml(n.title)}</strong>
          ${n.body ? "<br>" + escapeHtml(n.body) : ""}
          <br><small class="text-muted">${n.created_at}</small>
          ${!n.read_at ? `<button class="btn btn-sm btn-outline-primary ms-2" onclick="markRead(${n.id}); loadNotifications();">Mark read</button>` : ""}
        </div>
      `,
                  )
                  .join("")
            : '<p class="text-muted">No notifications</p>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function markRead(id) {
    try {
        await api("POST", "/notifications/" + id + "/read", {});
    } catch (e) {
        showToast(e.message, "danger");
    }
}
