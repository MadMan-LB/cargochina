<?php
$currentPage = 'consolidation';
$pageTitle = 'Consolidation';
require 'includes/layout.php';
?>
<link rel="stylesheet" href="frontend/css/style.css">
<div class="col-12">
  <h1 class="mb-4">Consolidation</h1>
  <div class="card mb-4">
    <div class="card-body py-2">
      <strong>Ready for consolidation:</strong> <span id="readyOrdersCount">0</span> orders —
      <span id="readyTotalCbm">0</span> CBM, <span id="readyTotalWeight">0</span> kg
    </div>
  </div>
  <div class="row">
    <div class="col-md-6">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Containers</span>
          <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#containerModal">+ Add Container</button>
        </div>
        <div class="card-body">
          <table class="table table-sm">
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
    <div class="col-md-6">
      <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
          <span>Shipment Drafts</span>
          <button class="btn btn-primary btn-sm" onclick="createShipmentDraft()">+ New Draft</button>
        </div>
        <div class="card-body">
          <div id="shipmentDraftsList"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="containerModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Add Container</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label">Presets</label>
          <div class="btn-group btn-group-sm" role="group">
            <button type="button" class="btn btn-outline-secondary" onclick="applyContainerPreset('20HQ',33,21000)">20HQ</button>
            <button type="button" class="btn btn-outline-secondary" onclick="applyContainerPreset('25HQ',41,26000)">25HQ</button>
            <button type="button" class="btn btn-outline-secondary" onclick="applyContainerPreset('40HQ',67,26000)">40HQ</button>
            <button type="button" class="btn btn-outline-secondary" onclick="applyContainerPreset('45HQ',76,29000)">45HQ</button>
          </div>
        </div>
        <div class="mb-2"><label class="form-label">Code *</label><input type="text" class="form-control" id="containerCode"></div>
        <div class="mb-2"><label class="form-label">Max CBM *</label><input type="number" step="0.01" class="form-control" id="containerMaxCbm"></div>
        <div class="mb-2"><label class="form-label">Max Weight (kg) *</label><input type="number" step="0.01" class="form-control" id="containerMaxWeight"></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="button" class="btn btn-primary" onclick="saveContainer()">Save</button></div>
    </div>
  </div>
</div>

<div class="modal fade" id="draftModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Shipment Draft <span id="draftModalId"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label">Add Orders (ReadyForConsolidation / Confirmed)</label>
          <select class="form-select" id="draftAddOrder" multiple></select>
          <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="addOrdersToDraft()">Add Selected</button>
        </div>
        <div class="mb-3"><label class="form-label">Assign Container</label>
          <select class="form-select" id="draftContainer">
            <option value="">— Select —</option>
          </select>
          <button type="button" class="btn btn-sm btn-outline-primary mt-1" onclick="assignContainerToDraft()">Assign</button>
        </div>
        <div class="mb-2"><strong>Orders in draft:</strong> <span id="draftOrderList"></span></div>
        <div class="mb-2 text-muted small"><span id="draftTotalCbm">0</span> CBM, <span id="draftTotalWeight">0</span> kg (vs container capacity)</div>
        <div class="mb-2"><select class="form-select form-select-sm" id="draftRemoveOrder" multiple style="max-height:80px"></select><button type="button" class="btn btn-sm btn-outline-danger mt-1" onclick="removeOrdersFromDraft()">Remove Selected</button></div>
        <div class="mt-2"><button type="button" class="btn btn-success" onclick="finalizeDraft()">Finalize & Push to Tracking</button></div>
      </div>
    </div>
  </div>
</div>
<?php $pageScript = 'frontend/js/consolidation.js';
require 'includes/footer.php'; ?>