<?php
// Local test service only: php -S 127.0.0.1:8093 tests/fixtures/tracking_api_stub.php
header('Content-Type: application/json');
$payload = json_decode(file_get_contents('php://input'), true);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($payload['header']['shipment_draft_id']) || empty($_SERVER['HTTP_IDEMPOTENCY_KEY'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid test request']);
    exit;
}
$mode = trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($mode === 'reject') {
    echo json_encode(['success' => false, 'message' => 'Shipment rejected by test tracking service']);
} elseif ($mode === 'fail') {
    http_response_code(503);
    echo json_encode(['error' => 'Temporarily unavailable']);
} elseif ($mode === 'unauthorized') {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid credentials']);
} else {
    echo json_encode(['success' => true, 'external_shipment_id' => 'stub-accepted-' . $payload['header']['shipment_draft_id']]);
}
