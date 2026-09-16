<?php

/**
 * Explicit dependencies of assignable pages. A page grant includes its normal
 * workflow, not unrelated master-data writes or system administration.
 * Keep this shared by PHP affordances and API authorization (never use Referer).
 */
function clmsPageCapabilityMap(): array
{
    $orderReaders = ['orders', 'receiving', 'pipeline', 'consolidation', 'containers', 'assign_container', 'expenses', 'financials', 'balances', 'calendar', 'warehouse_stock', 'procurement_drafts', 'customers', 'suppliers', 'dashboard'];
    $partyReaders = array_merge($orderReaders, ['products']);
    $draftEditors = ['orders', 'procurement_drafts'];
    $shippingEditors = ['consolidation', 'containers', 'assign_container'];
    return [
        'orders.read' => $orderReaders,
        'orders.write' => $draftEditors,
        'orders.approve' => ['orders'],
        'orders.receive' => ['receiving'],
        'orders.confirm' => ['orders', 'receiving'],
        'customers.read' => $partyReaders,
        'customers.lookup' => $partyReaders,
        'customers.create' => array_merge(['customers'], $draftEditors),
        'customers.write' => ['customers'],
        'customers.import' => ['customers'],
        'customers.finance' => ['customers', 'financials', 'balances'],
        'suppliers.read' => $partyReaders,
        'suppliers.manage.read' => ['suppliers', 'balances', 'financials'],
        'suppliers.details.read' => $partyReaders,
        'suppliers.create' => array_merge(['suppliers'], $draftEditors),
        'suppliers.write' => ['suppliers'],
        'suppliers.import' => ['suppliers'],
        'suppliers.interactions' => ['suppliers'],
        'suppliers.finance' => ['suppliers', 'balances', 'financials'],
        'products.read' => array_merge($partyReaders, ['products']),
        'products.create' => array_merge(['products'], $draftEditors),
        'products.write' => ['products'],
        'countries.read' => array_merge($partyReaders, ['hs_code_tax']),
        'containers.read' => ['containers', 'assign_container', 'consolidation', 'pipeline', 'calendar', 'expenses', 'financials', 'balances', 'orders', 'dashboard'],
        'containers.write' => ['containers', 'consolidation'],
        'containers.assign' => ['containers', 'assign_container'],
        'shipment-drafts.read' => array_merge($shippingEditors, ['pipeline', 'calendar', 'dashboard']),
        'shipment-drafts.write' => $shippingEditors,
        'shipment-drafts.finalize' => $shippingEditors,
        'shipment-drafts.push' => $shippingEditors,
        'expenses.write' => ['expenses'],
        'receiving.import' => ['receiving'],
        'balances.read' => ['balances'],
        'balances.write' => ['balances'],
        'hs-code-tax.read' => ['hs_code_tax'],
        'hs-code-tax.write' => ['hs_code_tax'],
        'hs-code-catalog.read' => ['hs_code_tax', 'orders', 'procurement_drafts', 'products'],
        'item-classifications.read' => ['orders', 'procurement_drafts', 'products'],
        'item-classifications.write' => ['orders', 'procurement_drafts', 'products'],
        'draft-order-costs.read' => ['procurement_drafts', 'orders'],
        'draft-order-costs.write' => ['procurement_drafts', 'orders'],
        'translations.write' => ['orders', 'procurement_drafts', 'products'],
        'internal-messages' => ['customers'],
        'customer-portal-tokens' => ['customers'],
        'design-attachments' => ['customers', 'suppliers', 'products', 'orders', 'procurement_drafts', 'receiving'],
    ];
}
