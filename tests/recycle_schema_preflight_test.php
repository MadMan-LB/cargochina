<?php
require_once dirname(__DIR__) . '/backend/services/ProductionReleasePreflightService.php';

// Simulate an incomplete deployment without altering any database or business record.
class RecoverySchemaStatement extends PDOStatement
{
    private array $params = [];
    private array $missing;
    public function __construct(array $missing) { $this->missing = $missing; }
    public function execute(?array $params = null): bool { $this->params = $params ?? []; return true; }
    public function fetchColumn(int $column = 0): mixed
    {
        return in_array(implode('.', $this->params), $this->missing, true) ? 0 : 1;
    }
}
class RecoverySchemaPDO extends PDO
{
    private array $missing;
    public function __construct(array $missing) { $this->missing = $missing; }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (strpos($query, 'information_schema.COLUMNS') === false) throw new Exception('Expected read-only column inspection');
        return new RecoverySchemaStatement($this->missing);
    }
}
$method = new ReflectionMethod(ProductionReleasePreflightService::class, 'recycleSchemaCheck');
$method->setAccessible(true);
$cases = [[], ['procurement_drafts.deleted_at'], ['shipment_drafts.deleted_at', 'suppliers.delete_generation']];
foreach ($cases as $missing) {
    $check = $method->invoke(new ProductionReleasePreflightService(new RecoverySchemaPDO($missing)));
    $expected = $missing ? ProductionReleasePreflightService::BLOCKED : ProductionReleasePreflightService::VERIFIED;
    if ($check['status'] !== $expected || $check['evidence']['missing_columns'] !== $missing) throw new Exception('Incomplete recovery schema was not identified accurately');
}
echo "PASS: complete and partially applied recovery schemas are distinguished without database writes\n";
