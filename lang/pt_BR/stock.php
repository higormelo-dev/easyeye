<?php

declare(strict_types=1);

/**
 * Strings do módulo de estoque (Panel/Stock) — produtos/materiais e
 * movimentação. Ver App\Http\Controllers\Stock\* e
 * App\Services\Stock\StockService.
 */
return [
    // Produtos
    'product_created' => 'Produto cadastrado.',
    'product_updated' => 'Produto atualizado.',
    'product_deleted' => 'Produto removido.',

    // Movimentação
    'movement_registered'      => 'Movimentação registrada.',
    'insufficient_balance'     => 'Saldo insuficiente para :product: solicitado :requested, disponível :available.',
    'insufficient_lot_balance' => 'Saldo insuficiente para :product (lote :lot): solicitado :requested, disponível :available.',
    'lot_required'             => 'Produto :product exige lote — selecione um lote existente ou informe um novo.',
    'lot_pick_one'             => 'Escolha um lote existente OU informe um novo, não os dois.',
    'new_lot_only_on_inbound'  => 'Só é possível criar um lote novo numa entrada.',

    // Lotes
    'lot_created'          => 'Lote cadastrado.',
    'lot_updated'          => 'Lote atualizado.',
    'lot_has_balance'      => 'Lote possui saldo em estoque — não pode ser excluído.',
    'lot_number_duplicate' => 'Já existe um lote com este número para este produto.',
    'lot_not_found'        => 'Lote não encontrado para este produto.',

    // Validação
    'quantity_must_be_positive' => 'A quantidade deve ser maior que zero.',

    // Consumo em procedimento (Fase 3)
    'procedure_created'        => 'Procedimento registrado.',
    'procedure_done'           => 'Procedimento marcado como executado.',
    'procedure_cancelled'      => 'Solicitação cancelada.',
    'procedure_bad_transition' => 'Procedimento :status não pode ser alterado para este estado.',

    // Fornecedores (Fase 4)
    'supplier_created' => 'Fornecedor cadastrado.',
    'supplier_updated' => 'Fornecedor atualizado.',
    'supplier_deleted' => 'Fornecedor removido.',

    // Pedidos de compra (Fase 4)
    'purchase_order_created'                    => 'Pedido de compra criado.',
    'purchase_order_updated'                    => 'Pedido de compra atualizado.',
    'purchase_order_deleted'                    => 'Pedido de compra removido.',
    'purchase_order_sent'                       => 'Pedido enviado ao fornecedor.',
    'purchase_order_cancelled'                  => 'Pedido cancelado.',
    'purchase_order_received'                   => 'Recebimento registrado.',
    'purchase_order_received_financial_pending' => 'Recebimento registrado — lançamento financeiro pendente (verifique o fechamento de caixa).',
    'purchase_order_not_editable'               => 'Pedido :status não pode ser editado/excluído — só rascunho.',
];
