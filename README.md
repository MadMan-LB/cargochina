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

```bash
php backend/migrations/run.php
```

> **Note:** If you see "could not find driver", enable the MySQL PDO extension in `php.ini` (XAMPP: uncomment `extension=pdo_mysql`).

### 4. Create admin user (optional)

```sql
INSERT INTO users (email, password_hash, full_name) VALUES
('admin@salameh.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Admin');
-- Password: password
INSERT INTO user_roles (user_id, role_id) SELECT 1, id FROM roles WHERE code = 'SuperAdmin';
```

### 5. Run locally

- **XAMPP**: Start Apache and MySQL. Open `http://localhost/cargochina/`
- **PHP built-in server**: `php -S localhost:8080 -t .` then open `http://localhost:8080/`

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

## Full Specification

See [CLMS_README.md](CLMS_README.md) for the complete specification, state machine, RBAC, and DB_CHANGELOG.
