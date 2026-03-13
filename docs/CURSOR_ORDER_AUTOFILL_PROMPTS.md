# Cursor Prompts: Orders Autofill, Speed, and UX

## 1. Lock in the order modal regressions

```
Review the CLMS order modal flow and fix any remaining regressions around modal state reset.

Focus on:
- `frontend/js/orders.js`
- `frontend/js/autocomplete.js`

Requirements:
- Opening a brand-new order must fully reset stale autocomplete selections and item counters.
- New item rows must not inherit customer name/code into `item_no` or `shipping_code`.
- Selecting the top-level supplier should fill only blank item supplier rows, without overwriting rows that already have a supplier.
- Keep the UX simple and predictable.

After changes:
- Explain the root cause of each bug.
- List the exact functions changed.
- Add a short manual regression checklist.
```

## 2. Fix per-unit vs total weight consistently

```
Audit the CLMS order item weight logic for consistency between products, templates, copies, edits, totals, and saved payloads.

Focus on:
- `frontend/js/orders.js`
- `backend/api/handlers/orders.php`
- `backend/api/handlers/order-templates.php`

Goals:
- Product master data should remain per-unit where intended.
- Order item forms should display per-unit weight, not total line weight.
- Copying an order, editing a draft, and loading a template must not multiply weight twice.
- If there is ambiguity between per-carton, per-piece, and total values, document it and normalize it.

Deliver:
- A code fix
- A short note describing the final weight contract for products, order forms, and stored order items
- A small regression test plan
```

## 3. Make the orders list faster

```
Optimize the orders list API and page rendering for scale.

Focus on:
- `backend/api/handlers/orders.php`
- `frontend/js/orders.js`

Current problem to investigate:
- The orders list endpoint appears to fetch full item arrays per order, which can create N+1 queries and large payloads.

Goals:
- Keep the table fast with many orders.
- Preserve multi-supplier display in the list.
- Only fetch full item detail when the UI truly needs it.

Please:
- Propose the simplest safe backend shape change
- Implement it
- Update the frontend to use the lighter response
- Summarize the before/after query and payload behavior
```

## 4. Make missing item photos degrade gracefully

```
Improve the CLMS order item photo UX when saved image paths no longer exist on disk.

Focus on:
- `frontend/js/orders.js`
- any shared CSS file if needed

Requirements:
- Broken image icons should not make the order modal look corrupted.
- If an image file is missing, show a small non-blocking placeholder like “Missing file”.
- Do not break existing remove-photo behavior.
- Keep the UI compact.

After the change, give:
- The exact UX behavior for valid photos vs missing photos
- Any console/network limitations that will still remain
```

## 5. Make product backfill safer

```
Review the server-side product backfill behavior that updates product master data from order item edits.

Focus on:
- `backend/api/handlers/orders.php`
- any related product handler or docs

What to evaluate:
- Whether order edits should always overwrite product `description`, `unit_price`, `weight`, `cbm`, dimensions, and `pieces_per_carton`
- Risk of shipment-specific data polluting shared product master data

Please:
- Recommend a safer strategy
- Implement one of these options:
  1. only fill missing product fields
  2. require an explicit opt-in flag
  3. log a pending suggestion instead of overwriting directly

Include:
- tradeoffs
- migration impact if any
- the exact behavior after your change
```

## 6. Add browser regression coverage

```
Create a lightweight browser regression plan for the CLMS orders flow using the existing local tooling.

Cover:
- new order modal opens cleanly
- item numbering resets to Item #1
- top-level supplier fills blank item suppliers
- product autocomplete fills supplier, price, CBM, weight, and dimensions
- template save/load preserves per-item supplier
- template load, copy, and edit do not inflate total weight
- finance modal still opens correctly

Do not add a huge framework if one is not already used.
Prefer the lightest repeatable approach and document how to run it locally.
```
