let productImagePaths = [];
let productDescEntries = [];
let productSupplierAutocomplete = null;

document.addEventListener("DOMContentLoaded", () => {
    loadProducts();
    setupProductImageUpload();
    setupProductDimensionInputs();
    setupProductDescription();
    setupProductSupplierAutocomplete();
    setupProductHsCodeAutocomplete();
    setupProductPricing();
});

function setupProductPricing() {
    const unitPriceEl = document.getElementById("productUnitPrice");
    const piecesEl = document.getElementById("productPiecesPerCarton");
    const totalEl = document.getElementById("productCartonTotal");
    if (!unitPriceEl || !piecesEl) return;
    const updateTotal = () => {
        const price = parseFloat(unitPriceEl.value) || 0;
        const pieces = parseInt(piecesEl.value, 10) || 0;
        if (totalEl)
            totalEl.textContent =
                price > 0 && pieces > 0 ? (price * pieces).toFixed(2) : "—";
    };
    unitPriceEl.addEventListener("input", updateTotal);
    piecesEl.addEventListener("input", updateTotal);
}

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
        if (!inputEl.value.trim()) hiddenEl.value = "";
    });
}

function setupProductHsCodeAutocomplete() {
    const inputEl = document.getElementById("productHsCode");
    if (!inputEl) return;
    if (typeof Autocomplete === "undefined") return;
    Autocomplete.init(inputEl, {
        resource: "products",
        searchPath: "/hs-codes",
        placeholder: "Type to search HS codes...",
        renderItem: (item) => item.hs_code || item.id || "",
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
            cbmEl.value = ((l * w * h) / 1000000).toFixed(4);
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

function setupProductImageUpload() {
    const input = document.getElementById("productImagesInput");
    const dropZone = document.getElementById("productImagesDropZone");
    if (!input || !dropZone) return;

    input.onchange = () => handleProductFiles(input.files);
    dropZone.ondragover = (e) => {
        e.preventDefault();
        dropZone.classList.add("border-primary");
    };
    dropZone.ondragleave = () => dropZone.classList.remove("border-primary");
    dropZone.ondrop = (e) => {
        e.preventDefault();
        dropZone.classList.remove("border-primary");
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
        if (btn) btn.textContent = "Add Photo";
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

async function loadProducts() {
    try {
        const res = await api("GET", "/products");
        const rows = res.data || [];
        const tbody = document.querySelector("#productsTable tbody");
        tbody.innerHTML =
            rows
                .map(
                    (r) => `
      <tr>
        <td>${r.thumbnail_url ? `<img src="${r.thumbnail_url}" class="img-thumbnail img-thumbnail-sm" alt="">` : "—"}</td>
        <td>${r.id}</td>
        <td class="text-truncate" style="max-width:200px" title="${escapeHtml(mergedDescription(r))}">${escapeHtml(mergedDescription(r))}</td>
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
            '<tr><td colspan="9" class="text-muted">No products yet.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

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
        document.getElementById("productHsCode").value = d.hs_code || "";
        document.getElementById("productPackaging").value = d.packaging || "";
        document.getElementById("productPiecesPerCarton").value =
            d.pieces_per_carton ?? "";
        document.getElementById("productUnitPrice").value = d.unit_price ?? "";
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
        hs_code: document.getElementById("productHsCode").value.trim() || null,
        packaging:
            document.getElementById("productPackaging").value.trim() || null,
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
