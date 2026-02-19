---
name: api-endpoint
description: Adds API endpoints with validation, auth, error handling, consistent response shape, and tests. Use when creating or modifying REST/API routes, endpoints, or when the user asks for new API functionality.
---

# API Endpoint Skill

## Workflow

For any new or modified endpoint:

1. **Propose a short plan** (method, path, auth, main logic).
2. **Implement endpoint** with validation, auth, and error handling.
3. **Use consistent response shape** across the API.
4. **Add tests** (at least smoke; prefer unit + integration where applicable).

## Validation

- Validate request body/query/params (types, required fields, formats).
- Return clear 400 errors with field-level messages when invalid.
- Use project validation library or schema (e.g. Zod, Joi, class-validator).

## Authorization

- Enforce auth for protected routes (JWT, session, API key).
- Check permissions/roles where applicable.
- Return 401/403 with consistent error format.

## Error Handling

- Catch and map errors to HTTP status codes.
- Use consistent error response shape (e.g. `{ error, message, code }`).
- Log errors with structured logs; never expose internals in responses.

## Response Shape

- Use project convention (e.g. `{ data, meta }` or `{ success, result }`).
- Document request/response examples in code or docs.
- Keep pagination, sorting, and filtering consistent with existing endpoints.

## Tests

- At least smoke test: call endpoint, assert status and basic shape.
- Add unit tests for validation and business logic.
- Add integration test if endpoint touches DB or external services.

## Checklist

- [ ] Request/response examples
- [ ] Validation on inputs
- [ ] Authorization check
- [ ] Consistent error handling
- [ ] Tests (at least smoke)
