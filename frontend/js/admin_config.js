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
        document.getElementById("minPhotosPerItem").value =
            c.min_photos_per_item ?? 1;
        document.getElementById("notificationChannels").value =
            (Array.isArray(c.notification_channels)
                ? c.notification_channels.join(",")
                : c.notification_channels) ?? "dashboard";
        document.getElementById("trackingApiBaseUrl").value =
            c.tracking_api_base_url ?? "";
        document.getElementById("trackingApiToken").placeholder =
            c.tracking_api_token_set
                ? "•••••••• (leave blank to keep)"
                : "Leave blank to keep current";
        document.getElementById("trackingApiPath").value =
            c.tracking_api_path ?? "/api/import/clms";
        document.getElementById("trackingApiTimeout").value =
            c.tracking_api_timeout_sec ?? 15;
        document.getElementById("trackingApiRetryCount").value =
            c.tracking_api_retry_count ?? 3;
        document.getElementById("trackingApiRetryBackoff").value =
            c.tracking_api_retry_backoff_ms ?? 800;
        document.getElementById("trackingPushEnabled").value =
            c.tracking_push_enabled ?? 0;
        document.getElementById("trackingPushDryRun").value =
            c.tracking_push_dry_run ?? 1;
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveConfig() {
    try {
        const cfg = {
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
                MIN_PHOTOS_PER_ITEM:
                    document.getElementById("minPhotosPerItem").value || 0,
                NOTIFICATION_CHANNELS:
                    document
                        .getElementById("notificationChannels")
                        .value?.trim() || "dashboard",
                TRACKING_API_BASE_URL:
                    document
                        .getElementById("trackingApiBaseUrl")
                        .value?.trim() || "",
                TRACKING_API_PATH:
                    document.getElementById("trackingApiPath").value?.trim() ||
                    "/api/import/clms",
                TRACKING_API_TIMEOUT_SEC:
                    document.getElementById("trackingApiTimeout").value || 15,
                TRACKING_API_RETRY_COUNT:
                    document.getElementById("trackingApiRetryCount").value || 3,
                TRACKING_API_RETRY_BACKOFF_MS:
                    document.getElementById("trackingApiRetryBackoff").value ||
                    800,
                TRACKING_PUSH_ENABLED:
                    document.getElementById("trackingPushEnabled").value || 0,
                TRACKING_PUSH_DRY_RUN:
                    document.getElementById("trackingPushDryRun").value ?? 1,
            },
        };
        const token = document.getElementById("trackingApiToken").value?.trim();
        if (token) cfg.config.TRACKING_API_TOKEN = token;
        await api("PUT", "/config", cfg);
        showToast("Configuration saved");
    } catch (e) {
        showToast(e.message, "danger");
    }
}
