-- CLMS Migration 031: Seed dummy data for development/demo
-- Requires: migrations 001-030 applied (products.pieces_per_carton, unit_price; order_items.supplier_id; etc.)
-- Rollback: DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE id > 0);
--   DELETE FROM orders WHERE id > 0; DELETE FROM customer_deposits; DELETE FROM supplier_payments;
--   DELETE FROM supplier_interactions; DELETE FROM order_template_items; DELETE FROM order_templates;
--   DELETE FROM products; DELETE FROM order_attachments; DELETE FROM audit_log WHERE entity_type IN ('order','customer','supplier','product');
--   DELETE FROM customers WHERE code LIKE 'DUM%'; DELETE FROM suppliers WHERE code LIKE 'DUM%';

-- Dummy customers (25) - use columns that exist (code, name, payment_terms) for schema compatibility
INSERT IGNORE
INTO customers
(code, name, payment_terms) VALUES
('DUM_C001', 'Acme Trading Co', 'Net 30'),
('DUM_C002', 'Global Import Ltd', 'Net 45'),
('DUM_C003', 'Euro Fashion Hub', 'Cash'),
('DUM_C004', 'Middle East Wholesale', 'Net 30'),
('DUM_C005', 'Americas Direct', 'Net 60'),
('DUM_C006', 'Asia Pacific Imports', 'Net 30'),
('DUM_C007', 'Nordic Trading AB', 'Net 45'),
('DUM_C008', 'African Distributors', 'Cash'),
('DUM_C009', 'Ocean Freight LLC', 'Net 30'),
('DUM_C010', 'Desert Rose Trading', 'Net 30'),
('DUM_C011', 'Sunrise Imports', 'Net 45'),
('DUM_C012', 'Pacific Rim Co', 'Cash'),
('DUM_C013', 'Cargo Express', 'Net 30'),
('DUM_C014', 'Dragon Trading', 'Net 30'),
('DUM_C015', 'Phoenix Wholesale', 'Net 45'),
('DUM_C016', 'Tiger Imports', 'Cash'),
('DUM_C017', 'Lion Trading', 'Net 30'),
('DUM_C018', 'Eagle Freight', 'Net 60'),
('DUM_C019', 'Bear Trading Co', 'Net 30'),
('DUM_C020', 'Wolf Imports', 'Net 45'),
('DUM_C021', 'Hawk Logistics', 'Cash'),
('DUM_C022', 'Cobra Trading', 'Net 30'),
('DUM_C023', 'Viper Imports', 'Net 30'),
('DUM_C024', 'Falcon Freight', 'Net 45'),
('DUM_C025', 'Condor Trading', 'Cash');

-- Dummy suppliers (25) - use columns that exist after 001,008,010,029
INSERT IGNORE
INTO suppliers
(code, store_id, name, phone, factory_location, notes) VALUES
('DUM_S001', 'ST001', 'Mastertools Company Limited', '+86-21-30001001', 'Yiwu Industrial Zone', 'Metal tools specialist'),
('DUM_S002', 'ST002', 'Fashion Factory Co', '+86-20-30002002', 'Guangzhou Baiyun', 'Garments wholesale'),
('DUM_S003', 'ST003', 'Electronics Hub Ltd', '+86-755-30003003', 'Shenzhen Huaqiang', 'Consumer electronics'),
('DUM_S004', 'ST004', 'Home Goods Factory', '+86-571-30004004', 'Hangzhou Furniture Park', 'Furniture & decor'),
('DUM_S005', 'ST005', 'Toy World Ltd', '+86-20-30005005', 'Guangzhou Toy City', 'Toys & games'),
('DUM_S006', 'ST006', 'Textile Masters', '+86-28-30006006', 'Chengdu Textile Zone', 'Fabrics & textiles'),
('DUM_S007', 'ST007', 'Hardware Pro Co', '+86-21-30007007', 'Shanghai Hardware Park', 'Hardware supplies'),
('DUM_S008', 'ST008', 'Gift & Packaging', '+86-571-30008008', 'Yiwu Gift Market', 'Gift & packaging'),
('DUM_S009', 'ST009', 'Shoe Factory Ltd', '+86-20-30009009', 'Guangzhou Shoe City', 'Footwear'),
('DUM_S010', 'ST010', 'Kitchen Supplies', '+86-755-30010010', 'Shenzhen Kitchen Zone', 'Kitchenware'),
('DUM_S011', 'ST011', 'Sports Gear Co', '+86-21-30011011', 'Shanghai Sports Park', 'Sports equipment'),
('DUM_S012', 'ST012', 'Stationery World', '+86-571-30012012', 'Ningbo Stationery Hub', 'Office supplies'),
('DUM_S013', 'ST013', 'Cosmetics Factory', '+86-20-30013013', 'Guangzhou Beauty Park', 'Cosmetics'),
('DUM_S014', 'ST014', 'Lighting Pro Ltd', '+86-755-30014014', 'Shenzhen LED Zone', 'Lighting'),
('DUM_S015', 'ST015', 'Auto Parts Co', '+86-28-30015015', 'Chengdu Auto Park', 'Auto parts'),
('DUM_S016', 'ST016', 'Bags & Accessories', '+86-21-30016016', 'Shanghai Leather Park', 'Bags & bags'),
('DUM_S017', 'ST017', 'Jewelry Masters', '+86-571-30017017', 'Yiwu Jewelry Market', 'Jewelry'),
('DUM_S018', 'ST018', 'Pet Supplies Ltd', '+86-20-30018018', 'Guangzhou Pet Zone', 'Pet products'),
('DUM_S019', 'ST019', 'Baby Products Co', '+86-755-30019019', 'Shenzhen Baby Park', 'Baby goods'),
('DUM_S020', 'ST020', 'Outdoor Gear', '+86-21-30020020', 'Shanghai Outdoor Zone', 'Outdoor equipment'),
('DUM_S021', 'ST021', 'Health & Wellness', '+86-571-30021021', 'Hangzhou Health Park', 'Health products'),
('DUM_S022', 'ST022', 'Art & Craft', '+86-20-30022022', 'Guangzhou Craft Market', 'Arts & crafts'),
('DUM_S023', 'ST023', 'Furniture Direct', '+86-28-30023023', 'Chengdu Furniture Zone', 'Furniture'),
('DUM_S024', 'ST024', 'Tech Accessories', '+86-755-30024024', 'Shenzhen Tech Park', 'Tech accessories'),
('DUM_S025', 'ST025', 'General Merchandise', '+86-21-30025025', 'Shanghai General Hub', 'General goods');

-- Dummy products (15 explicit + more via loop simulation)
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S001' LIMIT
1), 0.08, 5, 'Carton', '847130', '无线鼠标 黑色', 'Wireless Mouse Black', 24, 3.50
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S001')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '无线鼠标 黑色'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S001' LIMIT
1), 0.12, 8, 'Carton', '847160', '机械键盘 RGB', 'Mechanical Keyboard RGB', 12, 25.00
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S001')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '机械键盘 RGB'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S002' LIMIT
1), 0.15, 4, 'Carton', '610910', '棉质T恤 白色', 'Cotton T-Shirt White', 50, 2.80
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S002')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '棉质T恤 白色'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S002' LIMIT
1), 0.20, 6, 'Carton', '620342', '牛仔裤 蓝色', 'Denim Jeans Blue', 20, 8.50
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S002')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '牛仔裤 蓝色'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S003' LIMIT
1), 0.03, 0.5, 'Box', '851712', '手机充电器 USB-C', 'Phone Charger USB-C', 100, 1.20
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S003')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '手机充电器 USB-C'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S003' LIMIT
1), 0.02, 0.2, 'Box', '851770', '蓝牙耳机', 'Bluetooth Earbuds', 50, 5.00
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S003')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '蓝牙耳机'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S004' LIMIT
1), 0.50, 15, 'Carton', '940360', '办公椅 可调节', 'Office Chair Adjustable', 4, 45.00
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S004')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '办公椅 可调节'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S005' LIMIT
1), 0.10, 3, 'Carton', '950300', '塑料积木 200件', 'Plastic Building Blocks 200pc', 24, 4.50
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S005')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '塑料积木 200件'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S006' LIMIT
1), 0.25, 10, 'Roll', '540710', '涤纶面料 2米宽', 'Polyester Fabric 2m Wide', 10, 3.20
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S006')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '涤纶面料 2米宽'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S007' LIMIT
1), 0.05, 8, 'Carton', '731815', '不锈钢螺丝 套装', 'Stainless Steel Screws Set', 100, 2.00
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S007')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '不锈钢螺丝 套装'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S008' LIMIT
1), 0.08, 2, 'Carton', '481910', '礼品盒 大号', 'Gift Box Large', 50, 0.80
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S008')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '礼品盒 大号'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S009' LIMIT
1), 0.15, 5, 'Pair', '640411', '运动鞋 男款', 'Sports Shoes Men', 20, 12.00
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S009')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '运动鞋 男款'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S010' LIMIT
1), 0.12, 4, 'Carton', '732393', '不锈钢锅 24cm', 'Stainless Steel Pot 24cm', 12, 8.00
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S010')
AND NOT EXISTS
(SELECT 1
FROM products
WHERE description_cn = '不锈钢锅 24cm'
LIMIT 1);
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S011' LIMIT
1), 0.08, 3, 'Carton', '950691', '瑜伽垫 6mm', 'Yoga Mat 6mm', 20, 6.50
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S011');
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S012' LIMIT
1), 0.04, 2, 'Box', '482010', '笔记本 A5', 'Notebook A5', 48, 1.50
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S012');
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S013' LIMIT
1), 0.06, 1.5, 'Box', '330499', '护手霜 50ml', 'Hand Cream 50ml', 72, 2.20
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S013');
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S014' LIMIT
1), 0.10, 2, 'Carton', '940520', 'LED台灯 可调光', 'LED Desk Lamp Dimmable', 24, 9.00
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S014');
INSERT IGNORE
INTO products
(supplier_id, cbm, weight, packaging, hs_code, description_cn, description_en, pieces_per_carton, unit_price)
SELECT (SELECT id
    FROM suppliers
    WHERE code = 'DUM_S015' LIMIT
1), 0.02, 0.5, 'Box', '870899', '汽车手机支架', 'Car Phone Mount', 100, 1.80
WHERE EXISTS
(SELECT 1
FROM suppliers
WHERE code = 'DUM_S015');

-- Dummy orders (20) - only if no dummy orders exist yet (idempotent)
INSERT INTO orders
    (customer_id, supplier_id, expected_ready_date, currency, status, created_by)
SELECT c.id, s.id, DATE_ADD(CURDATE(), INTERVAL FLOOR
(7 + RAND
() * 30) DAY), 'USD',
  ELT
(1 + FLOOR
(RAND
() * 5), 'Draft', 'Submitted', 'Approved', 'InTransitToWarehouse', 'ReceivedAtWarehouse'),
(SELECT id
FROM users LIMIT
1)
FROM customers c, suppliers s
WHERE c.code LIKE 'DUM_C%' AND s.code LIKE 'DUM_S%'
  AND NOT EXISTS
(SELECT 1
FROM orders o2 JOIN customers c2 ON o2.customer_id = c2.id
WHERE c2.code LIKE 'DUM_C%'
LIMIT 1)
ORDER BY RAND
()
LIMIT 20;

-- Dummy order items - 2 items per order for orders with dummy customers
INSERT INTO order_items
    (order_id, product_id, supplier_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, unit_price, total_amount, description_cn, description_en)
SELECT o.id, (SELECT id
    FROM products
    ORDER BY RAND() LIMIT 1), o.supplier_id, c.name, c.code, 20, 24, 480, 'pieces', 2.4, 144, 5.00, 2400, '示例产品 A', 'Sample Product A'
FROM orders o
    JOIN customers c ON c.id = o.customer_id
WHERE o.customer_id IN (SELECT id
    FROM customers
    WHERE code LIKE 'DUM_C%')
    AND NOT EXISTS (SELECT 1
    FROM order_items oi
    WHERE oi.order_id = o.id);
INSERT INTO order_items
    (order_id, product_id, supplier_id, item_no, shipping_code, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, unit_price, total_amount, description_cn, description_en)
SELECT o.id, (SELECT id
    FROM products
    ORDER BY RAND() LIMIT 1), o.supplier_id, c.name, c.code, 15, 36, 540, 'pieces', 1.8, 108, 3.50, 1890, '示例产品 B', 'Sample Product B'
FROM orders o
    JOIN customers c ON c.id = o.customer_id
WHERE o.customer_id IN (SELECT id
    FROM customers
    WHERE code LIKE 'DUM_C%')
    AND (SELECT COUNT(*)
    FROM order_items oi2
    WHERE oi2.order_id = o.id) = 1;

-- Customer deposits (15) - only if no dummy deposits exist (idempotent)
INSERT INTO customer_deposits
    (customer_id, amount, currency, payment_method, reference_no, notes, created_by)
SELECT c.id, 500 + (RAND() * 4500), 'USD', 'Bank Transfer', CONCAT('DEP-', FLOOR(1000 + RAND() * 9000)), 'Dummy deposit', (SELECT id
    FROM users LIMIT
1)
FROM customers c
WHERE c.code LIKE 'DUM_C%'
  AND NOT EXISTS
(SELECT 1
FROM customer_deposits cd JOIN customers c2 ON cd.customer_id = c2.id
WHERE c2.code LIKE 'DUM_C%'
LIMIT 1)
ORDER BY RAND
()
LIMIT 15;

-- Supplier payments (15) - only if no dummy supplier payments exist (idempotent)
INSERT INTO supplier_payments
    (supplier_id, order_id, amount, currency, payment_type, notes)
SELECT s.id, (SELECT id
    FROM orders
    ORDER BY RAND() LIMIT 1), 300 + (RAND() * 2700), 'USD', 'partial', 'Dummy payment'
FROM suppliers s
WHERE s.code LIKE 'DUM_S%'
    AND NOT EXISTS (SELECT 1
    FROM supplier_payments sp JOIN suppliers s2 ON sp.supplier_id = s2.id
    WHERE s2.code LIKE 'DUM_S%'
LIMIT 1)
ORDER BY RAND
()
LIMIT 15;

-- Supplier interactions (25) - only if no dummy interactions exist (idempotent)
INSERT INTO supplier_interactions
    (supplier_id, interaction_type, content, created_by)
SELECT s.id, ELT(1 + FLOOR(RAND() * 3), 'visit', 'quote', 'note'),
    JSON_OBJECT('note', CONCAT('Dummy ', ELT(1 + FLOOR(RAND() * 3), 'visit', 'quote', 'note'), ' ', NOW())),
    (SELECT id
    FROM users LIMIT
1)
FROM suppliers s
WHERE s.code LIKE 'DUM_S%'
  AND NOT EXISTS
(SELECT 1
FROM supplier_interactions si JOIN suppliers s2 ON si.supplier_id = s2.id
WHERE s2.code LIKE 'DUM_S%'
LIMIT 1)
ORDER BY RAND
()
LIMIT 25;

-- Order templates (5)
INSERT IGNORE
INTO order_templates
(name, created_by)
SELECT CONCAT('DUM Template ', n), (SELECT id
    FROM users LIMIT
1)
FROM
(
    SELECT 1 n
UNION
    SELECT 2
UNION
    SELECT 3
UNION
    SELECT 4
UNION
    SELECT 5)
nums;

-- Order template items (1 per template)
INSERT INTO order_template_items
    (template_id, sort_order, item_no, shipping_code, product_id, supplier_id, description_cn, description_en, cartons, qty_per_carton, quantity, unit, declared_cbm, declared_weight, unit_price, total_amount)
SELECT t.id, 1, 'ITEM-001', 'SHIP001', (SELECT id
    FROM products LIMIT
1),
(SELECT id
FROM suppliers
WHERE code LIKE 'DUM_S%'
LIMIT 1), '模板产品', 'Template Product', 20, 24, 480, 'pieces', 2.4, 144, 5.00, 2400
FROM order_templates t
WHERE t.name LIKE 'DUM Template %' AND NOT EXISTS
(SELECT 1
FROM order_template_items
WHERE template_id = t.id);

-- Dummy users (3 extra)
INSERT IGNORE
INTO users
(email, password_hash, full_name) VALUES
('china.admin@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'China Admin Demo'),
('warehouse@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Warehouse Staff Demo'),
('employee@demo.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');
INSERT IGNORE
INTO user_roles
(user_id, role_id)
SELECT u.id, r.id
FROM users u, roles r
WHERE u.email = 'china.admin@demo.com' AND r.code = 'ChinaAdmin'
    AND NOT EXISTS (SELECT 1
    FROM user_roles ur
    WHERE ur.user_id = u.id AND ur.role_id = r.id);
INSERT IGNORE
INTO user_roles
(user_id, role_id)
SELECT u.id, r.id
FROM users u, roles r
WHERE u.email = 'warehouse@demo.com' AND r.code = 'WarehouseStaff'
    AND NOT EXISTS (SELECT 1
    FROM user_roles ur
    WHERE ur.user_id = u.id AND ur.role_id = r.id);
INSERT IGNORE
INTO user_roles
(user_id, role_id)
SELECT u.id, r.id
FROM users u, roles r
WHERE u.email = 'employee@demo.com' AND r.code = 'ChinaEmployee'
    AND NOT EXISTS (SELECT 1
    FROM user_roles ur
    WHERE ur.user_id = u.id AND ur.role_id = r.id);

-- Warehouse receipts for some Approved/InTransit orders (idempotent)
INSERT INTO warehouse_receipts
    (order_id, actual_cartons, actual_cbm, actual_weight, receipt_condition, notes, received_by)
SELECT o.id, 25, 2.5, 150, 'good', 'Dummy receipt', (SELECT id
    FROM users LIMIT
1)
FROM orders o
WHERE o.status IN
('Approved', 'InTransitToWarehouse')
  AND o.customer_id IN
(SELECT id
FROM customers
WHERE code LIKE 'DUM_C%')
AND NOT EXISTS
(SELECT 1
FROM warehouse_receipts wr
WHERE wr.order_id = o.id)
LIMIT 5;

-- Dummy containers (5)
INSERT IGNORE
INTO containers
(code, max_cbm, max_weight, status) VALUES
('DUM-CNT-001', 28.0, 22000, 'available'),
('DUM-CNT-002', 28.0, 22000, 'available'),
('DUM-CNT-003', 58.0, 26500, 'available'),
('DUM-CNT-004', 28.0, 22000, 'available'),
('DUM-CNT-005', 58.0, 26500, 'available');

-- Dummy shipment drafts (3) - only if no dummy drafts exist (idempotent)
INSERT INTO shipment_drafts
    (container_id, status)
SELECT c.id, 'draft'
FROM containers c
WHERE c.code LIKE 'DUM-CNT%'
    AND NOT EXISTS (SELECT 1
    FROM shipment_drafts sd JOIN containers c2 ON sd.container_id = c2.id
    WHERE c2.code LIKE 'DUM-CNT%'
LIMIT 1)
LIMIT 3;
