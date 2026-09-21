<?php

/**
 * Read-only production release preflight.
 *
 * Management-approved accounting policy is checked as implemented schema here;
 * production environment authorization and accounting signoff remain separate
 * release-gate evidence. Callers execute this in a read-only transaction.
 */
final class ProductionReleasePreflightService
{
    public const VERIFIED = 'VERIFIED';
    public const ENVIRONMENT = 'IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION';
    public const BLOCKED = 'BLOCKED';

    public function __construct(private PDO $pdo)
    {
    }

    public function run(array $context = []): array
    {
        $checks = [];
        $actualDatabase = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $expectedDatabase = trim((string) ($context['expected_database'] ?? ''));
        $checks[] = $this->check(
            'database_identity',
            $expectedDatabase !== '' && hash_equals($expectedDatabase, $actualDatabase) ? self::VERIFIED : self::BLOCKED,
            $expectedDatabase !== '' && hash_equals($expectedDatabase, $actualDatabase)
                ? 'Connected database matches the explicit expectation.'
                : 'Connected database does not match --expect-db.',
            ['expected' => $expectedDatabase, 'actual' => $actualDatabase]
        );

        $checks[] = $this->extensionCheck();
        $checks[] = $this->migrationCheck();
        $checks[] = $this->schemaCheck();
        $checks[] = $this->receivingIdempotencyCheck();
        $checks[] = $this->cargoIntegrityCheck();
        $checks[] = $this->seedPasswordCheck();
        $checks[] = $this->itemNumberCheck();
        $checks[] = $this->classificationCheck();
        $checks[] = $this->translationCheck($context);
        $checks[] = $this->accountingDecisionCheck();
        $checks[] = $this->backupCheck($context);
        $checks[] = $this->check('off_host_recovery',self::ENVIRONMENT,'OFF-HOST BACKUP EVIDENCE REQUIRED: encrypted upload, independent download, isolated restore, record verification and four-hour recovery objectives require deployment evidence.',[]);
        $checks[] = $this->check('owner_security_activation',self::ENVIRONMENT,'Verify named owner custody, restricted DB/OS identities, protected escrow keys, second factor, HTTPS and immutable reveal audit before activation.',[]);

        $counts = [self::VERIFIED => 0, self::ENVIRONMENT => 0, self::BLOCKED => 0];
        foreach ($checks as $check) {
            $counts[$check['status']] = ($counts[$check['status']] ?? 0) + 1;
        }

        return [
            'database' => $actualDatabase,
            'read_only' => true,
            'generated_at' => gmdate('c'),
            'status' => $counts[self::BLOCKED] > 0
                ? self::BLOCKED
                : ($counts[self::ENVIRONMENT] > 0 ? self::ENVIRONMENT : self::VERIFIED),
            'summary' => $counts,
            'checks' => $checks,
        ];
    }

    private function extensionCheck(): array
    {
        $required = ['bcmath', 'pdo_mysql', 'mbstring', 'fileinfo', 'zip', 'xmlreader', 'dom', 'SimpleXML'];
        $missing = array_values(array_filter($required, static fn(string $extension): bool => !extension_loaded($extension)));
        return $this->check(
            'php_extensions',
            $missing ? self::BLOCKED : self::VERIFIED,
            $missing ? 'Required PHP extensions are missing.' : 'Required PHP extensions are loaded.',
            ['required' => $required, 'missing' => $missing, 'php_version' => PHP_VERSION]
        );
    }

    private function migrationCheck(): array
    {
        $required = [
            '072_translation_queue_and_provenance.sql',
            '073_item_classification.sql',
            '074_draft_order_costs.sql',
            '075_receiving_idempotency.sql',
            '076_auth_login_throttling.sql',
            '077_approved_shipment_accounting_and_item_reservations.sql',
            '082_order_template_metrics.sql',
            '083_upload_asset_ownership.sql',
            '084_release_policy_controls.sql',
            '085_owner_controls.sql',
            '086_credential_recovery_requirement.sql',
        ];
        if (!$this->tableExists('_migrations')) {
            return $this->check('release_migrations', self::BLOCKED, 'Migration tracking table is missing.', ['required' => $required, 'missing' => $required]);
        }
        $placeholders = implode(',', array_fill(0, count($required), '?'));
        $stmt = $this->pdo->prepare("SELECT name FROM _migrations WHERE name IN ($placeholders)");
        $stmt->execute($required);
        $applied = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($required, $applied));
        return $this->check(
            'release_migrations',
            $missing ? self::ENVIRONMENT : self::VERIFIED,
            $missing ? 'Release migrations still require deployment verification.' : 'Release migrations are recorded.',
            ['required' => $required, 'applied' => array_values($applied), 'missing' => $missing]
        );
    }

    private function schemaCheck(): array
    {
        $tables = [
            'translation_jobs', 'translation_manual_corrections', 'bilingual_text_registry',
            'item_types', 'item_classifications', 'item_classification_history',
            'draft_order_cost_types', 'draft_order_costs', 'draft_order_cost_history',
            'shipment_financial_entries', 'item_number_reservations', 'item_number_references',
            'auth_login_attempts',
            'upload_assets',
            'historical_reconciliation_records','retention_holds','credential_escrow','owner_incidents','owner_incident_events',
        ];
        $missing = array_values(array_filter($tables, fn(string $table): bool => !$this->tableExists($table)));
        return $this->check(
            'release_schema',
            $missing ? self::BLOCKED : self::VERIFIED,
            $missing ? 'Required release tables are missing.' : 'Required release tables exist.',
            ['required_tables' => $tables, 'missing_tables' => $missing]
        );
    }

    private function receivingIdempotencyCheck(): array
    {
        $column = $this->columnExists('warehouse_receipts', 'receiving_operation_id');
        $index = $column && $this->indexExists('warehouse_receipts', 'uq_receiving_operation_id');
        $duplicateGroups = 0;
        if ($column) {
            $duplicateGroups = (int) $this->pdo->query(
                "SELECT COUNT(*) FROM (
                    SELECT receiving_operation_id FROM warehouse_receipts
                    WHERE receiving_operation_id IS NOT NULL AND receiving_operation_id<>''
                    GROUP BY receiving_operation_id HAVING COUNT(*)>1
                ) duplicate_keys"
            )->fetchColumn();
        }
        $verified = $column && $index && $duplicateGroups === 0;
        return $this->check(
            'receiving_idempotency',
            $verified ? self::VERIFIED : self::BLOCKED,
            $verified ? 'Receiving operation key and uniqueness protection are present.' : 'Receiving idempotency schema or data is unsafe.',
            ['column_present' => $column, 'unique_index_present' => $index, 'duplicate_key_groups' => $duplicateGroups]
        );
    }

    private function cargoIntegrityCheck(): array
    {
        foreach(['warehouse_receipts'=>['voided_at'],'warehouse_receipt_items'=>['actual_quantity'],'order_items'=>['dimensions_scope','order_cartons','order_qty_per_carton']] as $table=>$columns)foreach($columns as $column)if(!$this->columnExists($table,$column))return $this->check('cargo_integrity',self::BLOCKED,'Cargo reconciliation schema is incomplete.',['missing'=>$table.'.'.$column]);
        require_once __DIR__.'/ReceivingQuantityService.php';
        $quantity=ReceivingQuantityService::legacyQuantitySql();
        $unallocated=(int)$this->pdo->query('SELECT COUNT(*) FROM warehouse_receipts wr WHERE wr.voided_at IS NULL AND NOT EXISTS(SELECT 1 FROM warehouse_receipt_items wri WHERE wri.receipt_id=wr.id)')->fetchColumn();
        $excess=(int)$this->pdo->query("SELECT COUNT(*) FROM (SELECT oi.id FROM order_items oi JOIN warehouse_receipt_items wri ON wri.order_item_id=oi.id JOIN warehouse_receipts wr ON wr.id=wri.receipt_id AND wr.voided_at IS NULL GROUP BY oi.id,oi.quantity,oi.order_cartons,oi.cartons,oi.order_qty_per_carton,oi.qty_per_carton HAVING SUM($quantity)>CASE WHEN oi.quantity>0 THEN oi.quantity ELSE COALESCE(oi.order_cartons,oi.cartons,0)*COALESCE(oi.order_qty_per_carton,oi.qty_per_carton,0) END) over_received")->fetchColumn();
        $duplicates=(int)$this->pdo->query('SELECT COUNT(*) FROM (SELECT order_id FROM shipment_draft_orders GROUP BY order_id HAVING COUNT(*)>1) duplicate_memberships')->fetchColumn();
        $missingBasis=(int)$this->pdo->query("SELECT COUNT(*) FROM order_items WHERE dimensions_scope IS NULL AND product_id IS NOT NULL")->fetchColumn();
        $evidence=['unallocated_active_receipts'=>$unallocated,'over_received_items'=>$excess,'duplicate_assignments'=>$duplicates,'legacy_items_without_measurement_basis'=>$missingBasis];
        return $this->check('cargo_integrity',array_sum($evidence)>0?self::BLOCKED:self::VERIFIED,array_sum($evidence)>0?'Historical cargo requires verified reconciliation.':'Cargo allocation, assignment uniqueness and measurement snapshots are consistent.',$evidence);
    }

    private function seedPasswordCheck(): array
    {
        $seededActiveUsers = 0;
        if ($this->tableExists('users')) {
            $stmt = $this->pdo->query('SELECT password_hash FROM users WHERE is_active=1');
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $hash) {
                if (password_verify('password', (string) $hash)) {
                    $seededActiveUsers++;
                }
            }
        }
        return $this->check(
            'seed_password_rotation',
            $seededActiveUsers === 0 ? self::VERIFIED : self::BLOCKED,
            $seededActiveUsers === 0 ? 'No active user accepts the historical seed password.' : 'An active user still accepts the historical seed password.',
            ['active_seed_password_users' => $seededActiveUsers]
        );
    }

    private function itemNumberCheck(): array
    {
        if (!$this->tableExists('orders') || !$this->tableExists('order_items')) {
            return $this->check('legacy_item_numbers', self::BLOCKED, 'Order tables are missing.', []);
        }
        $blank = (int) $this->pdo->query("SELECT COUNT(*) FROM order_items WHERE TRIM(COALESCE(item_no,''))='' AND TRIM(COALESCE(shipping_code,''))=''")->fetchColumn();
        $globalGroups = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT TRIM(item_no) item_no FROM order_items
                WHERE TRIM(COALESCE(item_no,''))<>''
                GROUP BY TRIM(item_no) HAVING COUNT(*)>1
            ) duplicate_numbers"
        )->fetchColumn();
        $customerGroups = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM (
                SELECT o.customer_id,TRIM(oi.item_no) item_no
                FROM order_items oi JOIN orders o ON o.id=oi.order_id
                WHERE TRIM(COALESCE(oi.item_no,''))<>''
                GROUP BY o.customer_id,TRIM(oi.item_no) HAVING COUNT(*)>1
            ) duplicate_customer_numbers"
        )->fetchColumn();
        $verified = $blank === 0 && $customerGroups === 0;
        return $this->check(
            'legacy_item_numbers',
            $verified ? self::VERIFIED : self::BLOCKED,
            $verified ? 'Legacy data is compatible with a customer-scoped uniqueness constraint.' : 'Legacy item-number conflicts require an approved cleanup map before adding a constraint.',
            ['blank_item_numbers' => $blank, 'global_duplicate_groups' => $globalGroups, 'customer_duplicate_groups' => $customerGroups]
        );
    }

    private function classificationCheck(): array
    {
        if (!$this->tableExists('item_classifications')) {
            return $this->check('existing_item_classification', self::BLOCKED, 'Classification table is missing.', []);
        }
        $productMissing = $this->tableExists('products') ? (int) $this->pdo->query(
            "SELECT COUNT(*) FROM products p LEFT JOIN item_classifications ic
             ON ic.entity_type='product' AND ic.entity_id=p.id
             WHERE ic.entity_id IS NULL OR ic.item_type_code='unclassified' OR ic.is_confirmed=0"
        )->fetchColumn() : 0;
        $orderItemMissing = $this->tableExists('order_items') ? (int) $this->pdo->query(
            "SELECT COUNT(*) FROM order_items oi LEFT JOIN item_classifications ic
             ON ic.entity_type='order_item' AND ic.entity_id=oi.id
             WHERE ic.entity_id IS NULL OR ic.item_type_code='unclassified' OR ic.is_confirmed=0"
        )->fetchColumn() : 0;
        $needsReview = $productMissing + $orderItemMissing;
        return $this->check(
            'existing_item_classification',
            $needsReview === 0 ? self::VERIFIED : self::ENVIRONMENT,
            $needsReview === 0 ? 'Existing products and order items have confirmed classifications.' : 'Existing records remain in the explicit classification review queue.',
            ['products_requiring_review' => $productMissing, 'order_items_requiring_review' => $orderItemMissing]
        );
    }

    private function translationCheck(array $context): array
    {
        $provider = strtolower(trim((string) ($context['translation_provider'] ?? 'disabled')));
        $providerConfigured = $provider !== '' && $provider !== 'disabled';
        $pending = $this->tableExists('translation_jobs')
            ? (int) $this->pdo->query("SELECT COUNT(*) FROM translation_jobs WHERE status IN ('pending','failed')")->fetchColumn()
            : 0;
        return $this->check(
            'translation_provider',
            $providerConfigured ? self::ENVIRONMENT : self::VERIFIED,
            $providerConfigured
                ? 'Provider configuration exists but live network, quota, and output quality still require verification.'
                : 'Approved manual translation release mode: provider disabled; terminology review remains a separate gate.',
            ['provider' => $provider ?: 'disabled', 'pending_or_failed_jobs' => $pending, 'credentials_reported' => false]
        );
    }

    private function accountingDecisionCheck(): array
    {
        $required = [
            'ledger_table' => $this->tableExists('shipment_financial_entries'),
            'order_creation_key' => $this->columnExists('orders', 'creation_idempotency_key'),
            'order_lock_version' => $this->columnExists('orders', 'lock_version'),
            'cost_posting_status' => $this->columnExists('draft_order_costs', 'posting_status'),
            'cost_rate_lock' => $this->columnExists('draft_order_costs', 'rate_locked_at'),
            'receipt_fee_posting_status' => $this->columnExists('warehouse_receipt_fees', 'posting_status'),
        ];
        $uniqueLedgerKey = $required['ledger_table'] && $this->indexExists('shipment_financial_entries', 'uq_shipment_financial_idempotency');
        $uniqueSourceGeneration = $required['ledger_table'] && $this->indexExists('shipment_financial_entries', 'uq_shipment_financial_source_generation');
        $implemented = !in_array(false, $required, true) && $uniqueLedgerKey && $uniqueSourceGeneration;
        return $this->check(
            'accounting_policy',
            $implemented ? self::VERIFIED : self::BLOCKED,
            $implemented
                ? 'Management-approved shipment accounting schema and database idempotency controls are installed.'
                : 'Management-approved shipment accounting schema or database idempotency controls are incomplete.',
            [
                'management_decision_recorded' => true,
                'accountant_signoff_recorded' => false,
                'required_controls' => $required,
                'unique_ledger_idempotency' => $uniqueLedgerKey,
                'unique_source_generation' => $uniqueSourceGeneration,
                'decision_document' => 'docs/ACCOUNTING_DECISION_PACKET.md',
            ]
        );
    }

    private function backupCheck(array $context): array
    {
        $backupAt = trim((string) ($context['backup_verified_at'] ?? ''));
        $restoreAt = trim((string) ($context['restore_verified_at'] ?? ''));
        $backupFile = trim((string) ($context['backup_file'] ?? ''));
        $expectedHash = strtolower(trim((string) ($context['backup_sha256'] ?? '')));
        $fileExists = $backupFile !== '' && is_file($backupFile);
        $fileSize = $fileExists ? (int) filesize($backupFile) : 0;
        $actualHash = $fileExists && $fileSize > 0 ? strtolower((string) hash_file('sha256', $backupFile)) : '';
        $hashMatches = $expectedHash !== '' && preg_match('/^[a-f0-9]{64}$/', $expectedHash) && hash_equals($expectedHash, $actualHash);
        $valid = $this->validTimestamp($backupAt)
            && $this->validTimestamp($restoreAt)
            && $fileExists
            && $fileSize > 0
            && $hashMatches;
        return $this->check(
            'backup_and_restore_rehearsal',
            $valid ? self::VERIFIED : self::BLOCKED,
            $valid ? 'Backup artifact, checksum, and restore-rehearsal evidence are present.' : 'Backup artifact/checksum and restore-rehearsal evidence are incomplete.',
            [
                'backup_file_name' => $backupFile !== '' ? basename($backupFile) : null,
                'backup_file_exists' => $fileExists,
                'backup_file_size' => $fileSize,
                'sha256_matches' => (bool) $hashMatches,
                'backup_verified_at' => $backupAt ?: null,
                'restore_verified_at' => $restoreAt ?: null,
            ]
        );
    }

    private function validTimestamp(string $value): bool
    {
        return $value !== '' && strtotime($value) !== false;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->execute([$table]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $stmt->execute([$table, $column]);
        return (int) $stmt->fetchColumn() === 1;
    }

    private function indexExists(string $table, string $index): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
        $stmt->execute([$table, $index]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function check(string $code, string $status, string $message, array $evidence): array
    {
        return ['code' => $code, 'status' => $status, 'message' => $message, 'evidence' => $evidence];
    }
}
