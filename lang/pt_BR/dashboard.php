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
    'kpi_patients'           => 'Pacientes',
    'kpi_today'              => 'Consultas hoje',
    'kpi_doctors'            => 'Médicos ativos',
    'kpi_surgeries'          => 'Cirurgias hoje',
    'kpi_exams_pending'      => 'Exames pendentes',
    'kpi_exams_pending_hint' => 'Sem laudo · últimos 30 dias',
    'kpi_guides_waiting'     => 'Guias aguardando',
    'kpi_receivable'         => 'A receber',
    'kpi_satisfaction'       => 'Satisfação',
    'kpi_coming_soon'        => 'Em breve',
    'kpi_open_list'          => 'Ver lista de :label',

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
    'summary_total'          => 'Agendadas',
    'summary_attended'       => 'Atendidos',
    'summary_pending'        => 'A atender',
    'summary_cancelled'      => 'Faltas / cancelados',
    'summary_progress_label' => 'Andamento do dia',
    'summary_progress'       => ':attended de :expected atendidos',
    'summary_by_shift'       => 'Por turno',

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
    'live_label'       => 'Ao vivo',
    'live_refreshing'  => 'Atualizando...',
    'last_updated_at'  => 'Atualizado às',
    'btn_refresh'      => 'Atualizar',
    'btn_refresh_hint' => 'Atualizar agora (inclui os números do mês)',

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
    'sections_order'  => 'Seções do painel',

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

    // ── Dashboard do médico (só os dados dele) ───────────────────────────
    'kpi_my_today'         => 'Minhas consultas hoje',
    'kpi_my_waiting'       => 'Aguardando você',
    'kpi_my_waiting_hint'  => 'Já chegaram',
    'kpi_my_exams_pending' => 'Meus exames sem laudo',
    'kpi_ai_waiting'       => 'Laudos de IA a revisar',
    'kpi_ai_waiting_hint'  => 'Aguardando sua aprovação',
    'kpi_waiting_now'      => 'Aguardando agora',
    'kpi_waiting_now_hint' => 'Pacientes que já chegaram',

    'section_my_schedule_today'  => 'Minha agenda de hoje',
    'section_my_day_summary'     => 'Meu dia',
    'section_my_recent_patients' => 'Meus pacientes recentes',
    'section_pending'            => 'Minhas pendências',
    'col_last_visit'             => 'Última consulta',
    'empty_my_schedules'         => 'Nenhuma consulta sua marcada para hoje.',
    'shifts_label'               => 'Turnos da agenda de hoje',
    'shift_morning'              => 'Manhã',
    'shift_afternoon'            => 'Tarde',
    'shift_evening'              => 'Noite',
    'shift_now'                  => 'Agora',
    'shift_empty'                => 'Nenhuma consulta neste turno.',
    'empty_my_patients'          => 'Você ainda não atendeu nenhum paciente.',

    // Próximo paciente
    'next_patient_title'     => 'Próximo paciente',
    'next_patient_arrived'   => 'Chegou às :time',
    'next_patient_waiting'   => 'aguardando há :minutes min',
    'next_patient_scheduled' => 'Agendado para :time',
    'next_patient_empty'     => 'Ninguém aguardando e nenhum outro horário marcado para hoje.',
    'btn_start_attendance'   => 'Iniciar atendimento',
    'btn_open_patient'       => 'Abrir cadastro',
    'btn_open_schedule'      => 'Ver na agenda',

    // Pendências do médico
    'ai_waiting_title'  => 'Laudos de IA aguardando aprovação',
    'ai_waiting_empty'  => 'Nenhum laudo de IA aguardando sua aprovação.',
    'ai_waiting_review' => 'Revisar',
    'unsigned_title'    => 'Prontuários sem assinatura',
    'unsigned_hint'     => 'Seus prontuários dos últimos :days dias',
    'unsigned_empty'    => 'Nenhum prontuário seu sem assinatura nos últimos :days dias.',
    'btn_open_record'   => 'Abrir',
    'pending_count'     => 'Pendentes: :count',
    'pending_showing'   => 'Mostrando :shown de :total.',

    // Médico sem cadastro de médico na clínica
    'doctor_missing_title' => 'Cadastro de médico incompleto',
    'doctor_missing_text'  => 'Seu usuário ainda não está vinculado a um cadastro de médico nesta clínica, então o painel não mostra agenda, pacientes nem pendências. Peça ao administrador da clínica para concluir seu cadastro em Médicos.',

    // ══ Dashboard v2 — painel por função ══════════════════════════════════
    // Cabeçalho: posto de trabalho do perfil e ações primárias
    'role_doctor'           => 'Meu consultório',
    'role_secretary'        => 'Recepção',
    'role_admin'            => 'Gestão',
    'role_financial'        => 'Financeiro',
    'role_user'             => 'Visão geral',
    'action_new_schedule'   => 'Novo agendamento',
    'action_my_schedule'    => 'Minha agenda',
    'action_open_bi'        => 'Abrir BI',
    'action_new_cash_entry' => 'Lançar no caixa',

    // Seções (menu "Personalizar")
    'section_next'          => 'Próximo paciente',
    'section_finance'       => 'Caixa, a receber e glosas',
    'section_trends'        => 'Tendências',
    'section_confirmations' => 'Confirmações',
    'section_waitlist'      => 'Lista de espera',
    'section_birthdays'     => 'Aniversariantes',

    // Indicadores: período e variação
    'kpis_month_title'   => 'Indicadores do mês',
    'kpis_month_compare' => 'Até hoje, comparado ao mesmo período do mês anterior (:period).',
    'kpi_vs'             => 'vs. :period',
    'delta_pp'           => 'p.p.',
    'delta_no_base'      => 'Sem dados em :period',
    'delta_sr_up'        => 'Aumento de :value em relação a :period.',
    'delta_sr_down'      => 'Queda de :value em relação a :period.',
    'delta_sr_flat'      => 'Igual a :period.',
    'kpi_today_progress' => ':attended de :expected atendidos',

    // Indicadores do médico (mês)
    'kpi_my_month_attended' => 'Meus atendimentos no mês',
    'kpi_my_noshow_rate'    => 'Minha taxa de falta',
    'kpi_noshow_rate_hint'  => 'Faltas ÷ (atendidos + faltas) no período. Cancelados e consultas ainda não realizadas não entram na conta.',

    // Indicadores da recepção
    'kpi_longest_wait'    => 'Maior espera: :minutes min',
    'kpi_confirmed_today' => 'Confirmadas hoje',
    'kpi_confirmed_of'    => ':confirmed de :total consultas',
    'kpi_tomorrow'        => 'Consultas amanhã',
    'kpi_unconfirmed'     => ':count sem confirmação',
    'kpi_all_confirmed'   => 'Todas confirmadas',
    'kpi_waitlist'        => 'Lista de espera',
    'kpi_waitlist_hint'   => 'Pacientes aguardando vaga',

    // Indicadores de gestão (mesmas definições do BI)
    'kpi_occupancy'         => 'Ocupação da agenda',
    'kpi_occupancy_hint'    => 'Atendidos ÷ agendamentos não cancelados que já aconteceram no período (as consultas de hoje que ainda vão acontecer não entram).',
    'kpi_attendance'        => 'Comparecimento',
    'kpi_attendance_hint'   => 'Atendidos ÷ (atendidos + faltas). Cancelados e pendentes não entram na conta.',
    'kpi_noshow_rate'       => 'Taxa de falta',
    'kpi_new_patients'      => 'Pacientes novos',
    'kpi_income'            => 'Receita recebida',
    'kpi_receivable_hint'   => 'Posição de hoje: lançamentos de receita pendentes no caixa + guias de convênio enviadas aguardando pagamento.',
    'kpi_overdue'           => ':amount vencido',
    'kpi_nothing_overdue'   => 'Nada vencido',
    'kpi_attended_month'    => 'Consultas realizadas',
    'kpi_billed'            => 'Faturado (convênios)',
    'kpi_billed_hint'       => 'Valor das guias com atendimento no período, sem rascunhos e canceladas.',
    'kpi_paid'              => 'Recebido de convênios',
    'kpi_glosa'             => 'Glosado',
    'kpi_glosa_hint'        => 'Valor negado pelos convênios nas guias do período.',
    'kpi_ticket'            => 'Ticket médio',
    'kpi_ticket_hint'       => 'Valor recebido dividido pela quantidade de guias pagas no período.',
    'kpi_receipt_rate'      => 'Taxa de recebimento',
    'kpi_receipt_rate_hint' => 'Recebido ÷ faturado nas guias do período.',
    'kpi_patients_hint'     => 'Cadastros ativos',

    // Resumo do dia: andamento
    'summary_in_clinic'         => 'Na clínica',
    'summary_to_come'           => 'Restantes',
    'summary_missed_note_one'   => ':count falta/cancelamento fora da conta.',
    'summary_missed_note_other' => ':count faltas/cancelamentos fora da conta.',
    'summary_all_missed'        => 'Todas as consultas de hoje foram canceladas ou tiveram falta.',

    // Agenda de hoje
    'arrived_at' => 'chegou às :time',
    'live_hint'  => 'Atendimentos que ainda não terminaram',
    'list_more'  => '+ :count na lista completa',

    // Sala de espera (recepção)
    'waiting_room_title'   => 'Sala de espera',
    'waiting_room_empty'   => 'Ninguém aguardando agora.',
    'waiting_room_in_care' => ':count em consulta',
    'waiting_room_arrived' => 'chegou às :time',
    'waiting_room_minutes' => ':minutes min',
    'waiting_room_waiting' => 'Aguardando há :minutes minutos',

    // Confirmações (recepção)
    'confirm_title'             => 'Confirmações',
    'confirm_subtitle'          => 'Consultas de hoje e de amanhã',
    'confirm_today'             => 'Hoje',
    'confirm_tomorrow'          => 'Amanhã',
    'confirm_open_schedule'     => 'Abrir agenda',
    'confirm_no_schedules'      => 'Nenhuma consulta marcada.',
    'confirm_of_total'          => 'de :total confirmadas',
    'confirm_meter_aria'        => ':confirmed de :total consultas confirmadas',
    'confirm_unconfirmed'       => ':count sem confirmação',
    'confirm_all_done'          => 'Todas confirmadas',
    'confirm_by_whatsapp'       => ':count pelo WhatsApp',
    'confirm_shift_confirmed'   => '(:count conf.)',
    'confirm_whatsapp_label'    => 'Confirmação por WhatsApp das que faltam',
    'confirm_wa_awaiting'       => ':count aguardando resposta',
    'confirm_wa_queued'         => ':count na fila de envio',
    'confirm_wa_failed'         => ':count com falha no envio',
    'confirm_wa_none'           => ':count sem envio',
    'confirm_wa_state_awaiting' => 'WhatsApp sem resposta',
    'confirm_wa_state_queued'   => 'WhatsApp na fila',
    'confirm_wa_state_failed'   => 'WhatsApp falhou',
    'confirm_call_title'        => 'Ligar para confirmar',
    'confirm_call_aria'         => 'Ligar para :name — :phone',
    'confirm_no_phone'          => 'Sem telefone',
    'confirm_truncated'         => 'Dia muito cheio: os números consideram as primeiras 1.000 consultas.',

    // Lista de espera e aniversariantes (recepção)
    'waitlist_title'       => 'Lista de espera',
    'waitlist_open'        => 'Abrir na agenda',
    'waitlist_empty'       => 'Lista de espera vazia.',
    'waitlist_between'     => 'de :from a :until',
    'waitlist_from'        => 'a partir de :date',
    'waitlist_until'       => 'até :date',
    'waitlist_since_today' => 'entrou hoje',
    'waitlist_since_one'   => 'há :count dia',
    'waitlist_since_other' => 'há :count dias',
    'birthdays_title'      => 'Aniversariantes de hoje',
    'birthdays_empty'      => 'Nenhum paciente faz aniversário hoje.',
    'birthdays_age'        => 'Completa :age anos',

    // Atendimentos por médico (gestão)
    'doctors_today_title'      => 'Atendimentos por médico',
    'doctors_today_empty'      => 'Nenhuma consulta hoje.',
    'doctors_col_progress'     => 'Atendidos',
    'doctors_col_waiting'      => 'Na clínica',
    'doctors_col_waiting_hint' => 'Chegaram e aguardam (inclui dilatação e exame)',
    'doctors_col_missed'       => 'Faltas',
    'doctors_progress_sr'      => ':attended de :expected atendidos',

    // Tendências
    'trend_daily_title'   => 'Consultas × faltas',
    'trend_daily_sub'     => 'Últimos 30 dias',
    'trend_finance_title' => 'Receita × despesa · 6 meses',
    'daily_attended'      => 'Atendidas',
    'daily_noshow'        => 'Faltas',
    'daily_cancelled'     => 'Canceladas',
    'daily_rate'          => 'Taxa de falta: :rate%',
    'daily_chart_aria'    => 'Últimos :days dias: :attended consultas atendidas e :noshow faltas (taxa de falta de :rate%).',
    'col_day'             => 'Dia',
    'see_data'            => 'Ver dados',
    'hide_data'           => 'Ocultar dados',
    // Textos do gráfico do BI reaproveitado (TrendBarChart)
    'fin_chart' => [
        'trend_chart_aria' => 'De :from a :to: receitas :income, despesas :expense, saldo :balance.',
        'col_month'        => 'Mês',
        'col_income'       => 'Receitas',
        'col_expense'      => 'Despesas',
        'col_balance'      => 'Saldo',
        'monthly_trend'    => 'Receita × despesa por mês',
        'see_data'         => 'Ver dados',
        'hide_data'        => 'Ocultar dados',
    ],
    'covenants_title'        => 'Faturado × recebido por convênio',
    'covenants_sub'          => 'Mês atual',
    'covenants_billed'       => 'Faturado',
    'covenants_paid'         => 'Recebido',
    'covenants_denied_label' => 'Glosado',
    'covenants_rate'         => 'Recebimento: :rate%',
    'covenants_empty'        => 'Nenhuma guia faturada neste mês.',
    'covenants_row'          => 'Recebido :paid',
    'covenants_denied'       => 'glosado :denied',
    'covenants_row_aria'     => ':name: faturado :billed, recebido :paid, glosado :denied.',

    // Financeiro: caixa de hoje, a receber e glosas
    'cash_today_title'           => 'Caixa de hoje',
    'cash_today_open'            => 'Abrir caixa',
    'cash_in'                    => 'Entradas pagas hoje',
    'cash_out'                   => 'Saídas pagas hoje',
    'cash_balance'               => 'Saldo do dia',
    'cash_receivable_today'      => 'A receber ainda hoje',
    'cash_payable_today'         => 'A pagar ainda hoje',
    'cash_projected'             => 'Saldo previsto do dia',
    'cash_entries_count'         => 'Lançamentos de hoje',
    'receivables_title'          => 'A receber',
    'receivables_sub'            => 'Posição de hoje',
    'receivables_cash'           => 'Particulares a vencer',
    'receivables_cash_overdue'   => 'Particulares vencidos (:count)',
    'receivables_claims'         => 'Guias de convênio em aberto (:count)',
    'receivables_claims_overdue' => 'Guias vencidas (:count)',
    'glosas_title'               => 'Glosas a tratar',
    'glosas_open'                => 'Ver glosas',
    'glosas_empty'               => 'Nenhuma glosa pendente.',
    'glosas_amount_hint'         => 'em aberto e em recurso',
    'glosas_overdue'             => 'Prazo de recurso vencido (:count)',
    'glosas_due_soon'            => 'Prazo vence em até :days dias (:count)',
    'glosas_open_count'          => 'Em aberto (:count)',
    'glosas_appealed'            => 'Em recurso (:count)',

    // Atalhos
    'module_patients'   => 'Pacientes',
    'module_glosas'     => 'Glosas',
    'module_bi'         => 'BI',
    'empty_strip_label' => 'Seções sem itens agora',
];
