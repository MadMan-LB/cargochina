(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let draftOrderCustomerAc = null;
    let draftOrderDestinationCountryAc = null;
    let legacyMigrationCustomerAc = null;
    let builderModal = null;
    let migrationModal = null;
    let quickSupplierModal = null;
    let sectionIndex = 0;
    let itemIndex = 0;
    let quickSupplierPaymentLinkIndex = 0;
    let draftOrderCustomerCountryShipping = [];

    async function api(method, path, body) {
        const opts = { method, credentials: "same-origin" };
        if (body && (method === "POST" || method === "PUT")) {
            opts.headers = { "Content-Type": "application/json" };
            opts.body = JSON.stringify(body);
        }
        const res = await fetch(API + path, opts);
        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            throw new Error(data.message || "Request failed");
        }
        return data;
    }

    function fmtAmount(value) {
        return (parseFloat(value || 0) || 0).toFixed(2);
    }

    function fmtCbm(value) {
        return (parseFloat(value || 0) || 0).toFixed(6);
    }

    function fmtWeight(value) {
        return (parseFloat(value || 0) || 0).toFixed(4);
    }

    function getDraftDestinationCountryId() {
        return (
            document.getElementById("draftOrderDestinationCountryId")?.value || ""
        ).trim();
    }

    function getDraftDestinationCountryMapping(countryId) {
        const id = String(countryId || "");
        if (!id) return null;
        return (
            draftOrderCustomerCountryShipping.find(
                (row) => String(row.country_id || "") === id,
            ) || null
        );
    }

    function getCustomerShipCode() {
        const selectedCountryId = getDraftDestinationCountryId();
        if (
            draftOrderCustomerCountryShipping.length > 1 &&
            !selectedCountryId
        ) {
            return "";
        }
        const selectedCountry = getDraftDestinationCountryMapping(
            selectedCountryId,
        );
        if (selectedCountry?.shipping_code) {
            return String(selectedCountry.shipping_code).trim().toUpperCase();
        }
        return (
            draftOrderCustomerAc?.getSelected()?.default_shipping_code || ""
        )
            .trim()
            .toUpperCase();
    }

    function setDraftDestinationInputReadOnly(readOnly) {
        const input = document.getElementById("draftOrderDestinationCountry");
        if (!input) return;
        input.readOnly = !!readOnly;
        input.classList.toggle("bg-light", !!readOnly);
    }

    function resetDraftDestinationCountry() {
        draftOrderCustomerCountryShipping = [];
        const idInput = document.getElementById("draftOrderDestinationCountryId");
        const select = document.getElementById("draftOrderDestinationCountrySelect");
        const input = document.getElementById("draftOrderDestinationCountry");
        if (idInput) idInput.value = "";
        if (select) {
            select.innerHTML = '<option value="">Select country...</option>';
            select.value = "";
        }
        if (input) input.value = "";
        draftOrderDestinationCountryAc?.setValue(null);
        showDraftDestinationSelect(false);
        setDraftDestinationInputReadOnly(false);
    }

    function setDraftDestinationCountry(countryId, countryName, countryCode) {
        const idValue = countryId ? String(countryId) : "";
        const idInput = document.getElementById("draftOrderDestinationCountryId");
        const input = document.getElementById("draftOrderDestinationCountry");
        const select = document.getElementById("draftOrderDestinationCountrySelect");
        if (idInput) idInput.value = idValue;
        const display = countryName
            ? `${countryName}${countryCode ? ` (${countryCode})` : ""}`
            : "";
        if (input) input.value = display;
        if (draftOrderDestinationCountryAc && countryId && countryName) {
            draftOrderDestinationCountryAc.setValue({
                id: countryId,
                name: countryName,
                code: countryCode || "",
            });
        }
        if (select && idValue) {
            select.value = idValue;
        }
    }

    function renderDraftDestinationSelect() {
        const select = document.getElementById("draftOrderDestinationCountrySelect");
        if (!select) return;
        select.innerHTML =
            '<option value="">Select country...</option>' +
            draftOrderCustomerCountryShipping
                .map(
                    (country) =>
                        `<option value="${country.country_id}">${escapeHtml(
                            country.country_name || "",
                        )}${country.country_code ? ` (${escapeHtml(country.country_code)})` : ""}</option>`,
                )
                .join("");
    }

    function showDraftDestinationSelect(show) {
        const inputWrap = document.getElementById(
            "draftOrderDestinationCountryInputWrap",
        );
        const selectWrap = document.getElementById(
            "draftOrderDestinationCountrySelectWrap",
        );
        if (inputWrap) inputWrap.classList.toggle("d-none", !!show);
        if (selectWrap) selectWrap.classList.toggle("d-none", !show);
    }

    async function loadDraftCustomerCountryContext(
        customerId,
        fallbackDefaultShip = "",
        preferredCountry = null,
    ) {
        resetDraftDestinationCountry();
        if (!customerId) {
            setShippingHint(fallbackDefaultShip || "");
            renumberDraftItems();
            return;
        }

        try {
            const res = await api("GET", `/customers/${customerId}`);
            const customer = res.data || {};
            draftOrderCustomerCountryShipping = customer.country_shipping || [];
            const defaultShip =
                customer.default_shipping_code || fallbackDefaultShip || "";
            const preferredCountryId = preferredCountry?.id
                ? String(preferredCountry.id)
                : "";

            if (draftOrderCustomerCountryShipping.length === 1) {
                const country = draftOrderCustomerCountryShipping[0];
                setDraftDestinationCountry(
                    country.country_id,
                    country.country_name,
                    country.country_code,
                );
                showDraftDestinationSelect(false);
                setDraftDestinationInputReadOnly(true);
                setShippingHint(country.shipping_code || defaultShip);
                renumberDraftItems();
                return;
            }

            if (draftOrderCustomerCountryShipping.length > 1) {
                renderDraftDestinationSelect();
                showDraftDestinationSelect(true);
                setDraftDestinationInputReadOnly(false);
                const selected =
                    getDraftDestinationCountryMapping(preferredCountryId) ||
                    null;
                if (selected) {
                    setDraftDestinationCountry(
                        selected.country_id,
                        selected.country_name,
                        selected.country_code,
                    );
                    setShippingHint(selected.shipping_code || defaultShip || "");
                } else {
                    const idInput = document.getElementById(
                        "draftOrderDestinationCountryId",
                    );
                    if (idInput) idInput.value = "";
                    setShippingHint("");
                }
                renumberDraftItems();
                return;
            }

            showDraftDestinationSelect(false);
            setDraftDestinationInputReadOnly(false);
            if (preferredCountry?.id || preferredCountry?.name) {
                setDraftDestinationCountry(
                    preferredCountry?.id || "",
                    preferredCountry?.name || "",
                    preferredCountry?.code || "",
                );
            }
            setShippingHint(defaultShip || "");
            renumberDraftItems();
        } catch (_) {
            showDraftDestinationSelect(false);
            setDraftDestinationInputReadOnly(false);
            if (preferredCountry?.id || preferredCountry?.name) {
                setDraftDestinationCountry(
                    preferredCountry?.id || "",
                    preferredCountry?.name || "",
                    preferredCountry?.code || "",
                );
            }
            setShippingHint(fallbackDefaultShip || "");
            renumberDraftItems();
        }
    }

    function setShippingHint(prefix) {
        const hint = document.getElementById("draftOrderShippingHint");
        if (!hint) return;
        if (prefix) {
            hint.textContent =
                `Default shipping code ${prefix} is active. Item numbers now follow ${prefix}-supplierSequence-itemSequence and stay aligned with supplier groups.`;
            hint.className = "alert alert-info border mt-3 mb-0";
            return;
        }
        hint.textContent =
            "This customer has no default shipping code yet. Shipping code and item number stay blank until you fill them manually.";
        hint.className = "alert alert-warning border mt-3 mb-0";
    }

    function confirmMissingDraftExpectedReadyDate(actionLabel) {
        return window.confirm(
            `Expected Ready Date is empty. Continue ${actionLabel} without it? Date-based reminders, overdue tracking, and date filters will skip it until you fill it later.`,
        );
    }

    function getAllDraftItemCards() {
        return Array.from(
            document.querySelectorAll(".draft-order-item-card"),
        );
    }

    function renumberDraftItems() {
        const prefix = getCustomerShipCode();
        setShippingHint(prefix);
        const supplierSequenceByKey = new Map();
        const supplierItemCounts = new Map();
        let nextSupplierSequence = 1;
        document.querySelectorAll(".draft-order-section").forEach((section) => {
            const supplierId =
                section.querySelector(".draft-section-supplier-id")?.value?.trim() ||
                "";
            const supplierKey = supplierId || `section-${section.dataset.sectionId || nextSupplierSequence}`;
            if (!supplierSequenceByKey.has(supplierKey)) {
                supplierSequenceByKey.set(supplierKey, nextSupplierSequence++);
            }
            const supplierSequence = supplierSequenceByKey.get(supplierKey);
            let localCount = supplierItemCounts.get(supplierKey) || 0;
            section.querySelectorAll(".draft-order-item-card").forEach((card) => {
                const shipInput = card.querySelector(".draft-item-shipping-code");
                const itemNoInput = card.querySelector(".draft-item-item-no");
                if (!shipInput || !itemNoInput) return;
                if (!card.dataset.manualShippingCode) {
                    shipInput.value = prefix || "";
                }
                localCount += 1;
                supplierItemCounts.set(supplierKey, localCount);
                if (!card.dataset.manualItemNo) {
                    const base = (shipInput.value || prefix || "").trim();
                    itemNoInput.value = base
                        ? `${base}-${supplierSequence}-${localCount}`
                        : "";
                }
            });
        });
    }

    function buildDraftSupplierSectionLabel(section, collapsed = false) {
        const supplierName =
            section._supplierAc?.getSelected?.()?.name ||
            section.querySelector(".draft-section-supplier")?.value?.trim() ||
            "New supplier section";
        const amount =
            section.querySelector(".draft-section-amount")?.textContent || "0.00";
        const currency =
            section.querySelector(".draft-section-currency")?.textContent || "USD";
        return collapsed
            ? `${supplierName} • ${amount} ${currency}`
            : supplierName;
    }

    function syncDraftSectionCollapse(section) {
        const collapsed = section.dataset.collapsed === "1";
        const body = section.querySelector(".card-body");
        const button = section.querySelector('[data-builder-action="collapse-section"]');
        const label = section.querySelector(".draft-section-title");
        if (body) body.classList.toggle("d-none", collapsed);
        if (button) {
            button.textContent = collapsed ? "Expand" : "Collapse";
        }
        if (label) {
            label.textContent = buildDraftSupplierSectionLabel(section, collapsed);
        }
        section.classList.toggle("draft-order-section-collapsed", collapsed);
    }

    async function loadDraftOrders() {
        const res = await api("GET", "/draft-orders");
        renderDraftOrders(res.data || []);
    }

    function renderDraftOrders(rows) {
        const tbody = document.querySelector("#draftOrdersTable tbody");
        if (!tbody) return;
        if (!rows.length) {
            tbody.innerHTML =
                '<tr><td colspan="8" class="text-center text-muted py-4">No draft orders yet.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map((row) => {
                const suppliers = (row.supplier_names || []).join(", ") || "—";
                return `
                    <tr>
                      <td>${row.id}</td>
                      <td>${escapeHtml(row.customer_name || "—")}</td>
                      <td>${escapeHtml(suppliers)}</td>
                      <td>${escapeHtml(row.expected_ready_date || "—")}</td>
                      <td><span class="badge ${typeof statusBadgeClass === "function" ? statusBadgeClass(row.status) : "bg-secondary"}">${escapeHtml(typeof statusLabel === "function" ? statusLabel(row.status) : row.status)}</span></td>
                      <td>${row.item_count || 0}</td>
                      <td>
                        <div>${fmtAmount(row.totals?.amount)} ${escapeHtml(row.currency || "USD")}</div>
                        <small class="text-muted">${fmtCbm(row.totals?.cbm)} CBM · ${fmtWeight(row.totals?.weight)} kg</small>
                      </td>
                      <td class="table-actions">
                        <button class="btn btn-sm btn-outline-primary" type="button" onclick="openDraftOrderBuilder(${row.id})">${row.editable ? "Open" : "View"}</button>
                        ${row.status === "Draft" ? `<button class="btn btn-sm btn-success" type="button" onclick="submitDraftOrder(${row.id})">Submit</button>` : ""}
                        <a class="btn btn-sm btn-outline-success" href="${API}/draft-orders/${row.id}/export?format=xlsx" download>XLSX</a>
                        <a class="btn btn-sm btn-outline-secondary" href="/cargochina/procurement_draft_print.php?order_id=${row.id}" target="_blank" rel="noopener">Print</a>
                        <a class="btn btn-sm btn-outline-info" href="/cargochina/orders.php?order_type=draft_procurement">Orders</a>
                      </td>
                    </tr>
                `;
            })
            .join("");
    }

    async function loadLegacyDrafts() {
        const res = await api("GET", "/procurement-drafts");
        renderLegacyDrafts(res.data || []);
    }

    function renderLegacyDrafts(rows) {
        const tbody = document.querySelector("#legacyDraftsTable tbody");
        if (!tbody) return;
        if (!rows.length) {
            tbody.innerHTML =
                '<tr><td colspan="6" class="text-center text-muted py-4">No legacy procurement drafts found.</td></tr>';
            return;
        }
        tbody.innerHTML = rows
            .map((row) => {
                const migrated =
                    !!row.converted_order_id || row.status === "converted";
                return `
                    <tr>
                      <td>${row.id}</td>
                      <td>${escapeHtml(row.name || "—")}</td>
                      <td>${escapeHtml(row.supplier_name || "—")}</td>
                      <td><span class="badge ${migrated ? "bg-success" : "bg-secondary"}">${escapeHtml(migrated ? "Migrated" : row.status || "draft")}</span></td>
                      <td>${(row.items || []).length}</td>
                      <td class="table-actions">
                        ${migrated && row.converted_order_id ? `<button class="btn btn-sm btn-outline-success" type="button" onclick="openDraftOrderBuilder(${row.converted_order_id})">Open Order</button>` : `<button class="btn btn-sm btn-outline-primary" type="button" onclick="openLegacyMigration(${row.id})">Migrate</button>`}
                        <a class="btn btn-sm btn-outline-secondary" href="/cargochina/procurement_draft_print.php?id=${row.id}" target="_blank" rel="noopener">Print</a>
                      </td>
                    </tr>
                `;
            })
            .join("");
    }

    function resetDraftOrderBuilder() {
        document.getElementById("draftOrderForm")?.reset();
        document.getElementById("draftOrderId").value = "";
        document.getElementById("draftOrderEditable").value = "1";
        document.getElementById("draftOrderModalTitle").textContent =
            "Draft an Order";
        document.getElementById("draftOrderModalSubtitle").textContent =
            "One customer, multiple supplier sections, live totals.";
        document.getElementById("draftOrderSections").innerHTML = "";
        document.getElementById("draftOrderTotalAmount").textContent = "0.00";
        document.getElementById("draftOrderTotalCurrency").textContent = "USD";
        document.getElementById("draftOrderTotalCbm").textContent = "0.000000";
        document.getElementById("draftOrderTotalWeight").textContent = "0.0000";
        draftOrderCustomerAc?.setValue(null);
        sectionIndex = 0;
        itemIndex = 0;
        setBuilderEditable(true);
        resetDraftDestinationCountry();
        setShippingHint("");
    }

    function setBuilderEditable(editable) {
        document.getElementById("draftOrderEditable").value = editable
            ? "1"
            : "0";
        const form = document.getElementById("draftOrderForm");
        if (form) {
            form.querySelectorAll("input, textarea, select").forEach((el) => {
                el.disabled = !editable;
            });
        }
        document
            .querySelectorAll(
                "#draftOrderModal button[data-builder-action], #draftOrderModal .draft-item-action",
            )
            .forEach((btn) => {
                btn.disabled = !editable;
            });
        const saveBtn = document.getElementById("draftOrderSaveBtn");
        if (saveBtn) saveBtn.classList.toggle("d-none", !editable);
        const addSectionBtn = document.getElementById("draftOrderAddSectionBtn");
        if (addSectionBtn) addSectionBtn.disabled = !editable;
    }

    async function fillDraftOrderBuilder(orderId) {
        const res = await api("GET", "/draft-orders/" + orderId);
        const order = res.data;
        resetDraftOrderBuilder();
        document.getElementById("draftOrderId").value = order.id;
        document.getElementById("draftOrderModalTitle").textContent =
            order.editable
                ? `Edit Draft Order #${order.id}`
                : `View Draft Order #${order.id}`;
        document.getElementById("draftOrderModalSubtitle").textContent =
            `${order.customer_name || "Customer"} · ${order.status}`;
        draftOrderCustomerAc?.setValue({
            id: order.customer_id,
            name: order.customer_name,
            default_shipping_code: order.default_shipping_code || "",
        });
        await loadDraftCustomerCountryContext(
            order.customer_id,
            order.default_shipping_code || "",
            {
                id: order.destination_country_id || "",
                name: order.destination_country_name || "",
                code: order.destination_country_code || "",
            },
        );
        document.getElementById("draftOrderExpectedDate").value =
            order.expected_ready_date || "";
        document.getElementById("draftOrderCurrency").value =
            order.currency || "USD";
        document.getElementById("draftOrderHighAlertNotes").value =
            order.high_alert_notes || "";
        (order.supplier_sections || []).forEach((section) =>
            addDraftOrderSection(section),
        );
        document.getElementById("draftOrderTotalCurrency").textContent =
            order.currency || "USD";
        updateDraftOrderTotals();
        setBuilderEditable(!!order.editable);
        renumberDraftItems();
    }

    async function openDraftOrderBuilder(orderId = null) {
        builderModal =
            builderModal ||
            bootstrap.Modal.getOrCreateInstance(
                document.getElementById("draftOrderModal"),
            );
        resetDraftOrderBuilder();
        if (orderId) {
            await fillDraftOrderBuilder(orderId);
        } else {
            addDraftOrderSection();
        }
        builderModal.show();
    }

    function sectionMarkup(sectionId) {
        return `
            <div class="card draft-order-section" data-section-id="${sectionId}">
              <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <span class="fw-semibold draft-section-title">Supplier Section</span>
                  <input type="text" class="form-control form-control-sm draft-section-supplier" placeholder="Type to search supplier..." style="width:min(320px, 100%)" autocomplete="off">
                  <input type="hidden" class="draft-section-supplier-id">
                  <button type="button" class="btn btn-outline-primary btn-sm draft-item-action" data-builder-action="quick-add-supplier" title="Quick add supplier">+</button>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                  <small class="text-muted"><span class="draft-section-amount">0.00</span> <span class="draft-section-currency">USD</span> · <span class="draft-section-cbm">0.000000</span> CBM · <span class="draft-section-weight">0.0000</span> kg</small>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="collapse-section">Collapse</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="move-up">↑</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="move-down">↓</button>
                  <button type="button" class="btn btn-outline-danger btn-sm draft-item-action" data-builder-action="remove-section">Remove Section</button>
                </div>
              </div>
              <div class="card-body">
                <div class="draft-section-items d-flex flex-column gap-3"></div>
                <button type="button" class="btn btn-outline-primary btn-sm mt-3 draft-item-action" data-builder-action="add-item">+ Add Item</button>
              </div>
            </div>
        `;
    }

    function addDraftOrderSection(initial = {}) {
        const sections = document.getElementById("draftOrderSections");
        if (!sections) return;
        const wrapper = document.createElement("div");
        const sectionId = ++sectionIndex;
        wrapper.innerHTML = sectionMarkup(sectionId);
        const section = wrapper.firstElementChild;
        sections.appendChild(section);

        const supplierInput = section.querySelector(".draft-section-supplier");
        const supplierIdInput = section.querySelector(".draft-section-supplier-id");
        const ac = Autocomplete.init(supplierInput, {
            resource: "suppliers",
            placeholder: "Type to search supplier...",
            onSelect: (item) => {
                supplierIdInput.value = item.id || "";
                refreshSectionProductFilters(section);
                syncDraftSectionCollapse(section);
                renumberDraftItems();
            },
        });
        supplierInput.addEventListener("input", () => {
            supplierIdInput.value = "";
            refreshSectionProductFilters(section);
            syncDraftSectionCollapse(section);
            renumberDraftItems();
        });
        section._supplierAc = ac;
        if (initial.supplier_id && initial.supplier_name) {
            ac?.setValue({
                id: initial.supplier_id,
                name: initial.supplier_name,
            });
            supplierIdInput.value = initial.supplier_id;
        }

        section
            .querySelector('[data-builder-action="quick-add-supplier"]')
            ?.addEventListener("click", () => openDraftQuickSupplier(section));
        section
            .querySelector('[data-builder-action="collapse-section"]')
            ?.addEventListener("click", () => {
                section.dataset.collapsed =
                    section.dataset.collapsed === "1" ? "0" : "1";
                syncDraftSectionCollapse(section);
            });
        section
            .querySelector('[data-builder-action="add-item"]')
            ?.addEventListener("click", () => addDraftOrderItem(section));
        section
            .querySelector('[data-builder-action="remove-section"]')
            ?.addEventListener("click", () => {
                section.remove();
                renumberDraftItems();
                updateDraftOrderTotals();
            });
        section
            .querySelector('[data-builder-action="move-up"]')
            ?.addEventListener("click", () => moveDraftSection(section, -1));
        section
            .querySelector('[data-builder-action="move-down"]')
            ?.addEventListener("click", () => moveDraftSection(section, 1));

        const items = initial.items || [];
        if (items.length) {
            items.forEach((item) => addDraftOrderItem(section, item));
        } else {
            addDraftOrderItem(section);
        }
        updateDraftOrderTotals();
        syncDraftSectionCollapse(section);
    }

    function moveDraftSection(section, direction) {
        const sibling =
            direction < 0
                ? section.previousElementSibling
                : section.nextElementSibling;
        if (!sibling) return;
        if (direction < 0) {
            section.parentNode.insertBefore(section, sibling);
        } else {
            section.parentNode.insertBefore(sibling, section);
        }
        renumberDraftItems();
        updateDraftOrderTotals();
    }

    function collapseDescriptionEntries(entries) {
        const list = Array.isArray(entries) ? entries : [];
        const cn = list
            .map((entry) =>
                (
                    entry?.description_text ||
                    entry?.text ||
                    entry?.description_cn ||
                    ""
                ).trim(),
            )
            .filter(Boolean)
            .join(" | ");
        const en = list
            .map((entry) =>
                (
                    entry?.description_translated ||
                    entry?.translated ||
                    entry?.description_en ||
                    entry?.description_text ||
                    entry?.text ||
                    ""
                ).trim(),
            )
            .filter(Boolean)
            .join(" | ");
        return { cn, en };
    }

    function draftDescriptionDisplayValue(cn, en) {
        return (String(en || cn || "")).trim();
    }

    function setDraftDescriptionValue(card, entries = []) {
        const input = card.querySelector(".draft-item-description-input");
        if (!input) return;
        const { cn, en } = collapseDescriptionEntries(entries);
        input.value = draftDescriptionDisplayValue(cn, en);
        input.dataset.descriptionCn = cn;
        input.dataset.descriptionEn = en;
    }

    function seedDraftDescriptionFromText(card, text = "") {
        const input = card.querySelector(".draft-item-description-input");
        if (!input) return;
        input.value = String(text || "").trim();
        input.dataset.descriptionCn = "";
        input.dataset.descriptionEn = "";
    }

    function itemMarkup(idx) {
        return `
            <div class="border rounded p-3 draft-order-item-card" data-item-id="${idx}" data-dimensions-scope="carton">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                  <div class="fw-semibold">Item Line</div>
                  <small class="text-muted">Product match or free-text item, then one description, packaging, pricing, dimensions, and photos.</small>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm draft-item-action" data-builder-action="remove-item">Remove Item</button>
              </div>
              <div class="row g-3">
                <div class="col-12 col-xl-4">
                  <label class="form-label">Product</label>
                  <input type="text" class="form-control draft-item-product-search" placeholder="Search existing products or type a new item name..." autocomplete="off">
                  <input type="hidden" class="draft-item-product-id">
                  <div class="form-text draft-item-product-meta"></div>
                </div>
                <div class="col-6 col-xl-2">
                  <label class="form-label">Shipping Code</label>
                  <input type="text" class="form-control draft-item-shipping-code" placeholder="Auto from customer">
                </div>
                <div class="col-6 col-xl-2">
                  <label class="form-label">Item No</label>
                  <input type="text" class="form-control draft-item-item-no" placeholder="CODE-1">
                </div>
                <div class="col-6 col-xl-2">
                  <label class="form-label">Pieces / Carton</label>
                  <input type="number" step="0.0001" min="0" class="form-control draft-item-pieces-per-carton" placeholder="0">
                </div>
                <div class="col-6 col-xl-2">
                  <label class="form-label">Cartons</label>
                  <input type="number" step="1" min="0" class="form-control draft-item-cartons" placeholder="0">
                </div>
                <div class="col-12">
                  <label class="form-label">Description</label>
                  <input type="text" class="form-control draft-item-description-input" placeholder="Type one description. Chinese and English are filled automatically.">
                  <div class="form-text">Use one description only. The system stores Chinese and English automatically for this item.</div>
                </div>
                <div class="col-6 col-lg-3">
                  <label class="form-label">Unit Price</label>
                  <input type="number" step="0.0001" min="0" class="form-control draft-item-unit-price" placeholder="0">
                </div>
                <div class="col-6 col-lg-3">
                  <label class="form-label">Optional HS Code</label>
                  <input type="text" class="form-control draft-item-hs-code" placeholder="HS code">
                </div>
                <div class="col-6 col-lg-3">
                  <label class="form-label">CBM / unit or carton</label>
                  <input type="number" step="0.000001" min="0" class="form-control draft-item-cbm" placeholder="CBM">
                </div>
                <div class="col-6 col-lg-3">
                  <label class="form-label">Weight / unit or carton (kg)</label>
                  <input type="number" step="0.0001" min="0" class="form-control draft-item-weight" placeholder="Weight">
                </div>
                <div class="col-4 col-lg-2">
                  <label class="form-label">L (cm)</label>
                  <input type="number" step="0.01" min="0" class="form-control draft-item-length" placeholder="L">
                </div>
                <div class="col-4 col-lg-2">
                  <label class="form-label">W (cm)</label>
                  <input type="number" step="0.01" min="0" class="form-control draft-item-width" placeholder="W">
                </div>
                <div class="col-4 col-lg-2">
                  <label class="form-label">H (cm)</label>
                  <input type="number" step="0.01" min="0" class="form-control draft-item-height" placeholder="H">
                </div>
                <div class="col-12 col-lg-6">
                  <div class="border rounded p-3 h-100">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                      <div>
                        <div class="fw-semibold">Photos</div>
                        <small class="text-muted">Attach product photos or use the camera on mobile.</small>
                      </div>
                      <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="camera-photo">Take Photo</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="upload-photo">Upload Photo</button>
                      </div>
                    </div>
                    <input type="file" class="d-none draft-item-photo-upload" accept="image/*" multiple>
                    <input type="file" class="d-none draft-item-photo-camera" accept="image/*" capture="environment">
                    <div class="draft-item-photos d-flex flex-wrap gap-2 mt-3"></div>
                  </div>
                </div>
                <div class="col-12 col-lg-6">
                  <div class="border rounded p-3 h-100">
                    <div class="form-check mb-3">
                      <input class="form-check-input draft-item-custom-design-required" type="checkbox" id="draftItemCustomDesign${idx}">
                      <label class="form-check-label fw-semibold" for="draftItemCustomDesign${idx}">Custom design</label>
                    </div>
                    <div class="draft-item-custom-design-fields d-none">
                      <label class="form-label">Custom design note</label>
                      <textarea class="form-control draft-item-custom-design-note" rows="2" placeholder="Reference design note or internal reminder..."></textarea>
                      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
                        <small class="text-muted">Attach custom design files if the supplier needs them.</small>
                        <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="upload-design">Upload Design</button>
                      </div>
                      <input type="file" class="d-none draft-item-design-upload" accept="image/*,application/pdf,.pdf" multiple>
                      <div class="draft-item-design-files d-flex flex-wrap gap-2 mt-3"></div>
                    </div>
                  </div>
                </div>
                <div class="col-12">
                  <div class="d-flex gap-4 flex-wrap">
                    <div><strong class="draft-item-total-amount">0.00</strong> <span class="text-muted">total</span></div>
                    <div><strong class="draft-item-total-cbm">0.000000</strong> <span class="text-muted">CBM</span></div>
                    <div><strong class="draft-item-total-weight">0.0000</strong> <span class="text-muted">kg</span></div>
                    <div><strong class="draft-item-total-qty">0</strong> <span class="text-muted">pcs</span></div>
                  </div>
                </div>
              </div>
            </div>
        `;
    }

    function renderFilePills(container, paths, removable = true) {
        container.innerHTML = (paths || [])
            .map(
                (path, index) => `
                    <div class="border rounded px-2 py-1 d-flex align-items-center gap-2" data-path="${escapeHtml(path).replace(/"/g, "&quot;")}">
                      <a href="/cargochina/backend/${escapeHtml(path)}" target="_blank" rel="noopener" class="small text-decoration-none">${escapeHtml(path.split("/").pop())}</a>
                      ${removable ? `<button type="button" class="btn btn-sm btn-link text-danger p-0 draft-item-action" data-remove-index="${index}">Remove</button>` : ""}
                    </div>
                `,
            )
            .join("");
        container.querySelectorAll("[data-remove-index]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const idx = parseInt(btn.dataset.removeIndex || "-1", 10);
                if (idx < 0) return;
                paths.splice(idx, 1);
                renderFilePills(container, paths, removable);
            });
        });
    }

    async function uploadFiles(files, acceptImagesOnly = false) {
        const uploaded = [];
        for (const file of Array.from(files || [])) {
            if (acceptImagesOnly && !(file.type || "").startsWith("image/")) {
                continue;
            }
            const path = await uploadFile(file);
            if (path) uploaded.push(path);
        }
        return uploaded;
    }

    async function uploadPhotoFiles(card, files) {
        const paths = card._photoPaths || [];
        const uploaded = await uploadFiles(files, true);
        uploaded.forEach((path) => paths.push(path));
        card._photoPaths = paths;
        renderFilePills(card.querySelector(".draft-item-photos"), paths);
    }

    async function uploadDesignFiles(card, files) {
        const paths = card._designPaths || [];
        const uploaded = await uploadFiles(files, false);
        uploaded.forEach((path) => paths.push(path));
        card._designPaths = paths;
        renderFilePills(card.querySelector(".draft-item-design-files"), paths);
    }

    function refreshSectionProductFilters(section) {
        section
            .querySelectorAll(".draft-item-product-search")
            .forEach((input) => input.dispatchEvent(new Event("input")));
    }

    function updateDraftItemTotals(card) {
        const cartons =
            parseFloat(card.querySelector(".draft-item-cartons")?.value || 0) ||
            0;
        const ppc =
            parseFloat(
                card.querySelector(".draft-item-pieces-per-carton")?.value || 0,
            ) || 0;
        const qty = cartons > 0 && ppc > 0 ? cartons * ppc : 0;
        const unitPrice =
            parseFloat(
                card.querySelector(".draft-item-unit-price")?.value || 0,
            ) || 0;
        let cbm =
            parseFloat(card.querySelector(".draft-item-cbm")?.value || 0) || 0;
        const l =
            parseFloat(card.querySelector(".draft-item-length")?.value || 0) ||
            0;
        const w =
            parseFloat(card.querySelector(".draft-item-width")?.value || 0) || 0;
        const h =
            parseFloat(card.querySelector(".draft-item-height")?.value || 0) ||
            0;
        if (cbm <= 0 && l > 0 && w > 0 && h > 0) {
            cbm = (l * w * h) / 1000000;
        }
        const weight =
            parseFloat(card.querySelector(".draft-item-weight")?.value || 0) || 0;
        const scope =
            (card.dataset.dimensionsScope || "carton").toLowerCase() === "piece"
                ? "piece"
                : "carton";
        const multiplier = scope === "carton" ? cartons : qty;
        card.querySelector(".draft-item-total-qty").textContent = qty
            ? qty.toFixed(4).replace(/\.?0+$/, "")
            : "0";
        card.querySelector(".draft-item-total-amount").textContent = fmtAmount(
            qty * unitPrice,
        );
        card.querySelector(".draft-item-total-cbm").textContent = fmtCbm(
            cbm * multiplier,
        );
        card.querySelector(".draft-item-total-weight").textContent = fmtWeight(
            weight * multiplier,
        );
        updateDraftOrderTotals();
    }

    function updateDraftOrderTotals() {
        const currency =
            document.getElementById("draftOrderCurrency")?.value || "USD";
        document.getElementById("draftOrderTotalCurrency").textContent =
            currency;
        let totalAmount = 0;
        let totalCbm = 0;
        let totalWeight = 0;

        document.querySelectorAll(".draft-order-section").forEach((section) => {
            let sectionAmount = 0;
            let sectionCbm = 0;
            let sectionWeight = 0;
            section.querySelectorAll(".draft-order-item-card").forEach((card) => {
                sectionAmount +=
                    parseFloat(
                        card.querySelector(".draft-item-total-amount")
                            ?.textContent || 0,
                    ) || 0;
                sectionCbm +=
                    parseFloat(
                        card.querySelector(".draft-item-total-cbm")?.textContent ||
                            0,
                    ) || 0;
                sectionWeight +=
                    parseFloat(
                        card.querySelector(".draft-item-total-weight")
                            ?.textContent || 0,
                    ) || 0;
            });
            section.querySelector(".draft-section-amount").textContent =
                fmtAmount(sectionAmount);
            section.querySelector(".draft-section-currency").textContent =
                currency;
            section.querySelector(".draft-section-cbm").textContent =
                fmtCbm(sectionCbm);
            section.querySelector(".draft-section-weight").textContent =
                fmtWeight(sectionWeight);
            syncDraftSectionCollapse(section);
            totalAmount += sectionAmount;
            totalCbm += sectionCbm;
            totalWeight += sectionWeight;
        });

        document.getElementById("draftOrderTotalAmount").textContent =
            fmtAmount(totalAmount);
        document.getElementById("draftOrderTotalCbm").textContent =
            fmtCbm(totalCbm);
        document.getElementById("draftOrderTotalWeight").textContent =
            fmtWeight(totalWeight);
    }

    async function populateFromProduct(card, productSummary) {
        const section = card.closest(".draft-order-section");
        const sectionSupplierId =
            section.querySelector(".draft-section-supplier-id")?.value || "";
        const product = (
            await api("GET", "/products/" + productSummary.id)
        ).data;
        if (
            sectionSupplierId &&
            product.supplier_id &&
            String(product.supplier_id) !== String(sectionSupplierId)
        ) {
            showToast(
                "This product belongs to another supplier. Add another supplier section instead.",
                "danger",
            );
            card.querySelector(".draft-item-product-id").value = "";
            return;
        }

        if (!sectionSupplierId && product.supplier_id && product.supplier_name) {
            section._supplierAc?.setValue({
                id: product.supplier_id,
                name: product.supplier_name,
            });
            section.querySelector(".draft-section-supplier-id").value =
                product.supplier_id;
        }

        card.querySelector(".draft-item-product-id").value = product.id || "";
        card.dataset.dimensionsScope = (
            product.dimensions_scope || "carton"
        ).toLowerCase();
        const entries =
            product.description_entries && product.description_entries.length
                ? product.description_entries
                : [
                      {
                          description_text:
                              product.description_cn ||
                              product.description_en ||
                              "",
                          description_translated:
                              product.description_en ||
                              product.description_cn ||
                              "",
                      },
                  ];
        setDraftDescriptionValue(card, entries);
        if (product.pieces_per_carton != null) {
            card.querySelector(".draft-item-pieces-per-carton").value =
                product.pieces_per_carton;
        }
        if (product.unit_price != null) {
            card.querySelector(".draft-item-unit-price").value =
                product.unit_price;
        }
        if (product.cbm != null) {
            card.querySelector(".draft-item-cbm").value = fmtCbm(product.cbm);
        }
        if (product.weight != null) {
            card.querySelector(".draft-item-weight").value =
                parseFloat(product.weight).toFixed(4);
        }
        card.querySelector(".draft-item-length").value =
            product.length_cm ?? "";
        card.querySelector(".draft-item-width").value =
            product.width_cm ?? "";
        card.querySelector(".draft-item-height").value =
            product.height_cm ?? "";
        card.querySelector(".draft-item-hs-code").value = product.hs_code || "";
        toggleCustomDesignFields(card);
        card.querySelector(".draft-item-product-meta").textContent = [
            product.supplier_name || "",
            product.hs_code ? `HS ${product.hs_code}` : "",
            product.high_alert_note || product.required_design
                ? "Alert / design"
                : "",
        ]
            .filter(Boolean)
            .join(" · ");
        const photoPaths = Array.isArray(product.image_paths)
            ? product.image_paths.slice(0, 1)
            : [];
        card._photoPaths = photoPaths;
        renderFilePills(card.querySelector(".draft-item-photos"), photoPaths);
        updateDraftItemTotals(card);
        renumberDraftItems();
    }

    function toggleCustomDesignFields(card) {
        const checked = card.querySelector(
            ".draft-item-custom-design-required",
        ).checked;
        card.querySelector(".draft-item-custom-design-fields").classList.toggle(
            "d-none",
            !checked,
        );
    }

    function addDraftOrderItem(section, initial = {}) {
        const container = section.querySelector(".draft-section-items");
        const idx = ++itemIndex;
        const wrapper = document.createElement("div");
        wrapper.innerHTML = itemMarkup(idx);
        const card = wrapper.firstElementChild;
        card._photoPaths = (initial.photo_paths || []).slice();
        card._designPaths = (initial.custom_design_paths || []).slice();
        container.appendChild(card);

        if (initial.description_entries?.length) {
            setDraftDescriptionValue(card, initial.description_entries);
        } else if (
            initial.description_cn ||
            initial.description_en ||
            initial.description
        ) {
            setDraftDescriptionValue(card, [
                {
                    description_text:
                        initial.description_cn || initial.description || "",
                    description_translated:
                        initial.description_en || initial.description || "",
                },
            ]);
        } else {
            seedDraftDescriptionFromText(card, "");
        }
        card.querySelector(".draft-item-cartons").value = initial.cartons ?? "";
        card.querySelector(".draft-item-pieces-per-carton").value =
            initial.pieces_per_carton ?? "";
        card.querySelector(".draft-item-unit-price").value =
            initial.unit_price ?? "";
        card.querySelector(".draft-item-cbm").value = initial.cbm ?? "";
        card.querySelector(".draft-item-weight").value = initial.weight ?? "";
        card.querySelector(".draft-item-length").value =
            initial.item_length ?? "";
        card.querySelector(".draft-item-width").value = initial.item_width ?? "";
        card.querySelector(".draft-item-height").value =
            initial.item_height ?? "";
        card.querySelector(".draft-item-hs-code").value = initial.hs_code || "";
        card.querySelector(".draft-item-shipping-code").value =
            initial.shipping_code || "";
        card.querySelector(".draft-item-item-no").value = initial.item_no || "";
        if (initial.shipping_code) card.dataset.manualShippingCode = "1";
        if (initial.item_no) card.dataset.manualItemNo = "1";
        card.dataset.dimensionsScope = (
            initial.dimensions_scope || "carton"
        ).toLowerCase();
        card.querySelector(".draft-item-custom-design-required").checked =
            !!initial.custom_design_required;
        card.querySelector(".draft-item-custom-design-note").value =
            initial.custom_design_note || "";
        toggleCustomDesignFields(card);
        renderFilePills(card.querySelector(".draft-item-photos"), card._photoPaths);
        renderFilePills(
            card.querySelector(".draft-item-design-files"),
            card._designPaths,
        );

        const productInput = card.querySelector(".draft-item-product-search");
        const productIdInput = card.querySelector(".draft-item-product-id");
        const productAc = Autocomplete.init(productInput, {
            resource: "products",
            searchPath: "/search",
            placeholder: "Search existing products or type a new item name...",
            extraParams: () => ({
                supplier_id:
                    section.querySelector(".draft-section-supplier-id")?.value ||
                    "",
            }),
            onSelect: (item) => populateFromProduct(card, item),
        });
        if (initial.product_id) {
            productIdInput.value = initial.product_id;
            productAc?.setValue({
                id: initial.product_id,
                description_en:
                    initial.description_entries?.[0]?.description_translated ||
                    initial.description_entries?.[0]?.description_text ||
                    "Product",
            });
        }
        productInput.addEventListener("input", () => {
            productIdInput.value = "";
            card.querySelector(".draft-item-product-meta").textContent = "";
        });
        productInput.addEventListener("blur", () => {
            const descriptionInput = card.querySelector(
                ".draft-item-description-input",
            );
            if (
                !productIdInput.value &&
                descriptionInput &&
                !descriptionInput.value.trim() &&
                productInput.value.trim()
            ) {
                seedDraftDescriptionFromText(card, productInput.value.trim());
            }
        });

        card.querySelector('[data-builder-action="remove-item"]')?.addEventListener(
            "click",
            () => {
                card.remove();
                renumberDraftItems();
                updateDraftOrderTotals();
            },
        );
        card.querySelector('[data-builder-action="upload-photo"]')?.addEventListener(
            "click",
            () => card.querySelector(".draft-item-photo-upload").click(),
        );
        card.querySelector('[data-builder-action="camera-photo"]')?.addEventListener(
            "click",
            () => PHOTO_UPLOADER.pickPhotos(card.querySelector(".draft-item-photo-camera"), { capture: "environment" }),
        );
        card.querySelector('[data-builder-action="upload-design"]')?.addEventListener(
            "click",
            () => card.querySelector(".draft-item-design-upload").click(),
        );
        card.querySelector(".draft-item-photo-upload")?.addEventListener(
            "change",
            async (event) => {
                try {
                    await uploadPhotoFiles(card, event.target.files);
                    updateDraftOrderTotals();
                } catch (e) {
                    showToast(e.message, "danger");
                } finally {
                    event.target.value = "";
                }
            },
        );
        card.querySelector(".draft-item-photo-camera")?.addEventListener(
            "change",
            async (event) => {
                try {
                    await uploadPhotoFiles(card, event.target.files);
                    updateDraftOrderTotals();
                } catch (e) {
                    showToast(e.message, "danger");
                } finally {
                    event.target.value = "";
                }
            },
        );
        card.querySelector(".draft-item-design-upload")?.addEventListener(
            "change",
            async (event) => {
                try {
                    await uploadDesignFiles(card, event.target.files);
                } catch (e) {
                    showToast(e.message, "danger");
                } finally {
                    event.target.value = "";
                }
            },
        );
        card.querySelector(".draft-item-custom-design-required")?.addEventListener(
            "change",
            () => toggleCustomDesignFields(card),
        );
        card.querySelector(".draft-item-description-input")?.addEventListener(
            "input",
            (event) => {
                event.currentTarget.dataset.descriptionCn = "";
                event.currentTarget.dataset.descriptionEn = "";
            },
        );
        [
            ".draft-item-cartons",
            ".draft-item-pieces-per-carton",
            ".draft-item-unit-price",
            ".draft-item-cbm",
            ".draft-item-weight",
            ".draft-item-length",
            ".draft-item-width",
            ".draft-item-height",
        ].forEach((selector) => {
            card.querySelector(selector)?.addEventListener("input", () =>
                updateDraftItemTotals(card),
            );
        });
        card.querySelector(".draft-item-shipping-code")?.addEventListener(
            "input",
            () => {
                card.dataset.manualShippingCode = "1";
                delete card.dataset.manualItemNo;
                renumberDraftItems();
            },
        );
        card.querySelector(".draft-item-item-no")?.addEventListener(
            "input",
            () => {
                card.dataset.manualItemNo = "1";
            },
        );
        updateDraftItemTotals(card);
        renumberDraftItems();
    }

    function addDraftQuickSupplierPaymentLink(label = "", value = "") {
        const container = document.getElementById("draftQuickSupplierPaymentLinks");
        if (!container) return;
        const row = document.createElement("div");
        row.className = "row g-2 align-items-center";
        row.dataset.idx = String(++quickSupplierPaymentLinkIndex);
        row.innerHTML = `
            <div class="col-4"><input type="text" class="form-control form-control-sm draft-quick-supplier-payment-label" placeholder="WeChat / Bank / Alipay" value="${escapeHtml(label)}"></div>
            <div class="col-7"><input type="text" class="form-control form-control-sm draft-quick-supplier-payment-value" placeholder="Account / number / URL" value="${escapeHtml(value)}"></div>
            <div class="col-1 text-end"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.row').remove()">×</button></div>
        `;
        container.appendChild(row);
    }

    function collectDraftQuickSupplierPaymentLinks() {
        return Array.from(
            document.querySelectorAll(
                "#draftQuickSupplierPaymentLinks .row",
            ),
        )
            .map((row) => {
                const label = row
                    .querySelector(".draft-quick-supplier-payment-label")
                    ?.value?.trim();
                const value = row
                    .querySelector(".draft-quick-supplier-payment-value")
                    ?.value?.trim();
                if (!label && !value) return null;
                return { label: label || "Payment", value: value || "" };
            })
            .filter(Boolean);
    }

    function resetDraftQuickSupplierForm() {
        document.getElementById("draftSupplierQuickForm")?.reset();
        document.getElementById("draftQuickSupplierPaymentLinks").innerHTML = "";
        addDraftQuickSupplierPaymentLink();
    }

    function openDraftQuickSupplier(section) {
        quickSupplierModal =
            quickSupplierModal ||
            bootstrap.Modal.getOrCreateInstance(
                document.getElementById("draftSupplierQuickAddModal"),
            );
        resetDraftQuickSupplierForm();
        document.getElementById("draftQuickSupplierTargetSection").value =
            section.dataset.sectionId || "";
        const selected = section._supplierAc?.getSelected?.();
        if (selected?.name) {
            document.getElementById("draftQuickSupplierName").value =
                selected.name;
        }
        quickSupplierModal.show();
    }

    async function saveDraftQuickSupplier() {
        const targetSectionId =
            document.getElementById("draftQuickSupplierTargetSection").value;
        const targetSection = document.querySelector(
            `.draft-order-section[data-section-id="${CSS.escape(targetSectionId)}"]`,
        );
        if (!targetSection) {
            showToast("Supplier section was not found anymore.", "danger");
            return;
        }
        const payload = {
            code: document.getElementById("draftQuickSupplierCode").value.trim(),
            store_id:
                document.getElementById("draftQuickSupplierStoreId").value.trim() ||
                null,
            name: document.getElementById("draftQuickSupplierName").value.trim(),
            phone:
                document.getElementById("draftQuickSupplierPhone").value.trim() ||
                null,
            commission_rate: document.getElementById("draftQuickSupplierCommission")
                .value
                ? parseFloat(
                      document.getElementById("draftQuickSupplierCommission").value,
                  )
                : null,
            payment_facility_days:
                document.getElementById("draftQuickSupplierFacility").value || 30,
            payment_links: collectDraftQuickSupplierPaymentLinks(),
        };
        if (!payload.code || !payload.name) {
            showToast("Supplier code and name are required.", "danger");
            return;
        }
        const btn = document.getElementById("draftQuickSupplierSaveBtn");
        try {
            btn.disabled = true;
            const res = await api("POST", "/suppliers", payload);
            const supplier = res.data || {};
            const files = Array.from(
                document.getElementById("draftQuickSupplierFiles")?.files || [],
            );
            for (const file of files) {
                const path = await uploadFile(file);
                if (!path) continue;
                await api("POST", "/design-attachments", {
                    entity_type: "supplier",
                    entity_id: parseInt(supplier.id, 10),
                    file_path: path,
                    file_type:
                        (file.name || "").split(".").pop()?.toLowerCase() || null,
                    internal_note: file.name || "Supplier attachment",
                });
            }
            targetSection._supplierAc?.setValue({
                id: supplier.id,
                name: supplier.name,
            });
            targetSection.querySelector(".draft-section-supplier-id").value =
                supplier.id || "";
            refreshSectionProductFilters(targetSection);
            renumberDraftItems();
            syncDraftSectionCollapse(targetSection);
            showToast("Supplier added and selected in this section.");
            quickSupplierModal.hide();
        } catch (error) {
            showToast(error.message || "Failed to save supplier.", "danger");
        } finally {
            btn.disabled = false;
        }
    }

    function collectDescriptionEntries(card) {
        const input = card.querySelector(".draft-item-description-input");
        const value = input?.value?.trim() || "";
        const storedCn = input?.dataset?.descriptionCn?.trim() || "";
        const storedEn = input?.dataset?.descriptionEn?.trim() || "";
        if (!value && !storedCn && !storedEn) {
            return [];
        }
        if (
            storedCn ||
            storedEn
        ) {
            const storedDisplay = draftDescriptionDisplayValue(
                storedCn,
                storedEn,
            );
            if (!value || value === storedDisplay) {
                return [
                    {
                        description_text: storedCn || storedEn || value,
                        description_translated:
                            storedEn || storedCn || value,
                    },
                ];
            }
        }
        return [
            {
                description_text: value,
                description_translated: "",
            },
        ];
    }

    function collectDraftOrderPayload() {
        const customerId = draftOrderCustomerAc?.getSelectedId() || "";
        const expectedReadyDate =
            document.getElementById("draftOrderExpectedDate")?.value || "";
        const supplierSections = Array.from(
            document.querySelectorAll(".draft-order-section"),
        ).map((section) => ({
            supplier_id:
                section.querySelector(".draft-section-supplier-id")?.value || "",
            items: Array.from(
                section.querySelectorAll(".draft-order-item-card"),
            ).map((card) => ({
                product_id:
                    card.querySelector(".draft-item-product-id")?.value || null,
                item_no:
                    card.querySelector(".draft-item-item-no")?.value?.trim() ||
                    null,
                shipping_code:
                    card
                        .querySelector(".draft-item-shipping-code")
                        ?.value?.trim() || null,
                description_entries: collectDescriptionEntries(card),
                pieces_per_carton:
                    card.querySelector(".draft-item-pieces-per-carton")?.value ||
                    null,
                cartons:
                    card.querySelector(".draft-item-cartons")?.value || null,
                unit_price:
                    card.querySelector(".draft-item-unit-price")?.value || null,
                cbm_mode:
                    parseFloat(
                        card.querySelector(".draft-item-cbm")?.value || 0,
                    ) > 0
                        ? "direct"
                        : "dimensions",
                cbm: card.querySelector(".draft-item-cbm")?.value || null,
                item_length:
                    card.querySelector(".draft-item-length")?.value || null,
                item_width:
                    card.querySelector(".draft-item-width")?.value || null,
                item_height:
                    card.querySelector(".draft-item-height")?.value || null,
                weight:
                    card.querySelector(".draft-item-weight")?.value || null,
                hs_code:
                    card.querySelector(".draft-item-hs-code")?.value?.trim() ||
                    null,
                photo_paths: (card._photoPaths || []).slice(),
                custom_design_required: card.querySelector(
                    ".draft-item-custom-design-required",
                )?.checked
                    ? 1
                    : 0,
                custom_design_note:
                    card
                        .querySelector(".draft-item-custom-design-note")
                        ?.value?.trim() || null,
                custom_design_paths: (card._designPaths || []).slice(),
                dimensions_scope:
                    (card.dataset.dimensionsScope || "carton").toLowerCase(),
            })),
        }));

        return {
            customer_id: customerId,
            destination_country_id: getDraftDestinationCountryId() || null,
            expected_ready_date: expectedReadyDate || null,
            currency:
                document.getElementById("draftOrderCurrency")?.value || "USD",
            high_alert_notes:
                document
                    .getElementById("draftOrderHighAlertNotes")
                    ?.value?.trim() || null,
            supplier_sections: supplierSections,
        };
    }

    async function saveDraftOrder() {
        const id = document.getElementById("draftOrderId")?.value || "";
        const payload = collectDraftOrderPayload();
        if (!payload.customer_id) {
            showToast("Customer is required.", "danger");
            return;
        }
        if (
            draftOrderCustomerCountryShipping.length > 1 &&
            !payload.destination_country_id
        ) {
            showToast(
                "Choose one of the selected customer's countries before saving.",
                "danger",
            );
            return;
        }
        if (!payload.supplier_sections.length) {
            showToast("Add at least one supplier section.", "danger");
            return;
        }
        if (
            !payload.expected_ready_date &&
            !confirmMissingDraftExpectedReadyDate(
                id ? "updating this draft order" : "creating this draft order",
            )
        ) {
            return;
        }
        const saveBtn = document.getElementById("draftOrderSaveBtn");
        try {
            setLoading(saveBtn, true);
            const res = id
                ? await api("PUT", "/draft-orders/" + id, payload)
                : await api("POST", "/draft-orders", payload);
            if (res.warning) showToast(res.warning, "warning");
            showToast(id ? "Draft order updated" : "Draft order created");
            builderModal?.hide();
            await Promise.all([loadDraftOrders(), loadLegacyDrafts()]);
        } catch (e) {
            showToast(e.message, "danger");
        } finally {
            setLoading(saveBtn, false);
        }
    }

    async function submitDraftOrder(orderId) {
        try {
            await api("POST", `/orders/${orderId}/submit`, {});
            showToast("Draft order submitted");
            await loadDraftOrders();
        } catch (e) {
            showToast(e.message, "danger");
        }
    }

    function openLegacyMigration(id) {
        migrationModal =
            migrationModal ||
            bootstrap.Modal.getOrCreateInstance(
                document.getElementById("legacyMigrationModal"),
            );
        document.getElementById("legacyMigrationId").value = id;
        document.getElementById("legacyMigrationExpectedDate").value = "";
        document.getElementById("legacyMigrationCurrency").value = "USD";
        legacyMigrationCustomerAc?.setValue(null);
        migrationModal.show();
    }

    async function submitLegacyMigration() {
        const legacyId = document.getElementById("legacyMigrationId").value;
        const payload = {
            customer_id: legacyMigrationCustomerAc?.getSelectedId() || "",
            expected_ready_date:
                document.getElementById("legacyMigrationExpectedDate").value || null,
            currency:
                document.getElementById("legacyMigrationCurrency").value || "USD",
        };
        if (!payload.customer_id) {
            showToast("Customer is required.", "danger");
            return;
        }
        if (
            !payload.expected_ready_date &&
            !confirmMissingDraftExpectedReadyDate(
                "migrating this legacy procurement draft",
            )
        ) {
            return;
        }
        const btn = document.getElementById("legacyMigrationSubmitBtn");
        try {
            setLoading(btn, true);
            const res = await api(
                "POST",
                `/draft-orders/legacy/${legacyId}/migrate`,
                payload,
            );
            migrationModal?.hide();
            showToast(
                res.data?.already_migrated
                    ? "Legacy draft was already migrated"
                    : "Legacy draft migrated",
            );
            await Promise.all([loadDraftOrders(), loadLegacyDrafts()]);
            if (res.data?.order?.id) {
                await openDraftOrderBuilder(res.data.order.id);
            }
        } catch (e) {
            showToast(e.message, "danger");
        } finally {
            setLoading(btn, false);
        }
    }

    document.addEventListener("DOMContentLoaded", async () => {
        builderModal = bootstrap.Modal.getOrCreateInstance(
            document.getElementById("draftOrderModal"),
        );
        migrationModal = bootstrap.Modal.getOrCreateInstance(
            document.getElementById("legacyMigrationModal"),
        );

        draftOrderCustomerAc = Autocomplete.init(
            document.getElementById("draftOrderCustomer"),
            {
                resource: "customers",
                placeholder: "Type to search customer...",
                onSelect: async (item) => {
                    await loadDraftCustomerCountryContext(
                        item.id,
                        item.default_shipping_code || "",
                    );
                },
            },
        );
        draftOrderDestinationCountryAc = Autocomplete.init(
            document.getElementById("draftOrderDestinationCountry"),
            {
                resource: "countries",
                searchPath: "/search",
                minChars: 0,
                placeholder: "Search country...",
                displayValue: (country) =>
                    `${country.name || ""}${country.code ? ` (${country.code})` : ""}`,
                renderItem: (country) =>
                    `${country.name || ""}${country.code ? ` (${country.code})` : ""}`,
                onSelect: (country) => {
                    const idInput = document.getElementById(
                        "draftOrderDestinationCountryId",
                    );
                    if (idInput) idInput.value = country.id || "";
                    const mapping = getDraftDestinationCountryMapping(country.id);
                    setShippingHint(
                        mapping?.shipping_code || getCustomerShipCode(),
                    );
                    renumberDraftItems();
                },
            },
        );
        document
            .getElementById("draftOrderDestinationCountry")
            ?.addEventListener("input", () => {
                if (
                    draftOrderCustomerCountryShipping.length > 1 ||
                    document
                        .getElementById("draftOrderDestinationCountry")
                        ?.readOnly
                ) {
                    return;
                }
                const idInput = document.getElementById(
                    "draftOrderDestinationCountryId",
                );
                if (idInput) idInput.value = "";
            });
        document
            .getElementById("draftOrderDestinationCountrySelect")
            ?.addEventListener("change", function () {
                const selected = getDraftDestinationCountryMapping(this.value);
                const idInput = document.getElementById(
                    "draftOrderDestinationCountryId",
                );
                if (idInput) {
                    idInput.value = this.value || "";
                }
                if (selected) {
                    setDraftDestinationCountry(
                        selected.country_id,
                        selected.country_name,
                        selected.country_code,
                    );
                }
                setShippingHint(
                    selected?.shipping_code || getCustomerShipCode(),
                );
                renumberDraftItems();
            });
        legacyMigrationCustomerAc = Autocomplete.init(
            document.getElementById("legacyMigrationCustomer"),
            {
                resource: "customers",
                placeholder: "Type to search customer...",
            },
        );
        document
            .getElementById("draftOrderCurrency")
            ?.addEventListener("change", updateDraftOrderTotals);

        await Promise.all([loadDraftOrders(), loadLegacyDrafts()]);

        const params = new URLSearchParams(window.location.search);
        const orderId = params.get("order_id");
        const supplierId = params.get("supplier_id");
        if (orderId) {
            openDraftOrderBuilder(parseInt(orderId, 10));
            return;
        }
        if (supplierId) {
            openDraftOrderBuilder();
            setTimeout(async () => {
                const section = document.querySelector(".draft-order-section");
                if (!section) return;
                const res = await api("GET", "/suppliers/" + supplierId);
                if (res.data?.id) {
                    section._supplierAc?.setValue({
                        id: res.data.id,
                        name: res.data.name,
                    });
                    section.querySelector(".draft-section-supplier-id").value =
                        res.data.id;
                }
            }, 50);
        }
    });

    window.openDraftOrderBuilder = openDraftOrderBuilder;
    window.addDraftOrderSection = addDraftOrderSection;
    window.saveDraftOrder = saveDraftOrder;
    window.addDraftQuickSupplierPaymentLink = addDraftQuickSupplierPaymentLink;
    window.saveDraftQuickSupplier = saveDraftQuickSupplier;
    window.submitDraftOrder = submitDraftOrder;
    window.openLegacyMigration = openLegacyMigration;
    window.submitLegacyMigration = submitLegacyMigration;
})();
