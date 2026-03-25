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
    const PROFIT_DEFAULT_EXCLUDED_STATUSES = [
        "Draft",
        "CustomerDeclined",
        "CustomerDeclinedAfterAutoConfirm",
    ];

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

    function getCurrencyBucket(row, currency) {
        return row?.currencies?.[currency] || {};
    }

    function renderBalanceBreakdown(row, fields, positiveField = null) {
        const currency = fields.currency;
        const bucket = getCurrencyBucket(row, currency);
        const primary = parseFloat(bucket[fields.primary] || 0);
        const secondary = parseFloat(bucket[fields.secondary] || 0);
        const tertiary =
            fields.tertiary != null
                ? parseFloat(bucket[fields.tertiary] || 0)
                : null;
        const primaryClass =
            positiveField && positiveField === fields.primary && primary > 0
                ? "text-success"
                : positiveField && positiveField === fields.primary && primary < 0
                  ? "text-danger"
                  : "";
        return `
            <div class="small">
                <div><strong class="${primaryClass}">${fields.signed ? formatSignedNum(primary) : formatNum(primary)}</strong> ${escapeHtml(currency)}</div>
                <div class="text-muted">${escapeHtml(fields.secondaryLabel)} ${formatNum(secondary)}</div>
                ${
                    tertiary !== null
                        ? `<div class="text-muted">${escapeHtml(fields.tertiaryLabel)} ${formatNum(tertiary)}</div>`
                        : ""
                }
            </div>
        `;
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
                "Default scope: all except Draft and declined orders.";
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
                "Showing the default finance scope: all except Draft and declined orders.",
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
                <td>${renderBalanceBreakdown(c, { currency: "USD", primary: "balance", secondary: "deposits", tertiary: "receivable", secondaryLabel: "Deposits", tertiaryLabel: "Receivable", signed: true }, "balance")}</td>
                <td>${renderBalanceBreakdown(c, { currency: "RMB", primary: "balance", secondary: "deposits", tertiary: "receivable", secondaryLabel: "Deposits", tertiaryLabel: "Receivable", signed: true }, "balance")}</td>
                <td><button class="btn btn-sm btn-outline-primary" onclick="openFinDepositModal(${c.id}, '${nameEsc(c.name || c.code)}')">Record Deposit</button></td>
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
                <td>${renderBalanceBreakdown(s, { currency: "USD", primary: "payable", secondary: "invoiced", tertiary: "settlement_delta", secondaryLabel: "Invoiced", tertiaryLabel: "Settlement delta", signed: false })}</td>
                <td>${renderBalanceBreakdown(s, { currency: "RMB", primary: "payable", secondary: "invoiced", tertiary: "settlement_delta", secondaryLabel: "Invoiced", tertiaryLabel: "Settlement delta", signed: false })}</td>
                <td><button class="btn btn-sm btn-outline-success" onclick="openFinPaymentModal(${s.id}, '${nameEsc(s.name || s.code)}')">Record Payment</button></td>
            </tr>
        `,
                  )
                  .join("")
            : '<tr><td colspan="4" class="text-center text-muted">No suppliers.</td></tr>';
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

        const customerCurrencyTotals = { USD: { credit: 0, outstanding: 0, receivable: 0 }, RMB: { credit: 0, outstanding: 0, receivable: 0 } };
        const supplierCurrencyTotals = { USD: { payable: 0 }, RMB: { payable: 0 } };
        customers.forEach((customer) => {
            ["USD", "RMB"].forEach((currency) => {
                const bucket = getCurrencyBucket(customer, currency);
                const balance = parseFloat(bucket.balance || 0);
                const receivable = parseFloat(bucket.receivable || 0);
                customerCurrencyTotals[currency].receivable += receivable;
                if (balance > 0) {
                    customerCurrencyTotals[currency].credit += balance;
                } else if (balance < 0) {
                    customerCurrencyTotals[currency].outstanding += Math.abs(balance);
                }
            });
        });
        suppliers.forEach((supplier) => {
            ["USD", "RMB"].forEach((currency) => {
                const bucket = getCurrencyBucket(supplier, currency);
                supplierCurrencyTotals[currency].payable += Math.max(
                    parseFloat(bucket.payable || 0),
                    0,
                );
            });
        });

        setText("balanceCustomerCount", String(customers.length));
        setText(
            "balanceCustomerDetail",
            customers.length
                ? `${customers.length} customer account(s) split across RMB and USD without FX conversion.`
                : "No customers in the current balance view.",
        );
        setText(
            "balanceCreditCount",
            `USD ${formatNum(customerCurrencyTotals.USD.credit)} | RMB ${formatNum(customerCurrencyTotals.RMB.credit)}`,
        );
        setText(
            "balanceCreditDetail",
            customers.length
                ? "Prepaid customer credit is shown separately by currency."
                : "No prepaid customer balances in the visible set.",
        );
        setText(
            "balanceOutstandingCount",
            `USD ${formatNum(customerCurrencyTotals.USD.outstanding)} | RMB ${formatNum(customerCurrencyTotals.RMB.outstanding)}`,
        );
        setText(
            "balanceOutstandingDetail",
            customers.length
                ? "Outstanding customer exposure is tracked per currency."
                : "No outstanding customer receivables in the visible set.",
        );
        setText(
            "balanceSupplierPayableCount",
            `USD ${formatNum(supplierCurrencyTotals.USD.payable)} | RMB ${formatNum(supplierCurrencyTotals.RMB.payable)}`,
        );
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
                ? `Receivable USD ${formatNum(customerCurrencyTotals.USD.receivable)} / RMB ${formatNum(customerCurrencyTotals.RMB.receivable)} | Payables USD ${formatNum(supplierCurrencyTotals.USD.payable)} / RMB ${formatNum(supplierCurrencyTotals.RMB.payable)}.`
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
        document.getElementById("finPayChannel").value = "";
        document.getElementById("finPayMarkedFull").checked = false;
        document.getElementById("finPayNotes").value = "";
        document.getElementById("finPaySettlementNote").value = "";
        document
            .getElementById("finPaySettlementNoteWrap")
            .classList.add("d-none");
        document
            .getElementById("finPaySettlementPreview")
            .classList.add("d-none");
        document.getElementById("finPaySupplierContext").textContent =
            "Loading supplier payment options…";
        document.getElementById("finPayOrdersBody").innerHTML =
            '<tr><td colspan="6" class="text-center text-muted py-3">Loading order context…</td></tr>';
        document.getElementById("finPayOrdersSummary").textContent =
            "Loading order context…";
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
        Promise.all([
            api("/suppliers/" + supplierId),
            api("/orders?supplier_id=" + supplierId),
        ])
            .then(([supplierRes, ordersRes]) => {
                const supplier = supplierRes.data || {};
                const facility = supplier.payment_facility_days
                    ? `Facility ${supplier.payment_facility_days} day(s)`
                    : "No payment facility saved";
                const links = (supplier.payment_links || [])
                    .map((row) => `${row.label || "Payment"}: ${row.value || "—"}`)
                    .join(" | ");
                document.getElementById("finPaySupplierContext").textContent =
                    `${facility}${links ? " | " + links : ""}`;
                renderFinSupplierOrders(ordersRes.data || []);
            })
            .catch(() => {
                document.getElementById("finPaySupplierContext").textContent =
                    "Could not load supplier payment options.";
                document.getElementById("finPayOrdersBody").innerHTML =
                    '<tr><td colspan="6" class="text-center text-muted py-3">Could not load supplier orders.</td></tr>';
            });
        new bootstrap.Modal(document.getElementById("finPaymentModal")).show();
    };

    function calcOrderSellTotal(order) {
        return (order.items || []).reduce((sum, item) => {
            const quantity = parseFloat(item.quantity || 0) || 0;
            const sellPrice =
                item.sell_price != null
                    ? parseFloat(item.sell_price || 0)
                    : parseFloat(item.unit_price || 0);
            const totalAmount = parseFloat(item.total_amount || 0) || 0;
            return sum + (sellPrice > 0 ? quantity * sellPrice : totalAmount);
        }, 0);
    }

    function renderFinSupplierOrders(orders) {
        const tbody = document.getElementById("finPayOrdersBody");
        const summary = document.getElementById("finPayOrdersSummary");
        if (!tbody || !summary) return;
        if (!orders.length) {
            tbody.innerHTML =
                '<tr><td colspan="6" class="text-center text-muted py-3">No supplier-linked orders found.</td></tr>';
            summary.textContent = "No supplier-linked orders";
            return;
        }
        const orderRows = [...orders].sort((a, b) => {
            const aPending = ["Draft", "Submitted", "Approved", "InTransitToWarehouse", "ReceivedAtWarehouse", "Confirmed", "ReadyForConsolidation", "ConsolidatedIntoShipmentDraft", "AssignedToContainer"].includes(a.status);
            const bPending = ["Draft", "Submitted", "Approved", "InTransitToWarehouse", "ReceivedAtWarehouse", "Confirmed", "ReadyForConsolidation", "ConsolidatedIntoShipmentDraft", "AssignedToContainer"].includes(b.status);
            if (aPending !== bPending) return aPending ? -1 : 1;
            return (b.id || 0) - (a.id || 0);
        });
        tbody.innerHTML = orderRows
            .map((order) => {
                const total = calcOrderSellTotal(order);
                const state =
                    ["FinalizedAndPushedToTracking", "CustomerDeclined", "CustomerDeclinedAfterAutoConfirm"].includes(order.status)
                        ? "Closed / historical"
                        : "Open / review";
                return `
                    <tr>
                        <td><a href="/cargochina/orders.php?id=${order.id}">#${order.id}</a><div class="small text-muted">${escapeHtml(order.customer_name || "")}</div></td>
                        <td>${getStatusBadge(order.status || "")}</td>
                        <td>${escapeHtml(order.expected_ready_date || "—")}</td>
                        <td>${escapeHtml(order.currency || "USD")} ${formatNum(total)}</td>
                        <td>${escapeHtml(state)}</td>
                        <td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="openFinOrderInfo(${order.id})">Info</button></td>
                    </tr>
                `;
            })
            .join("");
        const openCount = orderRows.filter(
            (order) =>
                !["FinalizedAndPushedToTracking", "CustomerDeclined", "CustomerDeclinedAfterAutoConfirm"].includes(order.status),
        ).length;
        summary.textContent = `${openCount} open / ${orderRows.length - openCount} historical`;
    }

    window.openFinOrderInfo = async function (orderId) {
        document.getElementById("finOrderInfoTitle").textContent =
            `Order #${orderId}`;
        document.getElementById("finOrderInfoBody").innerHTML =
            '<div class="text-center py-4 text-muted">Loading order details…</div>';
        const modal = new bootstrap.Modal(
            document.getElementById("finOrderInfoModal"),
        );
        modal.show();
        try {
            const res = await api("/orders/" + orderId);
            const order = res.data || {};
            const attachments = (order.attachments || [])
                .map(
                    (attachment) =>
                        `<a class="btn btn-sm btn-outline-secondary me-2 mb-2" target="_blank" rel="noopener" href="/cargochina/backend/${escapeHtml(attachment.file_path || "")}">${escapeHtml((attachment.file_path || "").split("/").pop() || "Attachment")}</a>`,
                )
                .join("");
            const photos = (order.receipt?.photos || [])
                .map(
                    (photo) =>
                        `<img src="/cargochina/backend/${escapeHtml(photo.file_path || "")}" alt="Receipt evidence" class="img-thumbnail me-2 mb-2" style="max-width:120px;">`,
                )
                .join("");
            document.getElementById("finOrderInfoBody").innerHTML = `
                <div class="row g-3">
                    <div class="col-lg-4">
                        <div class="border rounded p-3 h-100">
                            <div><strong>Customer:</strong> ${escapeHtml(order.customer_name || "—")}</div>
                            <div><strong>Supplier:</strong> ${escapeHtml(order.supplier_name || "—")}</div>
                            <div><strong>Status:</strong> ${getStatusBadge(order.status || "")}</div>
                            <div><strong>Expected Ready:</strong> ${escapeHtml(order.expected_ready_date || "—")}</div>
                            <div><strong>Destination:</strong> ${escapeHtml(order.destination_country_name || "—")}</div>
                            <div><strong>High Alert:</strong> ${escapeHtml(order.high_alert_notes || "—")}</div>
                        </div>
                    </div>
                    <div class="col-lg-8">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold mb-2">Items</div>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead><tr><th>Item</th><th>Shipping</th><th>Qty</th><th>Sell</th></tr></thead>
                                    <tbody>
                                        ${(order.items || [])
                                            .map(
                                                (item) => `
                                            <tr>
                                                <td>${escapeHtml(item.description_en || item.description_cn || "—")}</td>
                                                <td class="small text-muted">${escapeHtml(item.item_no || item.shipping_code || "—")}</td>
                                                <td>${escapeHtml(String(item.quantity || item.cartons || "0"))}</td>
                                                <td>${escapeHtml(order.currency || "USD")} ${formatNum(item.total_amount || (parseFloat(item.quantity || 0) * parseFloat(item.sell_price || item.unit_price || 0)))}</td>
                                            </tr>`,
                                            )
                                            .join("") || '<tr><td colspan="4" class="text-muted">No items.</td></tr>'}
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-3">
                                <div class="fw-semibold mb-2">Attachments</div>
                                ${attachments || '<div class="text-muted small">No order attachments.</div>'}
                            </div>
                            <div class="mt-3">
                                <div class="fw-semibold mb-2">Receipt Photos</div>
                                ${photos || '<div class="text-muted small">No receipt photos.</div>'}
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } catch (error) {
            document.getElementById("finOrderInfoBody").innerHTML =
                `<div class="alert alert-danger mb-0">${escapeHtml(error.message || "Failed to load order details")}</div>`;
        }
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
            payment_channel:
                document.getElementById("finPayChannel").value || null,
            order_id: orderId,
            notes: document.getElementById("finPayNotes").value || null,
            marked_full_payment: document.getElementById("finPayMarkedFull").checked ? 1 : 0,
        };
        if (invoiceAmount) payload.invoice_amount = parseFloat(invoiceAmount);
        if (
            payload.marked_full_payment &&
            payload.invoice_amount &&
            amount < payload.invoice_amount
        ) {
            payload.settlement_mode = "fully_settled_by_agreement";
            payload.settlement_note =
                document.getElementById("finPaySettlementNote").value || null;
        }
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
        document
            .getElementById("finPayInvoiceAmount")
            ?.addEventListener("input", updateFinSettlementPreview);
        document
            .getElementById("finPayAmount")
            ?.addEventListener("input", updateFinSettlementPreview);
        document
            .getElementById("finPayMarkedFull")
            ?.addEventListener("change", updateFinSettlementPreview);
        activateTabFromHash();
        loadProfit();
        loadBalances();
    });

    function updateFinSettlementPreview() {
        const invoiceAmount = parseFloat(
            document.getElementById("finPayInvoiceAmount")?.value || 0,
        );
        const amount = parseFloat(
            document.getElementById("finPayAmount")?.value || 0,
        );
        const markedFull =
            document.getElementById("finPayMarkedFull")?.checked || false;
        const preview = document.getElementById("finPaySettlementPreview");
        const noteWrap = document.getElementById("finPaySettlementNoteWrap");
        if (!preview || !noteWrap) return;
        if (invoiceAmount > 0 && amount > 0 && invoiceAmount > amount) {
            const delta = invoiceAmount - amount;
            preview.textContent = markedFull
                ? `Settlement delta ${formatNum(delta)} will be stored explicitly and this payable will be closed as fully settled by agreement.`
                : `Short-paid amount ${formatNum(delta)} will remain payable unless you mark this as fully settled.`;
            preview.classList.remove("d-none");
            noteWrap.classList.toggle("d-none", !markedFull);
            return;
        }
        preview.classList.add("d-none");
        noteWrap.classList.add("d-none");
    }
})();
