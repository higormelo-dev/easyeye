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
    'report_inactive_exam'       => 'Uma ou mais imagens selecionadas estão desabilitadas — habilite-as antes de gerar o laudo.',
    'merge_split_same_patient'   => 'Só é possível mesclar/dividir imagens do mesmo paciente.',
    'merge_action'               => 'Mesclar exames',
    'merge_select_two'           => 'Selecione 2 ou mais imagens do mesmo paciente para mesclar.',
    'merge_success'              => 'Imagens mescladas no mesmo exame.',
    'split_action'               => 'Dividir exame',
    'split_select_one'           => 'Selecione ao menos 1 imagem do grupo para separar.',
    'split_success'              => 'Imagem(ns) separada(s) num novo exame.',
    'merged_badge'               => 'Mesclado',
    'undo_merge_split'           => 'Mesclado/dividido manualmente — clique pra desfazer (volta ao agrupamento automático).',

    // Calculadora de lentes (vértice + equivalente esférico) — benchmark 09/09/2026
    'lens_calc_title'           => 'Calculadora de lentes',
    'lens_calc_disclaimer'      => 'Fórmulas de óptica de referência (vértice e equivalente esférico) — sempre confira o resultado antes de usar. Não inclui cálculo de LIO (lente intraocular): use uma calculadora de biometria dedicada e validada para cirurgia de catarata.',
    'lens_calc_vertex_title'    => 'Conversão de distância ao vértice',
    'lens_calc_vertex_hint'     => 'Converte a graduação do óculos para a potência equivalente em lente de contato (vértice zero).',
    'lens_calc_vertex_distance' => 'Distância ao vértice (mm)',
    'lens_calc_sphere_od'       => 'Esférico OD (D)',
    'lens_calc_sphere_oe'       => 'Esférico OE (D)',
    'lens_calc_result'          => 'Lente de contato',
    'lens_calc_se_title'        => 'Equivalente esférico',
    'lens_calc_se_hint'         => 'SE = Esférico + Cilindro / 2.',

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

    // Menu de contexto (botão direito na miniatura) — benchmark 09/09/2026
    'context_menu_title'          => 'Ações rápidas',
    'context_menu_report'         => 'Fazer laudo manual',
    'context_menu_compare'        => 'Comparar / Alinhar',
    'context_menu_share'          => 'Compartilhar com o paciente',
    'context_menu_unshare'        => 'Revogar do Portal do Paciente',
    'context_menu_download'       => 'Baixar imagem',
    'context_menu_eye'            => 'Lateralidade',
    'context_menu_quality'        => 'Qualidade da captura',
    'context_menu_quality_hint'   => 'Clique pra avaliar — clique de novo na mesma estrela pra cancelar',
    'context_menu_disable'        => 'Desabilitar imagem',
    'context_menu_enable'         => 'Habilitar imagem',
    'context_menu_info_type'      => 'Tipo',
    'context_menu_info_created'   => 'Capturado em',
    'context_menu_info_equipment' => 'Equipamento',
    'context_menu_info_doctor'    => 'Médico',
    'context_menu_info_origin'    => 'Origem',
];
