<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'LebanonAdmin', 'SuperAdmin']);
$canManageContainers = in_array('SuperAdmin', $_SESSION['user_roles'] ?? [], true);
$currentPage = 'consolidation';
$pageTitle = 'Consolidation';
require 'includes/layout.php';
?>
<div id="consolidationPage" data-can-create-container="<?= $canManageContainers ? '1' : '0' ?>">
    <h1 class="mb-4">Consolidation</h1>
    <div class="card mb-4">
        <div class="card-body py-3 px-4">
            <strong>Ready for consolidation:</strong> <span id="readyOrdersCount">0</span> orders —
            <span id="readyTotalCbm">0</span> CBM, <span id="readyTotalWeight">0</span> kg
        </div>
    </div>
    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center py-3">
                    <span class="fw-semibold">Containers</span>
                    <?php if ($canManageContainers): ?>
                        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal"
                            data-bs-target="#containerModal">+ Add Container</button>
                    <?php endif; ?>
                </div>
                <div class="card-body py-3">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Max CBM</th>
                                <th>Max Weight</th>
                            </tr>
                        </thead>
                        <tbody id="containersBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center py-3">
                    <span class="fw-semibold">Shipment Drafts</span>
                    <button type="button" class="btn btn-primary btn-sm" onclick="createShipmentDraft()">+ New
                        Draft</button>
                </div>
                <div class="card-body py-3">
                    <div id="shipmentDraftsList" class="consolidation-drafts-list"></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($canManageContainers): ?>
        <div class="modal fade" id="containerModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Add Container</h5><button type="button" class="btn-close"
                            data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-2"><label class="form-label">Presets</label>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-outline-secondary"
                                    onclick="applyContainerPreset('20HQ',68,28000)">20HQ</button>
                                <button type="button" class="btn btn-outline-secondary"
                                    onclick="applyContainerPreset('40HQ',68,28000)">40HQ</button>
                                <button type="button" class="btn btn-outline-secondary"
                                    onclick="applyContainerPreset('45HQ',78,28000)">45HQ</button>
                            </div>
                        </div>
                        <div class="mb-2"><label class="form-label">Code *</label><input type="text" class="form-control"
                                id="containerCode"></div>
                        <div class="mb-2"><label class="form-label">Max CBM *</label><input type="number" step="0.01"
                                class="form-control" id="containerMaxCbm"></div>
                        <div class="mb-2"><label class="form-label">Max Weight (kg) *</label><input type="number" step="0.01"
                                class="form-control" id="containerMaxWeight"></div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-primary" onclick="saveContainer()">Save</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="modal fade" id="draftModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Shipment Draft <span id="draftModalId"></span></h5>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCurrentDraft()"
                            id="draftDeleteBtn">Delete Draft</button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body px-4 py-3">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="card mb-0">
                                <div class="card-header py-2"><strong>Add Orders</strong> <small class="text-muted">(Ready /
                                        Confirmed)</small></div>
                                <div class="card-body py-3">
                                    <div class="table-responsive" style="max-height:200px">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width:36px"><input type="checkbox" id="draftAddSelectAll"
                                                            onchange="toggleAddSelectAll()"></th>
                                                    <th>Order</th>
                                                    <th>CBM</th>
                                                    <th>kg</th>
                                                </tr>
                                            </thead>
                                            <tbody id="draftAddOrderBody"></tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-primary mt-2"
                                        onclick="addOrdersToDraft()">Add Selected</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card mb-0">
                                <div class="card-header py-2"><strong>Orders in Draft</strong></div>
                                <div class="card-body py-3">
                                    <div class="table-responsive" style="max-height:200px">
                                        <table class="table table-sm table-hover mb-0">
                                            <thead>
                                                <tr>
                                                    <th style="width:36px"><input type="checkbox" id="draftRemoveSelectAll"
                                                            onchange="toggleRemoveSelectAll()"></th>
                                                    <th>Order</th>
                                                    <th>CBM</th>
                                                    <th>kg</th>
                                                </tr>
                                            </thead>
                                            <tbody id="draftRemoveOrderBody"></tbody>
                                        </table>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-danger mt-2"
                                        onclick="removeOrdersFromDraft()">Remove Selected</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mt-1">
                        <div class="col-md-6">
                            <div class="mb-0">
                                <label class="form-label"><strong>Assign Container</strong></label>
                                <div class="d-flex gap-2 align-items-center">
                                    <select class="form-select" id="draftContainer" style="max-width:220px">
                                        <option value="">— Select container —</option>
                                    </select>
                                    <button type="button" class="btn btn-primary"
                                        onclick="assignContainerToDraft()">Assign</button>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-0">
                                <label class="form-label"><strong>Totals</strong></label>
                                <div class="d-flex align-items-center gap-3 mb-1">
                                    <span class="fw-semibold"><span id="draftTotalCbm">0</span> CBM</span>
                                    <span class="fw-semibold"><span id="draftTotalWeight">0</span> kg</span>
                                </div>
                                <div id="draftCapacityHint"></div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-12">
                            <label class="form-label"><strong>Carrier refs</strong></label>
                            <div class="row g-2">
                                <div class="col-md-4"><input type="text" class="form-control form-control-sm" id="draftContainerNumber" placeholder="Container #"></div>
                                <div class="col-md-4"><input type="text" class="form-control form-control-sm" id="draftBookingNumber" placeholder="Booking #"></div>
                                <div class="col-md-4"><input type="url" class="form-control form-control-sm" id="draftTrackingUrl" placeholder="Tracking URL"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary mt-2" onclick="saveDraftCarrierRefs()">Save refs</button>
                        </div>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-12">
                            <label class="form-label"><strong>Documents</strong> <small class="text-muted">(BOL, booking confirmation, invoices)</small></label>
                            <div id="draftDocumentsList" class="mb-2 small"></div>
                            <div class="d-flex gap-2 align-items-center">
                                <input type="file" class="form-control form-control-sm d-none" id="draftDocInput" accept="image/*,.pdf,.png,.jpg,.jpeg,.webp">
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('draftDocInput').click()">+ Add document</button>
                                <select class="form-select form-select-sm" id="draftDocType" style="max-width:180px">
                                    <option value="bol">BOL</option>
                                    <option value="booking_confirmation">Booking confirmation</option>
                                    <option value="invoice">Invoice</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <hr>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-muted small">Finalize when orders are added and container assigned.</span>
                        <button type="button" class="btn btn-success" onclick="openFinalizeConfirm()">Finalize & Push to
                            Tracking</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="finalizeConfirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Confirm Finalize</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Finalize this shipment draft and push to tracking? This cannot be undone.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" id="finalizeConfirmBtn"
                        onclick="finalizeDraft()">Finalize</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="deleteDraftConfirmModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Delete Draft</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-0">Delete this draft? Orders will return to Ready for Consolidation.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" id="deleteDraftConfirmBtn">Delete</button>
                </div>
            </div>
        </div>
    </div>
    <?php if (!$canManageContainers): ?>
        <p class="text-muted small mt-3 mb-0">Container creation is limited to SuperAdmin. Consolidation users can still assign existing containers.</p>
    <?php endif; ?>
</div>
<?php $pageScript = '/cargochina/frontend/js/consolidation.js?v=' . filemtime(__DIR__ . '/frontend/js/consolidation.js');
require 'includes/footer.php'; ?>