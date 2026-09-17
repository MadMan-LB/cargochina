<?php
// Run against an isolated test database with the local HTTP fixture listening.
require_once dirname(__DIR__) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/backend/services/TrackingPushService.php';
if (strpos($_ENV['DB_NAME'] ?? '', 'test') === false) throw new RuntimeException('Isolated test DB required');
class OutcomeTrackingService extends TrackingPushService {
    public array $fileResults = [];
    protected function appendFileLog(int $draftId, array $payload, string $result, int $code = 0, $extra = ''): void {
        $this->fileResults[] = compact('result', 'code', 'extra');
    }
}
$pdo = getDb();
$pdo->beginTransaction();
$checks = 0;
function checkOutcome($ok, string $name): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    ++$checks;
    echo "PASS: $name\n";
}
function newDraft(PDO $pdo): int {
    $pdo->exec("INSERT INTO shipment_drafts (status) VALUES ('finalized')");
    return (int) $pdo->lastInsertId();
}
function testService(PDO $pdo, array $override = []): OutcomeTrackingService {
    $svc = new OutcomeTrackingService($pdo);
    $property = new ReflectionProperty(TrackingPushService::class, 'config');
    $property->setAccessible(true);
    $property->setValue($svc, array_merge([
        'tracking_push_enabled' => 1, 'tracking_push_dry_run' => 0,
        'tracking_api_base_url' => 'http://127.0.0.1:8093', 'tracking_api_path' => '/success',
        'tracking_api_token' => 'stub-token', 'tracking_api_retry_count' => 1,
        'tracking_api_timeout_sec' => 2, 'tracking_api_retry_backoff_ms' => 0,
    ], $override));
    return $svc;
}
function expectFailure(TrackingPushService $svc, int $id): void {
    try { $svc->push($id); } catch (RuntimeException $e) { return; }
    throw new RuntimeException('Push unexpectedly succeeded');
}
try {
    $existing = $pdo->query("SELECT id,status,container_id FROM shipment_drafts WHERE status='finalized' ORDER BY id")->fetchAll();
    $id = newDraft($pdo);
    $svc = testService($pdo, ['tracking_push_enabled'=>0]);
    $result = $svc->push($id);
    checkOutcome($result['success'] === false && $result['status'] === 'disabled', 'Disabled is not success');
    checkOutcome($svc->getPushStatus($id)['attempt_count'] === 0, 'Disabled makes no HTTP attempt');
    $dryId = newDraft($pdo);
    $dry = testService($pdo, ['tracking_push_dry_run'=>1]);
    checkOutcome($dry->push($dryId)['success'] === false && $dry->getPushStatus($dryId)['status'] === 'dry_run', 'Dry run logs without Pushed');
    $missingId = newDraft($pdo);
    $missing = testService($pdo, ['tracking_api_base_url'=>'']);
    expectFailure($missing, $missingId);
    checkOutcome($missing->getPushStatus($missingId)['status'] === 'failed' && $missing->getPushStatus($missingId)['attempt_count'] === 0, 'Missing endpoint fails before network');
    $failureId = newDraft($pdo);
    $failure = testService($pdo, ['tracking_api_path'=>'/fail']);
    expectFailure($failure, $failureId);
    $log = $failure->getPushStatus($failureId);
    checkOutcome($log['status'] === 'failed' && (int)$log['response_code'] === 503 && (int)$log['attempt_count'] === 2, '503 retries and remains Failed');
    checkOutcome(!empty($log['request_payload']) && !empty($log['response_body']) && !empty($log['last_error']) && $failure->fileResults[0]['result'] === 'failed', 'Exhausted failure logs request, response, error and file result');
    $success = testService($pdo);
    $retried = $success->push($failureId);
    $log = $success->getPushStatus($failureId);
    checkOutcome($retried['success'] === true && $log['status'] === 'success' && (int)$log['attempt_count'] === 3 && $log['last_error'] === null, 'Manual retry becomes Pushed only after service acceptance');
    checkOutcome($log['external_id'] === 'stub-accepted-'.$failureId && (int)$log['response_code'] === 200, 'Accepted external ID and HTTP response persisted');
    $count = $log['attempt_count'];
    checkOutcome($success->push($failureId)['success'] === true && $success->getPushStatus($failureId)['attempt_count'] === $count, 'Accepted push is idempotent and not resent');
    $rejectId = newDraft($pdo);
    $reject = testService($pdo, ['tracking_api_path'=>'/reject']);
    expectFailure($reject, $rejectId);
    checkOutcome($reject->getPushStatus($rejectId)['status'] === 'failed' && (int)$reject->getPushStatus($rejectId)['response_code'] === 200, 'HTTP 200 application rejection is not Pushed');
    $authId = newDraft($pdo);
    $auth = testService($pdo, ['tracking_api_path'=>'/unauthorized']);
    expectFailure($auth, $authId);
    checkOutcome((int)$auth->getPushStatus($authId)['attempt_count'] === 1 && $auth->getPushStatus($authId)['status'] === 'failed', '401 does not retry invalid credentials');
    $success->push($id);
    checkOutcome($success->getPushStatus($id)['status'] === 'success', 'Previously disabled push can be retried');
    $unchanged = $pdo->query("SELECT id,status,container_id FROM shipment_drafts WHERE status='finalized' AND id NOT IN ($id,$dryId,$missingId,$failureId,$rejectId,$authId) ORDER BY id")->fetchAll();
    checkOutcome($existing === $unchanged, 'Existing finalized shipment data unchanged');
    echo "$checks checks passed; all test database changes rolled back.\n";
} finally {
    $pdo->rollBack();
}
