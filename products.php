<?php
$currentPage = 'products';
$pageTitle = 'Products';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">Products</h1>
  <div class="card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span>Product List</span>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#productModal" onclick="openProductForm()">+ Add Product</button>
    </div>
    <div class="card-body">
      <div id="productsTable" class="table-responsive">
        <table class="table table-hover">
          <thead>
            <tr>
              <th>Thumbnail</th>
              <th>ID</th>
              <th>Description (CN)</th>
              <th>Description (EN)</th>
              <th>CBM</th>
              <th>Weight</th>
              <th>HS Code</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="productModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="productModalTitle">Add Product</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form id="productForm">
          <input type="hidden" id="productId">
          <div class="row form-row-responsive">
            <div class="col-12 col-md-6 mb-2"><label class="form-label">Description (CN)</label><input type="text" class="form-control" id="productDescCn"></div>
            <div class="col-12 col-md-6 mb-2"><label class="form-label">Description (EN)</label><input type="text" class="form-control" id="productDescEn"></div>
          </div>
          <div class="row form-row-responsive">
            <div class="col-12 col-md-4 mb-2"><label class="form-label">CBM *</label><input type="number" step="0.0001" class="form-control" id="productCbm" required></div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Weight *</label><input type="number" step="0.0001" class="form-control" id="productWeight" required></div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">HS Code</label><input type="text" class="form-control" id="productHsCode"></div>
          </div>
          <div class="mb-2"><label class="form-label">Packaging</label><input type="text" class="form-control" id="productPackaging"></div>
          <div class="mb-2"><label class="form-label">Supplier</label><select class="form-select" id="productSupplier">
              <option value="">— None —</option>
            </select></div>
          <div class="mb-2">
            <label class="form-label">Images</label>
            <div class="border rounded p-2 bg-light" id="productImagesDropZone">
              <input type="file" class="d-none" id="productImagesInput" multiple accept="image/*">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="productAddPhotoBtn" onclick="document.getElementById('productImagesInput').click()">Add Photo</button>
              <span class="ms-2 text-muted small">camera or gallery</span>
            </div>
            <div id="productImagesPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="productSaveBtn" onclick="saveProduct()">Save</button>
      </div>
    </div>
  </div>
</div>
<?php $pageScripts = ['frontend/js/photo_uploader.js'];
$pageScript = 'frontend/js/products.js';
require 'includes/footer.php'; ?>