<?php

/*
 * Curso de injeções intravítreas (anti-VEGF) — Fase 6, ADR 0002 item 10. Textos do cartão do paciente, do pedido e da
 * execução (lote, autorização, intervalo decidido). Plural com "a|b" (escolhe pela contagem :count).
 */

return [
    'status' => [
        'active'       => 'Em andamento',
        'paused'       => 'Pausado',
        'completed'    => 'Concluído',
        'discontinued' => 'Interrompido',
        'lost'         => 'Perdido de vista',
    ],

    'regimen' => [
        'loading_tae' => 'Ataque e depois trate e estenda',
        'prn'         => 'Sob demanda (PRN)',
        'fixed'       => 'Intervalo fixo',
    ],

    'application_status' => [
        'planned'   => 'Planejada',
        'performed' => 'Realizada',
        'cancelled' => 'Cancelada',
        'missed'    => 'Perdida',
    ],

    'authorization' => [
        'not_required' => 'Sem autorização',
        'pending'      => 'A solicitar',
        'requested'    => 'Solicitada',
        'approved'     => 'Aprovada',
        'denied'       => 'Negada',
        'expired'      => 'Vencida',
    ],

    'drugs' => [
        'aflibercept'  => 'Aflibercepte',
        'ranibizumab'  => 'Ranibizumabe',
        'bevacizumab'  => 'Bevacizumabe',
        'faricimab'    => 'Faricimabe',
        'brolucizumab' => 'Brolucizumabe',
        'other'        => 'Outra droga',
    ],

    'panel' => [
        'title'            => 'Tratamento com injeções intravítreas',
        'eye'              => ['right' => 'OD', 'left' => 'OE'],
        'applications'     => ':count aplicação|:count aplicações',
        'interval'         => 'intervalo decidido: :weeks semanas',
        'next'             => 'Próxima aplicação: :date',
        'in_days'          => 'em :count dia|em :count dias',
        'overdue_days'     => 'atrasada há :count dia|atrasada há :count dias',
        'today'            => 'hoje',
        'no_next'          => 'Sem próxima aplicação planejada.',
        'has_schedule'     => 'Agendada',
        'ended'            => 'Encerrado em :date. :reason',
        'last_lot'         => 'Última: lote :lot (validade :expiry) · autorização: :auth',
        'schedule'         => 'Agendar a próxima',
        'schedule_when'    => 'Data e hora da próxima aplicação',
        'schedule_hint'    => 'Entra na série aberta do tratamento, com a mesma regra do agendamento comum (médico, intervalo, bloqueio, sala).',
        'schedule_confirm' => 'Agendar',
        'close'            => 'Encerrar curso',
        'close_status'     => 'Como o curso termina?',
        'close_reason'     => 'Motivo',
        'close_confirm'    => 'Encerrar curso',
        'back'             => 'Voltar',
    ],

    'request' => [
        'title'        => 'Curso de injeções intravítreas',
        'hint'         => 'Cada olho tem o seu curso: o sistema abre o do olho escolhido (ou continua o que já existe) e guarda lote, autorização e o intervalo decidido a cada aplicação.',
        'eye_required' => 'Escolha OD ou OE: o curso é de um olho só.',
        'drug'         => 'Droga',
        'drug_other'   => 'Nome da droga',
        'regimen'      => 'Esquema',
        'regimen_hint' => 'O esquema só orienta o sistema: a cada aplicação o médico decide o intervalo até a próxima.',
        'indication'   => 'Indicação (opcional)',
    ],

    'exec' => [
        'title'            => 'Aplicação intravítrea',
        'title_n'          => 'Aplicação intravítrea nº :n',
        'stock_lot'        => 'Lote do estoque',
        'stock_lot_none'   => 'Digitar o lote',
        'lot'              => 'Lote',
        'expiry'           => 'Validade do lote',
        'auth_required'    => 'O convênio exige autorização desta aplicação',
        'auth_status'      => 'Situação da autorização',
        'auth_number'      => 'Número',
        'auth_until'       => 'Válida até',
        'auth_override'    => 'Aplicar assim mesmo (autorização não aprovada)',
        'auth_notes'       => 'Justificativa',
        'end'              => 'Encerrar o curso nesta aplicação?',
        'end_none'         => 'Não, continua',
        'end_completed'    => 'Sim, concluído',
        'end_discontinued' => 'Sim, interrompido',
        'end_reason'       => 'Motivo do encerramento',
        'interval'         => 'Intervalo até a próxima (semanas)',
        'interval_hint'    => 'De :min a :max semanas. O médico decide a cada visita.',
        'schedule'         => 'Agendar a próxima já (opcional)',
        'schedule_hint'    => 'Se o horário não couber, a aplicação fica registrada e a próxima segue planejada, sem agendamento.',
    ],

    'done' => [
        'closed'          => 'Curso encerrado.',
        'scheduled'       => 'Próxima aplicação agendada.',
        'executed'        => 'Aplicação registrada. Próxima aplicação prevista para :date.',
        'executed_closed' => 'Aplicação registrada e curso encerrado.',
        'schedule_failed' => 'A aplicação foi registrada, mas a próxima não pôde ser agendada: :reason',
    ],

    'errors' => [
        'generic'               => 'Não foi possível concluir. Tente de novo.',
        'eye_required'          => 'Escolha OD ou OE: o curso é de um olho só.',
        'drug_required'         => 'Informe a droga do curso.',
        'not_planned'           => 'Esta aplicação já foi registrada ou não está planejada.',
        'eye_mismatch'          => 'O olho do procedimento não é o do curso.',
        'lot_required'          => 'Informe o lote e a validade do medicamento.',
        'lot_expired'           => 'O lote está vencido: não aplique este medicamento.',
        'lot_not_found'         => 'Lote do estoque não encontrado (ou sem validade).',
        'authorization_missing' => 'A autorização do convênio não está aprovada: confirme que vai aplicar assim mesmo e justifique.',
        'interval_required'     => 'Decida o intervalo até a próxima aplicação (ou encerre o curso).',
        'interval_range'        => 'O intervalo vai de 2 a 16 semanas.',
        'end_reason_required'   => 'Informe o motivo de encerrar o curso.',
        'course_not_found'      => 'Curso não encontrado nesta clínica.',
        'course_closed'         => 'Este curso já foi encerrado.',
        'no_reference_schedule' => 'Não há agendamento de referência para a série: agende pela Agenda.',
        'already_scheduled'     => 'A próxima aplicação já está agendada.',
        'other_doctor_calendar' => 'O agendamento de referência é da agenda de outro médico: agende pela Agenda, na sua própria agenda.',
    ],
];
