<?php

/**
 * SuperAdmin-only training data reset helper.
 *
 * This intentionally deletes business/training rows with DELETE statements
 * instead of dropping tables, so schema, migrations, roles, config, and core
 * settings stay intact.
 */
class TrainingDataResetService
{
    private PDO $pdo;
    private array $tableExistsCache = [];
    private array $columnExistsCache = [];

    private const GROUPS = [
        'draft_orders' => [
            'label' => 'Draft Orders / Procurement Drafts',
            'description' => 'Deletes Draft an Order procurement records, legacy procurement drafts, and order templates.',
        ],
        'orders' => [
            'label' => 'Orders & Receiving',
            'description' => 'Deletes regular orders, order items, attachments, confirmations, warehouse receipts, and receipt photos.',
        ],
        'containers' => [
            'label' => 'Containers & Shipment Drafts',
            'description' => 'Deletes shipment drafts, container assignments, container records, and arrival reminders.',
        ],
        'customers' => [
            'label' => 'Customers',
            'description' => 'Deletes customers and customer-only child data. Linked orders are removed first to satisfy database rules.',
        ],
        'suppliers' => [
            'label' => 'Suppliers',
            'description' => 'Deletes suppliers and supplier-only child data. Linked orders are removed first to satisfy database rules.',
        ],
        'products' => [
            'label' => 'Products',
            'description' => 'Deletes products and product description entries. Existing item rows are left with product references cleared by MySQL.',
        ],
        'financials' => [
            'label' => 'Financial Records',
            'description' => 'Deletes balances, customer deposits, and supplier payment records.',
        ],
        'expenses' => [
            'label' => 'Expenses',
            'description' => 'Deletes expense rows but keeps expense categories.',
        ],
        'notifications' => [
            'label' => 'Notifications & Internal Messages',
            'description' => 'Deletes notifications, delivery logs, internal messages, and container arrival notifications.',
        ],
        'users' => [
            'label' => 'Users Except Super Admin',
            'description' => 'Deletes non-SuperAdmin users only. The current user, SuperAdmin users, and admin@salameh.com are always protected.',
        ],
        'logs' => [
            'label' => 'Import / Audit / Tracking Logs',
            'description' => 'Deletes import history, tracking push logs, and audit log rows. A new audit row is written after the reset.',
        ],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function groups(): array
    {
        return self::GROUPS;
    }

    public function preview(int $currentUserId): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => $meta) {
            $groups[] = [
                'key' => $key,
                'label' => $meta['label'],
                'description' => $meta['description'],
                'count' => $this->countGroup($key, $currentUserId),
            ];
        }
        return $groups;
    }

    public function reset(array $requestedGroups, int $currentUserId): array
    {
        $groups = array_values(array_unique(array_filter(array_map(
            static fn($group): string => is_string($group) ? trim($group) : '',
            $requestedGroups
        ))));

        $unknown = array_values(array_diff($groups, array_keys(self::GROUPS)));
        if ($unknown) {
            throw new InvalidArgumentException('Unknown reset group: ' . implode(', ', $unknown));
        }
        if (!$groups) {
            throw new InvalidArgumentException('Select at least one reset group.');
        }

        $summary = [
            'groups' => $groups,
            'deleted' => [],
            'protected_users' => $this->protectedUserSummary($currentUserId),
        ];

        $this->pdo->beginTransaction();
        try {
            if (in_array('customers', $groups, true)) {
                $this->deleteOrdersWhere('customer_id IN (SELECT id FROM customers)', [], $summary, 'customer linked orders');
                $this->deleteWhere('balance_transactions', "party_type = 'customer' AND party_id IN (SELECT id FROM customers)", [], $summary, 'customer balance transactions');
            }
            if (in_array('suppliers', $groups, true)) {
                $this->deleteOrdersWhere('supplier_id IN (SELECT id FROM suppliers)', [], $summary, 'supplier linked orders');
                $this->deleteWhere('balance_transactions', "party_type = 'supplier' AND party_id IN (SELECT id FROM suppliers)", [], $summary, 'supplier balance transactions');
            }
            if (in_array('orders', $groups, true)) {
                $this->deleteOrdersWhere("(order_type IS NULL OR order_type <> 'draft_procurement')", [], $summary, 'orders');
            }
            if (in_array('draft_orders', $groups, true)) {
                $this->deleteOrdersWhere("order_type = 'draft_procurement'", [], $summary, 'draft procurement orders');
                $this->deleteTable('procurement_drafts', $summary);
                $this->deleteTable('order_templates', $summary);
            }
            if (in_array('containers', $groups, true)) {
                $this->resetShipmentDraftOrderStatuses();
                $this->deleteTable('shipment_drafts', $summary);
                $this->deleteTable('containers', $summary);
            }
            if (in_array('financials', $groups, true)) {
                $this->deleteTable('balance_transactions', $summary);
                $this->deleteTable('customer_deposits', $summary);
                $this->deleteTable('supplier_payments', $summary);
            }
            if (in_array('expenses', $groups, true)) {
                $this->deleteTable('expenses', $summary);
            }
            if (in_array('notifications', $groups, true)) {
                $this->deleteTable('notification_delivery_log', $summary);
                $this->deleteTable('notifications', $summary);
                $this->deleteTable('internal_messages', $summary);
                $this->deleteTable('container_arrival_notifications', $summary);
            }
            if (in_array('customers', $groups, true)) {
                $this->deleteTable('customers', $summary);
            }
            if (in_array('suppliers', $groups, true)) {
                $this->deleteTable('suppliers', $summary);
            }
            if (in_array('products', $groups, true)) {
                $this->deleteTable('products', $summary);
            }
            if (in_array('logs', $groups, true)) {
                $this->deleteTable('receiving_excel_imports', $summary);
                $this->deleteTable('tracking_push_log', $summary);
                $this->deleteTable('audit_log', $summary);
            }
            if (in_array('users', $groups, true)) {
                $this->deleteNonProtectedUsers($currentUserId, $summary);
            }

            $summary['total_deleted'] = array_sum($summary['deleted']);
            $summary['completed_at'] = date('c');
            $this->writeAuditLog($summary, $currentUserId);
            $this->pdo->commit();
            return $summary;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function countGroup(string $key, int $currentUserId): int
    {
        if ($key === 'draft_orders') {
            return $this->countWhere('orders', "order_type = 'draft_procurement'")
                + $this->countTable('procurement_drafts')
                + $this->countTable('order_templates');
        }
        if ($key === 'orders') {
            return $this->countWhere('orders', "(order_type IS NULL OR order_type <> 'draft_procurement')");
        }
        if ($key === 'containers') {
            return $this->countTable('containers') + $this->countTable('shipment_drafts');
        }
        if ($key === 'customers') {
            return $this->countTable('customers');
        }
        if ($key === 'suppliers') {
            return $this->countTable('suppliers');
        }
        if ($key === 'products') {
            return $this->countTable('products');
        }
        if ($key === 'financials') {
            return $this->countTable('balance_transactions')
                + $this->countTable('customer_deposits')
                + $this->countTable('supplier_payments');
        }
        if ($key === 'expenses') {
            return $this->countTable('expenses');
        }
        if ($key === 'notifications') {
            return $this->countTable('notifications')
                + $this->countTable('notification_delivery_log')
                + $this->countTable('internal_messages')
                + $this->countTable('container_arrival_notifications');
        }
        if ($key === 'users') {
            return $this->countDeletableUsers($currentUserId);
        }
        if ($key === 'logs') {
            return $this->countTable('receiving_excel_imports')
                + $this->countTable('tracking_push_log')
                + $this->countTable('audit_log');
        }
        return 0;
    }

    private function countTable(string $table): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        return (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
    }

    private function countWhere(string $table, string $where, array $params = []): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE {$where}");
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    private function deleteTable(string $table, array &$summary, string $label = ''): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        $count = $this->countTable($table);
        if ($count <= 0) {
            return 0;
        }
        $this->pdo->exec("DELETE FROM `{$table}`");
        $this->addDeleted($summary, $label ?: $table, $count);
        return $count;
    }

    private function deleteWhere(string $table, string $where, array $params, array &$summary, string $label): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }
        $count = $this->countWhere($table, $where, $params);
        if ($count <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare("DELETE FROM `{$table}` WHERE {$where}");
        $stmt->execute($params);
        $this->addDeleted($summary, $label, $count);
        return $count;
    }

    private function deleteOrdersWhere(string $where, array $params, array &$summary, string $label): int
    {
        if (!$this->tableExists('orders')) {
            return 0;
        }
        $count = $this->countWhere('orders', $where, $params);
        if ($count <= 0) {
            return 0;
        }
        $stmt = $this->pdo->prepare("DELETE FROM orders WHERE {$where}");
        $stmt->execute($params);
        $this->addDeleted($summary, $label, $count);
        return $count;
    }

    private function resetShipmentDraftOrderStatuses(): void
    {
        if (!$this->tableExists('orders') || !$this->tableExists('shipment_draft_orders')) {
            return;
        }
        $this->pdo->exec(
            "UPDATE orders o
             INNER JOIN shipment_draft_orders sdo ON sdo.order_id = o.id
             SET o.status = 'ReadyForConsolidation'
             WHERE o.status IN ('ConsolidatedIntoShipmentDraft','AssignedToContainer')"
        );
    }

    private function deleteNonProtectedUsers(int $currentUserId, array &$summary): int
    {
        if (!$this->tableExists('users')) {
            return 0;
        }
        $protected = $this->protectedUserIds($currentUserId);
        $params = $protected;
        $where = '';
        if ($protected) {
            $where = 'WHERE id NOT IN (' . implode(',', array_fill(0, count($protected), '?')) . ')';
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM users {$where}");
        $stmt->execute($params);
        $count = (int) $stmt->fetchColumn();
        if ($count <= 0) {
            return 0;
        }
        $delete = $this->pdo->prepare("DELETE FROM users {$where}");
        $delete->execute($params);
        $this->addDeleted($summary, 'users_except_super_admin', $count);
        return $count;
    }

    private function protectedUserIds(int $currentUserId): array
    {
        if (!$this->tableExists('users')) {
            return [$currentUserId];
        }
        $ids = [$currentUserId];
        if ($this->tableExists('roles') && $this->tableExists('user_roles')) {
            $stmt = $this->pdo->query(
                "SELECT DISTINCT u.id
                 FROM users u
                 INNER JOIN user_roles ur ON ur.user_id = u.id
                 INNER JOIN roles r ON r.id = ur.role_id
                 WHERE r.code = 'SuperAdmin'"
            );
            $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        if ($this->columnExists('users', 'email')) {
            $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute(['admin@salameh.com']);
            $ids = array_merge($ids, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
        }
        return array_values(array_unique(array_filter($ids, static fn($id): bool => (int) $id > 0)));
    }

    private function protectedUserSummary(int $currentUserId): array
    {
        $ids = $this->protectedUserIds($currentUserId);
        if (!$ids || !$this->tableExists('users')) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT id, email, full_name FROM users WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id'
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function countDeletableUsers(int $currentUserId): int
    {
        if (!$this->tableExists('users')) {
            return 0;
        }
        $protected = $this->protectedUserIds($currentUserId);
        if (!$protected) {
            return $this->countTable('users');
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM users WHERE id NOT IN (' . implode(',', array_fill(0, count($protected), '?')) . ')'
        );
        $stmt->execute($protected);
        return (int) $stmt->fetchColumn();
    }

    private function addDeleted(array &$summary, string $key, int $count): void
    {
        if ($count <= 0) {
            return;
        }
        $summary['deleted'][$key] = ($summary['deleted'][$key] ?? 0) + $count;
    }

    private function writeAuditLog(array $summary, int $currentUserId): void
    {
        if (!$this->tableExists('audit_log')) {
            return;
        }
        $stmt = $this->pdo->prepare(
            "INSERT INTO audit_log (entity_type, entity_id, action, new_value, user_id)
             VALUES ('system', 0, 'training_data_reset', ?, ?)"
        );
        $stmt->execute([json_encode($summary, JSON_UNESCAPED_UNICODE), $currentUserId]);
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableExistsCache)) {
            return $this->tableExistsCache[$table];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $stmt->execute([$table]);
        return $this->tableExistsCache[$table] = ((int) $stmt->fetchColumn() > 0);
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, $this->columnExistsCache)) {
            return $this->columnExistsCache[$key];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
        );
        $stmt->execute([$table, $column]);
        return $this->columnExistsCache[$key] = ((int) $stmt->fetchColumn() > 0);
    }
}
