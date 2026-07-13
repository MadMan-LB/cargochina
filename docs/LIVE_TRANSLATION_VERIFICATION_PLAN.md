# Live Translation Verification Plan

**Status:** PLAN ONLY — NOT EXECUTED  
**Production authorization:** none  
**Allowed target when this plan is approved:** an isolated verification database and provider-approved harmless test text only

This plan verifies a real translation provider without placing customer, supplier, order, shipment, financial, credential, or other production business data in the provider request or in a production database. Passing it does not authorize deployment or bilingual backfill.

## 1. Required configuration and ownership

| Variable | Required value/control | Verification without secret disclosure |
|---|---|---|
| `APP_ENV` | `testing` for the verification run | Print the literal non-production value. Refuse `production`. |
| `DB_HOST`, `DB_PORT`, `DB_NAME` | Explicit isolated target approved for disposable verification records | Print host, port, and database; require typed confirmation. Refuse the production database name. |
| `TRANSLATION_PROVIDER` | `libretranslate` or `generic` | Print provider name. `disabled` is a valid safe runtime mode but cannot pass live-provider verification. |
| `TRANSLATION_API_URL` | Approved provider endpoint, preferably HTTPS, matching the organization's allowlist | Print scheme and host only. Reject an empty URL, embedded credentials, unapproved host, or non-HTTPS URL unless security grants a documented exception. |
| `TRANSLATION_API_KEY` | Provider credential from the secret manager when the endpoint requires one | Report only `configured=true/false`; never print, hash, log, place in command history, or store in evidence. |
| `TRANSLATION_TIMEOUT_SECONDS` | Integer `1`–`30`; recommended initial value `8` | Print the integer. The service clamps values to this range. |

The release owner supplies the isolated database; security approves the provider host and credential handling; the application owner performs the test; a bilingual reviewer judges output quality. The key must be injected for the process from the secret manager and removed after the session.

## 2. Harmless test corpus

Create a nonce such as UTC timestamp plus eight random hexadecimal characters. Send only these synthetic strings:

- English to Chinese: `CLMS translation verification <nonce>. No customer or shipment data.`
- Chinese to English: `CLMS 翻译验证 <nonce>。不包含客户或货运数据。`

Do not substitute real descriptions, names, addresses, tracking references, item numbers, prices, or historical text. The nonce proves the response is from this run and avoids a false pass from an old cache.

## 3. Preflight gates

1. Confirm there is no route from the test process to the production business database. Record the isolated target identity and empty/baseline row counts for `translations`, `translation_jobs`, and `translation_manual_corrections`.
2. Confirm migration 072 exists in the isolated database. Do not apply it to production under this plan.
3. Confirm the provider contract, data location/retention, logging policy, quota, maximum payload, rate limit, and acceptable-use terms have been approved by the responsible owner.
4. Resolve the provider host, establish TLS, and inspect certificate validity without placing the API key in output. Failure is a stop, not a reason to disable certificate validation.
5. Confirm application/PHP logs and the provider test console do not echo secrets. Evidence may show provider host, HTTP status, elapsed time, payload byte length, and a redacted correlation ID only.

## 4. Execution sequence after separate approval

| Step | Action | Expected result | Evidence |
|---|---|---|---|
| 1 | Call the approved provider directly with the English synthetic string using its documented JSON contract. | HTTP 2xx, valid JSON, non-empty Chinese result within the configured timeout. | UTC time, provider host, status, duration, request/response byte counts, correlation ID, reviewer result; no request body/key. |
| 2 | Repeat direct call Chinese to English. | HTTP 2xx, valid JSON, non-empty English result. | Same redacted evidence. |
| 3 | Through `TranslationService::translateDetailed`, translate a new English nonce against the isolated database. | `status=translated`, `provenance=automatic`, correct language direction, and one cache row. | Redacted result excerpt or reviewer pass, status/provenance, isolated row-count delta. |
| 4 | Repeat the exact request. | `status=translated`, `provenance=cache`; no second provider call and no duplicate logical cache row. | Provider request count plus database key/count evidence. |
| 5 | Save a synthetic manual correction, then repeat the request. | `status=manual`, `provenance=manual`, exact manual text returned; the provider is not called. | Manual row and request-count evidence with the synthetic source only. |
| 6 | Change one character in the source and request again. | A new source hash is used. The previous manual correction is not applied to changed source text. | Old/new hash equality check reported as `false`; never treat hashes as credential protection. |
| 7 | Exercise application draft/order translation in the isolated fixture only. | The submitted original is preserved exactly; translation fills only the missing language value and does not alter amounts, parties, item numbers, or status. | Before/after fixture record and audit log. |

## 5. Failure, timeout, and retry verification

Provider failure scenarios should use an approved stub or provider sandbox. Do not deliberately generate abusive traffic or quota exhaustion against a paid live endpoint.

| Scenario | Required result |
|---|---|
| DNS/TLS/connection failure | Empty translated value, `status=failed`, original retained, bounded timeout, safe error queued/logged, business save still available. |
| HTTP 401/403 | Fail closed as a provider error; key absent from response, database, and application log. |
| HTTP 429 | No tight retry loop. A failed/pending job remains available for controlled later retry; honor provider retry guidance operationally. |
| HTTP 500 | Same non-blocking failure behavior; no fabricated `[EN]`/`[ZH]` placeholder. |
| Invalid JSON or empty translation | `status=failed`; source preserved; no empty value recorded as a successful translation. |
| Response after timeout | Application returns within the configured maximum and does not commit a late/unknown result. |

Current code records `next_retry_at` 15 minutes later but does not by itself prove a production worker, backoff policy, or quota-aware retry processor. Those are operational gaps; this plan must not claim retry automation is verified merely because a row is queued.

## 6. Manual-correction and replacement rules

These rules are the acceptance policy for live verification and subsequent backfill:

1. A manual correction for the exact source hash and language pair always wins over automatic and cached output.
2. A source-text change creates a different source hash. It must be reviewed/translated as new content; a correction for the old text must not silently carry forward.
3. Automatic output may replace an automatic cache entry for the same exact source/language pair only through a separately controlled refresh. It must never overwrite a manual correction.
4. Existing populated business-language fields and populated/manual bilingual registry values must not be overwritten by automatic backfill.
5. Manual correction create/update must retain actor and correction time. If a correction must be reversed, capture the prior value and actor in auditable history before replacement; migration 072 alone does not provide full correction-version history.

## 7. Proof that provider failure does not block business work

In the isolated database, point the process at an approved unavailable stub and execute these synthetic workflows:

- Save a procurement draft with original-language description and no translation.
- Edit and approve a synthetic order only if all unrelated validation is satisfied.
- Receive a synthetic quantity through the isolated receiving fixture.
- Reconcile the synthetic financial fixture without involving translation.

For each workflow, prove the original-language value and business transaction persist, the missing translation remains blank/pending rather than fabricated, and no amount, balance, stock quantity, status, or item number changes because of translation failure. Compare the relevant before/after counts and exact monetary strings.

## 8. Privacy and logging inspection

Inspect application, web-server, PHP, reverse-proxy, and provider-side test logs for the run window. Pass only if:

- no API key or authorization material is present;
- no production business text was sent or logged;
- evidence contains only the approved synthetic corpus;
- provider errors are bounded and do not include raw request bodies;
- access to translation evidence follows the organization's retention policy.

The current service deliberately avoids logging the key, but a full infrastructure log inspection remains required because intermediaries can log request bodies independently of application code.

## 9. Pass/fail gate and cleanup

Pass requires both language directions, valid provider responses, timeout/error handling, cache behavior, manual precedence, source-change behavior, non-blocking business proof, output-quality approval, and clean privacy/log evidence. Any missing item is `IMPLEMENTED, REQUIRES ENVIRONMENT VERIFICATION` or `BLOCKED`, not a partial production pass.

After evidence capture, delete only the isolated synthetic fixture through the approved test cleanup process, revoke the temporary credential if one was issued, and confirm the production database row counts were never queried or changed. Do not run bilingual backfill and do not enable the provider in production as part of this plan.
