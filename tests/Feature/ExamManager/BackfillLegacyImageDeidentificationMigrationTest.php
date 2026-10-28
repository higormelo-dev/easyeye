<?php

/*
 * Migration 2026_10_21_000200: 2026_10_03_200000 documenta null em
 * image_deidentification com DOIS significados ("execução anterior a esta
 * regra" OU "ainda não executada") — PatientExam::reportedConstraint() e
 * AiRunDocumentationService::writeRunDocumentation() (Fase 5 / revisão
 * adversarial Codex, rodada 2) exigem NOT NULL pra contar como "analisado",
 * correto só pro 2º significado. O backfill marca só as linhas DE FATO
 * anteriores ao corte com um sentinela, preservando o laudo aprovado
 * histórico sem reabrir a checagem pra runs novos (achado na 3ª rodada de
 * revisão adversarial Codex).
 *
 * O corte é o PRÓPRIO INSTANTE em que a migration roda (nunca a data do
 * nome do arquivo — achado na 6ª rodada de revisão adversarial Codex; nem
 * "o primeiro outcome real já gravado" — achado no /codex:review seguinte:
 * uma base com legado de verdade mas SEM nenhum outcome real ainda gravado
 * ficava sem backfill nenhum). Nos testes, isso significa: linha "legada"
 * = criada ANTES de chamar migration->up(); linha "nova" = criada DEPOIS.
 */

use App\Domains\AI\Models\AiRun;
use App\Enums\AI\AiRunStatus;
use App\Models\{Entity, Patient, PatientExam};
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_10_21_000200_backfill_legacy_image_deidentification.php');

    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
});

function backfillPivotRow($test, ?string $outcome, string $createdAt): array
{
    $exam = PatientExam::factory()->create(['patient_id' => $test->patient->id]);
    $run  = AiRun::factory()->economy()->create([
        'entity_id'         => $test->entity->id, 'patient_id' => $test->patient->id,
        'workflow'          => 'eye_image_analysis', 'risk_level' => 'medium',
        'estimated_credits' => 0, 'reserved_credits' => 0, 'status' => AiRunStatus::Approved->value,
    ]);
    DB::table('ai_run_patient_exam')->insert([
        'ai_run_id'              => $run->id, 'patient_exam_id' => $exam->id, 'entity_id' => $test->entity->id,
        'image_deidentification' => $outcome, 'created_at' => $createdAt,
    ]);

    return [$run, $exam];
}

it('backfill marca com sentinela só linha NULL anterior ao instante da migration; linha nova (criada DEPOIS) continua intocada mesmo sem nenhum outcome real já gravado — achado no /codex:review', function () {
    // Nenhuma linha com outcome real em lugar nenhum — representa uma base
    // que só tem legado (deploy da coluna/backfill aconteceu ANTES de
    // qualquer execução do código novo de análise). Sem isto o backfill
    // antigo (corte empírico) não marcava NADA, mesmo havendo legado de
    // verdade pra proteger.
    [, $legacyNull]       = backfillPivotRow($this, null, now()->subDays(30));
    [, $legacyWithLayout] = backfillPivotRow($this, 'oculus_pentacam_panel_a_pt', now()->subDays(30)); // nunca sobrescreve outcome real
    [, $legacySkipped]    = backfillPivotRow($this, 'unrecognized_layout', now()->subDays(30)); // nunca sobrescreve skip

    $this->migration->up();

    // SÓ DEPOIS do "deploy" (up() acima) um run NOVO é criado — nunca pode
    // ser visto por esta migration (já rodou), continua null de propósito
    // até resolveImages() rodar de verdade.
    [, $newNull] = backfillPivotRow($this, null, now());

    $outcome = fn (PatientExam $exam) => DB::table('ai_run_patient_exam')->where('patient_exam_id', $exam->id)->value('image_deidentification');

    expect($outcome($legacyNull))->toBe('legacy_unknown')
        ->and($outcome($legacyWithLayout))->toBe('oculus_pentacam_panel_a_pt')
        ->and($outcome($legacySkipped))->toBe('unrecognized_layout')
        ->and($outcome($newNull))->toBeNull();
});

it('[GAP] run com outcome misto (uma linha real, outra ainda null) preserva a linha null intocada — não é legado, achado na 8ª rodada de revisão adversarial Codex', function () {
    // Mesmo run, duas linhas, mesmo created_at (nasceram juntas na criação
    // do run) — exatamente o caso que um corte por INSTANTE não distingue
    // (as duas são "anteriores ao corte"), mas que a exclusão por RUN
    // resolve: este run tem uma linha não-null, prova que resolveImages()
    // já rodou pra ele com o código novo — a outra linha null é "nunca
    // tentada" (ex. vídeo), nunca legado.
    $examAnalyzed = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    $examPending  = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    $run          = AiRun::factory()->economy()->create([
        'entity_id'         => $this->entity->id, 'patient_id' => $this->patient->id,
        'workflow'          => 'eye_image_analysis', 'risk_level' => 'medium',
        'estimated_credits' => 0, 'reserved_credits' => 0, 'status' => AiRunStatus::WaitingApproval->value,
    ]);
    // Timestamp claramente no passado (não "now()" puro) pra não depender
    // de precisão de segundo coincidir com o instante da migration — o que
    // está em teste é a exclusão por RUN, não uma corrida de relógio.
    $createdTogetherAt = now()->subMinute();
    DB::table('ai_run_patient_exam')->insert([
        ['ai_run_id' => $run->id, 'patient_exam_id' => $examAnalyzed->id, 'entity_id' => $this->entity->id, 'image_deidentification' => 'oculus_pentacam_panel_a_pt', 'created_at' => $createdTogetherAt],
        ['ai_run_id' => $run->id, 'patient_exam_id' => $examPending->id, 'entity_id' => $this->entity->id, 'image_deidentification' => null, 'created_at' => $createdTogetherAt],
    ]);

    $this->migration->up();

    $outcome = fn (PatientExam $exam) => DB::table('ai_run_patient_exam')->where('patient_exam_id', $exam->id)->value('image_deidentification');

    expect($outcome($examAnalyzed))->toBe('oculus_pentacam_panel_a_pt')
        ->and($outcome($examPending))->toBeNull();
});

it('[GAP] captura os runs candidatos UMA vez e só ele entra nas 2 travas (FOR UPDATE) e no UPDATE — nenhuma instrução recalcula o conjunto (achado em nova rodada de revisão adversarial Codex + 11ª rodada)', function () {
    backfillPivotRow($this, null, now()->subDays(30));

    $log = [];
    DB::listen(function (QueryExecuted $query) use (&$log): void {
        $log[] = strtolower($query->sql);
    });

    $this->migration->up();

    $sql = collect($log);
    // Subqueries correlacionadas (NOT EXISTS) DENTRO do UPDATE nunca
    // aparecem como query separada pro listener — só a instrução externa.
    // O que falta sem a trava explícita: sob READ COMMITTED, o reexame de
    // EvalPlanQual do Postgres (que atualizaria a visão pra uma escrita
    // concorrente) só vale pra LINHA sendo de fato escrita, nunca pras
    // linhas lidas só via subquery — um UPDATE sozinho, mesmo com
    // subquery correlacionada, ainda tem uma janela (mais estreita, mas
    // real) pro worker comitar o outcome real da foto DEPOIS do snapshot
    // da instrução mas ANTES dela terminar de varrer a linha do vídeo.
    // Por isso: 1 captura do conjunto de runs, 2 SELECT ... FOR UPDATE
    // (linhas candidatas + ai_runs) e 1 UPDATE — as 3 últimas restritas ao
    // MESMO conjunto (IN com os IDs capturados), nunca recalculando "runs
    // com linha null": um run criado entre uma instrução e outra entrava
    // no UPDATE sem nenhuma linha travada.
    $captureQueries   = $sql->filter(fn (string $q): bool => str_contains($q, 'select distinct'));
    $forUpdateQueries = $sql->filter(fn (string $q): bool => str_contains($q, 'for update'));
    $updateQueries    = $sql->filter(fn (string $q): bool => str_starts_with($q, 'update'));

    expect($captureQueries)->toHaveCount(1)
        ->and($forUpdateQueries)->toHaveCount(2)
        ->and($updateQueries)->toHaveCount(1)
        ->and($updateQueries->first())->toContain('not exists');

    foreach ($forUpdateQueries->concat($updateQueries) as $query) {
        expect($query)->toContain(' in (?')
            ->not->toContain('select distinct');
    }
});

it('[GAP] run com dispatch_audit.images = 0 (nenhuma imagem chegou a ser enviada) preserva TODAS as linhas null intocadas — não é legado, achado na 9ª rodada de revisão adversarial Codex', function () {
    // Todo arquivo selecionado ilegível: resolveImages() seguiu adiante
    // (dispatch_audit gravado) mas não enviou nem bloqueou nenhum —
    // diferente do run legado (dispatch_audit nunca gravado, fica null).
    $examA = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    $examB = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    $run   = AiRun::factory()->economy()->create([
        'entity_id'         => $this->entity->id, 'patient_id' => $this->patient->id,
        'workflow'          => 'eye_image_analysis', 'risk_level' => 'medium',
        'estimated_credits' => 0, 'reserved_credits' => 0, 'status' => AiRunStatus::WaitingApproval->value,
        'dispatch_audit'    => [
            'system_prompt_sha256' => null, 'data_categories' => [], 'context_keys' => [],
            'images'               => 0, 'dispatched_at' => now()->toIso8601String(),
        ],
    ]);
    $createdTogetherAt = now()->subMinute();
    DB::table('ai_run_patient_exam')->insert([
        ['ai_run_id' => $run->id, 'patient_exam_id' => $examA->id, 'entity_id' => $this->entity->id, 'image_deidentification' => null, 'created_at' => $createdTogetherAt],
        ['ai_run_id' => $run->id, 'patient_exam_id' => $examB->id, 'entity_id' => $this->entity->id, 'image_deidentification' => null, 'created_at' => $createdTogetherAt],
    ]);

    $this->migration->up();

    $outcome = fn (PatientExam $exam) => DB::table('ai_run_patient_exam')->where('patient_exam_id', $exam->id)->value('image_deidentification');

    expect($outcome($examA))->toBeNull()
        ->and($outcome($examB))->toBeNull();
});

it('down() reverte só as linhas marcadas pelo sentinela, nunca um outcome real que por acaso tivesse o mesmo texto', function () {
    [, $legacyNull] = backfillPivotRow($this, null, now()->subDays(30));
    $this->migration->up();
    expect(DB::table('ai_run_patient_exam')->where('patient_exam_id', $legacyNull->id)->value('image_deidentification'))->toBe('legacy_unknown');

    $this->migration->down();

    expect(DB::table('ai_run_patient_exam')->where('patient_exam_id', $legacyNull->id)->value('image_deidentification'))->toBeNull();
});

it('[GAP] laudo de IA aprovado ANTES da coluna existir continua contando como laudado após o backfill (achado na 3ª rodada de revisão adversarial Codex)', function () {
    [$legacyRun, $legacyExam] = backfillPivotRow($this, null, now()->subDays(30));

    // Sem o backfill: contaria como pendente (regressão que a revisão achou).
    expect(PatientExam::query()->pendingReport($this->entity->id)->pluck('patient_exams.id'))->toContain($legacyExam->id);

    $this->migration->up();

    expect(PatientExam::query()->pendingReport($this->entity->id)->pluck('patient_exams.id'))->not->toContain($legacyExam->id)
        ->and(PatientExam::query()->reported($this->entity->id)->pluck('patient_exams.id'))->toContain($legacyExam->id);
});

it('mais runs candidatos que cabem numa instrução: o conjunto é dividido em blocos e TODOS os runs legados são marcados, só os do conjunto', function () {
    // 1.001 runs legados (1 linha null cada, mesmo exame) > bloco de 1.000.
    $exam = PatientExam::factory()->create(['patient_id' => $this->patient->id]);
    $user = DB::table('users')->value('id') ?? User::factory()->create()->id;
    $base = AiRun::factory()->economy()->make([
        'entity_id'         => $this->entity->id, 'patient_id' => $this->patient->id, 'requested_by' => $user,
        'workflow'          => 'eye_image_analysis', 'risk_level' => 'medium',
        'estimated_credits' => 0, 'reserved_credits' => 0, 'status' => AiRunStatus::Approved->value,
    ])->getAttributes();
    $runIds = [];

    foreach (range(1, 1001) as $i) {
        $runIds[] = (string) Str::uuid();
    }

    foreach (array_chunk($runIds, 250) as $chunk) {
        DB::table('ai_runs')->insert(array_map(fn (string $id): array => array_merge($base, ['id' => $id, 'created_at' => now(), 'updated_at' => now()]), $chunk));
        DB::table('ai_run_patient_exam')->insert(array_map(fn (string $id): array => [
            'ai_run_id'              => $id, 'patient_exam_id' => $exam->id, 'entity_id' => $this->entity->id,
            'image_deidentification' => null, 'created_at' => now()->subDays(30),
        ], $chunk));
    }

    $updates = 0;
    DB::listen(function (QueryExecuted $query) use (&$updates): void {
        if (str_starts_with(strtolower($query->sql), 'update "ai_run_patient_exam"') || str_starts_with(strtolower($query->sql), 'update ai_run_patient_exam')) {
            $updates++;
        }
    });

    $this->migration->up();

    expect(DB::table('ai_run_patient_exam')->where('image_deidentification', 'legacy_unknown')->count())->toBe(1001)
        ->and(DB::table('ai_run_patient_exam')->whereNull('image_deidentification')->count())->toBe(0)
        // 1.001 runs = 1 bloco de 1.000 + 1 de 1 → 2 UPDATEs.
        ->and($updates)->toBe(2);
});
