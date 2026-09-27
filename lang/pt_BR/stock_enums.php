<?php

declare(strict_types=1);

/**
 * Rótulos dos enums de estoque (App\\Enums\\StockUnit, StockMovementType,
 * PurchaseOrderStatus) — label() lê daqui para respeitar o idioma do usuário.
 */
return [
    'units' => [
        'un'  => 'Unidade',
        'cx'  => 'Caixa',
        'fr'  => 'Frasco',
        'par' => 'Par',
        'amp' => 'Ampola',
        'ml'  => 'Mililitro (ml)',
        'mg'  => 'Miligrama (mg)',
        'g'   => 'Grama (g)',
        'l'   => 'Litro (l)',
    ],
    'movement_types' => [
        'purchase_in'     => 'Entrada por compra',
        'manual_in'       => 'Entrada manual',
        'consumption_out' => 'Consumo em procedimento',
        'manual_out'      => 'Saída manual',
        'adjustment_in'   => 'Ajuste de balanço (entrada)',
        'adjustment_out'  => 'Ajuste de balanço (saída)',
        'loss'            => 'Perda/quebra',
        'return_out'      => 'Devolução a fornecedor',
    ],
    'purchase_order_statuses' => [
        'draft'              => 'Rascunho',
        'sent'               => 'Enviado ao fornecedor',
        'partially_received' => 'Recebido parcialmente',
        'received'           => 'Recebido',
        'cancelled'          => 'Cancelado',
    ],
];
