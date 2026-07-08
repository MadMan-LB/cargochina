<?php
$area = 'warehouse';
require __DIR__ . '/../../includes/area_bootstrap.php';
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if (!$orderId) {
  header('Location: ' . $areaBase . '/receiving/');
  exit;
}
$currentPage = 'receiving-receive';
$pageTitle = clmsT('Receive Order #{id}', ['id' => $orderId]);
$breadcrumbs = [[clmsT('Warehouse'), '/cargochina/warehouse/'], [clmsT('Receiving'), '/cargochina/warehouse/receiving/'], [clmsT('Receive #{id}', ['id' => $orderId]), '']];
require __DIR__ . '/../../includes/area_layout.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><?= htmlspecialchars(clmsT('Receive Order #{id}', ['id' => $orderId])) ?></h1>
    <div class="d-flex gap-2">
        <a href="/cargochina/api/v1/orders/<?= (int)$orderId ?>/export?format=xlsx" class="btn btn-outline-success btn-sm" target="_blank" rel="noopener"><?= htmlspecialchars(clmsT('Download Order Excel')) ?></a>
        <a href="<?= $areaBase ?>/receiving/" class="btn btn-outline-secondary btn-sm">← <?= htmlspecialchars(clmsT('Back to Queue')) ?></a>
    </div>
</div>

<div id="orderOverview" class="card mb-3">
    <div class="card-header"><?= htmlspecialchars(clmsT('A) Order Overview')) ?></div>
    <div class="card-body" id="orderOverviewBody">
        <div class="placeholder-glow"><span class="placeholder col-6"></span><span class="placeholder col-4"></span>
        </div>
    </div>
</div>

<div id="receiveForm" class="card mb-3">
    <div class="card-header"><?= htmlspecialchars(clmsT('B) Enter Total Receipt Values')) ?></div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-4"><label class="form-label"><?= htmlspecialchars(clmsT('Total Cartons *')) ?></label><input type="number"
                    class="form-control" id="actualCartons" min="0" required></div>
            <div class="col-md-4"><label class="form-label"><?= htmlspecialchars(clmsT('Total CBM')) ?></label><input type="number" step="0.0001"
                    class="form-control" id="actualCbm" min="0" placeholder="<?= htmlspecialchars(clmsT('Auto from item dimensions or enter total')) ?>"></div>
            <div class="col-md-4"><label class="form-label"><?= htmlspecialchars(clmsT('Total Weight *')) ?></label><input type="number" step="0.0001"
                    class="form-control" id="actualWeight" min="0" required placeholder="<?= htmlspecialchars(clmsT('Auto from item weight/carton or enter total')) ?>"></div>
        </div>
        <div class="row mb-3">
            <div class="col-md-4"><label class="form-label"><?= htmlspecialchars(clmsT('Condition')) ?></label><select class="form-select" id="condition">
                    <option value="good"><?= htmlspecialchars(clmsT('Good')) ?></option>
                    <option value="damaged"><?= htmlspecialchars(clmsT('Damaged')) ?></option>
                    <option value="partial"><?= htmlspecialchars(clmsT('Partial')) ?></option>
                </select></div>
            <div class="col-md-8"><label class="form-label"><?= htmlspecialchars(clmsT('Notes')) ?></label><input type="text" class="form-control"
                    id="receiveNotes"></div>
        </div>
        <div class="border rounded p-3 bg-light-subtle" id="receiptFeesSection">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div>
                    <div class="fw-semibold"><?= htmlspecialchars(clmsT('Customer fees')) ?></div>
                    <div class="small text-muted"><?= htmlspecialchars(clmsT('Add customer-facing receiving fees such as pallet fees. These appear on the customer Excel download.')) ?></div>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" id="addReceiptFeeBtn">+ <?= htmlspecialchars(clmsT('Add fee')) ?></button>
            </div>
            <div id="receiptFeesRows" class="d-flex flex-column gap-2"></div>
            <div class="small text-muted mt-2"><?= htmlspecialchars(clmsT('Fee currency follows this order:')) ?> <span id="receiptFeesCurrency">—</span></div>
        </div>
    </div>
</div>

<div class="card mb-3" id="itemLevelSection">
    <div class="card-header">
        <div class="fw-semibold"><?= htmlspecialchars(clmsT('C) Item Quantity & Price')) ?></div>
        <div class="small text-muted"><?= htmlspecialchars(clmsT('Edit cartons, pieces per carton, factory price, and totals per item before recording the receipt.')) ?></div>
    </div>
    <div class="card-body">
        <div id="itemLevelTable" class="table-responsive">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th><?= htmlspecialchars(clmsT('Item')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Declared')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Total Cartons')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Pieces / Carton')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Total Qty')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Unit Price / Factory Price')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Total Amount')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Total CBM')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Weight / Carton')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Total Weight')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Dimensions H/W/L (cm)')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Condition')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Photos')) ?></th>
                    </tr>
                </thead>
                <tbody id="itemLevelBody"></tbody>
            </table>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header"><?= htmlspecialchars(clmsT('D) Evidence Photos')) ?> <span class="text-danger"><?= htmlspecialchars(clmsT('*required if variance or damage')) ?></span></div>
    <div class="card-body">
        <div id="variancePhotoAlert" class="alert alert-warning py-2 d-none" role="alert"><?= htmlspecialchars(clmsT('Photo evidence required.')) ?></div>
        <input type="file" class="d-none" id="receivePhotos" multiple accept="image/*">
        <button type="button" class="btn btn-outline-secondary btn-sm" id="receiveAddPhotoBtn"><?= htmlspecialchars(clmsT('Add Photo')) ?></button>
        <div id="photoPreview" class="mt-2 d-flex flex-wrap gap-2"></div>
    </div>
</div>

<div class="card mb-3" id="varianceResult" style="display:none">
    <div class="card-header"><?= htmlspecialchars(clmsT('E) Variance Results')) ?></div>
    <div class="card-body" id="varianceResultBody"></div>
</div>

<button type="button" class="btn btn-primary" id="submitReceiveBtn"><?= htmlspecialchars(clmsT('Record Receipt')) ?></button>
<?php
$pageScripts = ['/cargochina/frontend/js/upload-utils.js', '/cargochina/frontend/js/photo_uploader.js'];
$pageScript = '/cargochina/frontend/js/receiving_receive.js';
?>
<script>
window.RECEIVE_ORDER_ID = <?= (int)$orderId ?>;
</script>
<?php require __DIR__ . '/../../includes/area_footer.php'; ?>
