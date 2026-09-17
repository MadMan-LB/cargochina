<?php

/** A free-text reference. Never send this value to internal number reservations. */
function clmsPackingListItemNumber(mixed $value): ?string
{
    if ($value === null) return null;
    if (!is_string($value)) {
        clmsRejectPackingListItemNumber('Item Number must be text.');
    }
    if (mb_strlen($value, 'UTF-8') > 150) {
        clmsRejectPackingListItemNumber('Item Number must be 150 characters or fewer.');
    }
    if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
        clmsRejectPackingListItemNumber('Item Number cannot contain line breaks or control characters.');
    }
    return $value; // Intentionally no trim, case conversion, or numeric conversion.
}
function clmsRejectPackingListItemNumber(string $message): never
{
    if (function_exists('jsonError')) jsonError($message, 400, ['items.item_number' => $message]);
    throw new InvalidArgumentException($message);
}

/** Search decoded reference strings, not JSON's escaped representation (e.g. A\/22). */
function clmsSharedCartonIdentifierSearch(string $column, string $identifier): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/i', $column)
        || !in_array($identifier, ['item_no', 'item_number'], true)) {
        throw new InvalidArgumentException('Invalid shared-carton identifier search.');
    }
    return "CASE WHEN JSON_VALID($column) THEN JSON_SEARCH(LOWER($column), 'one', LOWER(?), NULL, '$[*].$identifier') IS NOT NULL ELSE 0 END";
}
