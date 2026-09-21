<?php

/**
 * Order State Machine - enforces lifecycle transitions
 */

class OrderStateService
{
    private const TRANSITIONS = [
        'Draft' => ['Submitted'],
        'Submitted' => ['Approved'],
        'Approved' => ['InTransitToWarehouse', 'ReceivedAtWarehouse', 'Confirmed', 'ReadyForConsolidation'],
        'InTransitToWarehouse' => ['ReceivedAtWarehouse', 'Confirmed', 'ReadyForConsolidation'],
        'ReceivedAtWarehouse' => ['Confirmed', 'ReadyForConsolidation'],
        'AwaitingCustomerConfirmation' => ['Confirmed', 'ReadyForConsolidation', 'CustomerDeclined', 'CustomerDeclinedAfterAutoConfirm'],
        'CustomerDeclined' => ['Submitted'],
        'CustomerDeclinedAfterAutoConfirm' => ['Submitted'],
        'Confirmed' => ['ReadyForConsolidation', 'CustomerDeclinedAfterAutoConfirm', 'ConsolidatedIntoShipmentDraft', 'AssignedToContainer'],
        'ReadyForConsolidation' => ['ConsolidatedIntoShipmentDraft', 'AssignedToContainer'],
        'ConsolidatedIntoShipmentDraft' => ['AssignedToContainer', 'ReadyForConsolidation'],
        'AssignedToContainer' => ['ReadyForConsolidation', 'FinalizedAndPushedToTracking'],
        'FinalizedAndPushedToTracking' => [],
    ];

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public static function statuses(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function getAllowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public static function validateTransition(string $current, string $target): void
    {
        if (!self::canTransition($current, $target)) {
            throw new RuntimeException("Invalid transition: $current → $target. Allowed: " . implode(', ', self::getAllowedTransitions($current) ?: ['none']));
        }
    }
}
