<?php

declare(strict_types=1);

/**
 * Tela Financeiro › Conciliação de Glosas (panel/financial/tiss/glosas) e
 * rótulos dos enums TissGlosaStatus/TissAppealStatus (label() lê daqui).
 */
return [
    'title'                => 'Conciliação de Glosas',
    'subtitle'             => 'Prazos, recursos e valores recuperados das glosas dos convênios.',
    'breadcrumb_financial' => 'Financeiro',
    'total_label'          => 'Pendentes:',

    // Cabeçalho
    'btn_import_return' => 'Importar retorno TISS',

    // Filtros
    'filters_label'           => 'Filtros das glosas',
    'search_placeholder'      => 'Buscar nº da guia, código ou motivo',
    'search_clear'            => 'Limpar busca',
    'filter_status'           => 'Status',
    'filter_status_all'       => 'Todos os status',
    'filter_operator'         => 'Convênio/operadora',
    'filter_operator_all'     => 'Todos os convênios',
    'filter_clear'            => 'Limpar filtros',
    'status_recovered_option' => 'Recuperadas (total ou parcial)',
    'period_any'              => 'Pendentes de qualquer data',
    'filtering'               => 'Filtrando...',
    'period_hint'             => 'O período (data de identificação da glosa) vale para as abas Resolvidas e Todas e para o "Recuperado".',

    // Abas
    'tabs_label'   => 'Situação das glosas',
    'tab_pending'  => 'Pendentes',
    'tab_resolved' => 'Resolvidas',
    'tab_all'      => 'Todas',

    // Cards (os da fila independem do período; todos respeitam o convênio filtrado)
    'kpis_label'         => 'Indicadores das glosas',
    'total_glosa'        => 'Total glosado',
    'glosa_count'        => ':count glosa(s)',
    'open_amount'        => 'Em aberto',
    'open_hint'          => 'Glosas abertas (ainda sem recurso), de qualquer data. Clique para filtrar a fila.',
    'appealed'           => 'Recorridas',
    'appealed_hint'      => 'Glosas com recurso aberto ou aguardando a resposta da operadora, de qualquer data. Clique para filtrar a fila.',
    'overdue_title'      => 'Vencidas',
    'overdue_hint'       => 'Glosas abertas com o prazo para recorrer já vencido. Clique para filtrar a fila.',
    'recovered'          => 'Recuperado',
    'recovered_hint'     => 'Soma dos valores aceitos nos recursos das glosas identificadas no período. Clique para ver as recuperadas.',
    'recovered_of_total' => 'de :total glosados no período',
    'due_soon_title'     => 'Vencendo em :days dias',
    'due_soon_hint'      => 'Glosas abertas cujo prazo para recorrer vence hoje ou nos próximos :days dias. Clique para filtrar a fila.',

    // Resumo por convênio
    'by_covenant'  => 'Resumo por convênio',
    'col_covenant' => 'Convênio',
    'col_count'    => 'Glosas',
    'col_total'    => 'Total glosado',
    'col_open'     => 'Em aberto',

    // Lista
    'list_title_pending'  => 'Fila de glosas pendentes',
    'list_title_resolved' => 'Glosas resolvidas no período',
    'list_title_all'      => 'Glosas do período',
    'empty'               => 'Nenhuma glosa encontrada no período selecionado.',
    'empty_pending'       => 'Nenhuma glosa pendente.',
    'empty_filtered'      => 'Nenhuma glosa com esses filtros.',
    'empty_hint'          => 'As glosas entram pelo retorno TISS importado ou ao glosar uma guia no Faturamento.',
    'col_date'            => 'Identificada em',
    'col_guide'           => 'Guia',
    'col_reason'          => 'Motivo',
    'col_deadline'        => 'Prazo',
    'col_status'          => 'Status',
    'col_appeal'          => 'Recurso',
    'col_value'           => 'Valor',
    'col_actions'         => 'Ações',
    'no_covenant'         => 'Sem convênio',
    'claim_code_label'    => 'Faturamento :code',
    'no_guide'            => 'Sem guia',

    // Paginação da lista (TablePagination)
    'pagination_showing'  => 'Exibindo',
    'pagination_of'       => 'de',
    'pagination_suffix'   => 'glosas',
    'pagination_label'    => 'Paginação das glosas',
    'pagination_previous' => 'Página anterior',
    'pagination_next'     => 'Próxima página',
    'pagination_status'   => 'Página :page de :pages',

    // Prazo (badge com ícone + texto, não só cor)
    'deadline_overdue_days' => 'Vencida há :days dias',
    'deadline_overdue_one'  => 'Venceu ontem',
    'deadline_overdue'      => 'Vencida',
    'deadline_today'        => 'Vence hoje',
    'deadline_tomorrow'     => 'Vence amanhã',
    'deadline_in_days'      => 'Vence em :days dias',
    'deadline_none'         => 'Sem prazo',

    // Recurso na linha
    'appeal_response_until' => 'Resposta até :date',
    'appeals_previous'      => '+:count recurso(s) anterior(es)',
    'no_appeal'             => 'Sem recurso',

    // Ações
    'appeal_btn'         => 'Recorrer',
    'submit_appeal_btn'  => 'Marcar como enviado',
    'resolve_appeal_btn' => 'Registrar decisão',
    'details_btn'        => 'Detalhes',
    'details_label'      => 'Ver detalhes e histórico da glosa :code',
    'more_actions'       => 'Mais ações da glosa :code',
    'processing'         => 'Processando...',
    'close'              => 'Fechar',
    'cancel_btn'         => 'Cancelar',
    'action_error'       => 'Não foi possível concluir a ação. Tente novamente.',

    // Painel de detalhes
    'detail_title'             => 'Glosa :code',
    'detail_loading'           => 'Carregando os detalhes da glosa...',
    'detail_missing'           => 'Glosa não encontrada ou sem acesso a ela.',
    'detail_summary'           => 'Resumo',
    'detail_status'            => 'Status',
    'detail_identified'        => 'Identificada em',
    'detail_deadline'          => 'Prazo para recorrer',
    'detail_amount'            => 'Valor glosado',
    'detail_recovered'         => 'Recuperado',
    'detail_resolved_at'       => 'Resolvida em',
    'detail_resolution_notes'  => 'Observações da decisão',
    'detail_guide'             => 'Guia',
    'guide_provider_number'    => 'Nº da guia (prestador)',
    'guide_operator_number'    => 'Nº na operadora',
    'guide_claim_code'         => 'Guia de faturamento',
    'guide_attendance'         => 'Atendimento',
    'guide_patient'            => 'Paciente',
    'guide_total'              => 'Valor da guia',
    'detail_reason'            => 'Motivo da glosa',
    'detail_appeals'           => 'Recursos',
    'no_appeals'               => 'Nenhum recurso aberto para esta glosa.',
    'appeal_opened_at'         => 'Aberto em',
    'appeal_submitted_at'      => 'Enviado em',
    'appeal_response_deadline' => 'Resposta até',
    'appeal_requested'         => 'Solicitado',
    'appeal_accepted'          => 'Aceito',
    'appeal_reason'            => 'Justificativa',
    'appeal_result_notes'      => 'Decisão da operadora',
    'detail_timeline'          => 'Linha do tempo',
    'timeline_identified'      => 'Glosa identificada',
    'timeline_glosa'           => 'Glosa: :status',
    'timeline_glosa_change'    => 'Glosa: :from → :to',
    'timeline_appeal'          => 'Recurso :number: :status',
    'timeline_appeal_change'   => 'Recurso :number: :from → :to',

    // Contexto nos modais
    'modal_glosa_label'    => 'Motivo da glosa',
    'modal_value_label'    => 'Valor glosado',
    'modal_guide_label'    => 'Guia',
    'modal_covenant_label' => 'Convênio',
    'modal_deadline_label' => 'Prazo para recorrer',
    'modal_appeal_label'   => 'Recurso',
    'modal_requested'      => 'Valor solicitado',

    // Modal: abrir recurso
    'appeal_title'              => 'Recurso de Glosa',
    'justification_label'       => 'Justificativa do recurso',
    'justification_placeholder' => 'Descreva o motivo pelo qual esta glosa é indevida e os documentos que sustentam o recurso...',
    'min_chars_audit_hint'      => 'Mínimo de :min caracteres. Será registrado no log de auditoria.',
    'char_counter'              => ':count/:max',
    'appeal_number_hint'        => 'Um número de recurso será gerado automaticamente (formato REC-AAAAMM-NNNNN).',
    'submit_appeal'             => 'Abrir recurso',

    // Modal: marcar como enviado
    'submit_confirm_title'       => 'Marcar recurso como enviado',
    'submit_confirm_intro'       => 'Confirme que o recurso abaixo já foi enviado à operadora (portal, e-mail ou protocolo físico).',
    'submit_confirm_consequence' => 'O sistema não envia o recurso eletronicamente: esta ação só registra o envio com a data de hoje. O prazo de resposta da operadora (:days dias) passa a contar e a ação não pode ser desfeita.',
    'submit_confirm_btn'         => 'Confirmar envio',

    // Modal: decisão
    'resolve_title'         => 'Decisão do Recurso',
    'decision_label'        => 'Decisão da operadora',
    'decision_accepted'     => 'Aceito (total ou parcial)',
    'decision_rejected'     => 'Rejeitado',
    'accepted_amount_label' => 'Valor aceito pela operadora',
    'accepted_amount_help'  => 'Maior que zero e até :max (valor glosado).',
    'result_notes_label'    => 'Observações da decisão',
    'resolve_submit_btn'    => 'Confirmar decisão',
    'resolve_preview'       => 'A glosa ficará: :status',

    // Mensagens do servidor
    'reason_required'           => 'Informe a justificativa do recurso.',
    'reason_min'                => 'A justificativa precisa ter ao menos :min caracteres.',
    'reason_max'                => 'A justificativa pode ter no máximo :max caracteres.',
    'decision_required'         => 'Selecione a decisão da operadora.',
    'accepted_amount_required'  => 'Informe o valor aceito pela operadora.',
    'accepted_amount_numeric'   => 'Informe um valor aceito válido.',
    'accepted_amount_decimals'  => 'O valor aceito deve ter no máximo 2 casas decimais (centavos).',
    'accepted_amount_min'       => 'O valor aceito deve ser maior que zero.',
    'accepted_amount_max'       => 'O valor aceito não pode ser maior que o valor glosado (:max).',
    'appeal_success'            => 'Recurso :number aberto com sucesso.',
    'cannot_appeal'             => 'Esta glosa não pode ser recorrida no estado atual.',
    'appeal_number_unavailable' => 'Não foi possível gerar o número do recurso agora. Nada foi gravado — tente novamente em instantes.',
    'appeal_submitted'          => 'Recurso :number marcado como enviado à operadora.',
    'cannot_submit_appeal'      => 'Este recurso não pode ser enviado no estado atual.',
    'appeal_resolved'           => 'Recurso :number resolvido com sucesso.',
    'cannot_resolve_appeal'     => 'Este recurso não pode ser resolvido no estado atual.',

    // Enums (App\Domains\Tiss\Enums)
    'glosa_status' => [
        'open'             => 'Aberta',
        'appealed'         => 'Recorrida',
        'partial_reversed' => 'Revertida parcialmente',
        'reversed'         => 'Revertida',
        'maintained'       => 'Mantida',
        'cancelled'        => 'Cancelada',
    ],
    'appeal_status' => [
        'opened'      => 'Aberto',
        'submitted'   => 'Enviado',
        'in_analysis' => 'Em análise',
        'accepted'    => 'Aceito',
        'rejected'    => 'Rejeitado',
        'cancelled'   => 'Cancelado',
    ],
];
