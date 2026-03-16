/**
 * Procurement Drafts page
 */
(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let draftSupplierAc = null;

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

    let itemCounter = 0;

    window.loadDrafts = async function () {
        try {
            const d = await api("GET", "/procurement-drafts");
            renderDrafts(d.data || []);
        } catch (e) {
            alert(e.message || "Failed to load drafts");
        }
    };

    function renderDrafts(rows) {
        const tbody = document.querySelector("#draftsTable tbody");
        if (!rows.length) {
            tbody.innerHTML =
                '<tr><td colspan="7" class="text-center text-muted py-4">No drafts yet.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map(
                (r) => `
            <tr>
                <td>${r.id}</td>
                <td>${escapeHtml(r.name)}</td>
                <td>${escapeHtml(r.supplier_name || "—")}</td>
                <td><span class="badge bg-secondary">${escapeHtml(r.status)}</span></td>
                <td>${(r.items || []).length}</td>
                <td>${r.created_at ? new Date(r.created_at).toLocaleDateString() : "—"}</td>
                <td>
                    ${r.status === "converted" && r.converted_order_id ? `<a href="/cargochina/orders.php?id=${r.converted_order_id}" class="btn btn-sm btn-outline-success">View Order #${r.converted_order_id}</a>` : ""}
                    ${r.status === "draft" || r.status === "pending_review" ? `<button class="btn btn-sm btn-outline-success" onclick="convertDraft(${r.id})">Convert</button>` : ""}
                    ${r.status === "draft" || r.status === "pending_review" ? `<button class="btn btn-sm btn-outline-primary" onclick="editDraft(${r.id})">Edit</button>` : ""}
                    ${r.status === "draft" || r.status === "cancelled" ? `<button class="btn btn-sm btn-outline-danger" onclick="deleteDraft(${r.id})">Delete</button>` : ""}
                </td>
            </tr>
        `,
            )
            .join("");
    }

    window.openDraftForm = function () {
        document.getElementById("draftId").value = "";
        document.getElementById("draftName").value = "";
        document.getElementById("draftSupplierId").value = "";
        draftSupplierAc?.setValue(null);
        document.getElementById("draftModalTitle").textContent =
            "New Procurement Draft";
        document.getElementById("draftItemsContainer").innerHTML = "";
        addDraftItem();
    };

    window.addDraftItem = function (initialItem = null) {
        const c = document.getElementById("draftItemsContainer");
        const id = ++itemCounter;
        const div = document.createElement("div");
        div.className = "row g-2 mb-2 align-items-end draft-item-row";
        div.dataset.itemId = id;
        div.innerHTML = `
            <div class="col-md-6">
                <label class="form-label small">Product</label>
                <input type="text" class="form-control form-control-sm draft-product-search" data-id="${id}" placeholder="Type to search product..." autocomplete="off">
                <input type="hidden" class="draft-product" data-id="${id}">
            </div>
            <div class="col-md-2">
                <label class="form-label small">Qty</label>
                <input type="number" step="0.01" class="form-control form-control-sm draft-qty" value="1" data-id="${id}">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Notes</label>
                <input type="text" class="form-control form-control-sm draft-notes" data-id="${id}">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeDraftItem(${id})">×</button>
            </div>
        `;
        c.appendChild(div);
        if (initialItem) {
            div.querySelector(".draft-qty").value = initialItem.quantity || 1;
            div.querySelector(".draft-notes").value = initialItem.notes || "";
        }
        initDraftProductAutocomplete(
            div,
            initialItem?.product_id
                ? {
                      id: initialItem.product_id,
                      description_en:
                          initialItem.product_description_en ||
                          initialItem.description_en ||
                          initialItem.product_name ||
                          "",
                      description_cn:
                          initialItem.product_description_cn ||
                          initialItem.description_cn ||
                          "",
                      hs_code: initialItem.hs_code || "",
                  }
                : null,
        );
    };

    window.removeDraftItem = function (id) {
        document
            .querySelector(`.draft-item-row[data-item-id="${id}"]`)
            ?.remove();
    };

    function initDraftProductAutocomplete(row, initialProduct = null) {
        const inputEl = row.querySelector(".draft-product-search");
        const hiddenEl = row.querySelector(".draft-product");
        if (!inputEl || !hiddenEl || typeof Autocomplete === "undefined")
            return;
        const ac = Autocomplete.init(inputEl, {
            resource: "products",
            searchPath: "/search",
            placeholder: "Type to search product...",
            renderItem: (item) =>
                `${item.description_en || item.description_cn || "Product"}${item.high_alert_note || item.required_design ? " — Alert" : ""}${item.hs_code ? ` — HS ${item.hs_code}` : ""}`,
            onSelect: (item) => {
                hiddenEl.value = item.id || "";
            },
        });
        inputEl.addEventListener("input", () => {
            hiddenEl.value = "";
        });
        if (initialProduct && ac?.setValue) {
            ac.setValue(initialProduct);
            hiddenEl.value = initialProduct.id || "";
        }
    }

    function getDraftItems() {
        const items = [];
        document.querySelectorAll(".draft-item-row").forEach((row) => {
            const pid = row.querySelector(".draft-product")?.value;
            const qty = row.querySelector(".draft-qty")?.value;
            const notes = row.querySelector(".draft-notes")?.value;
            if (qty && parseFloat(qty) > 0) {
                items.push({
                    product_id: pid ? parseInt(pid) : null,
                    quantity: parseFloat(qty),
                    notes: notes || "",
                });
            }
        });
        return items;
    }

    window.saveDraft = async function () {
        const name = document.getElementById("draftName").value.trim();
        if (!name) {
            alert("Name required");
            return;
        }
        const supplierId = document.getElementById("draftSupplierId").value;
        const items = getDraftItems();
        const id = document.getElementById("draftId").value;
        try {
            if (id) {
                await api("PUT", "/procurement-drafts/" + id, {
                    name,
                    supplier_id: supplierId || null,
                    items,
                });
            } else {
                await api("POST", "/procurement-drafts", {
                    name,
                    supplier_id: supplierId || null,
                    items,
                });
            }
            document.querySelector('[data-bs-dismiss="modal"]').click();
            loadDrafts();
        } catch (e) {
            alert(e.message || "Failed to save");
        }
    };

    window.editDraft = async function (id) {
        try {
            const d = await api("GET", "/procurement-drafts/" + id);
            const r = d.data;
            document.getElementById("draftId").value = r.id;
            document.getElementById("draftName").value = r.name || "";
            document.getElementById("draftSupplierId").value =
                r.supplier_id || "";
            if (draftSupplierAc?.setValue) {
                draftSupplierAc.setValue(
                    r.supplier_id && r.supplier_name
                        ? { id: r.supplier_id, name: r.supplier_name }
                        : null,
                );
            } else {
                document.getElementById("draftSupplierSearch").value =
                    r.supplier_name || "";
            }
            document.getElementById("draftModalTitle").textContent =
                "Edit Draft #" + r.id;
            document.getElementById("draftItemsContainer").innerHTML = "";
            (r.items || []).forEach((it) => {
                addDraftItem(it);
            });
            if (!(r.items || []).length) addDraftItem();
            new bootstrap.Modal(document.getElementById("draftModal")).show();
        } catch (e) {
            alert(e.message || "Failed to load draft");
        }
    };

    window.deleteDraft = async function (id) {
        if (!confirm("Delete this draft?")) return;
        try {
            await api("DELETE", "/procurement-drafts/" + id);
            loadDrafts();
        } catch (e) {
            alert(e.message || "Failed to delete");
        }
    };

    function escapeHtml(s) {
        if (!s) return "";
        const d = document.createElement("div");
        d.textContent = s;
        return d.innerHTML;
    }

    window.convertDraft = function (id) {
        document.getElementById("convertDraftId").value = id;
        document.getElementById("convertCustomer").value = "";
        document.getElementById("convertCustomer").dataset.selectedId = "";
        document.getElementById("convertExpectedDate").value = new Date()
            .toISOString()
            .slice(0, 10);
        document.getElementById("convertCurrency").value = "USD";
        if (window.convertCustomerAc) {
            window.convertCustomerAc.setValue(null);
        } else {
            window.convertCustomerAc =
                typeof Autocomplete !== "undefined" && Autocomplete.init
                    ? Autocomplete.init(
                          document.getElementById("convertCustomer"),
                          {
                              resource: "customers",
                              searchPath: "/search",
                              placeholder: "Type customer name...",
                          },
                      )
                    : null;
        }
        new bootstrap.Modal(document.getElementById("convertModal")).show();
    };

    window.doConvertDraft = async function () {
        const draftId = document.getElementById("convertDraftId").value;
        const customerId =
            document.getElementById("convertCustomer").dataset.selectedId ||
            document.getElementById("convertCustomer").dataset.selectedid;
        const expectedDate = document.getElementById(
            "convertExpectedDate",
        ).value;
        const currency = document.getElementById("convertCurrency").value;
        if (!customerId || !expectedDate) {
            alert("Please select a customer and enter expected ready date.");
            return;
        }
        const btn = document.getElementById("convertSubmitBtn");
        try {
            if (window.setLoading) setLoading(btn, true);
            const res = await api(
                "POST",
                "/procurement-drafts/" + draftId + "/convert",
                {
                    customer_id: parseInt(customerId, 10),
                    expected_ready_date: expectedDate,
                    currency: currency || "USD",
                },
            );
            bootstrap.Modal.getInstance(
                document.getElementById("convertModal"),
            ).hide();
            loadDrafts();
            if (res.data?.converted_order_id) {
                window.location.href =
                    "/cargochina/orders.php?id=" + res.data.converted_order_id;
            }
        } catch (e) {
            alert(e.message || "Convert failed");
        } finally {
            if (window.setLoading) setLoading(btn, false);
        }
    };

    document.addEventListener("DOMContentLoaded", function () {
        loadDrafts();
        if (typeof Autocomplete !== "undefined") {
            draftSupplierAc = Autocomplete.init(
                document.getElementById("draftSupplierSearch"),
                {
                    resource: "suppliers",
                    searchPath: "/search",
                    placeholder: "Type supplier name...",
                    onSelect: (item) => {
                        document.getElementById("draftSupplierId").value =
                            item.id || "";
                    },
                },
            );
            document
                .getElementById("draftSupplierSearch")
                ?.addEventListener("input", () => {
                    document.getElementById("draftSupplierId").value = "";
                });
        }
    });
})();
