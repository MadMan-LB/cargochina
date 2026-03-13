#!/usr/bin/env python3
"""
Seed CLMS consolidation/export QA dataset.

Creates:
- 3 containers (20HQ / 40HQ / 45HQ style capacities)
- 36 orders (12 per container) with fully populated order_items
- image_paths that point to real files under backend/uploads/codex_seed/
- shipment drafts, draft-order links, container assignment, and finalized statuses

Idempotent by seed tag. Re-running removes prior seed-tagged records first.
"""

from __future__ import annotations

import argparse
import base64
import json
import subprocess
from dataclasses import dataclass
from datetime import date, timedelta
from pathlib import Path
from typing import Iterable


def sql_escape(value: str) -> str:
    return value.replace("\\", "\\\\").replace("'", "''")


@dataclass
class DbClient:
    mysql_path: Path
    db_name: str
    db_user: str
    db_pass: str

    def run(self, sql: str, batch: bool = True) -> list[str]:
        args = [
            str(self.mysql_path),
            "--default-character-set=utf8mb4",
            "-u",
            self.db_user,
            "-D",
            self.db_name,
        ]
        if self.db_pass:
            args.append(f"-p{self.db_pass}")
        if batch:
            args.extend(["--batch", "--skip-column-names"])
        args.extend(["-e", sql])
        proc = subprocess.run(args, capture_output=True, text=True)
        if proc.returncode != 0:
            raise RuntimeError(
                f"MySQL command failed\nSQL:\n{sql}\nSTDERR:\n{proc.stderr}\nSTDOUT:\n{proc.stdout}"
            )
        out = proc.stdout.strip()
        if not out:
            return []
        return [line.strip() for line in out.splitlines() if line.strip()]

    def scalar(self, sql: str) -> str | None:
        rows = self.run(sql, batch=True)
        return rows[-1] if rows else None


def write_placeholder_images(root_dir: Path) -> tuple[str, str, str]:
    upload_dir = root_dir / "backend" / "uploads" / "codex_seed"
    upload_dir.mkdir(parents=True, exist_ok=True)

    # Valid tiny PNG (1x1). Using multiple filenames is enough for export drawing paths.
    png_b64 = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+X2ioAAAAASUVORK5CYII="
    payload = base64.b64decode(png_b64)

    files = ["box-blue.png", "box-red.png", "box-green.png"]
    for name in files:
        target = upload_dir / name
        if not target.exists():
            target.write_bytes(payload)

    return (
        "uploads/codex_seed/box-blue.png",
        "uploads/codex_seed/box-red.png",
        "uploads/codex_seed/box-green.png",
    )


def ensure_supplier_columns(db: DbClient) -> None:
    has_address = int(
        db.scalar(
            "SELECT COUNT(*) FROM information_schema.columns "
            "WHERE table_schema = DATABASE() AND table_name = 'suppliers' AND column_name = 'address';"
        )
        or 0
    )
    has_fax = int(
        db.scalar(
            "SELECT COUNT(*) FROM information_schema.columns "
            "WHERE table_schema = DATABASE() AND table_name = 'suppliers' AND column_name = 'fax';"
        )
        or 0
    )
    if has_address == 0:
        db.run("ALTER TABLE suppliers ADD COLUMN address TEXT NULL AFTER factory_location;", batch=False)
    if has_fax == 0:
        db.run("ALTER TABLE suppliers ADD COLUMN fax VARCHAR(50) NULL AFTER phone;", batch=False)


def upsert_customers(db: DbClient, seed_tag: str) -> list[dict]:
    customers = [
        {"code": f"{seed_tag}_C01", "name": "Codex Beirut Retail", "phone": "+961-1-111111", "address": "Beirut, Lebanon", "terms": "Net 30"},
        {"code": f"{seed_tag}_C02", "name": "Codex Mount Lebanon", "phone": "+961-1-222222", "address": "Baabda, Lebanon", "terms": "Net 30"},
        {"code": f"{seed_tag}_C03", "name": "Codex North Hub", "phone": "+961-6-333333", "address": "Tripoli, Lebanon", "terms": "Net 45"},
        {"code": f"{seed_tag}_C04", "name": "Codex South Trade", "phone": "+961-7-444444", "address": "Saida, Lebanon", "terms": "Cash"},
        {"code": f"{seed_tag}_C05", "name": "Codex Bekaa Market", "phone": "+961-8-555555", "address": "Zahle, Lebanon", "terms": "Net 30"},
        {"code": f"{seed_tag}_C06", "name": "Codex Wholesale Group", "phone": "+961-1-666666", "address": "Sin El Fil, Lebanon", "terms": "Net 60"},
    ]
    for c in customers:
        db.run(
            "INSERT INTO customers (code, name, phone, address, payment_terms) "
            f"VALUES ('{sql_escape(c['code'])}', '{sql_escape(c['name'])}', '{sql_escape(c['phone'])}', "
            f"'{sql_escape(c['address'])}', '{sql_escape(c['terms'])}') "
            "ON DUPLICATE KEY UPDATE "
            "name=VALUES(name), phone=VALUES(phone), address=VALUES(address), payment_terms=VALUES(payment_terms);",
            batch=False,
        )
    return customers


def upsert_suppliers(db: DbClient, seed_tag: str) -> list[dict]:
    suppliers = [
        {"code": f"{seed_tag}_S01", "store_id": "SEED-ST-01", "name": "Yiwu Master Packaging", "phone": "+86-579-85170001", "fax": "+86-579-85171001", "factory": "Yiwu, Zhejiang", "address": "No.1 Jiangdong Street, Yiwu, Zhejiang, China"},
        {"code": f"{seed_tag}_S02", "store_id": "SEED-ST-02", "name": "Guangzhou Home Goods", "phone": "+86-20-81230002", "fax": "+86-20-81231002", "factory": "Guangzhou, Guangdong", "address": "Baiyun District, Guangzhou, Guangdong, China"},
        {"code": f"{seed_tag}_S03", "store_id": "SEED-ST-03", "name": "Shenzhen Light Electronics", "phone": "+86-755-88660003", "fax": "+86-755-88661003", "factory": "Shenzhen, Guangdong", "address": "Nanshan District, Shenzhen, Guangdong, China"},
        {"code": f"{seed_tag}_S04", "store_id": "SEED-ST-04", "name": "Ningbo Textile Source", "phone": "+86-574-77330004", "fax": "+86-574-77331004", "factory": "Ningbo, Zhejiang", "address": "Yinzhou District, Ningbo, Zhejiang, China"},
        {"code": f"{seed_tag}_S05", "store_id": "SEED-ST-05", "name": "Foshan Metal Works", "phone": "+86-757-66770005", "fax": "+86-757-66771005", "factory": "Foshan, Guangdong", "address": "Shunde District, Foshan, Guangdong, China"},
        {"code": f"{seed_tag}_S06", "store_id": "SEED-ST-06", "name": "Hangzhou Daily Use", "phone": "+86-571-99880006", "fax": "+86-571-99881006", "factory": "Hangzhou, Zhejiang", "address": "Xiaoshan District, Hangzhou, Zhejiang, China"},
    ]
    for s in suppliers:
        db.run(
            "INSERT INTO suppliers (code, store_id, name, phone, fax, factory_location, address, notes) "
            f"VALUES ('{sql_escape(s['code'])}', '{sql_escape(s['store_id'])}', '{sql_escape(s['name'])}', "
            f"'{sql_escape(s['phone'])}', '{sql_escape(s['fax'])}', '{sql_escape(s['factory'])}', "
            f"'{sql_escape(s['address'])}', 'Codex seeded for export QA') "
            "ON DUPLICATE KEY UPDATE "
            "store_id=VALUES(store_id), name=VALUES(name), phone=VALUES(phone), fax=VALUES(fax), "
            "factory_location=VALUES(factory_location), address=VALUES(address), notes=VALUES(notes);",
            batch=False,
        )
    return suppliers


def cleanup_existing_seed_data(db: DbClient, seed_tag: str) -> None:
    db.run(
        "DELETE FROM shipment_drafts "
        f"WHERE booking_number LIKE '{sql_escape(seed_tag)}-BOOK-%' "
        f"OR container_number LIKE '{sql_escape(seed_tag)}-CONT-%';",
        batch=False,
    )

    order_ids = db.run(
        "SELECT DISTINCT oi.order_id FROM order_items oi "
        f"WHERE oi.shipping_code LIKE '{sql_escape(seed_tag)}-%';"
    )
    if order_ids:
        order_csv = ",".join(order_ids)
        db.run(f"DELETE FROM shipment_draft_orders WHERE order_id IN ({order_csv});", batch=False)
        db.run(f"DELETE FROM order_attachments WHERE order_id IN ({order_csv});", batch=False)
        db.run(f"DELETE FROM order_items WHERE order_id IN ({order_csv});", batch=False)
        db.run(f"DELETE FROM orders WHERE id IN ({order_csv});", batch=False)


def upsert_containers(db: DbClient, seed_tag: str) -> list[dict]:
    containers = [
        {"code": f"{seed_tag}-20HQ", "max_cbm": 33.2, "max_weight": 28200.0},
        {"code": f"{seed_tag}-40HQ", "max_cbm": 67.7, "max_weight": 26800.0},
        {"code": f"{seed_tag}-45HQ", "max_cbm": 86.0, "max_weight": 29500.0},
    ]
    for c in containers:
        db.run(
            "INSERT INTO containers (code, max_cbm, max_weight, status) "
            f"VALUES ('{sql_escape(c['code'])}', {c['max_cbm']:.4f}, {c['max_weight']:.4f}, 'available') "
            "ON DUPLICATE KEY UPDATE max_cbm=VALUES(max_cbm), max_weight=VALUES(max_weight), status=VALUES(status);",
            batch=False,
        )
    return containers


def fetch_ids_by_code(db: DbClient, table: str, codes: Iterable[str]) -> dict[str, int]:
    values = ",".join(f"'{sql_escape(c)}'" for c in codes)
    rows = db.run(f"SELECT id, code FROM {table} WHERE code IN ({values}) ORDER BY id;")
    result: dict[str, int] = {}
    for row in rows:
        rid, code = row.split("\t")
        result[code] = int(rid)
    return result


def create_orders_and_items(
    db: DbClient,
    seed_tag: str,
    customer_ids: list[int],
    supplier_ids: list[int],
    created_by: int,
    image_paths: tuple[str, str, str],
) -> list[list[int]]:
    img_a, img_b, img_c = image_paths
    total_orders = 36
    orders_per_container = 12
    group_order_ids: list[list[int]] = [[], [], []]

    for i in range(1, total_orders + 1):
        group = (i - 1) // orders_per_container
        customer_id = customer_ids[(i - 1) % len(customer_ids)]
        supplier_primary = supplier_ids[(i - 1) % len(supplier_ids)]
        supplier_alt = supplier_ids[i % len(supplier_ids)]
        expected_ready = (date.today() + timedelta(days=5 + (i % 18))).isoformat()
        status = "ReadyForConsolidation" if i % 2 == 0 else "Confirmed"

        order_id = int(
            db.scalar(
                "INSERT INTO orders (customer_id, supplier_id, expected_ready_date, currency, status, created_by) "
                f"VALUES ({customer_id}, {supplier_primary}, '{expected_ready}', 'USD', '{status}', {created_by}); "
                "SELECT LAST_INSERT_ID();"
            )
            or 0
        )
        if order_id <= 0:
            raise RuntimeError(f"Failed to create order at index {i}")

        shipping_code = f"{seed_tag}-{i:03d}"

        cartons_1 = 7 + (i % 5)
        qty_ctn_1 = 12 + ((i * 3) % 7)
        quantity_1 = cartons_1 * qty_ctn_1
        unit_price_1 = 2.75 + ((i % 4) * 0.25)
        total_1 = quantity_1 * unit_price_1
        cbm_1 = cartons_1 * 0.065
        weight_1 = quantity_1 * 0.165
        desc_en_1 = f"Seed Product {i} A"
        desc_cn_1 = f"测试产品{i} A"
        images_1 = json.dumps([img_a, img_b], ensure_ascii=False)

        db.run(
            "INSERT INTO order_items "
            "(order_id, product_id, supplier_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, "
            "declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en) "
            "VALUES "
            f"({order_id}, NULL, {supplier_primary}, 'SEED-ITM-{i:03d}-A', '{sql_escape(shipping_code)}', "
            f"{cartons_1}, {qty_ctn_1:.4f}, {quantity_1:.4f}, 'pieces', {cbm_1:.4f}, {weight_1:.4f}, "
            f"45.0000, 32.0000, 28.0000, {unit_price_1:.4f}, {total_1:.4f}, 'Seeded item A', "
            f"'{sql_escape(images_1)}', '{sql_escape(desc_cn_1)}', '{sql_escape(desc_en_1)}');",
            batch=False,
        )

        cartons_2 = 5 + (i % 4)
        qty_ctn_2 = 10 + ((i * 5) % 6)
        quantity_2 = cartons_2 * qty_ctn_2
        unit_price_2 = 3.10 + ((i % 5) * 0.20)
        total_2 = quantity_2 * unit_price_2
        cbm_2 = cartons_2 * 0.055
        weight_2 = quantity_2 * 0.145
        supplier_2 = supplier_alt if i % 3 == 0 else supplier_primary
        desc_en_2 = f"Seed Product {i} B"
        desc_cn_2 = f"测试产品{i} B"
        images_2 = json.dumps([img_c], ensure_ascii=False)

        db.run(
            "INSERT INTO order_items "
            "(order_id, product_id, supplier_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, "
            "declared_cbm, declared_weight, item_length, item_width, item_height, unit_price, total_amount, notes, image_paths, description_cn, description_en) "
            "VALUES "
            f"({order_id}, NULL, {supplier_2}, 'SEED-ITM-{i:03d}-B', '{sql_escape(shipping_code)}', "
            f"{cartons_2}, {qty_ctn_2:.4f}, {quantity_2:.4f}, 'pieces', {cbm_2:.4f}, {weight_2:.4f}, "
            f"38.0000, 26.0000, 24.0000, {unit_price_2:.4f}, {total_2:.4f}, 'Seeded item B', "
            f"'{sql_escape(images_2)}', '{sql_escape(desc_cn_2)}', '{sql_escape(desc_en_2)}');",
            batch=False,
        )

        group_order_ids[group].append(order_id)

    return group_order_ids


def assign_and_finalize(
    db: DbClient,
    seed_tag: str,
    container_defs: list[dict],
    container_id_by_code: dict[str, int],
    group_order_ids: list[list[int]],
) -> list[int]:
    draft_ids: list[int] = []
    for idx, group_orders in enumerate(group_order_ids):
        container = container_defs[idx]
        container_code = container["code"]
        container_id = container_id_by_code[container_code]
        order_csv = ",".join(str(o) for o in group_orders)

        totals_rows = db.run(
            "SELECT ROUND(COALESCE(SUM(declared_cbm),0),4), ROUND(COALESCE(SUM(declared_weight),0),4) "
            f"FROM order_items WHERE order_id IN ({order_csv});"
        )
        if not totals_rows:
            raise RuntimeError(f"Missing item totals for container group {idx}")
        total_cbm, total_weight = [float(x) for x in totals_rows[0].split("\t")]
        if total_cbm > float(container["max_cbm"]) or total_weight > float(container["max_weight"]):
            raise RuntimeError(
                f"Capacity exceeded for {container_code}: cbm={total_cbm}, weight={total_weight}"
            )

        booking_number = f"{seed_tag}-BOOK-{idx + 1:02d}"
        container_number = f"{seed_tag}-CONT-{idx + 1:02d}"
        tracking_url = f"https://tracking.local/{seed_tag}/{idx + 1}"

        draft_id = int(
            db.scalar(
                "INSERT INTO shipment_drafts (container_id, container_number, booking_number, tracking_url, status) "
                f"VALUES ({container_id}, '{sql_escape(container_number)}', '{sql_escape(booking_number)}', "
                f"'{sql_escape(tracking_url)}', 'draft'); "
                "SELECT LAST_INSERT_ID();"
            )
            or 0
        )
        if draft_id <= 0:
            raise RuntimeError(f"Failed to create shipment draft for {container_code}")
        draft_ids.append(draft_id)

        values = ",".join(f"({draft_id},{oid})" for oid in group_orders)
        db.run(
            f"INSERT INTO shipment_draft_orders (shipment_draft_id, order_id) VALUES {values};",
            batch=False,
        )
        db.run(f"UPDATE orders SET status = 'ConsolidatedIntoShipmentDraft' WHERE id IN ({order_csv});", batch=False)
        db.run(f"UPDATE orders SET status = 'AssignedToContainer' WHERE id IN ({order_csv});", batch=False)
        db.run(f"UPDATE shipment_drafts SET status = 'finalized' WHERE id = {draft_id};", batch=False)
        db.run(f"UPDATE orders SET status = 'FinalizedAndPushedToTracking' WHERE id IN ({order_csv});", batch=False)

    return draft_ids


def main() -> None:
    parser = argparse.ArgumentParser(description="Seed CLMS consolidation/export QA data.")
    parser.add_argument("--mysql-path", default="C:/xampp/mysql/bin/mysql.exe")
    parser.add_argument("--db-name", default="clms")
    parser.add_argument("--db-user", default="root")
    parser.add_argument("--db-pass", default="")
    parser.add_argument("--seed-tag", default="CODX26")
    args = parser.parse_args()

    root_dir = Path(__file__).resolve().parents[1]
    output_dir = root_dir / "output"
    output_dir.mkdir(parents=True, exist_ok=True)

    db = DbClient(
        mysql_path=Path(args.mysql_path),
        db_name=args.db_name,
        db_user=args.db_user,
        db_pass=args.db_pass,
    )

    print("Writing placeholder image files...")
    image_paths = write_placeholder_images(root_dir)

    print("Ensuring supplier address/fax columns (migration 029 scope)...")
    ensure_supplier_columns(db)

    print("Upserting seed customers/suppliers...")
    customers = upsert_customers(db, args.seed_tag)
    suppliers = upsert_suppliers(db, args.seed_tag)

    print("Cleaning previous seed-tagged dataset...")
    cleanup_existing_seed_data(db, args.seed_tag)

    print("Upserting target containers...")
    container_defs = upsert_containers(db, args.seed_tag)

    customer_id_map = fetch_ids_by_code(db, "customers", [c["code"] for c in customers])
    supplier_id_map = fetch_ids_by_code(db, "suppliers", [s["code"] for s in suppliers])
    container_id_map = fetch_ids_by_code(db, "containers", [c["code"] for c in container_defs])

    customer_ids = [customer_id_map[c["code"]] for c in customers]
    supplier_ids = [supplier_id_map[s["code"]] for s in suppliers]
    created_by = int(db.scalar("SELECT id FROM users WHERE email = 'admin@salameh.com' LIMIT 1;") or "0")
    if created_by <= 0:
        created_by = int(db.scalar("SELECT MIN(id) FROM users;") or "0")
    if created_by <= 0:
        raise RuntimeError("No users found; cannot seed orders.")

    print("Creating 36 orders with populated items...")
    group_order_ids = create_orders_and_items(
        db=db,
        seed_tag=args.seed_tag,
        customer_ids=customer_ids,
        supplier_ids=supplier_ids,
        created_by=created_by,
        image_paths=image_paths,
    )

    print("Creating shipment drafts, assigning containers, and finalizing...")
    draft_ids = assign_and_finalize(
        db=db,
        seed_tag=args.seed_tag,
        container_defs=container_defs,
        container_id_by_code=container_id_map,
        group_order_ids=group_order_ids,
    )

    status_rows = db.run(
        "SELECT status, COUNT(*) FROM orders "
        "WHERE EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = orders.id "
        f"AND oi.shipping_code LIKE '{sql_escape(args.seed_tag)}-%') "
        "GROUP BY status ORDER BY status;"
    )
    sample_order_ids = [group_order_ids[0][0], group_order_ids[1][0], group_order_ids[2][0]]

    summary = {
        "generated_at": date.today().isoformat(),
        "seed_tag": args.seed_tag,
        "container_codes": [c["code"] for c in container_defs],
        "container_ids": container_id_map,
        "shipment_draft_ids": draft_ids,
        "total_seed_orders": 36,
        "orders_per_container": 12,
        "sample_order_ids_for_export": sample_order_ids,
        "seed_order_status_summary": status_rows,
        "image_paths": list(image_paths),
    }
    summary_path = output_dir / "codex_seed_export_summary.json"
    summary_path.write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")

    print("Seed complete.")
    print(f"Summary: {summary_path}")
    print(f"Sample order IDs for export: {', '.join(str(x) for x in sample_order_ids)}")
    print("Status summary:")
    for row in status_rows:
        print(f"  {row}")


if __name__ == "__main__":
    main()
