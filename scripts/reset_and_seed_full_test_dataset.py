#!/usr/bin/env python3
"""
Reset CLMS business data while preserving users/system tables, then seed a rich QA dataset.

What this script does:
- creates a SQL backup via mysqldump
- archives current backend/uploads
- wipes business data tables while preserving users/auth/config/reference data
- recreates fresh seed upload assets
- seeds realistic customers, suppliers, products, orders, receipts, drafts, containers,
  expenses, payments, portal tokens, confirmations, attachments, notifications, and more
- writes a manifest with counts and raw portal/confirmation links for manual QA

Uses only Python stdlib and mysql.exe/mysqldump.exe.
"""

from __future__ import annotations

import argparse
import base64
import hashlib
import json
import os
import random
import re
import shutil
import subprocess
import sys
import zipfile
from dataclasses import dataclass
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Any, Iterable


SEED_TAG = "FULLQA"
APP_BASE_URL = "http://localhost/cargochina"
MYSQL_DEFAULT = Path("C:/xampp/mysql/bin/mysql.exe")
MYSQLDUMP_DEFAULT = Path("C:/xampp/mysql/bin/mysqldump.exe")
PRESERVE_TABLES = {
    "_migrations",
    "users",
    "roles",
    "user_roles",
    "departments",
    "user_departments",
    "user_notification_preferences",
    "system_config",
    "business_settings",
    "countries",
    "expense_categories",
    "hs_code_tariff_catalog",
}
WIPE_TABLES = [
    "container_arrival_notifications",
    "tracking_push_log",
    "notification_delivery_log",
    "notifications",
    "internal_messages",
    "customer_confirmations",
    "customer_portal_tokens",
    "warehouse_receipt_item_photos",
    "warehouse_receipt_photos",
    "warehouse_receipt_items",
    "warehouse_receipts",
    "shipment_draft_documents",
    "shipment_draft_orders",
    "shipment_drafts",
    "order_attachments",
    "design_attachments",
    "procurement_draft_items",
    "procurement_drafts",
    "order_template_items",
    "order_templates",
    "supplier_payments",
    "customer_deposits",
    "expenses",
    "supplier_interactions",
    "order_items",
    "orders",
    "product_description_entries",
    "products",
    "containers",
    "customers",
    "suppliers",
    "hs_code_tax_rates",
    "translations",
    "audit_log",
]


def sql_escape(value: str) -> str:
    return value.replace("\\", "\\\\").replace("'", "''")


def sql_literal(value: Any) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, int):
        return str(value)
    if isinstance(value, float):
        rendered = f"{value:.6f}"
        return rendered.rstrip("0").rstrip(".") if "." in rendered else rendered
    return "'" + sql_escape(str(value)) + "'"


def parse_env(env_path: Path) -> dict[str, str]:
    result: dict[str, str] = {}
    if not env_path.exists():
        return result
    for raw_line in env_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip().strip("'").strip('"')
        result[key.strip()] = value
    return result


def chunked(items: list[Any], size: int) -> Iterable[list[Any]]:
    for idx in range(0, len(items), size):
        yield items[idx : idx + size]


def make_pdf_bytes(text: str) -> bytes:
    safe = text.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")
    stream = f"BT /F1 18 Tf 50 760 Td ({safe}) Tj ET".encode("latin-1", errors="replace")
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>",
        b"<< /Length %d >>\nstream\n%s\nendstream" % (len(stream), stream),
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
    ]
    out = bytearray(b"%PDF-1.4\n")
    offsets = [0]
    for idx, obj in enumerate(objects, start=1):
        offsets.append(len(out))
        out.extend(f"{idx} 0 obj\n".encode("ascii"))
        out.extend(obj)
        out.extend(b"\nendobj\n")
    xref_start = len(out)
    out.extend(f"xref\n0 {len(objects) + 1}\n".encode("ascii"))
    out.extend(b"0000000000 65535 f \n")
    for offset in offsets[1:]:
        out.extend(f"{offset:010d} 00000 n \n".encode("ascii"))
    out.extend(
        (
            f"trailer << /Size {len(objects) + 1} /Root 1 0 R >>\n"
            f"startxref\n{xref_start}\n%%EOF\n"
        ).encode("ascii")
    )
    return bytes(out)


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
        return [line.rstrip("\n") for line in out.splitlines() if line.strip()]

    def scalar(self, sql: str) -> str | None:
        rows = self.run(sql, batch=True)
        return rows[-1] if rows else None

    def insert(self, table: str, data: dict[str, Any]) -> int:
        columns = ", ".join(f"`{k}`" for k in data.keys())
        values = ", ".join(sql_literal(v) for v in data.values())
        sql = f"INSERT INTO `{table}` ({columns}) VALUES ({values}); SELECT LAST_INSERT_ID();"
        return int(self.scalar(sql) or 0)

    def insert_many(self, table: str, rows: list[dict[str, Any]]) -> None:
        if not rows:
            return
        columns = list(rows[0].keys())
        prefix = f"INSERT INTO `{table}` (" + ", ".join(f"`{col}`" for col in columns) + ") VALUES "
        for block in chunked(rows, 100):
            values_sql = []
            for row in block:
                values_sql.append("(" + ", ".join(sql_literal(row[col]) for col in columns) + ")")
            self.run(prefix + ", ".join(values_sql) + ";", batch=False)


def locate_mysql_tools() -> tuple[Path, Path]:
    mysql_path = Path(os.environ.get("MYSQL_PATH", str(MYSQL_DEFAULT)))
    mysqldump_path = Path(os.environ.get("MYSQLDUMP_PATH", str(MYSQLDUMP_DEFAULT)))
    if not mysql_path.exists():
        raise FileNotFoundError(f"MySQL client not found: {mysql_path}")
    if not mysqldump_path.exists():
        raise FileNotFoundError(f"mysqldump not found: {mysqldump_path}")
    return mysql_path, mysqldump_path


def create_backup(root_dir: Path, mysqldump_path: Path, env: dict[str, str]) -> dict[str, str]:
    backup_dir = root_dir / "output" / "reset_seed_backups" / datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_dir.mkdir(parents=True, exist_ok=True)

    db_name = env.get("DB_NAME", "clms")
    db_user = env.get("DB_USER", "root")
    db_pass = env.get("DB_PASS", "")
    dump_path = backup_dir / f"{db_name}_before_full_seed.sql"
    args = [
        str(mysqldump_path),
        "--default-character-set=utf8mb4",
        "-u",
        db_user,
        db_name,
    ]
    if db_pass:
        args.append(f"-p{db_pass}")
    with dump_path.open("wb") as fh:
        proc = subprocess.run(args, stdout=fh, stderr=subprocess.PIPE)
    if proc.returncode != 0:
        raise RuntimeError(f"mysqldump failed: {proc.stderr.decode('utf-8', errors='replace')}")

    uploads_dir = root_dir / "backend" / "uploads"
    uploads_archive = backup_dir / "uploads_before_full_seed.zip"
    with zipfile.ZipFile(uploads_archive, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        if uploads_dir.exists():
            for path in uploads_dir.rglob("*"):
                if path.is_file():
                    zf.write(path, path.relative_to(root_dir))

    return {
        "backup_dir": str(backup_dir),
        "database_dump": str(dump_path),
        "uploads_archive": str(uploads_archive),
    }


def clean_uploads(root_dir: Path) -> None:
    uploads_dir = root_dir / "backend" / "uploads"
    uploads_dir.mkdir(parents=True, exist_ok=True)
    for path in list(uploads_dir.iterdir()):
        if path.name == ".gitkeep":
            continue
        if path.is_dir():
            shutil.rmtree(path)
        else:
            path.unlink()


def write_seed_assets(root_dir: Path) -> dict[str, list[str]]:
    uploads_dir = root_dir / "backend" / "uploads" / "full_test_seed"
    product_dir = uploads_dir / "products"
    order_dir = uploads_dir / "orders"
    receipt_dir = uploads_dir / "receipts"
    doc_dir = uploads_dir / "documents"
    for path in [product_dir, order_dir, receipt_dir, doc_dir]:
        path.mkdir(parents=True, exist_ok=True)

    png_payload = base64.b64decode(
        "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+X2ioAAAAASUVORK5CYII="
    )
    assets: dict[str, list[str]] = {"product_images": [], "order_images": [], "receipt_images": [], "documents": []}
    for idx in range(1, 13):
        target = product_dir / f"product-{idx:02d}.png"
        target.write_bytes(png_payload)
        assets["product_images"].append(f"uploads/full_test_seed/products/{target.name}")
    for idx in range(1, 7):
        target = order_dir / f"order-{idx:02d}.png"
        target.write_bytes(png_payload)
        assets["order_images"].append(f"uploads/full_test_seed/orders/{target.name}")
    for idx in range(1, 7):
        target = receipt_dir / f"receipt-{idx:02d}.png"
        target.write_bytes(png_payload)
        assets["receipt_images"].append(f"uploads/full_test_seed/receipts/{target.name}")
    for idx, title in enumerate(
        [
            "Commercial Invoice",
            "Packing List",
            "Booking Confirmation",
            "Bill Of Lading",
            "Design Brief",
            "Sample Approval",
        ],
        start=1,
    ):
        target = doc_dir / f"document-{idx:02d}.pdf"
        target.write_bytes(make_pdf_bytes(f"{title} - Full QA Seed"))
        assets["documents"].append(f"uploads/full_test_seed/documents/{target.name}")
    return assets


def reset_business_tables(db: DbClient) -> None:
    db.run("SET FOREIGN_KEY_CHECKS=0;", batch=False)
    for table in WIPE_TABLES:
        db.run(f"DELETE FROM `{table}`;", batch=False)
    auto_increment_tables = set(
        db.run(
            "SELECT table_name FROM information_schema.tables "
            "WHERE table_schema = DATABASE() AND auto_increment IS NOT NULL"
        )
    )
    for table in WIPE_TABLES:
        if table in auto_increment_tables:
            db.run(f"ALTER TABLE `{table}` AUTO_INCREMENT = 1;", batch=False)
    db.run("SET FOREIGN_KEY_CHECKS=1;", batch=False)


def fetch_active_user_ids(db: DbClient) -> dict[str, int]:
    rows = db.run("SELECT id, email FROM users WHERE is_active = 1 ORDER BY id")
    result: dict[str, int] = {}
    for row in rows:
        user_id, email = row.split("\t", 1)
        result[email] = int(user_id)
    if not result:
        raise RuntimeError("No active users found. Users must be preserved.")
    return result


def choose_user(user_map: dict[str, int], email: str, fallback: int) -> int:
    return user_map.get(email, fallback)


def fetch_expense_categories(db: DbClient) -> dict[str, int]:
    rows = db.run("SELECT id, name FROM expense_categories ORDER BY id")
    return {name: int(id_) for id_, name in (row.split("\t", 1) for row in rows)}


def fetch_hs_catalog_entries(db: DbClient, count: int = 60) -> list[dict[str, Any]]:
    rows = db.run(
        "SELECT hs_code, name, "
        "COALESCE(NULLIF(category, ''), NULLIF(parent_directory_name, ''), NULLIF(section_name, ''), 'General') AS category_name, "
        "COALESCE(tariff_rate, ''), COALESCE(vat, '') "
        "FROM hs_code_tariff_catalog "
        "WHERE hs_code IS NOT NULL AND hs_code <> '' AND name IS NOT NULL AND name <> '' "
        "ORDER BY COALESCE(NULLIF(section_code,''),'99'), COALESCE(NULLIF(parent_directory_code,''),'9999'), hs_code "
        "LIMIT 1200"
    )
    candidates: list[dict[str, Any]] = []
    for row in rows:
        parts = row.split("\t")
        hs_code, name, category_name = parts[0], parts[1], parts[2]
        tariff_rate = parts[3] if len(parts) > 3 else ""
        vat = parts[4] if len(parts) > 4 else ""
        normalized = re.sub(r"\D+", "", hs_code)
        if len(normalized) < 4:
            continue
        candidates.append(
            {
                "hs_code": hs_code,
                "name": name,
                "category_name": category_name or "General",
                "tariff_rate": float(tariff_rate or 0),
                "vat": float(vat or 0),
                "chapter": normalized[:2],
            }
        )

    selected: list[dict[str, Any]] = []
    chapter_counts: dict[str, int] = {}
    for item in candidates:
        chapter = item["chapter"]
        if chapter_counts.get(chapter, 0) >= 5:
            continue
        selected.append(item)
        chapter_counts[chapter] = chapter_counts.get(chapter, 0) + 1
        if len(selected) >= count:
            return selected
    for item in candidates:
        if item in selected:
            continue
        selected.append(item)
        if len(selected) >= count:
            break
    if len(selected) < count:
        raise RuntimeError(f"HS catalog returned only {len(selected)} usable entries")
    return selected

def seed_customers(db: DbClient) -> list[dict[str, Any]]:
    customer_specs = [
        ("CEDAR-BEY", "Cedar Beirut Retail", "Beirut retail replenishment account", "high"),
        ("CEDAR-MET", "Cedar Metro Wholesale", "High-volume metro wholesale account", "critical"),
        ("NORTH-PORT", "North Port Trading", "Tripoli store network", "normal"),
        ("BEKAA-BULK", "Bekaa Bulk Market", "Agriculture and hardware distribution", "normal"),
        ("SOUTH-HUB", "South Hub Merchants", "Saida import merchant cluster", "high"),
        ("MOUNT-LINE", "Mount Line Lifestyle", "Lifestyle and decor chain", "normal"),
        ("AIR-DIRECT", "Air Direct Boutique", "Urgent replenishment customer", "high"),
        ("WAREHOUSE-DIRECT", "Warehouse Direct Client", "Warehouse-direct CBM customer", "critical"),
        ("FAMILY-MART", "Family Mart Lebanon", "Household and baby retail", "normal"),
        ("PET-NEST", "Pet Nest Stores", "Pet-focused retail chain", "normal"),
        ("ACTIVE-SPORT", "Active Sport House", "Outdoor and sport equipment", "high"),
        ("GIFT-CORNER", "Gift Corner Group", "Seasonal and gift retail", "normal"),
        ("OFFICE-SUPPLY", "Office Supply Hub", "Stationery and office goods", "normal"),
        ("HOME-LAB", "Home Lab Collection", "Kitchen and home organization", "high"),
        ("KIDS-WORLD", "Kids World Market", "Baby and kids assortment", "normal"),
    ]
    rows: list[dict[str, Any]] = []
    for idx, (shipping_code, name, note, priority) in enumerate(customer_specs, start=1):
        code = f"{SEED_TAG}-C{idx:02d}"
        phone = f"+961-{(idx % 9) + 1}-{100000 + idx * 731}"
        contacts = [
            {"name": f"{name} Ops", "phone": phone, "role": "Operations"},
            {"name": f"{name} Finance", "phone": f"+961-{(idx % 7) + 1}-{200000 + idx * 317}", "role": "Finance"},
        ]
        addresses = [
            {"label": "Main", "value": f"Zone {idx}, Beirut / Lebanon"},
            {"label": "Warehouse", "value": f"Warehouse {idx}, Mkalles / Lebanon"},
        ]
        payment_links = [{"label": "Bank slip", "url": f"https://example.test/payments/{code.lower()}"}]
        rows.append(
            {
                "code": code,
                "name": name,
                "phone": phone,
                "address": f"Customer address {idx}, Lebanon",
                "priority_level": priority,
                "priority_note": note if priority != "normal" else None,
                "default_shipping_code": shipping_code,
                "contacts": json.dumps(contacts, ensure_ascii=False),
                "addresses": json.dumps(addresses, ensure_ascii=False),
                "payment_terms": "Net 30" if idx % 3 else "Net 45",
                "payment_links": json.dumps(payment_links, ensure_ascii=False),
            }
        )
    db.insert_many("customers", rows)
    id_rows = db.run(f"SELECT id, code FROM customers WHERE code LIKE '{SEED_TAG}-C%' ORDER BY id")
    id_map = {code: int(id_) for id_, code in (row.split("\t") for row in id_rows)}
    for row in rows:
        row["id"] = id_map[row["code"]]
    return rows


def seed_suppliers(db: DbClient) -> list[dict[str, Any]]:
    specializations = [
        ("Packaging & Print", "Yiwu Master Packaging"),
        ("Kitchen & Dining", "Guangzhou Kitchen Source"),
        ("Home Decor", "Foshan Decor Works"),
        ("Lighting & Electronics", "Shenzhen Light Electronics"),
        ("Textile & Bedding", "Ningbo Textile Source"),
        ("Hardware & Tools", "Hangzhou Hardware Pro"),
        ("Beauty & Personal Care", "Suzhou Beauty Lab"),
        ("Baby & Kids", "Quanzhou Baby Products"),
        ("Sports & Outdoors", "Xiamen Outdoor Supply"),
        ("Pet & Utility", "Qingdao Pet Utility"),
        ("Office & Stationery", "Wenzhou Stationery House"),
        ("Seasonal & Gifts", "Jinhua Gift Creations"),
    ]
    rows: list[dict[str, Any]] = []
    for idx, (specialty, name) in enumerate(specializations, start=1):
        code = f"{SEED_TAG}-S{idx:02d}"
        percentage = idx % 4 != 0
        contacts = [
            {"name": f"{name} Sales", "phone": f"+86-{570 + idx}-{8500000 + idx * 13}", "role": "sales"},
            {"name": f"{name} QC", "phone": f"+86-{571 + idx}-{8600000 + idx * 19}", "role": "qc"},
        ]
        additional_ids = {"vat_id": f"VAT-{idx:03d}", "factory_license": f"LIC-{SEED_TAG}-{idx:03d}"}
        rows.append(
            {
                "code": code,
                "store_id": f"STORE-{SEED_TAG}-{idx:03d}",
                "name": name,
                "contacts": json.dumps(contacts, ensure_ascii=False),
                "factory_location": f"{specialty} Zone {idx}, China",
                "address": f"No.{idx} Industrial Avenue, China",
                "notes": f"Preferred supplier for {specialty}. Seeded full QA profile.",
                "commission_rate": round(1.25 + idx * 0.45, 4) if percentage else round(25 + idx * 7.5, 4),
                "commission_type": "percentage" if percentage else "fixed",
                "commission_applied_on": "buy_value" if idx % 3 else "sell_value",
                "phone": f"+86-{572 + idx}-{8700000 + idx * 11}",
                "fax": f"+86-{573 + idx}-{8800000 + idx * 17}",
                "additional_ids": json.dumps(additional_ids, ensure_ascii=False),
            }
        )
    db.insert_many("suppliers", rows)
    id_rows = db.run(f"SELECT id, code FROM suppliers WHERE code LIKE '{SEED_TAG}-S%' ORDER BY id")
    id_map = {code: int(id_) for id_, code in (row.split("\t") for row in id_rows)}
    for idx, row in enumerate(rows):
        row["id"] = id_map[row["code"]]
        row["specialization"] = specializations[idx][0]
    return rows


def seed_products(
    db: DbClient,
    suppliers: list[dict[str, Any]],
    hs_entries: list[dict[str, Any]],
    assets: dict[str, list[str]],
    rng: random.Random,
    uploader_id: int,
) -> list[dict[str, Any]]:
    family_products = [
        ("Packaging & Print", ["Mailer Carton", "Display Tray", "Printed Gift Box", "Paper Bag Set", "Sleeve Pack"], "箱装"),
        ("Kitchen & Dining", ["Glass Jar", "Lunch Box", "Storage Canister", "Utensil Set", "Serving Tray"], "厨房"),
        ("Home Decor", ["Wall Basket", "Storage Crate", "Decor Lantern", "Table Organizer", "Planter Pot"], "家居"),
        ("Lighting & Electronics", ["Desk Lamp", "USB Night Light", "Extension Hub", "Clip Light", "Charging Dock"], "电子"),
        ("Textile & Bedding", ["Blanket Set", "Pillow Cover", "Bed Sheet", "Curtain Panel", "Bath Towel"], "纺织"),
        ("Hardware & Tools", ["Tool Case", "Storage Hook", "Metal Shelf Kit", "Utility Handle", "Fastener Set"], "五金"),
        ("Beauty & Personal Care", ["Travel Bottle Kit", "Mirror Set", "Brush Holder", "Cosmetic Case", "Spa Towel"], "美容"),
        ("Baby & Kids", ["Toy Bin", "Feeding Set", "Baby Blanket", "Kids Stool", "Learning Mat"], "婴童"),
        ("Sports & Outdoors", ["Yoga Mat", "Camp Lantern", "Sports Bottle", "Picnic Basket", "Resistance Band"], "运动"),
        ("Pet & Utility", ["Pet Bowl", "Carrier Pad", "Litter Scoop", "Treat Jar", "Utility Bin"], "宠物"),
        ("Office & Stationery", ["Notebook Box", "Desk File", "Marker Set", "Letter Tray", "Pen Organizer"], "办公"),
        ("Seasonal & Gifts", ["Gift Hamper", "Ribbon Set", "Holiday Ornament Box", "Party Pack", "Keepsake Tin"], "礼品"),
    ]

    rows: list[dict[str, Any]] = []
    description_rows: list[dict[str, Any]] = []
    design_rows: list[dict[str, Any]] = []
    product_counter = 0
    for family_idx, (family_name, product_names, cn_prefix) in enumerate(family_products, start=1):
        supplier = suppliers[family_idx - 1]
        container_friendly = family_idx <= 8
        for item_idx, product_name in enumerate(product_names, start=1):
            hs = hs_entries[product_counter]
            image_paths = [assets["product_images"][product_counter % len(assets["product_images"])]]
            if product_counter % 4 == 0:
                image_paths.append(assets["product_images"][(product_counter + 3) % len(assets["product_images"])])

            if container_friendly:
                dimensions_scope = "carton"
                length_cm = round(52 + family_idx * 2 + item_idx * 4 + (product_counter % 3) * 3, 2)
                width_cm = round(34 + family_idx * 1.5 + item_idx * 2.2, 2)
                height_cm = round(28 + family_idx * 1.1 + item_idx * 2.7, 2)
                cbm = round((length_cm * width_cm * height_cm) / 1_000_000, 6)
                weight = round(8.5 + family_idx * 1.9 + item_idx * 2.4, 4)
                pieces_per_carton = 6 + ((family_idx + item_idx) % 5) * 6
            else:
                dimensions_scope = "piece" if item_idx % 2 else "carton"
                length_cm = round(18 + family_idx * 1.2 + item_idx * 1.5, 2)
                width_cm = round(11 + family_idx * 0.9 + item_idx * 1.1, 2)
                height_cm = round(6 + family_idx * 0.6 + item_idx * 0.9, 2)
                base_cbm = (length_cm * width_cm * height_cm) / 1_000_000
                cbm = round(base_cbm if dimensions_scope == "piece" else base_cbm * (12 + item_idx * 2), 6)
                weight = round(0.35 + family_idx * 0.18 + item_idx * 0.35, 4)
                pieces_per_carton = 12 + ((family_idx + item_idx) % 4) * 6
                if dimensions_scope == "carton":
                    weight = round(weight * pieces_per_carton * 0.68, 4)

            buy_price = round(0.9 + family_idx * 0.65 + item_idx * 0.55 + rng.uniform(0.15, 0.85), 4)
            sell_price = round(buy_price * (1.16 + (item_idx % 3) * 0.06), 4)
            high_alert_note = None
            required_design = 1 if product_counter % 7 == 0 else 0
            if product_counter % 6 == 0:
                high_alert_note = (
                    "Customer requested strict color/packaging match; verify label color, carton icon, "
                    "and sealing tape before approval."
                )
            packaging = ["Brown carton", "Printed carton", "Shrink wrap", "Poly bag", "Display sleeve"][product_counter % 5]

            row = {
                "supplier_id": supplier["id"],
                "cbm": cbm,
                "weight": weight,
                "length_cm": length_cm,
                "width_cm": width_cm,
                "height_cm": height_cm,
                "dimensions_scope": dimensions_scope,
                "packaging": packaging,
                "pieces_per_carton": pieces_per_carton,
                "unit_price": sell_price,
                "buy_price": buy_price,
                "sell_price": sell_price,
                "hs_code": hs["hs_code"],
                "description_cn": f"{cn_prefix} {item_idx}",
                "description_en": f"{product_name} - {family_name}",
                "image_paths": json.dumps(image_paths, ensure_ascii=False),
                "high_alert_note": high_alert_note,
                "required_design": required_design,
            }
            new_id = db.insert("products", row)
            row["id"] = new_id
            row["family_name"] = family_name
            row["supplier_name"] = supplier["name"]
            row["category_label"] = hs["category_name"]
            row["tariff_rate"] = hs["tariff_rate"]
            row["vat"] = hs["vat"]
            row["seed_index"] = product_counter + 1
            rows.append(row)

            description_rows.append(
                {
                    "product_id": new_id,
                    "description_text": row["description_en"],
                    "description_translated": row["description_cn"],
                    "sort_order": 1,
                }
            )
            description_rows.append(
                {
                    "product_id": new_id,
                    "description_text": f"Category: {family_name} / {hs['category_name']}",
                    "description_translated": f"{cn_prefix} / HS {hs['hs_code']}",
                    "sort_order": 2,
                }
            )
            if required_design or row["seed_index"] % 5 == 0:
                design_rows.append(
                    {
                        "entity_type": "product",
                        "entity_id": new_id,
                        "file_path": assets["documents"][row["seed_index"] % len(assets["documents"])],
                        "file_type": "application/pdf",
                        "uploaded_by": uploader_id,
                        "internal_note": f"Design pack for {row['description_en']}",
                    }
                )
            product_counter += 1
    db.insert_many("product_description_entries", description_rows)
    db.insert_many("design_attachments", design_rows)
    return rows


def make_order_specs(customers: list[dict[str, Any]]) -> list[dict[str, Any]]:
    full_containers = [
        ("CTR-FULL-01", [9.4, 8.9, 8.1]),
        ("CTR-FULL-02", [13.6, 11.3]),
        ("CTR-FULL-03", [22.0, 20.5, 18.5]),
        ("CTR-FULL-04", [31.0, 28.4]),
    ]
    almost_filled = [
        ("CTR-TOGO-01", [13.4, 10.6]),
        ("CTR-TOGO-02", [12.2, 10.8]),
        ("CTR-TOGO-03", [28.0, 26.0]),
    ]
    planning = [
        ("CTR-PLAN-01", [4.5, 3.5]),
        ("CTR-PLAN-02", [7.0, 7.5]),
    ]
    specs: list[dict[str, Any]] = []
    customer_count = len(customers)
    order_no = 1

    for draft_key, volumes in full_containers:
        for idx, cbm_target in enumerate(volumes, start=1):
            specs.append(
                {
                    "label": f"{SEED_TAG}-O{order_no:02d}",
                    "status": "FinalizedAndPushedToTracking",
                    "draft_key": draft_key,
                    "target_cbm": cbm_target,
                    "customer_idx": (order_no - 1) % customer_count,
                    "order_type": "standard",
                    "needs_receipt": True,
                    "has_confirmation_record": idx == 1,
                    "high_alert_notes": "Rush shipment, verify carton marks before loading." if order_no % 3 == 0 else None,
                }
            )
            order_no += 1

    for draft_key, volumes in almost_filled:
        for cbm_target in volumes:
            specs.append(
                {
                    "label": f"{SEED_TAG}-O{order_no:02d}",
                    "status": "AssignedToContainer",
                    "draft_key": draft_key,
                    "target_cbm": cbm_target,
                    "customer_idx": (order_no - 1) % customer_count,
                    "order_type": "standard",
                    "needs_receipt": True,
                    "has_confirmation_record": False,
                    "high_alert_notes": "Container nearly full - double-check balance and stack sequence." if order_no % 2 else None,
                }
            )
            order_no += 1

    for draft_key, volumes in planning:
        for cbm_target in volumes:
            specs.append(
                {
                    "label": f"{SEED_TAG}-O{order_no:02d}",
                    "status": "AssignedToContainer",
                    "draft_key": draft_key,
                    "target_cbm": cbm_target,
                    "customer_idx": (order_no - 1) % customer_count,
                    "order_type": "standard",
                    "needs_receipt": True,
                    "has_confirmation_record": False,
                    "high_alert_notes": None,
                }
            )
            order_no += 1

    specs.append(
        {
            "label": f"{SEED_TAG}-O{order_no:02d}",
            "status": "ConsolidatedIntoShipmentDraft",
            "draft_key": "CTR-DRAFT-ONLY",
            "target_cbm": 6.0,
            "customer_idx": (order_no - 1) % customer_count,
            "order_type": "draft_procurement",
            "needs_receipt": True,
            "has_confirmation_record": False,
            "high_alert_notes": "Converted from procurement planning draft.",
        }
    )
    order_no += 1

    standalone = [
        ("Draft", 2.4, None),
        ("Submitted", 3.2, None),
        ("Approved", 4.0, None),
        ("InTransitToWarehouse", 3.6, None),
        ("ReceivedAtWarehouse", 4.4, None),
        ("AwaitingCustomerConfirmation", 4.8, "Variance requires customer approval."),
        ("Confirmed", 5.2, "Customer approved variance; ready for next workflow."),
        ("ReadyForConsolidation", 5.6, None),
        ("CustomerDeclined", 4.1, "Customer declined due to carton count variance."),
    ]
    for status, cbm_target, note in standalone:
        specs.append(
            {
                "label": f"{SEED_TAG}-O{order_no:02d}",
                "status": status,
                "draft_key": None,
                "target_cbm": cbm_target,
                "customer_idx": (order_no - 1) % customer_count,
                "order_type": "standard",
                "needs_receipt": status in {"ReceivedAtWarehouse", "AwaitingCustomerConfirmation", "Confirmed", "ReadyForConsolidation", "CustomerDeclined"},
                "has_confirmation_record": status in {"Confirmed", "CustomerDeclined"},
                "high_alert_notes": note,
            }
        )
        order_no += 1

    if len(specs) != 30:
        raise RuntimeError(f"Expected 30 order specs, got {len(specs)}")
    return specs
