<?php

declare(strict_types=1);

/**
 * Tela Financeiro › Tabela de Preços (panel/financial/procedure-prices).
 * Chaves idênticas em lang/en/financial_procedure_prices.php.
 */
return [
    'title'                => 'Tabela de Preços',
    'subtitle'             => 'Defina o preço de cada procedimento por convênio.',
    'breadcrumb_financial' => 'Financeiro',
    'priced_counter'       => ':priced de :total com preço',

    'covenant'             => 'Convênio',
    'covenant_placeholder' => 'Selecione o convênio',
    'covenant_tiss'        => 'Com operadora TISS',
    'covenant_cash'        => 'Recebido no caixa',
    'code'                 => 'Código',
    'procedure'            => 'Procedimento',
    'price'                => 'Preço',
    'price_aria'           => 'Preço de :procedure',
    'empty_hint'           => 'Deixe o preço em branco para não precificar o procedimento neste convênio (um preço já salvo é removido).',
    'inherited_price'      => 'Padrão do sistema: :price',
    'row_changed'          => 'alterado',

    // Busca e filtros (locais)
    'search_placeholder' => 'Buscar por código ou nome',
    'search_clear'       => 'Limpar busca',
    'filter_label'       => 'Filtrar procedimentos',
    'filter_all'         => 'Todos',
    'filter_priced'      => 'Com preço',
    'filter_unpriced'    => 'Sem preço',
    'no_results'         => 'Nenhum procedimento encontrado com essa busca e filtro.',
    'clear_filters'      => 'Limpar busca e filtro',

    // "Faturável" → "Cobrar do convênio (guia TISS)"
    'charging'               => 'Cobrar do convênio (guia TISS)',
    'charging_aria'          => 'Cobrar :procedure do convênio por guia TISS',
    'charging_help'          => 'Marcado: o procedimento é faturado ao convênio por guia TISS e não é recebido por inteiro no caixa da chegada. Atenção: basta um procedimento marcado para a agenda tratar todos os atendimentos deste convênio como faturados por guia (o caixa da chegada não abre nem pré-preenche o valor).',
    'charging_help_cash'     => 'Este convênio não tem operadora TISS (sem registro ANS), como o Particular: o valor é recebido no caixa, sem guia. Por isso a cobrança pelo convênio fica desligada.',
    'charging_disabled_hint' => 'Informe um preço para definir a cobrança.',
    'charging_cash_hint'     => 'Recebido no caixa: convênio sem operadora TISS.',
    'charging_legacy'        => ':count procedimento(s) deste convênio ainda estão marcados para cobrança por guia, o que não vale para convênio sem operadora TISS (a agenda deixa de abrir o caixa da chegada). Salve a tabela para corrigir.',

    // Ações em lote (reajuste % e cópia de outro convênio): só na grade — nada
    // é salvo até "Salvar preços".
    'bulk_menu'           => 'Ajustar preços',
    'bulk_menu_label'     => 'Ajustar preços em lote (reajuste ou cópia)',
    'bulk_adjust'         => 'Reajustar %',
    'bulk_copy'           => 'Copiar de outro convênio',
    'bulk_local_hint'     => 'As mudanças vão só para a grade: nada é salvo até você clicar em "Salvar preços".',
    'bulk_applied'        => ':count preço(s) alterado(s) na grade. Nada foi salvo ainda: revise e clique em "Salvar preços".',
    'bulk_cancel'         => 'Cancelar',
    'preview_label'       => 'Prévia',
    'preview_changes'     => ':count preço(s) vão mudar.',
    'preview_none'        => 'Nenhum preço muda com essas opções.',
    'preview_examples'    => 'Exemplos:',
    'preview_example'     => ':procedure: de :from para :to',
    'preview_example_new' => ':procedure: sem preço próprio, passa a :to',

    // Reajustar %
    'adjust_title'         => 'Reajustar preços',
    'adjust_direction'     => 'Tipo de reajuste',
    'adjust_increase'      => 'Aumento',
    'adjust_decrease'      => 'Redução',
    'adjust_percent'       => 'Percentual',
    'adjust_percent_help'  => 'Até 2 casas decimais. Cada novo preço é arredondado para os centavos.',
    'adjust_percent_range' => 'Informe um percentual entre :min e :max.',
    'adjust_preview_empty' => 'Informe o percentual para ver a prévia.',
    'adjust_scope'         => 'Aplicar em',
    'adjust_scope_visible' => 'Só nas :count linha(s) visíveis (busca e filtro atuais)',
    'adjust_scope_all'     => 'Em todas as :count linha(s) deste convênio',
    'adjust_skipped'       => ':count linha(s) sem preço próprio ficam como estão (o padrão do sistema não é reajustado).',
    'adjust_apply'         => 'Aplicar na grade',

    // Copiar de outro convênio
    'copy_title'              => 'Copiar preços de outro convênio',
    'copy_source'             => 'Convênio de origem',
    'copy_source_placeholder' => 'Selecione o convênio de origem',
    'copy_no_sources'         => 'Não há outro convênio para copiar.',
    'copy_scope_hint'         => 'Vale para os :count procedimentos da tabela. O preço copiado é o que a tabela do convênio de origem mostra: o da clínica ou, sem ele, o padrão do sistema.',
    'copy_overwrite'          => 'Substituir também os preços já preenchidos neste convênio',
    'copy_overwrite_help'     => 'Desmarcado: só os procedimentos sem preço recebem o preço copiado.',
    'copy_loading'            => 'Carregando os preços do convênio de origem...',
    'copy_load_error'         => 'Não foi possível carregar os preços desse convênio. Tente novamente.',
    'copy_preview_empty'      => 'Escolha o convênio de origem para ver a prévia.',
    'copy_kept'               => ':count procedimento(s) já têm preço e ficam como estão.',
    'copy_missing'            => ':count procedimento(s) sem preço no convênio de origem ficam como estão.',
    'copy_apply'              => 'Copiar para a grade',

    // Salvar (barra fixa)
    'savebar_label'    => 'Salvar tabela de preços',
    'save'             => 'Salvar preços',
    'saving'           => 'Salvando...',
    'saved'            => 'Preços atualizados com sucesso.',
    'save_error'       => 'Não foi possível salvar os preços. Revise as linhas destacadas.',
    'rows_with_errors' => ':count linha(s) com erro.',
    'unsaved'          => ':count alteração(ões) não salva(s)',
    'no_changes'       => 'Nenhuma alteração pendente',
    'loading'          => 'Carregando preços...',
    'leave_confirm'    => 'Você tem :count alteração(ões) não salva(s) na tabela de preços. Sair desta página descarta essas alterações. Deseja sair mesmo assim?',
    'too_many_changes' => 'São :count alterações e o limite por salvamento é :max. Aplique o reajuste ou a cópia por partes (use a busca ou o filtro) e salve entre uma e outra.',

    // Estados vazios
    'no_covenants'        => 'Cadastre um convênio antes de definir preços.',
    'no_covenants_action' => 'Cadastrar convênio',
    'no_covenants_ask'    => 'Peça a um administrador da clínica para cadastrar os convênios.',
    'no_procedures'       => 'Nenhum procedimento ativo cadastrado.',
    'no_procedures_hint'  => 'Os procedimentos vêm do catálogo do sistema. Se faltar algum, fale com o suporte.',

    // Troca de convênio com alterações pendentes
    'discard_title'   => 'Descartar alterações?',
    'discard_body'    => 'Você tem :count alteração(ões) não salva(s) em :covenant. Trocar de convênio descarta essas alterações.',
    'discard_confirm' => 'Descartar e trocar',
    'discard_cancel'  => 'Continuar editando',

    // Nomes de campo nas mensagens de validação
    'covenant_attribute'  => 'convênio',
    'procedure_attribute' => 'procedimento',
    'price_attribute'     => 'preço',
    'charging_attribute'  => 'cobrar do convênio',

    // Mensagens de validação do salvamento (por linha: items.N.*)
    'items_max'           => 'Envie no máximo :max preços por vez.',
    'procedure_invalid'   => 'Procedimento inexistente ou de outra clínica.',
    'procedure_duplicate' => 'Procedimento repetido no mesmo salvamento.',
    'price_missing'       => 'Informe o preço (ou envie vazio para remover o preço desta linha).',
];
