<?php

/*
|--------------------------------------------------------------------------
| Recall de pacientes crônicos (Fase 6)
|--------------------------------------------------------------------------
|
| O que NÃO é regra clínica validada pelo Revisor: o vocabulário que o código
| precisa para LER o dado da clínica. A regra (quando voltar, quais critérios)
| mora no conteúdo congelado do protocolo; aqui só estão os nomes que o
| protocolo pode citar.
*/

return [
    /*
    | Meses até uma versão liberada pedir NOVA validação (aviso no Manager; não bloqueia o uso).
    | O revisor pode indicar outro prazo; a literatura clínica muda devagar, 24 meses é o padrão.
    */
    'revalidation_months' => 24,

    /*
    | Prazos OPERACIONAIS do retorno (não são regra clínica; o intervalo clínico mora no protocolo):
    | quantos dias antes do vencimento o paciente entra na lista "a vencer", a tolerância depois do
    | vencimento antes de ficar "vencido" e quantos dias depois ele passa a "perdido de vista".
    | Valem também para o retorno que o médico pediu em `follow_up_days` (que não tem protocolo).
    */
    'operational' => [
        'lead_days'       => 30,
        'grace_days'      => 30,
        'lost_after_days' => 180,
    ],

    /*
    | Convite de retorno por WhatsApp (ADR 0002, item 9). Nada aqui é regra clínica: é o RITMO que protege o número
    | de WhatsApp (compartilhado com a confirmação de consulta) e o paciente.
    |
    |  - ramp: ramp-up do teto diário. Chave = dias desde `ramp_started_on` (o dia em que a clínica ligou os
    |    convites); valor = teto de convites POR DIA a partir daquele dia (nulo = sem limite do ramp-up). O teto
    |    efetivo é o MENOR entre o degrau vigente e o `daily_invite_cap` da clínica.
    |  - reply_valid_days: o botão do convite só vale por N dias depois do envio.
    |  - requeue_after_minutes: mensagem `pending` sem job (falha entre gravar e enfileirar) é reenfileirada
    |    depois disto; `expire_pending_days`: convite que não saiu nesse prazo (fila parada, fora da janela)
    |    é cancelado — convite de uma semana atrás não faz mais sentido.
    |  - min_days_between_invites_floor: a clínica não pode configurar intervalo menor que isto.
    |  - max_per_run_per_clinic: teto de ondas ENFILEIRADAS por rodada e por clínica (não de candidatos lidos: quem o
    |    guard recusa não ocupa vaga).
    */
    'invites' => [
        'ramp'                           => [0 => 5, 7 => 10, 14 => 20, 21 => null],
        'reply_valid_days'               => 30,
        'requeue_after_minutes'          => 5,
        'expire_pending_days'            => 3,
        'min_days_between_invites_floor' => 7,
        'max_per_run_per_clinic'         => 200,
    ],

    /*
    | Curso de injeções intravítreas (anti-VEGF — ADR 0002, item 14). Nada aqui decide o intervalo: quem decide é o
    | médico, a cada aplicação, dentro da faixa abaixo.
    |
    |  - drugs: as drogas que o médico escolhe ao abrir o curso (a chave vira `intravitreal_courses.drug_key`; o
    |    rótulo está em `recall_course.drugs.<chave>` e é gravado no curso). "other" pede o nome livre. Chave nova
    |    exige rótulo nos dois idiomas.
    |  - loading_doses: doses da fase de ataque do esquema "ataque e depois trate e estenda".
    |  - interval_weeks: faixa do intervalo que o curso aceita (o médico decide dentro dela).
    |  - operational: prazos do retorno do curso (a próxima aplicação é o retorno que o médico decidiu). Aplicação
    |    atrasada pesa mais que consulta de rotina: a tolerância é curta e o "perdido de vista" vem cedo.
    */
    'intravitreal' => [
        'drugs' => [
            'aflibercept'  => [],
            'ranibizumab'  => [],
            'bevacizumab'  => [],
            'faricimab'    => [],
            'brolucizumab' => [],
            'other'        => [],
        ],
        'loading_doses'  => 3,
        'interval_weeks' => ['min' => 2, 'max' => 16],
        'operational'    => [
            'lead_days'       => 14,
            'grace_days'      => 7,
            'lost_after_days' => 90,
        ],
    ],

    /*
    | Medicações que um protocolo pode usar como critério de entrada. A chave é o
    | que o conteúdo do protocolo cita; `ingredients` são os princípios ativos
    | (mesma normalização de TermNormalizer/clinical_safety) que o detector
    | procura nas medicações do paciente. Chave nova exige código e teste.
    */
    'medications' => [
        'hydroxychloroquine' => ['ingredients' => ['hidroxicloroquina']],
        'chloroquine'        => ['ingredients' => ['cloroquina']],
    ],
];
