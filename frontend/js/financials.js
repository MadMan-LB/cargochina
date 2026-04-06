/**
 * Financials page - profit, balances
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    function financialsT(text, replacements = null) {
        return typeof t === "function" ? t(text, replacements) : text;
    }
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
        const method = String(opts.method || "GET").toUpperCase();
        let body = opts.body ?? null;
        if (typeof body === "string" && body.trim() !== "") {
            try {
                body = JSON.parse(body);
            } catch (_) {
            }
        }
        if (typeof window.api === "function") {
            return window.api(method, path, body);
        }
        const fetchOpts = { credentials: "same-origin", method };
        if (body && (method === "POST" || method === "PUT")) {
            fetchOpts.headers = { "Content-Type": "application/json" };
            fetchOpts.body = JSON.stringify(body);
        }
        const r = await fetch(API + path, fetchOpts);
        const d = await r.json().catch(() => ({}));
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

    function getFinancialSupplierDisplay(order) {
        return String(
            order?.supplier_name_display ||
                order?.item_supplier_names ||
                order?.supplier_name ||
                "—",
        ).trim();
    }

    function getFinancialOrderDisplayRows(order) {
        return (order?.items || []).flatMap((item) => {
            const sharedContents = Array.isArray(item?.shared_carton_contents)
                ? item.shared_carton_contents
                : [];
            if (!item?.shared_carton_enabled || sharedContents.length === 0) {
                return [
                    {
                        description:
                            item?.description_en ||
                            item?.description_cn ||
                            "—",
                        itemNo: item?.item_no || item?.shipping_code || "—",
                        quantity: item?.quantity || item?.cartons || "0",
                        sellPrice:
                            item?.sell_price != null
                                ? item.sell_price
                                : item?.unit_price,
                        totalAmount:
                            item?.total_amount != null
                                ? item.total_amount
                                : (parseFloat(item?.quantity || 0) || 0) *
                                  (parseFloat(
                                      item?.sell_price ?? item?.unit_price ?? 0,
                                  ) || 0),
                        supplierName: getFinancialSupplierDisplay(item),
                        isSummary: false,
                    },
                ];
            }

            return [
                {
                    description:
                        financialsT("Shared carton") +
                        (item?.shared_carton_code
                            ? ` ${item.shared_carton_code}`
                            : item?.item_no
                              ? ` ${item.item_no}`
                              : ""),
                    itemNo: item?.shared_carton_code || item?.item_no || "—",
                    quantity: item?.quantity || item?.cartons || "0",
                    sellPrice: null,
                    totalAmount: null,
                    supplierName: getFinancialSupplierDisplay(item),
                    isSummary: true,
                },
                ...sharedContents.map((content) => ({
                    description:
                        content?.description_en ||
                        content?.description_cn ||
                        "—",
                    itemNo: content?.item_no || item?.item_no || "—",
                    quantity: content?.quantity || "0",
                    sellPrice:
                        content?.sell_price != null
                            ? content.sell_price
                            : content?.unit_price,
                    totalAmount: content?.total_amount,
                    supplierName: String(content?.supplier_name || "—").trim(),
                    isSummary: false,
                })),
            ];
        });
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
            summaryEl.textContent = financialsT(
                "Default scope: all except Draft and declined orders.",
            );
            return;
        }
        const labels = formatProfitStatusLabels(statuses);
        summaryEl.textContent =
            getProfitStatusMode() === "exclude"
                ? financialsT("Default scope plus exclude: {labels}.", {
                      labels,
                  })
                : financialsT("Include: {labels}.", { labels });
    }

    function renderLoadingRows(bodyId, cols, message) {
        const tbody = document.getElementById(bodyId);
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="${cols}" class="text-center text-muted py-4">${escapeHtml(message || financialsT("Loading…"))}</td></tr>`;
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
                financialsT("Ready date {from} → {to}", {
                    from: df || "any",
                    to: dt || "any",
                }),
            );
        }
        if (customerText) {
            filters.push(
                financialsT("Customer: {customer}", {
                    customer: customerText,
                }),
            );
        }
        if (supplierText) {
            filters.push(
                financialsT("Supplier: {supplier}", {
                    supplier: supplierText,
                }),
            );
        }
        if (statuses.length) {
            filters.push(
                getProfitStatusMode() === "exclude"
                    ? financialsT("Default scope plus exclude: {labels}.", {
                          labels: formatProfitStatusLabels(statuses),
                      })
                    : financialsT("Include: {labels}.", {
                          labels: formatProfitStatusLabels(statuses),
                      }),
            );
        }

        setText("profitOrderCount", String(rows.length));
        setText(
            "profitOrderDetail",
            rows.length === 1
                ? financialsT("{count} order matches the current filters.", {
                      count: rows.length,
                  })
                : financialsT("{count} orders match the current filters.", {
                      count: rows.length,
                  }),
        );
        setText("profitGrossCount", formatNum(summary?.gross_profit || 0));
        setText(
            "profitGrossDetail",
            financialsT("Sell {sell} minus buy {buy}.", {
                sell: formatNum(summary?.total_sell || 0),
                buy: formatNum(summary?.total_buy || 0),
            }),
        );
        setText("profitNetCount", formatNum(summary?.net_profit || 0));
        setText(
            "profitNetDetail",
            financialsT("After {commission} commission.", {
                commission: formatNum(summary?.total_commission || 0),
            }),
        );
        setText(
            "profitCommissionCount",
            formatNum(summary?.total_commission || 0),
        );
        setText(
            "profitCommissionDetail",
            summary?.total_commission
                ? financialsT(
                      "Commission is being deducted from the visible margin.",
                  )
                : financialsT(
                      "No commission impact in the current result set.",
                  ),
        );
        setText(
            "profitFilterSummary",
            getFilterSummary(
                filters,
                financialsT(
                    "Showing the default finance scope: all except Draft and declined orders.",
                ),
            ),
        );
        setText(
            "profitTableSummary",
            rows.length
                ? financialsT(
                      rows.length === 1
                          ? "{count} order in view"
                          : "{count} orders in view",
                      { count: rows.length },
                  )
                : financialsT("No matching orders"),
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
                : financialsT("No expenses recorded for the visible period."),
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
            renderLoadingRows("profitTableBody", 8, financialsT("Loading profit data…"));
            setHtml(
                "profitSummary",
                `<div class="text-muted small">${escapeHtml(financialsT("Refreshing the profit summary…"))}</div>`,
            );
            const d = await api("/financials/profit?" + params.toString());
            renderProfit(d.data, d.summary);
        } catch (e) {
            alert(e.message || financialsT("Failed to load profit data"));
            renderProfit([], null);
        }
    };

    function renderProfit(rows, summary) {
        const tbody = document.getElementById("profitTableBody");
        if (!rows || rows.length === 0) {
            tbody.innerHTML =
                `<tr><td colspan="8" class="text-center text-muted py-4">${escapeHtml(financialsT("No orders in range."))}</td></tr>`;
            setHtml(
                "profitSummary",
                `<div class="text-muted small">${escapeHtml(financialsT("No profit data for the selected filters."))}</div>`,
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
                <td>${escapeHtml(getFinancialSupplierDisplay(r))}</td>
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
                financialsT("Loading customer balances…"),
            );
            renderLoadingRows(
                "supplierPayablesBody",
                5,
                financialsT("Loading supplier payables…"),
            );
            const d = await api(
                "/financials/balances" +
                    (params.toString() ? "?" + params.toString() : ""),
            );
            renderBalances(d.data);
            balancesLoadedOnce = true;
        } catch (e) {
            alert(e.message || financialsT("Failed to load balances"));
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
                <td><button class="btn btn-sm btn-outline-primary" onclick="openFinDepositModal(${c.id}, '${nameEsc(c.name || c.code)}')">${escapeHtml(financialsT("Record Deposit"))}</button></td>
            </tr>
        `,
                  )
                  .join("")
            : `<tr><td colspan="4" class="text-center text-muted">${escapeHtml(financialsT("No customers."))}</td></tr>`;
        suppBody.innerHTML = supp.length
            ? supp
                  .map(
                      (s) => `
            <tr>
                <td><a href="/cargochina/suppliers.php?id=${s.id}">${escapeHtml(s.name || s.code)}</a></td>
                <td>${renderBalanceBreakdown(s, { currency: "USD", primary: "payable", secondary: "invoiced", tertiary: "settlement_delta", secondaryLabel: "Invoiced", tertiaryLabel: "Settlement delta", signed: false })}</td>
                <td>${renderBalanceBreakdown(s, { currency: "RMB", primary: "payable", secondary: "invoiced", tertiary: "settlement_delta", secondaryLabel: "Invoiced", tertiaryLabel: "Settlement delta", signed: false })}</td>
                <td><button class="btn btn-sm btn-outline-success" onclick="openFinPaymentModal(${s.id}, '${nameEsc(s.name || s.code)}')">${escapeHtml(financialsT("Record Payment"))}</button></td>
            </tr>
        `,
                  )
                  .join("")
            : `<tr><td colspan="4" class="text-center text-muted">${escapeHtml(financialsT("No suppliers."))}</td></tr>`;
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
        if (customerText) {
            filters.push(
                financialsT("Customer: {customer}", {
                    customer: customerText,
                }),
            );
        }
        if (supplierText) {
            filters.push(
                financialsT("Supplier: {supplier}", {
                    supplier: supplierText,
                }),
            );
        }

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
                ? financialsT(
                      "{count} customer account(s) split across RMB and USD without FX conversion.",
                      { count: customers.length },
                  )
                : financialsT("No customers in the current balance view."),
        );
        setText(
            "balanceCreditCount",
            `USD ${formatNum(customerCurrencyTotals.USD.credit)} | RMB ${formatNum(customerCurrencyTotals.RMB.credit)}`,
        );
        setText(
            "balanceCreditDetail",
            customers.length
                ? financialsT(
                      "Prepaid customer credit is shown separately by currency.",
                  )
                : financialsT("No prepaid customer balances in the visible set."),
        );
        setText(
            "balanceOutstandingCount",
            `USD ${formatNum(customerCurrencyTotals.USD.outstanding)} | RMB ${formatNum(customerCurrencyTotals.RMB.outstanding)}`,
        );
        setText(
            "balanceOutstandingDetail",
            customers.length
                ? financialsT(
                      "Outstanding customer exposure is tracked per currency.",
                  )
                : financialsT(
                      "No outstanding customer receivables in the visible set.",
                  ),
        );
        setText(
            "balanceSupplierPayableCount",
            `USD ${formatNum(supplierCurrencyTotals.USD.payable)} | RMB ${formatNum(supplierCurrencyTotals.RMB.payable)}`,
        );
        setText(
            "balanceSupplierPayableDetail",
            suppliers.length
                ? financialsT(
                      "{count} supplier account(s) are in the current payables view.",
                      { count: suppliers.length },
                  )
                : financialsT("No suppliers in the current payable view."),
        );
        setText(
            "balancesFilterSummary",
            getFilterSummary(
                filters,
                financialsT(
                    "Showing customer receivables and supplier payables for the full list.",
                ),
            ),
        );
        setText(
            "balancesSummaryText",
            customers.length || suppliers.length
                ? financialsT(
                      "Receivable USD {usdReceivable} / RMB {rmbReceivable} | Payables USD {usdPayable} / RMB {rmbPayable}.",
                      {
                          usdReceivable: formatNum(
                              customerCurrencyTotals.USD.receivable,
                          ),
                          rmbReceivable: formatNum(
                              customerCurrencyTotals.RMB.receivable,
                          ),
                          usdPayable: formatNum(
                              supplierCurrencyTotals.USD.payable,
                          ),
                          rmbPayable: formatNum(
                              supplierCurrencyTotals.RMB.payable,
                          ),
                      },
                  )
                : financialsT("No balance data loaded yet."),
        );
        setText(
            "customerBalancesSummary",
            customers.length
                ? financialsT(
                      customers.length === 1
                          ? "{count} customer account"
                          : "{count} customer accounts",
                      { count: customers.length },
                  )
                : financialsT("No customers"),
        );
        setText(
            "supplierPayablesSummary",
            suppliers.length
                ? financialsT(
                      suppliers.length === 1
                          ? "{count} supplier account"
                          : "{count} supplier accounts",
                      { count: suppliers.length },
                  )
                : financialsT("No suppliers"),
        );
    }

    function formatNum(n) {
        if (n == null || n === "") return "—";
        if (typeof window.formatDisplayAmount === "function") {
            return window.formatDisplayAmount(n) || "0";
        }
        return String(parseFloat(n || 0) || 0);
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

    function formatPaymentAccountText(row) {
        if (!row) return financialsT("No saved supplier account selected.");
        const method =
            row.method ||
            (typeof normalizePaymentMethodName === "function"
                ? normalizePaymentMethodName(row.label || row.type || "")
                : "") ||
            financialsT("Payment");
        const label =
            row.account_label && row.account_label !== method
                ? `${method} - ${row.account_label}`
                : method;
        const detail = row.value || "—";
        const currency = row.currency || "RMB";
        return `${label} | ${currency} | ${detail}`;
    }

    function renderPaymentLinksSummary(links) {
        const rows = Array.isArray(links) ? links : [];
        if (!rows.length) {
            return financialsT("No saved payment accounts.");
        }
        return rows.map(formatPaymentAccountText).join(" | ");
    }

    function populateFinSupplierAccountOptions(links) {
        const select = document.getElementById("finPayAccountOption");
        if (!select) return;
        const rows = Array.isArray(links) ? links : [];
        select.innerHTML = `<option value="">${escapeHtml(financialsT("Choose saved account..."))}</option>`;
        rows.forEach((row, index) => {
            const option = document.createElement("option");
            option.value = String(index);
            option.textContent = formatPaymentAccountText(row);
            option.dataset.method = row.method || "";
            option.dataset.detail = row.value || "";
            option.dataset.currency = row.currency || "RMB";
            option.dataset.qr = row.qr_image_path || "";
            option.dataset.label = row.account_label || row.label || row.method || "";
            select.appendChild(option);
        });
        if (rows.length === 1) {
            select.value = "0";
        }
        syncFinSupplierAccountSelection();
    }

    function syncFinSupplierAccountSelection() {
        const select = document.getElementById("finPayAccountOption");
        const detailInput = document.getElementById("finPayAccountDetail");
        const qrWrap = document.getElementById("finPayAccountQrWrap");
        if (!select || !detailInput || !qrWrap) return;
        const option = select.selectedOptions?.[0] || null;
        if (!option || !option.value) {
            detailInput.value = "";
            qrWrap.classList.add("d-none");
            qrWrap.innerHTML = "";
            return;
        }
        const method = option.dataset.method || "";
        const currency = option.dataset.currency || "RMB";
        const detail = option.dataset.detail || "";
        detailInput.value = detail;
        const channelEl = document.getElementById("finPayChannel");
        const currencyEl = document.getElementById("finPayCurrency");
        if (channelEl && !channelEl.value && method) {
            channelEl.value = method;
        }
        if (currencyEl && option.dataset.currency) {
            currencyEl.value = currency;
        }
        const qrPath = option.dataset.qr || "";
        if (!qrPath) {
            qrWrap.classList.add("d-none");
            qrWrap.innerHTML = "";
            return;
        }
        qrWrap.classList.remove("d-none");
        qrWrap.innerHTML = `
            <div class="fw-semibold mb-1">${escapeHtml(financialsT("Saved QR image"))}</div>
            <img src="/cargochina/backend/${escapeHtml(qrPath)}" alt="${escapeHtml(financialsT("Payment QR"))}" class="img-thumbnail" style="max-width: 180px;">
        `;
    }

    window.openFinDepositModal = function (customerId, name) {
        document.getElementById("finDepCustomerId").value = customerId;
        document.getElementById("finDepCustomerName").textContent = name;
        document.getElementById("finDepAmount").value = "";
        document.getElementById("finDepCurrency").value = "RMB";
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
                placeholder: financialsT("Type to search order (optional)…"),
                extraParams: () => ({ customer_id: document.getElementById("finDepCustomerId")?.value || "" }),
                minChars: 0,
            });
        }
        refreshUnsavedBaseline?.(
            document.querySelector("#finDepositModal .modal-body"),
        );
        new bootstrap.Modal(document.getElementById("finDepositModal")).show();
    };

    window.openFinPaymentModal = function (supplierId, name) {
        document.getElementById("finPaySupplierId").value = supplierId;
        document.getElementById("finPaySupplierName").textContent = name;
        document.getElementById("finPayInvoiceAmount").value = "";
        document.getElementById("finPayAmount").value = "";
        document.getElementById("finPayCurrency").value = "RMB";
        document.getElementById("finPayChannel").value = "";
        document.getElementById("finPayAccountOption").innerHTML =
            `<option value="">${escapeHtml(financialsT("Choose saved account..."))}</option>`;
        document.getElementById("finPayAccountDetail").value = "";
        document.getElementById("finPayAccountQrWrap").classList.add("d-none");
        document.getElementById("finPayAccountQrWrap").innerHTML = "";
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
            financialsT("Loading supplier payment options…");
        document.getElementById("finPayOrdersBody").innerHTML =
            `<tr><td colspan="6" class="text-center text-muted py-3">${escapeHtml(financialsT("Loading order context…"))}</td></tr>`;
        document.getElementById("finPayOrdersSummary").textContent =
            financialsT("Loading order context…");
        const orderInput = document.getElementById("finPayOrderId");
        if (orderInput) orderInput.value = "";
        if (finPayOrderAc && typeof finPayOrderAc.setValue === "function") finPayOrderAc.setValue(null);
        if (typeof Autocomplete !== "undefined" && orderInput) {
            finPayOrderAc = Autocomplete.init(orderInput, {
                resource: "orders",
                searchPath: "/search",
                placeholder: financialsT("Type to search order (optional)…"),
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
                    ? financialsT("Facility {days} day(s)", {
                          days: supplier.payment_facility_days,
                      })
                    : financialsT("No payment facility saved");
                const links = renderPaymentLinksSummary(supplier.payment_links);
                document.getElementById("finPaySupplierContext").textContent =
                    `${facility}${links ? " | " + links : ""}`;
                populateFinSupplierAccountOptions(supplier.payment_links || []);
                renderFinSupplierOrders(ordersRes.data || []);
                refreshUnsavedBaseline?.(
                    document.querySelector("#finPaymentModal .modal-body"),
                );
            })
            .catch(() => {
                document.getElementById("finPaySupplierContext").textContent =
                    financialsT("Could not load supplier payment options.");
                document.getElementById("finPayOrdersBody").innerHTML =
                    `<tr><td colspan="6" class="text-center text-muted py-3">${escapeHtml(financialsT("Could not load supplier orders."))}</td></tr>`;
                refreshUnsavedBaseline?.(
                    document.querySelector("#finPaymentModal .modal-body"),
                );
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
                `<tr><td colspan="6" class="text-center text-muted py-3">${escapeHtml(financialsT("No supplier-linked orders found."))}</td></tr>`;
            summary.textContent = financialsT("No supplier-linked orders");
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
                        ? financialsT("Closed / historical")
                        : financialsT("Open / review");
                return `
                    <tr>
                        <td><a href="/cargochina/orders.php?id=${order.id}">#${order.id}</a><div class="small text-muted">${escapeHtml(order.customer_name || "")}</div></td>
                        <td>${getStatusBadge(order.status || "")}</td>
                        <td>${escapeHtml(order.expected_ready_date || "—")}</td>
                        <td>${escapeHtml(order.currency || "USD")} ${formatNum(total)}</td>
                        <td>${escapeHtml(state)}</td>
                        <td><button type="button" class="btn btn-sm btn-outline-secondary" onclick="openFinOrderInfo(${order.id})">${escapeHtml(financialsT("Info"))}</button></td>
                    </tr>
                `;
            })
            .join("");
        const openCount = orderRows.filter(
            (order) =>
                !["FinalizedAndPushedToTracking", "CustomerDeclined", "CustomerDeclinedAfterAutoConfirm"].includes(order.status),
        ).length;
        summary.textContent = financialsT("{open} open / {historical} historical", {
            open: openCount,
            historical: orderRows.length - openCount,
        });
    }

    window.openFinOrderInfo = async function (orderId) {
        document.getElementById("finOrderInfoTitle").textContent =
            financialsT("Order #{id}", { id: orderId });
        document.getElementById("finOrderInfoBody").innerHTML =
            `<div class="text-center py-4 text-muted">${escapeHtml(financialsT("Loading order details…"))}</div>`;
        const modal = new bootstrap.Modal(
            document.getElementById("finOrderInfoModal"),
        );
        modal.show();
        try {
            const res = await api("/orders/" + orderId);
            const order = res.data || {};
            const displayRows = getFinancialOrderDisplayRows(order);
            const supplierDisplay = getFinancialSupplierDisplay(order);
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
                            <div><strong>Supplier:</strong> ${escapeHtml(supplierDisplay)}</div>
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
                                        ${displayRows
                                            .map(
                                                (row) => `
                                            <tr class="${row.isSummary ? "table-light" : ""}">
                                                <td class="${row.isSummary ? "fw-semibold" : row.supplierName ? "ps-3" : ""}">${escapeHtml(row.description || "—")}</td>
                                                <td class="small text-muted">${escapeHtml(row.itemNo || "—")}</td>
                                                <td>${escapeHtml(String(row.quantity || "0"))}</td>
                                                <td>${row.totalAmount != null ? `${escapeHtml(order.currency || "USD")} ${formatNum(row.totalAmount)}` : "—"}</td>
                                            </tr>`,
                                            )
                                            .join("") || `<tr><td colspan="4" class="text-muted">${escapeHtml(financialsT("No items."))}</td></tr>`}
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
                `<div class="alert alert-danger mb-0">${escapeHtml(error.message || financialsT("Failed to load order details"))}</div>`;
        }
    };

    window.submitFinDeposit = async function () {
        const customerId = document.getElementById("finDepCustomerId").value;
        const amount = parseFloat(document.getElementById("finDepAmount").value || 0);
        if (amount <= 0) {
            showToast(financialsT("Amount must be positive"), "danger");
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
            showToast(financialsT("Deposit recorded"));
            refreshUnsavedBaseline?.(
                document.querySelector("#finDepositModal .modal-body"),
            );
            bootstrap.Modal.getInstance(document.getElementById("finDepositModal")).hide();
            loadBalances();
        } catch (e) {
            showToast(e.message || financialsT("Failed to record deposit"), "danger");
        } finally {
            btn.disabled = false;
        }
    };

    window.submitFinPayment = async function () {
        const supplierId = document.getElementById("finPaySupplierId").value;
        const amount = parseFloat(document.getElementById("finPayAmount").value || 0);
        if (amount <= 0) {
            showToast(financialsT("Amount must be positive"), "danger");
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
            payment_account_label:
                document.getElementById("finPayAccountOption")?.selectedOptions?.[0]
                    ?.dataset?.label || null,
            payment_account_value:
                document.getElementById("finPayAccountOption")?.selectedOptions?.[0]
                    ?.dataset?.detail || null,
            payment_account_qr_path:
                document.getElementById("finPayAccountOption")?.selectedOptions?.[0]
                    ?.dataset?.qr || null,
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
            showToast(financialsT("Payment recorded"));
            refreshUnsavedBaseline?.(
                document.querySelector("#finPaymentModal .modal-body"),
            );
            bootstrap.Modal.getInstance(document.getElementById("finPaymentModal")).hide();
            loadBalances();
        } catch (e) {
            showToast(e.message || financialsT("Failed to record payment"), "danger");
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

    document
        .getElementById("finPayAccountOption")
        ?.addEventListener("change", syncFinSupplierAccountSelection);

    document.addEventListener("DOMContentLoaded", function () {
        registerUnsavedChangesGuard?.("#finDepositModal .modal-body");
        registerUnsavedChangesGuard?.("#finPaymentModal .modal-body");
        profitCustomerAc = bindEntityAutocomplete(
            "profitCustomerSearch",
            "profitCustomerId",
            "customers",
            financialsT("Type to search customer..."),
        );
        profitSupplierAc = bindEntityAutocomplete(
            "profitSupplierSearch",
            "profitSupplierId",
            "suppliers",
            financialsT("Type to search supplier..."),
        );
        balanceCustomerAc = bindEntityAutocomplete(
            "balanceCustomerSearch",
            "balanceCustomerId",
            "customers",
            financialsT("Type to search customer..."),
        );
        balanceSupplierAc = bindEntityAutocomplete(
            "balanceSupplierSearch",
            "balanceSupplierId",
            "suppliers",
            financialsT("Type to search supplier..."),
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
                ? financialsT(
                      "Settlement delta {delta} will be stored explicitly and this payable will be closed as fully settled by agreement.",
                      { delta: formatNum(delta) },
                  )
                : financialsT(
                      "Short-paid amount {delta} will remain payable unless you mark this as fully settled.",
                      { delta: formatNum(delta) },
                  );
            preview.classList.remove("d-none");
            noteWrap.classList.toggle("d-none", !markedFull);
            return;
        }
        preview.classList.add("d-none");
        noteWrap.classList.add("d-none");
    }
})();
