# CLMS — China Logistics Management System

Salameh Cargo's China Operations platform. Replaces Excel-based workflows with validated, structured data entry and integration with the tracking system.

## Requirements

- PHP 8.0+
- MySQL 8
- XAMPP (or Apache + PHP + MySQL)

## Quick Start

### 1. Database setup

```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS clms CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2. Environment

```bash
cp .env.example .env
# Edit .env with your DB credentials
```

### 3. Run migrations

**Windows (XAMPP):**
```batch
run-migrations.bat
```
Or: `c:\xampp\php\php.exe backend/migrations/run.php`

**Linux/Mac:**
```bash
php backend/migrations/run.php
```

> **Note:** If you see "could not find driver", enable the MySQL PDO extension in `php.ini` (XAMPP: uncomment `extension=pdo_mysql`).

### 4. Login

Migration 006 seeds a default SuperAdmin: `admin@salameh.com` / `password`. Log in at `login.php`.

### 5. Run locally

- **XAMPP**: Start Apache and MySQL. Open `http://localhost/cargochina/`
- **PHP built-in server**: `php -S localhost:8080 -t .` then open `http://localhost:8080/`

### 6. Responsive testing

The UI is responsive for tablets (e.g. iPad). Test on iPad or use Chrome DevTools device emulation to verify layouts, scrollable tables, and stacked forms on smaller screens.

## Project Structure

```
cargochina/
├── backend/           # PHP API & business logic
│   ├── config/        # DB, thresholds
│   ├── migrations/    # SQL migrations
│   ├── models/       # Data access
│   ├── services/     # State machine, validation
│   ├── api/          # REST endpoints
│   └── uploads/      # Attachments (gitignored)
├── frontend/          # Web UI (Bootstrap)
├── docs/              # API docs, schema
└── tests/             # Tests
```

## API Base URL

`/api/v1/` — All REST endpoints are versioned under v1.

## Configuration

See `.env.example` for:
- `VARIANCE_THRESHOLD_PERCENT` — % difference to trigger customer confirmation
- `VARIANCE_THRESHOLD_ABS_CBM` — Absolute CBM difference threshold
- `CONFIRMATION_REQUIRED` — variance-only | always-on-arrival
- `CUSTOMER_PHOTO_VISIBILITY` — internal-only | customer-visible

## Operational Customer Visibility

Customer owner/creator visibility rules are intentionally limited to the full customer management surface (`customers.php` and full customer-management API actions). The Customers page is visible to operational users, and operational users can add customers. Edit, delete, deposit, portal-link, message, import, and attachment controls still follow their narrower backend permissions. The records inside the Customers page are filtered by the customer visibility rules: full-visibility roles see all, other users see unassigned legacy customers, their own customers, and any selected creators configured by an admin. Operational workflows such as Orders, Draft an Order, Receiving, Warehouse Stock, Consolidation, Containers, Expenses, Financials, and Balances should show the full operational data allowed by the user's page/module permission.

Customer selectors in operational workflows use the safe lookup API (`/api/v1/customers/lookup` and `/api/v1/customers/{id}/lookup`), which returns only minimal selection fields and must not expose full customer profiles, balances, private notes, contacts, addresses, or payment links.

## Draft Order Excel Import

`procurement_drafts.php` uses `/api/v1/draft-orders/import` as a preview import. The importer reads normalized header names rather than fixed column positions, tolerates missing optional fields, ignores exported subtotal/grand-total rows, reports skipped rows and warnings, and extracts embedded XLSX photos from the detected Photo column when the server supports workbook drawings. Supplier groups use `Supplier:` marker rows; within each supplier group, the first filled `Express Number` carries down to later item rows with blank express numbers until the next supplier marker. The import fills the draft form for review; it does not save the order until the user saves the draft.

Draft items store separate canonical English and Chinese descriptions. Entering one language triggers translation on blur when the other field is empty, so no provider request is sent for each keystroke; explicit Translate/Retranslate buttons are also available. Automatically generated text remains editable, and changing a source marks an existing manual translation for review instead of overwriting it. Final draft save repeats translation server-side and rejects an item if either language is still missing. Google Cloud Translation Basic is supported through the official server-side v2 API: set `TRANSLATION_PROVIDER=google` and place the Google API key in `TRANSLATION_API_KEY`; `TRANSLATION_API_URL` may remain blank to use the official endpoint. LibreTranslate and compatible generic JSON providers remain available through `TRANSLATION_API_URL`. Credentials belong only in `.env` or the server environment. With the provider disabled or unavailable, staff must enter the missing language manually or use an existing cached/manual translation.

The Draft an Order list supports bilingual text search plus status, customer, supplier, canonical goods type, brand, creator, created-date, and expected-date filters. Filters share one parameterized backend query for records and counts, remain active through pagination, and are reused by the complete filtered XLSX download.

## Receiving Excel Import

`receiving.php` uses the same generated procurement import template as Draft an Order, available from the receiving import modal. The preview step reads normalized procurement headers instead of fixed column positions, supports reordered columns, and maps columns such as Customer, Item No, SKU / Item Code, Express Number, Cartons, CBM/Unit or Total CBM or Height/Width/Length, Weight/Carton or Total Weight, and Supplier. Receiving applies the same supplier-section Express Number carry-down rule as Draft an Order before validation/commit. Manual receiving uses item-level dimensions to calculate Total CBM and item Weight / Carton to calculate Total Weight, then rolls those totals up to the receipt. Operators can add customer-facing receiving fees such as pallet fees during manual receiving; those fees are stored with the warehouse receipt and included in the order Excel download, but they do not automatically post accounting ledger transactions. When no `Order ID` is supplied, the import is treated as a direct warehouse intake: it creates the order/items, records receiving, and places the goods into warehouse stock through the normal receiving service after final confirmation. If the template has no customer and the user does not select one in the modal, the import assigns the receipt to the controlled fallback customer `Direct Warehouse Intake` and shows a warning in preview. If `Order ID` is supplied, it still supports receiving against existing approved/in-transit orders. The final import revalidates the preview token server-side and saves in one transaction.

## Excel Downloads and Filtered Results

System-generated workbooks use the Receiving Procurement Import Template conventions: Arial text, two blue header rows (English first and Chinese directly below), frozen rows, print setup, typed numeric/date cells, multilingual text, and embedded item photos where source images are available. Excel downloads start immediately without a language prompt. Draft and receiving importers recognize the English header and skip the accompanying Chinese header row instead of treating it as item data. Download actions are filter-aware and export the complete matching dataset rather than only the current pagination page. Balances downloads also use real XLSX files instead of CSV.

Orders, Receiving, Warehouse Stock, and Draft an Order support current-page checkbox selection. Selected records download one XLSX workbook: each order is a vertical section in the same worksheet with its complete order header, blue item-header row, bilingual descriptions, embedded product images, blank separation, and a page break before the next order. Image hydration checks the order-item image first, then the linked product image, then active item-level receiving evidence; stale image paths are skipped in favor of the next valid source. The backend reloads and authorizes every selected ID and limits one batch to 100 records.

Shared-carton exports preserve photos for each contained item and hydrate older shared-carton JSON from its linked product when the JSON predates child-level image paths. The same image precedence is used by Orders, Draft an Order, Receiving, Warehouse Stock, and Containers: item image, linked-product image, item-level receipt evidence, then applicable order-level receipt evidence. Missing, unreadable, unsafe, or unsupported image candidates do not invalidate the workbook; the cell shows `No photo` and a sanitized `excel_image_unavailable` diagnostic is written without exposing filesystem paths. A stale candidate does not prevent a later valid fallback image from being embedded.

The shared Excel service also performs a final canonical lookup by trusted order-item/product IDs when an upstream export payload omits image paths or all supplied paths are stale. SuperAdmins can check a live record under **Diagnostics > Excel Image Health**; the result separately reports source-readable and actually embeddable candidates, safe failure/fallback categories, upload/temp/GD/ZIP/Fileinfo health, the image-pipeline version, PHP version, and application/vendor source fingerprints without exposing stored paths. The exporter normally uses PhpSpreadsheet file drawings, but automatically falls back to a small GD-backed memory drawing when production PHP does not expose `mime_content_type()`/Fileinfo or rejects an otherwise valid image. After deploying Excel-image changes, deploy the related handlers, frontend diagnostic asset, service, and matching `vendor/` dependencies together and restart PHP-FPM (or clear OPcache) so production does not run a mixed release.

`backend/uploads/*` is intentionally Git-ignored and is not delivered by a code pull or source-only deployment. Back up and synchronize that directory as a separate media artifact, preserving filename case on Linux and granting the PHP-FPM site user read access. Never replace the live directory with an empty checkout.

Warehouse Stock exposes `Warehouse Received` as a receipt-derived filter. It matches orders with an active, non-void warehouse receipt even when their current lifecycle status has advanced to `Confirmed` or `ReadyForConsolidation`. `Legacy Received Status` remains available separately for historical rows whose stored status is `ReceivedAtWarehouse`.

Legacy databases must run migration `079_receipt_item_dimension_compatibility.sql`. It idempotently adds nullable receipt-item height, width, and length columns where migration history or an older deployment left them absent. Warehouse Stock remains readable during a rolling deployment by returning null dimensions until the migration has run.

## SuperAdmin Training Reset

`admin_config.php` includes a SuperAdmin-only Training Data Reset section. It can delete selected training data groups such as draft orders, orders/receiving, containers, customers, suppliers, products, financial rows, expenses, notifications, non-SuperAdmin users, and logs. The reset requires the configured reset password, runs server-side through `/api/v1/config/training-reset`, keeps schema/config/roles/countries/departments intact, always protects SuperAdmin/admin users, and writes an audit row after completion.

## Full Specification

See [CLMS_README.md](CLMS_README.md) for the complete specification, state machine, RBAC, and DB_CHANGELOG.

## Phase 2 Design

See [docs/PHASE2_NOTIFICATION_HARDENING.md](docs/PHASE2_NOTIFICATION_HARDENING.md) for the PR-ready design and implementation plan for item-level receiving, email/WhatsApp notifications, and open-question resolutions.
