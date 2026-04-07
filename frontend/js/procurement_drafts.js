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
    let sharedCartonContentIndex = 0;
    let quickSupplierPaymentLinkIndex = 0;
    let draftOrderCustomerCountryShipping = [];

    function draftT(text, replacements = null) {
        return typeof t === "function" ? t(text, replacements) : text;
    }

    async function api(method, path, body) {
        if (typeof window.api === "function") {
            return window.api(method, path, body);
        }

        const opts = { method, credentials: "same-origin" };
        if (body && (method === "POST" || method === "PUT")) {
            opts.headers = { "Content-Type": "application/json" };
            opts.body = JSON.stringify(body);
        }

        let res;
        try {
            res = await fetch(API + path, opts);
        } catch (_) {
            throw new Error(
                draftT(
                    "Could not reach the server. Check your connection and try again.",
                ),
            );
        }

        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.error) {
            throw new Error(
                data.message ||
                    draftT(
                        "The request could not be completed. Check the highlighted fields and try again.",
                    ),
            );
        }
        return data;
    }

    function trimDisplayNumber(value, maxDecimals = 2) {
        if (typeof window.formatDisplayNumber === "function") {
            return window.formatDisplayNumber(value, { maxDecimals }) || "0";
        }
        const numeric = parseFloat(value);
        if (!Number.isFinite(numeric)) return "0";
        return String(numeric);
    }

    function fmtAmount(value) {
        if (typeof window.formatDisplayAmount === "function") {
            return window.formatDisplayAmount(value) || "0";
        }
        return trimDisplayNumber(value, 2);
    }

    function fmtCbm(value) {
        if (typeof window.formatDisplayCbm === "function") {
            return window.formatDisplayCbm(value, 6) || "0";
        }
        return trimDisplayNumber(value, 6);
    }

    function fmtWeight(value) {
        if (typeof window.formatDisplayWeight === "function") {
            return window.formatDisplayWeight(value, 4) || "0";
        }
        return trimDisplayNumber(value, 4);
    }

    function fmtQty(value) {
        if (typeof window.formatDisplayQuantity === "function") {
            return window.formatDisplayQuantity(value, 4) || "0";
        }
        return trimDisplayNumber(value, 4);
    }

    function fmtFieldNumber(value, maxDecimals = 4) {
        if (value === null || value === undefined) return "";
        const raw = String(value).trim();
        if (!raw) return "";
        return trimDisplayNumber(raw, maxDecimals);
    }

    function getDraftQuickSupplierPaymentMethods() {
        return Array.isArray(window.STANDARD_PAYMENT_METHODS)
            ? window.STANDARD_PAYMENT_METHODS
            : ["WeChat", "Alipay", "Bank Transfer"];
    }

    function renderDraftQuickSupplierPaymentMethodOptions(selected = "") {
        return getDraftQuickSupplierPaymentMethods()
            .map(
                (method) =>
                    `<option value="${escapeHtml(method)}"${method === selected ? " selected" : ""}>${escapeHtml(method)}</option>`,
            )
            .join("");
    }

    function setDraftQuickSupplierQrPreview(row, qrPath = "", fileName = "") {
        const hidden = row.querySelector(".draft-quick-supplier-payment-qr");
        const preview = row.querySelector(
            ".draft-quick-supplier-payment-qr-preview",
        );
        if (!hidden || !preview) return;
        hidden.value = qrPath || "";
        if (!qrPath) {
            preview.classList.add("d-none");
            preview.innerHTML = "";
            return;
        }
        preview.classList.remove("d-none");
        preview.innerHTML = `
            <div class="d-flex align-items-center gap-2 mt-2">
              <a href="${escapeHtml(uploadedFileUrl(qrPath))}" target="_blank" rel="noopener" class="d-inline-flex align-items-center gap-2 text-decoration-none">
                <img src="${escapeHtml(uploadedThumbUrl(qrPath, 48, 48, "cover"))}" alt="${escapeHtml(draftT("QR"))}" style="width:48px;height:48px;object-fit:cover;border-radius:10px;border:1px solid #dbe4f0;" loading="lazy">
                <span class="small text-muted">${escapeHtml(fileName || draftT("QR saved"))}</span>
              </a>
              <button type="button" class="btn btn-sm btn-outline-danger draft-quick-supplier-payment-qr-clear">×</button>
            </div>
        `;
        preview
            .querySelector(".draft-quick-supplier-payment-qr-clear")
            ?.addEventListener("click", () =>
                setDraftQuickSupplierQrPreview(row, ""),
            );
    }

    async function handleDraftQuickSupplierQrFiles(row, files) {
        const list = Array.from(files || []).filter(Boolean);
        if (!list.length) return;
        const file = list[0];
        const path = await uploadFile(file, { category: "supplier-payment-qr" });
        if (!path) return;
        setDraftQuickSupplierQrPreview(row, path, file.name || draftT("QR image"));
        showToast(draftT("Payment QR uploaded"));
    }

    function bindDraftQuickSupplierPaymentRow(row) {
        const fileInput = row.querySelector(
            ".draft-quick-supplier-payment-qr-input",
        );
        const uploadBtn = row.querySelector(
            ".draft-quick-supplier-payment-qr-btn",
        );
        uploadBtn?.addEventListener("click", () => fileInput?.click());
        fileInput?.addEventListener("change", async function () {
            try {
                await handleDraftQuickSupplierQrFiles(row, this.files || []);
            } catch (e) {
                showToast(e.message, "danger");
            } finally {
                this.value = "";
            }
        });
        bindClipboardImagePaste?.(
            row,
            async (files) => {
                try {
                    await handleDraftQuickSupplierQrFiles(row, files);
                } catch (e) {
                    showToast(e.message, "danger");
                }
            },
            {
                requireTargetMatch: true,
                targetMatcher: (target) =>
                    !!target.closest(".draft-quick-supplier-payment-link-row"),
            },
        );
    }

    function parseStructuredItemNo(value) {
        const match = String(value || "")
            .trim()
            .match(/^(.+)-(\d+)-(\d+)$/);
        if (!match) return null;
        return {
            prefix: match[1].trim(),
            supplierSequence: parseInt(match[2], 10),
            itemSequence: parseInt(match[3], 10),
        };
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
            select.innerHTML = `<option value="">${escapeHtml(draftT("Select country..."))}</option>`;
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
            `<option value="">${escapeHtml(draftT("Select country..."))}</option>` +
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
                draftT(
                    "Default shipping code {prefix} is active. Item numbers now follow {prefix}-supplierSequence-itemSequence and stay aligned with supplier groups.",
                    { prefix },
                );
            hint.className = "alert alert-info border mt-3 mb-0 py-2";
            return;
        }
        hint.textContent = "";
        hint.className = "d-none";
    }

    function confirmMissingDraftExpectedReadyDate(actionLabel) {
        return window.confirm(
            draftT(
                "Expected Ready Date is empty. Continue {action} without it? Date-based reminders, overdue tracking, and date filters will skip it until you fill it later.",
                { action: actionLabel },
            ),
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
        const targets = [];
        const supplierOrder = [];
        const supplierSequenceByKey = new Map();
        const manualSupplierSequenceByKey = new Map();
        const usedSupplierSequences = new Set();
        const supplierItemCounts = new Map();

        document.querySelectorAll(".draft-order-section").forEach((section) => {
            const sectionSupplierId =
                section.querySelector(".draft-section-supplier-id")?.value?.trim() || "";
            const sectionKey =
                sectionSupplierId || `section-${section.dataset.sectionId || ""}`;
            section.querySelectorAll(".draft-order-item-card").forEach((card) => {
                const shipInput = card.querySelector(".draft-item-shipping-code");
                if (!shipInput) return;
                shipInput.value = prefix || "";

                if (card.dataset.sharedCartonEnabled === "1") {
                    getDraftSharedCartonRows(card).forEach((row) => {
                        const supplierId =
                            row.querySelector(".draft-shared-content-supplier-id")
                                ?.value?.trim() || sectionSupplierId;
                        const supplierKey =
                            supplierId ||
                            `${sectionKey}-shared-${row.dataset.contentId || ""}`;
                        if (!supplierOrder.includes(supplierKey)) {
                            supplierOrder.push(supplierKey);
                        }
                        const itemNoInput = row.querySelector(
                            ".draft-shared-content-item-no",
                        );
                        const value = itemNoInput?.value?.trim() || "";
                        const manual = !!row.dataset.manualItemNo;
                        if (manual) {
                            const parsed = parseStructuredItemNo(value);
                            if (
                                parsed &&
                                !manualSupplierSequenceByKey.has(supplierKey)
                            ) {
                                manualSupplierSequenceByKey.set(
                                    supplierKey,
                                    parsed.supplierSequence,
                                );
                                usedSupplierSequences.add(parsed.supplierSequence);
                            }
                        }
                        targets.push({
                            supplierKey,
                            input: itemNoInput,
                            manual,
                        });
                    });
                    return;
                }

                const itemNoInput = card.querySelector(".draft-item-item-no");
                if (!itemNoInput) return;
                const supplierKey = sectionKey;
                if (!supplierOrder.includes(supplierKey)) {
                    supplierOrder.push(supplierKey);
                }
                if (card.dataset.manualItemNo) {
                    const parsed = parseStructuredItemNo(itemNoInput.value);
                    if (parsed && !manualSupplierSequenceByKey.has(supplierKey)) {
                        manualSupplierSequenceByKey.set(
                            supplierKey,
                            parsed.supplierSequence,
                        );
                        usedSupplierSequences.add(parsed.supplierSequence);
                    }
                }
                targets.push({
                    supplierKey,
                    input: itemNoInput,
                    manual: !!card.dataset.manualItemNo,
                });
            });
        });

        let nextSupplierSequence = 1;
        supplierOrder.forEach((supplierKey) => {
            if (manualSupplierSequenceByKey.has(supplierKey)) {
                supplierSequenceByKey.set(
                    supplierKey,
                    manualSupplierSequenceByKey.get(supplierKey),
                );
                return;
            }
            while (usedSupplierSequences.has(nextSupplierSequence)) {
                nextSupplierSequence += 1;
            }
            supplierSequenceByKey.set(supplierKey, nextSupplierSequence);
            usedSupplierSequences.add(nextSupplierSequence);
            nextSupplierSequence += 1;
        });

        targets.forEach((target) => {
            const supplierSequence =
                supplierSequenceByKey.get(target.supplierKey) || 1;
            const parsed = parseStructuredItemNo(target.input?.value);
            if (parsed && parsed.supplierSequence === supplierSequence) {
                supplierItemCounts.set(
                    target.supplierKey,
                    Math.max(
                        supplierItemCounts.get(target.supplierKey) || 0,
                        parsed.itemSequence,
                    ),
                );
            }
        });

        targets.forEach((target) => {
            if (!target.input) return;
            if (target.manual) return;
            const supplierSequence =
                supplierSequenceByKey.get(target.supplierKey) || 1;
            const nextItemSequence =
                (supplierItemCounts.get(target.supplierKey) || 0) + 1;
            supplierItemCounts.set(target.supplierKey, nextItemSequence);
            target.input.value = prefix
                ? `${prefix}-${supplierSequence}-${nextItemSequence}`
                : "";
        });
    }

    function buildDraftSupplierSectionLabel(section, collapsed = false) {
        const supplierName =
            section._supplierAc?.getSelected?.()?.name ||
            section.querySelector(".draft-section-supplier")?.value?.trim() ||
            draftT("New supplier section");
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
            button.textContent = collapsed ? draftT("Expand") : draftT("Collapse");
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

    let legacyDraftLoadTimer = null;

    function scheduleLegacyDraftLoad(delay = 150) {
        if (legacyDraftLoadTimer) {
            clearTimeout(legacyDraftLoadTimer);
        }
        legacyDraftLoadTimer = window.setTimeout(async () => {
            legacyDraftLoadTimer = null;
            try {
                await loadLegacyDrafts();
            } catch (error) {
                console.warn("Deferred legacy draft load failed", error);
            }
        }, delay);
    }

    async function refreshDraftLists(opts = {}) {
        const { deferLegacy = false } = opts;
        await loadDraftOrders();
        if (deferLegacy) {
            scheduleLegacyDraftLoad();
            return;
        }
        await loadLegacyDrafts();
    }

    function renderDraftOrders(rows) {
        const tbody = document.querySelector("#draftOrdersTable tbody");
        if (!tbody) return;
        if (!rows.length) {
            tbody.innerHTML =
                `<tr><td colspan="8" class="text-center text-muted py-4">${escapeHtml(draftT("No draft orders yet."))}</td></tr>`;
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
                        <button class="btn btn-sm btn-outline-primary" type="button" onclick="openDraftOrderBuilder(${row.id})">${escapeHtml(draftT(row.editable ? "Open" : "View"))}</button>
                        ${row.status === "Draft" ? `<button class="btn btn-sm btn-success" type="button" onclick="submitDraftOrder(${row.id})">${escapeHtml(draftT("Submit"))}</button>` : ""}
                        <a class="btn btn-sm btn-outline-success" href="${API}/draft-orders/${row.id}/export?format=xlsx" download>XLSX</a>
                        <a class="btn btn-sm btn-outline-secondary" href="/cargochina/procurement_draft_print.php?order_id=${row.id}" target="_blank" rel="noopener">${escapeHtml(draftT("Print"))}</a>
                        <a class="btn btn-sm btn-outline-info" href="/cargochina/orders.php?order_type=draft_procurement">${escapeHtml(draftT("Orders"))}</a>
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
                `<tr><td colspan="6" class="text-center text-muted py-4">${escapeHtml(draftT("No legacy procurement drafts found."))}</td></tr>`;
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
                      <td><span class="badge ${migrated ? "bg-success" : "bg-secondary"}">${escapeHtml(migrated ? draftT("Migrated") : row.status || "draft")}</span></td>
                      <td>${(row.items || []).length}</td>
                      <td class="table-actions">
                        ${migrated && row.converted_order_id ? `<button class="btn btn-sm btn-outline-success" type="button" onclick="openDraftOrderBuilder(${row.converted_order_id})">${escapeHtml(draftT("Open Order"))}</button>` : `<button class="btn btn-sm btn-outline-primary" type="button" onclick="openLegacyMigration(${row.id})">${escapeHtml(draftT("Migrate"))}</button>`}
                        <a class="btn btn-sm btn-outline-secondary" href="/cargochina/procurement_draft_print.php?id=${row.id}" target="_blank" rel="noopener">${escapeHtml(draftT("Print"))}</a>
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
            draftT("Draft an Order");
        document.getElementById("draftOrderModalSubtitle").textContent =
            draftT(
                "One customer, multiple supplier sections, compact item cards, live totals.",
            );
        document.getElementById("draftOrderSections").innerHTML = "";
        document.getElementById("draftOrderTotalAmount").textContent = "0";
        document.getElementById("draftOrderCurrency").value = "RMB";
        document.getElementById("draftOrderTotalCurrency").textContent = "RMB";
        document.getElementById("draftOrderTotalQty").textContent = "0";
        document.getElementById("draftOrderTotalCbm").textContent = "0";
        document.getElementById("draftOrderTotalWeight").textContent = "0";
        draftOrderCustomerAc?.setValue(null);
        sectionIndex = 0;
        itemIndex = 0;
        setBuilderEditable(true);
        resetDraftDestinationCountry();
        setShippingHint("");
        refreshUnsavedBaseline?.(
            document.querySelector("#draftOrderModal .modal-body"),
        );
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
                ? draftT("Edit Draft Order #{id}", { id: order.id })
                : draftT("View Draft Order #{id}", { id: order.id });
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
            order.currency || "RMB";
        document.getElementById("draftOrderHighAlertNotes").value =
            order.high_alert_notes || "";
        (order.supplier_sections || []).forEach((section) =>
            addDraftOrderSection(section),
        );
        document.getElementById("draftOrderTotalCurrency").textContent =
            order.currency || "RMB";
        updateDraftOrderTotals();
        setBuilderEditable(!!order.editable);
        renumberDraftItems();
        refreshUnsavedBaseline?.(
            document.querySelector("#draftOrderModal .modal-body"),
        );
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
            refreshUnsavedBaseline?.(
                document.querySelector("#draftOrderModal .modal-body"),
            );
        }
        builderModal.show();
    }

    function sectionMarkup(sectionId) {
        return `
            <div class="card draft-order-section" data-section-id="${sectionId}">
              <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                  <span class="fw-semibold draft-section-title">${escapeHtml(draftT("Supplier Section"))}</span>
                  <input type="text" class="form-control form-control-sm draft-section-supplier" placeholder="${escapeHtml(draftT("Type to search supplier..."))}" style="width:min(320px, 100%)" autocomplete="off">
                  <input type="hidden" class="draft-section-supplier-id">
                  <button type="button" class="btn btn-outline-primary btn-sm draft-item-action" data-builder-action="quick-add-supplier" title="${escapeHtml(draftT("Quick add supplier"))}">+</button>
                </div>
                <div class="d-flex gap-2 align-items-center flex-wrap">
                  <small class="text-muted"><span class="draft-section-amount">0</span> <span class="draft-section-currency">USD</span> · <span class="draft-section-qty">0</span> qty · <span class="draft-section-cbm">0</span> CBM · <span class="draft-section-weight">0</span> kg</small>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="collapse-section">${escapeHtml(draftT("Collapse"))}</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="move-up">↑</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="move-down">↓</button>
                  <button type="button" class="btn btn-outline-danger btn-sm draft-item-action" data-builder-action="remove-section">${escapeHtml(draftT("Remove Section"))}</button>
                </div>
              </div>
              <div class="card-body">
                <div class="draft-section-items d-flex flex-column gap-3"></div>
                <button type="button" class="btn btn-outline-primary btn-sm mt-3 draft-item-action" data-builder-action="add-item">${escapeHtml(draftT("+ Add Item"))}</button>
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
            placeholder: draftT("Type to search supplier..."),
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
        renderDraftDescriptionEntries(card, entries);
    }

    function seedDraftDescriptionFromText(card, text = "") {
        renderDraftDescriptionEntries(
            card,
            text
                ? [
                      {
                          description_text: text,
                          description_translated: "",
                      },
                  ]
                : [],
        );
    }

    function descriptionEntryMarkup(entry = {}) {
        const cn = (entry?.description_text || entry?.text || "").trim();
        const en =
            (
                entry?.description_translated ||
                entry?.translated ||
                entry?.description_en ||
                ""
            ).trim();
        const display = draftDescriptionDisplayValue(cn, en);
        return `
            <div class="draft-item-description-row">
              <input
                type="text"
                class="form-control form-control-sm draft-item-description-entry-input"
                placeholder="Product name / description"
                value="${escapeHtml(display)}"
                data-description-cn="${escapeHtml(cn).replace(/"/g, "&quot;")}"
                data-description-en="${escapeHtml(en).replace(/"/g, "&quot;")}"
              >
              <button type="button" class="btn btn-outline-danger btn-sm draft-item-action draft-item-description-remove" title="${escapeHtml(draftT("Remove row"))}">×</button>
            </div>
        `;
    }

    function attachDraftDescriptionRowEvents(card, row) {
        row.querySelector(".draft-item-description-entry-input")?.addEventListener(
            "input",
            (event) => {
                event.currentTarget.dataset.descriptionCn = "";
                event.currentTarget.dataset.descriptionEn = "";
            },
        );
        row.querySelector(".draft-item-description-remove")?.addEventListener(
            "click",
            () => {
                const container = card.querySelector(
                    ".draft-item-description-entries",
                );
                row.remove();
                if (container && !container.children.length) {
                    addDraftDescriptionEntry(card);
                } else {
                    syncDraftPrimaryDescriptionInput(
                        card,
                        card.closest(".draft-order-section"),
                    );
                }
            },
        );
    }

    function addDraftDescriptionEntry(card, entry = {}, focus = false) {
        const container = card.querySelector(".draft-item-description-entries");
        if (!container) return;
        const wrapper = document.createElement("div");
        wrapper.innerHTML = descriptionEntryMarkup(entry);
        const row = wrapper.firstElementChild;
        container.appendChild(row);
        attachDraftDescriptionRowEvents(card, row);
        syncDraftPrimaryDescriptionInput(card, card.closest(".draft-order-section"));
        if (focus) {
            row.querySelector(".draft-item-description-entry-input")?.focus();
        }
    }

    function renderDraftDescriptionEntries(card, entries = []) {
        const container = card.querySelector(".draft-item-description-entries");
        if (!container) return;
        container.innerHTML = "";
        const list = Array.isArray(entries) ? entries.filter(Boolean) : [];
        if (!list.length) {
            addDraftDescriptionEntry(card);
            return;
        }
        list.forEach((entry) => addDraftDescriptionEntry(card, entry));
        syncDraftPrimaryDescriptionInput(card, card.closest(".draft-order-section"));
    }

    function syncDraftPrimaryDescriptionInput(card, section) {
        const inputs = Array.from(
            card.querySelectorAll(".draft-item-description-entry-input"),
        );
        if (!inputs.length) return;

        inputs.forEach((input, index) => {
            input.classList.toggle(
                "draft-item-description-primary",
                index === 0,
            );
            input.placeholder =
                index === 0
                    ? "Type description — search existing products or enter manually"
                    : "Additional name / description";
        });

        const primaryInput = inputs[0];
        if (!primaryInput) return;

        if (!primaryInput.dataset.productSearchBound) {
            primaryInput.dataset.productSearchBound = "1";
            const productIdInput = card.querySelector(".draft-item-product-id");
            primaryInput.addEventListener("input", () => {
                productIdInput.value = "";
                const meta = card.querySelector(".draft-item-product-meta");
                if (meta) meta.textContent = "";
            });
        }

        if (card._draftPrimaryDescriptionInput === primaryInput) {
            return;
        }

        card._draftPrimaryDescriptionInput = primaryInput;
        card._productAc = Autocomplete.init(primaryInput, {
            resource: "products",
            searchPath: "/search",
            placeholder:
                "Type description — search existing products or enter manually",
            extraParams: () => ({
                supplier_id:
                    section?.querySelector(".draft-section-supplier-id")?.value ||
                    "",
            }),
            renderItem: (product) =>
                `${product.description_cn || product.description_en || ""}${product.high_alert_note || product.required_design ? " — Alert" : ""}${product.hs_code ? ` — HS ${product.hs_code}` : ""}`
                    .replace(/^ — | — $/g, "")
                    .trim() || `#${product.id}`,
            onSelect: (item) => populateFromProduct(card, item),
        });
    }

    function getDraftSharedCartonRows(card) {
        return Array.from(
            card.querySelectorAll(".draft-shared-carton-row"),
        );
    }

    function sharedCartonContentMarkup(contentId) {
        return `
            <div class="draft-shared-carton-row" data-content-id="${contentId}">
              <div class="row g-2 align-items-start">
                <div class="col-12 col-xl-2">
                  <label class="form-label draft-item-label">Supplier</label>
                  <input type="text" class="form-control form-control-sm draft-shared-content-supplier" placeholder="${escapeHtml(draftT("Type to search supplier..."))}" autocomplete="off">
                  <input type="hidden" class="draft-shared-content-supplier-id">
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">Item No</label>
                  <input type="text" class="form-control form-control-sm draft-shared-content-item-no" placeholder="${escapeHtml(draftT("Auto"))}">
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">Qty / Carton</label>
                  <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-shared-content-qty-per-carton" placeholder="0">
                </div>
                <div class="col-12 col-xl-3">
                  <label class="form-label draft-item-label">Description</label>
                  <input type="text" class="form-control form-control-sm draft-shared-content-description-input" placeholder="${escapeHtml(draftT("Search for a product or add a new one."))}">
                  <input type="hidden" class="draft-shared-content-product-id">
                  <div class="form-text draft-shared-content-meta"></div>
                </div>
                <div class="col-12 col-sm-6 col-xl-1">
                  <label class="form-label draft-item-label">Factory</label>
                  <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-shared-content-unit-price" placeholder="0">
                </div>
                <div class="col-12 col-sm-6 col-xl-1">
                  <label class="form-label draft-item-label">Customer</label>
                  <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-shared-content-sell-price" placeholder="0">
                </div>
                <div class="col-12 col-sm-6 col-xl-1">
                  <label class="form-label draft-item-label">Total Qty</label>
                  <div class="draft-item-computed draft-shared-content-total-qty">0</div>
                </div>
                <div class="col-12 col-sm-6 col-xl-1">
                  <label class="form-label draft-item-label">Total</label>
                  <div class="draft-item-computed draft-shared-content-total-amount">0</div>
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">HS Code</label>
                  <input type="text" class="form-control form-control-sm draft-shared-content-hs-code" placeholder="${escapeHtml(draftT("HS code"))}">
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">Notes</label>
                  <input type="text" class="form-control form-control-sm draft-shared-content-notes" placeholder="${escapeHtml(draftT("Optional note"))}">
                </div>
                <div class="col-12 col-xl-1 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-danger btn-sm w-100 draft-item-action draft-shared-content-remove">${escapeHtml(draftT("Remove"))}</button>
                </div>
              </div>
            </div>
        `;
    }

    function setDraftSharedCartonDescription(row, entry = {}) {
        const input = row.querySelector(".draft-shared-content-description-input");
        if (!input) return;
        const cn = (
            entry.description_text ||
            entry.text ||
            entry.description_cn ||
            ""
        ).trim();
        const en = (
            entry.description_translated ||
            entry.translated ||
            entry.description_en ||
            ""
        ).trim();
        input.value = draftDescriptionDisplayValue(cn, en);
        input.dataset.descriptionCn = cn;
        input.dataset.descriptionEn = en;
    }

    function collectDraftSharedCartonDescription(row) {
        const input = row.querySelector(".draft-shared-content-description-input");
        if (!input) return [];
        const value = input.value.trim();
        const storedCn = (input.dataset.descriptionCn || "").trim();
        const storedEn = (input.dataset.descriptionEn || "").trim();
        if (!value && !storedCn && !storedEn) return [];
        if (storedCn || storedEn) {
            const storedDisplay = draftDescriptionDisplayValue(storedCn, storedEn);
            if (!value || value === storedDisplay) {
                return [
                    {
                        description_text: storedCn || storedEn || value,
                        description_translated: storedEn || storedCn || value,
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

    async function populateDraftSharedCartonContentFromProduct(card, row, productSummary) {
        const section = card.closest(".draft-order-section");
        const product = (await api("GET", "/products/" + productSummary.id)).data;
        const supplierValue = {
            id: product.supplier_id || "",
            name: product.supplier_name || "",
        };
        if (supplierValue.id && row._supplierAc) {
            row._supplierAc.setValue(supplierValue);
            row.querySelector(".draft-shared-content-supplier-id").value =
                supplierValue.id;
        }

        row.querySelector(".draft-shared-content-product-id").value =
            product.id || "";
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
        setDraftSharedCartonDescription(row, entries[0] || {});
        if (
            !row.querySelector(".draft-shared-content-qty-per-carton")?.value &&
            product.pieces_per_carton != null
        ) {
            row.querySelector(".draft-shared-content-qty-per-carton").value =
                fmtFieldNumber(product.pieces_per_carton, 4);
        }
        const factoryPrice =
            product.buy_price != null && product.buy_price !== ""
                ? product.buy_price
                : product.unit_price;
        if (factoryPrice != null && factoryPrice !== "") {
            row.querySelector(".draft-shared-content-unit-price").value =
                fmtFieldNumber(factoryPrice, 4);
        }
        if (product.sell_price != null && product.sell_price !== "") {
            row.querySelector(".draft-shared-content-sell-price").value =
                fmtFieldNumber(product.sell_price, 4);
        }
        row.querySelector(".draft-shared-content-hs-code").value =
            product.hs_code || "";
        row.querySelector(".draft-shared-content-meta").textContent = [
            product.supplier_name || "",
            product.hs_code ? `HS ${product.hs_code}` : "",
        ]
            .filter(Boolean)
            .join(" · ");
        updateDraftItemTotals(card);
        renumberDraftItems();
        refreshSectionProductFilters(section);
    }

    function syncDraftSharedCartonProductSearch(card, row, section) {
        const input = row.querySelector(".draft-shared-content-description-input");
        if (!input) return;
        row._productAc = Autocomplete.init(input, {
            resource: "products",
            searchPath: "/search",
            placeholder: draftT("Search for a product or add a new one."),
            extraParams: () => ({
                supplier_id:
                    row.querySelector(".draft-shared-content-supplier-id")
                        ?.value ||
                    section?.querySelector(".draft-section-supplier-id")?.value ||
                    "",
            }),
            renderItem: (product) =>
                `${product.description_cn || product.description_en || ""}${product.supplier_name ? ` — ${product.supplier_name}` : ""}${product.hs_code ? ` — HS ${product.hs_code}` : ""}`
                    .replace(/^ — | — $/g, "")
                    .trim() || `#${product.id}`,
            onSelect: (item) =>
                populateDraftSharedCartonContentFromProduct(card, row, item),
        });
    }

    function bindDraftSharedCartonSupplierAutocomplete(card, row, section) {
        const supplierInput = row.querySelector(".draft-shared-content-supplier");
        const supplierIdInput = row.querySelector(
            ".draft-shared-content-supplier-id",
        );
        if (!supplierInput || !supplierIdInput) return;
        row._supplierAc = Autocomplete.init(supplierInput, {
            resource: "suppliers",
            placeholder: draftT("Type to search supplier..."),
            onSelect: (item) => {
                supplierIdInput.value = item.id || "";
                syncDraftSharedCartonProductSearch(card, row, section);
                renumberDraftItems();
                updateDraftItemTotals(card);
            },
        });
        supplierInput.addEventListener("input", () => {
            supplierIdInput.value = "";
            row.querySelector(".draft-shared-content-product-id").value = "";
            syncDraftSharedCartonProductSearch(card, row, section);
            renumberDraftItems();
            updateDraftItemTotals(card);
        });
    }

    function addDraftSharedCartonContentRow(card, initial = {}) {
        const section = card.closest(".draft-order-section");
        const container = card.querySelector(".draft-shared-carton-rows");
        if (!container) return null;
        const wrapper = document.createElement("div");
        wrapper.innerHTML = sharedCartonContentMarkup(++sharedCartonContentIndex);
        const row = wrapper.firstElementChild;
        container.appendChild(row);

        bindDraftSharedCartonSupplierAutocomplete(card, row, section);
        syncDraftSharedCartonProductSearch(card, row, section);

        if (initial.supplier_id && initial.supplier_name && row._supplierAc) {
            row._supplierAc.setValue({
                id: initial.supplier_id,
                name: initial.supplier_name,
            });
            row.querySelector(".draft-shared-content-supplier-id").value =
                initial.supplier_id;
        }
        row.querySelector(".draft-shared-content-product-id").value =
            initial.product_id || "";
        row.querySelector(".draft-shared-content-item-no").value =
            initial.item_no || "";
        if (initial.item_no_manual || initial.item_no) {
            row.dataset.manualItemNo = initial.item_no_manual || initial.item_no
                ? "1"
                : "";
        }
        row.querySelector(".draft-shared-content-qty-per-carton").value =
            initial.quantity_per_carton ?? "";
        row.querySelector(".draft-shared-content-unit-price").value =
            initial.unit_price ?? "";
        row.querySelector(".draft-shared-content-sell-price").value =
            initial.sell_price ?? "";
        row.querySelector(".draft-shared-content-hs-code").value =
            initial.hs_code || "";
        row.querySelector(".draft-shared-content-notes").value =
            initial.notes || "";

        if (initial.description_entries?.length) {
            setDraftSharedCartonDescription(row, initial.description_entries[0]);
        } else if (initial.description_cn || initial.description_en) {
            setDraftSharedCartonDescription(row, {
                description_text: initial.description_cn || "",
                description_translated: initial.description_en || "",
            });
        }
        row.querySelector(".draft-shared-content-meta").textContent = [
            initial.supplier_name || "",
            initial.hs_code ? `HS ${initial.hs_code}` : "",
        ]
            .filter(Boolean)
            .join(" · ");

        row.querySelector(".draft-shared-content-description-input")?.addEventListener(
            "input",
            (event) => {
                event.currentTarget.dataset.descriptionCn = "";
                event.currentTarget.dataset.descriptionEn = "";
                row.querySelector(".draft-shared-content-product-id").value = "";
            },
        );
        [
            ".draft-shared-content-qty-per-carton",
            ".draft-shared-content-unit-price",
            ".draft-shared-content-sell-price",
            ".draft-shared-content-hs-code",
            ".draft-shared-content-notes",
        ].forEach((selector) => {
            row.querySelector(selector)?.addEventListener("input", () =>
                updateDraftItemTotals(card),
            );
        });
        row.querySelector(".draft-shared-content-item-no")?.addEventListener(
            "input",
            () => {
                row.dataset.manualItemNo = row
                    .querySelector(".draft-shared-content-item-no")
                    ?.value?.trim()
                    ? "1"
                    : "";
                renumberDraftItems();
            },
        );
        row.querySelector(".draft-shared-content-remove")?.addEventListener(
            "click",
            () => {
                row.remove();
                if (!getDraftSharedCartonRows(card).length) {
                    addDraftSharedCartonContentRow(card);
                }
                renumberDraftItems();
                updateDraftItemTotals(card);
            },
        );

        updateDraftItemTotals(card);
        renumberDraftItems();
        return row;
    }

    function calculateDraftSharedCartonSummary(card) {
        const cartons =
            parseFloat(card.querySelector(".draft-item-cartons")?.value || 0) ||
            0;
        let piecesPerCarton = 0;
        let totalQty = 0;
        let buyTotal = 0;
        let sellTotal = 0;
        let hasBuy = false;
        let hasSell = false;
        const suppliers = new Set();

        getDraftSharedCartonRows(card).forEach((row) => {
            const qtyPerCarton =
                parseFloat(
                    row.querySelector(".draft-shared-content-qty-per-carton")
                        ?.value || 0,
                ) || 0;
            const totalQuantity = cartons > 0 ? cartons * qtyPerCarton : 0;
            const unitPrice =
                parseFloat(
                    row.querySelector(".draft-shared-content-unit-price")
                        ?.value || 0,
                ) || 0;
            const sellPrice =
                parseFloat(
                    row.querySelector(".draft-shared-content-sell-price")
                        ?.value || 0,
                ) || 0;
            const priceForTotal = sellPrice > 0 ? sellPrice : unitPrice;
            const supplierName =
                row._supplierAc?.getSelected?.()?.name ||
                row.querySelector(".draft-shared-content-supplier")?.value?.trim() ||
                "";

            if (supplierName) suppliers.add(supplierName);
            piecesPerCarton += qtyPerCarton;
            totalQty += totalQuantity;
            row.querySelector(".draft-shared-content-total-qty").textContent =
                fmtQty(totalQuantity);
            row.querySelector(".draft-shared-content-total-amount").textContent =
                fmtAmount(totalQuantity * priceForTotal);

            if (unitPrice > 0) {
                hasBuy = true;
                buyTotal += totalQuantity * unitPrice;
            }
            if (sellPrice > 0) {
                hasSell = true;
                sellTotal += totalQuantity * sellPrice;
            }
        });

        const priceForSummary = hasSell ? sellTotal : hasBuy ? buyTotal : 0;
        return {
            piecesPerCarton,
            totalQty,
            buyTotal,
            sellTotal,
            hasBuy,
            hasSell,
            unitPrice:
                hasBuy && totalQty > 0 ? buyTotal / totalQty : 0,
            customerPrice:
                (hasSell || hasBuy) && totalQty > 0
                    ? (hasSell ? sellTotal : buyTotal) / totalQty
                    : 0,
            totalAmount: priceForSummary,
            supplierNames: Array.from(suppliers),
        };
    }

    function syncDraftSharedCartonMode(card, forceEnabled = null) {
        const toggle = card.querySelector(".draft-item-shared-carton-toggle");
        if (!toggle) return;
        const enabled =
            forceEnabled !== null ? !!forceEnabled : !!toggle.checked;
        toggle.checked = enabled;
        card.dataset.sharedCartonEnabled = enabled ? "1" : "";
        card.classList.toggle("draft-order-item-card--shared", enabled);

        card.querySelector(".draft-shared-carton-panel")?.classList.toggle(
            "d-none",
            !enabled,
        );
        card.querySelector(".draft-item-descriptions-panel")?.classList.toggle(
            "d-none",
            enabled,
        );
        card.querySelector(".draft-item-identity-hs-wrap")?.classList.toggle(
            "d-none",
            enabled,
        );

        const identityLabel = card.querySelector(".draft-item-identity-label");
        if (identityLabel) {
            identityLabel.textContent = enabled
                ? draftT("Carton Code")
                : draftT("Item No");
        }
        const itemNoInput = card.querySelector(".draft-item-item-no");
        if (itemNoInput) {
            itemNoInput.placeholder = enabled
                ? draftT("Optional carton code")
                : draftT("Auto");
        }

        const piecesInput = card.querySelector(".draft-item-pieces-per-carton");
        const unitPriceInput = card.querySelector(".draft-item-unit-price");
        const customerPriceInput = card.querySelector(".draft-item-customer-price");
        [piecesInput, unitPriceInput, customerPriceInput].forEach((input) => {
            if (!input) return;
            input.readOnly = enabled;
            input.classList.toggle("bg-light", enabled);
        });

        if (enabled) {
            delete card.dataset.manualItemNo;
            card.querySelector(".draft-item-product-id").value = "";
            if (!getDraftSharedCartonRows(card).length) {
                addDraftSharedCartonContentRow(card);
            }
        }

        updateDraftItemTotals(card);
        renumberDraftItems();
    }

    function draftDescriptionHasContent(card) {
        return Array.from(
            card.querySelectorAll(".draft-item-description-entry-input"),
        ).some((input) => input.value.trim());
    }

    function renderDraftPhotoThumbs(container, paths, removable = true) {
        container.innerHTML = (paths || [])
            .map(
                (path, index) => `
                    <div class="draft-item-photo-thumb" data-path="${escapeHtml(path).replace(/"/g, "&quot;")}">
                      <a href="${escapeHtml(uploadedFileUrl(path))}" target="_blank" rel="noopener">
                        <img src="${escapeHtml(uploadedThumbUrl(path, 128, 128, "cover"))}" alt="Item photo" loading="lazy">
                      </a>
                      ${
                          removable
                              ? `<button type="button" class="btn btn-sm btn-light border draft-item-action draft-item-photo-remove" data-remove-index="${index}" title="Remove photo">×</button>`
                              : ""
                      }
                    </div>
                `,
            )
            .join("");
        container.querySelectorAll("[data-remove-index]").forEach((btn) => {
            btn.addEventListener("click", () => {
                const idx = parseInt(btn.dataset.removeIndex || "-1", 10);
                if (idx < 0) return;
                paths.splice(idx, 1);
                renderDraftPhotoThumbs(container, paths, removable);
            });
        });
    }

    function itemMarkup(idx) {
        return `
            <div class="border rounded p-3 draft-order-item-card" data-item-id="${idx}" data-dimensions-scope="carton">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                  <div class="fw-semibold text-dark">Item Line</div>
                  <small class="text-muted">Compact draft item with photo, description, auto numbering, and live totals.</small>
                </div>
                <button type="button" class="btn btn-outline-danger btn-sm draft-item-action" data-builder-action="remove-item">Remove Item</button>
              </div>
              <div class="row g-2 align-items-start">
                <div class="col-12 col-xl-3 col-xxl-2">
                  <div class="draft-item-sidebar">
                    <div class="draft-item-panel draft-item-photo-panel">
                      <label class="form-label form-label-sm">Photo</label>
                      <div class="draft-item-photos"></div>
                      <input type="file" class="d-none draft-item-photo-upload" accept="image/*" multiple>
                      <input type="file" class="d-none draft-item-photo-camera" accept="image/*" capture="environment">
                      <div class="d-grid gap-2 mt-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action draft-item-photo-btn" data-builder-action="upload-photo">+ Add</button>
                        <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="camera-photo">Camera</button>
                      </div>
                    </div>
                    <div class="draft-item-stats draft-item-sidebar-stats">
                      <div class="draft-item-stat">
                        <span class="draft-item-stat-label">Total Qty</span>
                        <div class="draft-item-computed draft-item-total-qty">0</div>
                      </div>
                      <div class="draft-item-stat">
                        <span class="draft-item-stat-label">Total Amount</span>
                        <div class="draft-item-computed draft-item-total-amount">0</div>
                      </div>
                      <div class="draft-item-stat">
                        <span class="draft-item-stat-label">Total CBM</span>
                        <div class="draft-item-computed draft-item-total-cbm">0</div>
                      </div>
                      <div class="draft-item-stat">
                        <span class="draft-item-stat-label">Total Weight</span>
                        <div class="draft-item-computed draft-item-total-weight">0</div>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="col-12 col-xl-9 col-xxl-10">
                  <div class="row g-2">
                    <div class="col-12 col-lg-3 col-xl-3">
                      <div class="draft-item-panel draft-item-identity-panel h-100">
                        <div class="form-check form-switch mb-2">
                          <input class="form-check-input draft-item-shared-carton-toggle" type="checkbox" id="draftItemSharedCarton${idx}">
                          <label class="form-check-label small fw-semibold" for="draftItemSharedCarton${idx}">${escapeHtml(draftT("This carton contains multiple items"))}</label>
                        </div>
                        <label class="form-label form-label-sm draft-item-identity-label">Item No</label>
                        <input type="text" class="form-control form-control-sm draft-item-item-no" placeholder="Auto">
                        <input type="hidden" class="draft-item-shipping-code">
                        <div class="draft-item-identity-hs-wrap">
                          <label class="form-label form-label-sm mt-2">Optional HS Code</label>
                          <input type="text" class="form-control form-control-sm draft-item-hs-code" placeholder="HS code">
                        </div>
                      </div>
                    </div>
                    <div class="col-12 col-lg-9 col-xl-9">
                      <div class="draft-item-panel draft-item-descriptions-panel">
                        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                          <div class="d-flex align-items-center gap-2 flex-wrap">
                            <div class="draft-item-panel-title mb-0">Description</div>
                            <small class="text-muted">Search for a product or add a new one.</small>
                          </div>
                          <button type="button" class="btn btn-outline-primary btn-sm draft-item-action" data-builder-action="add-description-entry">+ Add name</button>
                        </div>
                        <input type="hidden" class="draft-item-product-id">
                        <div class="draft-item-description-entries d-flex flex-column gap-2"></div>
                        <div class="form-text draft-item-product-meta"></div>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="draft-item-panel draft-shared-carton-panel d-none">
                        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap mb-2">
                          <div>
                            <div class="draft-item-subgrid-title mb-1">${escapeHtml(draftT("Contained items in this carton"))}</div>
                            <small class="text-muted draft-shared-carton-summary">${escapeHtml(draftT("Add the packed items below. Suppliers can vary inside the same carton."))}</small>
                          </div>
                          <button type="button" class="btn btn-outline-primary btn-sm draft-item-action" data-builder-action="add-shared-content">${escapeHtml(draftT("+ Add Contained Item"))}</button>
                        </div>
                        <div class="draft-shared-carton-rows d-flex flex-column gap-2"></div>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="draft-item-panel draft-item-subgrid">
                        <div class="draft-item-subgrid-block">
                          <div class="draft-item-subgrid-title">Packaging</div>
                          <div class="row g-2">
                            <div class="col-4">
                              <label class="form-label draft-item-label">Total Cartons</label>
                              <input type="number" step="1" min="0" class="form-control form-control-sm draft-item-cartons" placeholder="0">
                            </div>
                            <div class="col-4">
                              <label class="form-label draft-item-label">Pieces / Carton</label>
                              <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-item-pieces-per-carton" placeholder="0">
                            </div>
                            <div class="col-4">
                              <label class="form-label draft-item-label">Total Qty</label>
                              <div class="draft-item-computed draft-item-total-qty-inline">0</div>
                            </div>
                          </div>
                        </div>
                        <div class="draft-item-subgrid-block">
                          <div class="draft-item-subgrid-title">Pricing</div>
                          <div class="row g-2">
                            <div class="col-6">
                              <label class="form-label draft-item-label">Factory Price</label>
                              <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-item-unit-price" placeholder="0">
                            </div>
                            <div class="col-6">
                              <label class="form-label draft-item-label">Total Amount</label>
                              <div class="draft-item-computed draft-item-total-amount-inline">0</div>
                            </div>
                          </div>
                          <div class="form-text draft-item-pricing-hint">Total amount follows customer price when set, otherwise factory price.</div>
                        </div>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="draft-item-panel draft-item-volume-panel">
                        <div class="draft-item-subgrid-title mb-2">Volume & Weight</div>
                        <div class="draft-item-volume-fields">
                          <div class="draft-item-volume-field">
                            <label class="form-label draft-item-label">CBM</label>
                            <input type="number" step="0.000001" min="0" class="form-control form-control-sm draft-item-cbm" placeholder="CBM">
                          </div>
                          <span class="draft-item-or">or</span>
                          <div class="draft-item-volume-field">
                            <label class="form-label draft-item-label">Length</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm draft-item-length" placeholder="L">
                          </div>
                          <div class="draft-item-volume-field">
                            <label class="form-label draft-item-label">Width</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm draft-item-width" placeholder="W">
                          </div>
                          <div class="draft-item-volume-field">
                            <label class="form-label draft-item-label">Height</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm draft-item-height" placeholder="H">
                          </div>
                          <div class="draft-item-volume-field">
                            <label class="form-label draft-item-label">Weight (kg)</label>
                            <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-item-weight" placeholder="Weight">
                          </div>
                          <div class="draft-item-volume-field">
                            <label class="form-label draft-item-label">Customer Price</label>
                            <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-item-customer-price" placeholder="Export">
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="col-12">
                      <div class="draft-item-panel draft-item-design-panel">
                        <div class="form-check mb-2">
                          <input class="form-check-input draft-item-custom-design-required" type="checkbox" id="draftItemCustomDesign${idx}">
                          <label class="form-check-label fw-semibold" for="draftItemCustomDesign${idx}">Custom design</label>
                        </div>
                        <div class="draft-item-custom-design-fields d-none">
                          <label class="form-label form-label-sm">Custom design note</label>
                          <textarea class="form-control form-control-sm draft-item-custom-design-note" rows="2" placeholder="Reference design note or internal reminder..."></textarea>
                          <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                            <small class="text-muted">Attach custom design files if the supplier needs them.</small>
                            <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="upload-design">Upload Design</button>
                          </div>
                          <input type="file" class="d-none draft-item-design-upload" accept="image/*,application/pdf,.pdf" multiple>
                          <div class="draft-item-design-files d-flex flex-wrap gap-2 mt-3"></div>
                        </div>
                      </div>
                    </div>
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
        renderDraftPhotoThumbs(card.querySelector(".draft-item-photos"), paths);
    }

    async function uploadDesignFiles(card, files) {
        const paths = card._designPaths || [];
        const uploaded = await uploadFiles(files, false);
        uploaded.forEach((path) => paths.push(path));
        card._designPaths = paths;
        renderFilePills(card.querySelector(".draft-item-design-files"), paths);
    }

    function refreshSectionProductFilters(section) {
        section.querySelectorAll(".draft-order-item-card").forEach((card) => {
            syncDraftPrimaryDescriptionInput(card, section);
            getDraftSharedCartonRows(card).forEach((row) =>
                syncDraftSharedCartonProductSearch(card, row, section),
            );
        });
    }

    function syncDraftItemCbmFromDimensions(card) {
        if (!card) return;
        const cbmInput = card.querySelector(".draft-item-cbm");
        if (!cbmInput) return;
        const l =
            parseFloat(card.querySelector(".draft-item-length")?.value || 0) ||
            0;
        const w =
            parseFloat(card.querySelector(".draft-item-width")?.value || 0) || 0;
        const h =
            parseFloat(card.querySelector(".draft-item-height")?.value || 0) ||
            0;
        const hasAllDimensions = l > 0 && w > 0 && h > 0;
        const isAutoDerived = card.dataset.cbmAutoDerived === "1";

        if (hasAllDimensions) {
            const computedCbm = (l * w * h) / 1000000;
            if (!cbmInput.value.trim() || isAutoDerived) {
                cbmInput.value = fmtFieldNumber(computedCbm, 6);
                card.dataset.cbmAutoDerived = "1";
            }
            return;
        }

        if (isAutoDerived) {
            cbmInput.value = "";
            delete card.dataset.cbmAutoDerived;
        }
    }

    function updateDraftItemTotals(card) {
        syncDraftItemCbmFromDimensions(card);
        const cartons =
            parseFloat(card.querySelector(".draft-item-cartons")?.value || 0) ||
            0;
        const sharedMode = card.dataset.sharedCartonEnabled === "1";
        let ppc =
            parseFloat(
                card.querySelector(".draft-item-pieces-per-carton")?.value || 0,
            ) || 0;
        let qty = cartons > 0 && ppc > 0 ? cartons * ppc : 0;
        let unitPrice =
            parseFloat(
                card.querySelector(".draft-item-unit-price")?.value || 0,
            ) || 0;
        let customerPrice =
            parseFloat(
                card.querySelector(".draft-item-customer-price")?.value || 0,
            ) || 0;
        let totalAmountValue = qty * (customerPrice > 0 ? customerPrice : unitPrice);
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

        if (sharedMode) {
            const sharedSummary = calculateDraftSharedCartonSummary(card);
            ppc = sharedSummary.piecesPerCarton;
            qty = sharedSummary.totalQty;
            unitPrice = sharedSummary.unitPrice;
            customerPrice = sharedSummary.customerPrice;
            totalAmountValue = sharedSummary.totalAmount;
            const piecesInput = card.querySelector(".draft-item-pieces-per-carton");
            const unitPriceInput = card.querySelector(".draft-item-unit-price");
            const customerPriceInput = card.querySelector(
                ".draft-item-customer-price",
            );
            if (piecesInput) {
                piecesInput.value = fmtFieldNumber(ppc, 4);
            }
            if (unitPriceInput) {
                unitPriceInput.value = fmtFieldNumber(unitPrice, 4);
            }
            if (customerPriceInput) {
                customerPriceInput.value = fmtFieldNumber(customerPrice, 4);
            }
            const summaryEl = card.querySelector(".draft-shared-carton-summary");
            if (summaryEl) {
                const supplierSummary = sharedSummary.supplierNames.length
                    ? sharedSummary.supplierNames.join(", ")
                    : draftT("Add the packed items below. Suppliers can vary inside the same carton.");
                summaryEl.textContent = supplierSummary;
            }
        }

        card.querySelector(".draft-item-total-qty").textContent = fmtQty(qty);
        const qtyInline = card.querySelector(".draft-item-total-qty-inline");
        if (qtyInline) {
            qtyInline.textContent = fmtQty(qty);
        }
        card.querySelector(".draft-item-total-amount").textContent = fmtAmount(
            totalAmountValue,
        );
        const amountInline = card.querySelector(
            ".draft-item-total-amount-inline",
        );
        if (amountInline) {
            amountInline.textContent = fmtAmount(totalAmountValue);
        }
        card.querySelector(".draft-item-total-cbm").textContent = fmtCbm(
            cbm * multiplier,
        );
        card.querySelector(".draft-item-total-weight").textContent = fmtWeight(
            weight * multiplier,
        );
        const pricingHint = card.querySelector(".draft-item-pricing-hint");
        if (pricingHint) {
            pricingHint.textContent =
                sharedMode
                    ? "Carton totals are derived from the contained items below."
                    : customerPrice > 0
                      ? "Total amount follows customer price."
                      : "Total amount follows factory price until customer price is set.";
        }
        updateDraftOrderTotals();
    }

    function updateDraftOrderTotals() {
        const currency =
            document.getElementById("draftOrderCurrency")?.value || "USD";
        document.getElementById("draftOrderTotalCurrency").textContent =
            currency;
        let totalAmount = 0;
        let totalQty = 0;
        let totalCbm = 0;
        let totalWeight = 0;

        document.querySelectorAll(".draft-order-section").forEach((section) => {
            let sectionAmount = 0;
            let sectionQty = 0;
            let sectionCbm = 0;
            let sectionWeight = 0;
            section.querySelectorAll(".draft-order-item-card").forEach((card) => {
                sectionAmount +=
                    parseFloat(
                        card.querySelector(".draft-item-total-amount")
                            ?.textContent || 0,
                    ) || 0;
                sectionQty +=
                    parseFloat(
                        card.querySelector(".draft-item-total-qty")?.textContent ||
                            0,
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
            section.querySelector(".draft-section-qty").textContent =
                fmtQty(sectionQty);
            section.querySelector(".draft-section-cbm").textContent =
                fmtCbm(sectionCbm);
            section.querySelector(".draft-section-weight").textContent =
                fmtWeight(sectionWeight);
            syncDraftSectionCollapse(section);
            totalAmount += sectionAmount;
            totalQty += sectionQty;
            totalCbm += sectionCbm;
            totalWeight += sectionWeight;
        });

        document.getElementById("draftOrderTotalAmount").textContent =
            fmtAmount(totalAmount);
        document.getElementById("draftOrderTotalQty").textContent =
            fmtQty(totalQty);
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
        const factoryPrice =
            product.buy_price != null && product.buy_price !== ""
                ? product.buy_price
                : product.unit_price;
        if (factoryPrice != null && factoryPrice !== "") {
            card.querySelector(".draft-item-unit-price").value =
                factoryPrice;
        }
        if (product.sell_price != null && product.sell_price !== "") {
            card.querySelector(".draft-item-customer-price").value =
                product.sell_price;
        }
        if (product.cbm != null) {
            card.querySelector(".draft-item-cbm").value = fmtCbm(product.cbm);
        }
        if (product.weight != null) {
            card.querySelector(".draft-item-weight").value =
                String(parseFloat(product.weight) || 0);
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
        renderDraftPhotoThumbs(card.querySelector(".draft-item-photos"), photoPaths);
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
        card.querySelector(".draft-item-customer-price").value =
            initial.sell_price ?? initial.customer_price ?? "";
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
        const isSharedCarton = !!initial.shared_carton_enabled;
        card.querySelector(".draft-item-item-no").value = isSharedCarton
            ? initial.shared_carton_code || ""
            : initial.item_no || "";
        if (!isSharedCarton && initial.item_no) card.dataset.manualItemNo = "1";
        card.dataset.dimensionsScope = (
            initial.dimensions_scope || "carton"
        ).toLowerCase();
        card.querySelector(".draft-item-custom-design-required").checked =
            !!initial.custom_design_required;
        card.querySelector(".draft-item-custom-design-note").value =
            initial.custom_design_note || "";
        toggleCustomDesignFields(card);
        renderDraftPhotoThumbs(
            card.querySelector(".draft-item-photos"),
            card._photoPaths,
        );
        renderFilePills(
            card.querySelector(".draft-item-design-files"),
            card._designPaths,
        );

        const productIdInput = card.querySelector(".draft-item-product-id");
        if (initial.product_id) {
            productIdInput.value = initial.product_id;
        }
        syncDraftPrimaryDescriptionInput(card, section);

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
        card.querySelector('[data-builder-action="add-description-entry"]')?.addEventListener(
            "click",
            () => addDraftDescriptionEntry(card, {}, true),
        );
        card.querySelector(".draft-item-shared-carton-toggle")?.addEventListener(
            "change",
            () => syncDraftSharedCartonMode(card),
        );
        card.querySelector('[data-builder-action="add-shared-content"]')?.addEventListener(
            "click",
            () => addDraftSharedCartonContentRow(card),
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
        bindClipboardImagePaste?.(
            card.querySelector(".draft-item-photo-panel") || card,
            async (files) => {
                try {
                    await uploadPhotoFiles(card, files);
                    updateDraftOrderTotals();
                } catch (e) {
                    showToast(e.message, "danger");
                }
            },
            {
                requireTargetMatch: true,
                targetMatcher: (target) =>
                    !!target.closest(
                        ".draft-item-photo-panel, .draft-item-photos, .draft-item-photo-btn",
                    ),
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
        [
            ".draft-item-cartons",
            ".draft-item-pieces-per-carton",
            ".draft-item-unit-price",
            ".draft-item-customer-price",
            ".draft-item-weight",
        ].forEach((selector) => {
            card.querySelector(selector)?.addEventListener("input", () =>
                updateDraftItemTotals(card),
            );
        });
        card.querySelector(".draft-item-cbm")?.addEventListener("input", () => {
            delete card.dataset.cbmAutoDerived;
            updateDraftItemTotals(card);
        });
        [
            ".draft-item-length",
            ".draft-item-width",
            ".draft-item-height",
        ].forEach((selector) => {
            card.querySelector(selector)?.addEventListener("input", () => {
                syncDraftItemCbmFromDimensions(card);
                updateDraftItemTotals(card);
            });
        });
        card.querySelector(".draft-item-item-no")?.addEventListener(
            "input",
            () => {
                if (card.dataset.sharedCartonEnabled === "1") {
                    return;
                }
                card.dataset.manualItemNo = card
                    .querySelector(".draft-item-item-no")
                    ?.value?.trim()
                    ? "1"
                    : "";
                renumberDraftItems();
            },
        );
        syncDraftSharedCartonMode(card, isSharedCarton);
        if (isSharedCarton) {
            getDraftSharedCartonRows(card).forEach((row) => row.remove());
            (initial.shared_carton_contents || []).forEach((content) =>
                addDraftSharedCartonContentRow(card, content),
            );
            if (!getDraftSharedCartonRows(card).length) {
                addDraftSharedCartonContentRow(card);
            }
        }
        syncDraftItemCbmFromDimensions(card);
        updateDraftItemTotals(card);
        renumberDraftItems();
    }

    function addDraftQuickSupplierPaymentLink(rowData = {}) {
        const container = document.getElementById("draftQuickSupplierPaymentLinks");
        if (!container) return;
        const method =
            rowData.method ||
            (typeof normalizePaymentMethodName === "function"
                ? normalizePaymentMethodName(
                      rowData.label || rowData.type || "",
                  )
                : "") ||
            "WeChat";
        const currency =
            rowData.currency === "USD" || rowData.currency === "RMB"
                ? rowData.currency
                : "RMB";
        const detail = rowData.value || rowData.link || "";
        const qrPath = rowData.qr_image_path || "";
        const row = document.createElement("div");
        row.className =
            "border rounded-3 p-2 draft-quick-supplier-payment-link-row";
        row.dataset.idx = String(++quickSupplierPaymentLinkIndex);
        row.innerHTML = `
            <div class="row g-2 align-items-center">
              <div class="col-12 col-md-3">
                <select class="form-select form-select-sm draft-quick-supplier-payment-method">
                  ${renderDraftQuickSupplierPaymentMethodOptions(method)}
                </select>
              </div>
              <div class="col-12 col-md-2">
                <select class="form-select form-select-sm draft-quick-supplier-payment-currency">
                  <option value="RMB"${currency === "RMB" ? " selected" : ""}>RMB</option>
                  <option value="USD"${currency === "USD" ? " selected" : ""}>USD</option>
                </select>
              </div>
              <div class="col-12 col-md-5">
                <input type="text" class="form-control form-control-sm draft-quick-supplier-payment-value" placeholder="${escapeHtml(draftT("Account / number / URL / account detail"))}" value="${escapeHtml(detail)}">
                <input type="hidden" class="draft-quick-supplier-payment-qr" value="${escapeHtml(qrPath)}">
                <input type="file" class="d-none draft-quick-supplier-payment-qr-input" accept="image/*,.jpg,.jpeg,.png,.webp,.jfif,.gif">
              </div>
              <div class="col-8 col-md-1">
                <button type="button" class="btn btn-sm btn-outline-secondary w-100 draft-quick-supplier-payment-qr-btn">${escapeHtml(draftT("QR"))}</button>
              </div>
              <div class="col-4 col-md-1 text-end">
                <button type="button" class="btn btn-sm btn-outline-danger w-100" onclick="this.closest('.draft-quick-supplier-payment-link-row').remove()">×</button>
              </div>
            </div>
            <div class="draft-quick-supplier-payment-qr-preview ${qrPath ? "" : "d-none"}"></div>
        `;
        container.appendChild(row);
        bindDraftQuickSupplierPaymentRow(row);
        setDraftQuickSupplierQrPreview(
            row,
            qrPath,
            rowData.file_name || "",
        );
    }

    function collectDraftQuickSupplierPaymentLinks() {
        return Array.from(
            document.querySelectorAll(
                "#draftQuickSupplierPaymentLinks .draft-quick-supplier-payment-link-row",
            ),
        )
            .map((row) => {
                const method = row
                    .querySelector(".draft-quick-supplier-payment-method")
                    ?.value?.trim();
                const value = row
                    .querySelector(".draft-quick-supplier-payment-value")
                    ?.value?.trim();
                const currency = row
                    .querySelector(".draft-quick-supplier-payment-currency")
                    ?.value?.trim();
                const qrImagePath = row
                    .querySelector(".draft-quick-supplier-payment-qr")
                    ?.value?.trim();
                if (!method && !value && !qrImagePath) return null;
                return {
                    method: method || "Bank Transfer",
                    label: method || "Bank Transfer",
                    account_label: method || "Bank Transfer",
                    value: value || "",
                    currency: currency === "USD" ? "USD" : "RMB",
                    qr_image_path: qrImagePath || null,
                };
            })
            .filter(Boolean);
    }

    function resetDraftQuickSupplierForm() {
        document.getElementById("draftSupplierQuickForm")?.reset();
        const fileInput = document.getElementById("draftQuickSupplierFiles");
        if (fileInput) fileInput.value = "";
        document.getElementById("draftQuickSupplierPaymentLinks").innerHTML = "";
        addDraftQuickSupplierPaymentLink();
        refreshUnsavedBaseline?.(
            document.querySelector("#draftSupplierQuickAddModal .modal-body"),
        );
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
        refreshUnsavedBaseline?.(
            document.querySelector("#draftSupplierQuickAddModal .modal-body"),
        );
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
            refreshUnsavedBaseline?.(
                document.querySelector("#draftSupplierQuickAddModal .modal-body"),
            );
            quickSupplierModal.hide();
        } catch (error) {
            showToast(error.message || "Failed to save supplier.", "danger");
        } finally {
            btn.disabled = false;
        }
    }

    function collectDescriptionEntries(card) {
        return Array.from(
            card.querySelectorAll(".draft-item-description-entry-input"),
        )
            .map((input) => {
                const value = input?.value?.trim() || "";
                const storedCn = input?.dataset?.descriptionCn?.trim() || "";
                const storedEn = input?.dataset?.descriptionEn?.trim() || "";
                if (!value && !storedCn && !storedEn) {
                    return null;
                }
                if (storedCn || storedEn) {
                    const storedDisplay = draftDescriptionDisplayValue(
                        storedCn,
                        storedEn,
                    );
                    if (!value || value === storedDisplay) {
                        return {
                            description_text: storedCn || storedEn || value,
                            description_translated:
                                storedEn || storedCn || value,
                        };
                    }
                }
                return {
                    description_text: value,
                    description_translated: "",
                };
            })
            .filter(Boolean);
    }

    function collectDraftSharedCartonContents(card, sectionSupplierId = "") {
        return getDraftSharedCartonRows(card).map((row) => ({
            supplier_id:
                row.querySelector(".draft-shared-content-supplier-id")?.value ||
                sectionSupplierId ||
                "",
            product_id:
                row.querySelector(".draft-shared-content-product-id")?.value ||
                null,
            item_no:
                row.querySelector(".draft-shared-content-item-no")?.value?.trim() ||
                null,
            item_no_manual: row.dataset.manualItemNo ? 1 : 0,
            shipping_code:
                card
                    .querySelector(".draft-item-shipping-code")
                    ?.value?.trim() || null,
            quantity_per_carton:
                row.querySelector(".draft-shared-content-qty-per-carton")
                    ?.value || null,
            unit_price:
                row.querySelector(".draft-shared-content-unit-price")?.value ||
                null,
            sell_price:
                row.querySelector(".draft-shared-content-sell-price")?.value ||
                null,
            hs_code:
                row.querySelector(".draft-shared-content-hs-code")?.value?.trim() ||
                null,
            description_entries: collectDraftSharedCartonDescription(row),
            notes:
                row.querySelector(".draft-shared-content-notes")?.value?.trim() ||
                null,
        }));
    }

    function collectDraftOrderPayload() {
        const customerId = draftOrderCustomerAc?.getSelectedId() || "";
        const expectedReadyDate =
            document.getElementById("draftOrderExpectedDate")?.value || "";
        const supplierSections = Array.from(
            document.querySelectorAll(".draft-order-section"),
        ).map((section) => {
            const sectionSupplierId =
                section.querySelector(".draft-section-supplier-id")?.value || "";
            return {
                supplier_id: sectionSupplierId,
                items: Array.from(
                    section.querySelectorAll(".draft-order-item-card"),
                ).map((card) => {
                    const sharedCartonEnabled =
                        card.dataset.sharedCartonEnabled === "1";
                    return {
                        product_id: sharedCartonEnabled
                            ? null
                            : card.querySelector(".draft-item-product-id")?.value ||
                              null,
                        item_no: sharedCartonEnabled
                            ? null
                            : card
                                  .querySelector(".draft-item-item-no")
                                  ?.value?.trim() || null,
                        item_no_manual: sharedCartonEnabled
                            ? 0
                            : card.dataset.manualItemNo
                              ? 1
                              : 0,
                        shared_carton_enabled: sharedCartonEnabled ? 1 : 0,
                        shared_carton_code: sharedCartonEnabled
                            ? card
                                  .querySelector(".draft-item-item-no")
                                  ?.value?.trim() || null
                            : null,
                        shared_carton_contents: sharedCartonEnabled
                            ? collectDraftSharedCartonContents(
                                  card,
                                  sectionSupplierId,
                              )
                            : [],
                        shipping_code:
                            card
                                .querySelector(".draft-item-shipping-code")
                                ?.value?.trim() || null,
                        description_entries: sharedCartonEnabled
                            ? []
                            : collectDescriptionEntries(card),
                        pieces_per_carton:
                            card.querySelector(".draft-item-pieces-per-carton")
                                ?.value || null,
                        cartons:
                            card.querySelector(".draft-item-cartons")?.value ||
                            null,
                        unit_price:
                            card.querySelector(".draft-item-unit-price")?.value ||
                            null,
                        sell_price:
                            card.querySelector(".draft-item-customer-price")
                                ?.value || null,
                        cbm_mode:
                            parseFloat(
                                card.querySelector(".draft-item-cbm")?.value || 0,
                            ) > 0
                                ? "direct"
                                : "dimensions",
                        cbm:
                            card.querySelector(".draft-item-cbm")?.value || null,
                        item_length:
                            card.querySelector(".draft-item-length")?.value ||
                            null,
                        item_width:
                            card.querySelector(".draft-item-width")?.value ||
                            null,
                        item_height:
                            card.querySelector(".draft-item-height")?.value ||
                            null,
                        weight:
                            card.querySelector(".draft-item-weight")?.value ||
                            null,
                        hs_code: sharedCartonEnabled
                            ? null
                            : card
                                  .querySelector(".draft-item-hs-code")
                                  ?.value?.trim() || null,
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
                            (
                                card.dataset.dimensionsScope || "carton"
                            ).toLowerCase(),
                    };
                }),
            };
        });

        return {
            customer_id: customerId,
            destination_country_id: getDraftDestinationCountryId() || null,
            expected_ready_date: expectedReadyDate || null,
            currency:
                document.getElementById("draftOrderCurrency")?.value || "RMB",
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
            refreshUnsavedBaseline?.(
                document.querySelector("#draftOrderModal .modal-body"),
            );
            builderModal?.hide();
            await refreshDraftLists({ deferLegacy: true });
        } catch (e) {
            showToast(e.message, "danger");
        } finally {
            setLoading(saveBtn, false);
        }
    }

    async function submitDraftOrder(orderId) {
        try {
            const res = await api("POST", `/orders/${orderId}/submit`, {});
            if (res?.warning) {
                showToast(res.warning, "warning");
            }
            showToast(res?.message || "Draft order submitted");
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
        document.getElementById("legacyMigrationCurrency").value = "RMB";
        legacyMigrationCustomerAc?.setValue(null);
        refreshUnsavedBaseline?.(
            document.querySelector("#legacyMigrationModal .modal-body"),
        );
        migrationModal.show();
    }

    async function submitLegacyMigration() {
        const legacyId = document.getElementById("legacyMigrationId").value;
        const payload = {
            customer_id: legacyMigrationCustomerAc?.getSelectedId() || "",
            expected_ready_date:
                document.getElementById("legacyMigrationExpectedDate").value || null,
            currency:
                document.getElementById("legacyMigrationCurrency").value || "RMB",
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
            refreshUnsavedBaseline?.(
                document.querySelector("#legacyMigrationModal .modal-body"),
            );
            migrationModal?.hide();
            showToast(
                res.data?.already_migrated
                    ? "Legacy draft was already migrated"
                    : "Legacy draft migrated",
            );
            await refreshDraftLists({ deferLegacy: true });
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
        registerUnsavedChangesGuard?.("#draftOrderModal .modal-body");
        registerUnsavedChangesGuard?.("#legacyMigrationModal .modal-body");
        registerUnsavedChangesGuard?.(
            "#draftSupplierQuickAddModal .modal-body",
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

        await refreshDraftLists({ deferLegacy: true });

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
