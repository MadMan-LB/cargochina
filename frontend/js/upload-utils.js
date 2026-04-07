/**
 * CLMS Upload utilities: config, pre-check, client-side compression, progress
 */
const UPLOAD_API = "/cargochina/api/v1";
let uploadConfigCache = null;

async function getUploadConfig() {
    if (uploadConfigCache) return uploadConfigCache;
    let res;
    try {
        res = await fetch(UPLOAD_API + "/config/upload", {
            credentials: "same-origin",
        });
    } catch (error) {
        throw new Error(
            typeof t === "function"
                ? t(
                      "Unable to load upload settings. Please refresh and try again.",
                  )
                : "Unable to load upload settings. Please refresh and try again.",
        );
    }
    const d = await res.json().catch(() => ({}));
    if (!res.ok)
        throw new Error(
            d.error?.message ||
                (typeof t === "function"
                    ? t(
                          "Unable to load upload settings. Please refresh and try again.",
                      )
                    : "Unable to load upload settings. Please refresh and try again."),
        );
    uploadConfigCache = d.data || {
        max_upload_mb: 8,
        allowed_types: [
            "jpg",
            "jpeg",
            "png",
            "webp",
            "jfif",
            "gif",
            "bmp",
            "avif",
            "pdf",
        ],
        unsupported_image_types: ["heic", "heif"],
        unsupported_image_messages: {
            heic: "HEIC / HEIF images are not supported on this server yet. Please convert them to JPG, PNG, WebP, BMP, or AVIF before uploading.",
            heif: "HEIC / HEIF images are not supported on this server yet. Please convert them to JPG, PNG, WebP, BMP, or AVIF before uploading.",
        },
        processing_mode: "deferred-thumbnail",
        preserve_originals: true,
    };
    return uploadConfigCache;
}

function isImageFile(file) {
    return file.type && file.type.startsWith("image/");
}

function getExt(file) {
    const n = file.name || "";
    const i = n.lastIndexOf(".");
    return i >= 0 ? n.slice(i + 1).toLowerCase() : "";
}

function formatUploadDisplayNumber(value, maxDecimals = 1) {
    if (typeof window.formatDisplayNumber === "function") {
        return window.formatDisplayNumber(value, { maxDecimals }) || "0";
    }
    const numeric = parseFloat(value);
    return Number.isFinite(numeric) ? String(numeric) : "0";
}

function getUnsupportedUploadMessage(ext, config) {
    const normalized = String(ext || "").toLowerCase();
    const messages = config?.unsupported_image_messages || {};
    return messages[normalized] || null;
}

function translateUploadMessage(message, replacements = null) {
    if (typeof t === "function") {
        return t(message, replacements);
    }
    if (!replacements || typeof replacements !== "object") return message;
    return String(message).replace(/\{(\w+)\}/g, (_, key) =>
        Object.prototype.hasOwnProperty.call(replacements, key)
            ? String(replacements[key])
            : `{${key}}`,
    );
}

function shouldDebugUploadTiming() {
    return (
        typeof window !== "undefined" &&
        typeof localStorage !== "undefined" &&
        localStorage.getItem("clms_debug_timing") === "1"
    );
}

function extractClipboardImageFiles(event) {
    const clipboardData = event?.clipboardData;
    if (!clipboardData?.items?.length) return [];
    const files = [];
    Array.from(clipboardData.items).forEach((item) => {
        if (item.kind !== "file") return;
        const file = item.getAsFile?.();
        if (!file) return;
        if (isImageFile(file)) {
            files.push(file);
        }
    });
    return files;
}

function bindClipboardImagePaste(target, onFiles, opts = {}) {
    const el =
        typeof target === "string" ? document.querySelector(target) : target;
    if (!el || typeof onFiles !== "function") return () => {};
    const enabledWhen =
        typeof opts.enabledWhen === "function" ? opts.enabledWhen : () => true;
    const listener = async (event) => {
        if (!enabledWhen()) return;
        const files = extractClipboardImageFiles(event);
        if (!files.length) return;
        event.preventDefault();
        try {
            await onFiles(files, event);
        } catch (error) {
            if (typeof opts.onError === "function") {
                opts.onError(error);
            } else if (typeof window.showToast === "function") {
                window.showToast(
                    error?.message || "Failed to paste image",
                    "danger",
                );
            }
        }
    };
    el.addEventListener("paste", listener);
    return () => el.removeEventListener("paste", listener);
}

async function compressImage(file, maxMb, maxPx = 1600, quality = 0.8) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            const ext = getExt(file);
            let w = img.width,
                h = img.height;
            const shouldNormalizeSmallImage = ext === "bmp";
            if (
                !shouldNormalizeSmallImage &&
                w <= maxPx &&
                h <= maxPx &&
                file.size <= maxMb * 1048576
            ) {
                resolve(file);
                return;
            }
            if (w > maxPx || h > maxPx) {
                if (w > h) {
                    h = Math.round((h * maxPx) / w);
                    w = maxPx;
                } else {
                    w = Math.round((w * maxPx) / h);
                    h = maxPx;
                }
            }
            const canvas = document.createElement("canvas");
            canvas.width = w;
            canvas.height = h;
            const ctx = canvas.getContext("2d");
            ctx.drawImage(img, 0, 0, w, h);
            canvas.toBlob(
                (blob) => {
                    if (!blob) {
                        resolve(file);
                        return;
                    }
                    const f = new File(
                        [blob],
                        file.name.replace(/\.[^.]+$/, ".jpg"),
                        { type: "image/jpeg" },
                    );
                    resolve(f);
                },
                "image/jpeg",
                quality,
            );
        };
        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(file);
        };
        img.src = url;
    });
}

/**
 * Upload file with pre-check, optional compression, progress callback
 * @param {File} file
 * @param {Object} opts - { onProgress?: (pct) => void, showToast?: (msg, type) => void }
 * @returns {Promise<string>} path
 */
async function uploadFileWithProgress(file, opts = {}) {
    const { onProgress, showToast = () => {} } = opts;
    const config = await getUploadConfig();
    const maxMb = config.max_upload_mb || 8;
    const allowed = config.allowed_types || [
        "jpg",
        "jpeg",
        "png",
        "webp",
        "jfif",
        "gif",
        "bmp",
        "avif",
        "pdf",
    ];
    const ext = getExt(file);

    const unsupportedMsg = getUnsupportedUploadMessage(ext, config);
    if (unsupportedMsg) {
        const msg = translateUploadMessage(unsupportedMsg);
        showToast(msg, "danger");
        throw new Error(msg);
    }

    if (!allowed.includes(ext)) {
        const msg = translateUploadMessage(
            "File type not allowed. Allowed: {types}",
            { types: allowed.join(", ") },
        );
        showToast(msg, "danger");
        throw new Error(msg);
    }

    let toUpload = file;
    const fileMb = file.size / 1048576;
    if (isImageFile(file)) {
        try {
            const quality = fileMb > 6 ? 0.72 : fileMb > 3 ? 0.76 : 0.82;
            toUpload = await compressImage(file, maxMb, 1600, quality);
            if (toUpload.size > maxMb * 1048576) {
                const msg = `File too large (${formatUploadDisplayNumber(fileMb, 1)} MB). Max ${formatUploadDisplayNumber(maxMb, 1)} MB. Compression did not reduce enough.`;
                showToast(msg, "danger");
                throw new Error(msg);
            }
        } catch (e) {
            if (e.message && e.message.includes("File too large")) throw e;
            showToast(
                typeof t === "function"
                    ? t("Image optimization failed, trying the original file")
                    : "Image optimization failed, trying the original file",
                "warning",
            );
            toUpload = file;
        }
    } else if (fileMb > maxMb) {
        const msg = `File too large (${formatUploadDisplayNumber(fileMb, 1)} MB). Max allowed ${formatUploadDisplayNumber(maxMb, 1)} MB.`;
        showToast(msg, "danger");
        throw new Error(msg);
    }

    return new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append("file", toUpload);
        const xhr = new XMLHttpRequest();
        xhr.open("POST", UPLOAD_API + "/upload");
        xhr.withCredentials = true;
        xhr.timeout = 120000;
        if (shouldDebugUploadTiming()) {
            xhr.setRequestHeader("X-CLMS-Debug-Timing", "1");
        }

        xhr.upload.onprogress = (e) => {
            if (e.lengthComputable && typeof onProgress === "function") {
                onProgress(Math.round((e.loaded / e.total) * 100));
            }
        };

        xhr.onload = () => {
            let j = {};
            try {
                j = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            } catch (_) {}
            if (shouldDebugUploadTiming()) {
                const serverMs = xhr.getResponseHeader("X-CLMS-Response-Time-Ms");
                if (serverMs) {
                    console.debug(`[CLMS Upload] ${toUpload.name} ${serverMs}ms`);
                }
            }
            if (xhr.status >= 200 && xhr.status < 300) {
                const path =
                    j.data?.path ||
                    (j.data?.url
                        ? j.data.url.replace(/^.*\/backend\//, "")
                        : null);
                resolve(path);
            } else {
                const err = j.error || {};
                const msg = err.allowed_types
                    ? translateUploadMessage(
                          "File type not allowed. Allowed: {types}",
                          { types: err.allowed_types.join(", ") },
                      )
                    : translateUploadMessage(
                          err.message || "Upload failed",
                      );
                const reqId = err.request_id ? ` (ref: ${err.request_id})` : "";
                reject(new Error(msg + reqId));
            }
        };
        xhr.onerror = () =>
            reject(
                new Error(
                    typeof t === "function"
                        ? t(
                              "Upload failed because the network connection was interrupted.",
                          )
                        : "Upload failed because the network connection was interrupted.",
                ),
            );
        xhr.ontimeout = () =>
            reject(
                new Error(
                    typeof t === "function"
                        ? t(
                              "Upload timed out. Please try a smaller image or retry on a stronger connection.",
                          )
                        : "Upload timed out. Please try a smaller image or retry on a stronger connection.",
                ),
            );
        xhr.send(fd);
    });
}

window.extractClipboardImageFiles = extractClipboardImageFiles;
window.bindClipboardImagePaste = bindClipboardImagePaste;
