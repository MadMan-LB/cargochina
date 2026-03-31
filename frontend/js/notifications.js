document.addEventListener("DOMContentLoaded", loadNotifications);

function notificationsT(text, replacements = null) {
    return typeof t === "function" ? t(text, replacements) : text;
}

function copyConfirmationLink(link) {
    if (!link) return;
    navigator.clipboard
        ?.writeText(link)
        .then(() => showToast(notificationsT("Link copied to clipboard")))
        .catch(() => showToast(notificationsT("Copy failed"), "danger"));
}

function openWhatsApp(phone, link) {
    if (!phone) {
        showToast(notificationsT("Customer phone not available"), "danger");
        return;
    }
    const promptText =
        uiLocale?.() === "zh-CN" ? "请确认差异：" : "Please confirm variance: ";
    const msg = link ? encodeURIComponent(promptText + link) : "";
    const url = msg
        ? `https://wa.me/${phone}?text=${msg}`
        : `https://wa.me/${phone}`;
    window.open(url, "_blank", "noopener");
}

function openWeChat(link) {
    if (link) copyConfirmationLink(link);
    showToast(
        link
            ? notificationsT(
                  "Link copied — paste in WeChat to share with customer",
              )
            : notificationsT("No link to share"),
        link ? "success" : "danger",
    );
}

async function loadNotifications() {
    try {
        const res = await api("GET", "/notifications");
        const rows = res.data || [];
        document.getElementById("notificationsList").innerHTML = rows.length
            ? rows
                  .map((n) => {
                      const hasConfirmLink = n.confirmation_link;
                      const hasCustomerPhone = n.customer_phone;
                      const link = (n.confirmation_link || "")
                          .replace(/&/g, "&amp;")
                          .replace(/"/g, "&quot;");
                      const actionBtns = hasConfirmLink
                          ? `
            <div class="d-flex flex-wrap gap-1 mt-2">
              <button class="btn btn-sm btn-outline-secondary" data-copy-link="${link}" onclick="copyConfirmationLink(this.dataset.copyLink)" title="Copy confirmation link">
                ${escapeHtml(notificationsT("Copy link"))}
              </button>
              ${hasCustomerPhone ? `<button class="btn btn-sm btn-outline-success" data-phone="${escapeHtml(n.customer_phone)}" data-link="${link}" onclick="openWhatsApp(this.dataset.phone, this.dataset.link)" title="${escapeHtml(notificationsT("Open WhatsApp to message customer"))}">${escapeHtml(notificationsT("WhatsApp"))}</button>` : ""}
              <button class="btn btn-sm btn-outline-primary" data-link="${link}" onclick="openWeChat(this.dataset.link)" title="${escapeHtml(notificationsT("Copy link to share in WeChat"))}">${escapeHtml(notificationsT("WeChat"))}</button>
            </div>`
                          : "";
                      return `
        <div class="border rounded p-2 mb-2 ${n.read_at ? "" : "bg-light"}">
          <strong>${escapeHtml(n.title)}</strong>
          ${n.body ? "<br>" + escapeHtml(n.body) : ""}
          ${actionBtns}
          <br><small class="text-muted">${n.created_at}</small>
          ${!n.read_at ? `<button class="btn btn-sm btn-outline-primary ms-2" onclick="markRead(${n.id}); loadNotifications();">${escapeHtml(notificationsT("Mark read"))}</button>` : ""}
        </div>
      `;
                  })
                  .join("")
            : `<p class="text-muted">${escapeHtml(notificationsT("No notifications"))}</p>`;
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
