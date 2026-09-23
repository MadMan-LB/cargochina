<?php

require_once __DIR__ . '/ShipmentAssignmentService.php';

/** Transport milestones are separate from irreversible cargo finalization. */
final class CargoStateService
{
    private const CONTAINER_TRANSITIONS = [
        'planning' => ['to_go'],
        'to_go' => ['planning', 'on_route'],
        'on_route' => ['arrived'],
        'arrived' => ['available'],
        'available' => [],
    ];

    public static function nextContainerStates(PDO $pdo, array $container): array
    {
        $next = self::CONTAINER_TRANSITIONS[$container['status']] ?? [];
        $stmt = $pdo->prepare("SELECT id,status FROM shipment_drafts WHERE deleted_at IS NULL AND container_id=?" . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $stmt->execute([$container['id']]);
        $drafts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $finalized = count(array_filter($drafts, static fn($d) => $d['status'] === 'finalized'));
        return array_values(array_filter($next, static function ($status) use ($drafts, $finalized) {
            if ($status === 'planning' && $finalized > 0) return false;
            if ($status === 'on_route' && (!$drafts || $finalized !== count($drafts))) return false;
            return true;
        }));
    }

    public static function assertContainerUpdate(PDO $pdo, array $container, array $input): void
    {
        if (isset($input['status']) && $input['status'] !== $container['status']
            && !in_array($input['status'], self::nextContainerStates($pdo, $container), true)) {
            throw new ShipmentAssignmentException('Invalid container transition; departure requires all shipment drafts finalized');
        }
        if (!ShipmentAssignmentService::containerIsOpen($pdo, $container, true)) {
            foreach (['code', 'max_cbm', 'max_weight', 'destination_country', 'destination', 'vessel_name'] as $field) {
                if (!array_key_exists($field, $input)) continue;
                $same = in_array($field, ['max_cbm', 'max_weight'], true)
                    ? is_numeric($input[$field]) && (float)$input[$field] === (float)$container[$field]
                    : trim((string)$input[$field]) === trim((string)($container[$field] ?? ''));
                if (!$same) throw new ShipmentAssignmentException('Finalized or departed container cargo details are locked');
            }
        }
    }

    public static function assertDraftMutable(array $draft): void
    {
        if (($draft['status'] ?? '') !== 'draft') throw new ShipmentAssignmentException('Finalized shipment drafts are locked');
    }
}
