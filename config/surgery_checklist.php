<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Checklist de cirurgia segura (OMS) — catálogo oftalmológico versionado
|--------------------------------------------------------------------------
| Cada item grava, na resposta, a CHAVE, a VERSÃO do catálogo e o TEXTO no
| idioma de quem respondeu (surgical_checklist_entries.item_text): mudar este
| arquivo não reescreve o que já foi conferido. Ao mudar itens, suba `version`.
|
| Campos de cada item:
|   critical : "não" exige nota para fechar a fase (e nunca passa em silêncio).
|   allow_na : aceita "não se aplica" (item condicional ao caso).
|   na_when  : regra automática que SUGERE "não se aplica" na tela, sem impô-la:
|              `topical_anesthesia` (anestesia tópica, sem jejum) ou `no_iol_plan`
|              (caso sem LIO planejada). A resposta final é sempre do usuário.
|
| Textos: lang/{pt_BR,en}/surgery_schedule.php → checklist.items.<chave>.
*/
return [
    'version' => '2026.1',

    'phases' => [
        // Antes da anestesia.
        'sign_in' => [
            ['key' => 'identity_confirmed', 'critical' => true, 'allow_na' => false],
            ['key' => 'eye_marked', 'critical' => true, 'allow_na' => false],
            ['key' => 'consent_signed', 'critical' => true, 'allow_na' => false],
            ['key' => 'allergies_reviewed', 'critical' => true, 'allow_na' => false],
            ['key' => 'anticoagulant_aware', 'critical' => true, 'allow_na' => true],
            ['key' => 'ifis_aware', 'critical' => true, 'allow_na' => true],
            ['key' => 'fasting_confirmed', 'critical' => false, 'allow_na' => true, 'na_when' => 'topical_anesthesia'],
            ['key' => 'anesthesia_confirmed', 'critical' => true, 'allow_na' => false],
            ['key' => 'vitals_checked', 'critical' => false, 'allow_na' => true],
        ],
        // Antes da incisão.
        'time_out' => [
            ['key' => 'team_introduced', 'critical' => false, 'allow_na' => false],
            ['key' => 'patient_procedure_eye', 'critical' => true, 'allow_na' => false],
            ['key' => 'iol_checked', 'critical' => true, 'allow_na' => true, 'na_when' => 'no_iol_plan'],
            ['key' => 'equipment_ready', 'critical' => true, 'allow_na' => false],
            ['key' => 'antisepsis_prophylaxis', 'critical' => false, 'allow_na' => true],
            ['key' => 'critical_steps_reviewed', 'critical' => false, 'allow_na' => false],
            ['key' => 'imaging_displayed', 'critical' => false, 'allow_na' => true],
        ],
        // Antes de o paciente deixar a sala.
        'sign_out' => [
            ['key' => 'procedure_recorded', 'critical' => true, 'allow_na' => false],
            ['key' => 'counts_correct', 'critical' => true, 'allow_na' => true],
            ['key' => 'implant_recorded', 'critical' => true, 'allow_na' => true, 'na_when' => 'no_iol_plan'],
            ['key' => 'specimens_labelled', 'critical' => false, 'allow_na' => true],
            ['key' => 'equipment_issues', 'critical' => false, 'allow_na' => true],
            ['key' => 'eye_protected', 'critical' => false, 'allow_na' => true],
            ['key' => 'recovery_plan', 'critical' => true, 'allow_na' => false],
        ],
    ],

    // Justificativa mínima da exceção da Pausa Cirúrgica (somente o cirurgião).
    'override_min_length' => 10,
    // Nota mínima exigida para fechar a fase com item crítico respondido "não".
    'critical_note_min_length' => 10,
];
