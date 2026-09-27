<?php

declare(strict_types=1);

use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\AiCreditWalletService;
use App\Enums\AI\{AiRiskLevel, AiRunMode, AiRunStatus};
use App\Enums\{ClientRule, FeatureKey, SubscriptionStatus};
use App\Models\{Doctor, Entity, MedicalRecord, MedicalRecordDocumentation, Patient, PatientExam, People, Plan, PlanFeature, Subscription, User};
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Acha o prontuário da consulta ou abre um" (laudo manual de imagem e
 * prontuário do laudo de IA) — antes: findRecord -> create sem lock, então
 * dois fluxos simultâneos para o mesmo paciente abriam DOIS prontuários da
 * mesma consulta; e o "abrir prontuário" do laudo de IA nem relia se outro
 * fluxo já tinha aberto o prontuário da consulta depois da aprovação.
 *
 * Contenção real provada com uma SEGUNDA sessão PostgreSQL segurando o lock
 * de abertura do paciente + lock_timeout curto na sessão do teste.
 */
const MROL_PROBE_CONNECTION = 'pgsql_lock_probe_opening';

beforeEach(function (): void {
    $this->entity = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->plan   = Plan::factory()->create(['active' => true]);

    PlanFeature::factory()->enabled(FeatureKey::HasAiEyeImageAnalysis)->for($this->plan)->create();
    PlanFeature::factory()->enabled(FeatureKey::HasAiExamAssistant)->for($this->plan)->create();
    PlanFeature::factory()->limit(FeatureKey::AiMonthlyCredits, 1000)->for($this->plan)->create();

    Subscription::factory()->create([
        'entity_id' => $this->entity->id,
        'plan_id'   => $this->plan->id,
        'status'    => SubscriptionStatus::Active,
        'starts_at' => now()->subDay(),
        'ends_at'   => now()->addMonth(),
    ]);

    $this->doctorUser       = User::factory()->create();
    $this->doctorEntityUser = createEntityUser($this->entity, $this->doctorUser, ClientRule::Doctor->value);
    $this->doctor           = Doctor::query()->create([
        'entity_user_id' => $this->doctorEntityUser->id,
        'person_id'      => People::factory()->create()->id,
        'active'         => true,
    ]);

    app(AiCreditWalletService::class)->purchaseCredits(
        entityId: $this->entity->id,
        amount: 200,
        description: 'Créditos de teste',
    );

    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);

    ['schedule' => $this->schedule] = createScheduleForEntity($this->entity, [
        'patient_id' => $this->patient->id,
        'date_time'  => now(),
    ]);
    $this->exam = PatientExam::factory()->create([
        'patient_id'  => $this->patient->id,
        'schedule_id' => $this->schedule->id,
        'laterality'  => 1,
    ]);
});

function mrolAsDoctor($test)
{
    return $test->actingAs($test->doctorUser)->withSession(panelSession($test->doctorEntityUser));
}

/** @return array<string, mixed> */
function mrolReportPayload($test): array
{
    return [
        'patient_id'          => $test->patient->id,
        'exam_ids'            => [$test->exam->id],
        'content'             => '<p>Achados normais.</p>',
        'confirm_open_record' => true,
    ];
}

function mrolWaitingEyeRun($test): AiRun
{
    $run = AiRun::query()->create([
        'entity_id'         => $test->entity->id,
        'patient_id'        => $test->patient->id,
        'requested_by'      => $test->doctorUser->id,
        'workflow'          => 'eye_image_analysis',
        'mode'              => AiRunMode::Economy->value,
        'risk_level'        => AiRiskLevel::Medium->value,
        'status'            => AiRunStatus::WaitingApproval->value,
        'estimated_credits' => 10,
        'reserved_credits'  => 10,
        'consumed_credits'  => 8,
        'final_output'      => 'Achados compatíveis com retinopatia leve.',
        'input_summary'     => ['exam_ids' => [(string) $test->exam->id]],
    ]);

    DB::table('ai_run_patient_exam')->insert([
        'ai_run_id'       => $run->id,
        'patient_exam_id' => $test->exam->id,
        'entity_id'       => $test->entity->id,
        'created_at'      => now(),
    ]);

    return $run;
}

/** Run aprovado SEM prontuário do dia (o front pediria confirmação para abrir). */
function mrolApprovedRunWithoutRecord($test): AiRun
{
    $run = mrolWaitingEyeRun($test);

    mrolAsDoctor($test)
        ->postJson(route('panel.ai-runs.approve', $run), ['final_output' => $run->final_output])
        ->assertOk()
        ->assertJsonPath('requires_record_confirmation', true);

    return $run;
}

/** Prontuário da consulta aberto por OUTRO fluxo (manual / outra aba). */
function mrolExistingConsultationRecord($test): MedicalRecord
{
    return MedicalRecord::query()->create([
        'entity_id'   => $test->entity->id,
        'patient_id'  => $test->patient->id,
        'doctor_id'   => $test->doctor->id,
        'schedule_id' => $test->schedule->id,
    ]);
}

/** Segunda sessão PostgreSQL segurando o lock de abertura de prontuário do paciente. */
function mrolHoldOpeningLock($test): Connection
{
    config(['database.connections.' . MROL_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    $key = (new ReflectionMethod(MedicalRecord::class, 'recordOpeningLockKey'))
        ->invoke(null, (string) $test->entity->id, (string) $test->patient->id);

    $probe = DB::connection(MROL_PROBE_CONNECTION);
    $probe->beginTransaction();
    $probe->select('select pg_advisory_xact_lock(?)', [$key]);

    DB::statement("set local lock_timeout = '300ms'");

    return $probe;
}

function mrolReleaseProbe(Connection $probe): void
{
    if ($probe->transactionLevel() > 0) {
        $probe->rollBack();
    }

    DB::purge(MROL_PROBE_CONNECTION);
}

function mrolRecordsOfPatient($test): int
{
    return MedicalRecord::query()->withoutGlobalScopes()->where('patient_id', $test->patient->id)->count();
}

describe('laudo manual de imagem (EyeImageReportController::store)', function (): void {
    it('relê sob lock: prontuário aberto por outro fluxo logo após a busca sem lock é reaproveitado, não duplicado', function (): void {
        // Simula a OUTRA requisição confirmando a abertura exatamente entre
        // a busca sem lock (que não achou nada) e a criação.
        $injected = null;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            $sql = strtolower($query->sql);

            if ($injected !== null || ! str_contains($sql, 'from "medical_records"') || ! str_contains($sql, '::date')) {
                return;
            }

            $injected = (string) Str::uuid();
            DB::table('medical_records')->insert([
                'id'          => $injected,
                'entity_id'   => $this->entity->id,
                'patient_id'  => $this->patient->id,
                'doctor_id'   => $this->doctor->id,
                'schedule_id' => $this->schedule->id,
                'code'        => 'PMR-0000000001',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        });

        $response = mrolAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), mrolReportPayload($this));

        $response->assertCreated()->assertJsonPath('medical_record_id', $injected);
        expect(mrolRecordsOfPatient($this))->toBe(1)
            ->and(MedicalRecordDocumentation::query()->where('medical_record_id', $injected)->count())->toBe(1);
    });

    it('abrir prontuário espera o lock de abertura do paciente (outra sessão no meio da abertura)', function (): void {
        $probe = mrolHoldOpeningLock($this);

        try {
            $this->withoutExceptionHandling();

            expect(fn () => mrolAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), mrolReportPayload($this)))
                ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));
        } finally {
            mrolReleaseProbe($probe);
        }

        expect(mrolRecordsOfPatient($this))->toBe(0)
            ->and(MedicalRecordDocumentation::query()->count())->toBe(0);
    });

    it('com prontuário da consulta já aberto, grava o laudo sem disputar o lock de abertura', function (): void {
        $existing = mrolExistingConsultationRecord($this);
        $probe    = mrolHoldOpeningLock($this);

        try {
            mrolAsDoctor($this)->postJson(route('panel.eye-images.reports.store'), mrolReportPayload($this))
                ->assertCreated()
                ->assertJsonPath('medical_record_id', $existing->id);
        } finally {
            mrolReleaseProbe($probe);
        }

        expect(mrolRecordsOfPatient($this))->toBe(1);
    });
});

describe('prontuário do laudo de IA (AiRunsController::openRecordForRun)', function (): void {
    it('reaproveita o prontuário da consulta aberto por outro fluxo depois da aprovação (antes abria um segundo)', function (): void {
        $run      = mrolApprovedRunWithoutRecord($this);
        $existing = mrolExistingConsultationRecord($this);

        mrolAsDoctor($this)->postJson(route('panel.ai-runs.record', $run))
            ->assertOk()
            ->assertJsonPath('message', __('ai.record_opened'));

        expect(mrolRecordsOfPatient($this))->toBe(1)
            ->and(MedicalRecordDocumentation::query()->where('ai_run_id', $run->id)->value('medical_record_id'))->toBe($existing->id);
    });

    it('sem prontuário da consulta, abre um só e o segundo clique é idempotente', function (): void {
        $run = mrolApprovedRunWithoutRecord($this);

        mrolAsDoctor($this)->postJson(route('panel.ai-runs.record', $run))->assertOk();
        mrolAsDoctor($this)->postJson(route('panel.ai-runs.record', $run))->assertOk();

        $record = MedicalRecord::query()->withoutGlobalScopes()->where('patient_id', $this->patient->id)->firstOrFail();

        expect(mrolRecordsOfPatient($this))->toBe(1)
            ->and($record->schedule_id)->toBe((string) $this->schedule->id)
            ->and(MedicalRecordDocumentation::query()->where('ai_run_id', $run->id)->count())->toBe(1);
    });

    it('abrir prontuário espera o lock de abertura do paciente (outra sessão no meio da abertura)', function (): void {
        $run   = mrolApprovedRunWithoutRecord($this);
        $probe = mrolHoldOpeningLock($this);

        try {
            $this->withoutExceptionHandling();

            expect(fn () => mrolAsDoctor($this)->postJson(route('panel.ai-runs.record', $run)))
                ->toThrow(fn (QueryException $e) => expect($e->getCode())->toBe('55P03'));
        } finally {
            mrolReleaseProbe($probe);
        }

        expect(mrolRecordsOfPatient($this))->toBe(0)
            ->and(MedicalRecordDocumentation::query()->where('ai_run_id', $run->id)->exists())->toBeFalse();
    });
});
