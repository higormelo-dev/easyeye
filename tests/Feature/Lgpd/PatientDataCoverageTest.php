<?php

declare(strict_types=1);

/**
 * Cobertura da exportação LGPD por ESQUEMA (art. 18, II e V; art. 19, II): toda tabela com chave estrangeira para
 * `patients` precisa estar classificada aqui — coberta por uma seção do PatientDataExporter, excluída por um motivo que
 * a própria exportação declara (`export_scope`) ou conhecida como LACUNA (registrada, com o porquê). Tabela nova com
 * dado do paciente que ninguém classificou REPROVA este teste: é assim que a exportação deixa de ficar para trás
 * quando uma fase cria tabela (o convite de retorno por WhatsApp e as condições de recall nasceram assim).
 *
 * E um teste de conteúdo: o que as seções novas (Fase 6) exportam é do paciente e só dele.
 */

use App\Domains\CareGuidance\Models\CareGuidanceIssue;
use App\Domains\Recall\Enums\InviteOrigin;
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Models\{PatientRecallEvent, RecallInvite};
use App\Enums\{Eye, UrgentCarePriority, UrgentCareReason, UrgentCareStatus};
use App\Models\UrgentCareEntry;
use App\Models\WhatsApp\WhatsAppMessage;
use App\Services\Lgpd\PatientDataExporter;
use Illuminate\Support\Facades\DB;

/** tabela → seção do arquivo exportado (`PatientDataExporter::export()`). */
const PDC_COVERED = [
    'ai_runs'                   => 'ai_processing',
    'billing_claims'            => 'financial',
    'care_guidance_issues'      => 'care_guidance',
    'data_access_logs'          => 'access_log_summary',
    'financial_cash_entries'    => 'financial',
    'intravitreal_applications' => 'intravitreal',
    'intravitreal_courses'      => 'intravitreal',
    'lgpd_requests'             => 'lgpd_requests',
    // Módulo cirúrgico (Fase 4): SurgeryDataExporter entrega a seção `surgery` (as demais tabelas do módulo ligam ao caso, não ao paciente).
    'surgery_document_issues'       => 'surgery',
    'surgical_cases'                => 'surgery',
    'surgical_description_addenda'  => 'surgery',
    'surgical_descriptions'         => 'surgery',
    'surgical_implants'             => 'surgery',
    'surgical_postop_evaluations'   => 'surgery',
    'medical_record_documentations' => 'medical_records',
    'medical_record_evolutions'     => 'medical_records',
    'medical_record_files'          => 'medical_records',
    'medical_record_procedures'     => 'medical_records',
    'medical_records'               => 'medical_records',
    'patient_allergies'             => 'clinical_safety',
    'patient_conditions'            => 'recall',
    'patient_consents'              => 'consents',
    'patient_document_shares'       => 'document_shares',
    'patient_exams'                 => 'exams',
    'patient_medications'           => 'clinical_safety',
    'patient_recall_blocks'         => 'recall',
    'patient_recall_events'         => 'recall',
    'patient_recalls'               => 'recall',
    'recall_invites'                => 'recall',
    'schedules'                     => 'appointments',
    'tiss_guides'                   => 'tiss_guides',
    'urgent_care_entries'           => 'urgent_care',
    'waiting_list'                  => 'waiting_list',
    'whatsapp_messages'             => 'messages',
];

/** tabela → categoria de `export_scope.not_included`. */
const PDC_EXCLUDED = [
    // Fila técnica da reavaliação do recall: só o id do paciente, o motivo e a hora (nenhum conteúdo).
    'recall_dirty_patients' => 'technical_records',
    // Repasse do médico: dado da relação clínica–profissional (o paciente aparece só como origem do ato pago).
    'doctor_payout_items' => 'professional_compensation',
];

/**
 * Lacunas CONHECIDAS, ainda fora da exportação — o que falta e por que não foi feito junto com a Fase 6. Cada uma é um
 * trabalho a priorizar; o teste existe para que ninguém acrescente outra sem ver.
 */
const PDC_KNOWN_GAPS = [
    'medical_record_drafts'   => 'Rascunho de prontuário em andamento (autosave cifrado, apagado ao salvar): só entra na exportação depois de salvo.',
    'eye_image_report_drafts' => 'Rascunho de laudo de imagem em andamento: entra na exportação depois de emitido (documentação do prontuário).',
];

beforeEach(function () {
    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
    $this->patient                                         = rcfInvitePatient($this->entity, 'PACIENTE EXPORTADO', '11977776666');
});

function pdcExport(object $test): array
{
    return app(PatientDataExporter::class)->export($test->patient->fresh());
}

it('toda tabela com chave estrangeira para pacientes está classificada (coberta, excluída ou lacuna conhecida)', function () {
    $tables = collect(DB::select(
        "select distinct tc.table_name as t from information_schema.table_constraints tc
         join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
         where tc.constraint_type = 'FOREIGN KEY' and ccu.table_name = 'patients' and tc.table_schema = current_schema()",
    ))->pluck('t')->all();

    $classified = [...array_keys(PDC_COVERED), ...array_keys(PDC_EXCLUDED), ...array_keys(PDC_KNOWN_GAPS)];

    expect(array_values(array_diff($tables, $classified)))->toBe([], 'Tabela nova com dado do paciente: inclua no PatientDataExporter e classifique em PatientDataCoverageTest.')
        // E nada classificado aqui deixou de existir (registro velho confunde).
        ->and(array_values(array_diff($classified, $tables, ['recall_invite_items'])))->toBe([]);
});

it('toda seção declarada como cobertura existe no arquivo exportado e a versão do formato subiu', function () {
    $export = pdcExport($this);

    foreach (array_unique(array_values(PDC_COVERED)) as $section) {
        expect($export)->toHaveKey($section);
    }

    expect($export['format_version'])->toBe(6)
        ->and(PatientDataExporter::FORMAT_VERSION)->toBe(6);
});

it('as exclusões usam categorias que a exportação declara e as lacunas têm explicação', function () {
    $declared = collect(pdcExport($this)['export_scope']['not_included'])->pluck('category')->all();

    foreach (PDC_EXCLUDED as $table => $category) {
        expect(in_array($category, $declared, true))->toBeTrue("{$table}: categoria '{$category}' não declarada em export_scope");
    }

    foreach (PDC_KNOWN_GAPS as $table => $note) {
        expect(strlen($note))->toBeGreaterThan(30, $table);
    }
});

it('exporta o retorno, as condições, os bloqueios e os convites por WhatsApp do paciente — e só dele', function () {
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2025-05-10');
    $recall = rcfOverdueRecall($this->entity, $this->patient, $this->doctor);

    PatientRecallEvent::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'recall_id' => $recall->id,
        'event'     => 'contacted', 'actor_label' => 'Recepção', 'meta' => ['note' => 'Pediu para ligar à tarde.'],
    ]);

    $invite = RecallInvite::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'wave_key' => '2026-02-01:' . $this->patient->id, 'origin' => InviteOrigin::Manual->value,
    ]);
    DB::table('recall_invite_items')->insert(['recall_invite_id' => $invite->id, 'patient_recall_id' => $recall->id]);

    WhatsAppMessage::query()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'recall_invite_id' => $invite->id, 'direction' => 'out', 'kind' => 'recall',
        'phone'     => '11977776666', 'body' => 'MENSAGEM-DE-CONVITE-EXPORTADA', 'status' => 'sent',
    ]);

    // Outro paciente da MESMA clínica: nada dele pode aparecer.
    $other = rcfInvitePatient($this->entity, 'OUTRO-PACIENTE-ISCA', '11955554444');
    rcfCondition($this->entity, $other, 'glaucoma', Eye::Left, '2025-07-01');
    WhatsAppMessage::query()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $other->id, 'direction' => 'out', 'kind' => 'recall',
        'phone'     => '11955554444', 'body' => 'MENSAGEM-DE-OUTRO-ISCA', 'status' => 'sent',
    ]);

    $export = pdcExport($this);
    $json   = json_encode($export, JSON_UNESCAPED_UNICODE);

    expect($export['recall']['conditions'])->toHaveCount(1)
        ->and($export['recall']['conditions'][0]['protocol'])->toBe('glaucoma')
        ->and($export['recall']['returns'])->not->toBeEmpty()
        ->and(collect($export['recall']['returns'])->pluck('source')->all())->toContain('doctor_order')
        ->and(collect($export['recall']['returns'][0]['history'])->firstWhere('event', 'contacted')['meta']['note'])->toBe('Pediu para ligar à tarde.')
        ->and($export['recall']['whatsapp_invites'])->toHaveCount(1)
        ->and($export['recall']['whatsapp_invites'][0]['returns'])->toBe(1)
        ->and($export['recall']['whatsapp_invites'][0]['origin'])->not->toBeEmpty()
        // O convite sai em `messages` (o corpo da mensagem enviada ao titular), sem agendamento.
        ->and(collect($export['messages'])->pluck('body')->all())->toContain('MENSAGEM-DE-CONVITE-EXPORTADA')
        ->and($json)->not->toContain('OUTRO-PACIENTE-ISCA')
        ->and($json)->not->toContain('MENSAGEM-DE-OUTRO-ISCA');
});

it('exporta o curso de injeções (lote, autorização, intervalo) e as orientações entregues — nada de outro paciente nem de outra clínica', function () {
    $course = IntravitrealCourse::query()->withoutGlobalScopes()->create([
        'entity_id'              => $this->entity->id, 'patient_id' => $this->patient->id, 'eye' => 'right', 'drug_key' => 'aflibercept', 'drug_label' => 'Aflibercepte',
        'indication'             => 'DMRI neovascular', 'regimen' => 'loading_tae', 'status' => 'active', 'started_on' => '2026-01-05', 'last_applied_on' => '2026-03-10',
        'current_interval_weeks' => 8, 'next_due_on' => '2026-05-05', 'responsible_doctor_id' => $this->doctor->id,
    ]);
    IntravitrealApplication::query()->withoutGlobalScopes()->create([
        'entity_id'              => $this->entity->id, 'patient_id' => $this->patient->id, 'course_id' => $course->id, 'sequence_no' => 1, 'phase' => 'loading', 'status' => 'performed',
        'performed_at'           => '2026-03-10 10:00:00', 'eye' => 'right', 'drug_label' => 'Aflibercepte', 'lot_number' => 'LOT-EXPORT', 'lot_expiry' => '2027-01-31',
        'authorization_required' => true, 'authorization_status' => 'approved', 'authorization_number' => 'AUT-1', 'decided_interval_weeks' => 8,
    ]);

    CareGuidanceIssue::query()->withoutGlobalScopes()->create([
        'entity_id'    => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'title' => 'Pós-operatório', 'location_label' => 'Sala 2',
        'eye'          => 'right', 'body' => 'TEXTO-ORIENTACAO-EXPORTADO', 'eye_drops' => [['name' => 'Moxifloxacino', 'how' => '1 gota', 'times_per_day' => 4, 'days' => 7]],
        'attachments'  => [['name' => 'preparo.pdf', 'size_bytes' => 100, 'sha256' => str_repeat('a', 64), 'path' => 'care-guidance/segredo/caminho-interno.pdf']],
        'content_hash' => '1:' . str_repeat('b', 64), 'issued_at' => now(),
    ]);

    UrgentCareEntry::query()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'reason' => UrgentCareReason::RedEye->value, 'reason_details' => 'Olho vermelho há 2 dias',
        'priority'  => UrgentCarePriority::Normal->value, 'status' => UrgentCareStatus::Waiting->value, 'arrived_at' => now(),
    ]);

    // Outra clínica: nada pode vazar.
    ['entity' => $foreignEntity] = rcfClinic(recall: true);
    $foreign                     = rcfInvitePatient($foreignEntity, 'PACIENTE-OUTRA-CLINICA', '11944443333');
    CareGuidanceIssue::query()->withoutGlobalScopes()->create([
        'entity_id' => $foreignEntity->id, 'patient_id' => $foreign->id, 'title' => 'ISCA', 'body' => 'TEXTO-OUTRA-CLINICA', 'content_hash' => '1:' . str_repeat('c', 64), 'issued_at' => now(),
    ]);

    $export = pdcExport($this);
    $json   = json_encode($export, JSON_UNESCAPED_UNICODE);

    expect($export['intravitreal'])->toHaveCount(1)
        ->and($export['intravitreal'][0]['drug'])->toBe('Aflibercepte')
        ->and($export['intravitreal'][0]['interval_weeks'])->toBe(8)
        ->and($export['intravitreal'][0]['applications'][0]['lot_number'])->toBe('LOT-EXPORT')
        ->and($export['intravitreal'][0]['applications'][0]['authorization_number'])->toBe('AUT-1')
        ->and($export['care_guidance'])->toHaveCount(1)
        ->and($export['care_guidance'][0]['text'])->toBe('TEXTO-ORIENTACAO-EXPORTADO')
        ->and($export['care_guidance'][0]['eye_drops'][0]['name'])->toBe('Moxifloxacino')
        ->and($export['care_guidance'][0]['attachments'][0])->toBe(['name' => 'preparo.pdf', 'size_bytes' => 100, 'sha256' => str_repeat('a', 64)])
        ->and($export['urgent_care'])->toHaveCount(1)
        ->and($export['urgent_care'][0]['reason_details'])->toBe('Olho vermelho há 2 dias')
        // O caminho interno do arquivo é infraestrutura, não dado do titular.
        ->and($json)->not->toContain('caminho-interno')
        ->and($json)->not->toContain('TEXTO-OUTRA-CLINICA')
        ->and($json)->not->toContain('PACIENTE-OUTRA-CLINICA');
});
