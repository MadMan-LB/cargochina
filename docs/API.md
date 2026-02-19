# CLMS API Reference

Base URL: `/api/v1/`

## Customers
- `GET /customers` — List all
- `GET /customers/{id}` — Get one
- `POST /customers` — Create `{code, name, contacts?, addresses?, payment_terms?}`
- `PUT /customers/{id}` — Update
- `DELETE /customers/{id}` — Delete

## Suppliers
- `GET /suppliers` — List all
- `GET /suppliers/{id}` — Get one
- `POST /suppliers` — Create `{code, name, contacts?, factory_location?, notes?}`
- `PUT /suppliers/{id}` — Update
- `DELETE /suppliers/{id}` — Delete

## Products
- `GET /products` — List all
- `GET /products/{id}` — Get one
- `GET /products/suggest?q=...` — Suggest by description/HS code
- `POST /products` — Create `{supplier_id?, cbm, weight, packaging?, hs_code?, description_cn?, description_en?}`
- `PUT /products/{id}` — Update
- `DELETE /products/{id}` — Delete

## Translations
- `POST /translations` — Lookup/translate `{text, source_lang?, target_lang?}` — returns `{translated, cached}`

## Orders
- `GET /orders?status=&customer_id=` — List (optional filters)
- `GET /orders/{id}` — Get one with items and attachments
- `POST /orders` — Create `{customer_id, supplier_id, expected_ready_date, items: [{product_id?, quantity, unit, declared_cbm, declared_weight, description_cn?, description_en?}]}`
- `PUT /orders/{id}` — Update (Draft only)
- `POST /orders/{id}/submit` — Draft → Submitted
- `POST /orders/{id}/approve` — Submitted → Approved
- `POST /orders/{id}/receive` — Record receipt `{actual_cartons, actual_cbm, actual_weight, condition, notes?, photo_paths?}`
- `POST /orders/{id}/confirm` — AwaitingCustomerConfirmation → Confirmed

## Upload
- `POST /upload` — Multipart form `file` — returns `{path}`

## Notifications
- `GET /notifications` — List for current user
- `POST /notifications/{id}/read` — Mark read

## Containers
- `GET /containers` — List all
- `POST /containers` — Create `{code, max_cbm, max_weight}`

## Shipment Drafts
- `GET /shipment-drafts` — List all
- `GET /shipment-drafts/{id}` — Get one
- `POST /shipment-drafts` — Create new draft
- `POST /shipment-drafts/{id}/add-orders` — Add orders `{order_ids: []}`
- `POST /shipment-drafts/{id}/assign-container` — Assign `{container_id}`
- `POST /shipment-drafts/{id}/finalize` — Finalize and push to tracking
