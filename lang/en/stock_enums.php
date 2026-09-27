<?php

declare(strict_types=1);

/**
 * Stock enum labels (App\\Enums\\StockUnit, StockMovementType,
 * PurchaseOrderStatus) — label() reads from here to follow the user's locale.
 */
return [
    'units' => [
        'un'  => 'Unit',
        'cx'  => 'Box',
        'fr'  => 'Bottle',
        'par' => 'Pair',
        'amp' => 'Ampoule',
        'ml'  => 'Milliliter (ml)',
        'mg'  => 'Milligram (mg)',
        'g'   => 'Gram (g)',
        'l'   => 'Liter (l)',
    ],
    'movement_types' => [
        'purchase_in'     => 'Purchase inbound',
        'manual_in'       => 'Manual inbound',
        'consumption_out' => 'Procedure consumption',
        'manual_out'      => 'Manual outbound',
        'adjustment_in'   => 'Count adjustment (inbound)',
        'adjustment_out'  => 'Count adjustment (outbound)',
        'loss'            => 'Loss/breakage',
        'return_out'      => 'Return to supplier',
    ],
    'purchase_order_statuses' => [
        'draft'              => 'Draft',
        'sent'               => 'Sent to supplier',
        'partially_received' => 'Partially received',
        'received'           => 'Received',
        'cancelled'          => 'Cancelled',
    ],
];
