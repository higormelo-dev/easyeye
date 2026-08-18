<?php

return [
    'title'    => 'Finanças EasyEye',
    'subtitle' => 'Receita, despesa e lucro do próprio EasyEye — visão exclusiva de donos e administradores gerais.',

    // ── Período ──────────────────────────────────────────────────────────
    'period' => [
        'this_month' => 'Este mês',
        '3m'         => 'Últimos 3 meses',
        '6m'         => 'Últimos 6 meses',
        '12m'        => 'Últimos 12 meses',
        'custom'     => 'Personalizado',
        'from'       => 'De',
        'to'         => 'Até',
        'apply'      => 'Aplicar',
    ],

    // ── KPIs ─────────────────────────────────────────────────────────────
    'kpi' => [
        'revenue'        => 'Receita',
        'expenses'       => 'Despesas',
        'profit'         => 'Lucro',
        'loss'           => 'Prejuízo',
        'margin'         => 'Margem',
        'mrr'            => 'MRR',
        'arpu'           => 'Ticket médio',
        'paying_clinics' => 'Clínicas pagantes',
        'new_clinics'    => 'Novas clínicas',
        'cancellations'  => 'Cancelamentos',
        'delinquency'    => 'Inadimplência',
        'at_risk'        => 'em risco',
        'vs_previous'    => 'vs. período anterior',
    ],

    // ── Receita por plano / Despesas por categoria ──────────────────────
    'breakdown' => [
        'revenue_by_plan'      => 'Receita por plano',
        'expenses_by_category' => 'Despesas por categoria',
        'no_revenue'           => 'Sem receita registrada neste período.',
        'no_expenses'          => 'Sem despesas neste período.',
        'auto_badge'           => 'automático',
        'auto_hint'            => 'Calculado automaticamente a partir do custo real (não é lançamento manual).',
    ],

    // ── Despesas manuais (CRUD) ──────────────────────────────────────────
    'expenses' => [
        'title'          => 'Lançamentos de despesa',
        'new'            => 'Nova despesa',
        'edit'           => 'Editar despesa',
        'category'       => 'Categoria',
        'description'    => 'Descrição',
        'amount'         => 'Valor',
        'effective_at'   => 'Data (competência)',
        'recurring'      => 'Recorrente',
        'notes'          => 'Observações',
        'save'           => 'Salvar',
        'delete'         => 'Excluir',
        'confirm_delete' => 'Excluir este lançamento?',
        'empty'          => 'Nenhum lançamento manual neste período.',
        'created'        => 'Despesa registrada.',
        'updated'        => 'Despesa atualizada.',
        'deleted'        => 'Despesa removida.',
    ],

    // ── Assistente de IA — análise financeira ────────────────────────────
    'ai' => [
        'title'                 => 'Análise por IA',
        'subtitle'              => 'Interpretação do período selecionado — nunca repete números, sempre cita o dado que sustenta cada conclusão.',
        'generate'              => 'Gerar análise',
        'regenerate'            => 'Gerar novamente',
        'thinking'              => 'Analisando o período...',
        'error'                 => 'Não foi possível gerar a análise. Tente novamente.',
        'empty'                 => 'Gere uma análise para ver onde o EasyEye está ganhando, perdendo e quais ações vale avaliar.',
        'section_summary'       => 'Resumo',
        'section_winning'       => 'Onde estamos ganhando',
        'section_losing'        => 'Onde estamos perdendo',
        'section_opportunities' => 'Oportunidades',
        'section_actions'       => 'Ações sugeridas',
        'evidence_label'        => 'Dado',

        // Chat
        'chat_title'       => 'Converse com os dados',
        'chat_subtitle'    => 'Pergunte sobre o período selecionado — as respostas usam os números reais do EasyEye.',
        'chat_placeholder' => 'Ex.: Por que nosso lucro caiu este mês?',
        'chat_send'        => 'Enviar',
        'chat_new'         => 'Nova conversa',
        'chat_empty'       => 'Envie uma pergunta pra começar.',
        'chat_suggestions' => [
            'Por que nosso lucro caiu?',
            'Onde estamos gastando mais?',
            'Qual plano dá mais lucro?',
            'Onde podemos reduzir custos?',
            'Quais clientes têm potencial de upgrade?',
            'O que podemos fazer para aumentar nossa receita?',
        ],
    ],
];
