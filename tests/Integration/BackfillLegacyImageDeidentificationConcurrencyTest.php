<?php

declare(strict_types=1);

/**
 * Backfill 2026_10_21_000200 (image_deidentification = 'legacy_unknown') sob
 * concorrência REAL (duas conexões PostgreSQL) — achado via revisão adversarial
 * Codex: as duas travas FOR UPDATE e o UPDATE final calculavam, cada uma, o seu
 * próprio conjunto de runs candidatos, cada qual com o seu snapshot (READ
 * COMMITTED). Um run criado e comitado DEPOIS da trava mas ANTES do UPDATE
 * entrava no UPDATE sem ter uma única linha travada: o worker dele gravava o
 * outcome real da foto depois do snapshot do UPDATE e a linha do vídeo (nunca
 * tentada, null de propósito) virava 'legacy_unknown' — contando como
 * "analisada" e saindo dos pendentes de laudo.
 *
 * A "outra conexão" é uma SEGUNDA sessão PostgreSQL de verdade que comita sem
 * a transação da migration; o intervalo entre as instruções da migration é
 * ocupado por ela via DB::listen (roda síncrono, logo depois da instrução).
 * Por isso este arquivo fica em tests/Integration (DatabaseMigrations, sem
 * transação de wrap) e roda por caminho explícito.
 */

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;
use App\Models\{Entity, Patient, PatientExam, User};
use Illuminate\Database\{Connection, QueryException};
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, DatabaseMigrations::class);

const BFC_PROBE_CONNECTION = 'pgsql_probe_backfill';

/** Segunda sessão PostgreSQL real: o que ela grava já está commitado. */
function bfcProbe(): Connection
{
    config(['database.connections.' . BFC_PROBE_CONNECTION => config('database.connections.' . config('database.default'))]);

    return DB::connection(BFC_PROBE_CONNECTION);
}

/**
 * Cenário: 1 run LEGADO (linha null, de antes da regra) já existe quando a
 * migration começa; 1 run NOVO (foto + vídeo, os dois null — recém-criado, o
 * worker ainda não rodou) é criado pela OUTRA conexão no meio da migration.
 *
 * @return array{legacyExam: PatientExam, photo: PatientExam, video: PatientExam, legacyRun: AiRun, createNewRun: Closure(): AiRun}
 */
function bfcScenario(): array
{
    $entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $patient = Patient::factory()->create(['entity_id' => $entity->id]);
    $user    = User::factory()->create();

    $attributes = [
        'entity_id'         => $entity->id, 'patient_id' => $patient->id, 'requested_by' => $user->id,
        'workflow'          => 'eye_image_analysis', 'risk_level' => 'medium',
        'estimated_credits' => 0, 'reserved_credits' => 0, 'status' => AiRunStatus::WaitingApproval->value,
    ];

    $legacyExam = PatientExam::factory()->create(['patient_id' => $patient->id]);
    $photo      = PatientExam::factory()->create(['patient_id' => $patient->id]);
    $video      = PatientExam::factory()->create(['patient_id' => $patient->id]);

    $legacyRun = AiRun::factory()->economy()->create($attributes);
    DB::table('ai_run_patient_exam')->insert([
        'ai_run_id'              => $legacyRun->id, 'patient_exam_id' => $legacyExam->id, 'entity_id' => $entity->id,
        'image_deidentification' => null, 'created_at' => now()->subDays(30),
    ]);

    // Linha pronta pra INSERT cru na outra conexão (sem Eloquent: nada de
    // auditoria/evento escrevendo de volta na conexão principal).
    $newRunRow = array_merge(
        AiRun::factory()->economy()->make($attributes)->getAttributes(),
        ['id' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()],
    );

    // Como o código real cria: o run e os vínculos com os exames, ATOMICAMENTE.
    $createNewRun = function () use ($newRunRow, $photo, $video, $entity): AiRun {
        $probe = bfcProbe();
        $probe->transaction(function () use ($probe, $newRunRow, $photo, $video, $entity): void {
            $probe->table('ai_runs')->insert($newRunRow);
            $probe->table('ai_run_patient_exam')->insert([
                ['ai_run_id' => $newRunRow['id'], 'patient_exam_id' => $photo->id, 'entity_id' => $entity->id, 'image_deidentification' => null, 'created_at' => now()],
                ['ai_run_id' => $newRunRow['id'], 'patient_exam_id' => $video->id, 'entity_id' => $entity->id, 'image_deidentification' => null, 'created_at' => now()],
            ]);
        });

        return AiRun::query()->withoutGlobalScopes()->findOrFail($newRunRow['id']);
    };

    return compact('legacyExam', 'photo', 'video', 'legacyRun', 'createNewRun');
}

function bfcOutcome(PatientExam $exam): ?string
{
    return DB::table('ai_run_patient_exam')->where('patient_exam_id', $exam->id)->value('image_deidentification');
}

/** O que o migrator faz: o up() inteiro numa única transação. */
function bfcRunMigration(): void
{
    $migration = require database_path('migrations/2026_10_21_000200_backfill_legacy_image_deidentification.php');

    DB::transaction(fn () => $migration->up());
}

afterEach(function () {
    DB::purge(BFC_PROBE_CONNECTION);
});

it('[GAP] run criado e comitado por OUTRA conexão depois que a migration escolheu os candidatos nunca é tocado — vídeo continua null e não vira laudado (achado via revisão adversarial Codex)', function () {
    ['legacyExam' => $legacyExam, 'photo' => $photo, 'video' => $video, 'createNewRun' => $createNewRun] = bfcScenario();

    // A outra sessão cria o run NOVO logo depois da 1ª instrução que escolhe
    // runs candidatos (a que lista "runs com linha null") — e antes das
    // travas e do UPDATE terminarem.
    $newRun = null;
    DB::listen(function (QueryExecuted $query) use (&$newRun, $createNewRun): void {
        if ($newRun === null && str_contains(strtolower($query->sql), 'select distinct')) {
            $newRun = $createNewRun();
        }
    });

    bfcRunMigration();

    expect($newRun)->not->toBeNull()
        // O legado continua protegido...
        ->and(bfcOutcome($legacyExam))->toBe('legacy_unknown')
        // ...e o run novo, que a migration nunca viu, fica como nasceu: pendente.
        ->and(bfcOutcome($photo))->toBeNull()
        ->and(bfcOutcome($video))->toBeNull();

    // O worker do run novo roda DEPOIS (resolveImages: zera a seleção e grava
    // só o outcome da foto — o vídeo não tem imagem pra enviar). Aprovado o
    // run, só a foto conta como laudada; o vídeo segue pendente.
    DB::transaction(function () use ($newRun, $photo, $video): void {
        DB::table('ai_run_patient_exam')->where('ai_run_id', $newRun->id)->whereIn('patient_exam_id', [$photo->id, $video->id])
            ->update(['image_deidentification' => null]);
        DB::table('ai_run_patient_exam')->where('ai_run_id', $newRun->id)->where('patient_exam_id', $photo->id)
            ->update(['image_deidentification' => 'oculus_pentacam_panel_a_pt']);
    });
    DB::table('ai_runs')->where('id', $newRun->id)->update(['status' => AiRunStatus::Approved->value]);

    $entityId = $newRun->entity_id;
    $reported = PatientExam::query()->reported($entityId)->pluck('patient_exams.id')->all();
    $pending  = PatientExam::query()->pendingReport($entityId)->pluck('patient_exams.id')->all();

    expect($reported)->toContain($photo->id)->not->toContain($video->id)
        ->and($pending)->toContain($video->id);
});

it('as linhas do conjunto escolhido ficam travadas até o fim da migration (o worker espera); as de um run criado depois não', function () {
    ['legacyRun' => $legacyRun, 'photo' => $photo, 'createNewRun' => $createNewRun] = bfcScenario();

    $newRun          = null;
    $legacyBlocked   = null;
    $newRunNotLocked = null;

    DB::listen(function (QueryExecuted $query) use (&$newRun, &$legacyBlocked, &$newRunNotLocked, $createNewRun, $legacyRun, $photo): void {
        $sql = strtolower($query->sql);

        // Logo depois da 2ª trava (ai_runs): todas as travas do conjunto já estão tomadas.
        if ($legacyBlocked !== null || ! (str_contains($sql, 'ai_runs') && str_contains($sql, 'for update'))) {
            return;
        }

        $newRun = $createNewRun();
        $probe  = bfcProbe();
        $probe->statement("SET lock_timeout = '300ms'");

        // O worker do run LEGADO (já no conjunto) tenta gravar: espera a trava.
        try {
            $probe->table('ai_run_patient_exam')->where('ai_run_id', $legacyRun->id)->update(['image_deidentification' => null]);
            $legacyBlocked = false;
        } catch (QueryException $e) {
            // "55P03" = lock_not_available — a mensagem do servidor vem no idioma da sessão.
            $legacyBlocked = str_contains($e->getMessage(), '55P03');
        }

        // O worker do run NOVO (fora do conjunto) grava sem esperar ninguém.
        try {
            $probe->table('ai_run_patient_exam')->where('ai_run_id', $newRun->id)->where('patient_exam_id', $photo->id)
                ->update(['image_deidentification' => 'oculus_pentacam_panel_a_pt']);
            $newRunNotLocked = true;
        } catch (QueryException) {
            $newRunNotLocked = false;
        }

        $probe->statement('SET lock_timeout = DEFAULT');
    });

    bfcRunMigration();

    expect($legacyBlocked)->toBeTrue()
        ->and($newRunNotLocked)->toBeTrue()
        // O outcome real que o worker do run novo gravou durante a migration fica.
        ->and(bfcOutcome($photo))->toBe('oculus_pentacam_panel_a_pt');
});
