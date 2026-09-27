<?php

declare(strict_types=1);

/**
 * Stock module strings (Panel/Stock) — products/materials and movements.
 * English counterpart of lang/pt_BR/stock.php (same keys).
 */
return [
    // Products
    'product_created'   => 'Product created.',
    'product_updated'   => 'Product updated.',
    'product_deleted'   => 'Product removed.',
    'barcode_required'  => 'Enter the barcode.',
    'barcode_not_found' => 'No active product found with this barcode.',

    // Movements
    'movement_registered'      => 'Stock movement registered.',
    'insufficient_balance'     => 'Insufficient balance for :product: requested :requested, available :available.',
    'insufficient_lot_balance' => 'Insufficient balance for :product (lot :lot): requested :requested, available :available.',
    'lot_required'             => 'Product :product requires a lot — select an existing lot or enter a new one.',
    'lot_pick_one'             => 'Choose an existing lot OR enter a new one, not both.',
    'new_lot_only_on_inbound'  => 'A new lot can only be created on an inbound movement.',

    // Lots
    'lot_created'          => 'Lot created.',
    'lot_updated'          => 'Lot updated.',
    'lot_has_balance'      => 'This lot has stock on hand and cannot be deleted.',
    'lot_number_duplicate' => 'A lot with this number already exists for this product.',
    'lot_not_found'        => 'Lot not found for this product.',

    // Validation
    'quantity_must_be_positive' => 'Quantity must be greater than zero.',

    // Procedure consumption
    'procedure_created'        => 'Procedure registered.',
    'procedure_done'           => 'Procedure marked as performed.',
    'procedure_cancelled'      => 'Request cancelled.',
    'procedure_bad_transition' => 'Procedure :status cannot be changed to this state.',

    // Suppliers
    'supplier_created'          => 'Supplier created.',
    'supplier_updated'          => 'Supplier updated.',
    'supplier_deleted'          => 'Supplier removed.',
    'supplier_document_invalid' => 'Invalid document — enter a valid CPF (11 digits) or CNPJ (14 characters).',

    // Purchase orders
    'purchase_order_created'                    => 'Purchase order created.',
    'purchase_order_updated'                    => 'Purchase order updated.',
    'purchase_order_deleted'                    => 'Purchase order removed.',
    'purchase_order_sent'                       => 'Order sent to the supplier.',
    'purchase_order_cancelled'                  => 'Order cancelled.',
    'purchase_order_received'                   => 'Receipt registered.',
    'purchase_order_received_financial_pending' => 'Receipt registered — financial entry pending (check the cash closing).',
    'purchase_order_not_editable'               => 'Order :status cannot be edited/deleted — drafts only.',

    // Physical count
    'count_adjustment_note' => 'Adjustment from physical stock count.',
    'count_applied'         => ':count product(s) adjusted — the rest already matched the system.',
];
