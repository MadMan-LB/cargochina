<?php

final class OrderCountryService
{
    private static array $countryCache = [];

    public static function fetchCustomerCountries(PDO $pdo, int $customerId): array
    {
        $stmt = $pdo->prepare(
            "SELECT ccs.country_id, ccs.shipping_code, co.code as country_code, co.name as country_name
             FROM customer_country_shipping ccs
             JOIN countries co ON co.id = ccs.country_id
             WHERE ccs.customer_id = ?
             ORDER BY co.name, ccs.id"
        );
        $stmt->execute([$customerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function resolveDestinationCountryId(PDO $pdo, int $customerId, ?int $requestedCountryId): ?int
    {
        $countries = self::fetchCustomerCountries($pdo, $customerId);
        if (!$countries) {
            return $requestedCountryId ?: null;
        }

        $allowedIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int) ($row['country_id'] ?? 0),
            $countries
        )));
        $allowedIds = array_values(array_filter($allowedIds));
        if (!$allowedIds) {
            return $requestedCountryId ?: null;
        }

        if (count($allowedIds) === 1) {
            $onlyId = $allowedIds[0];
            if ($requestedCountryId !== null && $requestedCountryId !== $onlyId) {
                jsonError('Destination country must match the selected customer country.', 400);
            }
            return $onlyId;
        }

        if ($requestedCountryId === null) {
            jsonError('Destination country is required when the selected customer has multiple countries.', 400);
        }

        if (!in_array($requestedCountryId, $allowedIds, true)) {
            jsonError('Destination country must be one of the selected customer countries.', 400);
        }

        return $requestedCountryId;
    }

    public static function resolveShippingCode(PDO $pdo, int $customerId, ?int $destinationCountryId, ?string $fallbackDefault = null): ?string
    {
        $fallbackDefault = self::normalizeShippingCode($fallbackDefault);

        $stmt = $pdo->prepare("SELECT default_shipping_code FROM customers WHERE id = ? LIMIT 1");
        $stmt->execute([$customerId]);
        $customerDefault = self::normalizeShippingCode((string) ($stmt->fetchColumn() ?: ''));

        foreach (self::fetchCustomerCountries($pdo, $customerId) as $countryRow) {
            if ((int) ($countryRow['country_id'] ?? 0) === (int) ($destinationCountryId ?? 0)) {
                $countryCode = self::normalizeShippingCode((string) ($countryRow['shipping_code'] ?? ''));
                if ($countryCode !== null) {
                    return $countryCode;
                }
            }
        }

        return $customerDefault ?: $fallbackDefault;
    }

    public static function resolveContainerDestinationCountryId(PDO $pdo, array $container): ?int
    {
        foreach ([
            (string) ($container['destination_country_id'] ?? ''),
            (string) ($container['destination_country'] ?? ''),
            (string) ($container['destination'] ?? ''),
        ] as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '') {
                continue;
            }
            if (ctype_digit($candidate)) {
                $countryId = (int) $candidate;
                foreach (self::allCountries($pdo) as $country) {
                    if ((int) $country['id'] === $countryId) {
                        return $countryId;
                    }
                }
            }

            $normalizedCandidate = self::normalizeCountryToken($candidate);
            if ($normalizedCandidate === '') {
                continue;
            }

            foreach (self::allCountries($pdo) as $country) {
                $code = self::normalizeCountryToken((string) ($country['code'] ?? ''));
                $name = self::normalizeCountryToken((string) ($country['name'] ?? ''));
                if ($normalizedCandidate === $code || $normalizedCandidate === $name) {
                    return (int) $country['id'];
                }
            }
        }

        return null;
    }

    public static function orderMatchesContainer(PDO $pdo, array $order, array $container): bool
    {
        $containerCountryId = self::resolveContainerDestinationCountryId($pdo, $container);
        if (!$containerCountryId) {
            return true;
        }

        $orderCountryId = (int) ($order['destination_country_id'] ?? 0);
        return $orderCountryId > 0 && $orderCountryId === $containerCountryId;
    }

    public static function normalizeShippingCode(?string $value): ?string
    {
        $value = strtoupper(trim((string) $value));
        return $value !== '' ? $value : null;
    }

    private static function allCountries(PDO $pdo): array
    {
        $key = spl_object_id($pdo);
        if (!array_key_exists($key, self::$countryCache)) {
            $rows = $pdo->query("SELECT id, code, name FROM countries ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
            self::$countryCache[$key] = $rows ?: [];
        }
        return self::$countryCache[$key];
    }

    private static function normalizeCountryToken(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '', $value) ?? '';
        if ($value === 'il' || $value === 'israel') {
            return 'ps';
        }
        return $value;
    }
}
