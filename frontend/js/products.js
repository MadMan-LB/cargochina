let productImagePaths = [];

document.addEventListener("DOMContentLoaded", () => {
    loadSuppliers();
    loadProducts();
    setupProductImageUpload();
});

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

async function loadSuppliers() {
    try {
        const res = await api("GET", "/suppliers");
        const sel = document.getElementById("productSupplier");
        sel.innerHTML =
            '<option value="">— None —</option>' +
            (res.data || [])
                .map(
                    (s) =>
                        `<option value="${s.id}">${escapeHtml(s.name)}</option>`,
                )
                .join("");
    } catch (e) {}
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
        <td>${escapeHtml(r.description_cn || "-")}</td>
        <td>${escapeHtml(r.description_en || "-")}</td>
        <td>${r.cbm}</td>
        <td>${r.weight}</td>
        <td>${escapeHtml(r.hs_code || "-")}</td>
        <td class="table-actions">
          <button class="btn btn-sm btn-outline-primary" onclick="editProduct(${r.id})">Edit</button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteProduct(${r.id})">Delete</button>
        </td>
      </tr>
    `,
                )
                .join("") ||
            '<tr><td colspan="8" class="text-muted">No products yet.</td></tr>';
    } catch (e) {
        showToast(e.message, "danger");
    }
}

function openProductForm() {
    document.getElementById("productForm").reset();
    document.getElementById("productId").value = "";
    document.getElementById("productModalTitle").textContent = "Add Product";
    productImagePaths = [];
    renderProductImagesPreview();
}

async function editProduct(id) {
    try {
        const res = await api("GET", "/products/" + id);
        const d = res.data;
        document.getElementById("productId").value = d.id;
        document.getElementById("productDescCn").value = d.description_cn || "";
        document.getElementById("productDescEn").value = d.description_en || "";
        document.getElementById("productCbm").value = d.cbm;
        document.getElementById("productWeight").value = d.weight;
        document.getElementById("productHsCode").value = d.hs_code || "";
        document.getElementById("productPackaging").value = d.packaging || "";
        document.getElementById("productSupplier").value = d.supplier_id || "";
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
    const payload = {
        description_cn:
            document.getElementById("productDescCn").value.trim() || null,
        description_en:
            document.getElementById("productDescEn").value.trim() || null,
        cbm: parseFloat(document.getElementById("productCbm").value) || 0,
        weight: parseFloat(document.getElementById("productWeight").value) || 0,
        hs_code: document.getElementById("productHsCode").value.trim() || null,
        packaging:
            document.getElementById("productPackaging").value.trim() || null,
        supplier_id: document.getElementById("productSupplier").value || null,
        image_paths: productImagePaths,
    };
    if (payload.cbm < 0 || payload.weight < 0) {
        showToast("CBM and weight must be non-negative", "danger");
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
