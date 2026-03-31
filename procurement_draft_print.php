<?php
require_once 'includes/auth_check.php';
require_once 'includes/page_guard.php';
requireRoleForPage(['ChinaAdmin', 'ChinaEmployee', 'SuperAdmin']);

require_once __DIR__ . '/backend/config/database.php';
$pdo = getDb();
$basePath = '/cargochina';

$orderId = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
$legacyId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

function printDraftEntryRows(array $sections): string
{
    ob_start();
    foreach ($sections as $sectionIndex => $section) {
        $supplierName = $section['supplier_name'] ?? 'Unassigned supplier';
        ?>
        <tr class="table-light">
          <td colspan="11"><strong>Supplier:</strong> <?= htmlspecialchars($supplierName) ?></td>
        </tr>
        <?php foreach (($section['items'] ?? []) as $itemIndex => $item): ?>
            <?php
            $desc = implode(' | ', array_map(
                static fn($entry) => trim((string) ($entry['description_text'] ?? '')),
                $item['description_entries'] ?? []
            ));
            $factoryPrice = isset($item['unit_price']) && $item['unit_price'] !== null
                ? (float) $item['unit_price']
                : null;
            $customerPrice = isset($item['sell_price']) && $item['sell_price'] !== null
                ? (float) $item['sell_price']
                : $factoryPrice;
            $multiplier = (($item['dimensions_scope'] ?? 'carton') === 'carton')
                ? (float) ($item['cartons'] ?? 0)
                : (float) ($item['quantity'] ?? 0);
            $totalCbm = round((float) (($item['cbm'] ?? 0) * $multiplier), 6);
            $totalWeight = round((float) (($item['weight'] ?? 0) * $multiplier), 4);
            ?>
            <tr>
              <td><?= $itemIndex + 1 ?></td>
              <td><?= htmlspecialchars($item['item_no'] ?? '—') ?></td>
              <td><?= htmlspecialchars($desc ?: '—') ?></td>
              <td><?= htmlspecialchars($item['hs_code'] ?? '—') ?></td>
              <td><?= htmlspecialchars((string) ($item['pieces_per_carton'] ?? '—')) ?></td>
              <td><?= htmlspecialchars((string) ($item['cartons'] ?? '—')) ?></td>
              <td><?= htmlspecialchars((string) ($item['quantity'] ?? '—')) ?></td>
              <td><?= $factoryPrice !== null ? number_format($factoryPrice, 2) : '—' ?></td>
              <td><?= $customerPrice !== null ? number_format($customerPrice, 2) : '—' ?></td>
              <td><?= number_format((float) ($item['total_amount'] ?? 0), 2) ?></td>
              <td><?= number_format($totalCbm, 6) ?></td>
              <td><?= number_format($totalWeight, 4) ?></td>
            </tr>
        <?php endforeach; ?>
        <tr class="table-secondary">
          <td colspan="8"><strong>Supplier subtotal</strong></td>
          <td>—</td>
          <td><strong><?= number_format((float) ($section['totals']['amount'] ?? 0), 2) ?></strong></td>
          <td><strong><?= number_format((float) ($section['totals']['cbm'] ?? 0), 6) ?></strong></td>
          <td><strong><?= number_format((float) ($section['totals']['weight'] ?? 0), 4) ?></strong></td>
        </tr>
        <?php
    }
    return ob_get_clean();
}

if ($orderId > 0) {
    $stmt = $pdo->prepare(
        "SELECT o.*, c.name as customer_name, c.default_shipping_code, s.name as supplier_name
         FROM orders o
         JOIN customers c ON o.customer_id = c.id
         LEFT JOIN suppliers s ON o.supplier_id = s.id
         WHERE o.id = ?
           AND o.order_type = 'draft_procurement'"
    );
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        header('Location: procurement_drafts.php');
        exit;
    }

    $itemsStmt = $pdo->prepare(
        "SELECT oi.*, s.name as supplier_name, p.hs_code as product_hs_code, p.dimensions_scope as product_dimensions_scope
         FROM order_items oi
         LEFT JOIN suppliers s ON oi.supplier_id = s.id
         LEFT JOIN products p ON p.id = oi.product_id
         WHERE oi.order_id = ?
         ORDER BY COALESCE(oi.supplier_id, 0), oi.id"
    );
    $itemsStmt->execute([$orderId]);
    $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

    $sections = [];
    foreach ($rows as $row) {
        $supplierId = (int) ($row['supplier_id'] ?? 0);
        $key = $supplierId > 0 ? (string) $supplierId : '0';
        if (!isset($sections[$key])) {
            $sections[$key] = [
                'supplier_name' => $row['supplier_name'] ?? 'Unassigned supplier',
                'items' => [],
                'totals' => ['amount' => 0.0, 'cbm' => 0.0, 'weight' => 0.0],
            ];
        }
        $entries = [];
        $cnParts = array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*/', (string) ($row['description_cn'] ?? '')) ?: [])));
        $enParts = array_values(array_filter(array_map('trim', preg_split('/\s*\|\s*/', (string) ($row['description_en'] ?? '')) ?: [])));
        $entryCount = max(count($cnParts), count($enParts));
        for ($i = 0; $i < $entryCount; $i++) {
            $entries[] = [
                'description_text' => $cnParts[$i] ?? $enParts[$i] ?? '',
                'description_translated' => $enParts[$i] ?? $cnParts[$i] ?? '',
            ];
        }
        $scope = strtolower((string) ($row['product_dimensions_scope'] ?? 'carton'));
        if (!in_array($scope, ['piece', 'carton'], true)) {
            $scope = 'carton';
        }
        $multiplier = $scope === 'carton'
            ? (float) ($row['cartons'] ?? 0)
            : (float) (($row['quantity'] ?? 0) ?: 0);
        $sections[$key]['items'][] = [
            'item_no' => $row['item_no'] ?: null,
            'description_entries' => $entries,
            'hs_code' => $row['hs_code'] ?? $row['product_hs_code'] ?? null,
            'pieces_per_carton' => $row['qty_per_carton'] ?? null,
            'cartons' => $row['cartons'] ?? null,
            'quantity' => $row['quantity'] ?? null,
            'unit_price' => $row['unit_price'] !== null ? (float) $row['unit_price'] : null,
            'sell_price' => isset($row['sell_price']) && $row['sell_price'] !== null ? (float) $row['sell_price'] : null,
            'total_amount' => $row['total_amount'] !== null ? (float) $row['total_amount'] : 0,
            'cbm' => $multiplier > 0 ? round(((float) ($row['declared_cbm'] ?? 0)) / $multiplier, 6) : 0,
            'weight' => $multiplier > 0 ? round(((float) ($row['declared_weight'] ?? 0)) / $multiplier, 4) : 0,
            'dimensions_scope' => $scope,
        ];
        $sections[$key]['totals']['amount'] += (float) ($row['total_amount'] ?? 0);
        $sections[$key]['totals']['cbm'] += (float) ($row['declared_cbm'] ?? 0);
        $sections[$key]['totals']['weight'] += (float) ($row['declared_weight'] ?? 0);
    }
    $sections = array_values($sections);
    $grandAmount = array_sum(array_map(static fn($section) => (float) ($section['totals']['amount'] ?? 0), $sections));
    $grandCbm = array_sum(array_map(static fn($section) => (float) ($section['totals']['cbm'] ?? 0), $sections));
    $grandWeight = array_sum(array_map(static fn($section) => (float) ($section['totals']['weight'] ?? 0), $sections));
    $title = 'Draft Order #' . $orderId;
    $subtitle = 'Customer: ' . ($order['customer_name'] ?? '—') . ' | Status: ' . ($order['status'] ?? '—') . ' | Expected Ready: ' . ($order['expected_ready_date'] ?? '—');
} else {
    if ($legacyId <= 0) {
        header('Location: procurement_drafts.php');
        exit;
    }

    $stmt = $pdo->prepare("SELECT pd.*, s.name as supplier_name FROM procurement_drafts pd LEFT JOIN suppliers s ON pd.supplier_id = s.id WHERE pd.id = ?");
    $stmt->execute([$legacyId]);
    $draft = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$draft) {
        header('Location: procurement_drafts.php');
        exit;
    }

    $itemsStmt = $pdo->prepare("SELECT pdi.*, p.description_cn, p.description_en, p.cbm, p.weight, p.unit_price, p.hs_code FROM procurement_draft_items pdi LEFT JOIN products p ON pdi.product_id = p.id WHERE pdi.draft_id = ? ORDER BY pdi.sort_order, pdi.id");
    $itemsStmt->execute([$legacyId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    $sections = [[
        'supplier_name' => $draft['supplier_name'] ?? '—',
        'items' => array_map(static function ($item) {
            $desc = trim((string) ($item['description_cn'] ?? $item['description_en'] ?? $item['notes'] ?? ''));
            $qty = (float) ($item['quantity'] ?? 0);
            return [
                'item_no' => null,
                'description_entries' => [[
                    'description_text' => $desc,
                    'description_translated' => $desc,
                ]],
                'hs_code' => $item['hs_code'] ?? null,
                'pieces_per_carton' => $qty ?: null,
                'cartons' => 1,
                'quantity' => $qty ?: null,
                'unit_price' => isset($item['unit_price']) ? (float) $item['unit_price'] : null,
                'sell_price' => isset($item['unit_price']) ? (float) $item['unit_price'] : null,
                'total_amount' => (float) (($item['unit_price'] ?? 0) * ($item['quantity'] ?? 0)),
                'cbm' => isset($item['cbm']) ? (float) $item['cbm'] : 0,
                'weight' => isset($item['weight']) ? (float) $item['weight'] : 0,
                'dimensions_scope' => 'piece',
            ];
        }, $items),
        'totals' => [
            'amount' => array_sum(array_map(static fn($item) => (float) (($item['unit_price'] ?? 0) * ($item['quantity'] ?? 0)), $items)),
            'cbm' => array_sum(array_map(static fn($item) => (float) (($item['cbm'] ?? 0) * ($item['quantity'] ?? 0)), $items)),
            'weight' => array_sum(array_map(static fn($item) => (float) (($item['weight'] ?? 0) * ($item['quantity'] ?? 0)), $items)),
        ],
    ]];
    $grandAmount = (float) ($sections[0]['totals']['amount'] ?? 0);
    $grandCbm = (float) ($sections[0]['totals']['cbm'] ?? 0);
    $grandWeight = (float) ($sections[0]['totals']['weight'] ?? 0);
    $title = $draft['name'] ?? 'Legacy Procurement Draft';
    $subtitle = 'Legacy procurement draft | Status: ' . ($draft['status'] ?? '—');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($title) ?> | CLMS</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    @media print {
      .no-print {
        display: none !important;
      }

      body {
        padding: 0;
      }
    }

    @media screen {
      .print-container {
        max-width: 1100px;
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

    <h1 class="h4 mb-2"><?= htmlspecialchars($title) ?></h1>
    <p class="text-muted small mb-3"><?= htmlspecialchars($subtitle) ?></p>

    <table class="table table-bordered table-sm">
      <thead class="table-light">
        <tr>
          <th>#</th>
          <th>Item No</th>
          <th>Product / Names</th>
          <th>HS Code</th>
          <th>Pieces/Carton</th>
          <th>Cartons</th>
          <th>Quantity</th>
          <th>Factory Price</th>
          <th>Customer Price</th>
          <th>Total Amount</th>
          <th>Total CBM</th>
          <th>Total Weight</th>
        </tr>
      </thead>
      <tbody>
        <?= printDraftEntryRows($sections) ?>
        <tr class="table-dark">
          <td colspan="9"><strong>Grand total</strong></td>
          <td><strong><?= number_format($grandAmount, 2) ?></strong></td>
          <td><strong><?= number_format($grandCbm, 6) ?></strong></td>
          <td><strong><?= number_format($grandWeight, 4) ?></strong></td>
        </tr>
      </tbody>
    </table>
  </div>
</body>

</html>
