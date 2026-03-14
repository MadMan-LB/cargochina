<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Order Confirmation — Salameh Cargo</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --brand: #2563eb;
      --brand-light: #eff6ff;
      --success: #16a34a;
      --warning: #d97706;
      --radius: 16px;
    }

    body {
      background: #f1f5f9;
      min-height: 100vh;
      display: flex;
      align-items: flex-start;
      justify-content: center;
      padding: 2rem 1rem;
      font-family: 'Segoe UI', system-ui, sans-serif;
    }

    .confirm-card {
      background: #fff;
      border-radius: var(--radius);
      box-shadow: 0 8px 32px rgba(15, 23, 42, .10);
      max-width: 680px;
      width: 100%;
      overflow: hidden;
    }

    .confirm-header {
      background: linear-gradient(135deg, var(--brand) 0%, #1d4ed8 100%);
      color: #fff;
      padding: 1.75rem 2rem 1.5rem;
    }

    .confirm-header h1 {
      font-size: 1.4rem;
      font-weight: 700;
      margin: 0 0 .25rem;
    }

    .confirm-header p {
      font-size: .9rem;
      opacity: .85;
      margin: 0;
    }

    .confirm-body {
      padding: 1.5rem 2rem;
    }

    .info-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: .75rem;
      margin-bottom: 1.25rem;
    }

    .info-cell {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: .75rem 1rem;
    }

    .info-label {
      font-size: .7rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .06em;
      color: #64748b;
      margin-bottom: .2rem;
    }

    .info-value {
      font-size: 1rem;
      font-weight: 600;
      color: #0f172a;
    }

    .variance-banner {
      background: #fffbeb;
      border: 1px solid #fde68a;
      border-radius: 12px;
      padding: .85rem 1.1rem;
      margin-bottom: 1.25rem;
      display: flex;
      gap: .75rem;
      align-items: flex-start;
    }

    .variance-icon {
      font-size: 1.3rem;
      flex-shrink: 0;
      margin-top: .1rem;
    }

    .items-table {
      width: 100%;
      border-collapse: collapse;
      font-size: .88rem;
    }

    .items-table th {
      background: #f1f5f9;
      color: #475569;
      font-weight: 600;
      padding: .5rem .75rem;
      text-align: left;
      border-bottom: 2px solid #e2e8f0;
    }

    .items-table td {
      padding: .5rem .75rem;
      border-bottom: 1px solid #f1f5f9;
    }

    .photos-row {
      display: flex;
      flex-wrap: wrap;
      gap: .5rem;
      margin: 1rem 0;
    }

    .photos-row img {
      width: 80px;
      height: 80px;
      object-fit: cover;
      border-radius: 8px;
      border: 2px solid #e2e8f0;
      cursor: pointer;
      transition: transform .2s;
    }

    .photos-row img:hover {
      transform: scale(1.05);
    }

    .action-panel {
      background: #f8fafc;
      border-top: 1px solid #e2e8f0;
      padding: 1.5rem 2rem;
    }

    .btn-confirm {
      background: var(--success);
      border: none;
      color: #fff;
      font-size: 1rem;
      font-weight: 600;
      padding: .75rem 2rem;
      border-radius: 10px;
      cursor: pointer;
      transition: background .2s;
      width: 100%;
    }

    .btn-confirm:hover {
      background: #15803d;
    }

    .btn-confirm:disabled {
      background: #9ca3af;
      cursor: not-allowed;
    }

    .success-state {
      text-align: center;
      padding: 3rem 2rem;
    }

    .success-icon {
      font-size: 4rem;
      margin-bottom: 1rem;
    }

    .error-state {
      text-align: center;
      padding: 3rem 2rem;
    }

    .spinner {
      display: inline-block;
      width: 32px;
      height: 32px;
      border: 3px solid #e2e8f0;
      border-top-color: var(--brand);
      border-radius: 50%;
      animation: spin .7s linear infinite;
    }

    @keyframes spin {
      to {
        transform: rotate(360deg);
      }
    }

    @media (max-width: 576px) {

      .confirm-body,
      .action-panel {
        padding: 1rem 1.25rem;
      }
    }
  </style>
</head>

<body>
  <div class="confirm-card" id="app">
    <div style="padding:3rem; text-align:center;">
      <div class="spinner"></div>
      <p class="mt-3 text-muted">Loading order details…</p>
    </div>
  </div>

  <script>
    const API = '/cargochina/api/v1';
    const token = new URLSearchParams(location.search).get('token') || '';

    function esc(s) {
      const d = document.createElement('div');
      d.textContent = String(s ?? '');
      return d.innerHTML;
    }

    function fmt(n, digits = 4) {
      return n != null ? parseFloat(n).toFixed(digits) : '—';
    }

    function descLang() {
      return localStorage.getItem('clms_desc_lang') || 'en';
    }

    function descText(it) {
      const lang = descLang();
      return (lang === 'cn' ? (it.description_cn || it.description_en) : (it.description_en || it.description_cn)) || '—';
    }

    function setDescLang(lang) {
      localStorage.setItem('clms_desc_lang', lang);
    }

    async function loadOrder() {
      const app = document.getElementById('app');
      if (!token) {
        renderError(app, 'No confirmation token provided. Please use the link from your notification.');
        return;
      }
      try {
        const res = await fetch(`${API}/?path=confirm&token=${encodeURIComponent(token)}`);
        const data = await res.json();
        if (!res.ok || data.error) {
          renderError(app, data.message || 'Could not load order details.');
          return;
        }
        renderOrder(app, data.data);
      } catch (e) {
        renderError(app, 'Network error. Please try again.');
      }
    }

    function renderOrder(app, o) {
      const photosHtml = (o.receipt_photos || []).length ?
        `<div class="photos-row">${o.receipt_photos.map(p => `<img src="/cargochina/backend/${esc(p)}" alt="Receipt photo" onclick="window.open(this.src)" title="Click to enlarge">`).join('')}</div>` :
        '';

      const itemsHtml = (o.items || []).map(it => `
    <tr>
      <td>${esc(it.item_no || '—')}</td>
      <td>${esc(descText(it))}</td>
      <td>${esc(it.shipping_code || '—')}</td>
      <td>${esc(it.cartons || '—')}</td>
      <td>${esc(it.quantity || '—')} ${esc(it.unit || '')}</td>
      <td>${fmt(it.declared_cbm, 4)}</td>
      <td>${fmt(it.declared_weight, 2)} kg</td>
    </tr>`).join('');

      app.innerHTML = `
    <div class="confirm-header">
      <h1>⚖️ Warehouse Receipt Confirmation</h1>
      <p>Order #${esc(o.id)} — Please review the actual measurements and confirm acceptance</p>
    </div>
    <div class="confirm-body">
      <div class="info-grid">
        <div class="info-cell"><div class="info-label">Customer</div><div class="info-value">${esc(o.customer_name)}</div></div>
        <div class="info-cell"><div class="info-label">Supplier</div><div class="info-value">${esc(o.supplier_name)}</div></div>
        <div class="info-cell"><div class="info-label">Expected Ready</div><div class="info-value">${esc(o.expected_ready_date || '—')}</div></div>
        <div class="info-cell"><div class="info-label">Currency</div><div class="info-value">${esc(o.currency || '—')}</div></div>
      </div>

      <div class="variance-banner">
        <div class="variance-icon">⚠️</div>
        <div>
          <strong>Variance Detected</strong><br>
          <span class="text-muted" style="font-size:.9rem;">The actual warehouse measurements differ from the declared values. Please review and confirm or contact us.</span>
        </div>
      </div>

      <h6 class="fw-bold mb-2" style="color:#0f172a;">Actual Measurements (at warehouse)</h6>
      <div class="info-grid mb-3">
        <div class="info-cell"><div class="info-label">Actual CBM</div><div class="info-value text-warning fw-bold">${fmt(o.actual_cbm, 4)} m³</div></div>
        <div class="info-cell"><div class="info-label">Actual Weight</div><div class="info-value text-warning fw-bold">${fmt(o.actual_weight, 2)} kg</div></div>
        <div class="info-cell"><div class="info-label">Actual Cartons</div><div class="info-value">${esc(o.actual_cartons ?? '—')}</div></div>
        <div class="info-cell"><div class="info-label">Condition</div><div class="info-value">${esc(o.receipt_condition || '—')}</div></div>
      </div>

      ${photosHtml ? `<h6 class="fw-bold mb-2" style="color:#0f172a;">Warehouse Evidence Photos</h6>${photosHtml}` : ''}

      ${itemsHtml ? `
        <h6 class="fw-bold mb-2 d-flex align-items-center gap-2" style="color:#0f172a;">
          Order Items (declared)
          <span class="btn-group btn-group-sm ms-2">
            <button type="button" class="btn btn-outline-primary ${descLang() === 'en' ? 'active' : ''}" onclick="setDescLang('en'); location.reload();" title="English">EN</button>
            <button type="button" class="btn btn-outline-primary ${descLang() === 'cn' ? 'active' : ''}" onclick="setDescLang('cn'); location.reload();" title="Chinese">中文</button>
          </span>
        </h6>
        <div style="overflow-x:auto;">
          <table class="items-table">
            <thead><tr><th>Item No</th><th>Description</th><th>Shipping Code</th><th>Cartons</th><th>Qty</th><th>Decl. CBM</th><th>Decl. Weight</th></tr></thead>
            <tbody>${itemsHtml}</tbody>
          </table>
        </div>` : ''}
    </div>
    <div class="action-panel">
      <p class="text-muted small mb-3">By confirming, you acknowledge the actual measurements above and release Salameh Cargo from responsibility for any declared vs. actual discrepancy.</p>
      <div id="errorMsg" class="alert alert-danger d-none mb-3"></div>
      <div id="declineSection" class="mb-3">
        <label class="form-label small">Or decline (reason required)</label>
        <div class="input-group input-group-sm mb-2">
          <input type="text" class="form-control" id="declineReason" placeholder="Reason for decline (min 5 characters)" maxlength="500">
          <button class="btn btn-outline-danger" type="button" id="declineBtn" onclick="submitDecline()">Decline</button>
        </div>
      </div>
      <button class="btn-confirm" id="confirmBtn" onclick="submitConfirmation()">
        ✓ I Confirm — Accept Actual Measurements
      </button>
      <p class="text-center mt-2 mb-0"><small class="text-muted">This link is single-use and will expire after confirmation or decline.</small></p>
    </div>`;
    }

    async function submitConfirmation() {
      const btn = document.getElementById('confirmBtn');
      const declineBtn = document.getElementById('declineBtn');
      const errEl = document.getElementById('errorMsg');
      btn.disabled = true;
      if (declineBtn) declineBtn.disabled = true;
      btn.textContent = 'Confirming…';
      errEl.classList.add('d-none');
      try {
        const res = await fetch(`${API}/?path=confirm`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            token
          })
        });
        const data = await res.json();
        if (!res.ok || data.error) {
          errEl.textContent = data.message || 'Confirmation failed.';
          errEl.classList.remove('d-none');
          btn.disabled = false;
          if (declineBtn) declineBtn.disabled = false;
          btn.textContent = '✓ I Confirm — Accept Actual Measurements';
          return;
        }
        renderSuccess(document.getElementById('app'));
      } catch (e) {
        errEl.textContent = 'Network error. Please try again.';
        errEl.classList.remove('d-none');
        btn.disabled = false;
        if (declineBtn) declineBtn.disabled = false;
        btn.textContent = '✓ I Confirm — Accept Actual Measurements';
      }
    }

    async function submitDecline() {
      const reason = (document.getElementById('declineReason') || {}).value || '';
      if (reason.trim().length < 5) {
        const errEl = document.getElementById('errorMsg');
        errEl.textContent = 'Please provide a reason for decline (minimum 5 characters).';
        errEl.classList.remove('d-none');
        return;
      }
      const btn = document.getElementById('confirmBtn');
      const declineBtn = document.getElementById('declineBtn');
      const errEl = document.getElementById('errorMsg');
      btn.disabled = true;
      if (declineBtn) declineBtn.disabled = true;
      declineBtn.textContent = 'Declining…';
      errEl.classList.add('d-none');
      try {
        const res = await fetch(`${API}/?path=confirm`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({
            token,
            decline: true,
            decline_reason: reason.trim()
          })
        });
        const data = await res.json();
        if (!res.ok || data.error) {
          errEl.textContent = data.message || 'Decline failed.';
          errEl.classList.remove('d-none');
          btn.disabled = false;
          if (declineBtn) {
            declineBtn.disabled = false;
            declineBtn.textContent = 'Decline';
          }
          return;
        }
        renderDeclined(document.getElementById('app'));
      } catch (e) {
        errEl.textContent = 'Network error. Please try again.';
        errEl.classList.remove('d-none');
        btn.disabled = false;
        if (declineBtn) {
          declineBtn.disabled = false;
          declineBtn.textContent = 'Decline';
        }
      }
    }

    function renderSuccess(app) {
      app.innerHTML = `
    <div class="success-state">
      <div class="success-icon">✅</div>
      <h2 style="color:#16a34a; font-weight:700;">Confirmed!</h2>
      <p class="text-muted">Thank you. Your confirmation has been recorded and Salameh Cargo has been notified.</p>
      <p class="text-muted small">You may close this page.</p>
    </div>`;
    }

    function renderDeclined(app) {
      app.innerHTML = `
    <div class="success-state">
      <div class="success-icon" style="font-size:3rem;">📋</div>
      <h2 style="color:#d97706; font-weight:700;">Declined</h2>
      <p class="text-muted">Your decline has been recorded. Salameh Cargo has been notified and will contact you.</p>
      <p class="text-muted small">You may close this page.</p>
    </div>`;
    }

    function renderError(app, msg) {
      app.innerHTML = `
    <div class="error-state">
      <div style="font-size:3rem; margin-bottom:1rem;">⚠️</div>
      <h2 style="font-weight:700;">Unable to Load</h2>
      <p class="text-muted">${esc(msg)}</p>
      <p class="text-muted small">If you believe this is an error, please contact Salameh Cargo directly.</p>
    </div>`;
    }

    loadOrder();
  </script>
</body>

</html>