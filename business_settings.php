<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['SuperAdmin']);
$currentPage = 'business_settings';
$pageTitle = 'Business Settings';
require 'includes/layout.php';
?>
<h1 class="mb-4">Business Settings</h1>
<p class="text-muted mb-4">ETA offsets, container CBM presets, arrival notifications. SuperAdmin only.</p>

<div class="card">
  <div class="card-body">
    <form id="businessSettingsForm">
      <h5 class="mb-3">Container CBM Presets</h5>
      <div class="row mb-3">
        <div class="col-md-4"><label class="form-label">20HQ CBM</label><input type="number" class="form-control" id="CONTAINER_20HQ_CBM" min="1" step="0.1"></div>
        <div class="col-md-4"><label class="form-label">40HQ CBM</label><input type="number" class="form-control" id="CONTAINER_40HQ_CBM" min="1" step="0.1"></div>
        <div class="col-md-4"><label class="form-label">45HQ CBM</label><input type="number" class="form-control" id="CONTAINER_45HQ_CBM" min="1" step="0.1"></div>
      </div>
      <h5 class="mb-3">Arrival Notifications</h5>
      <div class="row mb-3">
        <div class="col-md-6"><label class="form-label">Notify days before arrival (comma-separated)</label><input type="text" class="form-control" id="ARRIVAL_NOTIFY_DAYS" placeholder="7,3,1"></div>
      </div>
      <h5 class="mb-3">Operational Validation Rules</h5>
      <div class="row mb-3">
        <div class="col-md-6"><label class="form-label">Duplicate Shipping Code Handling</label><select class="form-select" id="SHIPPING_CODE_DUPLICATE_ACTION">
            <option value="warn">Warn only</option>
            <option value="block">Block save</option>
          </select><small class="text-muted">Applies to customer default shipping codes and order-item shipping-code duplication checks.</small></div>
      </div>
      <h5 class="mb-3">ETA Offsets (JSON)</h5>
      <div class="row mb-3">
        <div class="col-12"><label class="form-label">Per-country offsets (groupage, full_container, special)</label><textarea class="form-control font-monospace" id="ETA_OFFSETS_JSON" rows="4" placeholder='{"LB":{"groupage":15,"full_container":0},"DEFAULT":{"groupage":0}}'></textarea></div>
      </div>
      <button type="button" class="btn btn-primary" onclick="saveBusinessSettings()">Save</button>
    </form>
  </div>
</div>

<script>
  (function() {
    const API = window.API_BASE || "/cargochina/api/v1";
    async function load() {
      const r = await fetch(API + "/business-settings", {
        credentials: "same-origin"
      });
      const d = await r.json();
      if (!r.ok || d.error) return;
      const data = d.data || {};
      document.getElementById("CONTAINER_20HQ_CBM").value = data.CONTAINER_20HQ_CBM || "28";
      document.getElementById("CONTAINER_40HQ_CBM").value = data.CONTAINER_40HQ_CBM || "68";
      document.getElementById("CONTAINER_45HQ_CBM").value = data.CONTAINER_45HQ_CBM || "78";
      document.getElementById("ARRIVAL_NOTIFY_DAYS").value = data.ARRIVAL_NOTIFY_DAYS || "7,3,1";
      document.getElementById("SHIPPING_CODE_DUPLICATE_ACTION").value = data.SHIPPING_CODE_DUPLICATE_ACTION || "warn";
      document.getElementById("ETA_OFFSETS_JSON").value = typeof data.ETA_OFFSETS_JSON === "string" ? data.ETA_OFFSETS_JSON : JSON.stringify(data.ETA_OFFSETS_JSON || {}, null, 2);
    }
    window.saveBusinessSettings = async function() {
      const config = {
        CONTAINER_20HQ_CBM: document.getElementById("CONTAINER_20HQ_CBM").value,
        CONTAINER_40HQ_CBM: document.getElementById("CONTAINER_40HQ_CBM").value,
        CONTAINER_45HQ_CBM: document.getElementById("CONTAINER_45HQ_CBM").value,
        ARRIVAL_NOTIFY_DAYS: document.getElementById("ARRIVAL_NOTIFY_DAYS").value,
        SHIPPING_CODE_DUPLICATE_ACTION: document.getElementById("SHIPPING_CODE_DUPLICATE_ACTION").value,
        ETA_OFFSETS_JSON: document.getElementById("ETA_OFFSETS_JSON").value
      };
      try {
        const r = await fetch(API + "/business-settings", {
          method: "PUT",
          headers: {
            "Content-Type": "application/json"
          },
          credentials: "same-origin",
          body: JSON.stringify({
            config
          })
        });
        const d = await r.json();
        if (!r.ok || d.error) throw new Error(d.message || "Failed");
        if (window.showToast) showToast("Saved");
        else alert("Saved");
      } catch (e) {
        alert(e.message || "Failed to save");
      }
    };
    document.addEventListener("DOMContentLoaded", load);
  })();
</script>
