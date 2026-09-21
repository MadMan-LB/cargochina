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
function clmsSharedCartonIdentifierSearch(string $column, string $identifier, ?PDO $pdo = null, ?string $like = null, ?array &$params = null): string
{
    if (!preg_match('/^[a-z_][a-z0-9_]*\.[a-z_][a-z0-9_]*$/i', $column)
        || !in_array($identifier, ['item_no', 'item_number'], true)) {
        throw new InvalidArgumentException('Invalid shared-carton identifier search.');
    }
    if ($pdo === null) {
        return "CASE WHEN JSON_VALID($column) THEN JSON_SEARCH(LOWER($column), 'one', LOWER(?), NULL, '$[*].$identifier') IS NOT NULL ELSE 0 END";
    }
    if ($like === null || $params === null) throw new InvalidArgumentException('Search pattern and parameters are required.');
    if (clmsSupportsJsonSearch($pdo)) {
        $params[] = $like;
        return clmsSharedCartonIdentifierSearch($column, $identifier);
    }
    // Decode in PHP on legacy servers, then filter by primary key inside the
    // original SQL. Visibility, counts, pagination and exports keep one filter.
    // No cross-request cache: newly edited references must be searchable at once.
    $regex = '';
    $chars = preg_split('//u', $like, -1, PREG_SPLIT_NO_EMPTY);
    for ($i = 0; $i < count($chars); $i++) {
        $char = $chars[$i];
        if ($char === '\\' && isset($chars[$i + 1])) $regex .= preg_quote($chars[++$i], '~');
        else $regex .= $char === '%' ? '.*' : ($char === '_' ? '.' : preg_quote($char, '~'));
    }
    $matches = clmsSharedCartonMatchingIds($pdo, static function (array $content) use ($identifier, $regex): bool {
        $value = $content[$identifier] ?? null;
        return is_string($value) && preg_match('~^' . $regex . '$~isuD', $value) === 1;
    });
    $params[] = implode(',', $matches);
    $alias = explode('.', $column)[0];
    return "FIND_IN_SET($alias.id, ?) > 0";
}

function clmsSupportsJsonSearch(PDO $pdo): bool
{
    $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    $maria = stripos($version, 'MariaDB') !== false;
    $version = preg_replace('/^5\.5\.5-(?=\d)/', '', $version);
    return version_compare($version, $maria ? '10.2.3' : '5.7.8', '>=');
}

/** Legacy fallback only; returned IDs remain subject to the caller's SQL permissions/filters. */
function clmsSharedCartonMatchingIds(PDO $pdo, callable $matchesContent): array
{
    $matches = [];
    $rows = $pdo->query("SELECT id, shared_carton_contents FROM order_items WHERE shared_carton_contents IS NOT NULL AND shared_carton_contents <> '' AND shared_carton_contents <> '[]'");
    while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
        $contents = json_decode((string) $row['shared_carton_contents'], true);
        if (!is_array($contents)) continue;
        foreach ($contents as $content) {
            if (is_array($content) && $matchesContent($content)) {
                $matches[] = (int) $row['id'];
                break;
            }
        }
    }
    return $matches;
}
