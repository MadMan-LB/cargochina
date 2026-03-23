let productImagePaths = [];
let productDescEntries = [];
let productSupplierAutocomplete = null;
let productFilterSupplierAutocomplete = null;
let productFilterHsCodeAutocomplete = null;
let productSearchAutocomplete = null;
let productSearchTimer = null;

document.addEventListener("DOMContentLoaded", () => {
    setupProductFilters();
    loadProducts();
    setupProductImageUpload();
    setupProductDimensionInputs();
    setupProductDescription();
    setupProductSupplierAutocomplete();
    setupProductHsCodeAutocomplete();
    setupProductPricing();
    setupProductDesignAttachments();
});

function setProductText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
}

function setupProductFilters() {
    const searchEl = document.getElementById("productSearch");
    const alertEl = document.getElementById("productAlertFilter");
    const imageEl = document.getElementById("productImageFilter");
    const supplierInputEl = document.getElementById(
        "productFilterSupplierSearch",
    );
    const supplierHiddenEl = document.getElementById("productFilterSupplierId");
    const hsInputEl = document.getElementById("productFilterHsCode");

    if (searchEl) {
        if (typeof Autocomplete !== "undefined") {
            productSearchAutocomplete = Autocomplete.init(searchEl, {
                resource: "products",
                searchPath: "/search",
                placeholder: "ID, description, packaging, supplier, HS code…",
                renderItem: (item) => {
                    const desc =
                        mergedDescription(item) || `Product #${item.id}`;
                    const parts = [
                        `#${item.id}`,
                        desc,
                        item.supplier_name || "",
                        item.packaging || "",
                        item.hs_code ? `HS ${item.hs_code}` : "",
                    ].filter(Boolean);
                    const alertText = productAlertText(item);
                    return parts.join(" — ") + (alertText ? " — Alert" : "");
                },
                displayValue: (item) => {
                    const desc =
                        item.description_en ||
                        item.description_cn ||
                        `Product ${item.id}`;
                    return `${item.id} ${desc}`.trim();
                },
                onSelect: () => {
                    clearTimeout(productSearchTimer);
                    loadProducts();
                },
            });
        }
        searchEl.addEventListener("input", () => {
            clearTimeout(productSearchTimer);
            productSearchTimer = setTimeout(loadProducts, 220);
        });
        searchEl.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                clearTimeout(productSearchTimer);
                loadProducts();
            }
        });
    }

    [alertEl, imageEl].forEach((el) => {
        el?.addEventListener("change", loadProducts);
    });

    if (
        supplierInputEl &&
        supplierHiddenEl &&
        typeof Autocomplete !== "undefined"
    ) {
        productFilterSupplierAutocomplete = Autocomplete.init(supplierInputEl, {
            resource: "suppliers",
            placeholder: "Type to search supplier...",
            onSelect: (item) => {
                supplierHiddenEl.value = item.id || "";
                loadProducts();
            },
        });
        supplierInputEl.addEventListener("input", () => {
            supplierHiddenEl.value = "";
        });
        supplierInputEl.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                loadProducts();
            }
        });
    }

    if (hsInputEl && typeof Autocomplete !== "undefined") {
        productFilterHsCodeAutocomplete = Autocomplete.init(hsInputEl, {
            resource: "hs-code-catalog",
            searchPath: "",
            limit: 50,
            placeholder: "Start typing HS code prefix...",
            renderItem: (item) =>
                [item.hs_code, item.name].filter(Boolean).join(" — ") ||
                item.id ||
                "",
            displayValue: (item) => item.hs_code || item.id || "",
            onSelect: (item) => {
                hsInputEl.value = item.hs_code || item.id || "";
                loadProducts();
            },
        });
        hsInputEl.addEventListener("keydown", (event) => {
            if (event.key === "Enter") {
                event.preventDefault();
                loadProducts();
            }
        });
    }
}

function setupProductPricing() {
    const unitPriceEl = document.getElementById("productUnitPrice");
    const piecesEl = document.getElementById("productPiecesPerCarton");
    const cbmEl = document.getElementById("productCbm");
    const weightEl = document.getElementById("productWeight");
    const totalEl = document.getElementById("productCartonTotal");
    const cbmTotalEl = document.getElementById("productCartonCbm");
    const weightTotalEl = document.getElementById("productCartonWeight");
    if (!unitPriceEl || !piecesEl) return;

    const updateCartonTotals = () => {
        const price = parseFloat(unitPriceEl.value) || 0;
        const pieces = parseInt(piecesEl.value, 10) || 0;
        const cbm = parseFloat(cbmEl?.value) || 0;
        const weight = parseFloat(weightEl?.value) || 0;
        const scope = document.querySelector(
            'input[name="productDimensionsScope"]:checked',
        )?.value || "carton";

        if (totalEl)
            totalEl.textContent =
                price > 0 && pieces > 0 ? (price * pieces).toFixed(2) : "—";

        const cartonCbm =
            scope === "piece" && pieces > 0
                ? cbm * pieces
                : scope === "carton"
                  ? cbm
                  : null;
        if (cbmTotalEl)
            cbmTotalEl.textContent =
                cartonCbm != null && cartonCbm > 0
                    ? cartonCbm.toFixed(4)
                    : "—";

        const cartonWeight =
            scope === "piece" && pieces > 0
                ? weight * pieces
                : scope === "carton"
                  ? weight
                  : null;
        if (weightTotalEl)
            weightTotalEl.textContent =
                cartonWeight != null && cartonWeight > 0
                    ? cartonWeight.toFixed(4)
                    : "—";
    };

    [unitPriceEl, piecesEl].forEach((el) =>
        el.addEventListener("input", updateCartonTotals),
    );
    [cbmEl, weightEl].forEach((el) => {
        if (el) el.addEventListener("input", updateCartonTotals);
    });
    document
        .querySelectorAll('input[name="productDimensionsScope"]')
        .forEach((el) => el.addEventListener("change", updateCartonTotals));
    updateCartonTotals();
}

function setupProductDesignAttachments() {
    const addEl = document.getElementById("productDesignAttachmentInput");
    if (!addEl) return;
    addEl.addEventListener("change", async function () {
        const file = this.files?.[0];
        this.value = "";
        if (!file) return;
        const pid = document.getElementById("productId")?.value;
        if (!pid) {
            showToast(
                "Save product first to add design attachments",
                "warning",
            );
            return;
        }
        try {
            const path = await uploadFile(file);
            if (!path) return;
            await api("POST", "/design-attachments", {
                entity_type: "product",
                entity_id: parseInt(pid, 10),
                file_path: path,
                file_type: (file.name || "").split(".").pop() || null,
            });
            loadProductDesignAttachments(parseInt(pid, 10));
            showToast("Design attachment added");
        } catch (e) {
            showToast(e.message, "danger");
        }
    });
}

async function loadProductDesignAttachments(productId) {
    if (!productId) return [];
    try {
        const res = await api(
            "GET",
            "/design-attachments?entity_type=product&entity_id=" + productId,
        );
        const list = res.data || [];
        renderProductDesignAttachments(list);
        return list;
    } catch (e) {
        renderProductDesignAttachments([]);
        return [];
    }
}

window.toggleProductDesignAttachments = function toggleProductDesignAttachments() {
    const section = document.getElementById("productDesignAttachmentsSection");
    const addWrap = document.getElementById("productDesignAttachmentsAdd");
    const checkbox = document.getElementById("productShowDesignAttachments");
    if (!section || !checkbox) return;
    if (checkbox.checked) {
        section.classList.remove("d-none");
        if (addWrap) addWrap.classList.remove("d-none");
    } else {
        section.classList.add("d-none");
        if (addWrap) addWrap.classList.add("d-none");
    }
};

function renderProductDesignAttachments(list) {
    const el = document.getElementById("productDesignAttachmentsList");
    if (!el) return;
    if (!list.length) {
        el.innerHTML =
            '<p class="text-muted small mb-0">No design attachments</p>';
        return;
    }
    const base = (window.API_BASE || "/cargochina/api/v1").replace(
        "/api/v1",
        "",
    );
    el.innerHTML = list
        .map(
            (a) => `
        <div class="d-flex align-items-center gap-2 mb-1" data-attachment-id="${a.id}">
          <a href="${base}/backend/${a.file_path}" target="_blank" class="small text-truncate" style="max-width:200px">${escapeHtml((a.internal_note || a.file_path || "Attachment").substring(0, 40))}</a>
          <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteProductDesignAttachment(${a.id})">×</button>
        </div>`,
        )
        .join("");
}

window.deleteProductDesignAttachment = async function (attachmentId) {
    const pid = document.getElementById("productId")?.value;
    if (!pid) return;
    try {
        await api("DELETE", "/design-attachments/" + attachmentId);
        loadProductDesignAttachments(parseInt(pid, 10));
        showToast("Attachment removed");
    } catch (e) {
        showToast(e.message, "danger");
    }
};

function setupProductDescription() {
    const containerEl = document.getElementById("productDescFields");
    const addBtn = document.getElementById("productDescAddBtn");
    if (!containerEl || !addBtn) return;

    const addDescField = (text = "", translated = "") => {
        const id = Date.now() + Math.random();
        productDescEntries.push({ id, text, translated });
        renderDescFields();
    };

    const renderDescFields = () => {
        containerEl.innerHTML = productDescEntries
            .map(
                (e, i) => `
          <div class="d-flex align-items-center gap-2 mb-1" data-desc-idx="${i}">
            <input type="text" class="form-control form-control-sm flex-grow-1 product-desc-input" placeholder="Chinese or English" value="${escapeHtml(e.text)}" data-idx="${i}">
            <span class="product-desc-translated text-muted small" style="min-width:120px">${e.translated ? escapeHtml(e.translated) : ""}</span>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeProductDescEntry(${i})">×</button>
          </div>
        `,
            )
            .join("");
        containerEl.querySelectorAll(".product-desc-input").forEach((inp) => {
            inp.addEventListener("blur", async function () {
                const idx = parseInt(this.dataset.idx, 10);
                const text = this.value.trim();
                if (!text) return;
                const entry = productDescEntries[idx];
                if (!entry) return;
                entry.text = text;
                const isChinese = /[\u4e00-\u9fff]/.test(text);
                if (isChinese) {
                    try {
                        const res = await api("POST", "/translate", {
                            text,
                            source_lang: "zh",
                            target_lang: "en",
                        });
                        entry.translated =
                            (res.data && res.data.translated) || text;
                    } catch (err) {
                        entry.translated = text;
                    }
                } else {
                    entry.translated = text;
                }
                const span = this.closest("[data-desc-idx]").querySelector(
                    ".product-desc-translated",
                );
                if (span) span.textContent = entry.translated || "";
            });
        });
    };

    window.addProductDescField = () => addDescField();
    window.removeProductDescEntry = (i) => {
        productDescEntries.splice(i, 1);
        if (productDescEntries.length === 0) addDescField();
        else renderDescFields();
    };
    window.renderProductDescEntries = () => {
        if (productDescEntries.length === 0) addDescField();
        else renderDescFields();
    };
    addDescField();
}

function setupProductSupplierAutocomplete() {
    const inputEl = document.getElementById("productSupplier");
    const hiddenEl = document.getElementById("productSupplierId");
    if (!inputEl || !hiddenEl) return;
    if (typeof Autocomplete === "undefined") return;
    productSupplierAutocomplete = Autocomplete.init(inputEl, {
        resource: "suppliers",
        placeholder: "Type to search supplier...",
        onSelect: (item) => {
            hiddenEl.value = item.id;
        },
    });
    inputEl.addEventListener("input", () => {
        hiddenEl.value = "";
    });
}

function setupProductHsCodeAutocomplete() {
    const inputEl = document.getElementById("productHsCode");
    if (!inputEl) return;
    if (typeof Autocomplete === "undefined") return;
    Autocomplete.init(inputEl, {
        resource: "hs-code-catalog",
        searchPath: "",
        limit: 50,
        placeholder: "Start typing HS code prefix...",
        renderItem: (item) =>
            [item.hs_code, item.name].filter(Boolean).join(" — ") ||
            item.id ||
            "",
        displayValue: (item) => item.hs_code || item.id || "",
        onSelect: (item) => {
            inputEl.value = item.hs_code || item.id || "";
        },
    });
}

function setupProductDimensionInputs() {
    const cbmEl = document.getElementById("productCbm");
    const lEl = document.getElementById("productLength");
    const wEl = document.getElementById("productWidth");
    const hEl = document.getElementById("productHeight");
    if (!cbmEl || !lEl || !wEl || !hEl) return;

    const calcCbmFromLwh = () => {
        const l = parseFloat(lEl.value) || 0;
        const w = parseFloat(wEl.value) || 0;
        const h = parseFloat(hEl.value) || 0;
        if (l > 0 && w > 0 && h > 0) {
            const cbm = Math.round(((l * w * h) / 1000000) * 1e6) / 1e6;
            cbmEl.value = cbm.toFixed(6);
            cbmEl.dispatchEvent(new Event("input"));
        }
    };
    [lEl, wEl, hEl].forEach((el) =>
        el.addEventListener("input", calcCbmFromLwh),
    );

    cbmEl.addEventListener("input", () => {
        if (parseFloat(cbmEl.value) > 0) {
            lEl.value = wEl.value = hEl.value = "";
        }
    });
}

let productPhotoSource = "attach";

window.productTakePhoto = function productTakePhoto() {
    const input = document.getElementById("productImagesInput");
    if (!input || typeof PHOTO_UPLOADER === "undefined") return;
    productPhotoSource = "camera";
    PHOTO_UPLOADER.pickPhotos(input, { capture: "environment" });
}

function setupProductImageUpload() {
    const input = document.getElementById("productImagesInput");
    const dropZone = document.getElementById("productImagesDropZone");
    if (!input || !dropZone) return;

    input.onchange = () => {
        handleProductFiles(input.files);
    };
    dropZone.ondragover = (e) => {
        e.preventDefault();
        dropZone.classList.add("border-primary");
    };
    dropZone.ondragleave = () => dropZone.classList.remove("border-primary");
    dropZone.ondrop = (e) => {
        e.preventDefault();
        dropZone.classList.remove("border-primary");
        productPhotoSource = "attach";
        if (e.dataTransfer.files.length)
            handleProductFiles(e.dataTransfer.files);
    };
}

async function handleProductFiles(files) {
    const filesArr = Array.from(files || []).filter((f) =>
        f.type.startsWith("image/"),
    );
    if (!filesArr.length) return;
    const btn = document.getElementById("productAddPhotoBtn");
    try {
        setLoading(btn, true);
        const paths = await PHOTO_UPLOADER.uploadPhotos(
            filesArr,
            (i, total) => {
                if (btn) btn.textContent = `Uploading ${i}/${total}…`;
            },
        );
        paths.forEach((p) => {
            if (p && !productImagePaths.includes(p)) productImagePaths.push(p);
        });
        renderProductImagesPreview();
    } catch (e) {
        showToast("Upload failed: " + (e.message || "Unknown error"), "danger");
    } finally {
        setLoading(btn, false);
        if (btn) {
            btn.textContent =
                btn.id === "productTakePhotoBtn" ? "Take Photo" : "Attach";
        }
        productPhotoSource = "attach";
    }
}

function renderProductImagesPreview() {
    const container = document.getElementById("productImagesPreview");
    if (!container) return;
    container.innerHTML = productImagePaths
        .map(
            (path, i) => `
      <div class="position-relative d-inline-block">
        <img src="/cargochina/backend/${path}" class="img-thumbnail img-thumbnail-sm" alt="${i}">
        <button type="button" class="btn btn-sm btn-danger position-absolute top-0 end-0" style="padding:0.1rem 0.3rem" onclick="removeProductImage(${i})">×</button>
      </div>`,
        )
        .join("");
}

function removeProductImage(index) {
    productImagePaths.splice(index, 1);
    renderProductImagesPreview();
}

function mergedDescription(row) {
    const entries = row.description_entries;
    if (entries && entries.length > 0) {
        return entries
            .map((e) => e.description_translated || e.description_text)
            .join(" | ");
    }
    return row.description_en || row.description_cn || "-";
}

function productAlertText(item) {
    const req = item?.product_required_design || item?.required_design;
    const note = item?.product_high_alert_note || item?.high_alert_note || "";
    return (req ? "Required design. " : "") + (note || "");
}

function renderProductAlertBadge(item) {
    const text = typeof item === "object" ? productAlertText(item) : item || "";
    if (!text) return '<span class="text-muted">—</span>';
    return `<span class="product-alert-badge" title="${escapeHtml(text)}">Alert</span>`;
}

function getProductFilters() {
    return {
        q: document.getElementById("productSearch")?.value.trim() || "",
        supplierId:
            document.getElementById("productFilterSupplierId")?.value || "",
        supplierName:
            document
                .getElementById("productFilterSupplierSearch")
                ?.value.trim() || "",
        hsCode:
            document.getElementById("productFilterHsCode")?.value.trim() || "",
        alertFilter: document.getElementById("productAlertFilter")?.value || "",
        imageFilter: document.getElementById("productImageFilter")?.value || "",
    };
}

function updateProductsOverview(rows) {
    const list = rows || [];
    const filters = getProductFilters();
    const filterParts = [];
    if (filters.q) filterParts.push(`Search: ${filters.q}`);
    if (filters.supplierName)
        filterParts.push(`Supplier: ${filters.supplierName}`);
    if (filters.hsCode) filterParts.push(`HS: ${filters.hsCode}`);
    if (filters.alertFilter === "with") filterParts.push("Alert only");
    if (filters.alertFilter === "without") filterParts.push("Without alert");
    if (filters.imageFilter === "with") filterParts.push("With images");
    if (filters.imageFilter === "without") filterParts.push("Without images");

    const withAlert = list.filter((item) => !!productAlertText(item)).length;
    const withImages = list.filter(
        (item) =>
            Array.isArray(item.image_paths) && item.image_paths.length > 0,
    ).length;
    const withSupplier = list.filter((item) => !!item.supplier_id).length;

    setProductText("productsVisibleCount", String(list.length));
    setProductText(
        "productsVisibleDetail",
        list.length === 1
            ? "1 product matches the current filters."
            : `${list.length} products match the current filters.`,
    );
    setProductText("productsAlertCount", String(withAlert));
    setProductText(
        "productsAlertDetail",
        withAlert
            ? `${withAlert} product(s) need extra operational attention.`
            : "No alert-tagged products in the visible result.",
    );
    setProductText("productsImageCount", String(withImages));
    setProductText(
        "productsImageDetail",
        withImages
            ? `${withImages} product(s) already have images attached.`
            : "No images in the visible result.",
    );
    setProductText("productsSupplierCount", String(withSupplier));
    setProductText(
        "productsSupplierDetail",
        withSupplier
            ? `${withSupplier} product(s) are linked to suppliers.`
            : "No supplier-linked products in the visible result.",
    );
    setProductText(
        "productsFilterSummary",
        filterParts.length
            ? filterParts.join(" | ")
            : "Showing the full product catalog.",
    );
    setProductText(
        "productsGuideSummary",
        list.length
            ? `${list.length} visible | ${withAlert} alerts | ${withImages} with images | ${withSupplier} with supplier links.`
            : "No product data matches the current filters.",
    );
    setProductText("productsCountLabel", list.length ? `(${list.length})` : "");
    setProductText(
        "productsTableSummary",
        list.length
            ? `${list.length} product${list.length === 1 ? "" : "s"} in view`
            : "No matching products",
    );
}

async function loadProducts() {
    try {
        const filters = getProductFilters();
        const params = new URLSearchParams();
        if (filters.q) params.set("q", filters.q);
        if (filters.supplierId) params.set("supplier_id", filters.supplierId);
        if (filters.hsCode) params.set("hs_code", filters.hsCode);
        if (filters.alertFilter)
            params.set("alert_filter", filters.alertFilter);
        if (filters.imageFilter)
            params.set("image_filter", filters.imageFilter);
        const tbody = document.querySelector("#productsTable tbody");
        if (tbody) {
            tbody.innerHTML =
                '<tr><td colspan="11" class="text-center text-muted py-4">Loading products…</td></tr>';
        }
        const res = await api(
            "GET",
            "/products" + (params.toString() ? "?" + params.toString() : ""),
        );
        const rows = res.data || [];
        updateProductsOverview(rows);
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
      <tr>
        <td>${r.thumbnail_url ? `<img src="${r.thumbnail_url}" class="img-thumbnail img-thumbnail-sm" alt="">` : "—"}</td>
        <td>${r.id}</td>
        <td style="min-width:220px">
          <div class="fw-semibold text-truncate" style="max-width:260px" title="${escapeHtml(mergedDescription(r))}">${escapeHtml(mergedDescription(r))}</div>
          <div class="small text-muted text-truncate" style="max-width:260px">${escapeHtml(r.packaging || "No packaging note")}</div>
        </td>
        <td>${escapeHtml(r.supplier_name || "—")}</td>
        <td>${renderProductAlertBadge(r)}</td>
        <td>${r.cbm}</td>
        <td>${r.weight}</td>
        <td>${r.pieces_per_carton ?? "—"}</td>
        <td>${r.unit_price != null ? parseFloat(r.unit_price).toFixed(2) : "—"}</td>
        <td>${escapeHtml(r.hs_code || "-")}</td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-primary" onclick="editProduct(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteProduct(${r.id})">Delete</button>
        </td>
      </tr>
    `,
                )
                .join("") ||
            '<tr><td colspan="11" class="text-center text-muted py-4">No products match the current filters.</td></tr>';
    } catch (e) {
        updateProductsOverview([]);
        const tbody = document.querySelector("#productsTable tbody");
        if (tbody) {
            tbody.innerHTML = `<tr><td colspan="11" class="text-center text-danger py-4">${escapeHtml(e.message || "Failed to load products")}</td></tr>`;
        }
        showToast(e.message, "danger");
    }
}

window.clearProductFilters = function () {
    const searchEl = document.getElementById("productSearch");
    const supplierHiddenEl = document.getElementById("productFilterSupplierId");
    const hsCodeEl = document.getElementById("productFilterHsCode");
    const alertEl = document.getElementById("productAlertFilter");
    const imageEl = document.getElementById("productImageFilter");

    if (searchEl) searchEl.value = "";
    productSearchAutocomplete?.setValue?.(null);
    if (supplierHiddenEl) supplierHiddenEl.value = "";
    productFilterSupplierAutocomplete?.setValue?.(null);
    if (hsCodeEl) hsCodeEl.value = "";
    if (productFilterHsCodeAutocomplete?.setValue) {
        productFilterHsCodeAutocomplete.setValue(null);
    }
    if (alertEl) alertEl.value = "";
    if (imageEl) imageEl.value = "";
    loadProducts();
};

function openProductForm() {
    document.getElementById("productForm").reset();
    document.getElementById("productId").value = "";
    document.getElementById("productSupplierId").value = "";
    document.getElementById("productModalTitle").textContent = "Add Product";
    productImagePaths = [];
    productDescEntries = [];
    renderProductImagesPreview();
    if (window.renderProductDescEntries) window.renderProductDescEntries();
    if (productSupplierAutocomplete && productSupplierAutocomplete.setValue)
        productSupplierAutocomplete.setValue(null);
    document.getElementById("productHighAlertNote").value = "";
    const pieceRadio = document.getElementById("productDimensionsPiece");
    const cartonRadio = document.getElementById("productDimensionsCarton");
    if (pieceRadio) pieceRadio.checked = false;
    if (cartonRadio) cartonRadio.checked = true;
    const reqDesignCb = document.getElementById("productRequiredDesign");
    if (reqDesignCb) reqDesignCb.checked = false;
    const cartonTotalEl = document.getElementById("productCartonTotal");
    const cartonCbmEl = document.getElementById("productCartonCbm");
    const cartonWeightEl = document.getElementById("productCartonWeight");
    if (cartonTotalEl) cartonTotalEl.textContent = "—";
    if (cartonCbmEl) cartonCbmEl.textContent = "—";
    if (cartonWeightEl) cartonWeightEl.textContent = "—";
    renderProductDesignAttachments([]);
    const showDesignCb = document.getElementById("productShowDesignAttachments");
    const designSection = document.getElementById("productDesignAttachmentsSection");
    const designAdd = document.getElementById("productDesignAttachmentsAdd");
    if (showDesignCb) showDesignCb.checked = false;
    if (designSection) designSection.classList.add("d-none");
    if (designAdd) designAdd.classList.add("d-none");
}

async function editProduct(id) {
    try {
        const res = await api("GET", "/products/" + id);
        const d = res.data;
        document.getElementById("productId").value = d.id;
        productDescEntries = (d.description_entries || []).map((e) => ({
            id: Date.now() + Math.random(),
            text: e.description_text || e.description_cn || "",
            translated: e.description_translated || e.description_en || "",
        }));
        if (
            productDescEntries.length === 0 &&
            (d.description_cn || d.description_en)
        ) {
            productDescEntries = [
                {
                    id: Date.now(),
                    text: d.description_cn || d.description_en,
                    translated: d.description_en || d.description_cn,
                },
            ];
        }
        if (
            productDescEntries.length === 0 &&
            (d.description_cn || d.description_en)
        ) {
            productDescEntries = [
                {
                    text: d.description_cn || d.description_en,
                    translated: d.description_en || d.description_cn,
                },
            ];
        }
        if (window.renderProductDescEntries) window.renderProductDescEntries();
        document.getElementById("productCbm").value = d.cbm ?? "";
        document.getElementById("productLength").value = d.length_cm ?? "";
        document.getElementById("productWidth").value = d.width_cm ?? "";
        document.getElementById("productHeight").value = d.height_cm ?? "";
        document.getElementById("productWeight").value = d.weight;
        const scope = (d.dimensions_scope || "piece").toLowerCase();
        const pieceRadio = document.getElementById("productDimensionsPiece");
        const cartonRadio = document.getElementById("productDimensionsCarton");
        if (pieceRadio) pieceRadio.checked = scope === "piece";
        if (cartonRadio) cartonRadio.checked = scope === "carton";
        document.getElementById("productHsCode").value = d.hs_code || "";
        document.getElementById("productPackaging").value = d.packaging || "";
        document.getElementById("productHighAlertNote").value =
            d.high_alert_note || "";
        const reqDesignCb = document.getElementById("productRequiredDesign");
        if (reqDesignCb)
            reqDesignCb.checked = !!(
                d.required_design && d.required_design !== 0
            );
        document.getElementById("productPiecesPerCarton").value =
            d.pieces_per_carton ?? "";
        document.getElementById("productUnitPrice").value = d.unit_price ?? "";
        document
            .getElementById("productUnitPrice")
            ?.dispatchEvent(new Event("input"));
        const buyEl = document.getElementById("productBuyPrice");
        const sellEl = document.getElementById("productSellPrice");
        if (buyEl) buyEl.value = d.buy_price ?? "";
        if (sellEl) sellEl.value = d.sell_price ?? "";
        document.getElementById("productSupplierId").value =
            d.supplier_id || "";
        if (
            productSupplierAutocomplete &&
            productSupplierAutocomplete.setValue
        ) {
            productSupplierAutocomplete.setValue(
                d.supplier_id && d.supplier_name
                    ? { id: d.supplier_id, name: d.supplier_name }
                    : null,
            );
        } else {
            document.getElementById("productSupplier").value =
                d.supplier_name || "";
        }
        document.getElementById("productModalTitle").textContent =
            "Edit Product";
        productImagePaths = d.image_paths || [];
        renderProductImagesPreview();
        const designAttachments = await loadProductDesignAttachments(d.id);
        const showDesignCb = document.getElementById("productShowDesignAttachments");
        const designSection = document.getElementById("productDesignAttachmentsSection");
        const designAdd = document.getElementById("productDesignAttachmentsAdd");
        if (designAttachments.length > 0 && showDesignCb) {
            showDesignCb.checked = true;
            if (designSection) designSection.classList.remove("d-none");
            if (designAdd) designAdd.classList.remove("d-none");
        } else {
            if (showDesignCb) showDesignCb.checked = false;
            if (designSection) designSection.classList.add("d-none");
            if (designAdd) designAdd.classList.add("d-none");
        }
        new bootstrap.Modal(document.getElementById("productModal")).show();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

async function saveProduct() {
    const btn = document.getElementById("productSaveBtn");
    const id = document.getElementById("productId").value;
    const cbmRaw = document.getElementById("productCbm").value;
    const l = parseFloat(document.getElementById("productLength").value) || 0;
    const w = parseFloat(document.getElementById("productWidth").value) || 0;
    const h = parseFloat(document.getElementById("productHeight").value) || 0;
    const cbm =
        parseFloat(cbmRaw) ||
        (l > 0 && w > 0 && h > 0 ? (l * w * h) / 1000000 : 0);

    const descriptionEntries = productDescEntries
        .map((e) => ({
            description_text: (e.text || "").trim(),
            description_translated: (e.translated || "").trim(),
        }))
        .filter((e) => e.description_text);

    const payload = {
        description_entries: descriptionEntries,
        cbm,
        length_cm: l > 0 ? l : null,
        width_cm: w > 0 ? w : null,
        height_cm: h > 0 ? h : null,
        weight: parseFloat(document.getElementById("productWeight").value) || 0,
        dimensions_scope:
            document.querySelector(
                'input[name="productDimensionsScope"]:checked',
            )?.value || "piece",
        hs_code: document.getElementById("productHsCode").value.trim() || null,
        packaging:
            document.getElementById("productPackaging").value.trim() || null,
        high_alert_note:
            document.getElementById("productHighAlertNote").value.trim() ||
            null,
        required_design: document.getElementById("productRequiredDesign")
            ?.checked
            ? 1
            : 0,
        supplier_id: document.getElementById("productSupplierId").value || null,
        pieces_per_carton: document.getElementById("productPiecesPerCarton")
            .value
            ? parseInt(
                  document.getElementById("productPiecesPerCarton").value,
                  10,
              )
            : null,
        unit_price: document.getElementById("productUnitPrice").value
            ? parseFloat(document.getElementById("productUnitPrice").value)
            : null,
        buy_price: document.getElementById("productBuyPrice")?.value
            ? parseFloat(document.getElementById("productBuyPrice").value)
            : null,
        sell_price: document.getElementById("productSellPrice")?.value
            ? parseFloat(document.getElementById("productSellPrice").value)
            : null,
        image_paths: productImagePaths,
    };
    if (payload.cbm <= 0) {
        showToast(
            "Enter CBM directly or L/H/W (cm) to calculate CBM",
            "danger",
        );
        return;
    }
    if (payload.weight < 0) {
        showToast("Weight must be non-negative", "danger");
        return;
    }
    try {
        setLoading(btn, true);
        if (id) {
            await api("PUT", "/products/" + id, payload);
            showToast("Product updated");
        } else {
            await api("POST", "/products", payload);
            showToast("Product created");
        }
        bootstrap.Modal.getInstance(
            document.getElementById("productModal"),
        ).hide();
        loadProducts();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
}

async function deleteProduct(id) {
    if (!confirm("Delete this product?")) return;
    try {
        await api("DELETE", "/products/" + id);
        showToast("Product deleted");
        loadProducts();
    } catch (e) {
        showToast(e.message, "danger");
    }
}

window.openImportModal = function (entity) {
    window._importEntity = entity || "products";
    window._importOnSuccess = loadProducts;
    const ta = document.getElementById("importCsvData");
    const resultEl = document.getElementById("importResult");
    const fileInput = document.getElementById("importCsvFile");
    if (ta) ta.value = "";
    if (fileInput) fileInput.value = "";
    if (resultEl) {
        resultEl.classList.add("d-none");
        resultEl.textContent = "";
    }
};

window.doImport = async function () {
    const entity = window._importEntity || "products";
    const csv = document.getElementById("importCsvData")?.value?.trim();
    if (!csv) {
        showToast("Paste CSV data first", "danger");
        return;
    }
    const btn = document.getElementById("importBtn");
    const resultEl = document.getElementById("importResult");
    try {
        setLoading(btn, true);
        if (resultEl) {
            resultEl.classList.add("d-none");
            resultEl.textContent = "";
        }
        const res = await api("POST", "/" + entity + "/import", { csv });
        const d = res.data;
        let msg = `Created: ${d.created}, Skipped: ${d.skipped}`;
        if (d.errors?.length) msg += `; Errors: ${d.errors.join("; ")}`;
        if (resultEl) {
            resultEl.textContent = msg;
            resultEl.className =
                "alert alert-" +
                (d.errors?.length ? "warning" : "success") +
                " mt-2";
            resultEl.classList.remove("d-none");
        }
        showToast(msg);
        if (d.created > 0 && window._importOnSuccess) window._importOnSuccess();
    } catch (e) {
        showToast(e.message, "danger");
    } finally {
        setLoading(btn, false);
    }
};
