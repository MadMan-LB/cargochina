<?php
/** Exercise legacy paths on the local server and reject accidental modern SQL. */
class LegacyNoJsonPDO extends PDO
{
    public function __construct(private PDO $connection) {}
    private function checkSql(string $sql): void {
        if (preg_match('/\bJSON_[A-Z_]+\s*\(/i', $sql)) throw new RuntimeException('Unsupported MySQL 5.5 JSON function in SQL');
    }
    public function getAttribute(int $attribute): mixed { return $attribute === PDO::ATTR_SERVER_VERSION ? '5.5.62-log' : $this->connection->getAttribute($attribute); }
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->checkSql($query); return $this->connection->prepare($query,$options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false { $this->checkSql($query); return $fetchMode===null ? $this->connection->query($query) : $this->connection->query($query,$fetchMode,...$args); }
    public function quote(string $string, int $type = PDO::PARAM_STR): string|false { return $this->connection->quote($string,$type); }
    public function inTransaction(): bool { return $this->connection->inTransaction(); }
    public function lastInsertId(?string $name = null): string|false { return $this->connection->lastInsertId($name); }
}
