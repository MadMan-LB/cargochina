/**
 * CLMS Frontend - API helpers and common UI
 */

const API_BASE = "/cargochina/api/v1";

async function api(method, path, body = null) {
    const opts = { method, headers: { "Content-Type": "application/json" } };
    if (body && (method === "POST" || method === "PUT")) {
        opts.body = JSON.stringify(body);
    }
    const res = await fetch(API_BASE + path, opts);
    const data = await res.json().catch(() => ({}));
    if (!res.ok) {
        throw new Error(data.message || res.statusText || "Request failed");
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
