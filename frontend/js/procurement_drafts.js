(function () {
    const API = window.API_BASE || "/cargochina/api/v1";
    let draftOrderCustomerAc = null;
    let draftOrderDestinationCountryAc = null;
    let legacyMigrationCustomerAc = null;
    let builderModal = null;
    let migrationModal = null;
    let quickCustomerModal = null;
    let quickSupplierModal = null;
    let sectionIndex = 0;
    let itemIndex = 0;
    let sharedCartonContentIndex = 0;
    let quickSupplierPaymentLinkIndex = 0;
    let draftOrderCustomerCountryShipping = [];
    window.draftOrderNumberingHistory = [];
    let draftOrderImportTrigger = null;
    let draftOrderImportGuideModal = null;
    let draftOrderImportNeedsReplaceConfirm = false;
    let draftOrderImportReturnToBuilder = false;
    let draftOrderImportInProgress = false;
    let draftOrderImportProgressTimer = null;
    let draftOrderImportLongTimer = null;
    let draftOrderImportStartedAt = 0;
    let draftOrderImportProgressState = null;
    let draftOrderUnsavedGuard = null;
    let draftDownloadSelection = null;
    let draftFilterCustomerAc = null;
    let draftFilterSupplierAc = null;
    let draftListPage = 1;
    let draftListMeta = { page: 1, pages: 1, total: 0 };
    const DRAFT_ORDER_IMPORT_STEPS = [
        { key: "uploading", label: "Uploading", target: 40 },
        { key: "reading", label: "Reading Excel", target: 62 },
        { key: "rows", label: "Importing rows", target: 82 },
        { key: "images", label: "Processing images", target: 94 },
        { key: "preview", label: "Preparing preview", target: 98 },
        { key: "done", label: "Done", target: 100 },
    ];

    function draftT(text, replacements = null) {
        let value = typeof t === "function" ? t(text, replacements) : text;
        if (replacements && typeof value === "string") {
            Object.entries(replacements).forEach(([key, replacement]) => {
                value = value.replaceAll(`{${key}}`, String(replacement));
            });
        }
        return value;
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
            const error = new Error(
                data.message ||
                    draftT(
                        "The request could not be completed. Check the highlighted fields and try again.",
                    ),
            );
            error.status = res.status;
            error.errors = data.errors || {};
            error.response = data;
            throw error;
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

    function normalizeDraftGoodType(value) {
        const raw = String(value || "").trim();
        if (!raw) return "";
        const normalized = raw.toLowerCase().replace(/[\s_\/\\-]+/g, "");
        if (["copy", "copygoods", "replica", "仿牌", "仿货"].includes(normalized)) return "replica";
        if (["dangerous", "dangerousgoods", "hazmat", "hazardous", "hazardousgoods", "dg", "危险品", "危险货"].includes(normalized)) return "dangerous";
        if (["normal", "normalgoods", "regular", "普通货", "常规货"].includes(normalized)) return "normal";
        if (["cosmetic", "cosmetics", "化妆品", "美妆"].includes(normalized)) return "cosmetics";
        if (["branded", "brandedgoods", "brand", "品牌", "品牌货物"].includes(normalized)) return "branded";
        if (["food", "foods", "食品"].includes(normalized)) return "food";
        if (["other", "其他"].includes(normalized)) return "other";
        return "";
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

    function setDraftQuickSupplierQrContent(row, decodedContent = "") {
        const raw = row.querySelector(".draft-quick-supplier-payment-qr-raw");
        const decoded = row.querySelector(".draft-quick-supplier-payment-qr-decoded");
        if (raw) raw.value = decodedContent || "";
        if (decoded) decoded.value = decodedContent || "";
    }

    function fillDraftQuickSupplierFieldIfEmpty(id, value, label) {
        const field = document.getElementById(id);
        const nextValue = String(value || "").trim();
        if (!field || !nextValue) return;
        const current = field.value.trim();
        if (!current) {
            field.value = nextValue;
        } else if (current !== nextValue) {
            showToast(draftT("{label} already has a value. Existing value was kept.", { label: draftT(label) }), "warning");
        }
    }

    function applyWechatQrToDraftQuickSupplierRow(row, result) {
        if (!row || !result) return;
        const method = row.querySelector(".draft-quick-supplier-payment-method");
        const value = row.querySelector(".draft-quick-supplier-payment-value");
        const detail = result.account_detail || result.decoded_qr_content || result.raw_content || "";
        if (method) method.value = "WeChat";
        if (value) {
            if (value.value.trim() && value.value.trim() !== detail) {
                const replace = window.confirm(
                    draftT("Replace Existing QR") + "?\n" +
                        draftT("The account detail already has a value. Replace it with the scanned QR content?"),
                );
                if (!replace) return;
            }
            value.value = detail;
        }
        setDraftQuickSupplierQrContent(row, result.raw_content || result.decoded_qr_content || detail);
        if (result.qr_image_path) {
            setDraftQuickSupplierQrPreview(row, result.qr_image_path, draftT("QR saved"));
        }
        fillDraftQuickSupplierFieldIfEmpty("draftQuickSupplierName", result.name, "Name");
        fillDraftQuickSupplierFieldIfEmpty("draftQuickSupplierPhone", result.phone, "Phone");
        fillDraftQuickSupplierFieldIfEmpty("draftQuickSupplierAddress", result.address, "Address");
        showToast(draftT("WeChat QR detected. Only available QR information was filled. Please complete missing supplier details manually."));
    }

    function openDraftQuickSupplierWechatQrScanner(row) {
        if (typeof window.openWeChatQrScanner !== "function") {
            showToast(draftT("QR scanner is not available"), "danger");
            return;
        }
        window.openWeChatQrScanner({
            excludeSupplierId: () => "",
            onResult: (result) => applyWechatQrToDraftQuickSupplierRow(row, result),
        });
    }

    function bindDraftQuickSupplierPaymentRow(row) {
        const fileInput = row.querySelector(
            ".draft-quick-supplier-payment-qr-input",
        );
        const uploadBtn = row.querySelector(
            ".draft-quick-supplier-payment-qr-btn",
        );
        const scanBtn = row.querySelector(
            ".draft-quick-supplier-payment-scan-wechat-btn",
        );
        uploadBtn?.addEventListener("click", () => fileInput?.click());
        scanBtn?.addEventListener("click", () =>
            openDraftQuickSupplierWechatQrScanner(row),
        );
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
            ? (typeof window.formatCountryDisplay === "function"
                  ? window.formatCountryDisplay(countryName, countryCode)
                  : `${countryName}${countryCode ? ` (${countryCode})` : ""}`)
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
                            typeof window.formatCountryDisplay === "function"
                                ? window.formatCountryDisplay(
                                      country.country_name || "",
                                      country.country_code || "",
                                  )
                                : `${country.country_name || ""}${country.country_code ? ` (${country.country_code})` : ""}`,
                        )}</option>`,
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
            window.draftOrderNumberingHistory = [];
            setShippingHint(fallbackDefaultShip || "");
            renumberDraftItems();
            return;
        }

        try {
            const historyRes = await api("GET", `/draft-orders/numbering-history?customer_id=${encodeURIComponent(customerId)}`);
            window.draftOrderNumberingHistory = historyRes.data || [];
            const res = await api("GET", `/customers/${customerId}/lookup`);
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
            window.draftOrderNumberingHistory = [];
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

    function legacyRenumberDraftItems() {
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
                                (!manualSupplierSequenceByKey.has(supplierKey) ||
                                    parsed.supplierSequence >
                                        manualSupplierSequenceByKey.get(supplierKey))
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
                    if (
                        parsed &&
                        (!manualSupplierSequenceByKey.has(supplierKey) ||
                            parsed.supplierSequence >
                                manualSupplierSequenceByKey.get(supplierKey))
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
                    manual: !!card.dataset.manualItemNo,
                });
            });
        });

        let nextSupplierSequence = usedSupplierSequences.size
            ? Math.max(...Array.from(usedSupplierSequences)) + 1
            : 1;
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

    // Fill only untouched, empty fields. Existing generated, manual, imported,
    // or deliberately cleared values are never rewritten by this pass.
    function renumberDraftItems() {
        const prefix = getCustomerShipCode();
        setShippingHint(prefix);
        const targets = [];
        const supplierOrder = [];
        const supplierSequenceByKey = new Map();
        const usedSupplierSequences = new Set();
        const lastValidBySupplier = new Map();
        const usedNumbers = new Set();
        const remember = (supplierKey, value) => {
            if (!String(value ?? "").trim()) return;
            usedNumbers.add(normalizedItemNumber(value));
            if (parseFinalItemNumber(value)) lastValidBySupplier.set(supplierKey, String(value));
            const structured = parseStructuredItemNo(value);
            if (structured) {
                supplierSequenceByKey.set(supplierKey, structured.supplierSequence);
                usedSupplierSequences.add(structured.supplierSequence);
            }
        };

        (window.draftOrderNumberingHistory || []).forEach((row) => {
            remember(row.supplier_id ? String(row.supplier_id) : "supplier:none", row.item_no);
        });
        document.querySelectorAll(".draft-order-section").forEach((section) => {
            const supplierId = section.querySelector(".draft-section-supplier-id")?.value?.trim() || "";
            const sectionKey = supplierId || `section-${section.dataset.sectionId || ""}`;
            section.querySelectorAll(".draft-order-item-card").forEach((card) => {
                const shipping = card.querySelector(".draft-item-shipping-code");
                if (shipping && !shipping.value) shipping.value = prefix || "";
                if (card.dataset.sharedCartonEnabled === "1") {
                    getDraftSharedCartonRows(card).forEach((row) => {
                        const key = row.querySelector(".draft-shared-content-supplier-id")?.value?.trim() || supplierId || `${sectionKey}-shared-${row.dataset.contentId || ""}`;
                        if (!supplierOrder.includes(key)) supplierOrder.push(key);
                        const input = row.querySelector(".draft-shared-content-item-no");
                        remember(key, input?.value);
                        targets.push({ key, input, source: row.dataset.itemNoSource || "generated" });
                    });
                    return;
                }
                if (!supplierOrder.includes(sectionKey)) supplierOrder.push(sectionKey);
                const input = card.querySelector(".draft-item-item-no");
                remember(sectionKey, input?.value);
                targets.push({ key: sectionKey, input, source: card.dataset.itemNoSource || "generated" });
            });
        });
        let nextSupplierSequence = usedSupplierSequences.size ? Math.max(...usedSupplierSequences) + 1 : 1;
        supplierOrder.forEach((key) => {
            if (supplierSequenceByKey.has(key)) return;
            while (usedSupplierSequences.has(nextSupplierSequence)) nextSupplierSequence++;
            supplierSequenceByKey.set(key, nextSupplierSequence);
            usedSupplierSequences.add(nextSupplierSequence++);
        });
        targets.forEach((target) => {
            if (!target.input || target.source !== "generated" || target.input.value !== "") return;
            let candidate = lastValidBySupplier.has(target.key)
                ? incrementFinalItemNumber(lastValidBySupplier.get(target.key))
                : prefix ? `${prefix}-${supplierSequenceByKey.get(target.key) || 1}-1` : "";
            while (candidate && usedNumbers.has(normalizedItemNumber(candidate))) candidate = incrementFinalItemNumber(candidate) || "";
            if (!candidate) return;
            target.input.value = candidate;
            target.input.dataset.suggested = "1";
            remember(target.key, candidate);
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

    function collectDraftFilters(includePage = true) {
        const params = new URLSearchParams();
        const values = {
            q: document.getElementById("draftFilterSearch")?.value?.trim(),
            customer_id: document.getElementById("draftFilterCustomerId")?.value,
            supplier_id: document.getElementById("draftFilterSupplierId")?.value,
            goods_type: document.getElementById("draftFilterGoodsType")?.value,
            brand: document.getElementById("draftFilterBrand")?.value,
            creator_id: document.getElementById("draftFilterCreator")?.value,
            created_from: document.getElementById("draftFilterCreatedFrom")?.value,
            created_to: document.getElementById("draftFilterCreatedTo")?.value,
            expected_from: document.getElementById("draftFilterExpectedFrom")?.value,
            expected_to: document.getElementById("draftFilterExpectedTo")?.value,
        };
        Object.entries(values).forEach(([key, value]) => value && params.set(key, value));
        document.querySelectorAll(".draft-filter-status:checked").forEach((node) => params.append("status[]", node.value));
        if (!document.querySelector(".draft-filter-status")) {
            new URLSearchParams(window.location.search).getAll("status[]").forEach((value) => params.append("status[]", value));
        }
        if (includePage) params.set("page", String(draftListPage));
        params.set("limit", "20");
        return params;
    }

    function renderDraftFilterOptions(options = {}) {
        const currentParams = new URLSearchParams(window.location.search);
        const statusWrap = document.getElementById("draftFilterStatuses");
        if (statusWrap && !statusWrap.children.length) {
            statusWrap.innerHTML = (options.statuses || []).map((status, index) => `<label class="border rounded px-2 py-1 small"><input class="form-check-input me-1 draft-filter-status" type="checkbox" value="${escapeHtml(status)}" id="draftStatus${index}">${escapeHtml(typeof statusLabel === "function" ? statusLabel(status) : status)}</label>`).join("");
            const selectedStatuses = new Set(currentParams.getAll("status[]"));
            statusWrap.querySelectorAll(".draft-filter-status").forEach((node) => { node.checked = selectedStatuses.has(node.value); });
        }
        const brand = document.getElementById("draftFilterBrand");
        if (brand && brand.options.length <= 1) {
            (options.brands || []).forEach((value) => brand.add(new Option(value, value)));
            brand.value = currentParams.get("brand") || "";
        }
        const creator = document.getElementById("draftFilterCreator");
        if (creator && creator.options.length <= 1 && (options.creators || []).length) {
            (options.creators || []).forEach((row) => creator.add(new Option(row.name, row.id)));
            creator.value = currentParams.get("creator_id") || "";
            document.getElementById("draftFilterCreatorWrap")?.classList.remove("d-none");
        }
        const selected = options.selected || {};
        const customerInput = document.getElementById("draftFilterCustomer");
        if (selected.customer_id && customerInput && !customerInput.value.trim()) {
            draftFilterCustomerAc?.setValue(selected.customer_id);
        }
        const supplierInput = document.getElementById("draftFilterSupplier");
        if (selected.supplier_id && supplierInput && !supplierInput.value.trim()) {
            draftFilterSupplierAc?.setValue(selected.supplier_id);
        }
    }

    function syncDraftPagination() {
        const summary = document.getElementById("draftPaginationSummary");
        if (summary) summary.textContent = draftT("Page {page} of {pages} · {count} records", { page: draftListMeta.page || 1, pages: draftListMeta.pages || 1, count: draftListMeta.total || 0 });
        const previous = document.getElementById("draftPagePrevious");
        const next = document.getElementById("draftPageNext");
        if (previous) previous.disabled = (draftListMeta.page || 1) <= 1;
        if (next) next.disabled = (draftListMeta.page || 1) >= (draftListMeta.pages || 1);
        const active = Array.from(collectDraftFilters(false).entries()).filter(([key]) => key !== "limit");
        const activeSummary = document.getElementById("draftActiveFilterSummary");
        if (activeSummary) activeSummary.textContent = active.length ? draftT("Active filters: {count}", { count: active.length }) : draftT("No active filters");
    }

    async function loadDraftOrders() {
        const query = collectDraftFilters(true);
        const res = await api("GET", `/draft-orders?${query.toString()}`);
        draftListMeta = res.meta || draftListMeta;
        draftListPage = draftListMeta.page || draftListPage;
        renderDraftFilterOptions(res.filter_options || {});
        renderDraftOrders(res.data || []);
        syncDraftPagination();
        const url = new URL(window.location.href);
        ["q", "status[]", "customer_id", "supplier_id", "goods_type", "brand", "creator_id", "created_from", "created_to", "expected_from", "expected_to", "page"].forEach((key) => url.searchParams.delete(key));
        query.forEach((value, key) => { if (key !== "limit") url.searchParams.append(key, value); });
        window.history.replaceState({}, "", url);
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
                `<tr><td colspan="9" class="text-center text-muted py-4">${escapeHtml(draftT("No draft orders yet."))}</td></tr>`;
            draftDownloadSelection?.bind();
            return;
        }
        tbody.innerHTML = rows
            .map((row) => {
                const suppliers = (row.supplier_names || []).join(", ") || "—";
                return `
                    <tr>
                      <td class="text-center"><input class="form-check-input draft-download-cb" type="checkbox" data-download-id="${row.id}" aria-label="${escapeHtml(draftT("Select order {id}", { id: row.id }))}"></td>
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
                      <td class="table-actions"><div class="draft-action-group draft-row-actions" role="group" aria-label="${escapeHtml(draftT("Actions for draft order {id}", { id: row.id }))}">
                        <button class="btn btn-sm btn-outline-primary" type="button" onclick="openDraftOrderBuilder(${row.id})">${escapeHtml(draftT(row.editable ? "Open" : "View"))}</button>
                        ${row.status === "Draft" ? `<button class="btn btn-sm btn-success" type="button" onclick="submitDraftOrder(${row.id})">${escapeHtml(draftT("Submit"))}</button>` : ""}
                        <a class="btn btn-sm btn-outline-success" href="${API}/draft-orders/${row.id}/export?format=xlsx" download>${escapeHtml(draftT("Download"))}</a>
                        <a class="btn btn-sm btn-outline-secondary" href="/cargochina/procurement_draft_print.php?order_id=${row.id}" target="_blank" rel="noopener">${escapeHtml(draftT("Print"))}</a>
                        <a class="btn btn-sm btn-outline-info" href="/cargochina/orders.php?order_type=draft_procurement">${escapeHtml(draftT("Orders"))}</a>
                      </div></td>
                    </tr>
                `;
            })
            .join("");
        draftDownloadSelection?.bind();
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
                      <td class="table-actions"><div class="draft-action-group draft-row-actions" role="group" aria-label="${escapeHtml(draftT("Actions for legacy draft {id}", { id: row.id }))}">
                        ${migrated && row.converted_order_id ? `<button class="btn btn-sm btn-outline-success" type="button" onclick="openDraftOrderBuilder(${row.converted_order_id})">${escapeHtml(draftT("Open Order"))}</button>` : `<button class="btn btn-sm btn-outline-primary" type="button" onclick="openLegacyMigration(${row.id})">${escapeHtml(draftT("Migrate"))}</button>`}
                        <a class="btn btn-sm btn-outline-secondary" href="/cargochina/procurement_draft_print.php?id=${row.id}" target="_blank" rel="noopener">${escapeHtml(draftT("Print"))}</a>
                      </div></td>
                    </tr>
                `;
            })
            .join("");
    }

    function resetDraftOrderBuilder() {
        clearDraftSaveValidation();
        document.getElementById("draftOrderForm")?.reset();
        document.getElementById("draftOrderId").value = "";
        draftOrderRequestKey = newDraftRequestKey("draft");
        draftOrderLockVersion = 0;
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
        renderDraftOrderCosts({ lines: [], base_total: "0.0000", base_currency: null });
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
        draftOrderLockVersion = Number(order.lock_version || 0);
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
        renderDraftOrderCosts(order.operational_costs || { lines: [] });
        updateDraftOrderTotals();
        setBuilderEditable(!!order.editable);
        renumberDraftItems();
        refreshUnsavedBaseline?.(
            document.querySelector("#draftOrderModal .modal-body"),
        );
    }

    async function openDraftOrderBuilder(orderId = null, options = {}) {
        builderModal =
            builderModal ||
            bootstrap.Modal.getOrCreateInstance(
                document.getElementById("draftOrderModal"),
            );
        resetDraftOrderBuilder();
        if (orderId) {
            await fillDraftOrderBuilder(orderId);
            if (options.duplicate) {
                document.getElementById("draftOrderId").value = "";
                draftOrderLockVersion = 0;
                currentDraftCosts = [];
                renderDraftOrderCosts({ lines: [] });
                document.querySelectorAll(".draft-order-item-card").forEach((card) => {
                    card.dataset.existingItemId = "";
                    card.dataset.itemNoSource = "generated";
                    delete card.dataset.manualItemNo;
                    const itemNo = card.querySelector(".draft-item-item-no");
                    if (itemNo) itemNo.value = "";
                    getDraftSharedCartonRows(card).forEach((row) => {
                        row.dataset.itemNoSource = "generated";
                        delete row.dataset.manualItemNo;
                        const sharedItemNo = row.querySelector(".draft-shared-content-item-no");
                        if (sharedItemNo) sharedItemNo.value = "";
                    });
                });
                document.getElementById("draftOrderModalTitle").textContent =
                    draftT("Copy of Draft Order #{id}", { id: orderId });
                document.getElementById("draftOrderModalSubtitle").textContent =
                    draftT("Review the copied values before saving a new draft.");
                setBuilderEditable(true);
                renumberDraftItems();
                refreshUnsavedBaseline?.(
                    document.querySelector("#draftOrderModal .modal-body"),
                );
            }
        } else {
            addDraftOrderSection();
            refreshUnsavedBaseline?.(
                document.querySelector("#draftOrderModal .modal-body"),
            );
        }
        builderModal.show();
    }

    function draftOrderBuilderHasImportableInput() {
        const modalEl = document.getElementById("draftOrderModal");
        if (!modalEl?.classList.contains("show")) return false;
        if (document.getElementById("draftOrderId")?.value) return true;
        const fields = Array.from(
            modalEl.querySelectorAll(
                "#draftOrderCustomer, #draftOrderExpectedDate, #draftOrderHighAlertNotes, #draftOrderSections input:not([type='hidden']):not([type='file']), #draftOrderSections textarea",
            ),
        );
        return fields.some((field) => String(field.value || "").trim() !== "");
    }

    function clearDraftOrderImportProgressTimers() {
        if (draftOrderImportProgressTimer) {
            clearInterval(draftOrderImportProgressTimer);
            draftOrderImportProgressTimer = null;
        }
        if (draftOrderImportLongTimer) {
            clearTimeout(draftOrderImportLongTimer);
            draftOrderImportLongTimer = null;
        }
    }

    function draftOrderImportStepIndex(stepKey) {
        return Math.max(
            0,
            DRAFT_ORDER_IMPORT_STEPS.findIndex((step) => step.key === stepKey),
        );
    }

    function draftOrderImportStepLabel(stepKey) {
        return (
            DRAFT_ORDER_IMPORT_STEPS.find((step) => step.key === stepKey)
                ?.label || stepKey
        );
    }

    function draftOrderImportElapsedSeconds() {
        if (!draftOrderImportStartedAt) return 0;
        return Math.max(0, (Date.now() - draftOrderImportStartedAt) / 1000);
    }

    function draftOrderImportRowsText(meta = {}) {
        const imported = Number(meta.rows_imported || 0);
        const total = Number(meta.total_item_rows || 0) || imported;
        return draftT("{done} / {total} rows imported", {
            done: imported,
            total,
        });
    }

    function draftOrderImportImagesText(meta = {}) {
        const found = Number(meta.images_found || 0);
        const imported = Number(meta.images_imported || 0);
        if (!found && !imported) return draftT("No embedded images found");
        return draftT("{done} / {total} images processed", {
            done: imported,
            total: found || imported,
        });
    }

    function renderDraftOrderImportProgress() {
        const status = document.getElementById("draftOrderImportStatus");
        const state = draftOrderImportProgressState;
        if (!status || !state) return;
        const percent = Math.max(
            0,
            Math.min(100, Math.round(Number(state.percent || 0))),
        );
        const activeIndex = draftOrderImportStepIndex(state.step || "uploading");
        const alertClass =
            state.type === "success"
                ? "alert-success"
                : state.type === "danger"
                  ? "alert-danger"
                  : state.type === "warning"
                    ? "alert-warning"
                    : "alert-info";
        const stepMarkup = DRAFT_ORDER_IMPORT_STEPS.map((step, index) => {
            const done = index < activeIndex || percent >= step.target;
            const active = index === activeIndex;
            const cls = done
                ? "text-success"
                : active
                  ? "text-primary fw-semibold"
                  : "text-muted";
            const mark = done ? "OK" : active ? "Now" : "Next";
            return `<span class="${cls}">${mark} ${escapeHtml(draftT(step.label))}</span>`;
        }).join("");
        const detailList = Array.isArray(state.details)
            ? state.details.filter(Boolean).slice(0, 8)
            : [];
        status.className = `draft-import-status mt-3 alert ${alertClass} py-2 mb-0`;
        status.innerHTML = `
            <div class="d-flex justify-content-between align-items-center gap-2">
              <div>
                <div class="fw-semibold">${escapeHtml(draftT(state.message || draftOrderImportStepLabel(state.step)))}</div>
                <div class="small text-muted">${escapeHtml(draftT(draftOrderImportStepLabel(state.step || "uploading")))} · ${escapeHtml(draftT("Elapsed {seconds}s", { seconds: draftOrderImportElapsedSeconds().toFixed(1) }))}</div>
              </div>
              <div class="fw-bold">${percent}%</div>
            </div>
            <div class="progress mt-2" role="progressbar" aria-valuenow="${percent}" aria-valuemin="0" aria-valuemax="100" style="height: 10px;">
              <div class="progress-bar" style="width: ${percent}%"></div>
            </div>
            <div class="draft-import-step-list d-flex flex-wrap gap-2 mt-2 small">${stepMarkup}</div>
            ${
                state.rowsText || state.imageText || state.longMessage
                    ? `<div class="small mt-2">
                        ${state.rowsText ? `<div>${escapeHtml(state.rowsText)}</div>` : ""}
                        ${state.imageText ? `<div>${escapeHtml(state.imageText)}</div>` : ""}
                        ${state.longMessage ? `<div class="text-warning fw-semibold">${escapeHtml(draftT(state.longMessage))}</div>` : ""}
                      </div>`
                    : ""
            }
            ${
                detailList.length
                    ? `<ul class="mb-0 mt-2 ps-3 small">${detailList
                          .map((detail) => `<li>${escapeHtml(String(detail))}</li>`)
                          .join("")}</ul>`
                    : ""
            }
        `;
    }

    function setDraftOrderImportProgress(patch = {}) {
        draftOrderImportProgressState = {
            percent: 0,
            step: "uploading",
            type: "info",
            message: "Uploading file...",
            rowsText: "",
            imageText: "",
            details: [],
            ...draftOrderImportProgressState,
            ...patch,
        };
        renderDraftOrderImportProgress();
    }

    function startDraftOrderImportProgress(file) {
        clearDraftOrderImportProgressTimers();
        draftOrderImportStartedAt = Date.now();
        draftOrderImportProgressState = null;
        setDraftOrderImportProgress({
            percent: 1,
            step: "uploading",
            message: draftT("Uploading {file}...", {
                file: file?.name || draftT("selected file"),
            }),
        });
        draftOrderImportProgressTimer = setInterval(() => {
            if (!draftOrderImportInProgress || !draftOrderImportProgressState) {
                return;
            }
            const state = draftOrderImportProgressState;
            let step = state.step || "reading";
            let percent = Number(state.percent || 0);
            if (step === "uploading") {
                setDraftOrderImportProgress({
                    step: "uploading",
                    percent: Math.min(39, percent),
                    message: state.message || "Uploading file...",
                });
                return;
            }
            if (step === "reading" && percent >= 61) {
                step = "rows";
            } else if (step === "rows" && percent >= 81) {
                step = "images";
            } else if (step === "images" && percent >= 93) {
                step = "preview";
            }
            const target =
                DRAFT_ORDER_IMPORT_STEPS.find((item) => item.key === step)
                    ?.target || 95;
            const increment = step === "images" ? 0.35 : step === "preview" ? 0.2 : 0.7;
            percent = Math.min(target - 1, percent + increment);
            setDraftOrderImportProgress({
                step,
                percent,
                message:
                    step === "images"
                        ? "Processing images..."
                        : step === "rows"
                          ? "Importing rows..."
                          : step === "reading"
                            ? "Reading Excel..."
                            : step === "preview"
                              ? "Preparing preview..."
                              : state.message,
            });
        }, 650);
        draftOrderImportLongTimer = setTimeout(() => {
            if (!draftOrderImportInProgress) return;
            const currentStep = draftOrderImportProgressState?.step || "uploading";
            setDraftOrderImportProgress({
                longMessage:
                    currentStep === "uploading"
                        ? "Still uploading. This depends on your internet upload speed and the workbook/image size..."
                        : "Still processing on the server, please wait...",
                step: currentStep,
            });
        }, 15000);
    }

    function uploadDraftOrderImportFile(file, callbacks = {}) {
        const fd = new FormData();
        fd.append("file", file);

        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", API + "/draft-orders/import");
            xhr.withCredentials = true;
            xhr.timeout = 600000;

            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) return;
                const uploadPercent = Math.round((event.loaded / event.total) * 100);
                callbacks.onUploadProgress?.(uploadPercent);
            };
            xhr.upload.onload = () => callbacks.onUploadComplete?.();
            xhr.onerror = () =>
                reject(
                    new Error(
                        draftT(
                            "The import connection was interrupted. If upload progress was still moving slowly, the workbook/images are taking too long to upload to the server. Try again, or compress the photos / save as CSV without embedded photos.",
                        ),
                    ),
                );
            xhr.ontimeout = () =>
                reject(
                    new Error(
                        draftT(
                            "Import timed out before the server replied. Try a smaller/compressed workbook, save as CSV, or increase Nginx/PHP request timeouts on the server.",
                        ),
                    ),
                );
            xhr.onabort = () =>
                reject(new Error(draftT("Import was cancelled before it finished.")));
            xhr.onload = () => {
                let data = {};
                try {
                    data = xhr.responseText ? JSON.parse(xhr.responseText) : {};
                } catch (_) {
                    reject(
                        new Error(
                            draftT(
                                "Import failed because the server response was not JSON.",
                            ),
                        ),
                    );
                    return;
                }
                if (xhr.status < 200 || xhr.status >= 300 || data.error) {
                    const err = data.error === true ? data : data.error || {};
                    const message =
                        err.message ||
                        data.message ||
                        draftT("Import failed. Check the file and try again.");
                    const ref = err.request_id || data.request_id;
                    const error = new Error(message + (ref ? ` (ref: ${ref})` : ""));
                    error.errors = err.errors || data.errors || {};
                    reject(error);
                    return;
                }
                resolve(data);
            };
            xhr.send(fd);
        });
    }

    async function applyImportedDraftOrder(imported) {
        builderModal =
            builderModal ||
            bootstrap.Modal.getOrCreateInstance(
                document.getElementById("draftOrderModal"),
            );
        resetDraftOrderBuilder();

        const customer = imported.customer || {};
        if (customer.id) {
            draftOrderCustomerAc?.setValue({
                id: customer.id,
                name: customer.name || "",
                default_shipping_code: customer.default_shipping_code || "",
            });
            await loadDraftCustomerCountryContext(
                customer.id,
                customer.default_shipping_code || "",
                imported.destination_country || null,
            );
        } else if (customer.name) {
            draftOrderCustomerAc?.setValue(null);
            const customerInput = document.getElementById("draftOrderCustomer");
            if (customerInput) customerInput.value = customer.name || "";
        }

        const country = imported.destination_country || {};
        if (!customer.id && (country.id || country.name)) {
            setDraftDestinationCountry(
                country.id || "",
                country.name || "",
                country.code || "",
            );
        }

        document.getElementById("draftOrderExpectedDate").value =
            imported.expected_ready_date || "";
        document.getElementById("draftOrderCurrency").value =
            imported.currency || "RMB";
        document.getElementById("draftOrderHighAlertNotes").value =
            imported.high_alert_notes || "";
        document.getElementById("draftOrderSections").innerHTML = "";
        const sections = imported.supplier_sections || [];
        if (sections.length) {
            sections.forEach((section) => addDraftOrderSection(section));
        } else {
            addDraftOrderSection();
        }
        document.getElementById("draftOrderTotalCurrency").textContent =
            imported.currency || "RMB";
        setBuilderEditable(true);
        updateDraftOrderTotals();
        renumberDraftItems();
        refreshUnsavedBaseline?.(
            document.querySelector("#draftOrderModal .modal-body"),
        );
        builderModal.show();
    }

    let currentDraftCosts = [];
    let draftCostRequestKey = null;
    let draftOrderRequestKey = null;
    let draftOrderLockVersion = 0;
    const newDraftRequestKey = (prefix) => `${prefix}:${globalThis.crypto?.randomUUID?.() || (Date.now().toString(36) + Math.random().toString(36).slice(2))}`.slice(0, 64);

    function renderDraftOrderCosts(summary = {}) {
        currentDraftCosts = Array.isArray(summary.lines) ? summary.lines : [];
        const orderId = document.getElementById("draftOrderId")?.value || "";
        const saveFirst = document.getElementById("draftCostSaveFirst");
        const editor = document.getElementById("draftCostEditor");
        if (saveFirst) saveFirst.classList.toggle("d-none", !!orderId);
        if (editor) editor.classList.toggle("d-none", !orderId);
        const total = document.getElementById("draftCostBaseTotal");
        if (total) total.textContent = `${summary.base_total || "0.0000"} ${summary.base_currency || ""}`.trim();
        const container = document.getElementById("draftCostLines");
        if (!container) return;
        container.innerHTML = currentDraftCosts.length
            ? `<table class="table table-sm align-middle mb-0"><thead><tr><th>${escapeHtml(draftT("Type"))}</th><th>${escapeHtml(draftT("Description"))}</th><th>${escapeHtml(draftT("Amount"))}</th><th>${escapeHtml(draftT("Base Amount"))}</th><th>${escapeHtml(draftT("Status"))}</th><th></th></tr></thead><tbody>${currentDraftCosts.map((row) => `<tr><td>${escapeHtml(row.cost_type_label_en || row.cost_type_code)}</td><td>${escapeHtml(row.description_en || row.description_zh || "-")}</td><td>${escapeHtml(row.amount)} ${escapeHtml(row.currency)}</td><td>${escapeHtml(row.base_amount)} ${escapeHtml(row.base_currency)}</td><td><span class="badge ${row.posting_status === "finalized" ? "bg-success" : row.posting_status === "reversed" || row.posting_status === "archived" ? "bg-secondary" : "bg-warning text-dark"}">${escapeHtml(draftT(row.posting_status === "finalized" ? "Finalized" : row.posting_status === "reversed" ? "Reversed" : row.posting_status === "archived" ? "Archived" : "Pending"))}</span></td><td class="text-end"><button type="button" class="btn btn-outline-primary btn-sm" onclick="editDraftOrderCost(${Number(row.id)})">${escapeHtml(draftT("Edit"))}</button> <button type="button" class="btn btn-outline-danger btn-sm" onclick="deleteDraftOrderCost(${Number(row.id)})">${escapeHtml(draftT("Delete"))}</button></td></tr>`).join("")}</tbody></table>`
            : `<div class="text-muted small">${escapeHtml(draftT("No operational costs added."))}</div>`;
    }

    function resetDraftOrderCostEditor() {
        ["draftCostId", "draftCostAmount", "draftCostDescription", "draftCostProvider", "draftCostNotes"].forEach((id) => { const el = document.getElementById(id); if (el) el.value = ""; });
        const rate = document.getElementById("draftCostRate"); if (rate) rate.value = "1";
        draftCostRequestKey = newDraftRequestKey("cost");
    }

    function editDraftOrderCost(id) {
        const row = currentDraftCosts.find((cost) => Number(cost.id) === Number(id));
        if (!row) return;
        const values = { draftCostId: row.id, draftCostType: row.cost_type_code, draftCostAmount: row.amount, draftCostCurrency: row.currency, draftCostRate: row.exchange_rate, draftCostBaseCurrency: row.base_currency, draftCostDescription: row.description_en || row.description_zh || "", draftCostProvider: row.service_provider || "", draftCostNotes: row.notes || "" };
        Object.entries(values).forEach(([idKey, value]) => { const el = document.getElementById(idKey); if (el) el.value = value; });
    }

    async function reloadDraftOrderCosts() {
        const orderId = document.getElementById("draftOrderId")?.value;
        if (!orderId) return;
        const res = await api("GET", `/draft-order-costs?order_id=${encodeURIComponent(orderId)}`);
        renderDraftOrderCosts(res.data || {});
    }

    async function saveDraftOrderCost() {
        const orderId = document.getElementById("draftOrderId")?.value;
        if (!orderId) return showToast(draftT("Save the draft before adding costs."), "warning");
        const id = document.getElementById("draftCostId")?.value;
        const row = currentDraftCosts.find((cost) => Number(cost.id) === Number(id));
        const payload = { order_id: Number(orderId), cost_type_code: document.getElementById("draftCostType")?.value, description_en: document.getElementById("draftCostDescription")?.value?.trim() || null, amount: document.getElementById("draftCostAmount")?.value, currency: document.getElementById("draftCostCurrency")?.value, exchange_rate: document.getElementById("draftCostRate")?.value, base_currency: document.getElementById("draftCostBaseCurrency")?.value, service_provider: document.getElementById("draftCostProvider")?.value?.trim() || null, responsible_payer: "customer", allocation_method: "none", lock_version: row?.lock_version ?? 0, idempotency_key: draftCostRequestKey || (draftCostRequestKey = newDraftRequestKey("cost")), notes: document.getElementById("draftCostNotes")?.value?.trim() || null };
        await api(id ? "PUT" : "POST", id ? `/draft-order-costs/${id}` : "/draft-order-costs", payload);
        resetDraftOrderCostEditor();
        await reloadDraftOrderCosts();
        showToast(draftT("Cost line saved."), "success");
    }

    async function deleteDraftOrderCost(id) {
        if (!confirm(draftT("Delete this cost line?"))) return;
        await api("DELETE", `/draft-order-costs/${id}`);
        await reloadDraftOrderCosts();
    }

    function draftOrderImportUi() {
        return {
            status: document.getElementById("draftOrderImportStatus"),
            fileName: document.getElementById("draftOrderImportFileName"),
            dropZone: document.getElementById("draftOrderImportDropZone"),
            chooseBtn: document.getElementById("draftOrderImportChooseFileBtn"),
            dropBtn: document.getElementById("draftOrderImportDropBtn"),
            plusBtn: document.getElementById("draftOrderImportPlusBtn"),
        };
    }

    function parseFinalItemNumber(value) {
        const match = String(value ?? "").match(/^(.*?)(\d+)([^\d]*)$/u);
        if (!match) return null;
        return { prefix: match[1], digits: match[2], suffix: match[3], number: parseInt(match[2], 10), width: match[2].length };
    }

    function incrementFinalItemNumber(value) {
        const parsed = parseFinalItemNumber(value);
        if (!parsed) return null;
        let digits = String(parsed.number + 1);
        if (digits.length < parsed.width) digits = digits.padStart(parsed.width, "0");
        return `${parsed.prefix}${digits}${parsed.suffix}`;
    }

    function normalizedItemNumber(value) {
        return String(value ?? "").trim().toLocaleUpperCase();
    }

    function resetDraftOrderImportUi() {
        clearDraftOrderImportProgressTimers();
        draftOrderImportStartedAt = 0;
        draftOrderImportProgressState = null;
        const ui = draftOrderImportUi();
        if (ui.fileName) ui.fileName.textContent = draftT("No file selected");
        if (ui.status) {
            ui.status.className = "draft-import-status mt-3 d-none";
            ui.status.innerHTML = "";
        }
        ui.dropZone?.classList.remove("is-dragover", "is-importing");
        [ui.chooseBtn, ui.dropBtn, ui.plusBtn].forEach((button) => {
            if (button) button.disabled = false;
        });
    }

    function setDraftOrderImportStatus(type, message, details = []) {
        const status = document.getElementById("draftOrderImportStatus");
        if (!status) return;
        const alertClass =
            type === "danger"
                ? "alert-danger"
                : type === "warning"
                  ? "alert-warning"
                  : type === "success"
                    ? "alert-success"
                    : "alert-info";
        const list = Array.isArray(details)
            ? details.filter(Boolean).slice(0, 6)
            : [];
        status.className = `draft-import-status mt-3 alert ${alertClass} py-2 mb-0`;
        status.innerHTML = `
            <div>${escapeHtml(draftT(message))}</div>
            ${
                list.length
                    ? `<ul class="mb-0 mt-1 ps-3">${list
                          .map((detail) => `<li>${escapeHtml(String(detail))}</li>`)
                          .join("")}</ul>`
                    : ""
            }
        `;
    }

    function setDraftOrderImportBusy(loading) {
        const ui = draftOrderImportUi();
        draftOrderImportInProgress = !!loading;
        setLoading(ui.chooseBtn, loading);
        setLoading(ui.dropBtn, loading);
        ui.dropZone?.classList.toggle("is-importing", !!loading);
        ui.dropZone?.setAttribute("aria-busy", loading ? "true" : "false");
        ui.dropZone?.setAttribute("aria-disabled", loading ? "true" : "false");
        if (ui.plusBtn) ui.plusBtn.disabled = !!loading;
    }

    function draftOrderImportFileAllowed(file) {
        const name = String(file?.name || "");
        const ext = name.includes(".") ? name.split(".").pop().toLowerCase() : "";
        return ["xlsx", "xls", "csv", "cv"].includes(ext);
    }

    function draftOrderImportConfirmReplace() {
        if (
            (draftOrderImportNeedsReplaceConfirm ||
                draftOrderBuilderHasImportableInput()) &&
            !window.confirm(
                draftT(
                    "Importing will replace the current unsaved draft form. Continue?",
                ),
            )
        ) {
            return false;
        }
        draftOrderImportNeedsReplaceConfirm = false;
        return true;
    }

    function draftOrderImportErrorDetails(error) {
        const errors = error?.errors || {};
        if (Array.isArray(errors)) {
            return errors.map((item) => String(item));
        }
        if (typeof errors === "object" && errors !== null) {
            if (Array.isArray(errors.skipped_rows)) {
                return errors.skipped_rows.map((row) =>
                    draftT("Row {row} skipped: {reason}", {
                        row: row.row || "-",
                        reason: row.reason || draftT("Could not read row."),
                    }),
                );
            }
            return Object.values(errors)
                .flat()
                .map((item) => String(item));
        }
        return [];
    }

    function draftOrderImportSummaryDetails(meta = {}) {
        const details = [];
        details.push(draftOrderImportRowsText(meta));
        if (Number(meta.rows_skipped || 0) > 0) {
            details.push(
                draftT("{count} row(s) skipped", {
                    count: meta.rows_skipped,
                }),
            );
        }
        if (Number(meta.blank_rows_skipped || 0) > 0) {
            details.push(
                draftT("{count} blank row(s) ignored", {
                    count: meta.blank_rows_skipped,
                }),
            );
        }
        if (Number(meta.images_found || 0) || Number(meta.images_imported || 0)) {
            details.push(draftOrderImportImagesText(meta));
        }
        if (meta.reader_mode) {
            details.push(
                draftT("Reader: {mode}", {
                    mode: String(meta.reader_mode).replace(/_/g, " "),
                }),
            );
        }
        if (Array.isArray(meta.missing_optional_columns) && meta.missing_optional_columns.length) {
            details.push(
                draftT("Missing optional columns: {columns}", {
                    columns: meta.missing_optional_columns.join(", "),
                }),
            );
        }
        if (meta.total_seconds) {
            details.push(
                draftT("Finished in {seconds}s", {
                    seconds: Number(meta.total_seconds || 0).toFixed(1),
                }),
            );
        }
        return details;
    }

    function showDraftOrderImportResult(meta, fileName) {
        const imported = meta.rows_imported || 0;
        showToast(
            draftT("Imported {count} row(s) from {file}.", {
                count: imported,
                file: fileName || meta.source_file || draftT("selected file"),
            }),
        );
        if (meta.total_seconds) {
            showToast(
                draftT("Import finished in {seconds}s.", {
                    seconds: Number(meta.total_seconds || 0).toFixed(1),
                }),
                "success",
            );
        }
        if (Number(meta.images_found || 0) || Number(meta.images_imported || 0)) {
            showToast(draftOrderImportImagesText(meta), "success");
        }
        if (
            Array.isArray(meta.missing_optional_columns) &&
            meta.missing_optional_columns.length
        ) {
            showToast(
                draftT("Optional columns not found: {columns}", {
                    columns: meta.missing_optional_columns.join(", "),
                }),
                "warning",
            );
        }

        const skippedRows = Array.isArray(meta.skipped_rows)
            ? meta.skipped_rows
            : [];
        skippedRows.slice(0, 3).forEach((row) => {
            showToast(
                draftT("Row {row} skipped: {reason}", {
                    row: row.row || "—",
                    reason: row.reason || draftT("Could not read row."),
                }),
                "warning",
            );
        });
        if ((meta.rows_skipped || skippedRows.length) > 3) {
            showToast(
                draftT("{count} row(s) were skipped. Fix the file and import again if those rows are needed.", {
                    count: meta.rows_skipped || skippedRows.length,
                }),
                "warning",
            );
        }

        (meta.warnings || []).slice(0, 3).forEach((warning) =>
            showToast(warning, "warning"),
        );
        if ((meta.warnings || []).length > 3) {
            showToast(
                draftT("{count} import warning(s). Review the empty fields before saving.", {
                    count: meta.warnings.length,
                }),
                "warning",
            );
        }
    }

    async function processDraftOrderImportFile(file) {
        if (!file || draftOrderImportInProgress) return;
        const ui = draftOrderImportUi();
        if (ui.fileName) {
            ui.fileName.textContent = draftT("Selected: {file}", {
                file: file.name || draftT("selected file"),
            });
        }

        if (!draftOrderImportFileAllowed(file)) {
            const message = draftT("Unsupported import file. Use XLSX, XLS, or CSV.");
            setDraftOrderImportStatus("danger", message);
            showToast(message, "danger");
            return;
        }
        if (!draftOrderImportConfirmReplace()) {
            return;
        }

        try {
            draftOrderImportReturnToBuilder = false;
            setDraftOrderImportBusy(true);
            startDraftOrderImportProgress(file);
            const res = await uploadDraftOrderImportFile(file, {
                onUploadProgress: (percent) => {
                    setDraftOrderImportProgress({
                        step: "uploading",
                        percent: Math.max(1, Math.min(40, percent * 0.4)),
                        message: draftT("Uploading {percent}%...", { percent }),
                    });
                },
                onUploadComplete: () => {
                    setDraftOrderImportProgress({
                        step: "reading",
                        percent: Math.max(
                            41,
                            Number(draftOrderImportProgressState?.percent || 0),
                        ),
                        message: "Reading Excel...",
                        longMessage: "",
                    });
                },
            });
            const imported = res.data || {};
            const meta = imported.meta || {};
            setDraftOrderImportProgress({
                step: "preview",
                percent: 98,
                message: "Preparing preview...",
                rowsText: draftOrderImportRowsText(meta),
                imageText: draftOrderImportImagesText(meta),
                details: draftOrderImportSummaryDetails(meta),
            });
            draftOrderImportGuideModal?.hide();
            await applyImportedDraftOrder(imported);
            setDraftOrderImportProgress({
                type: "success",
                step: "done",
                percent: 100,
                message: draftT("Imported {count} row(s) into the draft form.", {
                    count: meta.rows_imported || 0,
                }),
                rowsText: draftOrderImportRowsText(meta),
                imageText: draftOrderImportImagesText(meta),
                details: draftOrderImportSummaryDetails(meta),
                longMessage: "",
            });
            showDraftOrderImportResult(meta, file.name || meta.source_file || "");
        } catch (e) {
            clearDraftOrderImportProgressTimers();
            const message = e.message || draftT("Import failed.");
            setDraftOrderImportStatus("danger", message, draftOrderImportErrorDetails(e));
            showToast(message, "danger");
        } finally {
            clearDraftOrderImportProgressTimers();
            setDraftOrderImportBusy(false);
            draftOrderImportTrigger = null;
            const input = document.getElementById("draftOrderImportFile");
            if (input) input.value = "";
        }
    }

    function triggerDraftOrderImport(button = null) {
        draftOrderImportTrigger = button;
        const guideEl = document.getElementById("draftOrderImportGuideModal");
        if (guideEl && typeof bootstrap !== "undefined") {
            draftOrderImportGuideModal =
                draftOrderImportGuideModal ||
                bootstrap.Modal.getOrCreateInstance(guideEl);
            resetDraftOrderImportUi();
            draftOrderImportNeedsReplaceConfirm =
                draftOrderBuilderHasImportableInput();
            const builderEl = document.getElementById("draftOrderModal");
            const showGuide = () => draftOrderImportGuideModal.show();
            if (
                button?.id === "draftOrderModalImportBtn" &&
                builderEl?.classList.contains("show")
            ) {
                draftOrderImportReturnToBuilder = true;
                builderEl.addEventListener("hidden.bs.modal", showGuide, {
                    once: true,
                });
                draftOrderUnsavedGuard?.bypassNextClose?.();
                builderModal =
                    builderModal || bootstrap.Modal.getOrCreateInstance(builderEl);
                builderModal.hide();
                return;
            }
            draftOrderImportReturnToBuilder = false;
            draftOrderImportGuideModal.show();
            return;
        }
        chooseDraftOrderImportFile();
    }

    function chooseDraftOrderImportFile() {
        if (draftOrderImportInProgress || !draftOrderImportConfirmReplace()) {
            return;
        }
        const input = document.getElementById("draftOrderImportFile");
        if (input) {
            input.value = "";
            input.click();
        }
    }

    async function handleDraftOrderImportFile(event) {
        const input = event.currentTarget;
        const file = input?.files?.[0];
        if (!file) return;
        await processDraftOrderImportFile(file);
    }

    function bindDraftOrderImportControls() {
        const input = document.getElementById("draftOrderImportFile");
        input?.addEventListener("change", handleDraftOrderImportFile);
        [
            document.getElementById("draftOrderImportBtn"),
            document.getElementById("draftOrderModalImportBtn"),
        ].forEach((button) => {
            button?.addEventListener("click", () =>
                triggerDraftOrderImport(button),
            );
        });
        document
            .getElementById("draftOrderImportChooseFileBtn")
            ?.addEventListener("click", chooseDraftOrderImportFile);
        [
            document.getElementById("draftOrderImportDropBtn"),
            document.getElementById("draftOrderImportPlusBtn"),
        ].forEach((button) => {
            button?.addEventListener("click", (event) => {
                event.preventDefault();
                event.stopPropagation();
                chooseDraftOrderImportFile();
            });
        });
        const dropZone = document.getElementById("draftOrderImportDropZone");
        dropZone?.addEventListener("click", chooseDraftOrderImportFile);
        dropZone?.addEventListener("keydown", (event) => {
            if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                chooseDraftOrderImportFile();
            }
        });
        ["dragenter", "dragover"].forEach((eventName) => {
            dropZone?.addEventListener(eventName, (event) => {
                event.preventDefault();
                event.stopPropagation();
                if (!draftOrderImportInProgress) {
                    dropZone.classList.add("is-dragover");
                }
            });
        });
        ["dragleave", "dragend"].forEach((eventName) => {
            dropZone?.addEventListener(eventName, (event) => {
                event.preventDefault();
                event.stopPropagation();
                dropZone.classList.remove("is-dragover");
            });
        });
        dropZone?.addEventListener("drop", async (event) => {
            event.preventDefault();
            event.stopPropagation();
            dropZone.classList.remove("is-dragover");
            const file = event.dataTransfer?.files?.[0];
            await processDraftOrderImportFile(file);
        });
        document
            .getElementById("draftOrderImportGuideModal")
            ?.addEventListener("hidden.bs.modal", () => {
                if (draftOrderImportReturnToBuilder) {
                    draftOrderImportReturnToBuilder = false;
                    builderModal =
                        builderModal ||
                        bootstrap.Modal.getOrCreateInstance(
                            document.getElementById("draftOrderModal"),
                        );
                    builderModal.show();
                }
            });
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
                <div class="draft-section-actions">
                  <small class="text-muted"><span class="draft-section-amount">0</span> <span class="draft-section-currency">USD</span> · <span class="draft-section-qty">0</span> qty · <span class="draft-section-cbm">0</span> CBM · <span class="draft-section-weight">0</span> kg</small>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="collapse-section">${escapeHtml(draftT("Collapse"))}</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action draft-icon-action" data-builder-action="move-up" title="${escapeHtml(draftT("Move section up"))}" aria-label="${escapeHtml(draftT("Move section up"))}">↑</button>
                  <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action draft-icon-action" data-builder-action="move-down" title="${escapeHtml(draftT("Move section down"))}" aria-label="${escapeHtml(draftT("Move section down"))}">↓</button>
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
                clearDraftInvalidTarget(supplierInput);
                refreshSectionProductFilters(section);
                syncDraftSectionCollapse(section);
                renumberDraftItems();
            },
        });
        supplierInput.addEventListener("input", () => {
            clearDraftInvalidTarget(supplierInput);
            supplierIdInput.value = "";
            refreshSectionProductFilters(section);
            syncDraftSectionCollapse(section);
            renumberDraftItems();
        });
        section._supplierAc = ac;
        if (initial.supplier_name) {
            if (initial.supplier_id) {
                ac?.setValue({
                    id: initial.supplier_id,
                    name: initial.supplier_name,
                });
                supplierIdInput.value = initial.supplier_id;
            } else {
                supplierInput.value = initial.supplier_name;
            }
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
                if (!window.confirm(draftT("Remove this supplier section and all of its items?"))) return;
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
        return `
            <div class="draft-item-description-row border rounded p-2" data-description-entry>
              <div class="draft-description-language-grid">
                <div>
                  <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                    <label class="form-label form-label-sm mb-0">${escapeHtml(draftT("English Description"))}</label>
                    <button type="button" class="btn btn-link btn-sm p-0 draft-description-translate" data-source-lang="en">${escapeHtml(draftT(en ? "Retranslate" : "Translate"))}</button>
                  </div>
                  <textarea rows="2" class="form-control form-control-sm draft-item-description-entry-input draft-description-en" lang="en" placeholder="${escapeHtml(draftT("English product description"))}">${escapeHtml(en)}</textarea>
                </div>
                <div>
                  <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
                    <label class="form-label form-label-sm mb-0">${escapeHtml(draftT("Chinese Description"))}</label>
                    <button type="button" class="btn btn-link btn-sm p-0 draft-description-translate" data-source-lang="zh">${escapeHtml(draftT(cn ? "Retranslate" : "Translate"))}</button>
                  </div>
                  <textarea rows="2" class="form-control form-control-sm draft-description-cn" lang="zh" placeholder="${escapeHtml(draftT("Chinese product description"))}">${escapeHtml(cn)}</textarea>
                </div>
              </div>
              <div class="d-flex justify-content-between align-items-center gap-2 mt-1">
                <small class="draft-description-status text-muted" aria-live="polite"></small>
                <button type="button" class="btn btn-outline-danger btn-sm draft-item-action draft-item-description-remove" title="${escapeHtml(draftT("Remove row"))}">×</button>
              </div>
            </div>
        `;
    }

    async function translateDraftDescriptionRow(row, sourceLang, explicit = false) {
        const source = row.querySelector(sourceLang === "zh" ? ".draft-description-cn" : ".draft-description-en");
        const target = row.querySelector(sourceLang === "zh" ? ".draft-description-en" : ".draft-description-cn");
        const status = row.querySelector(".draft-description-status");
        const buttons = Array.from(row.querySelectorAll(".draft-description-translate"));
        const text = source?.value?.trim() || "";
        if (!source || !target || !text) return;
        if (!explicit && target.value.trim() && target.dataset.manualEdit === "1") {
            target.dataset.stale = "1";
            target.classList.add("border-warning");
            if (status) status.textContent = draftT("Source changed; review the manual translation or choose Retranslate.");
            return;
        }
        buttons.forEach((button) => (button.disabled = true));
        source.setAttribute("aria-busy", "true");
        if (status) {
            status.className = "draft-description-status text-primary";
            status.textContent = draftT("Translating...");
        }
        try {
            const response = await api("POST", "/translations", {
                text,
                source_lang: sourceLang,
                target_lang: sourceLang === "zh" ? "en" : "zh",
            });
            const translated = String(response?.data?.translated || "").trim();
            if (!translated) {
                const notConfigured = response?.data?.error_code === "provider_not_configured";
                const translationError = new Error(
                    draftT(
                        notConfigured
                            ? "Translation service is not configured. Ask an administrator to configure Google Cloud Translation."
                            : "Translation is not available yet. Retry or enter the missing language manually.",
                    ),
                );
                translationError.retryable = response?.data?.retryable !== false;
                throw translationError;
            }
            target.dataset.programmatic = "1";
            target.value = translated;
            target.dataset.manualEdit = "0";
            target.dataset.autoSource = text;
            target.dataset.stale = "0";
            target.classList.remove("border-warning", "is-invalid");
            delete target.dataset.programmatic;
            if (status) {
                status.className = "draft-description-status text-success";
                status.textContent = draftT("Translation complete. You can edit either language.");
            }
        } catch (error) {
            if (status) {
                status.className = "draft-description-status text-danger";
                const retry = error?.retryable === false
                    ? ""
                    : ` <button type="button" class="btn btn-link btn-sm p-0 draft-description-retry">${escapeHtml(draftT("Retry"))}</button>`;
                status.innerHTML = `${escapeHtml(error.message || draftT("Translation failed."))}${retry}`;
                status.querySelector(".draft-description-retry")?.addEventListener("click", () => translateDraftDescriptionRow(row, sourceLang, true));
            }
        } finally {
            source.removeAttribute("aria-busy");
            buttons.forEach((button) => (button.disabled = false));
        }
    }

    function attachDraftDescriptionRowEvents(card, row) {
        const enInput = row.querySelector(".draft-description-en");
        const cnInput = row.querySelector(".draft-description-cn");
        [enInput, cnInput].forEach((input) => {
            input?.addEventListener("input", () => {
                if (input.dataset.programmatic !== "1") input.dataset.manualEdit = "1";
                const other = input === enInput ? cnInput : enInput;
                if (other?.value?.trim()) {
                    other.dataset.stale = "1";
                    other.classList.add("border-warning");
                }
            });
            input?.addEventListener("blur", () => {
                const other = input === enInput ? cnInput : enInput;
                if (input.value.trim() && !other?.value?.trim()) {
                    translateDraftDescriptionRow(row, input === enInput ? "en" : "zh", false);
                }
            });
        });
        row.querySelectorAll(".draft-description-translate").forEach((button) => {
            button.addEventListener("click", () => translateDraftDescriptionRow(row, button.dataset.sourceLang || "en", true));
        });
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

    function bindDraftHsCodeAutocomplete(input) {
        if (
            !input ||
            input.dataset.hsCodeSearchBound === "1" ||
            typeof Autocomplete === "undefined"
        ) {
            return;
        }
        input.dataset.hsCodeSearchBound = "1";
        input._hsCodeAutocomplete = Autocomplete.init(input, {
            resource: "hs-code-catalog",
            searchPath: "",
            limit: 50,
            placeholder: draftT("Start typing HS code or tariff name..."),
            renderItem: (item) =>
                [item.hs_code, item.name].filter(Boolean).join(" — ") ||
                item.id ||
                "",
            displayValue: (item) => item.hs_code || item.id || "",
            onSelect: (item) => {
                input.value = item.hs_code || item.id || "";
                input.dispatchEvent(new Event("input", { bubbles: true }));
            },
        });
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
                  <input type="text" maxlength="150" class="form-control form-control-sm draft-shared-content-item-no" placeholder="${escapeHtml(draftT("Auto"))}" autocomplete="off">
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">Qty / Carton</label>
                  <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-shared-content-qty-per-carton" placeholder="0">
                </div>
                <div class="col-12 col-xl-4">
                  <div class="row g-1">
                    <div class="col-12 col-md-6"><div class="d-flex justify-content-between"><label class="form-label draft-item-label">${escapeHtml(draftT("English Description"))}</label><button type="button" class="btn btn-link btn-sm p-0 draft-description-translate" data-source-lang="en">${escapeHtml(draftT("Translate"))}</button></div><input type="text" class="form-control form-control-sm draft-shared-content-description-input draft-description-en" lang="en" placeholder="${escapeHtml(draftT("English description"))}"></div>
                    <div class="col-12 col-md-6"><div class="d-flex justify-content-between"><label class="form-label draft-item-label">${escapeHtml(draftT("Chinese Description"))}</label><button type="button" class="btn btn-link btn-sm p-0 draft-description-translate" data-source-lang="zh">${escapeHtml(draftT("Translate"))}</button></div><input type="text" class="form-control form-control-sm draft-shared-content-description-cn draft-description-cn" lang="zh" placeholder="${escapeHtml(draftT("Chinese description"))}"></div>
                  </div>
                  <input type="hidden" class="draft-shared-content-product-id">
                  <div class="form-text draft-shared-content-meta"></div><small class="draft-description-status text-muted" aria-live="polite"></small>
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
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">Brand</label>
                  <input type="text" class="form-control form-control-sm draft-shared-content-brand" placeholder="${escapeHtml(draftT("Brand"))}">
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">Materials</label>
                  <input type="text" class="form-control form-control-sm draft-shared-content-materials" placeholder="${escapeHtml(draftT("Materials"))}">
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">${escapeHtml(draftT("Good Type"))}</label>
                  <select class="form-select form-select-sm draft-shared-content-copy-normal-goods">
                    <option value=""></option>
                    <option value="normal">${escapeHtml(draftT("Normal Goods"))}</option>
                    <option value="replica">${escapeHtml(draftT("Copy Goods"))}</option>
                    <option value="cosmetics">${escapeHtml(draftT("Cosmetics"))}</option>
                    <option value="branded">${escapeHtml(draftT("Branded Goods"))}</option>
                    <option value="food">${escapeHtml(draftT("Food"))}</option>
                    <option value="dangerous">${escapeHtml(draftT("Dangerous Goods"))}</option>
                    <option value="other">${escapeHtml(draftT("Other"))}</option>
                  </select>
                </div>
                <div class="col-12 col-sm-6 col-xl-2">
                  <label class="form-label draft-item-label">Express No.</label>
                  <input type="text" class="form-control form-control-sm draft-shared-content-express-number" placeholder="${escapeHtml(draftT("Express no."))}">
                </div>
                <div class="col-4 col-xl-1">
                  <label class="form-label draft-item-label">H</label>
                  <input type="number" step="0.01" min="0" class="form-control form-control-sm draft-shared-content-height" placeholder="H">
                </div>
                <div class="col-4 col-xl-1">
                  <label class="form-label draft-item-label">W</label>
                  <input type="number" step="0.01" min="0" class="form-control form-control-sm draft-shared-content-width" placeholder="W">
                </div>
                <div class="col-4 col-xl-1">
                  <label class="form-label draft-item-label">L</label>
                  <input type="number" step="0.01" min="0" class="form-control form-control-sm draft-shared-content-length" placeholder="L">
                </div>
                <div class="col-12 col-xl-1 d-flex align-items-end">
                  <button type="button" class="btn btn-outline-danger btn-sm w-100 draft-item-action draft-shared-content-remove">${escapeHtml(draftT("Remove"))}</button>
                </div>
              </div>
            </div>
        `;
    }

    function setDraftSharedCartonDescription(row, entry = {}) {
        const enInput = row.querySelector(".draft-shared-content-description-input");
        const cnInput = row.querySelector(".draft-shared-content-description-cn");
        if (!enInput || !cnInput) return;
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
        enInput.value = en;
        cnInput.value = cn;
    }

    function collectDraftSharedCartonDescription(row) {
        const en = row.querySelector(".draft-shared-content-description-input")?.value?.trim() || "";
        const cn = row.querySelector(".draft-shared-content-description-cn")?.value?.trim() || "";
        if (!en && !cn) return [];
        return [
            {
                description_text: cn,
                description_translated: en,
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
        bindDraftHsCodeAutocomplete(
            row.querySelector(".draft-shared-content-hs-code"),
        );

        if (initial.supplier_name) {
            if (initial.supplier_id && row._supplierAc) {
                row._supplierAc.setValue({
                    id: initial.supplier_id,
                    name: initial.supplier_name,
                });
                row.querySelector(".draft-shared-content-supplier-id").value =
                    initial.supplier_id;
            } else {
                const supplierInput = row.querySelector(
                    ".draft-shared-content-supplier",
                );
                if (supplierInput) supplierInput.value = initial.supplier_name;
            }
        }
        row.querySelector(".draft-shared-content-product-id").value =
            initial.product_id || "";
        row.querySelector(".draft-shared-content-item-no").value =
            initial.item_no || "";
        row.dataset.itemNoSource = initial.item_no_source || (initial.item_no_manual ? "manual" : "generated");
        row.dataset.manualItemNo = row.dataset.itemNoSource !== "generated" ? "1" : "";
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
        row.querySelector(".draft-shared-content-brand").value =
            initial.brand || initial.what_brand || "";
        row.querySelector(".draft-shared-content-materials").value =
            initial.materials || "";
        row.querySelector(".draft-shared-content-copy-normal-goods").value =
            normalizeDraftGoodType(initial.item_type_code || initial.copy_normal_goods);
        row.querySelector(".draft-shared-content-express-number").value =
            initial.express_number || "";
        row.querySelector(".draft-shared-content-height").value =
            initial.height ?? initial.item_height ?? "";
        row.querySelector(".draft-shared-content-width").value =
            initial.width ?? initial.item_width ?? "";
        row.querySelector(".draft-shared-content-length").value =
            initial.length ?? initial.item_length ?? "";

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

        [row.querySelector(".draft-description-en"), row.querySelector(".draft-description-cn")].forEach((input) => {
            input?.addEventListener("input", () => {
                input.dataset.manualEdit = "1";
                const other = input.classList.contains("draft-description-en") ? row.querySelector(".draft-description-cn") : row.querySelector(".draft-description-en");
                if (other?.value?.trim()) { other.dataset.stale = "1"; other.classList.add("border-warning"); }
                row.querySelector(".draft-shared-content-product-id").value = "";
            });
            input?.addEventListener("blur", () => {
                const other = input.classList.contains("draft-description-en") ? row.querySelector(".draft-description-cn") : row.querySelector(".draft-description-en");
                if (input.value.trim() && !other?.value?.trim()) translateDraftDescriptionRow(row, input.classList.contains("draft-description-en") ? "en" : "zh", false);
            });
        });
        row.querySelectorAll(".draft-description-translate").forEach((button) => button.addEventListener("click", () => translateDraftDescriptionRow(row, button.dataset.sourceLang || "en", true)));
        [
            ".draft-shared-content-qty-per-carton",
            ".draft-shared-content-unit-price",
            ".draft-shared-content-sell-price",
            ".draft-shared-content-hs-code",
            ".draft-shared-content-notes",
            ".draft-shared-content-brand",
            ".draft-shared-content-materials",
            ".draft-shared-content-copy-normal-goods",
            ".draft-shared-content-express-number",
            ".draft-shared-content-height",
            ".draft-shared-content-width",
            ".draft-shared-content-length",
        ].forEach((selector) => {
            row.querySelector(selector)?.addEventListener("input", () =>
                updateDraftItemTotals(card),
            );
        });
        row.querySelector(".draft-shared-content-item-no")?.addEventListener(
            "input",
            () => {
                row.dataset.itemNoSource = "manual";
                row.dataset.manualItemNo = "1";
                delete row.querySelector(".draft-shared-content-item-no")?.dataset.suggested;
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
            card.querySelectorAll(".draft-description-en, .draft-description-cn"),
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
                        <button type="button" class="btn btn-outline-secondary btn-sm draft-item-action" data-builder-action="paste-photo">Paste</button>
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
                        <input type="text" maxlength="150" class="form-control form-control-sm draft-item-item-no" placeholder="Auto" autocomplete="off">
                        <div class="form-text">${escapeHtml(draftT("Suggested automatically; you can type, paste, replace, or clear it."))}</div>
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
                          <div class="draft-item-subgrid-title">${escapeHtml(draftT("Shipment Details"))}</div>
                          <div class="row g-2">
                            <div class="col-12 col-sm-6 col-xl-2">
                              <label class="form-label draft-item-label">${escapeHtml(draftT("Brand"))}</label>
                              <input type="text" class="form-control form-control-sm draft-item-brand" placeholder="${escapeHtml(draftT("Brand"))}">
                            </div>
                            <div class="col-12 col-sm-6 col-xl-3">
                              <label class="form-label draft-item-label">${escapeHtml(draftT("Materials"))}</label>
                              <input type="text" class="form-control form-control-sm draft-item-materials" placeholder="${escapeHtml(draftT("Material / composition"))}">
                            </div>
                            <div class="col-12 col-sm-6 col-xl-2">
                              <label class="form-label draft-item-label">${escapeHtml(draftT("What Brand"))}</label>
                              <input type="text" class="form-control form-control-sm draft-item-what-brand" placeholder="${escapeHtml(draftT("Brand marker"))}">
                            </div>
                            <div class="col-12 col-sm-6 col-xl-2">
                              <label class="form-label draft-item-label">${escapeHtml(draftT("Good Type"))}</label>
                              <select class="form-select form-select-sm draft-item-copy-normal-goods">
                                <option value=""></option>
                                <option value="normal">${escapeHtml(draftT("Normal Goods"))}</option>
                                <option value="replica">${escapeHtml(draftT("Copy Goods"))}</option>
                                <option value="cosmetics">${escapeHtml(draftT("Cosmetics"))}</option>
                                <option value="branded">${escapeHtml(draftT("Branded Goods"))}</option>
                                <option value="food">${escapeHtml(draftT("Food"))}</option>
                                <option value="dangerous">${escapeHtml(draftT("Dangerous Goods"))}</option>
                                <option value="other">${escapeHtml(draftT("Other"))}</option>
                              </select>
                            </div>
                            <div class="col-12 col-sm-6 col-xl-2">
                              <label class="form-label draft-item-label">${escapeHtml(draftT("Code"))}</label>
                              <input type="text" class="form-control form-control-sm draft-item-code" placeholder="${escapeHtml(draftT("Code"))}">
                            </div>
                            <div class="col-12 col-sm-6 col-xl-2">
                              <label class="form-label draft-item-label">${escapeHtml(draftT("Express Number"))}</label>
                              <input type="text" class="form-control form-control-sm draft-item-express-number" placeholder="${escapeHtml(draftT("Express no."))}">
                            </div>
                            <div class="col-12 col-sm-6 col-xl-1">
                              <label class="form-label draft-item-label">${escapeHtml(draftT("Size"))}</label>
                              <input type="text" class="form-control form-control-sm draft-item-size" placeholder="${escapeHtml(draftT("L x W x H"))}">
                            </div>
                          </div>
                        </div>
                        <div class="draft-item-subgrid-block">
                          <div class="draft-item-subgrid-title">Packaging</div>
                          <div class="row g-2">
                            <div class="col-6 col-md-3">
                              <label class="form-label draft-item-label">Total Cartons</label>
                              <input type="number" step="1" min="0" class="form-control form-control-sm draft-item-cartons" placeholder="0">
                            </div>
                            <div class="col-6 col-md-3">
                              <label class="form-label draft-item-label">Pieces / Carton</label>
                              <input type="number" step="0.0001" min="0" class="form-control form-control-sm draft-item-pieces-per-carton" placeholder="0">
                            </div>
                            <div class="col-6 col-md-3">
                              <label class="form-label draft-item-label">Unit</label>
                              <input type="text" maxlength="20" class="form-control form-control-sm draft-item-unit" placeholder="pieces">
                            </div>
                            <div class="col-6 col-md-3">
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
                        <label class="form-label form-label-sm mt-3">Item notes</label>
                        <textarea class="form-control form-control-sm draft-item-notes" rows="2" placeholder="Optional item notes..."></textarea>
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
        clearDraftInvalidTarget(card.querySelector(".draft-item-photo-panel"));
    }

    function extensionFromImageMime(type = "") {
        return (
            {
                "image/jpeg": "jpg",
                "image/jpg": "jpg",
                "image/png": "png",
                "image/gif": "gif",
                "image/webp": "webp",
                "image/bmp": "png",
            }[String(type).toLowerCase()] || "png"
        );
    }

    async function readClipboardImageFiles() {
        if (!navigator.clipboard?.read) {
            throw new Error(
                draftT("Clipboard image paste is not available in this browser. Press Ctrl+V on the photo area instead."),
            );
        }
        const items = await navigator.clipboard.read();
        const files = [];
        for (const item of items || []) {
            const imageTypes = (item.types || []).filter((type) =>
                String(type).startsWith("image/"),
            );
            for (const type of imageTypes) {
                const blob = await item.getType(type);
                files.push(
                    new File(
                        [blob],
                        `pasted-image-${Date.now()}.${extensionFromImageMime(type)}`,
                        { type },
                    ),
                );
            }
        }
        if (!files.length) {
            throw new Error(draftT("No image was found in the clipboard."));
        }
        return files;
    }

    async function pasteClipboardPhoto(card, button = null) {
        try {
            setLoading(button, true);
            const files = await readClipboardImageFiles();
            await uploadPhotoFiles(card, files);
            clearDraftSaveValidation();
            updateDraftOrderTotals();
            showToast(draftT("Pasted image attached"));
        } catch (e) {
            showToast(e.message || draftT("Failed to paste image"), "danger");
        } finally {
            setLoading(button, false);
        }
    }

    async function uploadDesignFiles(card, files) {
        const paths = card._designPaths || [];
        const uploaded = await uploadFiles(files, false);
        uploaded.forEach((path) => paths.push(path));
        card._designPaths = paths;
        renderFilePills(card.querySelector(".draft-item-design-files"), paths);
        clearDraftInvalidTarget(card.querySelector(".draft-item-custom-design-note"));
        clearDraftInvalidTarget(card.querySelector(".draft-item-design-panel"));
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
        card.dataset.existingItemId = initial.existing_item_id || initial.id || "";
        card._photoPaths = (initial.photo_paths || []).slice();
        card._designPaths = (initial.custom_design_paths || []).slice();
        container.appendChild(card);
        bindDraftHsCodeAutocomplete(card.querySelector(".draft-item-hs-code"));

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
        card.querySelector(".draft-item-unit").value = initial.unit || "pieces";
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
        card.querySelector(".draft-item-brand").value =
            initial.brand || initial.what_brand || "";
        card.querySelector(".draft-item-materials").value =
            initial.materials || "";
        card.querySelector(".draft-item-what-brand").value =
            initial.what_brand || "";
        card.querySelector(".draft-item-copy-normal-goods").value =
            normalizeDraftGoodType(initial.item_type_code || initial.copy_normal_goods);
        card.querySelector(".draft-item-code").value = initial.code || "";
        card.querySelector(".draft-item-express-number").value =
            initial.express_number || "";
        card.querySelector(".draft-item-size").value = initial.size || "";
        const isSharedCarton = !!initial.shared_carton_enabled;
        card.querySelector(".draft-item-item-no").value = isSharedCarton
            ? initial.shared_carton_code || ""
            : initial.item_no || "";
        card.dataset.itemNoSource = initial.item_no_source || (initial.item_no_manual ? "manual" : "generated");
        card.dataset.manualItemNo = card.dataset.itemNoSource !== "generated" ? "1" : "";
        card.dataset.dimensionsScope = (
            initial.dimensions_scope || "carton"
        ).toLowerCase();
        card.querySelector(".draft-item-custom-design-required").checked =
            !!initial.custom_design_required;
        card.querySelector(".draft-item-custom-design-note").value =
            initial.custom_design_note || "";
        card.querySelector(".draft-item-notes").value = initial.notes || "";
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
                if (!window.confirm(draftT("Remove this item?"))) return;
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
        card.querySelector('[data-builder-action="paste-photo"]')?.addEventListener(
            "click",
            (event) => pasteClipboardPhoto(card, event.currentTarget),
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
                card.dataset.itemNoSource = "manual";
                card.dataset.manualItemNo = "1";
                delete card.querySelector(".draft-item-item-no")?.dataset.suggested;
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
        const qrRawContent =
            rowData.qr_raw_content || rowData.decoded_qr_content || "";
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
              <div class="col-12 col-md-4">
                <input type="text" class="form-control form-control-sm draft-quick-supplier-payment-value" placeholder="${escapeHtml(draftT("Account / number / URL / account detail"))}" value="${escapeHtml(detail)}">
                <input type="hidden" class="draft-quick-supplier-payment-qr" value="${escapeHtml(qrPath)}">
                <input type="hidden" class="draft-quick-supplier-payment-qr-raw" value="${escapeHtml(qrRawContent)}">
                <input type="hidden" class="draft-quick-supplier-payment-qr-decoded" value="${escapeHtml(qrRawContent)}">
                <input type="file" class="d-none draft-quick-supplier-payment-qr-input" accept="image/*,.jpg,.jpeg,.png,.webp,.jfif,.gif,.bmp,.avif">
              </div>
              <div class="col-8 col-md-1">
                <button type="button" class="btn btn-sm btn-outline-secondary w-100 draft-quick-supplier-payment-qr-btn">${escapeHtml(draftT("QR"))}</button>
              </div>
              <div class="col-12 col-md-1">
                <button type="button" class="btn btn-sm btn-outline-primary w-100 draft-quick-supplier-payment-scan-wechat-btn">${escapeHtml(draftT("Scan WeChat QR"))}</button>
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
                const qrRawContent = row
                    .querySelector(".draft-quick-supplier-payment-qr-raw")
                    ?.value?.trim();
                const decodedQrContent = row
                    .querySelector(".draft-quick-supplier-payment-qr-decoded")
                    ?.value?.trim();
                if (!method && !value && !qrImagePath) return null;
                return {
                    method: method || "Bank Transfer",
                    label: method || "Bank Transfer",
                    account_label: method || "Bank Transfer",
                    value: value || "",
                    currency: currency === "USD" ? "USD" : "RMB",
                    qr_image_path: qrImagePath || null,
                    qr_raw_content: qrRawContent || null,
                    decoded_qr_content: decodedQrContent || qrRawContent || null,
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
            store_id:
                document.getElementById("draftQuickSupplierStoreId").value.trim() ||
                null,
            name: document.getElementById("draftQuickSupplierName").value.trim(),
            address:
                document.getElementById("draftQuickSupplierAddress")?.value?.trim() ||
                null,
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
        if (!payload.name) {
            showToast("Supplier name is required.", "danger");
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

    function resetDraftQuickCustomerForm() {
        document.getElementById("draftCustomerQuickForm")?.reset();
        refreshUnsavedBaseline?.(
            document.querySelector("#draftCustomerQuickAddModal .modal-body"),
        );
    }

    function openDraftQuickCustomer() {
        quickCustomerModal =
            quickCustomerModal ||
            bootstrap.Modal.getOrCreateInstance(
                document.getElementById("draftCustomerQuickAddModal"),
            );
        resetDraftQuickCustomerForm();
        const currentCustomer = draftOrderCustomerAc?.getSelected?.();
        const customerName =
            currentCustomer?.name ||
            document.getElementById("draftOrderCustomer")?.value?.trim() ||
            "";
        const shippingCode =
            currentCustomer?.default_shipping_code ||
            currentCustomer?.code ||
            "";
        const nameInput = document.getElementById("draftQuickCustomerName");
        const codeInput = document.getElementById("draftQuickCustomerShippingCode");
        if (nameInput && customerName) nameInput.value = customerName;
        if (codeInput && shippingCode) codeInput.value = shippingCode;
        refreshUnsavedBaseline?.(
            document.querySelector("#draftCustomerQuickAddModal .modal-body"),
        );
        quickCustomerModal.show();
        setTimeout(() => {
            (nameInput?.value ? codeInput : nameInput)?.focus?.();
        }, 150);
    }

    async function saveDraftQuickCustomer() {
        const name = document.getElementById("draftQuickCustomerName")?.value?.trim() || "";
        const shippingCode =
            document.getElementById("draftQuickCustomerShippingCode")?.value?.trim() ||
            "";
        if (!name) {
            showToast(draftT("Customer name is required."), "danger");
            return;
        }
        if (!shippingCode) {
            showToast(draftT("Default shipping code is required."), "danger");
            return;
        }
        const payload = {
            name,
            default_shipping_code: shippingCode,
            phone:
                document.getElementById("draftQuickCustomerPhone")?.value?.trim() ||
                null,
            email:
                document.getElementById("draftQuickCustomerEmail")?.value?.trim() ||
                null,
            payment_terms:
                document
                    .getElementById("draftQuickCustomerPaymentTerms")
                    ?.value?.trim() || null,
            address:
                document.getElementById("draftQuickCustomerAddress")?.value?.trim() ||
                null,
        };
        const btn = document.getElementById("draftQuickCustomerSaveBtn");
        try {
            btn.disabled = true;
            const res = await api("POST", "/customers", payload);
            const customer = res.data || {};
            if (!customer.id) {
                throw new Error(
                    draftT("Customer was saved but could not be selected. Refresh and search for it."),
                );
            }
            const selected = {
                id: customer.id,
                name: customer.name || payload.name,
                code: customer.code || payload.default_shipping_code,
                default_shipping_code:
                    customer.default_shipping_code ||
                    payload.default_shipping_code ||
                    customer.code ||
                    "",
            };
            draftOrderCustomerAc?.setValue(selected);
            clearDraftInvalidTarget(document.getElementById("draftOrderCustomer"));
            await loadDraftCustomerCountryContext(
                selected.id,
                selected.default_shipping_code || "",
            );
            if (res?.warning) {
                showToast(res.warning, "warning");
            }
            showToast(draftT("Customer added and selected for this draft."));
            refreshUnsavedBaseline?.(
                document.querySelector("#draftCustomerQuickAddModal .modal-body"),
            );
            quickCustomerModal.hide();
        } catch (error) {
            showToast(error.message || draftT("Failed to save customer."), "danger");
        } finally {
            btn.disabled = false;
        }
    }

    function collectDescriptionEntries(card) {
        return Array.from(
            card.querySelectorAll("[data-description-entry]"),
        )
            .map((row) => {
                const cn = row.querySelector(".draft-description-cn")?.value?.trim() || "";
                const en = row.querySelector(".draft-description-en")?.value?.trim() || "";
                if (!cn && !en) return null;
                return {
                    description_text: cn,
                    description_translated: en,
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
            item_no: row.querySelector(".draft-shared-content-item-no")?.value || null,
            item_no_manual: row.dataset.manualItemNo ? 1 : 0,
            item_no_source: row.dataset.itemNoSource || "generated",
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
            brand:
                row.querySelector(".draft-shared-content-brand")?.value?.trim() ||
                null,
            materials:
                row
                    .querySelector(".draft-shared-content-materials")
                    ?.value?.trim() || null,
            copy_normal_goods:
                row
                    .querySelector(".draft-shared-content-copy-normal-goods")
                    ?.value?.trim() || null,
            express_number:
                row
                    .querySelector(".draft-shared-content-express-number")
                    ?.value?.trim() || null,
            height:
                row.querySelector(".draft-shared-content-height")?.value || null,
            width:
                row.querySelector(".draft-shared-content-width")?.value || null,
            length:
                row.querySelector(".draft-shared-content-length")?.value || null,
            description_entries: collectDraftSharedCartonDescription(row),
            notes:
                row.querySelector(".draft-shared-content-notes")?.value?.trim() ||
                null,
        }));
    }

    function draftPositiveNumber(value) {
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function clearDraftSaveValidation() {
        const summary = document.getElementById("draftOrderValidationSummary");
        if (summary) {
            summary.classList.add("d-none");
            summary.innerHTML = "";
        }
        document
            .querySelectorAll("#draftOrderModal .draft-save-invalid-feedback")
            .forEach((el) => el.remove());
        document
            .querySelectorAll("#draftOrderModal [data-draft-save-invalid='1']")
            .forEach((el) => {
                el.classList.remove(
                    "is-invalid",
                    "border-danger",
                    "draft-save-invalid-box",
                );
                delete el.dataset.draftSaveInvalid;
            });
        document
            .querySelectorAll(
                "#draftOrderModal .draft-save-invalid-scope, #draftOrderModal .draft-save-invalid-section",
            )
            .forEach((el) => {
                el.classList.remove(
                    "draft-save-invalid-scope",
                    "draft-save-invalid-section",
                );
            });
    }

    function ensureDraftValidationSummary() {
        let summary = document.getElementById("draftOrderValidationSummary");
        if (summary) return summary;
        const form = document.getElementById("draftOrderForm");
        if (!form) return null;
        summary = document.createElement("div");
        summary.id = "draftOrderValidationSummary";
        summary.className =
            "alert alert-danger draft-order-validation-summary d-none";
        summary.setAttribute("role", "alert");
        form.prepend(summary);
        return summary;
    }

    function showDraftValidationSummary(count = 0, message = null) {
        const summary = ensureDraftValidationSummary();
        if (!summary) return;
        const text =
            message ||
            draftT("Please complete the highlighted fields before saving.");
        summary.innerHTML = `
            <div class="fw-semibold">${escapeHtml(text)}</div>
            ${
                count
                    ? `<div class="small mt-1">${escapeHtml(
                          draftT("{count} field(s) need attention.", {
                              count,
                          }),
                      )}</div>`
                    : ""
            }
        `;
        summary.classList.remove("d-none");
    }

    function clearDraftInvalidTarget(target) {
        const field =
            typeof target === "string" ? document.querySelector(target) : target;
        if (!field) return;
        const targets = [field];
        const feedbackSiblings = [];
        if (field.matches?.("input, textarea, select")) {
            let sibling = field.nextElementSibling;
            while (sibling?.classList?.contains("draft-save-invalid-feedback")) {
                feedbackSiblings.push(sibling);
                sibling = sibling.nextElementSibling;
            }
        } else {
            feedbackSiblings.push(
                ...field.querySelectorAll(":scope > .draft-save-invalid-feedback"),
            );
        }
        feedbackSiblings.forEach((el) => el.remove());
        targets.forEach((el) => {
            el.classList.remove(
                "is-invalid",
                "border-danger",
                "draft-save-invalid-box",
            );
            delete el.dataset.draftSaveInvalid;
        });
        [field.closest(".draft-order-item-card"), field.closest(".draft-order-section")]
            .filter(Boolean)
            .forEach((scope) => {
                if (!scope.querySelector("[data-draft-save-invalid='1']")) {
                    scope.classList.remove(
                        "draft-save-invalid-scope",
                        "draft-save-invalid-section",
                    );
                }
            });
        if (!document.querySelector("#draftOrderModal [data-draft-save-invalid='1']")) {
            const summary = document.getElementById("draftOrderValidationSummary");
            summary?.classList.add("d-none");
        }
    }

    function markDraftInvalidField(target, message) {
        const field =
            typeof target === "string" ? document.querySelector(target) : target;
        if (!field) return null;
        clearDraftInvalidTarget(field);
        const isField = field.matches?.("input, textarea, select");
        field.dataset.draftSaveInvalid = "1";
        field.classList.add(isField ? "is-invalid" : "border-danger");
        if (!isField) field.classList.add("draft-save-invalid-box");
        const card = field.closest(".draft-order-item-card");
        const section = field.closest(".draft-order-section");
        if (card) card.classList.add("draft-save-invalid-scope");
        if (section) section.classList.add("draft-save-invalid-section");
        const feedback = document.createElement("div");
        feedback.className = "invalid-feedback d-block draft-save-invalid-feedback";
        feedback.textContent = message || draftT("This field needs attention.");
        if (isField) {
            field.insertAdjacentElement("afterend", feedback);
        } else {
            field.appendChild(feedback);
        }
        return field;
    }

    function scrollToDraftInvalidField(field) {
        if (!field) return;
        const section = field.closest?.(".draft-order-section");
        if (section?.dataset?.collapsed === "1") {
            section.dataset.collapsed = "0";
            syncDraftSectionCollapse(section);
        }
        const focusTarget =
            field.matches?.("input, textarea, select, button")
                ? field
                : field.querySelector("input, textarea, select, button");
        window.setTimeout(
            () => field.scrollIntoView({ behavior: "smooth", block: "center" }),
            60,
        );
        setTimeout(() => focusTarget?.focus?.({ preventScroll: true }), 250);
    }

    function bindDraftValidationAutoClear(root) {
        const container = root || document.getElementById("draftOrderModal");
        if (!container || container.dataset.draftValidationAutoClearBound === "1") {
            return;
        }
        container.dataset.draftValidationAutoClearBound = "1";
        container.addEventListener("input", (event) => {
            const target = event.target;
            if (target?.dataset?.draftSaveInvalid === "1") {
                clearDraftInvalidTarget(target);
            }
        });
        container.addEventListener("change", (event) => {
            const target = event.target;
            if (target?.dataset?.draftSaveInvalid === "1") {
                clearDraftInvalidTarget(target);
            }
            const card = target?.closest?.(".draft-order-item-card");
            if (card?.dataset?.draftSaveInvalid === "1") {
                clearDraftInvalidTarget(card);
            }
        });
    }

    function draftItemHasCbmOrDimensions(card) {
        if (draftPositiveNumber(card.querySelector(".draft-item-cbm")?.value) > 0) {
            return true;
        }
        return (
            draftPositiveNumber(card.querySelector(".draft-item-length")?.value) >
                0 &&
            draftPositiveNumber(card.querySelector(".draft-item-width")?.value) >
                0 &&
            draftPositiveNumber(card.querySelector(".draft-item-height")?.value) >
                0
        );
    }

    function validateDraftOrderBeforeSave(payload) {
        clearDraftSaveValidation();
        const invalidFields = [];
        const addInvalid = (target, message) => {
            const field = markDraftInvalidField(target, message);
            if (field) invalidFields.push(field);
        };

        if (!payload.customer_id) {
            addInvalid(
                document.getElementById("draftOrderCustomer"),
                draftT("Customer is required."),
            );
        }
        if (
            draftOrderCustomerCountryShipping.length > 1 &&
            !payload.destination_country_id
        ) {
            const destinationTarget =
                document
                    .getElementById("draftOrderDestinationCountrySelectWrap")
                    ?.classList.contains("d-none")
                    ? document.getElementById("draftOrderDestinationCountry")
                    : document.getElementById("draftOrderDestinationCountrySelect");
            addInvalid(
                destinationTarget,
                draftT("Choose one of the selected customer's countries."),
            );
        }
        if (!["USD", "RMB"].includes(String(payload.currency || ""))) {
            addInvalid(
                document.getElementById("draftOrderCurrency"),
                draftT("Currency must be USD or RMB."),
            );
        }

        const sections = Array.from(
            document.querySelectorAll(".draft-order-section"),
        );
        if (!sections.length) {
            addInvalid(
                document.getElementById("draftOrderSections"),
                draftT("Add at least one supplier section."),
            );
        }

        sections.forEach((section) => {
            const supplierId = section
                .querySelector(".draft-section-supplier-id")
                ?.value?.trim();
            if (!supplierId) {
                addInvalid(
                    section.querySelector(".draft-section-supplier"),
                    draftT("Supplier is required."),
                );
            }

            const cards = Array.from(
                section.querySelectorAll(".draft-order-item-card"),
            );
            if (!cards.length) {
                addInvalid(section, draftT("Add at least one item."));
            }

            cards.forEach((card) => {
                const shared = card.dataset.sharedCartonEnabled === "1";
                const cartonsInput = card.querySelector(".draft-item-cartons");
                const piecesInput = card.querySelector(
                    ".draft-item-pieces-per-carton",
                );
                if (draftPositiveNumber(cartonsInput?.value) <= 0) {
                    addInvalid(cartonsInput, draftT("Cartons are required."));
                }
                if (!shared && draftPositiveNumber(piecesInput?.value) <= 0) {
                    addInvalid(
                        piecesInput,
                        draftT("Pieces per carton are required."),
                    );
                }
                if (!draftItemHasCbmOrDimensions(card)) {
                    addInvalid(
                        card.querySelector(".draft-item-volume-panel"),
                        draftT("Add CBM or length, width, and height."),
                    );
                }

                const weightInput = card.querySelector(".draft-item-weight");
                if (parseFloat(weightInput?.value || 0) < 0) {
                    addInvalid(weightInput, draftT("Weight cannot be negative."));
                }
                const unitPriceInput = card.querySelector(".draft-item-unit-price");
                if (parseFloat(unitPriceInput?.value || 0) < 0) {
                    addInvalid(unitPriceInput, draftT("Price cannot be negative."));
                }
                const customerPriceInput = card.querySelector(
                    ".draft-item-customer-price",
                );
                if (parseFloat(customerPriceInput?.value || 0) < 0) {
                    addInvalid(
                        customerPriceInput,
                        draftT("Customer price cannot be negative."),
                    );
                }
                [
                    [card.querySelector(".draft-item-cbm"), draftT("CBM cannot be negative.")],
                    [card.querySelector(".draft-item-length"), draftT("Length cannot be negative.")],
                    [card.querySelector(".draft-item-width"), draftT("Width cannot be negative.")],
                    [card.querySelector(".draft-item-height"), draftT("Height cannot be negative.")],
                ].forEach(([input, message]) => {
                    if (parseFloat(input?.value || 0) < 0) {
                        addInvalid(input, message);
                    }
                });

                if (shared) {
                    const rows = getDraftSharedCartonRows(card);
                    if (!rows.length) {
                        addInvalid(
                            card.querySelector(".draft-shared-carton-panel"),
                            draftT("Shared cartons need contained items."),
                        );
                    }
                    rows.forEach((row) => {
                        if (
                            !row
                                .querySelector(".draft-shared-content-supplier-id")
                                ?.value?.trim()
                        ) {
                            addInvalid(
                                row.querySelector(".draft-shared-content-supplier"),
                                draftT("Contained supplier is required."),
                            );
                        }
                        if (
                            draftPositiveNumber(
                                row.querySelector(
                                    ".draft-shared-content-qty-per-carton",
                                )?.value,
                            ) <= 0
                        ) {
                            addInvalid(
                                row.querySelector(
                                    ".draft-shared-content-qty-per-carton",
                                ),
                                draftT("Quantity inside the carton is required."),
                            );
                        }
                        const desc = row.querySelector(
                            ".draft-shared-content-description-input",
                        );
                        const descCn = row.querySelector(".draft-shared-content-description-cn");
                        if (!String(desc?.value || "").trim() && !String(descCn?.value || "").trim()) {
                            addInvalid(
                                desc,
                                draftT("Contained item description is required."),
                            );
                        }
                        const sharedUnitPrice = row.querySelector(
                            ".draft-shared-content-unit-price",
                        );
                        if (parseFloat(sharedUnitPrice?.value || 0) < 0) {
                            addInvalid(
                                sharedUnitPrice,
                                draftT("Price cannot be negative."),
                            );
                        }
                        const sharedSellPrice = row.querySelector(
                            ".draft-shared-content-sell-price",
                        );
                        if (parseFloat(sharedSellPrice?.value || 0) < 0) {
                            addInvalid(
                                sharedSellPrice,
                                draftT("Customer price cannot be negative."),
                            );
                        }
                    });
                } else if (!draftDescriptionHasContent(card)) {
                    addInvalid(
                        card.querySelector(".draft-item-description-primary") ||
                            card.querySelector(
                                ".draft-description-en, .draft-description-cn",
                            ),
                        draftT("Item description is required."),
                    );
                }

                if (
                    card.querySelector(".draft-item-custom-design-required")
                        ?.checked &&
                    !String(
                        card.querySelector(".draft-item-custom-design-note")
                            ?.value || "",
                    ).trim() &&
                    !(card._designPaths || []).length
                ) {
                    addInvalid(
                        card.querySelector(".draft-item-custom-design-note"),
                        draftT("Add a custom design note or file."),
                    );
                }
            });
        });

        if (invalidFields.length) {
            showDraftValidationSummary(invalidFields.length);
            scrollToDraftInvalidField(invalidFields[0]);
            showToast(
                draftT("Please complete the highlighted fields before saving."),
                "danger",
            );
            return false;
        }
        return true;
    }

    function firstDraftSection() {
        return document.querySelector(".draft-order-section");
    }

    function firstDraftItemCard() {
        return document.querySelector(".draft-order-item-card");
    }

    function firstDraftInvalidBySelector(selector, predicate = null) {
        const nodes = Array.from(document.querySelectorAll(selector));
        return (
            nodes.find((node) => {
                if (typeof predicate === "function") return predicate(node);
                return true;
            }) || null
        );
    }

    function resolveDraftBackendErrorTarget(key, message = "") {
        const token = `${key || ""} ${message || ""}`.toLowerCase();
        if (token.includes("customer_id") || token.includes("customer is required")) {
            return document.getElementById("draftOrderCustomer");
        }
        if (
            token.includes("destination_country") ||
            token.includes("selected customer") ||
            token.includes("customer country")
        ) {
            return document
                .getElementById("draftOrderDestinationCountrySelectWrap")
                ?.classList.contains("d-none")
                ? document.getElementById("draftOrderDestinationCountry")
                : document.getElementById("draftOrderDestinationCountrySelect");
        }
        if (token.includes("currency")) {
            return document.getElementById("draftOrderCurrency");
        }
        if (token.includes("expected_ready_date")) {
            return document.getElementById("draftOrderExpectedDate");
        }
        if (token.includes("supplier")) {
            return (
                firstDraftInvalidBySelector(".draft-section-supplier", (input) => {
                    const section = input.closest(".draft-order-section");
                    return !section
                        ?.querySelector(".draft-section-supplier-id")
                        ?.value?.trim();
                }) || firstDraftSection()
            );
        }
        if (token.includes("description")) {
            return (
                firstDraftInvalidBySelector(
                    ".draft-item-description-primary, .draft-item-description-entry-input, .draft-shared-content-description-input",
                    (input) => !String(input.value || "").trim(),
                ) || firstDraftItemCard()
            );
        }
        if (token.includes("piece") || token.includes("qty_per_carton")) {
            return (
                firstDraftInvalidBySelector(
                    ".draft-item-pieces-per-carton, .draft-shared-content-qty-per-carton",
                    (input) => draftPositiveNumber(input.value) <= 0,
                ) || firstDraftItemCard()
            );
        }
        if (token.includes("carton")) {
            return (
                firstDraftInvalidBySelector(
                    ".draft-item-cartons",
                    (input) => draftPositiveNumber(input.value) <= 0,
                ) || firstDraftItemCard()
            );
        }
        if (
            token.includes("cbm") ||
            token.includes("dimension") ||
            token.includes("length") ||
            token.includes("width") ||
            token.includes("height")
        ) {
            return (
                firstDraftInvalidBySelector(".draft-item-volume-panel", (panel) => {
                    const card = panel.closest(".draft-order-item-card");
                    return card ? !draftItemHasCbmOrDimensions(card) : true;
                }) || firstDraftItemCard()
            );
        }
        if (token.includes("weight")) {
            return firstDraftInvalidBySelector(".draft-item-weight") || firstDraftItemCard();
        }
        if (token.includes("price") || token.includes("amount")) {
            return (
                firstDraftInvalidBySelector(
                    ".draft-item-unit-price, .draft-item-customer-price, .draft-shared-content-unit-price, .draft-shared-content-sell-price",
                    (input) => parseFloat(input.value || 0) < 0,
                ) || firstDraftItemCard()
            );
        }
        if (token.includes("photo") || token.includes("file") || token.includes("design")) {
            return (
                firstDraftInvalidBySelector(".draft-item-custom-design-note") ||
                firstDraftInvalidBySelector(".draft-item-photo-panel") ||
                firstDraftItemCard()
            );
        }
        if (token.includes("item")) {
            return firstDraftItemCard() || firstDraftSection();
        }
        return null;
    }

    function applyDraftBackendValidation(error) {
        const errors = error?.errors && typeof error.errors === "object" ? error.errors : {};
        const invalidFields = [];
        Object.entries(errors).forEach(([key, value]) => {
            const message = Array.isArray(value) ? value.join(", ") : String(value || "");
            const target = resolveDraftBackendErrorTarget(key, message);
            const field = markDraftInvalidField(
                target || firstDraftItemCard() || firstDraftSection() || document.getElementById("draftOrderForm"),
                draftT(message || error.message || "This field needs attention."),
            );
            if (field) invalidFields.push(field);
        });
        if (!invalidFields.length && error?.message) {
            const target = resolveDraftBackendErrorTarget("", error.message);
            if (target) {
                const field = markDraftInvalidField(target, draftT(error.message));
                if (field) invalidFields.push(field);
            }
        }
        if (!invalidFields.length) return false;
        showDraftValidationSummary(
            invalidFields.length,
            draftT("Please complete the highlighted fields before saving."),
        );
        scrollToDraftInvalidField(invalidFields[0]);
        return true;
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
                        existing_item_id: card.dataset.existingItemId || null,
                        item_no: sharedCartonEnabled
                            ? null
                            : card.querySelector(".draft-item-item-no")?.value || null,
                        item_no_manual: sharedCartonEnabled
                            ? 0
                            : card.dataset.manualItemNo
                              ? 1
                              : 0,
                        item_no_source: sharedCartonEnabled
                            ? "generated"
                            : card.dataset.itemNoSource || "generated",
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
                        what_brand:
                            card
                                .querySelector(".draft-item-what-brand")
                                ?.value?.trim() || null,
                        brand:
                            card
                                .querySelector(".draft-item-brand")
                                ?.value?.trim() || null,
                        materials:
                            card
                                .querySelector(".draft-item-materials")
                                ?.value?.trim() || null,
                        copy_normal_goods:
                            card
                                .querySelector(".draft-item-copy-normal-goods")
                                ?.value?.trim() || null,
                        code:
                            card
                                .querySelector(".draft-item-code")
                                ?.value?.trim() || null,
                        express_number:
                            card
                                .querySelector(".draft-item-express-number")
                                ?.value?.trim() || null,
                        size:
                            card
                                .querySelector(".draft-item-size")
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
                        unit:
                            card.querySelector(".draft-item-unit")?.value?.trim() ||
                            "pieces",
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
                        length:
                            card.querySelector(".draft-item-length")?.value ||
                            null,
                        width:
                            card.querySelector(".draft-item-width")?.value ||
                            null,
                        height:
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
                        notes:
                            card.querySelector(".draft-item-notes")?.value?.trim() ||
                            null,
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
            idempotency_key: draftOrderRequestKey || (draftOrderRequestKey = newDraftRequestKey("draft")),
            lock_version: draftOrderLockVersion,
            supplier_sections: supplierSections,
        };
    }

    async function saveDraftOrder() {
        const id = document.getElementById("draftOrderId")?.value || "";
        const payload = collectDraftOrderPayload();
        if (!validateDraftOrderBeforeSave(payload)) {
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
            clearDraftSaveValidation();
            if (res.warning) showToast(res.warning, "warning");
            showToast(id ? "Draft order updated" : "Draft order created");
            refreshUnsavedBaseline?.(
                document.querySelector("#draftOrderModal .modal-body"),
            );
            builderModal?.hide();
            await refreshDraftLists({ deferLegacy: true });
        } catch (e) {
            const mapped = applyDraftBackendValidation(e);
            showToast(
                mapped
                    ? draftT("Please complete the highlighted fields before saving.")
                    : e.message,
                "danger",
            );
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

    function initializeDraftFilters() {
        const params = new URLSearchParams(window.location.search);
        const mapping = {
            q: "draftFilterSearch",
            customer_id: "draftFilterCustomerId",
            supplier_id: "draftFilterSupplierId",
            goods_type: "draftFilterGoodsType",
            brand: "draftFilterBrand",
            creator_id: "draftFilterCreator",
            created_from: "draftFilterCreatedFrom",
            created_to: "draftFilterCreatedTo",
            expected_from: "draftFilterExpectedFrom",
            expected_to: "draftFilterExpectedTo",
        };
        Object.entries(mapping).forEach(([key, id]) => {
            const node = document.getElementById(id);
            if (node && params.has(key)) {
                const value = params.get(key) || "";
                if (node.tagName === "SELECT" && value && !Array.from(node.options).some((option) => option.value === value)) node.add(new Option(value, value));
                node.value = value;
            }
        });
        draftListPage = Math.max(1, parseInt(params.get("page") || "1", 10) || 1);
        draftFilterCustomerAc = Autocomplete.init(document.getElementById("draftFilterCustomer"), {
            resource: "customers", searchPath: "/lookup", placeholder: draftT("Search customer"),
            onSelect: (item) => { document.getElementById("draftFilterCustomerId").value = item.id || ""; },
        });
        draftFilterSupplierAc = Autocomplete.init(document.getElementById("draftFilterSupplier"), {
            resource: "suppliers", searchPath: "/search", placeholder: draftT("Search supplier"),
            onSelect: (item) => { document.getElementById("draftFilterSupplierId").value = item.id || ""; },
        });
        document.getElementById("draftFilterCustomer")?.addEventListener("input", () => { document.getElementById("draftFilterCustomerId").value = ""; });
        document.getElementById("draftFilterSupplier")?.addEventListener("input", () => { document.getElementById("draftFilterSupplierId").value = ""; });
        document.getElementById("draftOrderFilters")?.addEventListener("submit", async (event) => {
            event.preventDefault(); draftListPage = 1; await loadDraftOrders();
        });
        document.getElementById("draftFilterClear")?.addEventListener("click", async () => {
            document.getElementById("draftOrderFilters")?.reset();
            draftFilterCustomerAc?.setValue(null); draftFilterSupplierAc?.setValue(null);
            document.getElementById("draftFilterCustomerId").value = "";
            document.getElementById("draftFilterSupplierId").value = "";
            draftListPage = 1; await loadDraftOrders();
        });
        document.getElementById("draftPagePrevious")?.addEventListener("click", async () => { if (draftListPage > 1) { draftListPage--; await loadDraftOrders(); } });
        document.getElementById("draftPageNext")?.addEventListener("click", async () => { if (draftListPage < (draftListMeta.pages || 1)) { draftListPage++; await loadDraftOrders(); } });
        document.getElementById("draftDownloadFilteredBtn")?.addEventListener("click", () => {
            const query = collectDraftFilters(false);
            window.location.href = `${API}/draft-orders/export-filtered?${query.toString()}`;
        });
    }

    document.addEventListener("DOMContentLoaded", async () => {
        draftDownloadSelection = window.ClmsBulkExcelDownload?.create({
            endpoint: `${API}/orders/bulk-export`,
            buttonId: "draftDownloadSelectedBtn",
            countId: "draftDownloadSelectedCount",
            selectAllId: "draftDownloadSelectAll",
            checkboxSelector: ".draft-download-cb",
        });
        builderModal = bootstrap.Modal.getOrCreateInstance(
            document.getElementById("draftOrderModal"),
        );
        migrationModal = bootstrap.Modal.getOrCreateInstance(
            document.getElementById("legacyMigrationModal"),
        );
        draftOrderUnsavedGuard = registerUnsavedChangesGuard?.(
            "#draftOrderModal .modal-body",
        );
        registerUnsavedChangesGuard?.("#legacyMigrationModal .modal-body");
        registerUnsavedChangesGuard?.(
            "#draftCustomerQuickAddModal .modal-body",
        );
        registerUnsavedChangesGuard?.(
            "#draftSupplierQuickAddModal .modal-body",
        );
        document
            .getElementById("draftCustomerQuickForm")
            ?.addEventListener("submit", (event) => {
                event.preventDefault();
                saveDraftQuickCustomer();
            });
        ensureDraftValidationSummary();
        bindDraftValidationAutoClear(document.getElementById("draftOrderModal"));
        initializeDraftFilters();

        draftOrderCustomerAc = Autocomplete.init(
            document.getElementById("draftOrderCustomer"),
            {
                resource: "customers",
                searchPath: "/lookup",
                placeholder: draftT("Type to search customer..."),
                onSelect: async (item) => {
                    clearDraftInvalidTarget(
                        document.getElementById("draftOrderCustomer"),
                    );
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
                placeholder: draftT("Search country..."),
                displayValue: (country) =>
                    typeof window.formatCountryDisplay === "function"
                        ? window.formatCountryDisplay(country.name || "", country.code || "")
                        : `${country.name || ""}${country.code ? ` (${country.code})` : ""}`,
                renderItem: (country) =>
                    typeof window.formatCountryDisplay === "function"
                        ? window.formatCountryDisplay(country.name || "", country.code || "")
                        : `${country.name || ""}${country.code ? ` (${country.code})` : ""}`,
                onSelect: (country) => {
                    clearDraftInvalidTarget(
                        document.getElementById("draftOrderDestinationCountry"),
                    );
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
                clearDraftInvalidTarget(
                    document.getElementById("draftOrderDestinationCountry"),
                );
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
                clearDraftInvalidTarget(this);
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
                searchPath: "/lookup",
                placeholder: draftT("Type to search customer..."),
            },
        );
        document
            .getElementById("draftOrderCurrency")
            ?.addEventListener("change", (event) => {
                clearDraftInvalidTarget(event.currentTarget);
                updateDraftOrderTotals();
            });
        bindDraftOrderImportControls();

        await refreshDraftLists({ deferLegacy: true });

        const params = new URLSearchParams(window.location.search);
        const copyOrderId = params.get("copy_order_id");
        const orderId = params.get("order_id");
        const legacyDraftId = params.get("legacy_draft_id");
        const supplierId = params.get("supplier_id");
        if (copyOrderId) {
            const url = new URL(window.location.href);
            url.searchParams.delete("copy_order_id");
            window.history.replaceState({}, "", url);
            openDraftOrderBuilder(parseInt(copyOrderId, 10), { duplicate: true });
            return;
        }
        if (orderId) {
            openDraftOrderBuilder(parseInt(orderId, 10));
            return;
        }
        if (legacyDraftId) {
            openLegacyMigration(parseInt(legacyDraftId, 10));
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
    window.saveDraftOrderCost = saveDraftOrderCost;
    window.editDraftOrderCost = editDraftOrderCost;
    window.deleteDraftOrderCost = deleteDraftOrderCost;
    window.resetDraftOrderCostEditor = resetDraftOrderCostEditor;
    window.openDraftQuickCustomer = openDraftQuickCustomer;
    window.saveDraftQuickCustomer = saveDraftQuickCustomer;
    window.addDraftQuickSupplierPaymentLink = addDraftQuickSupplierPaymentLink;
    window.saveDraftQuickSupplier = saveDraftQuickSupplier;
    window.submitDraftOrder = submitDraftOrder;
    window.openLegacyMigration = openLegacyMigration;
    window.submitLegacyMigration = submitLegacyMigration;
})();
