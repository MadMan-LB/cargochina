document.addEventListener("DOMContentLoaded", loadConfig);

async function loadConfig() {
    try {
        const res = await api("GET", "/config");
        const c = res.data || {};
        document.getElementById("variancePct").value =
            c.variance_threshold_percent ?? 10;
        document.getElementById("varianceAbs").value =
            c.variance_threshold_abs_cbm ?? 0.1;
        document.getElementById("confirmationRequired").value =
            c.confirmation_required ?? "variance-only";
        document.getElementById("photoVisibility").value =
            c.customer_photo_visibility ?? "internal-only";
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveConfig() {
    try {
        await api("PUT", "/config", {
            config: {
                VARIANCE_THRESHOLD_PERCENT:
                    document.getElementById("variancePct").value,
                VARIANCE_THRESHOLD_ABS_CBM:
                    document.getElementById("varianceAbs").value,
                CONFIRMATION_REQUIRED: document.getElementById(
                    "confirmationRequired",
                ).value,
                CUSTOMER_PHOTO_VISIBILITY:
                    document.getElementById("photoVisibility").value,
            },
        });
        showToast("Configuration saved");
    } catch (e) {
        showToast(e.message, "danger");
    }
}
