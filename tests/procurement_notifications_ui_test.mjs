import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const draft = readFileSync("frontend/js/procurement_drafts.js", "utf8");
const css = readFileSync("frontend/css/style.css", "utf8");
const notifications = readFileSync("frontend/js/notifications.js", "utf8");
const translations = readFileSync("includes/ui_translations.php", "utf8");

// Internal identifiers are generated; customer packing-list references remain editable.
for (const name of ['draft-item-item-no','draft-shared-content-item-no']) {
    const input=draft.match(new RegExp('<input[^>]*class="[^"\\n]*'+name+'(?: |")'+'[^>]*>'))?.[0] || '';
    assert.match(input, /\breadonly\b/, 'internal identifier must not allow arbitrary edits');
}
for (const name of ['draft-item-item-number','draft-shared-content-item-number']) {
    const input=draft.match(new RegExp('<input[^>]*class="[^"\\n]*'+name+'(?: |")'+'[^>]*>'))?.[0] || '';
    assert.ok(input, 'packing-list reference input missing');
    assert.doesNotMatch(input, /\breadonly\b/, 'packing-list reference should be editable');
}
assert.match(draft, /card\.dataset\.itemNoSource\s*=\s*initial\.item_no_source/, 'loaded provenance is missing');
assert.match(draft, /row\.dataset\.itemNoSource\s*=\s*initial\.item_no_source/, 'shared loaded provenance is missing');
assert.match(draft, /target\.source\s*!==\s*"generated"\s*\|\|\s*target\.input\.value\s*!==\s*""/, "suggestions can overwrite existing values");
assert.match(draft, /incrementFinalItemNumber/, "final numeric portion algorithm is missing");
assert.match(draft, /item_no_source:\s*row\.dataset\.itemNoSource/, "shared item provenance is not serialized");
assert.match(draft, /item_no_source:\s*sharedCartonEnabled/, "normal item provenance is not serialized");
assert.match(css, /@media \(max-width: 768px\)[\s\S]*\.draft-action-group[\s\S]*min-height:\s*42px/, "mobile action layout/tap target rule is missing");
assert.match(css, /notification-card-link:focus-visible/, "notification keyboard focus state is missing");
assert.match(notifications, /\/notifications\/\$\{id\}\/open/, "notification open endpoint is not used");
assert.match(notifications, /event\?\.stopPropagation|event\.stopPropagation/, "notification nested actions do not prevent double navigation");
for (const label of ["Unavailable", "Open related record", "Suggested automatically; you can type, paste, replace, or clear it."]) {
    assert.ok(translations.includes(`'${label}' =>`), `Chinese translation missing: ${label}`);
}

console.log("PASS procurement and notifications UI static regression checks");
