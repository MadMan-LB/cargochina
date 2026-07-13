# Management-approved shipment accounting decisions

Status: **VERIFIED** as the management decision record for Checkpoint 10. These rules are management-approved; they are **not labeled accountant-approved**, because no separate accountant signoff is recorded. Implementation is prospective for the future fresh dataset. No production access, migration, data rewrite, backup, deployment, or reset is authorized by this document.

## Controlling rules

| ID | Management-approved rule | Implementation consequence |
|---|---|---|
| A1 | Every configured shipment operational cost is a shipment-level customer charge. | Pallet, transport, receiving, loading/unloading, handling, storage, insurance, customs-related, and other configured costs create separately visible customer-charge entries. |
| A2 | Only a product supplier invoice may increase that supplier's liability. | Shipment cost and receipt-fee posting never writes supplier balances or `supplier_payments`; a supplier/provider reference is descriptive only. |
| A3 | Shipment operational costs are separate business/shipment expenses. | The same source creates a shipment-expense entry, never changes item purchase price, supplier invoice, or inventory unit value. |
| A4 | Receiving fees are shipment-level customer charges and expenses. | A receipt fee creates one paired finalized charge/expense posting, but no inventory value or supplier liability. |
| A5 | Costs appear at draft save as provisional/pending and finalize exactly once at approval. | Draft create/update/delete synchronizes the existing provisional pair. Approval changes it to finalized once; retries return the existing result. Finalized rows are locked and changes use reversals. |
| A6 | Use the exact draft exchange rate; same-currency rate is 1; lock it at approval. | Amount, rate, base currency, and base amount are stored on source and ledger entries. Provisional edits may change the rate. Finalized history is never recalculated from a later system rate. |
| A7 | Never allocate shipment costs to items. | Allocation is fixed to `none`; quantity/value/weight/volume/manual allocation UI and execution are disabled. Historical compatibility columns remain non-destructively. |
| A8 | Inventory value is supplier purchase price only under the existing cost method. | Receiving changes quantities. Pallet, transport, receiving, handling, storage, insurance, and other shipment costs are excluded from unit value and no historical valuation is rewritten. |
| A9 | Keep profitability shipment-focused. | Gross result = customer goods sales/charges minus supplier purchase cost. Net shipment result = total customer charges minus supplier invoice/purchase cost and shipment operational expenses. Each shipment expense is subtracted once. |
| A10 | Complete shipment costs apply from the first receiving operation and are never prorated or reposted. | Approval owns cost finalization. Receiving does not repost approved costs; receipt fees use receipt/source uniqueness. Partial receipts and retries cannot duplicate charges, expenses, balances, or ledger rows. |
| A11 | First valid committed request wins. | Database transactions, row locks, optimistic versions, unique retry/source keys, receiving operation keys, and item-number reservations protect create, draft edit, approval, cost posting, receiving, balance/ledger entries, and numbering. |
| A12 | Delete/cancel looks deleted operationally but preserves auditable accounting. | Sources are soft-deleted/archived/voided, hidden by default, and exposed only through explicit archive views. Finalized originals remain; linked signed reversals remove active balance effects once. Restoration, where supported, creates a new generation once. |
| A13 | Management plans a future reset but authorizes none now. | No historical financial rewrite, automatic reset, production reset, migration reset, startup reset, or test-runner reset is included. Existing data is preserved until a separately authorized, backed-up reset procedure. |

## Posting model

Each shipment cost or receiving fee has two balanced business views, not a double expense:

1. `customer_charge` — what the customer owes for the shipment service.
2. `shipment_expense` — the shipment/business cost used by net shipment profitability.

Draft cost entries use `provisional`; approval changes those same entries to `finalized`. Receiving fees are actual receipt sources and finalize once when the receipt is created. Supplier liability and inventory valuation do not consume either role.

Cancellation preserves the primary entries and adds linked negative `reversal` entries. Restoring an order cost creates a new generation. Receipt fees are restored only through a new valid receiving operation, not by silently reviving a voided fee.

## Reconciliation example

Supplier goods are 100 units × USD 3 = USD 300. Customer goods sales are USD 450. Draft transport is RMB 200 at exactly 0.14000000 USD/RMB = USD 28, and handling is USD 10. The customer sees USD 38 shipment charges. Supplier liability remains USD 300. Inventory unit value remains USD 3; total received inventory value is USD 300, not USD 338.

- Gross result: USD 450 + USD 38 customer charges − USD 300 supplier purchase cost = USD 188.
- Net shipment result: USD 488 total customer charges − USD 300 supplier cost − USD 38 shipment expense = USD 150.

Receiving 60 units and later 40 units changes quantities only. It does not allocate USD 38 to either receipt and does not repost the charge/expense. Canceling the order adds USD -38 customer-charge reversals and USD -38 shipment-expense reversals, leaving zero active shipment effect while retaining the original audit trail.

## Signoff boundary

Management approval is recorded by the Checkpoint 10 instruction dated 2026-07-12. A separate accountant signoff, production financial validation, authorized production preflight, backup/restore rehearsal, deployment, migrations, smoke tests, monitoring, and downstream production verification remain outside this decision record and keep production readiness blocked.
