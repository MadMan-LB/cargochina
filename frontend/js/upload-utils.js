/**
 * CLMS Upload utilities: config, pre-check, client-side compression, progress
 */
const UPLOAD_API = "/cargochina/api/v1";
let uploadConfigCache = null;

async function getUploadConfig() {
    if (uploadConfigCache) return uploadConfigCache;
    const res = await fetch(UPLOAD_API + "/config/upload", {
        credentials: "same-origin",
    });
    const d = await res.json().catch(() => ({}));
    if (!res.ok)
        throw new Error(d.error?.message || "Failed to load upload config");
    uploadConfigCache = d.data || {
        max_upload_mb: 8,
        allowed_types: ["jpg", "jpeg", "png", "webp"],
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

async function compressImage(file, maxMb, maxPx = 1600, quality = 0.8) {
    return new Promise((resolve, reject) => {
        const img = new Image();
        const url = URL.createObjectURL(file);
        img.onload = () => {
            URL.revokeObjectURL(url);
            let w = img.width,
                h = img.height;
            if (w <= maxPx && h <= maxPx && file.size <= maxMb * 1048576) {
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
    const allowed = config.allowed_types || ["jpg", "jpeg", "png", "webp"];
    const ext = getExt(file);

    if (!allowed.includes(ext)) {
        const msg = "File type not allowed. Allowed: " + allowed.join(", ");
        showToast(msg, "danger");
        throw new Error(msg);
    }

    let toUpload = file;
    const fileMb = file.size / 1048576;
    if (fileMb > maxMb && isImageFile(file)) {
        try {
            toUpload = await compressImage(file, maxMb);
            if (toUpload.size > maxMb * 1048576) {
                const msg = `File too large (${fileMb.toFixed(1)} MB). Max ${maxMb} MB. Compression did not reduce enough.`;
                showToast(msg, "danger");
                throw new Error(msg);
            }
        } catch (e) {
            if (e.message && e.message.includes("File too large")) throw e;
            showToast("Compression failed, trying original", "warning");
        }
    } else if (fileMb > maxMb) {
        const msg = `File too large (${fileMb.toFixed(1)} MB). Max allowed ${maxMb} MB.`;
        showToast(msg, "danger");
        throw new Error(msg);
    }

    return new Promise((resolve, reject) => {
        const fd = new FormData();
        fd.append("file", toUpload);
        const xhr = new XMLHttpRequest();
        xhr.open("POST", UPLOAD_API + "/upload");
        xhr.withCredentials = true;

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
            if (xhr.status >= 200 && xhr.status < 300) {
                const path =
                    j.data?.path ||
                    (j.data?.url
                        ? j.data.url.replace(/^.*\/backend\//, "")
                        : null);
                resolve(path);
            } else {
                const err = j.error || {};
                const msg = err.message || "Upload failed";
                const reqId = err.request_id ? ` (ref: ${err.request_id})` : "";
                reject(new Error(msg + reqId));
            }
        };
        xhr.onerror = () => reject(new Error("Network error"));
        xhr.send(fd);
    });
}
