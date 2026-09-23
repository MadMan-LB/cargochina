<?php

/** Audit-backed idempotency lookup without database JSON functions (MySQL 5.5). */
final class AuditReplayLookupService
{
    public static function find(PDO $pdo, string $type, string $key, bool $lock = false): ?array
    {
        // Explicit escape avoids depending on NO_BACKSLASH_ESCAPES SQL mode.
        $escaped = strtr($key, ['!'=>'!!', '%'=>'!%', '_'=>'!_']);
        $stmt = $pdo->prepare("SELECT entity_id,user_id,new_value FROM audit_log
            WHERE entity_type=? AND action='create' AND new_value LIKE ? ESCAPE '!'
            ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute([$type, '%' . $escaped . '%']);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $payload = json_decode((string)$row['new_value'], true);
            if (is_array($payload) && ($payload['idempotency_key'] ?? null) === $key) return $row;
        }
        return null;
    }
}
