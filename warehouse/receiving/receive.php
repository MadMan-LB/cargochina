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
    <div class="card-header"><?= htmlspecialchars(clmsT('B) Enter Actual Totals')) ?></div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-4"><label class="form-label"><?= htmlspecialchars(clmsT('Actual Cartons *')) ?></label><input type="number"
                    class="form-control" id="actualCartons" min="0" required></div>
            <div class="col-md-4"><label class="form-label"><?= htmlspecialchars(clmsT('Actual CBM')) ?></label><input type="number" step="0.0001"
                    class="form-control" id="actualCbm" min="0" placeholder="<?= htmlspecialchars(clmsT('Direct or from L×W×H')) ?>"></div>
            <div class="col-md-4"><label class="form-label"><?= htmlspecialchars(clmsT('L / W / H (cm)')) ?></label>
                <div class="input-group input-group-sm">
                    <input type="number" step="0.01" class="form-control" id="actualLength" placeholder="<?= htmlspecialchars(clmsT('L')) ?>" title="<?= htmlspecialchars(clmsT('Length cm')) ?>">
                    <input type="number" step="0.01" class="form-control" id="actualWidth" placeholder="<?= htmlspecialchars(clmsT('W')) ?>" title="<?= htmlspecialchars(clmsT('Width cm')) ?>">
                    <input type="number" step="0.01" class="form-control" id="actualHeight" placeholder="<?= htmlspecialchars(clmsT('H')) ?>" title="<?= htmlspecialchars(clmsT('Height cm')) ?>">
                </div>
                <small class="text-muted"><?= htmlspecialchars(clmsT('Optional: auto-calculates CBM')) ?></small>
            </div>
            <div class="col-md-4"><label class="form-label"><?= htmlspecialchars(clmsT('Actual Weight *')) ?></label><input type="number" step="0.0001"
                    class="form-control" id="actualWeight" min="0" required></div>
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
                        <th><?= htmlspecialchars(clmsT('Actual Cartons')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Pieces / Carton')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Total Qty')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Unit Price / Factory Price')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Total Amount')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Actual CBM')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Actual Weight')) ?></th>
                        <th><?= htmlspecialchars(clmsT('Dimensions H/W/L')) ?></th>
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
