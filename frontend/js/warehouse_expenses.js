/**
 * Warehouse Expenses - simplified page for warehouse staff
 * Category: dropdown (warehouse only), Amount, Currency, Payee, Order, Notes
 * Date: auto (current). No delete. Edit allowed.
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let formOrderAc, editOrderAc;

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

    function esc(s) {
        if (s == null) return "";
        const d = document.createElement("div");
        d.textContent = String(s);
        return d.innerHTML;
    }

    async function loadWarehouseCategories() {
        const res = await api("GET", "/expenses/categories?warehouse_only=1");
        const cats = res.data || [];
        const sel = document.getElementById("whCategory");
        const editSel = document.getElementById("whEditCategory");
        if (sel) {
            sel.innerHTML = '<option value="">— Select —</option>' + cats.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join("");
        }
        if (editSel) {
            editSel.innerHTML = '<option value="">— Select —</option>' + cats.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join("");
        }
        return cats;
    }

    async function loadExpenses() {
        const tbody = document.getElementById("whExpensesTbody");
        const empty = document.getElementById("whExpensesEmpty");
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">Loading…</td></tr>';
        if (empty) empty.classList.add("d-none");
        try {
            const d = await api("GET", "/expenses?limit=50");
            const rows = d.data || [];
            if (rows.length === 0) {
                tbody.innerHTML = "";
                if (empty) empty.classList.remove("d-none");
                return;
            }
            tbody.innerHTML = rows.map(r => `
                <tr>
                    <td>${r.expense_date || "—"}</td>
                    <td><span class="badge bg-secondary">${esc(r.category_name || "—")}</span></td>
                    <td><strong>${parseFloat(r.amount).toFixed(2)}</strong> ${r.currency}</td>
                    <td>${esc(r.payee || "—")}</td>
                    <td>${r.order_id ? '<a href="/cargochina/orders.php?order_id=' + r.order_id + '">#' + r.order_id + "</a>" : "—"}</td>
                    <td class="small text-muted">${esc((r.notes || "").slice(0, 40))}${(r.notes || "").length > 40 ? "…" : ""}</td>
                    <td><button class="btn btn-sm btn-outline-primary" onclick="window.editWhExpense(${r.id})">Edit</button></td>
                </tr>
            `).join("");
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-4">' + esc(e.message) + "</td></tr>";
        }
    }

    document.getElementById("warehouseExpenseForm")?.addEventListener("submit", async (e) => {
        e.preventDefault();
        const catSel = document.getElementById("whCategory");
        const categoryId = parseInt(catSel?.value || "0", 10);
        const amount = parseFloat(document.getElementById("whAmount").value);
        const currency = document.getElementById("whCurrency").value;
        const payee = document.getElementById("whPayee").value?.trim() || null;
        const orderVal = (formOrderAc?.getSelectedId?.() || document.getElementById("whOrderId").value?.trim() || "").replace(/^#/, "");
        const orderId = orderVal && /^\d+$/.test(String(orderVal)) ? parseInt(orderVal, 10) : null;
        const notes = document.getElementById("whNotes").value?.trim() || null;
        if (!categoryId || amount <= 0) {
            alert("Category and amount are required.");
            return;
        }
        try {
            await api("POST", "/expenses", {
                category_id: categoryId,
                amount,
                currency,
                payee,
                order_id: orderId,
                notes,
                expense_date: new Date().toISOString().slice(0, 10),
            });
            document.getElementById("warehouseExpenseForm").reset();
            if (formOrderAc?.setValue) formOrderAc.setValue(null);
            await loadExpenses();
            if (typeof showToast === "function") showToast("Expense saved");
            else alert("Expense saved.");
        } catch (err) {
            alert(err.message || "Failed to save");
        }
    });

    window.editWhExpense = async function (id) {
        try {
            const d = await api("GET", "/expenses/" + id);
            const r = d.data;
            document.getElementById("whEditId").value = id;
            document.getElementById("whEditCategory").value = r.category_id;
            document.getElementById("whEditAmount").value = r.amount;
            document.getElementById("whEditCurrency").value = r.currency || "USD";
            document.getElementById("whEditPayee").value = r.payee || "";
            document.getElementById("whEditNotes").value = r.notes || "";
            if (r.order_id && editOrderAc?.setValue) {
                editOrderAc.setValue({ id: r.order_id, customer_name: r.customer_name || "", expected_ready_date: r.order_expected_ready_date || "", status: "" });
            } else {
                if (editOrderAc?.setValue) editOrderAc.setValue(null);
                else document.getElementById("whEditOrderId").value = r.order_id ? "#" + r.order_id : "";
            }
            new bootstrap.Modal(document.getElementById("whExpenseEditModal")).show();
        } catch (e) {
            alert(e.message || "Failed to load");
        }
    };

    window.saveWhExpenseEdit = async function () {
        const id = document.getElementById("whEditId").value;
        const categoryId = parseInt(document.getElementById("whEditCategory").value || "0", 10);
        const amount = parseFloat(document.getElementById("whEditAmount").value);
        const currency = document.getElementById("whEditCurrency").value;
        const payee = document.getElementById("whEditPayee").value?.trim() || null;
        const orderVal = (editOrderAc?.getSelectedId?.() || document.getElementById("whEditOrderId").value?.trim() || "").replace(/^#/, "");
        const orderId = orderVal && /^\d+$/.test(String(orderVal)) ? parseInt(orderVal, 10) : null;
        const notes = document.getElementById("whEditNotes").value?.trim() || null;
        if (!categoryId || amount <= 0) {
            alert("Category and amount are required.");
            return;
        }
        try {
            await api("PUT", "/expenses/" + id, {
                category_id: categoryId,
                amount,
                currency,
                payee,
                order_id: orderId,
                notes,
            });
            bootstrap.Modal.getInstance(document.getElementById("whExpenseEditModal")).hide();
            await loadWarehouseCategories();
            await loadExpenses();
            if (typeof showToast === "function") showToast("Expense updated");
            else alert("Expense updated.");
        } catch (e) {
            alert(e.message || "Failed to update");
        }
    };

    (async function init() {
        await loadWarehouseCategories();
        await loadExpenses();

        const orderInput = document.getElementById("whOrderId");
        if (orderInput && typeof Autocomplete !== "undefined") {
            formOrderAc = Autocomplete.init(orderInput, {
                resource: "orders",
                searchPath: "/search",
                placeholder: "Type to search order…",
            });
        }

        const editOrderInput = document.getElementById("whEditOrderId");
        if (editOrderInput && typeof Autocomplete !== "undefined") {
            editOrderAc = Autocomplete.init(editOrderInput, {
                resource: "orders",
                searchPath: "/search",
                placeholder: "Type to search order…",
            });
        }
    })();
})();
