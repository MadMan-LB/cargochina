# CLMS Vision — Inspired by Amazon, CMA CGM, ONE

**Goal:** Manage the whole process and relations — internally, suppliers, customers, admins, warehouse, field staff (people who visit shops), products — with each department independent yet working together, plus accounting.

**Principle:** Same capabilities as these reference systems, but **simpler, easier, and only what we need**.

---

## 1. What We Learn from Each Reference

### Amazon (Vendor/Seller Central, Supply Chain)
| Capability | What it does | CLMS equivalent / gap |
|------------|--------------|------------------------|
| **Vendor management** | Suppliers, contracts, performance | ✅ Suppliers + payments; ⬜ supplier scoring, visit history |
| **Order management** | Create, approve, track | ✅ Orders + lifecycle |
| **Inventory / fulfillment** | Stock levels, replenishment | ⬜ We consolidate, not hold stock; ⬜ "ready to ship" visibility |
| **Returns** | RMA, refunds | ⬜ Not in scope yet |
| **Reporting** | Dashboards, exports | ✅ CSV export; ⬜ role-based dashboards, KPIs |
| **Multi-channel** | Different sales channels | ⬜ Single flow; could add "channel" tag later |

### CMA CGM (Shipping / Cargo)
| Capability | What it does | CLMS equivalent / gap |
|------------|--------------|------------------------|
| **Shipment dashboard** | Central view of all cargo | ⬜ End-to-end pipeline view (order → receive → consolidate → ship) |
| **Document management** | BOL, booking confirmations, invoices | ⬜ Order attachments exist; ⬜ shipment-level docs, BOL storage |
| **Tracking** | Real-time container/shipment status | ✅ Push to tracking; ⬜ container-level tracking in CLMS |
| **To-do / workflow** | Tasks per shipment | ⬜ "My tasks" per user/department |
| **Scheduled exports** | Daily/weekly CSV | ✅ Manual export; ⬜ scheduled/automated |
| **Booking & quoting** | Spot rates, quick booking | ⬜ Out of scope (we hand off to carrier) |

### ONE (Ocean Network Express)
| Capability | What it does | CLMS equivalent / gap |
|------------|--------------|------------------------|
| **Quote → booking** | Get rate, then book | ⬜ We consolidate; carrier does booking |
| **Tracking** | Container, BOL, booking refs | ✅ Push to tracking; ⬜ store carrier refs in CLMS |
| **Document access** | BOL, invoices | ⬜ Attach to shipment draft |
| **Mobile** | App for tracking | ⬜ Responsive web; mobile app later |

---

## 2. Our Target Model — Departments & Relations

```
                    ┌─────────────────────────────────────────────────────────┐
                    │                      ADMIN                               │
                    │  Users, config, accounting overview, audit               │
                    └─────────────────────────────────────────────────────────┘
                                         │
         ┌───────────────────────────────┼───────────────────────────────┐
         │                               │                               │
         ▼                               ▼                               ▼
┌─────────────────┐           ┌─────────────────┐           ┌─────────────────┐
│    BUYERS       │           │   WAREHOUSE      │           │ CONSOLIDATION   │
│ Orders, cust,   │──────────▶│ Receiving,       │──────────▶│ Shipment drafts,│
│ suppliers,      │           │ evidence, queue  │           │ containers,    │
│ products        │           │                  │           │ push to track   │
└─────────────────┘           └─────────────────┘           └─────────────────┘
         │                               │                               │
         │                               │                               │
         ▼                               ▼                               ▼
┌─────────────────┐           ┌─────────────────┐           ┌─────────────────┐
│ FIELD STAFF     │           │   SUPPLIERS     │           │   CUSTOMERS     │
│ People who      │           │ Factories,      │           │ End buyers,     │
│ visit shops,    │           │ stores, contacts│           │ deposits,       │
│ inspections     │           │ payments        │           │ confirmations  │
└─────────────────┘           └─────────────────┘           └─────────────────┘
         │                               │                               │
         └───────────────────────────────┼───────────────────────────────┘
                                         │
                                         ▼
                              ┌─────────────────┐
                              │   ACCOUNTING    │
                              │ Customer $ in,  │
                              │ Supplier $ out, │
                              │ per-order P&L  │
                              └─────────────────┘
```

---

## 3. Feature Roadmap — Phased

### Phase 1 — Department independence & visibility (near term) ✅ 2025-02-19
- [x] **Department dashboards** — Dashboard stats + role-scoped "My tasks" (orders to approve, to receive, ready to consolidate, draft shipments).
- [x] **"My tasks"** — Role-based actionable links on dashboard (buyers, warehouse, consolidation).
- [x] **Audit log viewer** — `admin_audit_log.php` + `GET /audit-log`; filter by entity_type, entity_id, user_id, date; SuperAdmin, ChinaAdmin.
- [x] **Field staff role** — Migration 024: `FieldStaff` role; suppliers page for FieldStaff (view + Log visit); visit/quote/note via `POST /suppliers/{id}/interactions`.

### Phase 2 — Relations & documents (medium term) ✅ 2025-02-19
- [x] **Supplier visits / interactions** — Log visit modal on suppliers page (Phase 1).
- [x] **Shipment documents** — Migration 025: `shipment_draft_documents`, carrier refs (container_number, booking_number, tracking_url) on shipment_drafts. Consolidation draft modal: carrier refs + document upload (BOL, booking confirmation, invoice).
- [x] **End-to-end pipeline view** — `pipeline.php`: stage cards with counts and links (Draft → Submitted → To receive → Await confirm → Ready → Finalized).
- [ ] **Customer portal (optional)** — Deferred.

### Phase 3 — Accounting (medium term)
- [ ] **Customer ledger** — Invoices vs deposits vs balance; per customer, per currency.
- [ ] **Supplier ledger** — Invoices vs payments vs balance; per supplier.
- [ ] **Order-level P&L** — Cost (supplier) vs revenue (customer) per order; margin view.
- [ ] **Accounting export** — CSV/Excel for accountant; by period, by customer/supplier.

### Phase 4 — Advanced (later)
- [ ] **Container tracking in CLMS** — Store carrier tracking URL, container #; link to shipment.
- [ ] **Scheduled reports** — Daily/weekly export to email or shared folder.
- [ ] **Supplier scoring** — On-time, quality, dispute rate (if we have data).
- [ ] **Mobile-first views** — Optimize warehouse receive, field visit capture for phones.

---

## 4. Department Responsibilities (Clarified)

| Department | Owns | Sees | Works with |
|------------|------|------|------------|
| **Buyers** | Orders, customers, suppliers, products | Order list, customer/supplier data | Field staff (visit reports), warehouse (receipt status) |
| **Warehouse** | Receiving, evidence, queue | Pending receive, history | Buyers (order details), consolidation (ready orders) |
| **Consolidation** | Shipment drafts, containers, push | Ready orders, drafts, containers | Warehouse (confirmed), tracking (push status) |
| **Field staff** | Visits, inspections | Suppliers, products, visit log | Buyers (order context) |
| **Admin** | Users, config, accounting | Everything, audit, ledgers | All departments |

---

## 5. Accounting Model (Target)

```
Customer side:
  - Order total (revenue)
  - Deposits received
  - Balance = total - deposits

Supplier side:
  - Order cost (what we pay)
  - Payments made
  - Balance = cost - payments

Order P&L:
  - Revenue (customer) - Cost (supplier) = Margin
  - Shipping/freight can be added later
```

**Existing:** `customer_deposits`, `supplier_payments`, order `currency`, item `unit_price`/`total_amount`.  
**Gaps:** Ledger views, balance calculation, P&L report, accounting export.

---

## 6. What We Keep Simple (vs Amazon/CMA CGM/ONE)

- **No inventory management** — We consolidate, not hold stock.
- **No carrier booking** — We hand off to tracking; carrier does booking.
- **No real-time vessel tracking** — Tracking system handles that.
- **No complex pricing engine** — Spot rates, contracts out of scope for now.
- **No returns/RMA** — Add only if business requires.
- **No mobile app** — Responsive web first.

---

## 7. Next Steps (Recommendation)

1. **Implement Phase 1** — Department dashboards, "My tasks", audit viewer, field staff role.
2. **Wire supplier interactions** — Ensure visit logging is usable by field staff.
3. **Design accounting schema** — Ledger tables, balance logic, P&L query.
4. **Document API** — New endpoints for dashboards, tasks, ledgers.

---

*Document created 2025-02-19. Update as priorities and scope change.*
