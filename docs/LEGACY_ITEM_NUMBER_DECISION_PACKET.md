# Management-approved item-number decisions

Status: **VERIFIED** as the management decision record for Checkpoint 10; **IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION** for production deployment and live historical-conflict facts. No production number was inspected, rewritten, or reserved in this checkpoint, and no reset is authorized.

## Controlling rules

| ID | Management-approved rule | Implementation consequence |
|---|---|---|
| I1 | Automatic database-backed reservation with concurrency protection. | The backend generates the visible number inside a transaction. A canonical registry has unique `(customer_id, normalized_item_no)` protection, while reference rows associate issued numbers with order items and shared-carton data. |
| I2 | Continue from the largest issued sequence plus one; never fill gaps or reuse numbers. | Generation scans established history and reservations, keeps supplier grouping, and increments the correct largest sequence. Registry rows survive archive/cancel/delete and item replacement. |
| I3 | Draft, approved, confirmed, partially received, received, canceled, archived, and soft-deleted records reserve numbers. | Status is not a release condition. Hidden/canceled rows stay in issued-number history. |
| I4 | Preserve historical data; prevent new conflicts; do not automatically renumber legacy rows. | Migration 077 is additive and deliberately performs no historical backfill or renumber. Read-only conflict reporting remains a production gate. A future reset is separate and unauthorized here. |
| I5 | Authorized operational users trigger generation; ordinary users cannot edit/blank/conflict-remediate numbers. | Normal item-number controls are read-only and the backend discards unauthorized manual values except the already-issued number on the same edited record. Exceptional remediation requires explicit administration permission, normalization, uniqueness validation, and audit logging. |
| I6 | Formatting variants that represent one controlled number compare equal. | Canonical comparison trims, uppercases, removes accidental internal spacing, and normalizes spaces/underscores/dash variants to the official dash format. `AB-10`, `ab-10`, `AB 10`, and padded `AB-10` collide. Display values remain properly formatted. |

## Established visible format and supplier behavior

The existing production-compatible form is preserved:

`<customer-prefix>-<supplier-sequence>-<item-sequence>`

Example for prefix `CUST`:

1. Supplier A first item: `CUST-1-1`
2. Supplier A second item: `CUST-1-2`
3. Supplier B first item: `CUST-2-1`
4. Return to Supplier A: `CUST-1-3`

The backend continues from the largest issued sequence in the established customer/supplier scope. It does not use an unlocked `SELECT MAX` as the only protection: the customer row serializes generation, reservation is part of the transaction, and the normalized unique key is the final database guard. A losing duplicate request rolls back; a retry with the same creation key returns the existing committed order.

## Historical conflicts and future reset

Prior point-in-time evidence reported seven global duplicate groups, eight customer-scoped duplicate groups, and one blank legacy value. Those counts are not asserted as current production facts because production access is prohibited. They remain a read-only preflight/reconciliation item, not authorization to renumber.

Migration 077 does not backfill the reservation registry from legacy data because management explicitly chose preservation now and a future fresh dataset. Existing malformed, duplicate, canceled, archived, and soft-deleted values remain unchanged. New creation paths consult both historical order data and the registry, preventing new reuse while retaining the established format.

## Permission and audit boundary

Ordinary create/edit forms expose item numbers as read-only. The API is authoritative. Exceptional historical conflict remediation is not implemented as an ordinary workflow and remains restricted to an explicitly authorized administrator procedure with before/after evidence and an audit entry. Production preflight, backup/restore rehearsal, any cleanup, and the future reset remain separately authorized gates.
