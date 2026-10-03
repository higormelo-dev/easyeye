<?php

return [
    // Header
    'greeting_morning'   => 'Bom dia',
    'greeting_afternoon' => 'Boa tarde',
    'greeting_evening'   => 'Boa noite',
    'operational_panel'  => 'Painel operacional do :app',

    // Botões header
    'btn_patients'    => 'Pacientes',
    'btn_new_patient' => 'Novo paciente',

    // KPIs
    'kpi_patients'       => 'Pacientes',
    'kpi_today'          => 'Consultas hoje',
    'kpi_doctors'        => 'Médicos ativos',
    'kpi_surgeries'      => 'Cirurgias hoje',
    'kpi_exams_pending'  => 'Exames pendentes',
    'kpi_guides_waiting' => 'Guias aguardando',
    'kpi_receivable'     => 'A receber',
    'kpi_satisfaction'   => 'Satisfação',
    'kpi_coming_soon'    => 'Em breve',
    'kpi_open_list'      => 'Ver lista de :label',

    // Módulos / Atalhos
    'module_schedule'     => 'Agenda',
    'module_waiting_room' => 'Sala de Espera',
    'module_eye_images'   => 'Eye Images',
    'module_tiss'         => 'Guias TISS',
    'module_financial'    => 'Financeiro',
    'module_surgery'      => 'Centro Cirúrgico',
    'coming_soon'         => 'Em breve',

    // Seções
    'section_recent_patients' => 'Últimos pacientes cadastrados',
    'section_day_summary'     => 'Resumo do dia',

    // Tabela de pacientes
    'col_name'      => 'Paciente',
    'col_phone'     => 'Telefone',
    'col_code'      => 'Código',
    'col_actions'   => 'Ações',
    'col_doctor'    => 'Médico',
    'col_time'      => 'Horário',
    'col_situation' => 'Situação',

    // Resumo do dia
    'summary_total'     => 'Total de consultas',
    'summary_attended'  => 'Atendidos',
    'summary_pending'   => 'Em andamento / aguardando',
    'summary_cancelled' => 'Cancelados / faltaram',

    // Ações
    'btn_see_all'      => 'Ver todos',
    'btn_view'         => 'Ver',
    'btn_waiting_room' => 'Sala de espera',
    'btn_see_schedule' => 'Ver agenda completa',

    // Estados vazios
    'empty_schedules' => 'Nenhuma consulta agendada para hoje.',
    'empty_patients'  => 'Nenhum paciente cadastrado.',

    // Activation / Setup
    'activation_title'        => 'Configure sua clínica',
    'activation_subtitle'     => 'Complete os passos para aproveitar ao máximo o sistema.',
    'activation_done'         => 'setup concluído',
    'activation_optional'     => 'opcional',
    'activation_completed_on' => 'Concluído em',

    // Live / Polling
    'live_label'      => 'Ao vivo',
    'live_refreshing' => 'Atualizando...',
    'last_updated_at' => 'Atualizado às',
    'btn_refresh'     => 'Atualizar',

    // Agenda de hoje
    'section_schedule_today' => 'Agenda de hoje',

    // Demo
    'demo_title'       => 'Ambiente de demonstração',
    'demo_description' => 'Popule dados de teste ou redefina o ambiente para demonstrações.',
    'demo_btn_seed'    => 'Popular dados',
    'demo_btn_reset'   => 'Resetar ambiente',

    // Cabeçalho e personalização
    'page_title'      => 'Painel de controle',
    'customize'       => 'Personalizar',
    'customize_title' => 'Personalizar o painel',
    'sections_order'  => 'Ordem das seções',

    // Seções reordenáveis
    'section_kpis'      => 'Indicadores',
    'section_shortcuts' => 'Atalhos',
    'section_agenda'    => 'Agenda de hoje',
    'section_patients'  => 'Pacientes recentes',
    'section_stock'     => 'Alertas de estoque',

    // Atalhos favoritos
    'shortcuts'       => 'Atalhos',
    'shortcuts_title' => 'Escolher atalhos favoritos',
    'shortcuts_menu'  => 'Atalhos favoritos',

    // Menu de ordenar (mostrar/ocultar/mover)
    'order_show'      => 'Mostrar',
    'order_hide'      => 'Ocultar',
    'order_move_up'   => 'Mover para cima',
    'order_move_down' => 'Mover para baixo',
    'order_reset'     => 'Restaurar padrão',

    // Agenda de hoje
    'arrived' => 'Chegou',

    // Alertas de estoque
    'stock_title'               => 'Alertas de estoque',
    'stock_see'                 => 'Ver estoque',
    'stock_below_minimum_one'   => ':count produto abaixo do mínimo',
    'stock_below_minimum_other' => ':count produtos abaixo do mínimo',
    'stock_below_minimum_hint'  => 'Reponha o estoque para não faltar material.',
    'stock_expiring_one'        => ':count produto com lote vencendo',
    'stock_expiring_other'      => ':count produtos com lote vencendo',
    'stock_expiring_hint'       => 'Vencidos ou vencendo nos próximos 30 dias.',

    // Etapas do cartão "Configure sua clínica" (App\Enums\ActivationStep)
    'activation_steps' => [
        'integrator_registered'    => 'Integrador cadastrado', 'integrator_capture_observed' => 'Captura observada no integrador', 'integrator_receipt_confirmed' => 'Primeiro recibo confirmado',
        'entity_profile_completed' => 'Perfil da clínica preenchido',
        'first_doctor_added'       => 'Primeiro médico cadastrado',
        'first_patient_added'      => 'Primeiro paciente cadastrado',
        'first_schedule_created'   => 'Primeira consulta agendada',
        'first_medical_record'     => 'Primeiro prontuário criado',
        'team_member_invited'      => 'Membro da equipe convidado',
        'integrator_connected'     => 'Integrador autenticado',
    ],

    // Agenda de hoje: lista limitada
    'schedule_showing' => 'Mostrando :shown de :total consultas de hoje.',
];
