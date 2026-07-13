# Accounting policy decisions required before posting operational costs

Status: **BLOCKED** pending business-owner/accountant approval.

The current implementation records and audits draft operational costs and customer-facing receiving fees, preserves them through approval, and exposes them in reports/exports as informational. It deliberately does not post them to ledgers, profitability, landed cost, or inventory valuation.

The following decisions must be supplied together because each affects downstream reversal and reconciliation behavior:

| Decision | Required authoritative rule |
|---|---|
| Customer responsibility | For each cost type and `responsible_payer=customer`, state whether it increases the customer order receivable, when it posts, its currency/exchange-rate source, and how cancellation/refund reverses it. |
| Supplier responsibility | State whether a supplier-linked cost creates supplier liability, reduces an existing invoice, or remains informational; identify the posting document and settlement behavior. |
| Company responsibility | State whether the cost is only an expense, is capitalized into inventory, or is allocated to an order; identify the expense/account category. |
| Receiving fees | State whether customer-facing receiving fees increase customer receivables and whether warehouse/service-provider liability or expense is created. |
| Posting event | Choose the authoritative event: draft approval, order confirmation, receipt creation, receipt acceptance, shipment finalization, or another documented event. |
| Allocation | For quantity, value, weight, volume, and manual methods, define eligible items, precision, remainder handling, zero-denominator behavior, and multi-currency conversion date/source. |
| Inventory valuation | Define whether valuation uses supplier buy price, received quantity, allocated operational costs, receiving fees, discounts, and which receipt/currency date controls valuation. |
| Profitability | Define which posted costs reduce gross versus net profit and how customer-paid charges are presented. |
| Partial receiving | Define whether costs allocate once per order or proportionally per receipt, and how later receipts consume remaining allocation. |
| Idempotency | Define the unique posting identity for approval/receipt retries and the expected behavior when a source cost changes after posting. |
| Cancellation/reversal | Define immutable reversal entries for order cancellation, receipt void, customer decline, cost deletion, refund, and supplier settlement. Historical ledger rows must not be silently edited. |
| Reporting | Define required customer statement, supplier statement, inventory valuation, profit report, financial statement, and export columns. |

Approval must identify the policy owner, approval date, effective date, currencies, rounding mode, and worked examples for:

- one USD order with a customer-paid handling fee;
- one RMB supplier cost converted to USD;
- a partially received order with two receipts;
- a voided first receipt followed by a replacement receipt;
- a cost edited after order approval;
- a full cancellation after customer and supplier payments.

Until this document is answered and approved, `accounting_treatment='informational'` remains the only safe behavior.

The read-only deployment preflight will continue to return `BLOCKED` for `accounting_policy` until the approved rules are implemented and their posting/reversal tests pass. Merely adding configuration text must not make that check pass.
