# Codex: Order Export & Template Alignment Test Prompt

Use this prompt with Codex to test the order export feature and suggest improvements.

---

## Prompt for Codex

```
Test the CLMS order export feature and Template.xlsx alignment:

1. **Setup**
   - Run migration 029: `php backend/migrations/run.php` (or apply `backend/migrations/029_supplier_address_fax.sql` manually)
   - Ensure you have at least one order with items, and the order's supplier has name, address (or factory_location), phone, and optionally fax filled in

2. **Export flow**
   - Go to Orders page
   - Click "Export" on an order row
   - Verify the downloaded CSV opens in Excel and matches the Template.xlsx structure:
     - Row 1: Company name (supplier, uppercase)
     - Row 2: ADDRESS: ... (supplier address or factory_location)
     - Row 3: TEL: ... FAX: ...
     - Row 4: GOOD DETAILS
     - Row 5: Headers — PHOTO, ITEM NO, DESCRIPTION, TOTAL CTNS, QTY/CTN, TOTAL QTY, UNIT PRICE, TOTAL AMOUNT, CBM, TOTAL CBM, GWKG, TOTAL GW (plus SUPPLIER if multi-supplier)
     - Row 6+: Item data

3. **Verify**
   - Photo column shows "N photo(s)" when item has images
   - Numeric columns (cartons, qty, price, CBM, weight) are correct
   - Multi-supplier orders include SUPPLIER column per item
   - UTF-8 BOM present so Excel shows Chinese correctly

4. **Ideas to consider**
   - Should we add PhpSpreadsheet for true .xlsx output instead of CSV?
   - Should the export include embedded product images in the PHOTO column?
   - Should we support batch export (multiple orders in one file)?
   - Any layout/column order changes to better match Template.xlsx?
   - Should customer info appear in the export (e.g. for packing slips)?
```

---

## Reference: Template.xlsx structure

| Row | Content |
|-----|---------|
| 1 | Company name (supplier) |
| 2 | ADDRESS: full address |
| 3 | TEL: xxx FAX: xxx |
| 4 | GOOD DETAILS |
| 5 | PHOTO \| ITEM NO \| DESCRIPTION \| TOTAL CTNS \| QTY/CTN \| TOTAL QTY \| UNIT PRICE \| TOTAL AMOUNT \| CBM \| TOTAL CBM \| GWKG \| TOTAL GW |
| 6+ | Item rows |
