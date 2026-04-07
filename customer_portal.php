<?php

/**
 * Customer Portal - one-time token access to view orders and customer follow-up actions
 * No auth required; token in URL validates access
 */
require_once __DIR__ . '/backend/config/database.php';

$token = trim($_GET['token'] ?? '');
$error = null;
$customer = null;
$orders = [];

function formatPortalDate(?string $value, string $format = 'Y-m-d'): string
{
  if (!$value) {
    return '—';
  }
  $ts = strtotime($value);
  return $ts ? date($format, $ts) : '—';
}

if ($token) {
  $pdo = getDb();
  $hash = hash('sha256', $token);
  $stmt = $pdo->prepare("SELECT cpt.*, c.name as customer_name, c.code as customer_code FROM customer_portal_tokens cpt JOIN customers c ON cpt.customer_id = c.id WHERE cpt.token_hash = ? AND cpt.expires_at > NOW() AND cpt.used_at IS NULL");
  $stmt->execute([$hash]);
  $row = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$row) {
    $error = 'Invalid or expired link. Please request a new link from Salameh Cargo.';
  } else {
    $customer = $row;
    $pdo->prepare("UPDATE customer_portal_tokens SET used_at = NOW() WHERE id = ?")->execute([$row['id']]);

    $latestReceiptSql = "SELECT wr1.* FROM warehouse_receipts wr1
      INNER JOIN (
        SELECT order_id, MAX(id) as max_id
        FROM warehouse_receipts
        GROUP BY order_id
      ) wr2 ON wr1.order_id = wr2.order_id AND wr1.id = wr2.max_id";

    $latestConfirmationSql = "SELECT cc1.* FROM customer_confirmations cc1
      INNER JOIN (
        SELECT order_id, MAX(id) as max_id
        FROM customer_confirmations
        GROUP BY order_id
      ) cc2 ON cc1.order_id = cc2.order_id AND cc1.id = cc2.max_id";

    $stmt = $pdo->prepare(
      "SELECT o.id, o.status, o.expected_ready_date, o.created_at, o.currency, o.confirmation_token,
              COALESCE(s.name, '') as supplier_name,
              wr.received_at as warehouse_received_at, wr.actual_cbm, wr.actual_weight, wr.actual_cartons, wr.receipt_condition,
              cc.confirmed_at, cc.declined_at, cc.decline_reason
       FROM orders o
       LEFT JOIN suppliers s ON o.supplier_id = s.id
       LEFT JOIN ($latestReceiptSql) wr ON wr.order_id = o.id
       LEFT JOIN ($latestConfirmationSql) cc ON cc.order_id = o.id
       WHERE o.customer_id = ? AND o.status <> 'Draft'
       ORDER BY o.created_at DESC, o.id DESC
       LIMIT 50"
    );
    $stmt->execute([$row['customer_id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
  }
}

$statusLabels = [
  'Approved' => 'Approved',
  'InTransitToWarehouse' => 'In Transit To Warehouse',
  'ReceivedAtWarehouse' => 'Received At Warehouse',
  'AwaitingCustomerConfirmation' => 'Legacy Awaiting Confirmation',
  'CustomerDeclined' => 'Declined',
  'CustomerDeclinedAfterAutoConfirm' => 'Declined After Auto-Confirm',
  'Confirmed' => 'Confirmed',
  'ReadyForConsolidation' => 'Ready For Consolidation',
  'ConsolidatedIntoShipmentDraft' => 'Added To Shipment Draft',
  'AssignedToContainer' => 'Assigned To Container',
  'FinalizedAndPushedToTracking' => 'Finalized',
  'Shipped' => 'Shipped',
];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Portal | Salameh Cargo</title>
    <link href="/cargochina/frontend/css/bootstrap.min.css" rel="stylesheet">
    <style>
    body {
        background: #f8fafc;
    }

    .portal-shell {
        max-width: 1080px;
    }

    .portal-hero {
        background: linear-gradient(135deg, #0f172a 0%, #1d4ed8 100%);
        color: #fff;
        border-radius: 1rem;
        box-shadow: 0 24px 60px rgba(15, 23, 42, 0.16);
    }

    .timeline-line {
        border-left: 2px solid #dbeafe;
        padding-left: 1rem;
        margin-left: .4rem;
    }

    .timeline-dot {
        width: .75rem;
        height: .75rem;
        background: #2563eb;
        border-radius: 50%;
        display: inline-block;
        margin-left: -.45rem;
        margin-right: .55rem;
        border: 2px solid #fff;
        box-shadow: 0 0 0 2px #bfdbfe;
    }

    .order-card {
        border: 1px solid #e2e8f0;
        border-radius: 1rem;
        box-shadow: 0 10px 30px rgba(15, 23, 42, 0.06);
    }
    </style>
</head>

<body>
    <div class="container py-5 portal-shell">
        <?php if (!$token): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <h2>Customer Portal</h2>
                <p class="text-muted mb-0">Please use the secure link sent to you by Salameh Cargo.</p>
            </div>
        </div>
        <?php elseif ($error): ?>
        <div class="card shadow-sm border-warning">
            <div class="card-body text-center py-5">
                <h2>Link Expired or Invalid</h2>
                <p class="text-muted mb-0"><?= htmlspecialchars($error) ?></p>
            </div>
        </div>
        <?php else: ?>
        <section class="portal-hero p-4 p-md-5 mb-4">
            <div class="d-flex flex-column flex-md-row justify-content-between gap-3 align-items-md-end">
                <div>
                    <p class="text-uppercase small mb-2 opacity-75">Secure Customer Portal</p>
                    <h1 class="h3 mb-2"><?= htmlspecialchars($customer['customer_name']) ?></h1>
                    <p class="mb-0 opacity-75">Code: <?= htmlspecialchars($customer['customer_code']) ?>. This one-time
                        link has now been activated for this visit.</p>
                </div>
                <div class="text-md-end">
                    <div class="small opacity-75">Orders visible</div>
                    <div class="display-6 fw-semibold mb-0"><?= count($orders) ?></div>
                </div>
            </div>
        </section>

        <?php if (empty($orders)): ?>
        <div class="card order-card">
            <div class="card-body py-5 text-center text-muted">
                No orders are currently available in the portal.
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4">
            <?php foreach ($orders as $order): ?>
            <?php
          $pendingReview = trim((string) ($order['confirmation_token'] ?? '')) !== '' && empty($order['declined_at']) && empty($order['confirmed_at']);
          switch ($order['status']) {
            case 'CustomerDeclinedAfterAutoConfirm':
            case 'CustomerDeclined':
              $badgeClass = 'bg-danger';
              break;
            case 'AwaitingCustomerConfirmation':
              $badgeClass = 'bg-secondary';
              break;
            case 'Confirmed':
            case 'ReadyForConsolidation':
            case 'AssignedToContainer':
            case 'FinalizedAndPushedToTracking':
            case 'Shipped':
              $badgeClass = 'bg-success';
              break;
            default:
              $badgeClass = 'bg-primary';
          }
          if ($pendingReview) {
            $badgeClass = 'bg-warning text-dark';
          }
          ?>
            <div class="col-12">
                <div class="card order-card">
                    <div class="card-body p-4">
                        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                            <div>
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <h2 class="h5 mb-0">Order #<?= (int) $order['id'] ?></h2>
                                    <span
                                        class="badge <?= $badgeClass ?>"><?= htmlspecialchars($pendingReview ? 'Awaiting Your Review' : ($statusLabels[$order['status']] ?? $order['status'])) ?></span>
                                </div>
                                <div class="text-muted small">Supplier:
                                    <?= htmlspecialchars($order['supplier_name'] ?: 'Multiple / not specified') ?></div>
                            </div>
                            <div class="row g-2 text-sm-end">
                                <div class="col-auto">
                                    <div class="small text-muted">Created</div>
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars(formatPortalDate($order['created_at'])) ?></div>
                                </div>
                                <div class="col-auto">
                                    <div class="small text-muted">Expected Ready</div>
                                    <div class="fw-semibold">
                                        <?= htmlspecialchars(formatPortalDate($order['expected_ready_date'])) ?></div>
                                </div>
                                <div class="col-auto">
                                    <div class="small text-muted">Currency</div>
                                    <div class="fw-semibold"><?= htmlspecialchars($order['currency'] ?: '—') ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="row g-4">
                            <div class="col-12 col-lg-7">
                                <h3 class="h6 mb-3">Timeline</h3>
                                <div class="timeline-line">
                                    <div class="mb-3">
                                        <span class="timeline-dot"></span>
                                        <strong>Order created</strong>
                                        <div class="text-muted small ms-4">
                                            <?= htmlspecialchars(formatPortalDate($order['created_at'], 'Y-m-d H:i')) ?>
                                        </div>
                                    </div>
                                    <div class="mb-3">
                                        <span class="timeline-dot"></span>
                                        <strong>Expected ready date</strong>
                                        <div class="text-muted small ms-4">
                                            <?= htmlspecialchars(formatPortalDate($order['expected_ready_date'])) ?>
                                        </div>
                                    </div>
                                    <?php if (!empty($order['warehouse_received_at'])): ?>
                                    <div class="mb-3">
                                        <span class="timeline-dot"></span>
                                        <strong>Warehouse receipt recorded</strong>
                                        <div class="text-muted small ms-4">
                                            <?= htmlspecialchars(formatPortalDate($order['warehouse_received_at'], 'Y-m-d H:i')) ?>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['declined_at'])): ?>
                                    <div class="mb-0">
                                        <span class="timeline-dot"></span>
                                        <strong>Customer declined</strong>
                                        <div class="text-muted small ms-4">
                                            <?= htmlspecialchars(formatPortalDate($order['declined_at'], 'Y-m-d H:i')) ?>
                                        </div>
                                        <?php if (!empty($order['decline_reason'])): ?>
                                        <div class="small ms-4 text-danger">Reason:
                                            <?= htmlspecialchars($order['decline_reason']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <?php elseif (!empty($order['confirmed_at'])): ?>
                                    <div class="mb-0">
                                        <span class="timeline-dot"></span>
                                        <strong>Customer confirmed</strong>
                                        <div class="text-muted small ms-4">
                                            <?= htmlspecialchars(formatPortalDate($order['confirmed_at'], 'Y-m-d H:i')) ?>
                                        </div>
                                    </div>
                                    <?php elseif ($pendingReview): ?>
                                    <div class="mb-0">
                                        <span class="timeline-dot"></span>
                                        <strong>Waiting for your review</strong>
                                        <div class="text-muted small ms-4">The receipt is already auto-confirmed into stock. Please review the warehouse measurements below.</div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="col-12 col-lg-5">
                                <h3 class="h6 mb-3">Current Receipt Details</h3>
                                <div class="row g-2 mb-3">
                                    <div class="col-4">
                                        <div class="border rounded-3 p-3 bg-light h-100">
                                            <div class="text-muted small">Actual CBM</div>
                                            <div class="fw-semibold">
                                                <?= htmlspecialchars($order['actual_cbm'] !== null ? number_format((float) $order['actual_cbm'], 4) : '—') ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border rounded-3 p-3 bg-light h-100">
                                            <div class="text-muted small">Actual Weight</div>
                                            <div class="fw-semibold">
                                                <?= htmlspecialchars($order['actual_weight'] !== null ? number_format((float) $order['actual_weight'], 2) . ' kg' : '—') ?>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="border rounded-3 p-3 bg-light h-100">
                                            <div class="text-muted small">Cartons</div>
                                            <div class="fw-semibold">
                                                <?= htmlspecialchars($order['actual_cartons'] !== null ? (string) $order['actual_cartons'] : '—') ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="small text-muted mb-3">Condition:
                                    <?= htmlspecialchars($order['receipt_condition'] ?: '—') ?></div>

                                <?php if ($pendingReview): ?>
                                <div class="alert alert-warning py-2 small mb-3">
                                    This order is already auto-confirmed into stock. Please review the warehouse measurements and either accept them or decline with a reason.
                                </div>
                                <a class="btn btn-primary w-100"
                                    href="/cargochina/confirm.php?token=<?= urlencode($order['confirmation_token']) ?>">Review
                                    Warehouse Receipt</a>
                                <?php elseif (!empty($order['confirmed_at'])): ?>
                                <div class="alert alert-success py-2 small mb-0">Your review has already been recorded and the order remains accepted in stock.
                                </div>
                                <?php elseif (!empty($order['declined_at'])): ?>
                                <div class="alert alert-danger py-2 small mb-0">This order was declined after auto-confirm and has been sent back to the team for recovery.</div>
                                <?php else: ?>
                                <div class="alert alert-light py-2 small mb-0">No customer action is currently required
                                    for this order.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</body>

</html>
