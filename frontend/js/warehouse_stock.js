/**
 * Warehouse Stock page
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let stockCustomerAc = null;
    let stockSupplierAc = null;

    function getSelectedStockStatuses() {
        return Array.from(
            document.querySelectorAll(".stock-status-filter:checked"),
        ).map((el) => el.value);
    }

    function stockStatusDisplay(status) {
        return typeof statusLabel === "function" ? statusLabel(status) : status;
    }

    window.updateStockStatusFilterSummary = function () {
        const summaryEl = document.getElementById("filterStatusSummary");
        if (!summaryEl) return;
        const selected = getSelectedStockStatuses();
        const mode = document.getElementById("filterStatusMode")?.value || "include";
        if (!selected.length) {
            summaryEl.textContent = "All statuses";
            return;
        }
        summaryEl.textContent =
            (mode === "exclude" ? "Excluding: " : "Including: ") +
            selected.map(stockStatusDisplay).join(", ");
    };

    function setStockStatusFilter(statuses = [], mode = "include") {
        const selected = new Set((statuses || []).map(String));
        document.querySelectorAll(".stock-status-filter").forEach((el) => {
            el.checked = selected.has(el.value);
        });
        const modeEl = document.getElementById("filterStatusMode");
        if (modeEl) modeEl.value = mode === "exclude" ? "exclude" : "include";
        window.updateStockStatusFilterSummary();
    }

    window.clearStockStatusFilter = function () {
        setStockStatusFilter([], "include");
        loadStock();
    };

    async function api(path) {
        const r = await fetch(API + path, { credentials: "same-origin" });
        const d = await r.json();
        if (!r.ok || d.error) throw new Error(d.message || "Request failed");
        return d;
    }

    window.loadStock = async function () {
        const params = new URLSearchParams();
        const cid = document.getElementById("filterCustomerId").value;
        const sid = document.getElementById("filterSupplierId").value;
        const statuses = getSelectedStockStatuses();
        const statusMode =
            document.getElementById("filterStatusMode")?.value || "include";
        const q = document.getElementById("filterQ").value.trim();
        if (cid) params.set("customer_id", cid);
        if (sid) params.set("supplier_id", sid);
        statuses.forEach((status) => params.append("status[]", status));
        if (statuses.length) params.set("status_mode", statusMode);
        if (q) params.set("q", q);
        try {
            const d = await api("/warehouse-stock?" + params.toString());
            renderStock(d.data);
        } catch (e) {
            alert(e.message || "Failed to load stock");
        }
    };

    function renderStock(rows) {
        const tbody = document.getElementById("stockTableBody");
        if (!rows || rows.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">No stock found.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map(
                (r) => `
            <tr>
                <td><a href="/cargochina/orders.php?id=${r.order_id}">#${r.order_id}</a></td>
                <td>${escapeHtml(r.customer_name || "")}</td>
                <td>${escapeHtml(r.supplier_name || "—")}</td>
                <td><span class="badge bg-secondary">${escapeHtml(stockStatusDisplay(r.status || ""))}</span></td>
                <td>${escapeHtml(r.description_en || r.description_cn || r.product_desc_en || r.product_desc_cn || "—")}</td>
                <td>${r.quantity || "—"}</td>
                <td>${r.declared_cbm != null ? parseFloat(r.declared_cbm).toFixed(2) : "—"}</td>
                <td>${r.order_actual_cbm != null ? parseFloat(r.order_actual_cbm).toFixed(2) : "—"}</td>
            </tr>
        `,
            )
            .join("");
    }

    function escapeHtml(s) {
        if (!s) return "";
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    document.addEventListener("DOMContentLoaded", function () {
        const urlParams = new URLSearchParams(window.location.search);
        const statusFromUrl = urlParams.getAll("status[]");
        const legacyStatus = urlParams.get("status");
        const statusMode = urlParams.get("status_mode") || "include";
        if (statusFromUrl.length) {
            setStockStatusFilter(statusFromUrl, statusMode);
        } else if (legacyStatus) {
            setStockStatusFilter([legacyStatus], statusMode);
        } else {
            window.updateStockStatusFilterSummary();
        }

        if (typeof Autocomplete !== "undefined") {
            stockCustomerAc = Autocomplete.init(
                document.getElementById("filterCustomerSearch"),
                {
                    resource: "customers",
                    searchPath: "/search",
                    placeholder: "Type to search customer...",
                    onSelect: (item) => {
                        document.getElementById("filterCustomerId").value =
                            item.id || "";
                    },
                },
            );
            stockSupplierAc = Autocomplete.init(
                document.getElementById("filterSupplierSearch"),
                {
                    resource: "suppliers",
                    searchPath: "/search",
                    placeholder: "Type to search supplier...",
                    onSelect: (item) => {
                        document.getElementById("filterSupplierId").value =
                            item.id || "";
                    },
                },
            );
            document
                .getElementById("filterCustomerSearch")
                ?.addEventListener("input", () => {
                    document.getElementById("filterCustomerId").value = "";
                });
            document
                .getElementById("filterSupplierSearch")
                ?.addEventListener("input", () => {
                    document.getElementById("filterSupplierId").value = "";
                });
        }
        loadStock();
    });
})();
