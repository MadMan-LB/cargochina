/**
 * CLMS Frontend - API helpers and common UI
 */

const API_BASE = "/cargochina/api/v1";

const UPLOAD_BASE = "/cargochina/api/v1/upload";

async function api(method, path, body = null) {
    const opts = {
        method,
        headers: { "Content-Type": "application/json" },
        credentials: "same-origin",
    };
    if (body && (method === "POST" || method === "PUT")) {
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(API_BASE + path, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        const err = data.error === true ? data : data.error || {};
        const msg =
            err.message || data.message || res.statusText || "Request failed";
        const reqId = err.request_id || data.request_id;
        throw new Error(msg + (reqId ? ` (ref: ${reqId})` : ""));
    }
    return data;
}

function showToast(message, type = "success") {
    const container =
        document.querySelector(".toast-container") || createToastContainer();
    const toast = document.createElement("div");
    toast.className = `toast align-items-center text-bg-${type} border-0`;
    toast.setAttribute("role", "alert");
    toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body">${escapeHtml(message)}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>`;
    container.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    toast.addEventListener("hidden.bs.toast", () => toast.remove());
}

function createToastContainer() {
    const c = document.createElement("div");
    c.className = "toast-container position-fixed top-0 end-0 p-3";
    document.body.appendChild(c);
    return c;
}

function escapeHtml(s) {
    const div = document.createElement("div");
    div.textContent = s;
    return div.innerHTML;
}

function setLoading(el, loading) {
    if (!el) return;
    if (loading) {
        el.classList.add("btn-loading");
        el.disabled = true;
    } else {
        el.classList.remove("btn-loading");
        el.disabled = false;
    }
}

async function uploadFile(file, opts = {}) {
    if (typeof uploadFileWithProgress === "function") {
        return uploadFileWithProgress(file, {
            showToast,
            ...opts,
        });
    }
    const fd = new FormData();
    fd.append("file", file);
    const res = await fetch("/cargochina/api/v1/upload", {
        method: "POST",
        body: fd,
        credentials: "same-origin",
    });
    const text = await res.text();
    let j = {};
    try {
        j = text ? JSON.parse(text) : {};
    } catch (_) {
        const snippet = text.substring(0, 200).replace(/</g, "&lt;");
        throw new Error(
            "Server returned invalid response (not JSON). " +
                (snippet ? "Response: " + snippet : ""),
        );
    }
    if (!res.ok) {
        const err = j.error || {};
        const msg = err.message || j.message || "Upload failed";
        const reqId = err.request_id ? ` (ref: ${err.request_id})` : "";
        throw new Error(msg + reqId);
    }
    return (
        j.data?.path ||
        (j.data?.url ? j.data.url.replace("/cargochina/backend/", "") : null)
    );
}
