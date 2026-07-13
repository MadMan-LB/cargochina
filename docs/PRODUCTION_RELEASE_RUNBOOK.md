# Controlled Production Release Runbook

**Status:** PREPARED — NOT AUTHORIZED OR EXECUTED  
**Overall release state:** BLOCKED  
**Scope:** future release only after every management, read-only, backup, restore, and operational gate below is signed

This runbook does not grant production access. It orders a future release and labels every action so an approver can see where production is read or changed.

## Classification legend

- **READ-ONLY:** observes state and must not mutate production.
- **PRODUCTION WRITE:** changes production files, configuration, schema, or data.
- **REVERSIBLE:** has a tested, bounded reversal path; reversal can still lose writes made after release.
- **IRREVERSIBLE OR HIGH-RISK:** may lock tables, discard post-release evidence, affect balances/stock/security, or require backup restoration. This label is a warning, not permission.

## Mandatory release sequence

| # | Classification | Owner/gate | Action and required evidence | Failure or rollback response |
|---:|---|---|---|---|
| 1 | READ-ONLY | Management + accounting | Approve every A1–A13 answer in `ACCOUNTING_DECISION_PACKET.md`, including effective date and historical treatment. Record approver, UTC time, and document hash. | Stop. No posting/allocation/valuation rule may be enabled. |
| 2 | READ-ONLY | Operations + management | Approve the item-number scope, legacy preservation rule, active-conflict treatment, canonical model, enforcement timing, and audit requirement in `LEGACY_ITEM_NUMBER_DECISION_PACKET.md`. | Stop. Do not renumber or create a DB uniqueness constraint. |
| 3 | READ-ONLY | Release owner + DBA | Approve the exact authorization statement in `SAFE_PRODUCTION_PREFLIGHT_PROCEDURE.md`, create the dedicated read-only account, and review the static SQL. | Stop. Do not substitute the application credential or application bootstrap. |
| 4 | READ-ONLY | Two operators | Execute the separately authorized pure-client preflight, verify literal host/database/server identity, grants, migrations 067–071, migration 070 columns, schema drift, legacy-number counts, financial totals, orphans, and unchanged before/after counts. Archive stdout/stderr and SQL hash. | Roll back the read-only transaction and stop on any mismatch or unexpected result. No automatic remediation. |
| 5 | READ-ONLY | Release board | Review the preflight evidence and reconcile it with `MIGRATION_067_076_RECONCILIATION.md`. Approve a forward-only treatment for 067–071; explicitly prohibit blind rollback/reapplication. | Stop and investigate offline. |
| 6 | PRODUCTION WRITE; REVERSIBLE; IRREVERSIBLE OR HIGH-RISK | DBA + storage owner | Create the database and uploads backups exactly as scoped in `BACKUP_AND_RESTORE_RUNBOOK.md`; produce SHA-256 manifests, timestamps, tool versions, source identity, sizes, encrypted storage location, and custody record. | Delete only known-incomplete artifacts according to retention policy; production itself is not altered by a logical dump, but the operation can affect load. Stop. |
| 7 | PRODUCTION WRITE; REVERSIBLE | Restore operator on isolated infrastructure | Restore the new artifacts into an isolated non-production target and verify integrity, row counts, financial/control totals, orphan checks, uploads, login, exports, and receiving evidence. Record actual restore duration. | Stop. Fix backup/recovery process; do not enter the deployment window. |
| 8 | READ-ONLY | Release owner | Freeze and hash the release manifest: all application files, migrations 072–077, environment-variable names (not values), rollback artifact, dependency versions, and current production version. Confirm PHP BCMath/PDO MySQL/mbstring/fileinfo/ZIP/XML and storage capacity. | Stop on an unknown file, missing dependency, or absent rollback artifact. |
| 9 | PRODUCTION WRITE; REVERSIBLE | Operations | Announce the maintenance window, prevent new application writes, drain in-flight jobs/requests, and capture final pre-write control totals and active-session/queue state. | If writes cannot be quiesced, cancel the release and restore normal service without schema work. |
| 10 | PRODUCTION WRITE; REVERSIBLE | Release operator | Stage the exact release files without activating them. Protect `.env`, uploads, logs, generated artifacts, and credentials from replacement. Verify staged hashes. | Remove the staged release and retain the prior active version. |
| 11 | PRODUCTION WRITE; REVERSIBLE; IRREVERSIBLE OR HIGH-RISK | DBA + release operator | Invoke the reviewed migration runner once with explicit process-level target variables, `APP_ENV=production`, and `ALLOW_DEMO_SEED=0`. It must report 067–071 already recorded/skipped and apply 072, then 073, then 074, then 075, then 076 in lexical order. Capture complete redacted output and timestamps. | Stop on the first discrepancy. Do not mark a migration manually, rerun blindly, or reverse 067–071. Use the migration-specific response below. |
| 12 | PRODUCTION WRITE; REVERSIBLE | Release operator | Atomically activate the staged application release after migrations succeed. Restart/reload only the required services and verify active file/version hashes. | Re-activate the prior code if schema compatibility permits; otherwise follow the coordinated rollback decision. |
| 13 | READ-ONLY | DBA | Verify migrations 072–077 are recorded exactly once, required tables/columns/indexes/FKs exist, 067–071 signatures still match, migration 070 dimensions remain nullable, migration 077 performed no historical posting/renumber/reset, and pre-write control totals were not unexpectedly changed by schema installation. | Keep maintenance mode. Escalate drift; do not repair production ad hoc. |
| 14 | PRODUCTION WRITE; REVERSIBLE | Security/release owner | Apply approved production configuration and secret references, including secure session/origin settings and any rotated administrator credential. Keep the translation provider disabled unless its separate live plan has passed and the release board authorizes it. | Restore previous secret/config references; rotate any exposed credential. |
| 15 | PRODUCTION WRITE; REVERSIBLE | Bilingual reviewer + application owner | If separately approved, execute `LIVE_TRANSLATION_VERIFICATION_PLAN.md` with synthetic data. This is not approval for backfill. | Disable provider configuration; retain original text and queued failure evidence. |
| 16 | PRODUCTION WRITE; REVERSIBLE; IRREVERSIBLE OR HIGH-RISK | Named test operators | Run minimal production-safe smoke using pre-approved synthetic accounts/fixtures: authentication/RBAC, customer/supplier selection boundaries, draft save, order lifecycle, partial receiving/idempotent retry/reversal, stock, exports, English/Chinese parity. Record every fixture ID for cleanup/audit. | Keep maintenance mode and trigger rollback review on any authorization, receiving, stock, or data-integrity failure. |
| 17 | READ-ONLY | Finance + DBA | Re-run exact financial/control queries: customer receivable/deposits, supplier invoice/payment balances, active receipt quantities/CBM/weight, cost/fee totals, orphans, item-number conflicts, and before/after row counts. Obtain finance signoff. | Any unexplained delta is an immediate rollback trigger. Never “balance” by editing history. |
| 18 | READ-ONLY, then PRODUCTION WRITE | Release owner | If and only if all gates pass, end maintenance mode and record activation time. This changes service availability but not intended business data. | Re-enable maintenance mode if health degrades. |
| 19 | READ-ONLY | Operations/security/finance | Monitor errors, latency, DB locks/load, login throttling, authorization denials, translation failures/queue, receiving operation-key conflicts, stock and financial reconciliation, and export failures at 15 min, 1 h, 4 h, 24 h, and the approved extended window. | Apply the trigger matrix below; preserve evidence before rollback. |
| 20 | READ-ONLY | Management + finance + operations + security + release owner | Sign the final release record only after the monitoring window and downstream impact review. Include exceptions and residual risks. | Status remains blocked or conditional; silence is not approval. |

## Migration 072–077 execution and response

The future write window must use the reviewed application migration runner because the SQL contains guarded statements and migration bookkeeping. The command and credential injection method must be written into the change ticket, peer-reviewed, and target the identity proven by the read-only preflight. Do not paste secrets into the ticket or terminal transcript.

| Migration | Production-write effect | Precheck | Immediate verification | Rollback/forward response |
|---|---|---|---|---|
| 072 | Creates translation queue, manual-correction, and bilingual registry tables. | Table names absent or structurally identical; FK target `users(id)` valid. | Three tables, unique keys, indexes, FK; no existing translated business values changed. | Prefer forward fix. Dropping tables loses new queue/manual evidence; export it before any approved reversal. |
| 073 | Creates item taxonomy/classification/history and derives classification rows from legacy flags. | Required item/order/product tables and legacy columns match; review derived-row estimate. | Type seed set, classification/history constraints, derived counts, no update to legacy item rows. | High data-loss risk after users classify items; export classification/history and prefer forward fix. |
| 074 | Creates cost types, draft costs, and history. | Currency/user/order/supplier dependencies match. | Tables, seed types, FKs/indexes, zero unexpected historical cost rows. | Export all cost/history rows before reversal; prefer forward fix. |
| 075 | Adds nullable receiving operation ID and a unique index. | No duplicate non-null operation IDs; assess DDL lock/space/time. | Column/index signature, existing rows remain null, receiving retry smoke. | Dropping the index/column removes idempotency evidence; prefer forward fix. This is the highest lock-sensitive step. |
| 076 | Creates login-attempt throttle state. | Table absent or compatible; database collation/index lengths supported. | Table/indexes and authentication/throttle smoke. | Can drop only after security approves loss of throttle state; code/config rollback usually safer. |

The runner must not newly execute 067–071. If its output says otherwise, abort before approval of subsequent application activation. A “migration recorded but schema absent/different” result is schema drift requiring a reviewed forward repair, not a reason to delete migration history.

## Rollback triggers

Immediate rollback review is mandatory for:

- database identity mismatch, unexpected migration, migration bookkeeping/schema disagreement, or uncontrolled DDL lock;
- authentication bypass, cross-customer data exposure, CSRF/origin regression, secret exposure, or unexplained authorization change;
- duplicate receipt from retry, receipt reversal failure, stock/control-total drift, or orphan creation;
- unexplained customer/supplier balance, profitability, cost, fee, or financial-report delta;
- material error/latency/load thresholds exceeding the approved limits;
- inability to preserve original bilingual data or manual corrections;
- inability to recover within the management-approved recovery objectives.

## Rollback decision and order

1. Re-enter maintenance mode and stop new writes.
2. Capture timestamps, active release/schema versions, error evidence, queues, control totals, and post-release business writes.
3. Convene the DBA, finance, operations, security, and release owner. Decide whether a forward fix is safer than reversal.
4. If code-only rollback is schema-compatible, re-activate the prior release and restore prior configuration references.
5. Do not drop 072–077 tables/columns while they contain post-release auth, translation, classification, cost, receiving, ledger, or item-number reservation evidence. Export and reconcile first; prefer forward fixes.
6. Restore the verified database/uploads backup only for confirmed corruption or an approved full rollback. A restore discards legitimate writes after the backup and is therefore **IRREVERSIBLE OR HIGH-RISK** without a reconciled post-backup transaction plan.
7. Execute and evidence recovery using `BACKUP_AND_RESTORE_RUNBOOK.md`; repeat security, workflow, stock, financial, file, and downstream verification before service resumes.

## Final signoff record

The release record must contain the approved accounting and item-number decisions, preflight authorization/evidence, backup/restore evidence, release and SQL hashes, production target identity, migration output, config/provider disposition, smoke fixtures/results, financial/control comparisons, monitoring results, rollback decision, residual risks, and named UTC approvals. Until all exist, overall production readiness remains **BLOCKED**.
