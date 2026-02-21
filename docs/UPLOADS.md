# CLMS File Upload Configuration

## Overview

CLMS supports photo uploads for orders and receiving. Limits are configurable via environment variables or `system_config` table.

## Config Keys

| Key | Default | Description |
|-----|---------|-------------|
| `UPLOAD_MAX_MB` | 8 | Max file size in megabytes |
| `UPLOAD_ALLOWED_TYPES` | jpg,jpeg,png,webp | Comma-separated allowed extensions |

## Environment Variables

Add to `.env` (never commit secrets):

```env
UPLOAD_MAX_MB=8
UPLOAD_ALLOWED_TYPES=jpg,jpeg,png,webp
```

## PHP Limits (Production)

If uploads fail with "File exceeds server limit", increase PHP limits.

### php.ini

```ini
upload_max_filesize = 8M
post_max_size = 10M
max_execution_time = 60
memory_limit = 128M
```

### .htaccess (Apache)

If you cannot edit php.ini:

```apache
php_value upload_max_filesize 8M
php_value post_max_size 10M
```

### .user.ini (shared hosting, e.g. Hostinger)

Some hosts use `.user.ini` instead of `.htaccess`:

```ini
upload_max_filesize = 8M
post_max_size = 10M
```

## Client-Side Behavior

- **Pre-check**: Before upload, client fetches `GET /api/v1/config/upload` for `max_upload_mb` and `allowed_types`.
- **Rejection**: If file exceeds limit, shows toast: "File too large (X MB). Max allowed Y MB."
- **Auto-compress**: For images over the limit, client downscales to max 1600px and converts to JPEG quality 0.8, then retries.
- **Progress**: Upload progress shown during transfer.
- **Submit disabled**: Submit button disabled while uploads are in progress.

## API

- `GET /api/v1/config/upload` — Returns `{ max_upload_mb, allowed_types }` (authenticated).
- `POST /api/v1/upload` — Multipart `file` — Returns `{ data: { path, url } }` or error with `max_upload_mb`, `file_size_mb`, `request_id`.

## Rollback

To revert to 5MB limit:

```env
UPLOAD_MAX_MB=5
```

Or remove `UPLOAD_MAX_MB` and set `UPLOAD_MAX_SIZE=5242880` (bytes).
