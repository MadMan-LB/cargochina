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


def parse_decimalish(value: str | int | float | None) -> float:
    if value is None:
        return 0.0
    if isinstance(value, (int, float)):
        return float(value)
    cleaned = re.sub(r"[^0-9.\\-]+", "", str(value))
    try:
        return float(cleaned or 0)
    except ValueError:
        return 0.0


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
                "tariff_rate": parse_decimalish(tariff_rate),
                "vat": parse_decimalish(vat),
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

def create_containers_and_drafts(
    db: DbClient,
    assets: dict[str, list[str]],
    today: date,
) -> tuple[dict[str, dict[str, Any]], dict[str, dict[str, Any]]]:
    container_specs = [
        {"key": "CTR-FULL-01", "code": f"{SEED_TAG}-CTR-001", "max_cbm": 28.0, "max_weight": 28200.0, "status": "on_route", "notes": "Full 20HQ container already finalized and en route to Beirut.", "destination_country": "LB", "destination": "Beirut", "expected_ship_date": (today - timedelta(days=12)).isoformat(), "actual_departure_date": (today - timedelta(days=9)).isoformat(), "eta_date": (today + timedelta(days=6)).isoformat(), "vessel_name": "Mediterranean Cedar 1"},
        {"key": "CTR-FULL-02", "code": f"{SEED_TAG}-CTR-002", "max_cbm": 28.0, "max_weight": 28200.0, "status": "on_route", "notes": "Second 20HQ consolidated and pushed to tracking.", "destination_country": "LB", "destination": "Tripoli", "expected_ship_date": (today - timedelta(days=10)).isoformat(), "actual_departure_date": (today - timedelta(days=8)).isoformat(), "eta_date": (today + timedelta(days=7)).isoformat(), "vessel_name": "Tripoli Link 8"},
        {"key": "CTR-FULL-03", "code": f"{SEED_TAG}-CTR-003", "max_cbm": 67.7, "max_weight": 26800.0, "status": "on_route", "notes": "40HQ multi-customer finalized draft en route to Jounieh.", "destination_country": "LB", "destination": "Jounieh", "expected_ship_date": (today - timedelta(days=9)).isoformat(), "actual_departure_date": (today - timedelta(days=6)).isoformat(), "eta_date": (today + timedelta(days=5)).isoformat(), "vessel_name": "Levant Star 4"},
        {"key": "CTR-FULL-04", "code": f"{SEED_TAG}-CTR-004", "max_cbm": 67.7, "max_weight": 26800.0, "status": "on_route", "notes": "High-value 40HQ container with finalized tracking push.", "destination_country": "AE", "destination": "Dubai", "expected_ship_date": (today - timedelta(days=7)).isoformat(), "actual_departure_date": (today - timedelta(days=5)).isoformat(), "eta_date": (today + timedelta(days=11)).isoformat(), "vessel_name": "Gulf Horizon 9"},
        {"key": "CTR-TOGO-01", "code": f"{SEED_TAG}-CTR-005", "max_cbm": 28.0, "max_weight": 28200.0, "status": "to_go", "notes": "Almost full 20HQ - waiting for final carton top-up.", "destination_country": "LB", "destination": "Beirut", "expected_ship_date": (today + timedelta(days=4)).isoformat(), "actual_departure_date": None, "eta_date": (today + timedelta(days=19)).isoformat(), "vessel_name": "Ready Queue 1"},
        {"key": "CTR-TOGO-02", "code": f"{SEED_TAG}-CTR-006", "max_cbm": 28.0, "max_weight": 28200.0, "status": "to_go", "notes": "Almost full 20HQ for north route.", "destination_country": "LB", "destination": "Tripoli", "expected_ship_date": (today + timedelta(days=5)).isoformat(), "actual_departure_date": None, "eta_date": (today + timedelta(days=20)).isoformat(), "vessel_name": "Ready Queue 2"},
        {"key": "CTR-TOGO-03", "code": f"{SEED_TAG}-CTR-007", "max_cbm": 67.7, "max_weight": 26800.0, "status": "to_go", "notes": "Large almost-filled 40HQ awaiting booking confirmation.", "destination_country": "IQ", "destination": "Basra", "expected_ship_date": (today + timedelta(days=6)).isoformat(), "actual_departure_date": None, "eta_date": (today + timedelta(days=24)).isoformat(), "vessel_name": "Ready Queue 3"},
        {"key": "CTR-PLAN-01", "code": f"{SEED_TAG}-CTR-008", "max_cbm": 28.0, "max_weight": 28200.0, "status": "planning", "notes": "New 20HQ planning batch with early assignments.", "destination_country": "LB", "destination": "Saida", "expected_ship_date": (today + timedelta(days=13)).isoformat(), "actual_departure_date": None, "eta_date": (today + timedelta(days=31)).isoformat(), "vessel_name": "Planning Board 1"},
        {"key": "CTR-PLAN-02", "code": f"{SEED_TAG}-CTR-009", "max_cbm": 67.7, "max_weight": 26800.0, "status": "planning", "notes": "Large planning container starting to collect cargo.", "destination_country": "JO", "destination": "Aqaba", "expected_ship_date": (today + timedelta(days=15)).isoformat(), "actual_departure_date": None, "eta_date": (today + timedelta(days=35)).isoformat(), "vessel_name": "Planning Board 2"},
    ]

    containers: dict[str, dict[str, Any]] = {}
    for spec in container_specs:
        container_id = db.insert(
            "containers",
            {
                "code": spec["code"],
                "max_cbm": spec["max_cbm"],
                "max_weight": spec["max_weight"],
                "status": spec["status"],
                "notes": spec["notes"],
                "destination_country": spec["destination_country"],
                "destination": spec["destination"],
                "eta_date": spec["eta_date"],
                "actual_arrival_date": None,
                "expected_ship_date": spec["expected_ship_date"],
                "actual_departure_date": spec["actual_departure_date"],
                "vessel_name": spec["vessel_name"],
            },
        )
        spec["id"] = container_id
        containers[spec["key"]] = spec

    draft_specs = [
        ("CTR-FULL-01", "finalized"),
        ("CTR-FULL-02", "finalized"),
        ("CTR-FULL-03", "finalized"),
        ("CTR-FULL-04", "finalized"),
        ("CTR-TOGO-01", "draft"),
        ("CTR-TOGO-02", "draft"),
        ("CTR-TOGO-03", "draft"),
        ("CTR-PLAN-01", "draft"),
        ("CTR-PLAN-02", "draft"),
        ("CTR-DRAFT-ONLY", "draft"),
    ]
    drafts: dict[str, dict[str, Any]] = {}
    document_rows: list[dict[str, Any]] = []
    for idx, (container_key, status) in enumerate(draft_specs, start=1):
        container_id = containers[container_key]["id"] if container_key in containers else None
        draft_id = db.insert(
            "shipment_drafts",
            {
                "container_id": container_id,
                "container_number": containers[container_key]["code"] if container_key in containers else None,
                "booking_number": f"{SEED_TAG}-BOOK-{idx:03d}",
                "tracking_url": f"https://tracking.example.test/shipments/{SEED_TAG.lower()}-{idx:03d}",
                "status": status,
            },
        )
        drafts[container_key] = {"id": draft_id, "container_id": container_id, "status": status, "booking_number": f"{SEED_TAG}-BOOK-{idx:03d}"}
        document_rows.append({"shipment_draft_id": draft_id, "file_path": assets["documents"][idx % len(assets["documents"])], "doc_type": "booking_confirmation" if idx % 2 else "invoice"})
        if status == "finalized":
            document_rows.append({"shipment_draft_id": draft_id, "file_path": assets["documents"][(idx + 1) % len(assets["documents"])], "doc_type": "bol"})
    db.insert_many("shipment_draft_documents", document_rows)
    return containers, drafts


def create_order_items_for_target(
    spec: dict[str, Any],
    order_id: int,
    order_index: int,
    products_for_order: list[dict[str, Any]],
    customer: dict[str, Any],
    assets: dict[str, list[str]],
) -> list[dict[str, Any]]:
    item_rows: list[dict[str, Any]] = []
    target_cbm = spec["target_cbm"]
    item_targets = [target_cbm * 0.56, target_cbm * 0.44]
    for line_index, (product, item_target) in enumerate(zip(products_for_order, item_targets), start=1):
        qty_per_carton = int(product["pieces_per_carton"] or 12)
        order_qty_per_carton = qty_per_carton
        if (order_index + line_index) % 4 == 0:
            order_qty_per_carton = max(4, qty_per_carton + (2 if line_index % 2 else -2))
        if product["dimensions_scope"] == "carton":
            cartons = max(1, int(round(item_target / max(float(product["cbm"]), 0.01))))
            quantity = cartons * order_qty_per_carton
            declared_cbm = round(cartons * float(product["cbm"]), 6)
            declared_weight = round(cartons * float(product["weight"]), 4)
        else:
            per_piece_cbm = max(float(product["cbm"]), 0.0001)
            quantity = max(order_qty_per_carton, int(round(item_target / per_piece_cbm)))
            cartons = max(1, int(round(quantity / max(order_qty_per_carton, 1))))
            quantity = cartons * order_qty_per_carton
            declared_cbm = round(quantity * per_piece_cbm, 6)
            declared_weight = round(quantity * float(product["weight"]), 4)

        sell_price = round(float(product["sell_price"]) * (1 + (line_index - 1) * 0.02), 4)
        buy_price = round(float(product["buy_price"]) * (1 + (line_index - 1) * 0.015), 4)
        shipping_code = f"{customer['default_shipping_code']}-{order_index:02d}-{line_index}"
        notes = f"Seed line {line_index} for {spec['label']}."
        if product["high_alert_note"]:
            notes += " High-alert product selected."
        image_paths = json.dumps(
            [
                assets["order_images"][(order_index + line_index) % len(assets["order_images"])],
                assets["product_images"][(product["seed_index"] + line_index) % len(assets["product_images"])],
            ],
            ensure_ascii=False,
        )
        row = {
            "order_id": order_id,
            "product_id": product["id"],
            "supplier_id": product["supplier_id"],
            "item_no": f"{spec['label']}-IT{line_index:02d}",
            "shipping_code": shipping_code,
            "cartons": cartons,
            "qty_per_carton": qty_per_carton,
            "unit_price": sell_price,
            "total_amount": round(quantity * sell_price, 4),
            "buy_price": buy_price,
            "sell_price": sell_price,
            "order_cartons": cartons,
            "order_qty_per_carton": order_qty_per_carton,
            "notes": notes,
            "image_paths": image_paths,
            "quantity": quantity,
            "unit": "pieces",
            "declared_cbm": declared_cbm,
            "declared_weight": declared_weight,
            "item_length": product["length_cm"],
            "item_width": product["width_cm"],
            "item_height": product["height_cm"],
            "description_cn": product["description_cn"],
            "description_en": product["description_en"],
        }
        item_rows.append(row)
    return item_rows


def seed_orders_and_items(
    db: DbClient,
    order_specs: list[dict[str, Any]],
    customers: list[dict[str, Any]],
    products: list[dict[str, Any]],
    drafts: dict[str, dict[str, Any]],
    assets: dict[str, list[str]],
    created_by: int,
    today: date,
    uploader_id: int,
) -> tuple[list[dict[str, Any]], list[dict[str, Any]]]:
    orders: list[dict[str, Any]] = []
    all_items: list[dict[str, Any]] = []
    product_cursor = 0
    order_attachment_rows: list[dict[str, Any]] = []
    design_attachment_rows: list[dict[str, Any]] = []
    for idx, spec in enumerate(order_specs, start=1):
        customer = customers[spec["customer_idx"]]
        selected_products = products[product_cursor : product_cursor + 2]
        if len(selected_products) != 2:
            raise RuntimeError("Not enough products to seed the planned orders")
        product_cursor += 2
        supplier_ids = {p["supplier_id"] for p in selected_products if p.get("supplier_id")}
        order_supplier_id = next(iter(supplier_ids)) if len(supplier_ids) == 1 else None
        confirm_token = None
        if spec["status"] == "AwaitingCustomerConfirmation":
            confirm_token = hashlib.sha256(f"{SEED_TAG}-confirm-{idx}".encode("utf-8")).hexdigest()[:40]

        created_at = datetime.combine(today - timedelta(days=46 - idx), datetime.min.time()) + timedelta(hours=9 + (idx % 6))
        ready_date = (
            today - timedelta(days=max(2, 30 - idx))
            if spec["status"] in {"FinalizedAndPushedToTracking", "AssignedToContainer", "ConsolidatedIntoShipmentDraft"}
            else today + timedelta(days=(idx % 7) + 2)
        )
        order_id = db.insert(
            "orders",
            {
                "customer_id": customer["id"],
                "supplier_id": order_supplier_id,
                "expected_ready_date": ready_date.isoformat(),
                "currency": "USD",
                "status": spec["status"],
                "confirmation_token": confirm_token,
                "high_alert_notes": spec["high_alert_notes"],
                "order_type": spec["order_type"],
                "created_by": created_by,
                "created_at": created_at.strftime("%Y-%m-%d %H:%M:%S"),
            },
        )
        spec["id"] = order_id
        spec["confirmation_token"] = confirm_token
        spec["customer_id"] = customer["id"]
        spec["customer_name"] = customer["name"]
        spec["supplier_id"] = order_supplier_id
        spec["created_at"] = created_at.strftime("%Y-%m-%d %H:%M:%S")
        spec["expected_ready_date"] = ready_date.isoformat()

        item_rows = create_order_items_for_target(spec, order_id, idx, selected_products, customer, assets)
        created_item_ids: list[int] = []
        for row in item_rows:
            item_id = db.insert("order_items", row)
            row["id"] = item_id
            created_item_ids.append(item_id)
            all_items.append(row)
        spec["item_ids"] = created_item_ids
        spec["items"] = item_rows
        orders.append(spec)

        order_attachment_rows.append({"order_id": spec["id"], "file_path": assets["documents"][idx % len(assets["documents"])], "type": "invoice"})
        order_attachment_rows.append({"order_id": spec["id"], "file_path": assets["order_images"][idx % len(assets["order_images"])], "type": "photo"})
        if spec["high_alert_notes"]:
            design_attachment_rows.append(
                {
                    "entity_type": "order_item",
                    "entity_id": created_item_ids[0],
                    "file_path": assets["documents"][(idx + 1) % len(assets["documents"])],
                    "file_type": "application/pdf",
                    "uploaded_by": uploader_id,
                    "internal_note": f"Special handling note for {spec['label']}",
                }
            )

    sdo_rows = []
    for spec in orders:
        if spec["draft_key"]:
            sdo_rows.append({"shipment_draft_id": drafts[spec["draft_key"]]["id"], "order_id": spec["id"]})
    db.insert_many("shipment_draft_orders", sdo_rows)
    db.insert_many("order_attachments", order_attachment_rows)
    db.insert_many("design_attachments", design_attachment_rows)
    return orders, all_items

def seed_receipts_and_confirmations(
    db: DbClient,
    orders: list[dict[str, Any]],
    assets: dict[str, list[str]],
    received_by: int,
    today: date,
) -> dict[str, Any]:
    receipt_photo_rows: list[dict[str, Any]] = []
    receipt_item_photo_rows: list[dict[str, Any]] = []
    confirmation_rows: list[dict[str, Any]] = []
    confirm_manifest: dict[str, str] = {}

    for idx, order in enumerate(orders, start=1):
        if not order["needs_receipt"]:
            continue
        declared_cartons = sum(int(item["cartons"] or 0) for item in order["items"])
        declared_cbm = sum(float(item["declared_cbm"] or 0) for item in order["items"])
        declared_weight = sum(float(item["declared_weight"] or 0) for item in order["items"])
        variance_mode = order["status"] in {"AwaitingCustomerConfirmation", "CustomerDeclined", "Confirmed"}
        actual_factor = 1.08 if order["status"] == "AwaitingCustomerConfirmation" else 0.92 if order["status"] == "CustomerDeclined" else 1.03 if order["status"] == "Confirmed" else 1.0
        actual_cartons = max(1, int(round(declared_cartons * (1.04 if variance_mode else 1.0))))
        actual_cbm = round(declared_cbm * actual_factor, 6)
        actual_weight = round(declared_weight * (1.02 if variance_mode else 1.0), 4)
        receipt_condition = "partial" if order["status"] == "CustomerDeclined" else "damaged" if order["status"] == "AwaitingCustomerConfirmation" else "good"
        received_at = datetime.combine(today - timedelta(days=max(1, 28 - idx)), datetime.min.time()) + timedelta(hours=11 + (idx % 4))
        receipt_id = db.insert(
            "warehouse_receipts",
            {
                "order_id": order["id"],
                "actual_cartons": actual_cartons,
                "actual_cbm": actual_cbm,
                "actual_weight": actual_weight,
                "receipt_condition": receipt_condition,
                "notes": "Variance flagged for customer review." if variance_mode else "Warehouse receipt recorded from seeded inbound workflow.",
                "received_by": received_by,
                "received_at": received_at.strftime("%Y-%m-%d %H:%M:%S"),
            },
        )
        receipt_photo_rows.append({"receipt_id": receipt_id, "file_path": assets["receipt_images"][idx % len(assets["receipt_images"])]})

        for line_no, item in enumerate(order["items"], start=1):
            item_variance = variance_mode and line_no == 1
            factor = 1.12 if order["status"] == "AwaitingCustomerConfirmation" and line_no == 1 else 0.88 if order["status"] == "CustomerDeclined" and line_no == 1 else 1.02 if order["status"] == "Confirmed" and line_no == 1 else 1.0
            receipt_item_id = db.insert(
                "warehouse_receipt_items",
                {
                    "receipt_id": receipt_id,
                    "order_item_id": item["id"],
                    "actual_cartons": max(1, int(round((item["cartons"] or 0) * (1.05 if item_variance else 1.0)))),
                    "actual_cbm": round(float(item["declared_cbm"] or 0) * factor, 6),
                    "actual_weight": round(float(item["declared_weight"] or 0) * (1.03 if item_variance else 1.0), 4),
                    "receipt_condition": receipt_condition if item_variance else "good",
                    "variance_detected": 1 if item_variance else 0,
                    "notes": "Line variance seeded for QA." if item_variance else "Matches declared values.",
                },
            )
            if line_no == 1:
                receipt_item_photo_rows.append({"receipt_item_id": receipt_item_id, "file_path": assets["receipt_images"][(idx + line_no) % len(assets["receipt_images"])]})

        if order["status"] == "AwaitingCustomerConfirmation" and order["confirmation_token"]:
            confirm_manifest[f"order_{order['id']}"] = f"{APP_BASE_URL}/confirm.php?token={order['confirmation_token']}"
        elif order["status"] == "Confirmed" or order.get("has_confirmation_record"):
            confirmation_rows.append(
                {
                    "order_id": order["id"],
                    "confirmed_by": None,
                    "confirmed_at": (received_at + timedelta(days=1)).strftime("%Y-%m-%d %H:%M:%S"),
                    "declined_at": None,
                    "decline_reason": None,
                    "accepted_actuals": json.dumps({"actual_cbm": actual_cbm, "actual_weight": actual_weight, "actual_cartons": actual_cartons}),
                }
            )
        elif order["status"] == "CustomerDeclined":
            decline_time = (received_at + timedelta(days=1)).strftime("%Y-%m-%d %H:%M:%S")
            confirmation_rows.append(
                {
                    "order_id": order["id"],
                    "confirmed_by": None,
                    "confirmed_at": decline_time,
                    "declined_at": decline_time,
                    "decline_reason": "Seeded decline: customer rejected carton variance and requested recount.",
                    "accepted_actuals": None,
                }
            )
    db.insert_many("warehouse_receipt_photos", receipt_photo_rows)
    db.insert_many("warehouse_receipt_item_photos", receipt_item_photo_rows)
    db.insert_many("customer_confirmations", confirmation_rows)
    return {"confirmation_links": confirm_manifest}


def seed_portal_tokens(
    db: DbClient,
    customers: list[dict[str, Any]],
    created_by: int,
) -> list[dict[str, str]]:
    manifest_rows: list[dict[str, str]] = []
    for idx, customer in enumerate(customers[:5], start=1):
        raw_token = f"{SEED_TAG.lower()}-portal-{customer['code'].lower()}-{idx:02d}"
        token_hash = hashlib.sha256(raw_token.encode("utf-8")).hexdigest()
        expires_at = (datetime.now() + timedelta(days=15 + idx)).strftime("%Y-%m-%d %H:%M:%S")
        db.insert(
            "customer_portal_tokens",
            {
                "customer_id": customer["id"],
                "token_hash": token_hash,
                "expires_at": expires_at,
                "used_at": None,
                "created_by": created_by,
            },
        )
        manifest_rows.append(
            {
                "customer": customer["name"],
                "customer_code": customer["code"],
                "token": raw_token,
                "url": f"{APP_BASE_URL}/customer_portal.php?token={raw_token}",
            }
        )
    return manifest_rows


def seed_finance_and_supporting(
    db: DbClient,
    orders: list[dict[str, Any]],
    customers: list[dict[str, Any]],
    suppliers: list[dict[str, Any]],
    products: list[dict[str, Any]],
    containers: dict[str, dict[str, Any]],
    drafts: dict[str, dict[str, Any]],
    expense_categories: dict[str, int],
    user_ids: dict[str, int],
) -> None:
    superadmin = user_ids.get("admin@salameh.com", next(iter(user_ids.values())))
    lebanon_admin = user_ids.get("qa.lebanonadmin@salameh.local", superadmin)
    china_admin = user_ids.get("qa.chinaadmin@salameh.local", superadmin)
    warehouse_user = user_ids.get("qa.warehouse@salameh.local", superadmin)
    employee = user_ids.get("qa.employee@salameh.local", superadmin)
    container_values = list(containers.values())

    deposit_rows = []
    for idx, customer in enumerate(customers[:10], start=1):
        deposit_rows.append(
            {
                "customer_id": customer["id"],
                "amount": round(1200 + idx * 275.5, 4),
                "currency": "USD" if idx % 3 else "RMB",
                "payment_method": "bank_transfer" if idx % 2 else "cash",
                "reference_no": f"DEP-{SEED_TAG}-{idx:03d}",
                "notes": f"Seed deposit for {customer['name']}",
                "created_by": superadmin,
                "created_at": (datetime.now() - timedelta(days=20 - idx)).strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    db.insert_many("customer_deposits", deposit_rows)

    payable_orders = [order for order in orders if order["status"] not in {"Draft", "Submitted"}]
    payment_rows = []
    for idx, supplier in enumerate(suppliers, start=1):
        order = payable_orders[(idx - 1) % len(payable_orders)]
        invoice_amount = round(sum(float(item["buy_price"]) * float(item["quantity"]) for item in order["items"]), 4)
        amount = round(invoice_amount * (0.65 if idx % 3 else 1.0), 4)
        payment_rows.append(
            {
                "supplier_id": supplier["id"],
                "order_id": order["id"],
                "amount": amount,
                "invoice_amount": invoice_amount,
                "discount_amount": round(15 + idx * 2.5, 4) if idx % 4 == 0 else 0,
                "marked_full_payment": 1 if idx % 3 == 0 else 0,
                "marked_by": lebanon_admin,
                "currency": "USD",
                "payment_type": "full" if idx % 3 == 0 else "partial",
                "notes": f"Seed supplier payment for {supplier['name']}",
                "created_at": (datetime.now() - timedelta(days=16 - idx)).strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    db.insert_many("supplier_payments", payment_rows)

    expense_rows = []
    expense_names = [name for name in expense_categories.keys() if name.lower() != "testing"]
    for idx in range(1, 29):
        category_name = expense_names[(idx - 1) % len(expense_names)]
        order = orders[(idx - 1) % len(orders)]
        container = container_values[(idx - 1) % len(container_values)] if idx % 2 else None
        supplier = suppliers[(idx - 1) % len(suppliers)] if idx % 3 == 0 else None
        customer = customers[(idx - 1) % len(customers)] if idx % 4 == 0 else None
        expense_rows.append(
            {
                "category_id": expense_categories[category_name],
                "amount": round(55 + idx * 23.75, 4),
                "currency": "USD",
                "expense_date": (date.today() - timedelta(days=30 - idx)).isoformat(),
                "payee": f"{category_name} Vendor {idx:02d}",
                "notes": f"Seeded {category_name.lower()} expense for QA finance coverage.",
                "order_id": order["id"] if idx % 5 else None,
                "container_id": container["id"] if container and idx % 2 else None,
                "customer_id": customer["id"] if customer else None,
                "supplier_id": supplier["id"] if supplier else None,
                "created_by": china_admin if idx % 2 else lebanon_admin,
                "created_at": (datetime.now() - timedelta(days=30 - idx)).strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    db.insert_many("expenses", expense_rows)

    interaction_rows = []
    interaction_types = ["visit", "quote", "note"]
    for idx, supplier in enumerate(suppliers, start=1):
        for offset in range(2):
            interaction_rows.append(
                {
                    "supplier_id": supplier["id"],
                    "interaction_type": interaction_types[(idx + offset) % len(interaction_types)],
                    "content": json.dumps({"summary": f"Seed interaction {offset + 1} with {supplier['name']}", "specialization": supplier["specialization"], "action_owner": "China buying team" if offset == 0 else "Lebanon consolidation"}),
                    "created_by": china_admin if offset == 0 else employee,
                    "created_at": (datetime.now() - timedelta(days=12 - idx + offset)).strftime("%Y-%m-%d %H:%M:%S"),
                }
            )
    db.insert_many("supplier_interactions", interaction_rows)

    template_item_rows = []
    for idx in range(1, 7):
        template_id = db.insert("order_templates", {"name": f"{SEED_TAG} Template {idx:02d}", "created_by": china_admin, "created_at": (datetime.now() - timedelta(days=idx)).strftime("%Y-%m-%d %H:%M:%S")})
        source_order = orders[(idx - 1) * 2]
        for sort_order, item in enumerate(source_order["items"], start=1):
            template_item_rows.append(
                {
                    "template_id": template_id,
                    "sort_order": sort_order,
                    "item_no": item["item_no"],
                    "shipping_code": item["shipping_code"],
                    "product_id": item["product_id"],
                    "supplier_id": item["supplier_id"],
                    "description_cn": item["description_cn"],
                    "description_en": item["description_en"],
                    "cartons": item["cartons"],
                    "qty_per_carton": item["qty_per_carton"],
                    "quantity": item["quantity"],
                    "unit": "pieces",
                    "declared_cbm": item["declared_cbm"],
                    "declared_weight": item["declared_weight"],
                    "item_length": item["item_length"],
                    "item_width": item["item_width"],
                    "item_height": item["item_height"],
                    "unit_price": item["unit_price"],
                    "total_amount": item["total_amount"],
                    "notes": f"Template seeded from {source_order['label']}",
                }
            )
    db.insert_many("order_template_items", template_item_rows)

    converted_order = next(order for order in orders if order["order_type"] == "draft_procurement")
    procurement_statuses = ["draft", "pending_review", "sent_to_supplier", "converted", "cancelled"]
    procurement_item_rows = []
    for idx, status in enumerate(procurement_statuses, start=1):
        supplier = suppliers[(idx - 1) % len(suppliers)]
        draft_id = db.insert(
            "procurement_drafts",
            {
                "name": f"{SEED_TAG} Procurement Draft {idx:02d}",
                "supplier_id": supplier["id"],
                "status": status,
                "created_by": china_admin,
                "created_at": (datetime.now() - timedelta(days=idx + 2)).strftime("%Y-%m-%d %H:%M:%S"),
                "updated_at": (datetime.now() - timedelta(days=idx)).strftime("%Y-%m-%d %H:%M:%S"),
                "converted_order_id": converted_order["id"] if status == "converted" else None,
            },
        )
        seed_products = [orders[(idx + offset) % len(orders)]["items"][0]["product_id"] for offset in range(3)]
        for sort_order, product_id in enumerate(seed_products, start=1):
            procurement_item_rows.append({"draft_id": draft_id, "product_id": product_id, "quantity": round(24 + idx * 3 + sort_order * 2, 4), "notes": f"Seed procurement line {sort_order} for status {status}", "sort_order": sort_order})
    db.insert_many("procurement_draft_items", procurement_item_rows)
    message_rows = []
    for idx, order in enumerate(orders[:18], start=1):
        message_rows.append(
            {
                "customer_id": order["customer_id"],
                "order_id": order["id"],
                "container_id": drafts[order["draft_key"]]["container_id"] if order["draft_key"] and drafts[order["draft_key"]]["container_id"] else None,
                "sender_id": warehouse_user if idx % 2 else lebanon_admin,
                "body": f"Seed internal note for {order['label']}: verify receiving status and next workflow owner.",
                "read_at": None if idx % 3 else datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
                "created_at": (datetime.now() - timedelta(days=10 - idx % 5)).strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    db.insert_many("internal_messages", message_rows)

    notification_rows = []
    for idx, user_id in enumerate({superadmin, china_admin, lebanon_admin, warehouse_user, employee}, start=1):
        notification_rows.append({"user_id": user_id, "type": "shipment_finalized", "channel": "dashboard", "title": f"Seed notification {idx}: shipment finalized", "body": "A seeded shipment draft is finalized and available for QA verification.", "read_at": None if idx % 2 else datetime.now().strftime("%Y-%m-%d %H:%M:%S"), "created_at": (datetime.now() - timedelta(hours=idx * 3)).strftime("%Y-%m-%d %H:%M:%S")})
        notification_rows.append({"user_id": user_id, "type": "variance_confirmation", "channel": "dashboard", "title": f"Seed notification {idx}: variance needs review", "body": "Awaiting customer confirmation exists in the seeded dataset.", "read_at": None, "created_at": (datetime.now() - timedelta(hours=idx * 2)).strftime("%Y-%m-%d %H:%M:%S")})
    db.insert_many("notifications", notification_rows)
    notification_ids = [int(row.split("\t")[0]) for row in db.run("SELECT id FROM notifications ORDER BY id")]
    delivery_rows = []
    for idx, notification_id in enumerate(notification_ids[-10:], start=1):
        delivery_rows.append(
            {
                "notification_id": notification_id,
                "channel": "email" if idx % 2 else "whatsapp",
                "payload_hash": hashlib.sha256(f"{notification_id}-{idx}".encode("utf-8")).hexdigest(),
                "status": "sent" if idx % 4 else "failed",
                "attempts": 1 if idx % 4 else 2,
                "last_error": None if idx % 4 else "Simulated provider timeout",
                "external_id": f"seed-ext-{notification_id}",
                "created_at": (datetime.now() - timedelta(hours=idx)).strftime("%Y-%m-%d %H:%M:%S"),
                "updated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    db.insert_many("notification_delivery_log", delivery_rows)

    tax_rate_rows = []
    for idx, product in enumerate(products[:18], start=1):
        tax_rate_rows.append(
            {
                "hs_code": product["hs_code"],
                "country_code": "LB" if idx % 3 else "AE",
                "rate_percent": round(max(float(product["tariff_rate"]), 5) + (idx % 4) * 0.75, 4),
                "effective_from": (date.today() - timedelta(days=120 - idx * 3)).isoformat(),
                "notes": f"Seed tax rate {idx}",
            }
        )
    db.insert_many("hs_code_tax_rates", tax_rate_rows)

    arrival_rows = []
    on_route = [container for container in container_values if container["status"] == "on_route"]
    for idx, container in enumerate(on_route, start=1):
        arrival_rows.append({"container_id": container["id"], "days_before": 3 if idx % 2 else 7, "notified_at": (datetime.now() - timedelta(days=idx)).strftime("%Y-%m-%d %H:%M:%S")})
    db.insert_many("container_arrival_notifications", arrival_rows)

    push_rows = []
    finalized_drafts = [drafts[key] for key in drafts if drafts[key]["status"] == "finalized"]
    for idx, draft in enumerate(finalized_drafts, start=1):
        push_rows.append(
            {
                "entity_type": "shipment_draft",
                "entity_id": draft["id"],
                "idempotency_key": f"clms-seed-draft-{draft['id']}",
                "status": "success",
                "request_payload": json.dumps({"shipment_draft_id": draft["id"], "seeded": True}),
                "response_code": 200,
                "response_body": json.dumps({"status": "ok", "external_shipment_id": f"TRK-{draft['id']:04d}"}),
                "external_id": f"TRK-{draft['id']:04d}",
                "attempt_count": 1,
                "last_error": None,
                "created_at": (datetime.now() - timedelta(days=idx)).strftime("%Y-%m-%d %H:%M:%S"),
                "updated_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    db.insert_many("tracking_push_log", push_rows)

    audit_rows = []
    for idx, order in enumerate(orders[:20], start=1):
        audit_rows.append(
            {
                "entity_type": "order",
                "entity_id": order["id"],
                "action": "seed_create",
                "old_value": None,
                "new_value": json.dumps({"status": order["status"], "customer_id": order["customer_id"], "draft_key": order["draft_key"]}),
                "user_id": superadmin if idx % 2 else china_admin,
                "created_at": (datetime.now() - timedelta(days=5, hours=idx)).strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    for key, draft in drafts.items():
        audit_rows.append(
            {
                "entity_type": "shipment_draft",
                "entity_id": draft["id"],
                "action": "seed_create",
                "old_value": None,
                "new_value": json.dumps({"status": draft["status"], "key": key}),
                "user_id": lebanon_admin,
                "created_at": datetime.now().strftime("%Y-%m-%d %H:%M:%S"),
            }
        )
    db.insert_many("audit_log", audit_rows)


def summarize_counts(db: DbClient, tables: list[str]) -> dict[str, int]:
    result: dict[str, int] = {}
    for table in tables:
        result[table] = int(db.scalar(f"SELECT COUNT(*) FROM `{table}`") or 0)
    return result


def build_manifest(
    root_dir: Path,
    backup_info: dict[str, str],
    customers: list[dict[str, Any]],
    suppliers: list[dict[str, Any]],
    products: list[dict[str, Any]],
    orders: list[dict[str, Any]],
    containers: dict[str, dict[str, Any]],
    drafts: dict[str, dict[str, Any]],
    portal_links: list[dict[str, str]],
    confirm_links: dict[str, str],
    counts: dict[str, int],
) -> Path:
    manifest_dir = root_dir / "output" / "seed_manifests"
    manifest_dir.mkdir(parents=True, exist_ok=True)
    container_summary = [{"code": container["code"], "status": container["status"], "destination": container["destination"], "eta_date": container["eta_date"]} for container in containers.values()]
    order_status_counts: dict[str, int] = {}
    for order in orders:
        order_status_counts[order["status"]] = order_status_counts.get(order["status"], 0) + 1
    manifest = {
        "seed_tag": SEED_TAG,
        "generated_at": datetime.now().isoformat(),
        "backup": backup_info,
        "preserved_tables": sorted(PRESERVE_TABLES),
        "counts": counts,
        "order_status_counts": order_status_counts,
        "customers": [{"id": row["id"], "code": row["code"], "name": row["name"], "shipping_code": row["default_shipping_code"]} for row in customers],
        "suppliers": [{"id": row["id"], "code": row["code"], "name": row["name"], "commission_type": row["commission_type"], "commission_rate": row["commission_rate"]} for row in suppliers],
        "containers": container_summary,
        "shipment_drafts": [{"key": key, "id": value["id"], "status": value["status"]} for key, value in drafts.items()],
        "portal_links": portal_links,
        "confirmation_links": confirm_links,
        "sample_products": [{"id": product["id"], "description_en": product["description_en"], "supplier_name": product["supplier_name"], "hs_code": product["hs_code"]} for product in products[:12]],
    }
    manifest_path = manifest_dir / f"full_test_dataset_manifest_{datetime.now().strftime('%Y%m%d_%H%M%S')}.json"
    manifest_path.write_text(json.dumps(manifest, indent=2, ensure_ascii=False), encoding="utf-8")
    return manifest_path


def main() -> int:
    parser = argparse.ArgumentParser(description="Reset CLMS business data and seed a full QA dataset")
    parser.add_argument("--skip-backup", action="store_true", help="Skip SQL/uploads backup (not recommended)")
    args = parser.parse_args()

    root_dir = Path(__file__).resolve().parents[1]
    env = parse_env(root_dir / ".env")
    mysql_path, mysqldump_path = locate_mysql_tools()
    db = DbClient(mysql_path=mysql_path, db_name=env.get("DB_NAME", "clms"), db_user=env.get("DB_USER", "root"), db_pass=env.get("DB_PASS", ""))

    user_ids = fetch_active_user_ids(db)
    superadmin = user_ids.get("admin@salameh.com", next(iter(user_ids.values())))
    china_admin = choose_user(user_ids, "qa.chinaadmin@salameh.local", superadmin)
    warehouse_user = choose_user(user_ids, "qa.warehouse@salameh.local", superadmin)
    lebanon_admin = choose_user(user_ids, "qa.lebanonadmin@salameh.local", superadmin)

    backup_info = {"backup_dir": "", "database_dump": "", "uploads_archive": ""}
    if not args.skip_backup:
        backup_info = create_backup(root_dir, mysqldump_path, env)

    clean_uploads(root_dir)
    assets = write_seed_assets(root_dir)
    reset_business_tables(db)

    rng = random.Random(20260316)
    today = date.today()
    expense_categories = fetch_expense_categories(db)
    hs_entries = fetch_hs_catalog_entries(db, count=60)

    customers = seed_customers(db)
    suppliers = seed_suppliers(db)
    products = seed_products(db, suppliers, hs_entries, assets, rng, uploader_id=superadmin)
    containers, drafts = create_containers_and_drafts(db, assets, today=today)
    order_specs = make_order_specs(customers)
    orders, order_items = seed_orders_and_items(db, order_specs, customers, products, drafts, assets, created_by=china_admin, today=today, uploader_id=lebanon_admin)
    receipt_info = seed_receipts_and_confirmations(db, orders, assets, received_by=warehouse_user, today=today)
    portal_links = seed_portal_tokens(db, customers, created_by=superadmin)
    seed_finance_and_supporting(db, orders, customers, suppliers, products, containers, drafts, expense_categories, user_ids)

    counts = summarize_counts(db, ["customers", "suppliers", "products", "orders", "order_items", "containers", "shipment_drafts", "warehouse_receipts", "expenses", "customer_deposits", "supplier_payments", "procurement_drafts", "order_templates", "notifications", "customer_portal_tokens"])
    manifest_path = build_manifest(root_dir, backup_info, customers, suppliers, products, orders, containers, drafts, portal_links, receipt_info["confirmation_links"], counts)

    print("Full QA dataset reset/seed completed.")
    print(json.dumps(counts, indent=2))
    print(f"Manifest: {manifest_path}")
    if backup_info["backup_dir"]:
        print(f"Backup: {backup_info['backup_dir']}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
