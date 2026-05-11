/**
 * Employee Balances page.
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let balanceCustomerAc = null;
    let balanceSupplierAc = null;
    let activeDataset = "customers";
    let balanceTxnAccounts = [];

    function balancesT(text, replacements = null) {
        return typeof t === "function" ? t(text, replacements) : text;
    }

    async function api(path, opts = {}) {
        const method = String(opts.method || "GET").toUpperCase();
        let body = opts.body ?? null;
        if (typeof window.api === "function") {
            if (typeof body === "string" && body.trim() !== "") {
                body = JSON.parse(body);
            }
            return window.api(method, path, body);
        }
        const fetchOpts = { credentials: "same-origin", method };
        if (body && (method === "POST" || method === "PUT")) {
            fetchOpts.headers = { "Content-Type": "application/json" };
            fetchOpts.body = typeof body === "string" ? body : JSON.stringify(body);
        }
        const response = await fetch(API + path, fetchOpts);
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.error) {
            const error = new Error(data.message || balancesT("Request failed"));
            error.errors = data.errors || null;
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function el(id) {
        return document.getElementById(id);
    }

    function setText(id, value) {
        const node = el(id);
        if (node) node.textContent = value;
    }

    function escapeHtml(value) {
        const div = document.createElement("div");
        div.textContent = value == null ? "" : String(value);
        return div.innerHTML;
    }

    function formatAmount(value) {
        if (typeof window.formatDisplayAmount === "function") {
            return window.formatDisplayAmount(value, { minDecimals: 2 }) || "0.00";
        }
        const number = Number(value || 0);
        return number.toLocaleString(undefined, {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });
    }

    function formatCurrencyTotal(totals) {
        const usd = formatAmount(totals?.USD || 0);
        const rmb = formatAmount(totals?.RMB || 0);
        return `USD ${usd} | RMB ${rmb}`;
    }

    function formatMoney(currency, amount) {
        const value = Number(amount || 0);
        const sign = value < 0 ? "-" : "";
        return `${currency} ${sign}${formatAmount(Math.abs(value))}`;
    }

    function todayIso() {
        const date = new Date();
        date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
        return date.toISOString().slice(0, 10);
    }

    function getFilters() {
        return {
            q: el("balanceSearch")?.value.trim() || "",
            date_from: el("balanceDateFrom")?.value || "",
            date_to: el("balanceDateTo")?.value || "",
            party_type: el("balancePartyType")?.value || "",
            currency: el("balanceCurrency")?.value || "",
            payment_method: el("balancePaymentMethod")?.value || "",
            status: el("balanceStatus")?.value || "",
        };
    }

    function buildParams(forTransactions = false) {
        const filters = getFilters();
        const params = new URLSearchParams();
        Object.entries(filters).forEach(([key, value]) => {
            if (!value) return;
            if (!forTransactions && key === "payment_method") return;
            if (forTransactions && key === "status") return;
            params.set(key, value);
        });
        return params;
    }

    function renderLoading() {
        const balanceLoading = balancesT("Loading balances...");
        const transactionsLoading = balancesT("Loading transaction history...");
        setText("customerBalancesSummary", balanceLoading);
        setText("supplierBalancesSummary", balanceLoading);
        setText("transactionsSummary", transactionsLoading);
        el("customerBalancesBody").innerHTML =
            `<tr><td colspan="9" class="text-center text-muted py-4">${escapeHtml(balanceLoading)}</td></tr>`;
        el("supplierBalancesBody").innerHTML =
            `<tr><td colspan="9" class="text-center text-muted py-4">${escapeHtml(balanceLoading)}</td></tr>`;
        el("transactionsBody").innerHTML =
            `<tr><td colspan="11" class="text-center text-muted py-4">${escapeHtml(transactionsLoading)}</td></tr>`;
    }

    function statusBadge(status, label) {
        const translated = balancesT(label || status || "Settled");
        const cls =
            status === "due"
                ? "bg-danger-subtle text-danger"
                : status === "credit"
                  ? "bg-success-subtle text-success"
                  : "bg-secondary-subtle text-secondary";
        return `<span class="badge ${cls}">${escapeHtml(translated)}</span>`;
    }

    function transactionTypeLabel(type) {
        const labels = {
            deposit: "Deposit",
            payment_received: "Payment Received",
            payment_sent: "Payment Sent",
            adjustment: "Adjustment",
            refund: "Refund",
            other: "Other",
        };
        return balancesT(labels[type] || type || "Other");
    }

    function partyTypeLabel(type) {
        return balancesT(type === "supplier" ? "Supplier" : "Customer");
    }

    function normalizeAccountLabel(row) {
        if (!row) return balancesT("Account");
        const label = row.account_label || row.label || row.name || row.method || balancesT("Account");
        const method = row.method && row.method !== label ? row.method : "";
        const currency = row.currency || "";
        const detail = row.value || row.account_value || row.link || "";
        return [method || label, currency, detail].filter(Boolean).join(" - ");
    }

    function selectedAccountOption() {
        return el("balanceTxnAccountOption")?.selectedOptions?.[0] || null;
    }

    function clearBalanceAccountOptions(message = "Choose saved account...") {
        balanceTxnAccounts = [];
        const select = el("balanceTxnAccountOption");
        if (!select) return;
        select.innerHTML = `<option value="">${escapeHtml(balancesT(message))}</option>`;
        el("balanceTxnAccountDetail").value = "";
        setText("balanceTxnAccountHelp", balancesT("Choose a saved account or type a new account number."));
    }

    function syncBalanceAccountSelection() {
        const option = selectedAccountOption();
        if (!option || option.value === "") return;
        el("balanceTxnAccountDetail").value = option.dataset.value || "";
        if (option.dataset.method) {
            const methodEl = el("balanceTxnMethod");
            if (methodEl && Array.from(methodEl.options).some((o) => o.value === option.dataset.method)) {
                methodEl.value = option.dataset.method;
            }
        }
        if (option.dataset.currency) {
            el("balanceTxnCurrency").value = option.dataset.currency;
        }
    }

    async function loadBalancePartyAccounts(partyType, partyId) {
        if (!partyId) {
            clearBalanceAccountOptions();
            return;
        }
        const select = el("balanceTxnAccountOption");
        if (select) {
            select.innerHTML = `<option value="">${escapeHtml(balancesT("Loading saved accounts..."))}</option>`;
        }
        try {
            const params = new URLSearchParams({ party_type: partyType, party_id: String(partyId) });
            const response = await api("/balances/accounts?" + params.toString());
            balanceTxnAccounts = Array.isArray(response.data) ? response.data : [];
            if (!select) return;
            select.innerHTML = `<option value="">${escapeHtml(balancesT("Choose saved account..."))}</option>`;
            balanceTxnAccounts.forEach((row, index) => {
                const option = document.createElement("option");
                option.value = String(index);
                option.dataset.label = row.account_label || row.label || row.name || row.method || "";
                option.dataset.method = row.method || "";
                option.dataset.value = row.value || row.account_value || row.link || "";
                option.dataset.currency = row.currency || "";
                option.dataset.qr = row.qr_image_path || "";
                option.textContent = normalizeAccountLabel(row);
                select.appendChild(option);
            });
            if (balanceTxnAccounts.length === 1) {
                select.value = "0";
                syncBalanceAccountSelection();
            }
            setText(
                "balanceTxnAccountHelp",
                balanceTxnAccounts.length
                    ? balancesT("Choose a saved account or type a new account number.")
                    : balancesT("No saved accounts. Type an account number to save it with this party."),
            );
        } catch (error) {
            clearBalanceAccountOptions();
            showToast(error.message || balancesT("Failed to load saved accounts"), "warning");
        }
    }

    function renderBalanceRows(rows, bodyId, summaryId, emptyMessage, partyType) {
        const body = el(bodyId);
        const safeRows = Array.isArray(rows) ? rows : [];
        setText(
            summaryId,
            safeRows.length
                ? balancesT("{count} balance row(s)", { count: safeRows.length })
                : balancesT("No rows"),
        );
        if (!safeRows.length) {
            body.innerHTML = `<tr><td colspan="9" class="text-center text-muted py-4">${escapeHtml(emptyMessage)}</td></tr>`;
            return;
        }
        body.innerHTML = safeRows
            .map((row) => {
                const balanceClass =
                    Number(row.current_balance || 0) > 0
                        ? "text-danger"
                        : Number(row.current_balance || 0) < 0
                          ? "text-success"
                          : "text-secondary";
                const name = row.name || row.code || `#${row.id}`;
                const encodedName = encodeURIComponent(name);
                return `
                    <tr>
                        <td>
                            <a data-no-translate href="/cargochina/${partyType === "customer" ? "customers" : "suppliers"}.php?id=${encodeURIComponent(row.id)}">${escapeHtml(name)}</a>
                            <div class="small text-muted" data-no-translate>${escapeHtml(row.code || "")}</div>
                        </td>
                        <td data-no-translate>${escapeHtml(row.phone || "-")}</td>
                        <td>${escapeHtml(row.currency || "")}</td>
                        <td><span class="balance-amount ${balanceClass}">${escapeHtml(formatMoney(row.currency, row.current_balance))}</span></td>
                        <td>${escapeHtml(formatMoney(row.currency, row.total_paid))}</td>
                        <td>${escapeHtml(formatMoney(row.currency, row.total_due))}</td>
                        <td>${escapeHtml(row.last_payment_date || "-")}</td>
                        <td>${statusBadge(row.status, row.status_label)}</td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="openBalanceTransactionModal('${partyType}', ${Number(row.id)}, decodeURIComponent('${encodedName}'))">${escapeHtml(balancesT("Record"))}</button>
                        </td>
                    </tr>
                `;
            })
            .join("");
    }

    function renderSummary(summary) {
        setText("summaryCustomerBalances", formatCurrencyTotal(summary?.customer_balances));
        setText("summarySupplierBalances", formatCurrencyTotal(summary?.supplier_balances));
        setText("summaryReceivedToday", formatCurrencyTotal(summary?.payments_received_today));
        setText("summarySentToday", formatCurrencyTotal(summary?.payments_sent_today));
    }

    function renderOverview(data) {
        renderSummary(data?.summary || {});
        renderBalanceRows(
            data?.customers || [],
            "customerBalancesBody",
            "customerBalancesSummary",
            balancesT("No customer balances found."),
            "customer",
        );
        renderBalanceRows(
            data?.suppliers || [],
            "supplierBalancesBody",
            "supplierBalancesSummary",
            balancesT("No supplier balances found."),
            "supplier",
        );
    }

    function renderTransactions(rows) {
        const body = el("transactionsBody");
        const safeRows = Array.isArray(rows) ? rows : [];
        setText(
            "transactionsSummary",
            safeRows.length
                ? balancesT("{count} transaction(s)", { count: safeRows.length })
                : balancesT("No transactions"),
        );
        if (!safeRows.length) {
            body.innerHTML = `<tr><td colspan="11" class="text-center text-muted py-4">${escapeHtml(balancesT("No transactions found."))}</td></tr>`;
            return;
        }
        body.innerHTML = safeRows
            .map((row) => `
                <tr>
                    <td>${escapeHtml(row.transaction_date || "-")}</td>
                    <td>${escapeHtml(partyTypeLabel(row.party_type))}</td>
                    <td data-no-translate>${escapeHtml(row.party_name || "-")}</td>
                    <td>${escapeHtml(transactionTypeLabel(row.transaction_type))}</td>
                    <td><strong>${escapeHtml(formatMoney(row.currency, row.amount))}</strong></td>
                    <td>${escapeHtml(row.payment_method || "-")}</td>
                    <td data-no-translate>${escapeHtml(row.payment_account_value || "-")}</td>
                    <td data-no-translate>${row.order_id ? `<a href="/cargochina/orders.php?order_id=${encodeURIComponent(row.order_id)}">${escapeHtml(row.order_reference || "#" + row.order_id)}</a>` : "-"}</td>
                    <td data-no-translate>${escapeHtml(row.reference_number || "-")}</td>
                    <td data-no-translate>${escapeHtml(row.created_by_name || "-")}</td>
                    <td data-no-translate>${escapeHtml(row.notes || "")}</td>
                </tr>
            `)
            .join("");
    }

    function updateFilterSummary() {
        const filters = getFilters();
        const parts = [];
        if (filters.q) parts.push(balancesT("Search: {query}", { query: filters.q }));
        if (filters.date_from || filters.date_to) {
            parts.push(
                balancesT("Date {from} to {to}", {
                    from: filters.date_from || balancesT("any"),
                    to: filters.date_to || balancesT("any"),
                }),
            );
        }
        if (filters.party_type) parts.push(partyTypeLabel(filters.party_type));
        if (filters.currency) parts.push(filters.currency);
        if (filters.payment_method) parts.push(filters.payment_method);
        if (filters.status) {
            parts.push(
                balancesT(
                    filters.status === "due"
                        ? "Due"
                        : filters.status === "credit"
                          ? "Credit"
                          : "Settled",
                ),
            );
        }
        setText(
            "balanceFilterSummary",
            parts.length
                ? parts.join(" | ")
                : balancesT("Showing all current balances."),
        );
    }

    async function loadOverview() {
        const params = buildParams(false);
        const response = await api("/balances" + (params.toString() ? "?" + params.toString() : ""));
        renderOverview(response.data || {});
    }

    async function loadTransactions() {
        const params = buildParams(true);
        const response = await api(
            "/balances/transactions" + (params.toString() ? "?" + params.toString() : ""),
        );
        renderTransactions(response.data || []);
    }

    window.loadBalancePageData = async function () {
        updateFilterSummary();
        renderLoading();
        try {
            await Promise.all([loadOverview(), loadTransactions()]);
        } catch (error) {
            showToast(error.message || balancesT("Failed to load balances"), "danger");
            renderOverview({ customers: [], suppliers: [], summary: {} });
            renderTransactions([]);
        }
    };

    window.clearBalancePageFilters = function () {
        ["balanceSearch", "balanceDateFrom", "balanceDateTo"].forEach((id) => {
            if (el(id)) el(id).value = "";
        });
        ["balancePartyType", "balanceCurrency", "balancePaymentMethod", "balanceStatus"].forEach((id) => {
            if (el(id)) el(id).value = "";
        });
        loadBalancePageData();
    };

    window.exportActiveBalanceView = function () {
        const params = buildParams(activeDataset === "transactions");
        params.set("dataset", activeDataset);
        window.location.href = API + "/balances/export?" + params.toString();
    };

    function setPartyTypeVisible(shouldClear = false) {
        const partyType = el("balanceTxnPartyType")?.value || "customer";
        const customerWrap = el("balanceTxnCustomerWrap");
        const supplierWrap = el("balanceTxnSupplierWrap");
        customerWrap?.classList.toggle("d-none", partyType !== "customer");
        supplierWrap?.classList.toggle("d-none", partyType !== "supplier");
        if (shouldClear) {
            balanceCustomerAc?.setValue(null);
            balanceSupplierAc?.setValue(null);
            el("balanceTxnCustomerId").value = "";
            el("balanceTxnSupplierId").value = "";
            clearBalanceAccountOptions();
        }
        if (partyType === "customer" && el("balanceTxnType").value === "payment_sent") {
            el("balanceTxnType").value = "payment_received";
        }
        if (partyType === "supplier" && el("balanceTxnType").value === "payment_received") {
            el("balanceTxnType").value = "payment_sent";
        }
        updateDirectionDefault();
    }

    function updateDirectionDefault() {
        const partyType = el("balanceTxnPartyType")?.value || "customer";
        const txType = el("balanceTxnType")?.value || "payment_received";
        let direction = "increase_balance";
        let help = balancesT("Adjustments can increase or reduce the selected balance.");
        if (
            (partyType === "customer" && (txType === "payment_received" || txType === "deposit")) ||
            (partyType === "supplier" && (txType === "payment_sent" || txType === "deposit"))
        ) {
            direction = "reduce_balance";
            help = balancesT(txType === "deposit" ? "This deposit will reduce the selected balance." : "This payment will reduce the selected balance.");
        } else if (txType === "refund") {
            direction = partyType === "supplier" ? "reduce_balance" : "increase_balance";
            help = balancesT("Use direction to match how the refund affects the balance.");
        }
        el("balanceTxnDirection").value = direction;
        setText("balanceTxnDirectionHelp", help);
    }

    function setModalPartySelection(partyType, partyId, partyName) {
        if (!partyId) {
            clearBalanceAccountOptions();
            return;
        }
        const item = { id: partyId, name: partyName };
        if (partyType === "customer") {
            balanceCustomerAc?.setValue(item);
            el("balanceTxnCustomerId").value = String(partyId);
        } else {
            balanceSupplierAc?.setValue(item);
            el("balanceTxnSupplierId").value = String(partyId);
        }
        loadBalancePartyAccounts(partyType, partyId);
    }

    function clearBalanceTxnValidation() {
        el("balanceTxnValidationSummary")?.classList.add("d-none");
        document.querySelectorAll("#balanceTransactionModal .is-invalid").forEach((node) => {
            node.classList.remove("is-invalid");
        });
        document.querySelectorAll("#balanceTransactionModal .balance-invalid-feedback").forEach((node) => {
            node.remove();
        });
    }

    function fieldForError(key, partyType = null) {
        const map = {
            party_type: "balanceTxnPartyType",
            party_id: partyType === "supplier" ? "balanceTxnSupplierSearch" : "balanceTxnCustomerSearch",
            customer_id: "balanceTxnCustomerSearch",
            supplier_id: "balanceTxnSupplierSearch",
            amount: "balanceTxnAmount",
            currency: "balanceTxnCurrency",
            payment_method: "balanceTxnMethod",
            transaction_date: "balanceTxnDate",
            order_id: "balanceLinkedOrderWrap",
        };
        return el(map[key] || key);
    }

    function markBalanceTxnError(key, message, partyType = null) {
        const field = fieldForError(key, partyType);
        if (!field) return null;
        const target = field.classList.contains("linked-order-panel") || field.id === "balanceLinkedOrderWrap"
            ? field
            : field;
        target.classList.add("is-invalid");
        const feedback = document.createElement("div");
        feedback.className = "invalid-feedback d-block balance-invalid-feedback";
        feedback.textContent = balancesT(message);
        if (field.id === "balanceLinkedOrderWrap") {
            field.appendChild(feedback);
        } else {
            field.insertAdjacentElement("afterend", feedback);
        }
        return field;
    }

    function showBalanceTxnErrors(errors, partyType) {
        const summary = el("balanceTxnValidationSummary");
        if (summary) {
            summary.textContent = balancesT("Please complete the highlighted fields before saving.");
            summary.classList.remove("d-none");
        }
        let first = null;
        Object.entries(errors || {}).forEach(([key, message]) => {
            first = first || markBalanceTxnError(key, message, partyType);
        });
        if (first) {
            first.scrollIntoView({ behavior: "smooth", block: "center" });
            const focusable = first.matches?.("input,select,textarea") ? first : first.querySelector?.("input,select,textarea");
            setTimeout(() => focusable?.focus?.(), 250);
        }
    }

    function validateBalanceTransactionForm() {
        const partyType = el("balanceTxnPartyType").value;
        const partyId =
            partyType === "customer"
                ? el("balanceTxnCustomerId").value
                : el("balanceTxnSupplierId").value;
        const errors = {};
        if (!partyId) {
            errors.party_id = partyType === "customer" ? "Customer is required" : "Supplier is required";
        }
        const amount = Number(el("balanceTxnAmount").value || 0);
        if (amount <= 0) {
            errors.amount = "Amount must be positive";
        }
        if (!el("balanceTxnCurrency").value) {
            errors.currency = "Currency is required";
        }
        if (el("balanceTxnType").value === "deposit" && !el("balanceTxnMethod").value) {
            errors.payment_method = "Payment method is required";
        }
        if (Object.keys(errors).length) {
            showBalanceTxnErrors(errors, partyType);
            return null;
        }
        return { partyType, partyId, amount };
    }

    function setLinkedOrder(orderId = "", orderReference = "") {
        const wrap = el("balanceLinkedOrderWrap");
        el("balanceTxnOrderId").value = orderId || "";
        el("balanceTxnOrderReference").value = orderReference || "";
        if (wrap) {
            wrap.classList.toggle("d-none", !orderId);
        }
        setText("balanceLinkedOrderLabel", orderReference || (orderId ? "#" + orderId : ""));
    }

    window.openBalanceTransactionModal = function (partyType = "customer", partyId = null, partyName = "") {
        const normalized = partyType === "supplier" ? "supplier" : "customer";
        clearBalanceTxnValidation();
        setLinkedOrder("", "");
        el("balanceTxnPartyType").value = normalized;
        setPartyTypeVisible(true);
        el("balanceTxnType").value = normalized === "supplier" ? "payment_sent" : "payment_received";
        setText("balanceTxnModalTitle", balancesT("Record Transaction"));
        setText("balanceTxnSubmitBtn", balancesT("Record Transaction"));
        el("balanceTxnAmount").value = "";
        el("balanceTxnCurrency").value = "RMB";
        el("balanceTxnDate").value = todayIso();
        el("balanceTxnMethod").value = "";
        clearBalanceAccountOptions();
        el("balanceTxnReference").value = "";
        el("balanceTxnNotes").value = "";
        setModalPartySelection(normalized, partyId, partyName);
        updateDirectionDefault();
        refreshUnsavedBaseline?.(el("balanceTransactionModal")?.querySelector(".modal-body"));
        bootstrap.Modal.getOrCreateInstance(el("balanceTransactionModal")).show();
    };

    async function openDepositForOrder(orderId) {
        try {
            const response = await api("/balances/order-context?order_id=" + encodeURIComponent(orderId));
            const data = response.data || {};
            window.openBalanceTransactionModal(data.party_type || "customer", data.party_id, data.party_name || "");
            el("balanceTxnType").value = "deposit";
            setText("balanceTxnModalTitle", balancesT("Record Deposit"));
            setText("balanceTxnSubmitBtn", balancesT("Record Deposit"));
            el("balanceTxnCurrency").value = data.currency || "RMB";
            el("balanceTxnDate").value = data.transaction_date || todayIso();
            setLinkedOrder(data.order_id, data.order_reference || ("#" + data.order_id));
            updateDirectionDefault();
            setText("balanceTxnDirectionHelp", balancesT("This deposit will reduce the linked order balance."));
            el("balanceTxnAmount")?.focus();
        } catch (error) {
            showToast(error.message || balancesT("Invalid order"), "danger");
        }
    }

    window.submitBalanceTransaction = async function () {
        clearBalanceTxnValidation();
        const valid = validateBalanceTransactionForm();
        if (!valid) return;
        const { partyType, partyId, amount } = valid;
        const accountValue = el("balanceTxnAccountDetail").value.trim();
        const payload = {
            party_type: partyType,
            party_id: Number(partyId),
            transaction_type: el("balanceTxnType").value,
            direction: el("balanceTxnDirection").value,
            amount,
            currency: el("balanceTxnCurrency").value,
            transaction_date: el("balanceTxnDate").value || todayIso(),
            payment_method: el("balanceTxnMethod").value || null,
            payment_account_label: accountValue ? (selectedAccountOption()?.dataset?.label || el("balanceTxnMethod").value || null) : null,
            payment_account_value: accountValue || null,
            payment_account_qr_path: accountValue ? (selectedAccountOption()?.dataset?.qr || null) : null,
            order_id: el("balanceTxnOrderId").value ? Number(el("balanceTxnOrderId").value) : null,
            order_reference: el("balanceTxnOrderReference").value || null,
            reference_number: el("balanceTxnReference").value.trim() || null,
            notes: el("balanceTxnNotes").value.trim() || null,
        };
        const button = el("balanceTxnSubmitBtn");
        try {
            button.disabled = true;
            await api("/balances/transactions", {
                method: "POST",
                body: JSON.stringify(payload),
            });
            showToast(balancesT(payload.transaction_type === "deposit" ? "Deposit Saved" : "Transaction recorded"));
            refreshUnsavedBaseline?.(el("balanceTransactionModal")?.querySelector(".modal-body"));
            bootstrap.Modal.getInstance(el("balanceTransactionModal"))?.hide();
            loadBalancePageData();
        } catch (error) {
            if (error?.errors && typeof error.errors === "object") {
                showBalanceTxnErrors(error.errors, partyType);
                return;
            }
            showToast(error.message || balancesT("Failed to record transaction"), "danger");
        } finally {
            button.disabled = false;
        }
    };

    function attachEnterToLoad(id) {
        el(id)?.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                loadBalancePageData();
            }
        });
    }

    document.addEventListener("DOMContentLoaded", () => {
        registerUnsavedChangesGuard?.("#balanceTransactionModal .modal-body");
        if (typeof Autocomplete !== "undefined") {
            balanceCustomerAc = Autocomplete.init(el("balanceTxnCustomerSearch"), {
                resource: "customers",
                searchPath: "/search",
                placeholder: balancesT("Type to search customer..."),
                onSelect: (item) => {
                    el("balanceTxnCustomerId").value = item.id || "";
                    loadBalancePartyAccounts("customer", item.id || "");
                },
            });
            balanceSupplierAc = Autocomplete.init(el("balanceTxnSupplierSearch"), {
                resource: "suppliers",
                searchPath: "/search",
                placeholder: balancesT("Type to search supplier..."),
                onSelect: (item) => {
                    el("balanceTxnSupplierId").value = item.id || "";
                    loadBalancePartyAccounts("supplier", item.id || "");
                },
            });
        }
        el("balanceTxnCustomerSearch")?.addEventListener("input", () => {
            el("balanceTxnCustomerId").value = "";
            clearBalanceAccountOptions();
        });
        el("balanceTxnSupplierSearch")?.addEventListener("input", () => {
            el("balanceTxnSupplierId").value = "";
            clearBalanceAccountOptions();
        });
        el("balanceTxnPartyType")?.addEventListener("change", () => setPartyTypeVisible(true));
        el("balanceTxnType")?.addEventListener("change", updateDirectionDefault);
        el("balanceTxnAccountOption")?.addEventListener("change", syncBalanceAccountSelection);
        el("balanceTxnAccountDetail")?.addEventListener("input", () => {
            const option = selectedAccountOption();
            if (option && option.value !== "" && el("balanceTxnAccountDetail").value !== (option.dataset.value || "")) {
                el("balanceTxnAccountOption").value = "";
            }
        });
        [
            "balanceTxnPartyType",
            "balanceTxnCustomerSearch",
            "balanceTxnSupplierSearch",
            "balanceTxnType",
            "balanceTxnAmount",
            "balanceTxnCurrency",
            "balanceTxnDate",
            "balanceTxnMethod",
            "balanceTxnReference",
        ].forEach((id) => {
            const node = el(id);
            node?.addEventListener("input", clearBalanceTxnValidation);
            node?.addEventListener("change", clearBalanceTxnValidation);
        });

        ["balancePartyType", "balanceCurrency", "balancePaymentMethod", "balanceStatus", "balanceDateFrom", "balanceDateTo"].forEach((id) => {
            el(id)?.addEventListener("change", loadBalancePageData);
        });
        attachEnterToLoad("balanceSearch");

        document.getElementById("customer-balances-tab")?.addEventListener("shown.bs.tab", () => {
            activeDataset = "customers";
            window.history.replaceState(null, "", "#customers");
        });
        document.getElementById("supplier-balances-tab")?.addEventListener("shown.bs.tab", () => {
            activeDataset = "suppliers";
            window.history.replaceState(null, "", "#suppliers");
        });
        document.getElementById("balance-transactions-tab")?.addEventListener("shown.bs.tab", () => {
            activeDataset = "transactions";
            window.history.replaceState(null, "", "#transactions");
        });

        const hash = (window.location.hash || "").replace("#", "");
        if (hash === "suppliers") {
            activeDataset = "suppliers";
            bootstrap.Tab.getOrCreateInstance(el("supplier-balances-tab")).show();
        } else if (hash === "transactions") {
            activeDataset = "transactions";
            bootstrap.Tab.getOrCreateInstance(el("balance-transactions-tab")).show();
        }

        setPartyTypeVisible(false);
        loadBalancePageData();
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get("action") === "deposit" && urlParams.get("order_id")) {
            openDepositForOrder(urlParams.get("order_id"));
        }
    });
})();
