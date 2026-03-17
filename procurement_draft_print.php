<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
  header('Location: procurement_drafts.php');
  exit;
}

require_once __DIR__ . '/backend/config/database.php';
$pdo = getDb();

$stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.id = ?");
$stmt->execute([$id]);
$draft = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$draft) {
  header('Location: procurement_drafts.php');
  exit;
}

$itemsStmt = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en, p.cbm, p.weight, p.unit_price FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order, pdi.id");
$itemsStmt->execute([$id]);
$items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

$basePath = '/cargochina';
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Print: <?= htmlspecialchars($draft['name'] ?? 'Procurement Draft') ?> | CLMS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    @media print {
      .no-print {
        display: none !important;
      }

      body {
        padding: 0;
      }

      .print-container {
        box-shadow: none;
        border: none;
      }
    }

    @media screen {
      .print-container {
        max-width: 900px;
        margin: 2rem auto;
        padding: 1.5rem;
      }
    }
  </style>
</head>

<body>
  <div class="print-container">
    <div class="no-print mb-3 d-flex justify-content-between align-items-center">
      <a href="<?= $basePath ?>/procurement_drafts.php" class="btn btn-outline-secondary btn-sm">&larr; Back</a>
      <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
    </div>

    <h1 class="h4 mb-2"><?= htmlspecialchars($draft['name'] ?? 'Procurement Draft') ?></h1>
    <p class="text-muted small mb-3">
      Supplier: <?= htmlspecialchars($draft['supplier_name'] ?? '—') ?> &nbsp;|&nbsp;
      Status: <?= htmlspecialchars($draft['status'] ?? '') ?> &nbsp;|&nbsp;
      Created: <?= $draft['created_at'] ? date('d/m/Y', strtotime($draft['created_at'])) : '—' ?>
    </p>

    <table class="table table-bordered table-sm">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Description</th>
          <th>Qty</th>
          <th>CBM</th>
          <th>Weight (kg)</th>
          <th>Unit Price</th>
          <th>Total</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $i => $it): ?>
          <?php
          $qty = (float) ($it['quantity'] ?? 0);
          $cbm = (float) ($it['cbm'] ?? 0);
          $weight = (float) ($it['weight'] ?? 0);
          $unitPrice = (float) ($it['unit_price'] ?? 0);
          $totalCbm = $qty > 0 ? round($cbm * $qty, 4) : 0;
          $totalWeight = $qty > 0 ? round($weight * $qty, 4) : 0;
          $totalAmount = $qty > 0 && $unitPrice > 0 ? round($unitPrice * $qty, 2) : '';
          $desc = trim($it['description_en'] ?? $it['description_cn'] ?? $it['notes'] ?? '');
          ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td><?= htmlspecialchars($desc ?: '—') ?></td>
            <td><?= $qty ?></td>
            <td><?= $totalCbm ?></td>
            <td><?= $totalWeight ?></td>
            <td><?= $unitPrice > 0 ? number_format($unitPrice, 2) : '—' ?></td>
            <td><?= $totalAmount !== '' ? number_format($totalAmount, 2) : '—' ?></td>
            <td><?= htmlspecialchars($it['notes'] ?? '') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <p class="small text-muted mt-3 no-print">Use your browser's Print dialog and choose "Save as PDF" to download as PDF.</p>
  </div>
</body>

</html>