---
name: bugfix
description: Fixes bugs using repro steps, root cause analysis, minimal patch, and regression test. Use when debugging, fixing defects, or when the user reports a bug or unexpected behavior.
---

# Bugfix Skill

## Workflow

1. **Repro steps** – Reproduce the bug reliably.
2. **Root cause** – Identify why it happens.
3. **Minimal patch** – Fix with smallest change that resolves it.
4. **Regression test** – Add test to prevent recurrence.

## Repro Steps

- Document exact steps to trigger the bug.
- Note environment, inputs, and expected vs actual behavior.
- If unable to repro, state assumptions and ask for more info.

## Root Cause

- Trace from symptom to source (logs, stack traces, data flow).
- Identify the specific line or condition causing the issue.
- Avoid fixing symptoms; fix the underlying cause.

## Minimal Patch

- Change only what is necessary to fix the bug.
- Prefer minimal diffs; do not rewrite whole files unless needed.
- Avoid introducing new behavior or refactors in the same change.

## Regression Test

- Add a test that fails before the fix and passes after.
- Prefer unit test; use integration test if the bug spans layers.
- Name the test to describe the bug scenario.

## Checklist

- [ ] Repro steps documented
- [ ] Root cause identified
- [ ] Minimal patch applied
- [ ] Regression test added
