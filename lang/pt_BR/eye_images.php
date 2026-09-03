<?php

declare(strict_types=1);

return [
    // Filter bar
    'search_placeholder' => 'Nome ou código do paciente...',
    'period_today'       => 'Hoje',
    'period_7'           => 'Últimos 7 dias',
    'period_15'          => 'Últimos 15 dias',
    'period_30'          => 'Últimos 30 dias',
    'period_90'          => 'Últimos 90 dias',
    'filters_btn'        => 'Filtros',
    'eye_label'          => 'Olho:',
    'all'                => 'Todos',
    'all_exams'          => 'Todos os exames',
    'all_statuses'       => 'Todos os status',
    'status_requested'   => 'Solicitado',
    'status_done'        => 'Realizado',
    'status_reported'    => 'Laudado',
    'status_cancelled'   => 'Cancelado',
    'all_doctors'        => 'Todos os médicos',
    'clear_btn'          => 'Limpar',

    // Sidebar
    'patients_title'        => 'Pacientes',
    'no_patients'           => 'Nenhum paciente.',
    'patients_count_suffix' => 'paciente(s)',

    // Main area
    'medical_record'      => 'Prontuário',
    'view_selected'       => 'Visualizar selecionadas',
    'view_all'            => 'Visualizar todas',
    'print_btn'           => 'Imprimir',
    'selected_suffix'     => 'selecionado(s)',
    'select_patient_hint' => 'Selecione um paciente na lateral para visualizar os exames.',
    'loading_images'      => 'Carregando imagens…',
    'no_exams'            => 'Nenhum exame para os filtros selecionados.',
    'upload_btn'          => 'Upload',
    'download_btn'        => 'Download',
    'panel_prefix'        => 'Painel ',
    'no_image'            => 'Sem imagem',
    'not_found'           => 'Não encontrado',

    // Print modal
    'portrait'    => 'Retrato',
    'landscape'   => 'Paisagem',
    'close_btn'   => 'Fechar',
    'report_date' => 'Data do relatório:',

    // IA — análise de imagem ocular
    'ai_analyze'         => 'Analisar com IA',
    'ai_selected_images' => 'Imagens selecionadas',
    'ai_no_selection'    => 'Selecione ao menos uma imagem para analisar.',
    'ai_report'          => 'Laudo da IA',
    'ai_reported_badge'  => 'Laudado (IA)',
    'download_pdf'       => 'Baixar PDF',

    // Laudo manual (Modelos) — reaproveita o catálogo de templates das
    // Documentações do prontuário, filtrado a laudos/exames especializados.
    'report_doctor_required'     => 'Selecione um médico responsável antes de emitir o laudo.',
    'report_default_title'       => 'Laudo de Exame de Imagem',
    'report_title'               => 'Novo laudo',
    'report_new'                 => 'Novo laudo',
    'report_templates'           => 'Modelos',
    'report_template_blank'      => 'Em branco',
    'report_loading_templates'   => 'Carregando modelos…',
    'report_no_templates'        => 'Nenhum modelo disponível.',
    'report_content_label'       => 'Conteúdo do laudo',
    'report_title_placeholder'   => 'Título do laudo (opcional)',
    'report_save'                => 'Salvar laudo',
    'report_saved'               => 'Laudo salvo com sucesso.',
    'report_save_failed'         => 'Não foi possível salvar o laudo.',
    'report_confirm_open_record' => 'Não há prontuário do dia da consulta para este paciente. Deseja abrir um novo prontuário para registrar o laudo?',
    'report_content_required'    => 'Escreva o conteúdo do laudo antes de salvar.',

    // Comparar / Alinhar (evolução entre exames)
    'compare_title'             => 'Comparar exames',
    'compare_action'            => 'Comparar',
    'compare_select_two'        => 'Selecione exatamente 2 imagens para comparar.',
    'compare_mode_overlay'      => 'Sobrepor',
    'compare_mode_side_by_side' => 'Lado a lado',
    'compare_opacity'           => 'Opacidade',
    'compare_reset'             => 'Redefinir posição',
    'compare_hint'              => 'Arraste a imagem de cima para alinhar os pontos de referência.',

    // Importar exame externo
    'import' => [
        'success' => 'Exame importado com sucesso.',
    ],
];
