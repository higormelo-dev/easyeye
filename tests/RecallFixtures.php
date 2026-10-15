<?php

/**
 * Fixtures do recall (Fase 6, M2): clínica com protocolo ADOTADO, paciente com atendimentos e exames.
 * Funções globais (como ClinicalReviewFixtures) com prefixo `rcf`. A versão do protocolo é validada e
 * liberada pelos MESMOS serviços do produto (crfValidatedVersion + ProtocolGovernanceService).
 */

use App\Domains\ClinicalReview\Enums\AcceptanceDecision;
use App\Domains\ClinicalReview\Services\ReviewAcceptanceService;
use App\Domains\Recall\Enums\{ConditionOrigin, ConditionStatus};
use App\Domains\Recall\Models\{PatientCondition, PatientRecall, RecallClinicProtocol, RecallClinicSettings, RecallExamMapping, RecallProtocolVersion};
use App\Domains\Recall\Services\{ProtocolCatalogService, ProtocolGovernanceService, RecallEvaluator};
use App\Domains\Recall\Support\ProtocolContentSchema;
use App\Enums\ClinicalSafety\{MedicationSource, MedicationStatus};
use App\Enums\{ConsentType, Eye, FeatureKey, LegalBasis, SubscriptionStatus};
use App\Models\{Covenant, Doctor, Entity, ExamType, MedicalRecord, Patient, PatientConsent, PatientExam, PatientMedication, People, Plan, PlanFeature, Schedule, Subscription};
use App\Models\WhatsApp\WhatsAppSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\{Collection, Str};

/**
 * Clínica de teste SEM trial automático (a assinatura é a que o teste monta); `$recall` liga a feature do recall.
 *
 * @return array{entity: Entity, doctor: Doctor}
 */
function rcfClinic(bool $recall = false): array
{
    $entity                = Entity::factory()->make(['is_client' => true, 'active' => true]);
    $entity->skipAutoTrial = true;
    $entity->save();

    if ($recall) {
        rcfGrantRecall($entity);
    }

    return ['entity' => $entity, 'doctor' => createDoctorForEntity($entity)];
}

/** Assinatura ativa num plano COM a feature `has_patient_recall` (ou sem, com `$enabled = false`). */
function rcfGrantRecall(Entity $entity, bool $enabled = true): void
{
    $plan = Plan::factory()->create(['active' => true]);

    if ($enabled) {
        PlanFeature::factory()->enabled(FeatureKey::HasPatientRecall)->for($plan)->create();
    }

    Subscription::factory()->create([
        'entity_id' => $entity->id,
        'plan_id'   => $plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);
}

/** @param array<string, mixed> $overrides */
function rcfPatient(Entity $entity, array $overrides = []): Patient
{
    return Patient::create(array_merge([
        'entity_id'   => $entity->id,
        'person_id'   => People::factory()->create()->id,
        'covenant_id' => Covenant::factory()->create()->id,
        'active'      => true,
    ], $overrides));
}

/** A versão LIBERADA do protocolo (validada de verdade e liberada); reaproveita a que já existe na suíte. */
function rcfReleased(string $key = 'glaucoma'): RecallProtocolVersion
{
    $existing = RecallProtocolVersion::query()
        ->where('state', 'released')
        ->whereHas('protocol', fn ($query) => $query->where('key', $key))
        ->first();

    return $existing ?? app(ProtocolGovernanceService::class)->release(crfValidatedVersion($key)['version']);
}

/**
 * Uma 2ª versão do protocolo, VALIDADA de verdade e LIBERADA (a 1ª, que estava liberada, vira "substituída"):
 * campimetria a cada 9 meses. Mesmos serviços do produto (revisão, parecer, aceite, liberação).
 */
function rcfReleaseSecond(RecallProtocolVersion $first): RecallProtocolVersion
{
    $catalog = app(ProtocolCatalogService::class);
    $second  = $catalog->startRevision($first->protocol);

    $content                                    = $second->contentArray();
    $content['change_note']                     = 'Campimetria a cada 9 meses.';
    $content['tasks'][1]['interval']['default'] = 9;
    $catalog->saveDraft($second, $content, 0);
    $second = $catalog->freeze($second->fresh());

    $created = crfInvitation(null, [$second]);
    crfSubmit($created);
    app(ReviewAcceptanceService::class)->record($created['invitation']->fresh(), AcceptanceDecision::Accepted, true, true, null);

    return app(ProtocolGovernanceService::class)->release($second->fresh());
}

/**
 * A clínica ADOTA a versão: tarefas obrigatórias ligadas no intervalo padrão; `$tasks` sobrescreve
 * (`['campimetria' => ['interval' => 6], 'oct_nervo_optico' => ['enabled' => true]]`).
 *
 * @param array<string, array<string, mixed>> $tasks
 */
function rcfAdopt(Entity $entity, string $key = 'glaucoma', array $tasks = [], ?RecallProtocolVersion $version = null): RecallClinicProtocol
{
    $version ??= rcfReleased($key);
    $content = ProtocolContentSchema::normalize($version->contentArray());

    $parameters = ['tasks' => []];

    foreach ($content['tasks'] as $task) {
        $parameters['tasks'][$task['key']] = ['interval' => (int) $task['interval']['default'], 'enabled' => (bool) ($task['required'] ?? false)];
    }

    foreach ($tasks as $taskKey => $override) {
        $parameters['tasks'][$taskKey] = array_merge($parameters['tasks'][$taskKey] ?? [], $override);
    }

    return RecallClinicProtocol::query()->withoutGlobalScopes()->create([
        'entity_id'            => $entity->id,
        'protocol_id'          => $version->protocol_id,
        'version_id'           => $version->id,
        'adopted_content_hash' => $version->content_hash,
        'parameters'           => $parameters,
        'activated_at'         => now(),
    ]);
}

function rcfCondition(
    Entity $entity,
    Patient $patient,
    string $key = 'glaucoma',
    Eye $eye = Eye::Both,
    ?string $startedOn = '2026-01-15',
    ConditionStatus $status = ConditionStatus::Active,
): PatientCondition {
    return PatientCondition::query()->withoutGlobalScopes()->create([
        'entity_id'    => $entity->id,
        'patient_id'   => $patient->id,
        'protocol_key' => $key,
        'eye'          => $eye->value,
        'status'       => $status->value,
        'origin'       => ConditionOrigin::Manual->value,
        'started_on'   => $startedOn,
    ]);
}

/**
 * Atendimento do paciente na data (e o retorno que o médico pediu, em dias). Sem eventos: só o dado. `$cids` são
 * códigos (com ou sem ponto) gravados em `diagnosis_cids`; `$signed` assina de mentira (signed_at + trava).
 *
 * @param list<string> $cids
 */
function rcfRecord(Entity $entity, Patient $patient, Doctor $doctor, string $date, ?int $followUpDays = null, ?Schedule $schedule = null, array $cids = [], bool $signed = false): MedicalRecord
{
    $record = MedicalRecord::create([
        'entity_id'      => $entity->id,
        'patient_id'     => $patient->id,
        'doctor_id'      => $doctor->id,
        'main_complaint' => 'Consulta',
        'schedule_id'    => $schedule?->id,
    ]);

    $attributes = ['follow_up_days' => $followUpDays, 'created_at' => "{$date} 10:00:00", 'updated_at' => "{$date} 10:00:00"];

    if ($cids !== []) {
        $attributes['diagnosis_cids'] = array_map(static fn (string $code): array => ['code' => $code, 'description' => 'Diagnóstico de teste'], $cids);
    }

    if ($signed) {
        $attributes += ['signed_at' => "{$date} 11:00:00", 'is_locked' => true];
    }

    $record->forceFill($attributes)->saveQuietly();

    return $record;
}

/** Medicação do paciente (princípio ativo no nome livre); `$confirmed` = o médico confirmou o uso. */
function rcfMedication(Entity $entity, Patient $patient, string $name, ?string $startedOn = '2025-01-10', bool $confirmed = true, MedicationStatus $status = MedicationStatus::Active): PatientMedication
{
    return PatientMedication::create([
        'entity_id'    => $entity->id,
        'patient_id'   => $patient->id,
        'name'         => $name,
        'started_on'   => $startedOn,
        'status'       => $status,
        'source'       => MedicationSource::Clinic,
        'confirmed_at' => $confirmed ? now() : null,
    ]);
}

function rcfExamType(string $name = 'Campimetria computadorizada'): ExamType
{
    return ExamType::factory()->create(['entity_id' => null, 'name' => $name]);
}

function rcfMapExam(Entity $entity, string $examKey, ExamType $type): RecallExamMapping
{
    return RecallExamMapping::query()->withoutGlobalScopes()->create(['entity_id' => $entity->id, 'exam_key' => $examKey, 'exam_type_id' => $type->id]);
}

/** Exame do paciente (lateralidade como no banco: 1 = OD, 2 = OE, 0/nulo = AO). */
function rcfExam(Patient $patient, ExamType $type, string $date, ?int $laterality = 0, bool $active = true): PatientExam
{
    return PatientExam::factory()->create([
        'patient_id'        => $patient->id,
        'exam_id'           => $type->id,
        'laterality'        => $laterality,
        'exam_performed_at' => "{$date} 09:00:00",
        'active'            => $active,
    ]);
}

/**
 * Reavalia o paciente na data (o relógio é um parâmetro: os testes não dependem de "hoje").
 *
 * @return array{created: int, updated: int, closed: int, unchanged: int}
 */
function rcfEvaluate(Entity $entity, Patient $patient, string $today = '2026-02-01'): array
{
    return app(RecallEvaluator::class)->evaluatePatient((string) $entity->id, (string) $patient->id, CarbonImmutable::parse($today));
}

/** As linhas de retorno do paciente, por tarefa (`doctor_order`, `consulta`, `campimetria`...). */
function rcfRecalls(Patient $patient): Collection
{
    return PatientRecall::query()->withoutGlobalScopes()->where('patient_id', $patient->id)->get()->keyBy('task_key');
}

// ── convite por WhatsApp (S11) ───────────────────────────────────────────────

/**
 * Liga o canal de convite da clínica nos três níveis (Manager: `recall_enabled`; clínica: `whatsapp_invites_enabled`;
 * pausa global liberada) com um app Gupshup de teste (driver mock: nada sai de verdade). Janela de horário aberta o
 * dia todo, todos os dias, e tetos folgados — o teste fecha o que quer exercitar. `$settings` sobrescreve as
 * configurações do recall da clínica; `$whatsapp`, as do WhatsApp.
 *
 * @param array<string, mixed> $settings
 * @param array<string, mixed> $whatsapp
 */
function rcfInviteChannel(Entity $entity, array $settings = [], array $whatsapp = []): WhatsAppSetting
{
    config()->set('whatsapp.driver', 'mock');

    $setting = WhatsAppSetting::create(array_merge([
        'entity_id'            => $entity->id,
        'app_id'               => 'recall-app-' . Str::random(8),
        'webhook_token'        => Str::random(48),
        'webhook_secret'       => 'segredo-do-webhook-do-recall',
        'active'               => true,
        'confirmation_enabled' => false,
        'survey_enabled'       => false,
        'recall_enabled'       => true,
    ], $whatsapp));

    RecallClinicSettings::query()->withoutGlobalScopes()->updateOrCreate(['entity_id' => $entity->id], array_merge([
        'doctor_order_enabled'         => true,
        'whatsapp_invites_enabled'     => true,
        'invite_window_start'          => '00:00',
        'invite_window_end'            => '23:59',
        'invite_weekdays'              => [1, 2, 3, 4, 5, 6, 7],
        'min_days_between_invites'     => 30,
        'max_auto_invites_per_cycle'   => 3,
        'daily_invite_cap'             => 100,
        'ramp_started_on'              => now()->subDays(60)->toDateString(),
        'auto_invite_max_overdue_days' => 180,
    ], $settings));

    return $setting;
}

/** Paciente com celular móvel e a marca de WhatsApp; `$consent` registra a autorização de lembretes de retorno. */
function rcfInvitePatient(Entity $entity, string $name = 'MARIA DA SILVA', string $cellphone = '11988887777', bool $consent = true, bool $whatsapp = true): Patient
{
    $patient = rcfPatient($entity, ['person_id' => People::factory()->create(['full_name' => $name, 'cellphone' => $cellphone, 'whatsapp' => $whatsapp, 'birth_date' => '1950-03-10'])->id]);

    if ($consent) {
        rcfConsent($entity, $patient);
    }

    return $patient;
}

/** A autorização de lembretes de retorno por WhatsApp, vigente. */
function rcfConsent(Entity $entity, Patient $patient): PatientConsent
{
    return PatientConsent::query()->withoutGlobalScopes()->create([
        'entity_id'    => $entity->id,
        'patient_id'   => $patient->id,
        'consent_type' => ConsentType::CareReminders,
        'legal_basis'  => LegalBasis::Consent,
        'status'       => 'granted',
        'channel'      => 'in_person',
        'granted_at'   => now(),
    ]);
}

/**
 * Retorno vencido do paciente (pedido pelo médico em 30 dias, atendimento em 01/12/2025: vence em 31/12/2025, e em
 * 01/02/2026 está em atraso, dentro da janela de convite). Devolve a linha de retorno.
 */
function rcfOverdueRecall(Entity $entity, Patient $patient, Doctor $doctor, string $today = '2026-02-01'): PatientRecall
{
    rcfRecord($entity, $patient, $doctor, '2025-12-01', 30);
    rcfEvaluate($entity, $patient, $today);

    return rcfRecalls($patient)->firstOrFail();
}
