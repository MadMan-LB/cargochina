# Checkpoint 9 — Decision and Release-Preparation Gate

**Checkpoint documentation state:** VERIFIED by code/document review  
**Overall production readiness:** **BLOCKED**  
**Production actions performed in this checkpoint:** none

## Checkpoint 10 superseding decision update — 2026-07-12

Management has approved accounting rules A1–A13 and item-number rules I1–I6. `ACCOUNTING_DECISION_PACKET.md` and `LEGACY_ITEM_NUMBER_DECISION_PACKET.md` are now the controlling decision records. This is management approval only; no separate accountant signoff is recorded or claimed.

The decision blockers in this Checkpoint 9 snapshot are superseded by the Checkpoint 10 prospective code/schema/UI implementation. Production readiness remains **BLOCKED**: no production access, read-only production preflight, backup/restore rehearsal, live translation-provider verification, deployment, production migration 072–077, smoke test, financial signoff, monitoring, or final production downstream verification has been authorized or performed. Historical conflicts and migrations 067–071 still require authorized read-only verification. No reset is authorized.

No production connection, migration, backup, deployment, production-data change, item-number change, or accounting-rule implementation was performed. This checkpoint supplies decisions and procedures; it does not authorize their execution.

## Prepared evidence set

| Required deliverable | Document | State |
|---|---|---|
| Accounting decision packet | `ACCOUNTING_DECISION_PACKET.md` | A1–A13 management-approved in Checkpoint 10; separate accountant signoff is not recorded. |
| Migration 067–076 reconciliation | `MIGRATION_067_076_RECONCILIATION.md` | Code-only analysis prepared; production facts require authorized read-only verification. |
| Legacy item-number decision packet | `LEGACY_ITEM_NUMBER_DECISION_PACKET.md` | I1–I6 management-approved; fresh production read-only conflict counts remain required. |
| Safe production preflight | `SAFE_PRODUCTION_PREFLIGHT_PROCEDURE.md` | Exact pure-client, static-SQL procedure prepared; not authorized or executed. |
| Backup and restore gate | `BACKUP_AND_RESTORE_RUNBOOK.md` | Commands, verification, evidence, and rollback order prepared; no production backup created. |
| Live translation verification | `LIVE_TRANSLATION_VERIFICATION_PLAN.md` | Isolated synthetic-data plan prepared; not executed. |
| Ordered release procedure | `PRODUCTION_RELEASE_RUNBOOK.md` | Prepared and classified; release remains blocked. |

## Accounting decisions requiring management’s answer

This section preserves the pre-Checkpoint-10 question set. Management answered it through the controlling A1–A13 rules; the earlier recommendations below are historical and cannot override those rules. Separate accountant signoff remains an independent release gate.

1. **A1 — Customer-responsible costs:** whether and when they increase customer receivables.
2. **A2 — Supplier/provider liability:** which evidence creates a payable and prevents duplicate liability.
3. **A3 — Cost classification:** which company-paid costs are capitalizable landed cost versus period expense.
4. **A4 — Receiving fees:** whether/when customer fees post and whether a separate provider invoice is required.
5. **A5 — Posting events:** confirmation, receipt acceptance, or another approved authoritative event by source type.
6. **A6 — Foreign exchange:** rate source/date, locked currencies, precision, rounding, and any remeasurement policy.
7. **A7 — Allocation:** approved basis by cost type, eligibility, zero denominators, precision, and final remainder.
8. **A8 — Inventory valuation:** supplier price plus which approved costs, and when a received inventory layer begins.
9. **A9 — Profit presentation:** landed COGS, period expenses, service-fee revenue, and related expense lines.
10. **A10 — Partial receiving:** cumulative allocation and deterministic remainder across partial receipts.
11. **A11 — Idempotency/source edits:** unique posting identity and reversal/replacement versus delta treatment.
12. **A12 — Reversals:** cancellation, receipt void, decline, refund, and cost deletion treatment.
13. **A13 — Reporting/effective date:** affected reports/statements, prospective cutover, and any separately governed restatement.

No recommendation in the packet is active until those answers are signed.

## Item-number decisions requiring management’s answer

1. Approve the recommended canonical registry plus many-to-one legacy references (R3), choose denormalization (R4), or formally accept application-only locking as interim (R5).
2. Decide whether canceled/declined/soft-deleted item numbers remain permanently reserved.
3. Define exactly which statuses are active and therefore require mandatory conflict resolution.
4. Decide whether any completed historical number may change and specify the legal/document conditions.
5. For every active conflict, name which item retains the identifier, who approves a replacement, and how issued/receipt/export references are preserved.
6. Confirm whether uniqueness comparison is case-insensitive and whitespace-trimmed, as recommended.

The safe default remains: preserve historical identifiers; change only a confirmed active same-customer collision or blank that makes operations ambiguous; map history to a canonical reservation; do not add the constraint yet.

## Exact read-only production authorization required

The following text must be completed exactly, signed by the named approver, and timestamped before the prepared preflight can run:

> I authorize one read-only CLMS production preflight against host `<PRODUCTION_HOST>`, port `<PORT>`, database `<PRODUCTION_DB>`, expected server identity `<SERVER_HOSTNAME_OR_APPROVED_CLUSTER_IDENTITIES>`, using dedicated account `<READ_ONLY_ACCOUNT>`, during `<UTC_START–UTC_END>`. No migration runner, application bootstrap, backup, INSERT, UPDATE, DELETE, ALTER, CREATE, DROP, TRUNCATE, REPLACE, or corrective action is authorized. Results may be written only to `<LOCAL_EVIDENCE_DIRECTORY>`.

“Continue,” “check production,” or approval of this documentation checkpoint is not that authorization. The dedicated account must have only the equivalent of `SELECT, SHOW VIEW` on the named database. The exact later command and every static SQL statement are in `SAFE_PRODUCTION_PREFLIGHT_PROCEDURE.md`.

## Backup and restore evidence required

The backup gate passes only when all of the following exist and an isolated restore is signed `VERIFIED`:

- explicit backup authorization and named operator/reviewer;
- literal production source and isolated restore target identities without credentials;
- UTC start/end and duration for database dump, uploads archive, transfer, restore, and verification;
- tool/vendor versions and redacted command transcript;
- timestamped database, upload, release-manifest, and checksum artifact names and sizes;
- matching SHA-256 values before transfer and after retrieval, encrypted off-host storage, custody, and retention evidence;
- table-engine inventory and consistency method;
- database dump parse/integrity checks and uploads archive-list/extraction checks;
- isolated restored schema/migration signatures, row counts, orphan checks, and restored upload count/hash sample;
- exact customer receivable/deposit, supplier invoice/payment, operational cost/fee, active receiving, quantity/CBM/weight, and other critical control-total comparison using DECIMAL strings;
- isolated authentication/RBAC, English/Chinese, receiving/idempotency/reversal, export, and file-display smoke results;
- measured recovery duration compared with management-approved RTO and RPO;
- final named approval and exact rollback/recovery decision record.

A dump file or checksum without a successful isolated restore rehearsal is insufficient.

## Translation configuration required

| Variable | Required disposition |
|---|---|
| `TRANSLATION_PROVIDER` | `libretranslate` or `generic` for an approved live test; `disabled` remains the safe default until then. |
| `TRANSLATION_API_URL` | Approved allowlisted endpoint, preferably HTTPS; no embedded credentials. |
| `TRANSLATION_API_KEY` | Secret-manager injection if required; validate only configured/not configured and never print/hash/log it. |
| `TRANSLATION_TIMEOUT_SECONDS` | Integer 1–30; initial recommendation 8. |
| `APP_ENV`, `DB_HOST`, `DB_PORT`, `DB_NAME` | Explicit isolated non-production verification target; refuse production. |

Provider contract/privacy, retention, quota/rate limits, DNS/TLS, both language directions, caching, manual precedence, source changes, outage behavior, application non-blocking behavior, and infrastructure logs must pass `LIVE_TRANSLATION_VERIFICATION_PLAN.md`. This does not authorize backfill or production provider activation.

## Risks from migrations 067–071 already in production

1. Production schema is ahead of the normal application release, so environment comparisons can falsely diagnose drift unless the incident is retained in the release record.
2. Migration 068 updated existing `customers.created_by` values using the **lowest numeric matching audit `user_id`**, not necessarily the chronologically earliest create event. It is the incident migration with historical business-table mutation; the old null state cannot be reconstructed reliably without a pre-migration snapshot, multi-user create histories require review, and attribution later affects visibility behavior.
3. Migrations 069 and 070 could have caused transient ALTER/metadata locks when they ran. That is historical execution risk, not an ongoing recalculation.
4. Migration 070 added only nullable `DECIMAL(12,4)` columns `actual_height`, `actual_width`, and `actual_length` to `warehouse_receipt_items`. It performed no INSERT/UPDATE, stock, balance, fee, or financial recalculation. Old code should ignore the columns. Dropping them later could destroy captured dimensional evidence if anything has begun writing them.
5. Migration 071’s additive fee table does not itself post balances. Its presence must not be treated as approval or evidence that fee behavior is deployed.
6. Nullable columns/additive tables in 067, 069, 070, and 071 appear compatible with the inferred old code, but only the authorized scan can confirm live signatures, values, and unexpected manual use.
7. Deleting `_migrations` rows, blindly re-running 067–071, or destructively rolling them back would increase risk. Migration 068 especially requires a forward reconciliation; all rollback decisions require backup/restore evidence and proof that no required data would be lost.

## Exact safest next action

The former management-decision action is complete. Checkpoint 10 implements the prospective rules in code and isolated databases. The next production action remains the separately authorized read-only preflight; backup, restore rehearsal, provider verification, migration, and deployment remain later gates.

After those decisions—but not before—a separately named approver may issue the exact read-only authorization for the pure-client production preflight. Backup, restore rehearsal, provider verification, migration, and deployment remain later, separately authorized gates.

## Updated blockers

| State | Blocker |
|---|---|
| VERIFIED | Management decisions A1–A13 and I1–I6 are recorded in the Checkpoint 10 packets. No accountant approval is claimed. |
| IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION | Prospective accounting posting/reversal and registry-based normalized item-number reservation are implemented in migration 077 and matching code; production migration and live state are unverified. |
| BLOCKED | Current production item-number conflict counts are unverified; no historical cleanup, renumbering, or reset is authorized. |
| BLOCKED | No exact authorization, dedicated grants proof, or evidence exists for the read-only production preflight. Live migration/schema/customer-attribution/item/financial state therefore remains unverified. |
| BLOCKED | No newly authorized production database/uploads backup and no successful isolated restore rehearsal with SHA-256/control-total evidence exist. |
| IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION | Live translation credentials, provider contract/privacy, network/TLS, quotas, latency, output quality, failure modes, and log hygiene are unverified. |
| BLOCKED | Production migration 072–077, release activation, PHP/server dependency checks, maintenance-window execution, production-safe smoke, financial signoff, performance/load, monitoring, and rollback readiness are unexecuted. |
| BLOCKED | Migrations 067–071 remain incident-deployed and require authorized read-only signature/data reconciliation; migration 068 attribution requires special review. |
| BLOCKED | No source-control metadata exists in this workspace, so the handoff cannot rely on a native commit/diff/rollback artifact; file hashes and the maintained inventory are required. |

Checkpoint 9 is complete only as a **decision and release-preparation documentation checkpoint**. Overall readiness remains blocked until the downstream gates are separately authorized, executed, reconciled, and signed.
