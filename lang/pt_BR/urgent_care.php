<?php

declare(strict_types=1);

/*
 * Pronto atendimento (Fase 1 / B1) — fila de demanda espontânea do dia.
 * Tela Panel/UrgentCare/Index (prop `t`), enums UrgentCare* e mensagens do
 * UrgentCareController/UrgentCareService.
 */
return [
    'title'    => 'Pronto atendimento',
    'subtitle' => 'Quem chegou sem hora marcada, por prioridade e ordem de chegada.',

    // Resumo do topo
    'summary_queued'    => 'Na fila',
    'summary_immediate' => 'Imediatas',
    'summary_longest'   => 'Maior espera',
    'live_region'       => ':count na fila do pronto atendimento',
    'updated_at'        => 'Atualizado às :time',
    'refresh'           => 'Atualizar',

    // Fila
    'add'              => 'Adicionar paciente',
    'queue_title'      => 'Fila agora',
    'queue_label'      => 'Fila do pronto atendimento, na ordem de atendimento',
    'queue_empty'      => 'Ninguém na fila do pronto atendimento.',
    'queue_empty_hint' => 'Quem chegar sem hora marcada entra aqui pelo botão "Adicionar paciente".',
    'closed_title'     => 'Encerrados hoje',
    'closed_empty'     => 'Nada encerrado hoje.',
    'position'         => ':n.º',
    'age_years'        => ':age anos',
    'arrived_at'       => 'Chegou às :time',
    'waiting_for'      => 'Esperando há :time',
    'called_at'        => 'Chamado às :time',
    'called_times'     => ':count chamadas',
    'attended_at'      => 'Atendido às :time por :doctor',
    'closed_at'        => 'Às :time — :reason',
    'no_doctor'        => 'Qualquer médico',
    'no_covenant'      => 'Sem convênio',
    'minutes_short'    => ':m min',
    'hours_short'      => ':h h :m min',
    'doctor_label'     => 'Médico',
    'covenant_label'   => 'Convênio',
    'phone_label'      => 'Telefone',

    // Andamento
    'status_waiting' => 'Aguardando',
    'status_called'  => 'Chamado',
    'status_in_care' => 'Em atendimento',
    'status_left'    => 'Desistiu',
    'status_removed' => 'Removido',

    // Prioridade (Lei 10.048/2000 e Estatuto da Pessoa Idosa)
    'priority_immediate'      => 'Imediata',
    'priority_immediate_hint' => 'Risco à visão agora: passa na frente de todos (queimadura química, trauma, perda súbita de visão).',
    'priority_legal'          => 'Prioritária por lei',
    'priority_legal_hint'     => 'Lei 10.048/2000: 60 anos ou mais, gestantes, lactantes, pessoas com criança de colo, pessoas com deficiência ou mobilidade reduzida e obesos.',
    'priority_normal'         => 'Normal',
    'priority_normal_hint'    => 'Ordem de chegada.',
    'priority_suggested'      => 'Prioridade sugerida pelo motivo. Você pode alterar.',
    'priority_suggested_age'  => 'Paciente com :age anos: prioridade por lei sugerida.',
    'chemical_burn_alert'     => 'Queimadura química: avise o médico agora. A lavagem do olho deve começar imediatamente.',

    // Motivos (lista rápida oftalmológica)
    'reason_ocular_trauma'      => 'Trauma ocular',
    'reason_foreign_body'       => 'Corpo estranho',
    'reason_chemical_burn'      => 'Queimadura química',
    'reason_red_eye'            => 'Olho vermelho',
    'reason_eye_pain'           => 'Dor ocular',
    'reason_sudden_vision_loss' => 'Perda súbita de visão',
    'reason_post_op_complaint'  => 'Pós-operatório com queixa',
    'reason_other'              => 'Outro',
    'reason_details_ph'         => 'Descreva a queixa',
    'reason_details_required'   => 'Descreva a queixa (obrigatório em "Outro").',

    // Formulário
    'add_title'             => 'Adicionar ao pronto atendimento',
    'edit_title'            => 'Editar item da fila',
    'field_patient'         => 'Paciente',
    'field_reason'          => 'Motivo',
    'field_reason_details'  => 'Descrição da queixa',
    'field_priority'        => 'Prioridade',
    'field_doctor'          => 'Médico preferido',
    'field_covenant'        => 'Convênio',
    'field_close_status'    => 'Situação',
    'field_close_reason'    => 'Motivo',
    'doctor_optional'       => 'Opcional — sem médico, qualquer médico da clínica pode atender.',
    'covenant_hint'         => 'Só convênios ativos. Vem do cadastro do paciente; troque se necessário.',
    'patient_search_ph'     => 'Nome, CPF, telefone ou código PAC',
    'patient_search_min'    => 'Digite ao menos 2 letras.',
    'patient_none'          => 'Nenhum paciente encontrado.',
    'patient_new'           => 'Cadastrar paciente',
    'patient_change'        => 'Trocar paciente',
    'patient_already'       => 'Já está na fila',
    'quick_title'           => 'Cadastro rápido',
    'quick_name'            => 'Nome completo',
    'quick_cellphone'       => 'Celular',
    'quick_birth_date'      => 'Data de nascimento',
    'quick_birth_hint'      => 'Ajuda a evitar cadastro duplicado e a sugerir a prioridade por idade.',
    'quick_save'            => 'Cadastrar e selecionar',
    'quick_homonym_confirm' => 'Confirmo que é outro paciente',
    'save'                  => 'Salvar',
    'saving'                => 'Salvando…',
    'cancel'                => 'Cancelar',
    'close'                 => 'Fechar',

    // Ações
    'action_call'         => 'Chamar',
    'action_call_again'   => 'Chamar de novo',
    'action_attend'       => 'Atender',
    'action_edit'         => 'Editar',
    'action_left'         => 'Desistiu',
    'action_remove'       => 'Remover da fila',
    'action_more'         => 'Mais ações',
    'action_open_record'  => 'Abrir prontuário',
    'attend_hint'         => 'Cria o atendimento de urgência agora e abre o prontuário.',
    'attend_doctor_only'  => 'Só o médico (com cadastro de médico ativo) pode atender.',
    'attend_staff_hint'   => 'O médico atende pela tela dele de Pronto atendimento.',
    'call_hint'           => 'Mostra o nome do paciente no painel de chamadas da sala de espera.',
    'close_left_title'    => 'Paciente desistiu',
    'close_removed_title' => 'Remover da fila',
    'close_reason_ph'     => 'Ex.: foi embora sem avisar, encaminhado ao hospital, cadastrado por engano',
    'close_confirm'       => 'Confirmar',

    // Mensagens
    'added'            => ':name entrou na fila do pronto atendimento.',
    'updated'          => 'Item da fila atualizado.',
    'called'           => 'Paciente chamado no painel da sala de espera.',
    'closed_left'      => 'Desistência registrada.',
    'closed_removed'   => 'Paciente removido da fila.',
    'already_queued'   => 'Este paciente já está na fila de hoje. Edite o item existente.',
    'no_longer_queued' => 'Este paciente não está mais na fila (:status). A lista foi atualizada.',
    'not_yours'        => 'Este item da fila é de outro médico.',
    'invalid_doctor'   => 'Escolha um médico ativo desta clínica.',

    // Agendamento criado pelo "Atender"
    'schedule_note' => 'Pronto atendimento — :reason',
    'badge'         => 'Pronto atendimento',
];
