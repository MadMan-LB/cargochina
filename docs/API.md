# CLMS API Reference

Base URL: `/cargochina/api/v1/` (or `/api/v1/` if at document root)

**Authentication:** Session-based. Login via `POST /auth/login`. All endpoints except `auth` require an active session.

## Audit Log (SuperAdmin, ChinaAdmin)
- `GET /audit-log?entity_type=&entity_id=&user_id=&action=&date_from=&date_to=&limit=&offset=` — List audit entries with filters.
- `GET /audit-log/users` — List users (id, email, full_name) for filter dropdown.

## Authentication
- `POST /auth/login` — `{email, password}` — Returns `{user_id, name, roles}`. **Public.**
- `POST /auth/logout` — Destroys session. **Public.**

## Customers
- `GET /customers` — List all (supports `?q=` for search by name, code, phone)
- `GET /customers/search?q=...` — Search by name/code/phone (top 20 matches)
- `GET /customers/{id}` — Get one
- `GET /customers/{id}/deposits` — Get customer with deposits list
- `GET /customers/{id}/balance` — Get balance per currency `{USD: X, RMB: Y}`
- `POST /customers` — Create `{code, name, phone?, address?, contacts?, addresses?, payment_terms?, payment_links?}` — payment_links: `[{name, value}]` e.g. weeecha, xxx xx xxxx xx
- `POST /customers/{id}/deposits` — Record deposit `{amount, currency (USD|RMB), payment_method?, reference_no?, notes?}`
- `PUT /customers/{id}` — Update (phone, address supported)
- `DELETE /customers/{id}` — Delete

## Suppliers
- `GET /suppliers` — List all
- `GET /suppliers/search?q=...` — Search by name/code/phone/store_id (top 10)
- `GET /suppliers/{id}` — Get one
- `POST /suppliers` — Create `{code, store_id?, name, phone?, address?, fax?, contacts?, factory_location?, notes?, additional_ids?}` — store_id: official China store identifier; address, fax for Template export
- `POST /suppliers/{id}/payments` — Add payment `{amount, currency (USD|RMB), invoice_amount?, marked_full_payment?, payment_type?, order_id?, notes?}` — discount auto-calculated when invoice_amount > amount
- `GET /suppliers/{id}/balance` — Balance summary per currency (total_paid, total_invoiced, total_discount, outstanding)
- `POST /suppliers/{id}/interactions` — Add interaction `{interaction_type?, content?}` — type: visit/quote/note
- `PUT /suppliers/{id}` — Update
- `DELETE /suppliers/{id}` — Delete

## Products
- `GET /products` — List all (filters: `q`, `supplier_id`, `hs_code`, `alert_filter`, `image_filter`)
- `GET /products/search?q=...&supplier_id=...` — Search by description_cn/description_en/hs_code (top 10). `supplier_id` scopes autocomplete results to one supplier section.
- `GET /products/hs-codes?q=...` — Distinct HS codes from products (legacy; Products page uses hs-code-catalog)
- `GET /products/{id}` — Get one
- `GET /products/suggest?q=...` — Suggest by description/HS code (with similarity score)
- `POST /products` — Create `{supplier_id?, cbm, weight, packaging?, hs_code?, description_entries?, pieces_per_carton?, unit_price?, image_paths?, force_create?}` — description_entries: `[{description_text, description_translated}]`; image_paths: array of paths from upload
- `GET /products` — Returns `thumbnail_url` (first image) per product
- `PUT /products/{id}` — Update
- `DELETE /products/{id}` — Delete

## HS Code Tariff Catalog (Lebanon customs reference)
- `GET /hs-code-catalog?q=...&limit=...` — Search catalog for autocomplete and catalog lookup. Numeric HS-code queries match from the opening digits first (normalized to ignore dots/spaces), while text queries rank prefix matches before contains matches. Returns `{data: [{id, hs_code, name, category, tariff_rate, vat, section_name}], meta: {total, returned, limit, truncated, match_mode}}`. Used by Products page filter/HS field and HS Code Tax catalog/estimator fields.
- `GET /hs-code-catalog/files` — List CSV files in `hs codes/` folder (SuperAdmin only)
- `POST /hs-code-catalog/import` — Import `{source?: "filename.csv"}` from `hs codes/` folder. Default: lebanon_customs_tariffs.csv. Truncates and reloads. **SuperAdmin only.**

## Translations
- `POST /translations` — Lookup/translate `{text, source_lang?, target_lang?}` — returns `{translated}`
- `POST /translate` — Translate via TranslationService `{text, source_lang?, target_lang?}` — returns `{translated}`

## Orders
- `GET /orders?status=&customer_id=&order_type=` — List (optional filters). `order_type=draft_procurement` isolates Draft an Order records from standard orders.
- `GET /orders/search?q=...&order_type=` — Search by order ID, customer, supplier, phone, shipping code, item description, and item-level HS code (`order_items.hs_code` with fallback to product HS code).
- `GET /orders/{id}/export` — Export a standard order as Template-style CSV (company header + GOOD DETAILS table: PHOTO, ITEM NO, DESCRIPTION, TOTAL CTNS, QTY/CTN, TOTAL QTY, UNIT PRICE, TOTAL AMOUNT, CBM, TOTAL CBM, GWKG, TOTAL GW). Draft-procurement orders use `GET /draft-orders/{id}/export` for grouped supplier-section export.
- `GET /orders/{id}` — Get one with items, attachments, receipt (when present), receipt.items, receipt.photos, customer_photo_visibility
- `POST /orders` — Create `{customer_id, supplier_id?, expected_ready_date?, currency (USD|RMB), items}` — `expected_ready_date` is optional. Items: `product_id?`, `supplier_id?`, `item_no?`, `shipping_code?`, `cartons?`, `qty_per_carton?`, `quantity`, `unit`, `declared_cbm`, `declared_weight`, `item_length?`, `item_width?`, `item_height?`, `unit_price?`, `total_amount?`, `notes?`, `image_paths?`, `description_cn?`, `description_en?`, `hs_code?`, `custom_design_required?`, `custom_design_note?` — Submit requires min 1 photo per item (configurable). CBM auto-calculated from L*W*H/1000000 on client.
- `PUT /orders/{id}` — Update (any status)
- `POST /orders/{id}/submit` — Draft → Submitted
- `POST /orders/{id}/approve` — Submitted → Approved
- `POST /orders/{id}/receive` — Record receipt `{actual_cartons, actual_cbm, actual_weight, condition, notes?, photo_paths?, items?}` — items: `[{order_item_id, actual_cartons?, actual_cbm?, actual_weight?, condition?, photo_paths?}]` for item-level receiving. Sum of items must match order-level. Evidence photos required when variance or damage.
- `POST /orders/{id}/confirm` — AwaitingCustomerConfirmation → Confirmed

## Draft Orders
- `GET /draft-orders` — List Draft an Order records backed by real `orders` where `order_type='draft_procurement'`
- `GET /draft-orders/{id}` — Get one builder payload grouped into `supplier_sections[]`, with section totals, grand totals, customer shipping-code defaults, and editability state
- `GET /draft-orders/{id}/export` — Export grouped draft-order CSV by supplier section with section subtotals and grand totals
- `POST /draft-orders/import` — Multipart preview import using `file` (`.xlsx`, `.xls`, `.csv`, `.cv`). Parses the existing draft/order export column names and returns a builder-shaped payload; it does not save an order, so missing columns stay empty until the user completes the form and saves. Supplier sections can be marked with a `Supplier:` row and the supplier name in the next cell. Imported item numbers are left for the draft-order numbering logic. Explicit `Factory Price` / `Customer Price` columns map to those form fields; a bare exported `UNIT PRICE` maps to customer price.
- `POST /draft-orders` — Create a real draft order. Body: `{customer_id, expected_ready_date?, currency, high_alert_notes?, supplier_sections[]}` where `expected_ready_date` is optional and each section is `{supplier_id, items[]}`. Each item can include `product_id?`, `description_entries[]`, `pieces_per_carton`, `cartons`, `unit_price`, `cbm_mode`, `cbm?`, `item_length?`, `item_width?`, `item_height?`, `weight`, `hs_code?`, `photo_paths[]`, `custom_design_required`, `custom_design_note?`, `custom_design_paths[]`, `shipping_code?`, `item_no?`, `dimensions_scope?}`. Draft-order items may send a single description entry with only one side filled; the server auto-completes the missing Chinese/English counterpart before storing the order item and any auto-created product.
- `PUT /draft-orders/{id}` — Update a real draft order while its status is still `Draft`
- `POST /draft-orders/legacy/{legacyId}/migrate` — Guided migration for one legacy `procurement_drafts` row. Requires `{customer_id, currency}`; `expected_ready_date` is optional. Existing `converted_order_id` rows are mapped instead of duplicated.

## Order Templates
- `GET /order-templates` — List all templates (id, name, created_at)
- `GET /order-templates/{id}` — Get template with items
- `POST /order-templates` — Create `{name, items[]}` — items: item_no?, shipping_code?, product_id?, supplier_id?, description_cn?, description_en?, cartons?, qty_per_carton?, quantity?, unit?, declared_cbm?, declared_weight?, item_length?, item_width?, item_height?, unit_price?, total_amount?, notes?

## Upload
- `POST /upload` — Multipart form `file` — returns `{data: {path, url}}` — path for storage; url for img src. Always returns JSON; errors use `{error: {message, code: "UPLOAD_FAILED"}}`. Requires `Content-Type: application/json` response.

## Notifications
- `GET /notifications` — List for current user
- `POST /notifications/{id}/read` — Mark read

## Notification Preferences (all authenticated)
- `GET /notification-preferences` — List current user's channel toggles per event
- `PUT /notification-preferences` — Update `{preferences: [{channel, event_type, enabled}]}` — channels: dashboard, email, whatsapp; event_types: order_submitted, order_approved, order_received, variance_confirmation, shipment_finalized

## Shipment Drafts (extended)
- `PUT /shipment-drafts/{id}` — Update carrier refs `{container_number?, booking_number?, tracking_url?}`
- `POST /shipment-drafts/{id}/documents` — Add document `{file_path, doc_type}` — doc_type: bol, booking_confirmation, invoice, other
- `POST /shipment-drafts/{id}/remove-document` — Remove `{document_id}`

## Containers
- `GET /containers` — List all — **SuperAdmin only**
- `POST /containers` — Create `{code, max_cbm, max_weight}` — **SuperAdmin only**

## Shipment Drafts
- `GET /shipment-drafts` — List all (includes push_status, push_last_error)
- `GET /shipment-drafts/{id}` — Get one
- `POST /shipment-drafts` — Create new draft
- `POST /shipment-drafts/{id}/add-orders` — Add orders `{order_ids: []}`
- `POST /shipment-drafts/{id}/remove-orders` — Remove orders `{order_ids: []}`
- `POST /shipment-drafts/{id}/assign-container` — Assign `{container_id}`
- `POST /shipment-drafts/{id}/finalize` — Finalize locally; if TRACKING_PUSH_ENABLED=1, attempt push. Decision B: finalize always succeeds; push can fail and be retried. **Roles:** LebanonAdmin, SuperAdmin
- `POST /shipment-drafts/{id}/push` — Retry push to tracking. **Roles:** LebanonAdmin, SuperAdmin

## Tracking Push Log
- `GET /tracking-push-log?entity_type=shipment_draft&entity_id=&failed_only=1` — List push attempts (last 50). **Roles:** LebanonAdmin, SuperAdmin

## Users (SuperAdmin only)
- `GET /users` — List all users with roles
- `GET /users/{id}` — Get one user with roles and departments
- `GET /users/{id}/activity?entity_type=&action=&date_from=&date_to=&limit=&offset=` — User activity: audit_log entries, customer_confirmations (admin confirmations), and created_by/uploaded_by synthetic events (orders, expenses, procurement_drafts, order_templates, customer_deposits, supplier_interactions, customer_portal_tokens, design_attachments). Filters apply to all sources.
- `POST /users` — Create user. Body: `{email, full_name, password, roles[], department_ids?}`. At least one role required.
- `PUT /users/{id}` — Update roles, departments, is_active
- `POST /users/{id}/reset-password` — Reset password. Body: `{password}` (min 6 chars). Returns `{new_password}` once for sharing with user.

## Diagnostics (SuperAdmin only)
- `GET /diagnostics/notification-delivery-log` — List delivery log rows (filters: status, channel, date_from, date_to; limit, offset). Never returns tokens.
- `GET /diagnostics/config-health` — Config readiness: `email_configured`, `whatsapp_configured`, `item_level_enabled`, `retry_configured`
- `POST /diagnostics/retry-delivery/{logId}` — Retry failed email/WhatsApp delivery (skips if payload_hash already succeeded)

## Expenses (ChinaAdmin, LebanonAdmin, SuperAdmin)
- `GET /expenses` — List expenses with filters `?date_from=&date_to=&category_id=&order_id=&container_id=&customer_id=&supplier_id=&q=&limit=&offset=`
- `GET /expenses/categories` — List active categories (optional `?q=` for search)
- `GET /expenses/payee-suggestions?q=` — Payee autocomplete (expenses + suppliers)
- `GET /expenses/{id}` — Get one expense
- `POST /expenses` — Create `{category_id?, category_name?, amount, currency?, expense_date?, payee?, notes?, order_id?, container_id?, customer_id?, supplier_id?}` — Use `category_id` or `category_name`; if `category_name` is provided and no category is selected, a new category is created automatically
- `PUT /expenses/{id}` — Update (same fields as POST; `category_name` creates category when `category_id` is 0)
- `DELETE /expenses/{id}` — Delete

## Config
- `GET /config` — Get system config (SuperAdmin only; tokens masked as ********)
- `GET /config/receiving` — Get receiving config `{item_level_receiving_enabled}` (WarehouseStaff, SuperAdmin)

## Receiving (WarehouseStaff, SuperAdmin)
- `GET /receiving/search?q=` — Search orders by order ID, customer/supplier name, customer/supplier phone, shipping code. Returns Approved/InTransit orders (limit 30).
- `GET /receiving/queue?status=&customer_id=&supplier_id=&order_id=&date_from=&date_to=&shipping_code=` — List pending orders for receiving

## Customer Confirmation (Public — no auth)
- `GET /confirm?token=` — Fetch order summary for a pending confirmation (returns order details, actual measurements, photos)
- `POST /confirm` — Body: `{token}` — Confirm the order via token. Token is single-use and cleared after confirmation. Used by `confirm.php` public page.

Note: Confirmation tokens are generated when an order moves to `AwaitingCustomerConfirmation` status during warehouse receiving. Token is included in the variance notification body. Admin staff can also confirm via `POST /orders/{id}/confirm` using their session.
- `PUT /config` — Update `{config: {...}}` (SuperAdmin only). Keys: VARIANCE_THRESHOLD_PERCENT, VARIANCE_THRESHOLD_ABS_CBM, CUSTOMER_PHOTO_VISIBILITY, EMAIL_FROM_ADDRESS, EMAIL_FROM_NAME, WHATSAPP_PROVIDER (generic|twilio), WHATSAPP_API_URL, WHATSAPP_API_TOKEN, WHATSAPP_TWILIO_ACCOUNT_SID, WHATSAPP_TWILIO_AUTH_TOKEN, WHATSAPP_TWILIO_FROM, WHATSAPP_TWILIO_TO, ITEM_LEVEL_RECEIVING_ENABLED, PHOTO_EVIDENCE_PER_ITEM, NOTIFICATION_MAX_ATTEMPTS (1–10), NOTIFICATION_RETRY_SECONDS (1–3600), TRACKING_*, ...

## RBAC
- **Approve orders:** ChinaAdmin, LebanonAdmin, SuperAdmin
- **Receive orders:** WarehouseStaff, SuperAdmin
- **Draft orders builder + API:** ChinaAdmin, ChinaEmployee, SuperAdmin
- **Containers, Users, Config:** SuperAdmin
