/**
 * Financials page - profit, balances
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let profitCustomerAc = null;
    let profitSupplierAc = null;
    let balanceCustomerAc = null;
    let balanceSupplierAc = null;

    async function api(path) {
        const r = await fetch(API + path, { credentials: "same-origin" });
        const d = await r.json();
        if (!r.ok || d.error) throw new Error(d.message || "Request failed");
        return d;
    }

    window.loadProfit = async function () {
        const params = new URLSearchParams();
        const df = document.getElementById("profitDateFrom").value;
        const dt = document.getElementById("profitDateTo").value;
        const cid = document.getElementById("profitCustomerId").value;
        const sid = document.getElementById("profitSupplierId").value;
        if (df) params.set("date_from", df);
        if (dt) params.set("date_to", dt);
        if (cid) params.set("customer_id", cid);
        if (sid) params.set("supplier_id", sid);
        try {
            const d = await api("/financials/profit?" + params.toString());
            renderProfit(d.data, d.summary);
        } catch (e) {
            alert(e.message || "Failed to load profit data");
        }
    };

    function renderProfit(rows, summary) {
        const tbody = document.getElementById("profitTableBody");
        if (!rows || rows.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">No orders in range.</td></tr>';
            document.getElementById("profitSummary").innerHTML =
                '<small class="text-muted">No data.</small>';
            return;
        }
        tbody.innerHTML = rows
            .map(
                (r) => `
            <tr>
                <td><a href="/cargochina/orders.php?id=${r.id}">#${r.id}</a></td>
                <td>${escapeHtml(r.customer_name || "")}</td>
                <td>${escapeHtml(r.supplier_name || "—")}</td>
                <td><span class="badge bg-secondary">${escapeHtml(r.status || "")}</span></td>
                <td>${formatNum(r.order_total)}</td>
                <td>${formatNum(r.buy_total)}</td>
                <td>${formatNum(r.commission)}</td>
                <td class="${r.margin >= 0 ? "text-success" : "text-danger"}">${formatNum(r.margin)}</td>
            </tr>
        `,
            )
            .join("");
        let sumHtml = "";
        if (summary) {
            sumHtml = `<strong>Total Sell:</strong> ${formatNum(summary.total_sell)} &nbsp; <strong>Total Buy:</strong> ${formatNum(summary.total_buy)}`;
            if (
                summary.total_commission != null &&
                summary.total_commission > 0
            ) {
                sumHtml += ` &nbsp; <strong>Commission:</strong> ${formatNum(summary.total_commission)}`;
            }
            sumHtml += ` &nbsp; <strong>Gross Profit:</strong> <span class="text-success">${formatNum(summary.gross_profit)}</span>`;
            if (summary.net_profit != null) {
                sumHtml += ` &nbsp; <strong>Net (after commission):</strong> <span class="text-success">${formatNum(summary.net_profit)}</span>`;
            }
            if (summary.expenses && summary.expenses.length) {
                sumHtml +=
                    " &nbsp; <strong>Expenses:</strong> " +
                    summary.expenses
                        .map((e) => `${e.currency}: ${formatNum(e.total)}`)
                        .join(", ");
            }
        }
        document.getElementById("profitSummary").innerHTML =
            sumHtml || '<small class="text-muted">No summary.</small>';
    }

    window.loadBalances = async function () {
        const params = new URLSearchParams();
        const customerId = document.getElementById("balanceCustomerId").value;
        const supplierId = document.getElementById("balanceSupplierId").value;
        if (customerId) params.set("customer_id", customerId);
        if (supplierId) params.set("supplier_id", supplierId);
        try {
            const d = await api(
                "/financials/balances" +
                    (params.toString() ? "?" + params.toString() : ""),
            );
            renderBalances(d.data);
        } catch (e) {
            alert(e.message || "Failed to load balances");
        }
    };

    window.clearBalanceFilters = function () {
        document.getElementById("balanceCustomerId").value = "";
        document.getElementById("balanceSupplierId").value = "";
        balanceCustomerAc?.setValue(null);
        balanceSupplierAc?.setValue(null);
        loadBalances();
    };

    function renderBalances(data) {
        const cust = data?.customers || [];
        const supp = data?.suppliers || [];
        const custBody = document.getElementById("customerBalancesBody");
        const suppBody = document.getElementById("supplierPayablesBody");
        custBody.innerHTML = cust.length
            ? cust
                  .map(
                      (c) => `
            <tr>
                <td><a href="/cargochina/customers.php?id=${c.id}">${escapeHtml(c.name || c.code)}</a></td>
                <td>${formatNum(c.deposits)}</td>
                <td>${formatNum(c.receivable)}</td>
                <td class="${c.balance >= 0 ? "text-success" : "text-danger"}">${formatNum(c.balance)}</td>
            </tr>
        `,
                  )
                  .join("")
            : '<tr><td colspan="4" class="text-center text-muted">No customers.</td></tr>';
        suppBody.innerHTML = supp.length
            ? supp
                  .map(
                      (s) => `
            <tr>
                <td><a href="/cargochina/suppliers.php?id=${s.id}">${escapeHtml(s.name || s.code)}</a></td>
                <td>${formatNum(s.invoiced)}</td>
                <td>${formatNum(s.paid)}</td>
                <td class="${s.payable > 0 ? "text-warning" : ""}">${formatNum(s.payable)}</td>
            </tr>
        `,
                  )
                  .join("")
            : '<tr><td colspan="4" class="text-center text-muted">No suppliers.</td></tr>';
    }

    function formatNum(n) {
        if (n == null || n === "") return "—";
        return parseFloat(n).toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }
    function escapeHtml(s) {
        if (!s) return "";
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    function bindEntityAutocomplete(inputId, hiddenId, resource, placeholder) {
        const inputEl = document.getElementById(inputId);
        const hiddenEl = document.getElementById(hiddenId);
        if (!inputEl || !hiddenEl || typeof Autocomplete === "undefined")
            return null;
        const ac = Autocomplete.init(inputEl, {
            resource,
            searchPath: "/search",
            placeholder,
            onSelect: (item) => {
                hiddenEl.value = item.id || "";
            },
        });
        inputEl.addEventListener("input", () => {
            hiddenEl.value = "";
        });
        return ac;
    }

    document
        .getElementById("balances-tab")
        ?.addEventListener("shown.bs.tab", loadBalances);
    document.addEventListener("DOMContentLoaded", function () {
        profitCustomerAc = bindEntityAutocomplete(
            "profitCustomerSearch",
            "profitCustomerId",
            "customers",
            "Type to search customer...",
        );
        profitSupplierAc = bindEntityAutocomplete(
            "profitSupplierSearch",
            "profitSupplierId",
            "suppliers",
            "Type to search supplier...",
        );
        balanceCustomerAc = bindEntityAutocomplete(
            "balanceCustomerSearch",
            "balanceCustomerId",
            "customers",
            "Type to search customer...",
        );
        balanceSupplierAc = bindEntityAutocomplete(
            "balanceSupplierSearch",
            "balanceSupplierId",
            "suppliers",
            "Type to search supplier...",
        );
        loadProfit();
    });
})();
