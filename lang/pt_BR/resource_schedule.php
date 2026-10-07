<?php

// Escala e bloqueios de sala/equipamento (Configurações › Recursos).
return [
    'title'             => 'Escala e bloqueios',
    'breadcrumb'        => 'Escala e bloqueios',
    'back'              => 'Voltar para Recursos',
    'action'            => 'Escala e bloqueios',
    'intro'             => 'Defina quando esta sala/equipamento pode ser reservada na Agenda. Sem nenhum dia ativo, não há restrição de horário; bloqueios valem sempre.',
    'active_days_zero'  => 'Nenhum dia ativo',
    'active_days_one'   => '1 dia ativo',
    'active_days_other' => ':count dias ativos',
    'schedule_title'    => 'Horários de disponibilidade',
    'day_toggle'        => 'Disponível em :day',
    'day_off'           => 'Indisponível neste dia.',
    'add_range'         => 'Faixa',
    'add_range_aria'    => 'Adicionar faixa de horário em :day',
    'remove_range'      => 'Remover faixa de horário',
    'from'              => 'De',
    'until'             => 'até',
    'range_start'       => 'Início da faixa em :day',
    'range_end'         => 'Fim da faixa em :day',
    'no_ranges'         => 'Nenhuma faixa de horário. Use "+ Faixa" para adicionar.',
    'save'              => 'Salvar escala',
    'saving'            => 'Salvando…',
    'saved'             => 'Escala salva com sucesso.',
    'save_error'        => 'Não foi possível salvar a escala.',
    'conn_error'        => 'Erro de conexão. Tente novamente.',

    'blocks_title'      => 'Bloqueios',
    'blocks_hint'       => 'Períodos em que a sala/equipamento não pode ser reservado (manutenção, feriado…).',
    'block_add'         => 'Adicionar bloqueio',
    'block_type'        => 'Tipo',
    'block_starts'      => 'Início',
    'block_ends'        => 'Fim',
    'block_reason'      => 'Motivo',
    'block_reason_ph'   => 'Motivo (opcional)',
    'block_save'        => 'Salvar bloqueio',
    'block_cancel'      => 'Cancelar',
    'block_remove'      => 'Remover bloqueio',
    'block_remove_aria' => 'Remover bloqueio de :start a :end',
    'block_confirm'     => 'Remover este bloqueio? Esta ação não pode ser desfeita.',
    'no_blocks'         => 'Nenhum bloqueio futuro.',
    'block_error'       => 'Não foi possível salvar o bloqueio.',

    'block_types' => [
        'absence' => 'Indisponível',
        'holiday' => 'Feriado',
        'meeting' => 'Manutenção',
        'other'   => 'Outro',
    ],

    'resource_types' => [
        'room'      => 'Sala',
        'equipment' => 'Equipamento',
        'other'     => 'Outro',
    ],

    // Validação da escala (sync)
    'range_invalid'   => ':day: o fim da faixa (:end) precisa ser depois do início (:start).',
    'range_duplicate' => ':day: há duas faixas começando às :start.',
    'range_overlap'   => ':day: as faixas :first e :second se sobrepõem.',
];
