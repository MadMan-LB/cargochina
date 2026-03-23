/**
 * Financials page - profit, balances
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let profitCustomerAc = null;
    let profitSupplierAc = null;
    let balanceCustomerAc = null;
    let balanceSupplierAc = null;
    let finDepOrderAc = null;
    let finPayOrderAc = null;
    let balancesLoadedOnce = false;
    const PROFIT_DEFAULT_EXCLUDED_STATUSES = ["Draft", "CustomerDeclined"];

    async function api(path, opts = {}) {
        const fetchOpts = { credentials: "same-origin", ...opts };
        if (opts.method === "POST" && opts.body) {
            fetchOpts.headers = { "Content-Type": "application/json" };
        }
        const r = await fetch(API + path, fetchOpts);
        const d = await r.json();
        if (!r.ok || d.error) throw new Error(d.message || "Request failed");
        return d;
    }

    function setText(id, value) {
        const el = document.getElementById(id);
        if (el) el.textContent = value;
    }

    function setHtml(id, value) {
        const el = document.getElementById(id);
        if (el) el.innerHTML = value;
    }

    function formatSignedNum(n) {
        const value = parseFloat(n || 0);
        const formatted = formatNum(Math.abs(value));
        if (value > 0) return "+" + formatted;
        if (value < 0) return "-" + formatted;
        return formatted;
    }

    function getStatusBadge(status) {
        const label =
            typeof statusLabel === "function" ? statusLabel(status) : status;
        const cls =
            typeof statusBadgeClass === "function"
                ? statusBadgeClass(status)
                : "bg-secondary";
        return `<span class="badge ${cls}">${escapeHtml(label || "—")}</span>`;
    }

    function getFilterSummary(parts, fallback) {
        return parts.length ? parts.join(" | ") : fallback;
    }

    function getSelectedProfitStatuses() {
        return Array.from(
            document.querySelectorAll(".profit-status-filter:checked"),
        ).map((el) => el.value);
    }

    function getProfitStatusMode() {
        return document.getElementById("profitStatusMode")?.value || "include";
    }

    function formatProfitStatusLabels(statuses) {
        return (statuses || [])
            .map((status) =>
                typeof statusLabel === "function" ? statusLabel(status) : status,
            )
            .join(", ");
    }

    function updateProfitStatusSummary() {
        const summaryEl = document.getElementById("profitStatusSummary");
        if (!summaryEl) return;
        const statuses = getSelectedProfitStatuses();
        if (!statuses.length) {
            summaryEl.textContent =
                "Default scope: all except Draft and Customer Declined.";
            return;
        }
        const labels = formatProfitStatusLabels(statuses);
        summaryEl.textContent =
            getProfitStatusMode() === "exclude"
                ? `Default scope plus excluding: ${labels}.`
                : `Including: ${labels}.`;
    }

    function renderLoadingRows(bodyId, cols, message) {
        const tbody = document.getElementById(bodyId);
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted py-4">${escapeHtml(message || "Loading…")}</td></tr>`;
    }

    function buildProfitSummary(summary) {
        if (!summary) {
            return '<div class="text-muted small">No summary available.</div>';
        }
        const items = [
            { label: "Total Sell", value: formatNum(summary.total_sell) },
            { label: "Total Buy", value: formatNum(summary.total_buy) },
            {
                label: "Gross Profit",
                value: formatNum(summary.gross_profit),
                className:
                    parseFloat(summary.gross_profit || 0) >= 0
                        ? "positive"
                        : "negative",
            },
            {
                label: "Commission",
                value: formatNum(summary.total_commission),
            },
            {
                label: "Net Profit",
                value: formatNum(summary.net_profit),
                className:
                    parseFloat(summary.net_profit || 0) >= 0
                        ? "positive"
                        : "negative",
            },
        ];
        return `
            <div class="finance-summary-grid">
                ${items
                    .map(
                        (item) => `
                    <div class="finance-summary-card">
                        <div class="label">${escapeHtml(item.label)}</div>
                        <div class="value ${item.className || ""}">${escapeHtml(item.value)}</div>
                    </div>`,
                    )
                    .join("")}
            </div>
        `;
    }

    function updateProfitOverview(rows, summary) {
        const df = document.getElementById("profitDateFrom").value;
        const dt = document.getElementById("profitDateTo").value;
        const customerText = document
            .getElementById("profitCustomerSearch")
            .value.trim();
        const supplierText = document
            .getElementById("profitSupplierSearch")
            .value.trim();
        const statuses = getSelectedProfitStatuses();
        const filters = [];
        if (df || dt) {
            filters.push(
                `Ready date ${df || "any"} → ${dt || "any"}`,
            );
        }
        if (customerText) filters.push(`Customer: ${customerText}`);
        if (supplierText) filters.push(`Supplier: ${supplierText}`);
        if (statuses.length) {
            filters.push(
                `${
                    getProfitStatusMode() === "exclude"
                        ? "Default scope plus exclude"
                        : "Include"
                }: ${formatProfitStatusLabels(statuses)}`,
            );
        }

        setText("profitOrderCount", String(rows.length));
        setText(
            "profitOrderDetail",
            rows.length === 1
                ? "1 order matches the current filters."
                : `${rows.length} orders match the current filters.`,
        );
        setText("profitGrossCount", formatNum(summary?.gross_profit || 0));
        setText(
            "profitGrossDetail",
            `Sell ${formatNum(summary?.total_sell || 0)} minus buy ${formatNum(summary?.total_buy || 0)}.`,
        );
        setText("profitNetCount", formatNum(summary?.net_profit || 0));
        setText(
            "profitNetDetail",
            `After ${formatNum(summary?.total_commission || 0)} commission.`,
        );
        setText(
            "profitCommissionCount",
            formatNum(summary?.total_commission || 0),
        );
        setText(
            "profitCommissionDetail",
            summary?.total_commission
                ? "Commission is being deducted from the visible margin."
                : "No commission impact in the current result set.",
        );
        setText(
            "profitFilterSummary",
            getFilterSummary(
                filters,
                "Showing the default finance scope: all except Draft and Customer Declined.",
            ),
        );
        setText(
            "profitTableSummary",
            rows.length
                ? `${rows.length} order${rows.length === 1 ? "" : "s"} in view`
                : "No matching orders",
        );
        const expenses = summary?.expenses || [];
        setText(
            "profitExpenseSummary",
            expenses.length
                ? expenses
                      .map((expense) => {
                          return `${expense.currency}: ${formatNum(expense.total)}`;
                      })
                      .join(" | ")
                : "No expenses recorded for the visible period.",
        );
    }

    window.loadProfit = async function () {
        const params = new URLSearchParams();
        const df = document.getElementById("profitDateFrom").value;
        const dt = document.getElementById("profitDateTo").value;
        const cid = document.getElementById("profitCustomerId").value;
        const sid = document.getElementById("profitSupplierId").value;
        const statuses = getSelectedProfitStatuses();
        if (df) params.set("date_from", df);
        if (dt) params.set("date_to", dt);
        if (cid) params.set("customer_id", cid);
        if (sid) params.set("supplier_id", sid);
        if (statuses.length) {
            statuses.forEach((status) => params.append("status[]", status));
            params.set("status_mode", getProfitStatusMode());
        }
        try {
            renderLoadingRows("profitTableBody", 8, "Loading profit data…");
            setHtml(
                "profitSummary",
                '<div class="text-muted small">Refreshing the profit summary…</div>',
            );
            const d = await api("/financials/profit?" + params.toString());
            renderProfit(d.data, d.summary);
        } catch (e) {
            alert(e.message || "Failed to load profit data");
            renderProfit([], null);
        }
    };

    function renderProfit(rows, summary) {
        const tbody = document.getElementById("profitTableBody");
        if (!rows || rows.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">No orders in range.</td></tr>';
            setHtml(
                "profitSummary",
                '<div class="text-muted small">No profit data for the selected filters.</div>',
            );
            updateProfitOverview([], summary);
            return;
        }
        tbody.innerHTML = rows
            .map(
                (r) => `
            <tr>
                <td><a href="/cargochina/orders.php?id=${r.id}">#${r.id}</a></td>
                <td>${escapeHtml(r.customer_name || "")}</td>
                <td>${escapeHtml(r.supplier_name || "—")}</td>
                <td>${getStatusBadge(r.status || "")}</td>
                <td>${formatNum(r.order_total)}</td>
                <td>${formatNum(r.buy_total)}</td>
                <td>${formatNum(r.commission)}</td>
                <td class="${r.margin >= 0 ? "text-success" : "text-danger"}">${formatNum(r.margin)}</td>
            </tr>
        `,
            )
            .join("");
        setHtml("profitSummary", buildProfitSummary(summary));
        updateProfitOverview(rows, summary);
    }

    window.loadBalances = async function () {
        const params = new URLSearchParams();
        const customerId = document.getElementById("balanceCustomerId").value;
        const supplierId = document.getElementById("balanceSupplierId").value;
        if (customerId) params.set("customer_id", customerId);
        if (supplierId) params.set("supplier_id", supplierId);
        try {
            renderLoadingRows(
                "customerBalancesBody",
                5,
                "Loading customer balances…",
            );
            renderLoadingRows(
                "supplierPayablesBody",
                5,
                "Loading supplier payables…",
            );
            const d = await api(
                "/financials/balances" +
                    (params.toString() ? "?" + params.toString() : ""),
            );
            renderBalances(d.data);
            balancesLoadedOnce = true;
        } catch (e) {
            alert(e.message || "Failed to load balances");
            renderBalances({ customers: [], suppliers: [] });
        }
    };

    window.clearProfitFilters = function () {
        document.getElementById("profitDateFrom").value = "";
        document.getElementById("profitDateTo").value = "";
        document.getElementById("profitCustomerId").value = "";
        document.getElementById("profitSupplierId").value = "";
        document
            .querySelectorAll(".profit-status-filter")
            .forEach((el) => (el.checked = false));
        const modeEl = document.getElementById("profitStatusMode");
        if (modeEl) modeEl.value = "include";
        profitCustomerAc?.setValue(null);
        profitSupplierAc?.setValue(null);
        updateProfitStatusSummary();
        loadProfit();
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
        const nameEsc = (s) => escapeHtml(s || "").replace(/'/g, "\\'");
        custBody.innerHTML = cust.length
            ? cust
                  .map(
                      (c) => `
            <tr>
                <td><a href="/cargochina/customers.php?id=${c.id}">${escapeHtml(c.name || c.code)}</a></td>
                <td>${formatNum(c.deposits)}</td>
                <td>${formatNum(c.receivable)}</td>
                <td class="${c.balance >= 0 ? "text-success" : "text-danger"}">${formatSignedNum(c.balance)}</td>
                <td><button class="btn btn-sm btn-outline-primary" onclick="openFinDepositModal(${c.id}, '${nameEsc(c.name || c.code)}')">Record Deposit</button></td>
            </tr>
        `,
                  )
                  .join("")
            : '<tr><td colspan="5" class="text-center text-muted">No customers.</td></tr>';
        suppBody.innerHTML = supp.length
            ? supp
                  .map(
                      (s) => `
            <tr>
                <td><a href="/cargochina/suppliers.php?id=${s.id}">${escapeHtml(s.name || s.code)}</a></td>
                <td>${formatNum(s.invoiced)}</td>
                <td>${formatNum(s.paid)}</td>
                <td class="${s.payable > 0 ? "text-warning" : ""}">${formatNum(s.payable)}</td>
                <td><button class="btn btn-sm btn-outline-success" onclick="openFinPaymentModal(${s.id}, '${nameEsc(s.name || s.code)}')">Record Payment</button></td>
            </tr>
        `,
                  )
                  .join("")
            : '<tr><td colspan="5" class="text-center text-muted">No suppliers.</td></tr>';
        updateBalanceOverview(cust, supp);
    }

    function updateBalanceOverview(customers, suppliers) {
        const customerText = document
            .getElementById("balanceCustomerSearch")
            .value.trim();
        const supplierText = document
            .getElementById("balanceSupplierSearch")
            .value.trim();
        const filters = [];
        if (customerText) filters.push(`Customer: ${customerText}`);
        if (supplierText) filters.push(`Supplier: ${supplierText}`);

        const positiveBalances = customers.filter((c) => (c.balance || 0) > 0);
        const negativeBalances = customers.filter((c) => (c.balance || 0) < 0);
        const totalCredit = positiveBalances.reduce(
            (sum, c) => sum + (parseFloat(c.balance) || 0),
            0,
        );
        const totalOutstanding = negativeBalances.reduce(
            (sum, c) => sum + Math.abs(parseFloat(c.balance) || 0),
            0,
        );
        const totalPayable = suppliers.reduce(
            (sum, s) => sum + Math.max(parseFloat(s.payable) || 0, 0),
            0,
        );
        const totalReceivable = customers.reduce(
            (sum, c) => sum + (parseFloat(c.receivable) || 0),
            0,
        );

        setText("balanceCustomerCount", String(customers.length));
        setText(
            "balanceCustomerDetail",
            customers.length
                ? `${positiveBalances.length} with credit, ${negativeBalances.length} still owing.`
                : "No customers in the current balance view.",
        );
        setText("balanceCreditCount", formatNum(totalCredit));
        setText(
            "balanceCreditDetail",
            positiveBalances.length
                ? `${positiveBalances.length} customer account(s) currently prepaid.`
                : "No prepaid customer balances in the visible set.",
        );
        setText("balanceOutstandingCount", formatNum(totalOutstanding));
        setText(
            "balanceOutstandingDetail",
            negativeBalances.length
                ? `${negativeBalances.length} customer account(s) are still collectible.`
                : "No outstanding customer receivables in the visible set.",
        );
        setText("balanceSupplierPayableCount", formatNum(totalPayable));
        setText(
            "balanceSupplierPayableDetail",
            suppliers.length
                ? `${suppliers.length} supplier account(s) are in the current payables view.`
                : "No suppliers in the current payable view.",
        );
        setText(
            "balancesFilterSummary",
            getFilterSummary(
                filters,
                "Showing customer receivables and supplier payables for the full list.",
            ),
        );
        setText(
            "balancesSummaryText",
            customers.length || suppliers.length
                ? `Receivable total ${formatNum(totalReceivable)} | Supplier payable total ${formatNum(totalPayable)}.`
                : "No balance data loaded yet.",
        );
        setText(
            "customerBalancesSummary",
            customers.length
                ? `${customers.length} customer account${customers.length === 1 ? "" : "s"}`
                : "No customers",
        );
        setText(
            "supplierPayablesSummary",
            suppliers.length
                ? `${suppliers.length} supplier account${suppliers.length === 1 ? "" : "s"}`
                : "No suppliers",
        );
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

    window.openFinDepositModal = function (customerId, name) {
        document.getElementById("finDepCustomerId").value = customerId;
        document.getElementById("finDepCustomerName").textContent = name;
        document.getElementById("finDepAmount").value = "";
        document.getElementById("finDepMethod").value = "";
        document.getElementById("finDepReference").value = "";
        document.getElementById("finDepNotes").value = "";
        const orderInput = document.getElementById("finDepOrderId");
        if (orderInput) orderInput.value = "";
        if (finDepOrderAc && typeof finDepOrderAc.setValue === "function") finDepOrderAc.setValue(null);
        if (typeof Autocomplete !== "undefined" && orderInput) {
            finDepOrderAc = Autocomplete.init(orderInput, {
                resource: "orders",
                searchPath: "/search",
                placeholder: "Type to search order (optional)…",
                extraParams: () => ({ customer_id: document.getElementById("finDepCustomerId")?.value || "" }),
                minChars: 0,
            });
        }
        new bootstrap.Modal(document.getElementById("finDepositModal")).show();
    };

    window.openFinPaymentModal = function (supplierId, name) {
        document.getElementById("finPaySupplierId").value = supplierId;
        document.getElementById("finPaySupplierName").textContent = name;
        document.getElementById("finPayInvoiceAmount").value = "";
        document.getElementById("finPayAmount").value = "";
        document.getElementById("finPayMarkedFull").checked = false;
        document.getElementById("finPayNotes").value = "";
        const orderInput = document.getElementById("finPayOrderId");
        if (orderInput) orderInput.value = "";
        if (finPayOrderAc && typeof finPayOrderAc.setValue === "function") finPayOrderAc.setValue(null);
        if (typeof Autocomplete !== "undefined" && orderInput) {
            finPayOrderAc = Autocomplete.init(orderInput, {
                resource: "orders",
                searchPath: "/search",
                placeholder: "Type to search order (optional)…",
                extraParams: () => ({ supplier_id: document.getElementById("finPaySupplierId")?.value || "" }),
                minChars: 0,
            });
        }
        new bootstrap.Modal(document.getElementById("finPaymentModal")).show();
    };

    window.submitFinDeposit = async function () {
        const customerId = document.getElementById("finDepCustomerId").value;
        const amount = parseFloat(document.getElementById("finDepAmount").value || 0);
        if (amount <= 0) {
            alert("Amount must be positive");
            return;
        }
        const orderVal = (finDepOrderAc?.getSelectedId?.() || document.getElementById("finDepOrderId").value?.trim() || "").replace(/^#/, "");
        const orderId = orderVal && /^\d+$/.test(String(orderVal)) ? parseInt(orderVal, 10) : null;
        const payload = {
            amount,
            currency: document.getElementById("finDepCurrency").value,
            payment_method: document.getElementById("finDepMethod").value || null,
            reference_no: document.getElementById("finDepReference").value || null,
            notes: document.getElementById("finDepNotes").value || null,
            order_id: orderId,
        };
        const btn = document.getElementById("finDepSubmitBtn");
        try {
            btn.disabled = true;
            await api("/customers/" + customerId + "/deposits", { method: "POST", body: JSON.stringify(payload) });
            if (typeof showToast === "function") showToast("Deposit recorded");
            else alert("Deposit recorded");
            bootstrap.Modal.getInstance(document.getElementById("finDepositModal")).hide();
            loadBalances();
        } catch (e) {
            alert(e.message || "Failed to record deposit");
        } finally {
            btn.disabled = false;
        }
    };

    window.submitFinPayment = async function () {
        const supplierId = document.getElementById("finPaySupplierId").value;
        const amount = parseFloat(document.getElementById("finPayAmount").value || 0);
        if (amount <= 0) {
            alert("Amount must be positive");
            return;
        }
        const orderVal = (finPayOrderAc?.getSelectedId?.() || document.getElementById("finPayOrderId").value?.trim() || "").replace(/^#/, "");
        const orderId = orderVal && /^\d+$/.test(String(orderVal)) ? parseInt(orderVal, 10) : null;
        const invoiceAmount = document.getElementById("finPayInvoiceAmount").value?.trim();
        const payload = {
            amount,
            currency: document.getElementById("finPayCurrency").value,
            order_id: orderId,
            notes: document.getElementById("finPayNotes").value || null,
            marked_full_payment: document.getElementById("finPayMarkedFull").checked ? 1 : 0,
        };
        if (invoiceAmount) payload.invoice_amount = parseFloat(invoiceAmount);
        const btn = document.getElementById("finPaySubmitBtn");
        try {
            btn.disabled = true;
            await api("/suppliers/" + supplierId + "/payments", { method: "POST", body: JSON.stringify(payload) });
            if (typeof showToast === "function") showToast("Payment recorded");
            else alert("Payment recorded");
            bootstrap.Modal.getInstance(document.getElementById("finPaymentModal")).hide();
            loadBalances();
        } catch (e) {
            alert(e.message || "Failed to record payment");
        } finally {
            btn.disabled = false;
        }
    };

    function activateTabFromHash() {
        const hash = (window.location.hash || "").replace("#", "");
        if (hash !== "balances") return;
        const balanceTab = document.getElementById("balances-tab");
        if (balanceTab && typeof bootstrap !== "undefined") {
            bootstrap.Tab.getOrCreateInstance(balanceTab).show();
        }
    }

    function attachSubmitOnEnter(id, callback) {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                callback();
            }
        });
    }

    document.getElementById("profit-tab")?.addEventListener("shown.bs.tab", () => {
        window.history.replaceState(null, "", "#profit");
    });
    document
        .getElementById("balances-tab")
        ?.addEventListener("shown.bs.tab", () => {
            window.history.replaceState(null, "", "#balances");
            if (!balancesLoadedOnce) loadBalances();
        });

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
        attachSubmitOnEnter("profitDateFrom", loadProfit);
        attachSubmitOnEnter("profitDateTo", loadProfit);
        attachSubmitOnEnter("profitCustomerSearch", loadProfit);
        attachSubmitOnEnter("profitSupplierSearch", loadProfit);
        attachSubmitOnEnter("balanceCustomerSearch", loadBalances);
        attachSubmitOnEnter("balanceSupplierSearch", loadBalances);
        document.querySelectorAll(".profit-status-filter").forEach((el) => {
            el.addEventListener("change", () => {
                updateProfitStatusSummary();
                loadProfit();
            });
        });
        document
            .getElementById("profitStatusMode")
            ?.addEventListener("change", () => {
                updateProfitStatusSummary();
                if (getSelectedProfitStatuses().length) loadProfit();
            });
        updateProfitStatusSummary();
        activateTabFromHash();
        loadProfit();
        loadBalances();
    });
})();
