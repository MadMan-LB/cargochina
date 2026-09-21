<?php
/** Real SQL execution against connection-local disposable tables; no business writes. */
require_once dirname(__DIR__) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/backend/api/helpers.php';
class LegacySearchPDO extends PDO {
    public function __construct(private PDO $connection, private string $version) {}
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : $this->connection->getAttribute($attribute); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { return $this->connection->query($query); }
}
$pdo = getDb();
$checks = 0;
function legacyCheck(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
$pdo->exec('CREATE TEMPORARY TABLE order_items (id INT PRIMARY KEY, shared_carton_contents TEXT)');
try {
    $insert = $pdo->prepare('INSERT INTO order_items VALUES (?,?)');
    $values = ['A/22', '00125', 'HZ', 'HZ', '货物', '100%_full', 'Back\\Slash', ' Mixed Case ', '=1+1'];
    foreach ($values as $i => $value) $insert->execute([$i+1, json_encode([['item_no'=>'AUTO-'.$i, 'item_number'=>$value,'supplier_id'=>$i%2 ? '7' : 7]])]);
    $insert->execute([20, '{broken']); $insert->execute([21, null]);
    $insert->execute([22, json_encode([['description_en'=>'A/22', 'supplier_id'=>70]])]);
    foreach (['5.5.62-log','5.6.51','5.7.44','8.0.36','5.5.5-10.4.32-MariaDB'] as $version) {
        $connection = new LegacySearchPDO($pdo, $version);
        foreach (['A/22'=>[1], '00125'=>[2], 'HZ'=>[3,4], '货物'=>[5], '100%_full'=>[6], 'Back\\Slash'=>[7], 'mixed case'=>[8], '=1+1'=>[9], 'missing'=>[]] as $query => $expected) {
            $params = [];
            $predicate = clmsSharedCartonIdentifierSearch('oi2.shared_carton_contents','item_number',$connection,clmsSearchLike((string)$query),$params);
            if (!clmsSupportsJsonSearch($connection)) legacyCheck(!str_contains($predicate,'JSON_'), 'No unsupported SQL on '.$version);
            $stmt = $pdo->prepare("SELECT oi2.id FROM order_items oi2 WHERE ($predicate) ORDER BY oi2.id"); $stmt->execute($params);
            legacyCheck(array_map('intval',$stmt->fetchAll(PDO::FETCH_COLUMN))===$expected, 'Decoded literal search '.$version.' '.$query);
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM order_items oi2 WHERE oi2.id<>3 AND ($predicate)"); $stmt->execute($params);
            legacyCheck((int)$stmt->fetchColumn()===count(array_diff($expected,[3])), 'Outer access/filter predicate remains enforced');
        }
        $params=[]; $predicate=clmsSharedCartonIdentifierSearch('oi.shared_carton_contents','item_no',$connection,clmsSearchLike('AUTO-2'),$params);
        $stmt=$pdo->prepare("SELECT id FROM order_items oi WHERE $predicate LIMIT 1 OFFSET 0"); $stmt->execute($params);
        legacyCheck((int)$stmt->fetchColumn()===3,'Automatic identifier and pagination');
        $params=[]; $predicate=clmsSharedCartonSupplierPredicate('oi.shared_carton_contents',$connection,7,$params);
        $stmt=$pdo->prepare("SELECT COUNT(*) FROM order_items oi WHERE $predicate"); $stmt->execute($params);
        legacyCheck((int)$stmt->fetchColumn()===9,'Supplier numeric/string match, not 70');
    }
    echo "PASS: $checks legacy/native search, filter and pagination assertions\n";
} finally { $pdo->exec('DROP TEMPORARY TABLE order_items'); }
