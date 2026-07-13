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

async function openNotificationTarget(id, event = null) {
    event?.preventDefault?.();
    event?.stopPropagation?.();
    try {
        const res = await api("POST", `/notifications/${id}/open`, {});
        const url = String(res.data?.url || "");
        if (!url.startsWith("/cargochina/") || url.startsWith("//")) {
            throw new Error(notificationsT("The notification destination is unavailable."));
        }
        window.location.assign(url);
    } catch (e) {
        showToast(e.message, "warning");
        await loadNotifications();
    }
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
              <button class="btn btn-sm btn-outline-secondary" data-copy-link="${link}" onclick="event.stopPropagation(); copyConfirmationLink(this.dataset.copyLink)" title="Copy confirmation link">
                ${escapeHtml(notificationsT("Copy link"))}
              </button>
              ${hasCustomerPhone ? `<button class="btn btn-sm btn-outline-success" data-phone="${escapeHtml(n.customer_phone)}" data-link="${link}" onclick="event.stopPropagation(); openWhatsApp(this.dataset.phone, this.dataset.link)" title="${escapeHtml(notificationsT("Open WhatsApp to message customer"))}">${escapeHtml(notificationsT("WhatsApp"))}</button>` : ""}
              <button class="btn btn-sm btn-outline-primary" data-link="${link}" onclick="event.stopPropagation(); openWeChat(this.dataset.link)" title="${escapeHtml(notificationsT("Copy link to share in WeChat"))}">${escapeHtml(notificationsT("WeChat"))}</button>
            </div>`
                          : "";
                      const target = n.target || {};
                      const canView = !!target.available;
                      const rowAttrs = canView
                          ? `role="link" tabindex="0" data-notification-id="${n.id}" onclick="openNotificationTarget(${n.id}, event)" onkeydown="if(event.key==='Enter'||event.key===' '){openNotificationTarget(${n.id},event)}"`
                          : "";
                      return `
        <div class="notification-card border rounded p-3 mb-2 ${n.read_at ? "" : "bg-light"} ${canView ? "notification-card-link" : ""}" ${rowAttrs}>
          <strong>${escapeHtml(n.title)}</strong>
          ${n.body ? "<br>" + escapeHtml(n.body) : ""}
          ${actionBtns}
          ${!canView && target.reason ? `<div class="small text-muted mt-2">${escapeHtml(target.reason)}</div>` : ""}
          <div class="notification-actions d-flex flex-wrap align-items-center gap-2 mt-2">
            <small class="text-muted me-auto">${escapeHtml(n.created_at || "")}</small>
            ${canView ? `<button type="button" class="btn btn-sm btn-primary" onclick="openNotificationTarget(${n.id}, event)" title="${escapeHtml(notificationsT("Open related record"))}">${escapeHtml(notificationsT("View"))}</button>` : `<button type="button" class="btn btn-sm btn-outline-secondary" disabled>${escapeHtml(notificationsT("Unavailable"))}</button>`}
            ${!n.read_at ? `<button type="button" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); markRead(${n.id}).then(loadNotifications);">${escapeHtml(notificationsT("Mark read"))}</button>` : ""}
          </div>
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
