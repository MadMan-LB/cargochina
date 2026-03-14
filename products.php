<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);
$currentPage = 'products';
$pageTitle = 'Products';
require 'includes/layout.php';
?>
<h1 class="mb-4">Products</h1>
<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Product List</span>
    <div class="d-flex gap-2">
      <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#importModal" onclick="openImportModal('products')">Import CSV</button>
      <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#productModal" onclick="openProductForm()">+ Add Product</button>
    </div>
  </div>
  <div class="card-body">
    <div id="productsTable" class="table-responsive">
      <table class="table table-hover table-striped table-sm align-middle">
        <thead>
          <tr>
            <th>Thumbnail</th>
            <th>ID</th>
            <th>Description</th>
            <th>Alert</th>
            <th>CBM</th>
            <th>Weight</th>
            <th>Pcs/Carton</th>
            <th>Unit Price</th>
            <th>HS Code</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody></tbody>
      </table>
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
          <div class="mb-2">
            <label class="form-label">Description</label>
            <small class="text-muted d-block mb-1">Type in Chinese or English — auto-translates Chinese to English. Press + to add another field.</small>
            <div id="productDescFields" class="mb-1"></div>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="productDescAddBtn" title="Add another description field" onclick="addProductDescField&&addProductDescField()">+</button>
          </div>
          <div class="row form-row-responsive">
            <div class="col-12 col-md-4 mb-2">
              <label class="form-label">CBM</label>
              <input type="number" step="0.0001" class="form-control" id="productCbm" placeholder="Direct entry or from L×W×H" title="Enter CBM directly, or use L/H/W below">
            </div>
            <div class="col-12 col-md-4 mb-2">
              <label class="form-label">L / W / H (cm)</label>
              <div class="input-group input-group-sm">
                <input type="number" step="0.01" class="form-control" id="productLength" placeholder="L" title="Length cm">
                <input type="number" step="0.01" class="form-control" id="productWidth" placeholder="W" title="Width cm">
                <input type="number" step="0.01" class="form-control" id="productHeight" placeholder="H" title="Height cm">
              </div>
              <small class="text-muted">Optional: auto-calculates CBM when all three filled</small>
            </div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Weight *</label><input type="number" step="0.0001" class="form-control" id="productWeight" required></div>
          </div>
          <div class="row form-row-responsive">
            <div class="col-12 col-md-4 mb-2"><label class="form-label">HS Code</label><input type="text" class="form-control" id="productHsCode"></div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Pieces per carton</label><input type="number" min="1" class="form-control" id="productPiecesPerCarton" placeholder="e.g. 24"></div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Unit price (per piece)</label><input type="number" step="0.0001" class="form-control" id="productUnitPrice" placeholder="e.g. 0.50"><small class="text-muted">Carton total: <span id="productCartonTotal">—</span></small></div>
          </div>
          <div class="row form-row-responsive">
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Buy price (internal)</label><input type="number" step="0.0001" class="form-control" id="productBuyPrice" placeholder="Cost"></div>
            <div class="col-12 col-md-4 mb-2"><label class="form-label">Sell price (customer-facing)</label><input type="number" step="0.0001" class="form-control" id="productSellPrice" placeholder="Falls back to unit price"></div>
          </div>
          <div class="mb-2"><label class="form-label">Packaging</label><input type="text" class="form-control" id="productPackaging"></div>
          <div class="mb-2">
            <label class="form-label">High Alert Note</label>
            <textarea class="form-control product-alert-note" id="productHighAlertNote" rows="2" placeholder="Special color, shape, packaging, fragile handling, or other critical production details"></textarea>
            <small class="text-muted">This is surfaced as an operational alert when the product is selected in orders and related workflows.</small>
          </div>
          <div class="mb-2">
            <label class="form-label">Supplier</label>
            <input type="text" class="form-control" id="productSupplier" placeholder="Type to search supplier..." autocomplete="off">
            <input type="hidden" id="productSupplierId">
          </div>
          <div class="mb-2">
            <label class="form-label">Images</label>
            <div class="border rounded p-2 bg-light" id="productImagesDropZone">
              <input type="file" class="d-none" id="productImagesInput" multiple accept="image/*">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="productAddPhotoBtn" onclick="document.getElementById('productImagesInput').click()">Add Photo</button>
              <span class="ms-2 text-muted small">camera or gallery</span>
            </div>
            <div id="productImagesPreview" class="d-flex flex-wrap gap-2 mt-2"></div>
          </div>
          <div class="mb-2" id="productDesignAttachmentsSection">
            <label class="form-label">Design attachments</label>
            <p class="text-muted small mb-1">Drawings, specs, images, or PDFs. Save product first to add attachments.</p>
            <div id="productDesignAttachmentsList" class="mb-2"></div>
            <div id="productDesignAttachmentsAdd" class="d-none">
              <input type="file" class="d-none" id="productDesignAttachmentInput" accept="image/*,application/pdf,.pdf">
              <button type="button" class="btn btn-outline-secondary btn-sm" id="productAddDesignBtn" onclick="document.getElementById('productDesignAttachmentInput').click()">+ Add design file</button>
            </div>
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
<!-- Import CSV Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Import Products CSV</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted small">Paste CSV or choose file. Columns: <code>description_cn</code>, <code>description_en</code>, <code>cbm</code>, <code>weight</code>, <code>hs_code</code>, <code>pieces_per_carton</code>, <code>unit_price</code>, <code>packaging</code>, <code>supplier_code</code>. Duplicate descriptions skipped.</p>
        <input type="file" class="form-control form-control-sm mb-2" id="importCsvFile" accept=".csv,.txt" title="Choose CSV file">
        <textarea class="form-control font-monospace" id="importCsvData" rows="10" placeholder="description_cn,description_en,cbm,weight,hs_code,pieces_per_carton,unit_price,packaging,supplier_code&#10;产品A,Product A,0.05,2.5,12345678,24,0.5,Box,S001"></textarea>
        <div id="importResult" class="alert d-none mt-2"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="importBtn" onclick="doImport()">Import</button>
      </div>
    </div>
  </div>
</div>
<?php $pageScripts = ['frontend/js/photo_uploader.js', 'frontend/js/autocomplete.js'];
$pageScript = 'frontend/js/products.js';
require 'includes/footer.php'; ?>
