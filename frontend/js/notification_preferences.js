const EVENT_LABELS = {
    order_submitted: "Order submitted",
    order_approved: "Order approved",
    order_received: "Order received",
    variance_confirmation: "Variance — customer follow-up sent",
    shipment_finalized: "Shipment finalized",
};

let prefs = {};
let preferencesRevision=null, preferencesSaving=false;

document.addEventListener("DOMContentLoaded", loadNotificationPreferences);

async function loadNotificationPreferences() {
    try {
        const res = await api("GET", "/notification-preferences");
        const data = res.data || [];
        preferencesRevision=res.revision;
        prefs = {};
        data.forEach((p) => {
            const key = `${p.channel}:${p.event_type}`;
            prefs[key] = !!p.enabled;
        });
        renderPrefs();
    } catch (e) {
        showToast(e.message || "Failed to load preferences", "danger");
    }
}

function renderPrefs() {
    const tbody = document.getElementById("prefsBody");
    if (!tbody) return;
    const events = Object.keys(EVENT_LABELS);
    tbody.innerHTML = events
        .map(
            (et) => `
      <tr>
        <td>${escapeHtml(EVENT_LABELS[et])}</td>
        <td><span class="text-muted">Always on</span></td>
        <td><input type="checkbox" class="form-check-input pref-email" data-event="${et}" ${prefs[`email:${et}`] !== false ? "checked" : ""}></td>
        <td><input type="checkbox" class="form-check-input pref-whatsapp" data-event="${et}" ${prefs[`whatsapp:${et}`] !== false ? "checked" : ""}></td>
      </tr>`,
        )
        .join("");
}

async function saveNotificationPreferences() {
    if(preferencesSaving || !preferencesRevision)return;
    const preferences = [];
    Object.keys(EVENT_LABELS).forEach((et) => {
        preferences.push({
            channel: "email",
            event_type: et,
            enabled:
                document.querySelector(`.pref-email[data-event="${et}"]`)
                    ?.checked ?? true,
        });
        preferences.push({
            channel: "whatsapp",
            event_type: et,
            enabled:
                document.querySelector(`.pref-whatsapp[data-event="${et}"]`)
                    ?.checked ?? true,
        });
    });
    const btn = document.getElementById("savePrefsBtn");
    preferencesSaving=true;
    try {
        setLoading(btn, true);
        const saved=await api("PUT", "/notification-preferences", { preferences, revision:preferencesRevision });
        preferencesRevision=saved.revision;
        showToast("Preferences saved");
    } catch (e) {
        showToast(e.message || "Failed to save", "danger");
    } finally {
        preferencesSaving=false;
        setLoading(btn, false);
    }
}
