---
name: db-migration
description: Given a schema change request, generates migration file, updates models, updates docs, and adds basic test/seed. Use when adding or modifying database tables, columns, indexes, constraints, or when the user asks for migrations, schema changes, or DB updates.
---

# DB Migration Skill

## Workflow

For any schema change request:

1. **Propose a short plan** before editing (tables/columns affected, migration type).
2. **Generate migration file** with up/down (or forward/rollback).
3. **Update models** (ORM entities, types, relationships).
4. **Update schema docs** (schema notes, ERD, or README).
5. **Add basic test/seed** (smoke test, seed data for new fields).

## Migration File

- Use project convention (e.g. `YYYYMMDDHHMMSS_description.sql` or framework migrations).
- Include **up** (apply) and **down** (rollback) steps.
- Add a **rollback note** in comments or docs describing how to revert.

## Model Updates

- Sync ORM/entity definitions with new schema.
- Update types, constraints, and relationships.
- Ensure nullable/defaults match migration.

## Schema Notes

- Document new/changed tables and columns.
- Note indexes, FKs, and constraints.
- Update any schema diagram or README references.

## Test/Seed

- Add smoke test that schema applies and rolls back cleanly.
- Add seed data for new required fields or enums.
- Keep seeds idempotent where possible.

## Checklist

- [ ] Migration file with up/down
- [ ] Rollback note
- [ ] Updated schema notes
- [ ] Models/entities in sync
- [ ] Basic test or seed
