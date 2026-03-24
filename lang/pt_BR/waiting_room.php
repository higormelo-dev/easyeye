<?php

declare(strict_types=1);

return [

    // ── Títulos e cabeçalhos ──────────────────────────────────────────────────
    'title'              => 'Sala de Espera',
    'title_admin'        => 'Sala de Espera — Administração',
    'updated_at'         => 'Atualizado às :time',
    'refresh'            => 'Atualizar',

    // ── Filtros ───────────────────────────────────────────────────────────────
    'filter_date'        => 'Data',
    'filter_doctor'      => 'Médico',
    'filter_doctor_all'  => 'Todos os médicos',
    'filter_status'      => 'Situação',
    'filter_status_all'  => 'Todas as situações',

    // ── KPIs ─────────────────────────────────────────────────────────────────
    'kpi_total'          => 'Total',
    'kpi_waiting'        => 'Aguardando',
    'kpi_in_progress'    => 'Em atendimento',
    'kpi_done'           => 'Concluídos',
    'kpi_absent'         => 'Faltas / Cancelados',

    // ── Colunas da tabela ─────────────────────────────────────────────────────
    'col_time'           => 'Hora',
    'col_patient'        => 'Paciente',
    'col_doctor'         => 'Médico',
    'col_type'           => 'Tipo',
    'col_status'         => 'Situação',
    'col_wait'           => 'Espera',
    'col_actions'        => 'Ações',

    // ── Ações operacionais (admin) ────────────────────────────────────────────
    'action_checkin'     => 'Chegou',
    'action_confirm'     => 'Confirmar',
    'action_start'       => 'Iniciar atendimento',
    'action_noshow'      => 'Marcar falta',
    'action_cancel'      => 'Cancelar',
    'action_reschedule'  => 'Reagendar',
    'action_view_card'   => 'Ver ficha',

    // ── Ações médico (doctor view) ────────────────────────────────────────────
    'action_call'        => 'Chamar',
    'action_finish'      => 'Finalizar',

    // ── Offcanvas "Ver Ficha" ─────────────────────────────────────────────────
    'card_title'         => 'Ficha do Paciente',
    'card_code'          => 'Código',
    'card_schedule_code' => 'Agendamento',
    'card_birthdate'     => 'Nascimento',
    'card_age'           => 'Idade',
    'card_covenant'      => 'Convênio',
    'card_visit_type'    => 'Tipo de consulta',
    'card_doctor'        => 'Médico',
    'card_phone'         => 'Telefone',
    'card_notes'         => 'Observações',
    'card_arrived_at'    => 'Chegou às',
    'card_view_patient'  => 'Ver cadastro completo',
    'card_no_patient'    => 'Sem cadastro vinculado',

    // ── Modais / confirmações ─────────────────────────────────────────────────
    'cancel_reason'          => 'Motivo do cancelamento (opcional)',
    'cancel_confirm'         => 'Cancelar agendamento',
    'cancel_confirm_message' => 'Deseja cancelar este agendamento?',
    'noshow_confirm'         => 'Marcar como falta',
    'noshow_confirm_message' => 'Deseja registrar a falta deste paciente?',

    // ── Estados vazios ────────────────────────────────────────────────────────
    'empty_queue'        => 'Nenhum paciente aguardando no momento.',
    'empty_day'          => 'Nenhum agendamento encontrado para este dia.',
    'empty_filtered'     => 'Nenhum resultado para os filtros selecionados.',

    // ── Status (mapeados do ScheduleSituation) ────────────────────────────────
    'status' => [
        'scheduled'   => 'Agendado',
        'confirmed'   => 'Confirmado',
        'waiting'     => 'Aguardando',
        'dilating'    => 'Dilatando',
        'exam'        => 'Em exame',
        'in_progress' => 'Em atendimento',
        'attended'    => 'Atendido',
        'noshow'      => 'Faltou',
        'cancelled'   => 'Cancelado',
    ],

];
