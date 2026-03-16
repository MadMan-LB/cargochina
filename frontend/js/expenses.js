/**
 * Expenses page - load, filter, add, edit, delete
 * Auto-apply filters, persistence, fast autocomplete
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    const FILTER_KEY = "clms_expenses_filters";
    let filterOrderAc,
        filterContainerAc,
        filterSupplierAc,
        formOrderAc,
        formContainerAc,
        formPayeeAc,
        formCategoryAc;
    let filterDebounce = null;
    let loading = false;

    async function api(method, path, body) {
        const opts = { method, credentials: "same-origin" };
        if (body && (method === "POST" || method === "PUT")) {
            opts.headers = { "Content-Type": "application/json" };
            opts.body = JSON.stringify(body);
        }
        const r = await fetch(API + path, opts);
        const d = await r.json();
        if (!r.ok || d.error) throw new Error(d.message || "Request failed");
        return d;
    }

    function getFilterState() {
        return {
            q: document.getElementById("filterSearch")?.value?.trim() || "",
            df: document.getElementById("filterDateFrom")?.value || "",
            dt: document.getElementById("filterDateTo")?.value || "",
            cat: document.getElementById("filterCategory")?.value || "",
            oid:
                filterOrderAc?.getSelectedId?.() ||
                document.getElementById("filterOrderId")?.value?.trim() ||
                "",
            cid:
                filterContainerAc?.getSelectedId?.() ||
                document.getElementById("filterContainerId")?.value?.trim() ||
                "",
            sid:
                filterSupplierAc?.getSelectedId?.() ||
                document.getElementById("filterSupplierId")?.value?.trim() ||
                "",
        };
    }

    function saveFiltersToStorage() {
        try {
            const s = getFilterState();
            if (s.q || s.df || s.dt || s.cat || s.oid || s.cid || s.sid) {
                localStorage.setItem(FILTER_KEY, JSON.stringify(s));
            } else {
                localStorage.removeItem(FILTER_KEY);
            }
        } catch (_) {}
    }

    window.loadExpenses = async function () {
        if (loading) return;
        loading = true;
        const tbody = document.querySelector("#expensesTable tbody");
        if (tbody)
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">Loading…</td></tr>';

        const params = new URLSearchParams();
        const s = getFilterState();
        if (s.q) params.set("q", s.q);
        if (s.df) params.set("date_from", s.df);
        if (s.dt) params.set("date_to", s.dt);
        if (s.cat) params.set("category_id", s.cat);
        if (s.oid) params.set("order_id", s.oid);
        if (s.cid) params.set("container_id", s.cid);
        if (s.sid) params.set("supplier_id", s.sid);

        try {
            const d = await api("GET", "/expenses?" + params.toString());
            renderExpenses(d.data);
            renderSummary(d.summary);
            saveFiltersToStorage();
        } catch (e) {
            if (tbody)
                tbody.innerHTML =
                    '<tr><td colspan="8" class="text-center text-danger py-4">' +
                    escapeHtml(e.message || "Failed to load") +
                    "</td></tr>";
            alert(e.message || "Failed to load expenses");
        } finally {
            loading = false;
        }
    };

    function applyFiltersDebounced() {
        clearTimeout(filterDebounce);
        filterDebounce = setTimeout(loadExpenses, 220);
    }

    window.clearExpenseFilters = function () {
        document.getElementById("filterDateFrom").value = "";
        document.getElementById("filterDateTo").value = "";
        document.getElementById("filterCategory").value = "";
        document.getElementById("filterSearch").value = "";
        if (filterOrderAc?.setValue) filterOrderAc.setValue(null);
        else document.getElementById("filterOrderId").value = "";
        if (filterContainerAc?.setValue) filterContainerAc.setValue(null);
        else document.getElementById("filterContainerId").value = "";
        if (filterSupplierAc?.setValue) filterSupplierAc.setValue(null);
        else document.getElementById("filterSupplierId").value = "";
        try {
            localStorage.removeItem(FILTER_KEY);
        } catch (_) {}
        loadExpenses();
    };

    function renderSummary(summary) {
        const el = document.getElementById("expenseSummary");
        if (!summary || summary.length === 0) {
            el.innerHTML =
                '<small class="text-muted">No expenses in range.</small>';
            return;
        }
        const parts = summary
            .map(
                (s) =>
                    `<strong>${s.currency}:</strong> ${parseFloat(s.total).toFixed(2)}`,
            )
            .join(" &nbsp; ");
        el.innerHTML =
            '<div class="alert alert-light border py-2 mb-0"><strong>Total:</strong> ' +
            parts +
            "</div>";
    }

    function renderExpenses(rows) {
        const tbody = document.querySelector("#expensesTable tbody");
        if (!rows || rows.length === 0) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">No expenses found.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map(
                (r) => `
      <tr>
        <td>${r.expense_date || "—"}</td>
        <td><span class="badge bg-secondary">${escapeHtml(r.category_name || r.category_type || "—")}</span></td>
        <td><strong>${parseFloat(r.amount).toFixed(2)}</strong> ${r.currency}</td>
        <td>${escapeHtml(r.payee || "—")}</td>
        <td>${r.order_id ? '<a href="/cargochina/orders.php?order_id=' + r.order_id + '">#' + r.order_id + "</a>" : "—"}</td>
        <td>${r.container_code || (r.container_id ? "#" + r.container_id : "—")}</td>
        <td class="small text-muted">${escapeHtml((r.notes || "").slice(0, 40))}${(r.notes || "").length > 40 ? "…" : ""}</td>
        <td>
          <button class="btn btn-sm btn-outline-primary" onclick="editExpense(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteExpense(${r.id})">Delete</button>
        </td>
      </tr>
    `,
            )
            .join("");
    }

    function escapeHtml(s) {
        if (s == null) return "";
        const d = document.createElement("div");
        d.textContent = String(s);
        return d.innerHTML;
    }

    window.openExpenseForm = function (editId) {
        document.getElementById("expenseModalTitle").textContent = editId
            ? "Edit Expense"
            : "Add Expense";
        document.getElementById("expenseId").value = editId || "";
        if (formCategoryAc?.setValue) formCategoryAc.setValue(null);
        else document.getElementById("expenseCategory").value = "";
        document.getElementById("expenseAmount").value = "";
        document.getElementById("expenseCurrency").value = "USD";
        document.getElementById("expenseDate").value = new Date()
            .toISOString()
            .slice(0, 10);
        if (formPayeeAc?.setValue) formPayeeAc.setValue(null);
        else document.getElementById("expensePayee").value = "";
        if (formOrderAc?.setValue) formOrderAc.setValue(null);
        else document.getElementById("expenseOrderId").value = "";
        if (formContainerAc?.setValue) formContainerAc.setValue(null);
        else document.getElementById("expenseContainerId").value = "";
        document.getElementById("expenseNotes").value = "";
        if (editId) loadExpenseForEdit(editId);
    };

    window.editExpense = function (id) {
        openExpenseForm(id);
        const modal = new bootstrap.Modal(
            document.getElementById("expenseModal"),
        );
        modal.show();
    };

    async function loadExpenseForEdit(id) {
        try {
            const d = await api("GET", "/expenses/" + id);
            const r = d.data;
            if (formCategoryAc?.setValue) {
                formCategoryAc.setValue({
                    id: r.category_id,
                    name: r.category_name || "",
                    category_type: r.category_type || "",
                });
            } else {
                document.getElementById("expenseCategory").value =
                    r.category_name || "";
            }
            document.getElementById("expenseAmount").value = r.amount;
            document.getElementById("expenseCurrency").value =
                r.currency || "USD";
            document.getElementById("expenseDate").value = r.expense_date || "";
            document.getElementById("expensePayee").value = r.payee || "";
            document.getElementById("expenseNotes").value = r.notes || "";
            if (r.order_id && formOrderAc?.setValue) {
                formOrderAc.setValue({
                    id: r.order_id,
                    customer_name: r.customer_name || "",
                    expected_ready_date: r.order_expected_ready_date || "",
                    status: "",
                });
            } else {
                document.getElementById("expenseOrderId").value = "";
                if (formOrderAc) formOrderAc.setValue?.(null);
            }
            if (r.container_id && formContainerAc?.setValue) {
                formContainerAc.setValue({
                    id: r.container_id,
                    code: r.container_code || "",
                    status: "",
                });
            } else {
                document.getElementById("expenseContainerId").value = "";
                if (formContainerAc) formContainerAc.setValue?.(null);
            }
            if (r.payee && formPayeeAc?.setValue) {
                formPayeeAc.setValue({
                    id: r.supplier_id
                        ? "supplier:" + r.supplier_id
                        : "payee:" + r.payee,
                    payee: r.payee,
                    name: r.payee,
                    supplier_id: r.supplier_id || undefined,
                });
            } else if (formPayeeAc) {
                formPayeeAc.setValue?.(null);
            }
        } catch (e) {
            alert(e.message || "Failed to load expense");
        }
    }

    window.saveExpense = async function () {
        const id = document.getElementById("expenseId").value;
        const categoryVal =
            formCategoryAc?.getSelectedId?.() ||
            document.getElementById("expenseCategory").value.trim();
        const orderVal =
            formOrderAc?.getSelectedId?.() ||
            document.getElementById("expenseOrderId").value.trim();
        const containerVal =
            formContainerAc?.getSelectedId?.() ||
            document.getElementById("expenseContainerId").value.trim();
        const categoryId =
            categoryVal && /^\d+$/.test(String(categoryVal))
                ? parseInt(categoryVal, 10)
                : 0;
        const categoryName =
            document.getElementById("expenseCategory").value?.trim() || "";
        const payeeSel = formPayeeAc?.getSelected?.() || null;
        const supplierIdFromPayee =
            payeeSel?.supplier_id != null ? payeeSel.supplier_id : null;
        const body = {
            category_id: categoryId,
            ...(categoryId === 0 && categoryName
                ? { category_name: categoryName }
                : {}),
            amount: parseFloat(document.getElementById("expenseAmount").value),
            currency: document.getElementById("expenseCurrency").value,
            expense_date: document.getElementById("expenseDate").value,
            payee:
                document.getElementById("expensePayee").value?.trim() || null,
            order_id:
                orderVal && /^\d+$/.test(String(orderVal))
                    ? parseInt(orderVal, 10)
                    : null,
            container_id:
                containerVal && /^\d+$/.test(String(containerVal))
                    ? parseInt(containerVal, 10)
                    : null,
            supplier_id: supplierIdFromPayee,
            notes:
                document.getElementById("expenseNotes").value?.trim() || null,
        };
        if ((!body.category_id && !categoryName) || body.amount <= 0) {
            alert(
                "Category and amount are required. Type a category name or select one from the search.",
            );
            return;
        }
        try {
            if (id) {
                await api("PUT", "/expenses/" + id, body);
            } else {
                await api("POST", "/expenses", body);
            }
            bootstrap.Modal.getInstance(
                document.getElementById("expenseModal"),
            ).hide();
            await refreshFilterCategories();
            loadExpenses();
        } catch (e) {
            alert(e.message || "Failed to save expense");
        }
    };

    window.deleteExpense = async function (id) {
        if (!confirm("Delete this expense?")) return;
        try {
            await api("DELETE", "/expenses/" + id);
            loadExpenses();
        } catch (e) {
            alert(e.message || "Failed to delete");
        }
    };

    function setupExpenseAutocompletes() {
        if (typeof Autocomplete === "undefined") return;

        // Filter bar: Order and Container search
        const fo = document.getElementById("filterOrderId");
        const fc = document.getElementById("filterContainerId");
        if (fo) {
            filterOrderAc = Autocomplete.init(fo, {
                resource: "orders",
                searchPath: "/search",
                placeholder: "Type to search order…",
                debounceMs: 100,
                onSelect: () => applyFiltersDebounced(),
            });
        }
        if (fc) {
            filterContainerAc = Autocomplete.init(fc, {
                resource: "containers",
                searchPath: "/search",
                placeholder: "Type to search container…",
                debounceMs: 100,
                onSelect: () => applyFiltersDebounced(),
            });
        }
        const fs = document.getElementById("filterSupplierId");
        if (fs) {
            filterSupplierAc = Autocomplete.init(fs, {
                resource: "suppliers",
                searchPath: "/search",
                placeholder: "Type to search supplier…",
                debounceMs: 100,
                onSelect: () => applyFiltersDebounced(),
            });
        }

        // Modal: Order, Container, Payee
        const eo = document.getElementById("expenseOrderId");
        const ec = document.getElementById("expenseContainerId");
        const ep = document.getElementById("expensePayee");
        if (eo) {
            formOrderAc = Autocomplete.init(eo, {
                resource: "orders",
                searchPath: "/search",
                placeholder: "Type to search order…",
            });
        }
        if (ec) {
            formContainerAc = Autocomplete.init(ec, {
                resource: "containers",
                searchPath: "/search",
                placeholder: "Type to search container…",
            });
        }
        if (ep) {
            formPayeeAc = Autocomplete.init(ep, {
                resource: "expenses",
                searchPath: "/payee-suggestions",
                placeholder: "Type to search payees or suppliers…",
                renderItem: (item) =>
                    (item.name || item.payee || "") +
                    (item.source === "supplier" ? " (Supplier)" : ""),
            });
        }
        const catEl = document.getElementById("expenseCategory");
        if (catEl) {
            formCategoryAc = Autocomplete.init(catEl, {
                resource: "expenses",
                searchPath: "/categories",
                placeholder: "Type to search category…",
                debounceMs: 80,
                renderItem: (c) =>
                    (c.name || "") +
                    (c.category_type ? " (" + c.category_type + ")" : ""),
            });
        }
    }

    async function refreshFilterCategories() {
        const sel = document.getElementById("filterCategory");
        if (!sel) return;
        const catRes = await api("GET", "/expenses/categories").catch(() => ({
            data: [],
        }));
        const currentVal = sel.value;
        sel.innerHTML =
            '<option value="">All</option>' +
            (catRes.data || [])
                .map(
                    (c) =>
                        `<option value="${c.id}">${escapeHtml(c.name)} (${c.category_type})</option>`,
                )
                .join("");
        if (currentVal && sel.querySelector(`option[value="${currentVal}"]`)) {
            sel.value = currentVal;
        }
    }

    function restoreFiltersFromStorage() {
        try {
            const raw = localStorage.getItem(FILTER_KEY);
            if (!raw) return;
            const s = JSON.parse(raw);
            if (s.q) document.getElementById("filterSearch").value = s.q;
            if (s.df) document.getElementById("filterDateFrom").value = s.df;
            if (s.dt) document.getElementById("filterDateTo").value = s.dt;
            if (s.cat) document.getElementById("filterCategory").value = s.cat;
            if (s.oid && filterOrderAc?.setValue)
                filterOrderAc.setValue({
                    id: s.oid,
                    customer_name: "",
                    expected_ready_date: "",
                    status: "",
                });
            else if (s.oid)
                document.getElementById("filterOrderId").value = s.oid;
            if (s.cid && filterContainerAc?.setValue)
                filterContainerAc.setValue({ id: s.cid, code: "", status: "" });
            else if (s.cid)
                document.getElementById("filterContainerId").value = s.cid;
            if (s.sid && filterSupplierAc?.setValue)
                filterSupplierAc.setValue({
                    id: s.sid,
                    name: "",
                    code: "",
                    phone: "",
                });
            else if (s.sid)
                document.getElementById("filterSupplierId").value = s.sid;
        } catch (_) {}
    }

    // Load categories and expenses on init
    (async function init() {
        setupExpenseAutocompletes();
        await refreshFilterCategories();
        restoreFiltersFromStorage();

        loadExpenses();

        document
            .getElementById("filterSearch")
            ?.addEventListener("input", applyFiltersDebounced);
        document
            .getElementById("filterDateFrom")
            ?.addEventListener("change", applyFiltersDebounced);
        document
            .getElementById("filterDateTo")
            ?.addEventListener("change", applyFiltersDebounced);
        document
            .getElementById("filterCategory")
            ?.addEventListener("change", applyFiltersDebounced);

        const fs = document.getElementById("filterSearch");
        if (fs)
            fs.addEventListener("keydown", (e) => {
                if (e.key === "Enter") {
                    e.preventDefault();
                    loadExpenses();
                }
            });
    })();
})();
