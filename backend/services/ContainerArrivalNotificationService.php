<?php

require_once __DIR__.'/NotificationService.php';

/** The delivery intent and its deduplication marker must commit together. */
final class ContainerArrivalNotificationService
{
    public static function notifyDue(PDO $pdo, NotificationService $notifications, int $containerId, array $days, string $today): bool
    {
        if ($pdo->inTransaction()) throw new LogicException('Arrival scheduling owns its transaction');
        $pdo->beginTransaction();
        try {
            $s=$pdo->prepare('SELECT code,eta_date,status,DATEDIFF(eta_date,?) days_before FROM containers WHERE id=? FOR UPDATE');
            $s->execute([$today,$containerId]);$container=$s->fetch(PDO::FETCH_ASSOC);
            if (!$container || $container['eta_date']===null || in_array($container['status'],['arrived','available'],true)
                || !in_array((int)$container['days_before'],$days,true)) {$pdo->rollBack();return false;}
            $daysBefore=(int)$container['days_before'];
            $s=$pdo->prepare('SELECT id FROM container_arrival_notifications WHERE container_id=? AND days_before=? FOR UPDATE');
            $s->execute([$containerId,$daysBefore]);
            if ($s->fetchColumn()) {$pdo->rollBack();return false;}
            $pdo->prepare('INSERT INTO container_arrival_notifications(container_id,days_before) VALUES (?,?)')->execute([$containerId,$daysBefore]);
            // NotificationService queues external delivery while in a transaction;
            // rollback removes both dashboard entries and pending delivery intents.
            $notifications->notifyContainerArrival($containerId,$container['code'],$container['eta_date'],$daysBefore);
            $pdo->commit();return true;
        } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}
