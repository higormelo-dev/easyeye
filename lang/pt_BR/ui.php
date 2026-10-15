<?php

declare(strict_types=1);

/*
 * Textos de interface dos componentes compartilhados do painel (modais,
 * menus). Enviados em toda página como `t_ui` (HandleInertiaRequests).
 */
return [
    'close'   => 'Fechar',
    'loading' => 'Carregando...',

    // Progresso em tempo real (WebSocket/Reverb) — composables/useImportProgress.js
    'realtime_offline' => 'Conexão em tempo real indisponível — tentando reconectar…',

    // Busca de diagnóstico CID-10 (Components/Panel/Cid10Picker.vue)
    'cid10' => [
        'placeholder'  => 'Buscar por código ou diagnóstico (ex: H40.1, glaucoma)…',
        'search_label' => 'Buscar diagnóstico (CID-10)',
        'suggestions'  => 'Sugestões de diagnóstico',
        'most_used'    => 'Mais usados',
        'custom'       => 'Customizado',
        // Código criado no manager (fora da tabela oficial do DATASUS).
        'non_official'      => 'Fora da tabela oficial',
        'non_official_hint' => 'Código fora da tabela oficial da CID-10 (DATASUS): guias TISS podem recusar.',
        'create'            => "Cadastrar novo diagnóstico: ':term'",
        'primary'           => 'Diagnóstico principal',
        'mark_primary'      => 'Marcar como diagnóstico principal',
        'primary_toggle'    => 'Diagnóstico principal: :item',
        'remove'            => 'Remover :item',
        'searching'         => 'Buscando…',
        'results_one'       => ':count resultado',
        'results_other'     => ':count resultados',
        'no_results'        => 'Nenhum diagnóstico encontrado.',
    ],

    // Cadastro de paciente (componente compartilhado Pacientes/Agenda).
    'patient_form' => [
        // Celular respondeu SAIR ao WhatsApp da clínica/EasyEye.
        'wa_opted_out'      => 'Descadastrado do WhatsApp',
        'wa_opted_out_hint' => 'Este celular respondeu SAIR: não recebe confirmação nem pesquisa por WhatsApp até responder VOLTAR.',
        // Autorização de lembretes de retorno por WhatsApp (Fase 6, LGPD).
        'care_reminders'                => 'Autoriza lembretes de retorno por WhatsApp',
        'care_reminders_hint'           => 'O lembrete não cita o motivo do acompanhamento. O paciente pode parar a qualquer momento respondendo SAIR.',
        'care_reminders_needs_whatsapp' => 'Marque que o celular tem WhatsApp para registrar esta autorização.',
        'care_reminders_badge'          => 'Lembretes de retorno',
        'care_reminders_badge_hint'     => 'O paciente autorizou lembretes de retorno por WhatsApp.',
        'occupation'                    => 'Profissão',
        'occupation_placeholder'        => 'Ex.: professora, motorista, aposentado(a)',
        // Plano do convênio (produto da ANS ou plano da clínica)
        'plan'                 => 'Plano',
        'plan_placeholder'     => 'Buscar pelo nome ou registro na ANS',
        'plan_select_covenant' => 'Escolha o convênio primeiro',
        'plan_particular'      => 'Não se aplica a Particular',
        'plan_empty'           => 'Nenhum plano para este convênio',
        'plan_no_results'      => 'Nenhum plano encontrado',
        'plan_none'            => 'Nenhum plano cadastrado para este convênio. A clínica pode cadastrar em Configurações › Convênios › Planos.',
        'plan_unavailable'     => 'Este plano não está mais disponível (cancelado na ANS ou desativado). Escolha outro ao atualizar o cadastro.',
        'plan_hint'            => 'Opcional. O registro do produto na ANS vem impresso na carteirinha.',
        // Convênio do paciente inativado depois do cadastro (mantido, marcado).
        'covenant_inactive_suffix' => '(inativo)',
        // Carregar alergias/medicações falhou — trocar de aba e voltar tenta de novo (18ª revisão Codex, P2).
        'safety_load_error' => 'Não foi possível carregar alergias e medicações. Troque de aba e volte para tentar de novo.',
        // Homônimo: mesmo nome de outro paciente da clínica (PatientDuplicateFinder).
        'homonym_title'   => 'Paciente com o mesmo nome',
        'homonym_confirm' => 'Confirmo que é outro paciente',
    ],
];
