<?php

declare(strict_types=1);

/**
 * Isolamento da exportação LGPD nas seções da Fase 6 (recall, convites, curso de injeções, orientações, pronto
 * atendimento). O teste de cobertura por esquema prova que a seção EXISTE; este prova que cada consulta filtra por
 * clínica E por paciente por conta própria — o filtro da clínica é a segunda barreira: uma linha com o `patient_id` do
 * titular mas o `entity_id` de outra clínica (dado corrompido, importação errada) nunca sai no arquivo dele, e uma
 * linha de OUTRO paciente da mesma clínica também não. Cada caso isola UM filtro (um mutante por filtro, ver S15).
 */

use App\Domains\CareGuidance\Models\CareGuidanceIssue;
use App\Domains\Recall\Enums\{InviteOrigin, InviteResponse};
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Models\{PatientCondition, RecallInvite};
use App\Enums\{Eye, UrgentCarePriority, UrgentCareReason, UrgentCareStatus};
use App\Models\UrgentCareEntry;
use App\Services\Lgpd\PatientDataExporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);
    ['entity' => $this->foreignEntity]                     = rcfClinic(recall: true);

    $this->patient = rcfInvitePatient($this->entity, 'PACIENTE EXPORTADO', '11977776666');
    $this->other   = rcfInvitePatient($this->entity, 'OUTRO-PACIENTE-ISCA', '11955554444');
});

function pdiExport(object $test): array
{
    return app(PatientDataExporter::class)->export($test->patient->fresh());
}

function pdiCourse(object $test, string $patientId, string $entityId, array $extra = []): IntravitrealCourse
{
    return IntravitrealCourse::query()->withoutGlobalScopes()->create(array_merge([
        'entity_id'             => $entityId, 'patient_id' => $patientId, 'eye' => 'right', 'drug_key' => 'aflibercept', 'drug_label' => 'Aflibercepte',
        'regimen'               => 'loading_tae', 'status' => 'active', 'started_on' => '2026-01-05', 'current_interval_weeks' => 8,
        'responsible_doctor_id' => $test->doctor->id,
    ], $extra));
}

function pdiApplication(string $courseId, string $patientId, string $entityId, int $number, string $lot): IntravitrealApplication
{
    return IntravitrealApplication::query()->withoutGlobalScopes()->create([
        'entity_id'              => $entityId, 'patient_id' => $patientId, 'course_id' => $courseId, 'sequence_no' => $number, 'phase' => 'loading', 'status' => 'performed',
        'performed_at'           => '2026-03-10 10:00:00', 'eye' => 'right', 'drug_label' => 'Aflibercepte', 'lot_number' => $lot, 'lot_expiry' => '2027-01-31',
        'decided_interval_weeks' => 8,
    ]);
}

it('convite: leva a resposta do paciente e só os convites DELE (não os de outro paciente da clínica)', function () {
    $recall = rcfOverdueRecall($this->entity, $this->patient, $this->doctor);

    $own = RecallInvite::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'wave_key' => '2026-02-01:' . $this->patient->id, 'origin' => InviteOrigin::Manual->value,
        'response'  => InviteResponse::WantsToSchedule->value, 'responded_at' => '2026-02-02 09:30:00',
    ]);
    DB::table('recall_invite_items')->insert(['recall_invite_id' => $own->id, 'patient_recall_id' => $recall->id]);

    RecallInvite::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->entity->id, 'patient_id' => $this->other->id, 'wave_key' => '2026-02-01:' . $this->other->id, 'origin' => InviteOrigin::Auto->value,
    ]);

    $invites = pdiExport($this)['recall']['whatsapp_invites'];

    expect($invites)->toHaveCount(1)
        ->and($invites[0]['response'])->toBe(__('recall_invite.response.wants_to_schedule'))
        ->and($invites[0]['responded_at'])->not->toBeNull();
});

it('condições: a linha com o patient_id do titular mas de OUTRA clínica nunca é exportada', function () {
    rcfCondition($this->entity, $this->patient, 'glaucoma', Eye::Both, '2025-05-10');

    PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id' => $this->foreignEntity->id, 'patient_id' => $this->patient->id, 'protocol_key' => 'dmri', 'eye' => Eye::Left->value,
        'status'    => 'active', 'origin' => 'manual', 'started_on' => '2025-06-01',
    ]);

    $conditions = pdiExport($this)['recall']['conditions'];

    expect($conditions)->toHaveCount(1)
        ->and($conditions[0]['protocol'])->toBe('glaucoma');
});

it('curso de injeções: só as aplicações do titular e o curso apagado (soft delete) continua no arquivo', function () {
    $course = pdiCourse($this, (string) $this->patient->id, (string) $this->entity->id);
    pdiApplication((string) $course->id, (string) $this->patient->id, (string) $this->entity->id, 1, 'LOT-DO-TITULAR');

    // Aplicação de OUTRO paciente da mesma clínica que (dado corrompido) aponta para o curso do titular.
    pdiApplication((string) $course->id, (string) $this->other->id, (string) $this->entity->id, 2, 'LOT-DE-OUTRO-ISCA');

    // Curso apagado pela clínica (soft delete): o dado continua sendo do titular e entra na exportação.
    $deleted = pdiCourse($this, (string) $this->patient->id, (string) $this->entity->id, ['eye' => 'left', 'drug_label' => 'Ranibizumabe', 'drug_key' => 'ranibizumab', 'started_on' => '2026-02-01']);
    $deleted->delete();

    $export = pdiExport($this);
    $json   = json_encode($export, JSON_UNESCAPED_UNICODE);

    expect($export['intravitreal'])->toHaveCount(2)
        ->and(collect($export['intravitreal'])->pluck('drug')->sort()->values()->all())->toBe(['Aflibercepte', 'Ranibizumabe'])
        ->and(collect($export['intravitreal'])->firstWhere('drug', 'Aflibercepte')['applications'])->toHaveCount(1)
        ->and($json)->toContain('LOT-DO-TITULAR')
        ->and($json)->not->toContain('LOT-DE-OUTRO-ISCA');
});

it('orientações: levam o hash do conteúdo e só as do titular (nem de outro paciente da clínica)', function () {
    $issue = fn (string $patientId, string $body, string $hash) => CareGuidanceIssue::query()->withoutGlobalScopes()->create([
        'entity_id'    => $this->entity->id, 'patient_id' => $patientId, 'doctor_id' => $this->doctor->id, 'title' => 'Pós-operatório', 'body' => $body,
        'content_hash' => $hash, 'issued_at' => CarbonImmutable::parse('2026-03-10 10:00:00'),
    ]);

    $issue((string) $this->patient->id, 'TEXTO-DO-TITULAR', '1:' . str_repeat('d', 64));
    $issue((string) $this->other->id, 'TEXTO-DE-OUTRO-ISCA', '1:' . str_repeat('e', 64));

    $export = pdiExport($this);
    $json   = json_encode($export, JSON_UNESCAPED_UNICODE);

    expect($export['care_guidance'])->toHaveCount(1)
        ->and($export['care_guidance'][0]['content_hash'])->toBe('1:' . str_repeat('d', 64))
        ->and($json)->not->toContain('TEXTO-DE-OUTRO-ISCA');
});

it('pronto atendimento: a entrada com o patient_id do titular mas de OUTRA clínica nunca é exportada', function () {
    $entry = fn (string $entityId, string $details) => UrgentCareEntry::query()->create([
        'entity_id' => $entityId, 'patient_id' => $this->patient->id, 'reason' => UrgentCareReason::RedEye->value, 'reason_details' => $details,
        'priority'  => UrgentCarePriority::Normal->value, 'status' => UrgentCareStatus::Waiting->value, 'arrived_at' => now(),
    ]);

    $entry((string) $this->entity->id, 'ENTRADA-DA-CLINICA-DO-TITULAR');
    $entry((string) $this->foreignEntity->id, 'ENTRADA-DE-OUTRA-CLINICA');

    $urgent = pdiExport($this)['urgent_care'];

    expect($urgent)->toHaveCount(1)
        ->and($urgent[0]['reason_details'])->toBe('ENTRADA-DA-CLINICA-DO-TITULAR');
});
