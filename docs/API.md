# CLMS API Reference

Base URL: `/cargochina/api/v1/` (or `/api/v1/` if at document root)

**Authentication:** Session-based. Login via `POST /auth/login`. All endpoints except `auth` require an active session.

## Authentication
- `POST /auth/login` — `{email, password}` — Returns `{user_id, name, roles}`. **Public.**
- `POST /auth/logout` — Destroys session. **Public.**

## Customers
- `GET /customers` — List all
- `GET /customers/{id}` — Get one
- `POST /customers` — Create `{code, name, contacts?, addresses?, payment_terms?}`
- `PUT /customers/{id}` — Update
- `DELETE /customers/{id}` — Delete

## Suppliers
- `GET /suppliers` — List all
- `GET /suppliers/{id}` — Get one
- `POST /suppliers` — Create `{code, store_id?, name, phone?, contacts?, factory_location?, notes?, additional_ids?}` — store_id: official China store identifier
- `POST /suppliers/{id}/payments` — Add payment `{amount, currency?, payment_type?, order_id?, notes?}`
- `POST /suppliers/{id}/interactions` — Add interaction `{interaction_type?, content?}` — type: visit/quote/note
- `PUT /suppliers/{id}` — Update
- `DELETE /suppliers/{id}` — Delete

## Products
- `GET /products` — List all
- `GET /products/{id}` — Get one
- `GET /products/suggest?q=...` — Suggest by description/HS code (with similarity score)
- `POST /products` — Create `{supplier_id?, cbm, weight, packaging?, hs_code?, description_cn?, description_en?, image_paths?, force_create?}` — image_paths: array of paths from upload
- `GET /products` — Returns `thumbnail_url` (first image) per product
- `PUT /products/{id}` — Update
- `DELETE /products/{id}` — Delete

## Translations
- `POST /translations` — Lookup/translate `{text, source_lang?, target_lang?}` — returns `{translated, cached}`
- `POST /translate` — Translate via TranslationService `{text, source_lang?, target_lang?}` — returns `{translated}`

## Orders
- `GET /orders?status=&customer_id=` — List (optional filters)
- `GET /orders/{id}` — Get one with items and attachments
- `POST /orders` — Create `{customer_id, supplier_id, expected_ready_date, items}` — items: product_id?, item_no?, shipping_code?, cartons?, qty_per_carton?, quantity, unit, declared_cbm, declared_weight, unit_price?, total_amount?, notes?, image_paths?, description_cn?, description_en? — Submit requires min 1 photo per item (configurable)
- `PUT /orders/{id}` — Update (Draft only)
- `POST /orders/{id}/submit` — Draft → Submitted
- `POST /orders/{id}/approve` — Submitted → Approved
- `POST /orders/{id}/receive` — Record receipt `{actual_cartons, actual_cbm, actual_weight, condition, notes?, photo_paths?}`
- `POST /orders/{id}/confirm` — AwaitingCustomerConfirmation → Confirmed

## Upload
- `POST /upload` — Multipart form `file` — returns `{path, url}` — path for storage; url for img src

## Notifications
- `GET /notifications` — List for current user
- `POST /notifications/{id}/read` — Mark read

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

## Config (SuperAdmin only)
- `GET /config` — Get system config (token masked as ********)
- `PUT /config` — Update `{config: {VARIANCE_THRESHOLD_PERCENT?, TRACKING_API_BASE_URL?, TRACKING_API_TOKEN?, TRACKING_PUSH_ENABLED?, TRACKING_PUSH_DRY_RUN?, ...}}`

## RBAC
- **Approve orders:** ChinaAdmin, LebanonAdmin, SuperAdmin
- **Receive orders:** WarehouseStaff, SuperAdmin
- **Containers, Users, Config:** SuperAdmin
