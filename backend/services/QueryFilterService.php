<?php

/** Request validation shared by operational lists, lookup endpoints and their exports. */
final class QueryFilterService
{
    public static function validate(array $query, string $resource): void
    {
        foreach ($query as $key => $value) {
            if ($value === null || $value === '') continue;
            if ($key === 'status' && is_array($value) && in_array($resource, ['orders','draft-orders','receiving','warehouse-stock','containers'], true)) {
                if (count($value) > 30 || !array_is_list($value)) jsonError('Invalid status filter', 422);
                foreach ($value as $entry) if (!is_string($entry)) jsonError('Invalid status filter', 422);
                continue;
            }
            if (!is_scalar($value) || is_bool($value)) jsonError('Invalid ' . $key . ' filter', 422);
            if (preg_match('/(^|_)id$/', $key) || in_array($key, ['limit','page','offset','customer_offset','supplier_offset'], true)) {
                $minimum = str_ends_with($key, 'offset') ? 0 : 1;
                if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $minimum || (float)$value > 4294967295) jsonError('Invalid ' . $key . ' filter', 422);
            }
            if (in_array($key, ['date_from','date_to','created_from','created_to','expected_from','expected_to'], true)) {
                $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
                if (!$date || $date->format('Y-m-d') !== $value) jsonError('Invalid ' . $key . ' date', 422);
            }
            if (in_array($key, ['q','shipping_code','brand','hs_code'], true) && (!is_string($value) || !mb_check_encoding($value,'UTF-8') || mb_strlen($value)>500)) jsonError('Invalid ' . $key . ' text', 422);
        }
        foreach (['date','created','expected'] as $prefix) {
            if (!empty($query[$prefix.'_from']) && !empty($query[$prefix.'_to']) && $query[$prefix.'_from'] > $query[$prefix.'_to']) jsonError('Start date must not follow end date', 422);
        }
        $enums = ['currency'=>['USD','RMB'], 'order'=>['ASC','DESC','asc','desc'], 'direction'=>['ASC','DESC','asc','desc']];
        if($resource==='expenses')$enums['currency'][]='EUR';
        if ($resource === 'orders' || $resource === 'draft-orders' || $resource === 'receiving') {
            require_once __DIR__ . '/OrderStateService.php';
            if ($resource !== 'receiving') $enums['status'] = $resource === 'draft-orders' ? ['Draft','Submitted','Confirmed','Approved'] : OrderStateService::statuses();
        }
        $enums += match ($resource) {
            'orders' => ['view'=>['list','full'], 'status_mode'=>['include','exclude'], 'customer_feedback'=>['pending','declined_after_auto_confirm'], 'order_type'=>['standard','draft_procurement']],
            'products' => ['alert_filter'=>['with','without'], 'image_filter'=>['with','without']],
            'suppliers' => ['payment_status'=>['outstanding','fully_paid']],
            'balances' => ['party_type'=>['customer','supplier'], 'status'=>['due','credit','settled'], 'transaction_type'=>['payment_received','payment_sent','deposit','invoice','adjustment','refund','other']],
            default => [],
        };
        foreach ($enums as $key => $allowed) {
            if (!isset($query[$key]) || $query[$key] === '') continue;
            $values = is_array($query[$key]) ? $query[$key] : ($key==='status' ? explode(',', $query[$key]) : [$query[$key]]);
            foreach ($values as $value) if (!in_array($value,$allowed,true)) jsonError('Invalid ' . $key . ' filter',422);
        }
    }
}
