document.addEventListener("DOMContentLoaded", () => {
    loadConfig();
    loadHsCatalogFiles();
    document
        .getElementById("hsCatalogImportBtn")
        ?.addEventListener("click", runHsCatalogImport);
});

function toggleWhatsAppSections() {
    const provider =
        document.getElementById("whatsappProvider")?.value || "generic";
    document
        .getElementById("whatsappGenericSection")
        .classList.toggle("d-none", provider !== "generic");
    document
        .getElementById("whatsappTwilioSection")
        .classList.toggle("d-none", provider !== "twilio");
}

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
        document.getElementById("itemLevelReceivingEnabled").value =
            c.item_level_receiving_enabled ?? 0;
        document.getElementById("photoEvidencePerItem").value =
            c.photo_evidence_per_item ?? 0;
        document.getElementById("emailFromAddress").value =
            c.email_from_address ?? "";
        document.getElementById("emailFromName").value =
            c.email_from_name ?? "CLMS";
        document.getElementById("whatsappProvider").value =
            c.whatsapp_provider ?? "generic";
        document.getElementById("whatsappApiUrl").value =
            c.whatsapp_api_url ?? "";
        document.getElementById("whatsappApiToken").placeholder =
            c.whatsapp_api_token_set
                ? "•••••••• (leave blank to keep)"
                : "Leave blank to keep current";
        document.getElementById("whatsappTwilioAccountSid").value =
            c.whatsapp_twilio_account_sid ?? "";
        document.getElementById("whatsappTwilioAuthToken").placeholder =
            c.whatsapp_twilio_auth_token_set
                ? "•••••••• (leave blank to keep)"
                : "Leave blank to keep";
        document.getElementById("whatsappTwilioFrom").value =
            c.whatsapp_twilio_from ?? "";
        document.getElementById("whatsappTwilioTo").value =
            c.whatsapp_twilio_to ?? "";
        document.getElementById("notificationMaxAttempts").value =
            c.notification_max_attempts ?? 3;
        document.getElementById("notificationRetrySeconds").value =
            c.notification_retry_seconds ?? 60;
        document.getElementById("uploadMaxMb").value = c.upload_max_mb ?? 8;
        document.getElementById("uploadAllowedTypes").value = Array.isArray(
            c.upload_allowed_types,
        )
            ? c.upload_allowed_types.join(",")
            : c.upload_allowed_types ||
              "jpg,jpeg,png,webp,jfif,gif,bmp,avif,pdf";
        toggleWhatsAppSections();
        document.getElementById("whatsappProvider").onchange =
            toggleWhatsAppSections;
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
        document.getElementById("staleOrderThresholdDays").value =
            c.stale_order_threshold_days ?? 3;
        document.getElementById("appUrl").value = c.app_url ?? "";
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function loadHsCatalogFiles() {
    const sel = document.getElementById("hsCatalogFile");
    if (!sel) return;
    try {
        const res = await api("GET", "/hs-code-catalog/files");
        const files = res.data || [];
        sel.innerHTML =
            '<option value="">lebanon_customs_tariffs.csv (default)</option>';
        files.forEach((f) => {
            const opt = document.createElement("option");
            opt.value = f.name;
            opt.textContent =
                f.name + (f.size ? ` (${(f.size / 1024).toFixed(1)} KB)` : "");
            sel.appendChild(opt);
        });
    } catch (e) {
        sel.innerHTML =
            '<option value="">lebanon_customs_tariffs.csv (default)</option>';
    }
}

async function runHsCatalogImport() {
    const btn = document.getElementById("hsCatalogImportBtn");
    const status = document.getElementById("hsCatalogImportStatus");
    if (!btn || !status) return;
    const sel = document.getElementById("hsCatalogFile");
    const source = sel?.value?.trim() || "";
    btn.disabled = true;
    status.textContent = "Importing…";
    try {
        const res = await api("POST", "/hs-code-catalog/import", { source });
        const d = res.data || {};
        status.textContent = `Imported ${d.imported ?? 0} rows from ${d.file ?? "file"}.`;
        showToast(`HS catalog: ${d.imported ?? 0} rows imported`);
    } catch (e) {
        status.textContent = "Error: " + (e.message || "Import failed");
        showToast(e.message, "danger");
    } finally {
        btn.disabled = false;
    }
}

function validateConfig(cfg) {
    const errs = [];
    const pct = parseFloat(cfg.VARIANCE_THRESHOLD_PERCENT);
    if (isNaN(pct) || pct < 0 || pct > 100)
        errs.push("Variance threshold % must be 0–100");
    const abs = parseFloat(cfg.VARIANCE_THRESHOLD_ABS_CBM);
    if (isNaN(abs) || abs < 0)
        errs.push("Variance threshold abs CBM must be ≥ 0");
    const vis = cfg.CUSTOMER_PHOTO_VISIBILITY;
    if (vis && !["internal-only", "customer-visible"].includes(vis))
        errs.push(
            "Customer photo visibility must be internal-only or customer-visible",
        );
    const url = cfg.WHATSAPP_API_URL;
    if (url && !/^https?:\/\/.+/.test(url))
        errs.push("WhatsApp API URL must be a valid HTTP(S) URL");
    const provider = cfg.WHATSAPP_PROVIDER;
    if (provider && !["generic", "twilio"].includes(provider))
        errs.push("WhatsApp provider must be generic or twilio");
    const maxAttempts = parseInt(cfg.NOTIFICATION_MAX_ATTEMPTS, 10);
    if (!isNaN(maxAttempts) && (maxAttempts < 1 || maxAttempts > 10))
        errs.push("Notification max attempts must be 1–10");
    const retrySec = parseInt(cfg.NOTIFICATION_RETRY_SECONDS, 10);
    if (!isNaN(retrySec) && (retrySec < 1 || retrySec > 3600))
        errs.push("Notification retry seconds must be 1–3600");
    const uploadMb = parseFloat(cfg.UPLOAD_MAX_MB);
    if (!isNaN(uploadMb) && (uploadMb < 0.5 || uploadMb > 50))
        errs.push("Upload max MB must be 0.5–50");
    const allowedTypes = String(cfg.UPLOAD_ALLOWED_TYPES || "")
        .split(",")
        .map((t) => t.trim().toLowerCase())
        .filter(Boolean);
    if (
        allowedTypes.length &&
        allowedTypes.some(
            (t) =>
                ![
                    "jpg",
                    "jpeg",
                    "png",
                    "webp",
                    "jfif",
                    "gif",
                    "bmp",
                    "avif",
                    "pdf",
                ].includes(t),
        )
    ) {
        errs.push(
            "Upload allowed types must be jpg,jpeg,png,webp,jfif,gif,bmp,avif,pdf",
        );
    }
    return errs;
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
                ITEM_LEVEL_RECEIVING_ENABLED:
                    document.getElementById("itemLevelReceivingEnabled")
                        .value || 0,
                PHOTO_EVIDENCE_PER_ITEM:
                    document.getElementById("photoEvidencePerItem").value || 0,
                EMAIL_FROM_ADDRESS:
                    document.getElementById("emailFromAddress").value?.trim() ||
                    "",
                EMAIL_FROM_NAME:
                    document.getElementById("emailFromName").value?.trim() ||
                    "CLMS",
                WHATSAPP_PROVIDER:
                    document.getElementById("whatsappProvider").value ||
                    "generic",
                WHATSAPP_API_URL:
                    document.getElementById("whatsappApiUrl").value?.trim() ||
                    "",
                WHATSAPP_TWILIO_ACCOUNT_SID:
                    document
                        .getElementById("whatsappTwilioAccountSid")
                        .value?.trim() || "",
                WHATSAPP_TWILIO_FROM:
                    document
                        .getElementById("whatsappTwilioFrom")
                        .value?.trim() || "",
                WHATSAPP_TWILIO_TO:
                    document.getElementById("whatsappTwilioTo").value?.trim() ||
                    "",
                NOTIFICATION_MAX_ATTEMPTS:
                    document.getElementById("notificationMaxAttempts").value ||
                    3,
                NOTIFICATION_RETRY_SECONDS:
                    document.getElementById("notificationRetrySeconds").value ||
                    60,
                UPLOAD_MAX_MB:
                    document.getElementById("uploadMaxMb").value || 8,
                UPLOAD_ALLOWED_TYPES:
                    document
                        .getElementById("uploadAllowedTypes")
                        .value?.trim() ||
                    "jpg,jpeg,png,webp,jfif,gif,bmp,avif,pdf",
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
                STALE_ORDER_THRESHOLD_DAYS:
                    document.getElementById("staleOrderThresholdDays").value ||
                    3,
                APP_URL: document.getElementById("appUrl").value?.trim() || "",
            },
        };
        const token = document.getElementById("trackingApiToken").value?.trim();
        if (token) cfg.config.TRACKING_API_TOKEN = token;
        const whatsappToken = document
            .getElementById("whatsappApiToken")
            .value?.trim();
        if (whatsappToken) cfg.config.WHATSAPP_API_TOKEN = whatsappToken;
        const twilioToken = document
            .getElementById("whatsappTwilioAuthToken")
            .value?.trim();
        if (twilioToken) cfg.config.WHATSAPP_TWILIO_AUTH_TOKEN = twilioToken;
        const errs = validateConfig(cfg.config);
        if (errs.length) {
            showToast(errs.join("; "), "danger");
            return;
        }
        await api("PUT", "/config", cfg);
        showToast("Configuration saved");
    } catch (e) {
        showToast(e.message, "danger");
    }
}
