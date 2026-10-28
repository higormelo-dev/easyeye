<?php

use App\Domains\AI\Contracts\{AiProviderInterface, AiRunRepositoryInterface};
use App\Domains\AI\Exceptions\AiImageNotDeidentifiedException;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiProviderManager, AiProviderSettings, AiRunExecutionService, EyeImageAttachmentService};
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\{AiProvider, AiRunStatus};
use App\Jobs\AI\RunAiWorkflowJob;
use App\Models\PatientExam;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\{DB, Storage};

/**
 * Execução da análise de imagem (Eye Image) com a tarja LGPD: só sai imagem
 * de layout reconhecido, já tarjada; a que não sai fica registrada, vira
 * aviso ao médico e sai do contexto; sem nenhuma imagem a análise falha
 * antes de chamar o provedor (nada cobrado, sem nova tentativa).
 */
class DeidentificationFakeProvider implements AiProviderInterface
{
    /** @var list<AiRequestData> */
    public array $requests = [];

    public function __construct(private AiProvider $code)
    {
    }

    public function generate(AiRequestData $request): AiProviderResponseData
    {
        $this->requests[] = $request;

        return new AiProviderResponseData(
            provider: $this->code,
            model: 'fake-model',
            content: 'Rascunho de laudo para revisão médica.',
            usage: new AiUsageData(inputTokens: 100, outputTokens: 50, rawCostUsd: 0.0001),
            latencyMs: 5,
        );
    }

    public function supportsVision(): bool
    {
        return true;
    }

    public function supportsJsonMode(): bool
    {
        return true;
    }

    public function provider(): AiProvider
    {
        return $this->code;
    }
}

beforeEach(function () {
    Storage::fake('s3');
    config()->set('ai.eye_image.max_dimension', 2000);
    app(AiProviderSettings::class)->setEnabledCodes(['openai']);

    $this->fakes = [];

    foreach (AiProvider::cases() as $case) {
        $this->fakes[$case->value] = new DeidentificationFakeProvider($case);
    }

    app()->instance(AiProviderManager::class, new AiProviderManager($this->fakes, app(AiProviderSettings::class)));
});

/** Exame com tela conhecida (fixture sem dado real), tela desconhecida ou sem arquivo. */
function deidentExam(string $kind, int $laterality = 1, ?PatientExam $samePatientAs = null): PatientExam
{
    $exam = PatientExam::factory()->create([
        'laterality' => $laterality,
        ...($samePatientAs ? ['patient_id' => $samePatientAs->patient_id] : []),
    ]);

    if ($kind === 'known') {
        Storage::disk('s3')->put($exam->archive, (string) file_get_contents(base_path('tests/Fixtures/exam-image-layouts/oculus_pentacam_panel_a_pt.png')));
    } elseif ($kind === 'unknown') {
        $blank = imagecreatetruecolor(640, 480);
        ob_start();
        imagejpeg($blank);
        Storage::disk('s3')->put($exam->archive, (string) ob_get_clean());
    } elseif ($kind === 'video') {
        // Bytes NÃO decodificáveis como imagem (GD) — mesmo efeito de um
        // .webm/.mp4 de verdade (aceito na importação desde a Fase 5/6a),
        // sem precisar de um arquivo de vídeo de fato no teste.
        Storage::disk('s3')->put($exam->archive, 'fake-webm-bytes-not-a-real-image');
    }

    return $exam;
}

/** @param list<PatientExam> $exams */
function deidentRun(array $exams): AiRun
{
    $patient = $exams[0]->patient()->withoutGlobalScopes()->first();

    $run = AiRun::factory()->economy()->create([
        'entity_id'         => $patient->entity_id,
        'patient_id'        => $patient->id,
        'workflow'          => 'eye_image_analysis',
        'risk_level'        => 'medium',
        'estimated_credits' => 0,
        'reserved_credits'  => 0,
        'input_summary'     => [
            'user_prompt' => 'Analise as imagens selecionadas.',
            'exam_ids'    => array_map(fn (PatientExam $e) => (string) $e->id, $exams),
            'context'     => ['selected_exams' => array_map(fn (PatientExam $e, int $i) => [
                'image'     => $i + 1,
                'exam_type' => "Exame {$i}",
                'eye'       => $e->laterality === 2 ? 'OS' : 'OD',
                'exam_date' => '2026-10-03',
            ], $exams, array_keys($exams))],
        ],
    ]);

    foreach ($exams as $exam) {
        DB::table('ai_run_patient_exam')->insert([
            'ai_run_id' => $run->id, 'patient_exam_id' => $exam->id, 'entity_id' => $run->entity_id, 'created_at' => now(),
        ]);
    }

    return $run;
}

function deidentOutcome(AiRun $run, PatientExam $exam): ?string
{
    return DB::table('ai_run_patient_exam')->where('ai_run_id', $run->id)->where('patient_exam_id', $exam->id)->value('image_deidentification');
}

it('[LGPD] só a imagem reconhecida sai, tarjada; a desconhecida fica registrada, vira aviso e sai do contexto', function () {
    $unknown = deidentExam('unknown', 2);
    $known   = deidentExam('known', 1, $unknown);
    $missing = deidentExam('missing', 1, $unknown);
    $run     = deidentRun([$unknown, $known, $missing]);

    app(AiRunExecutionService::class)->execute($run);

    $request = $this->fakes['openai']->requests[0] ?? null;
    expect($this->fakes['openai']->requests)->toHaveCount(1)
        ->and($request->attachments)->toHaveCount(1)
        ->and($request->attachments[0]['exam_id'])->toBe((string) $known->id)
        // Imagem 1 do contexto = o único anexo (era o 2º exame da seleção).
        ->and($request->context['selected_exams'])->toBe([
            ['image' => 1, 'exam_type' => 'Exame 1', 'eye' => 'OD', 'exam_date' => '2026-10-03'],
        ]);

    $sent = imagecreatefromstring(base64_decode($request->attachments[0]['data']));
    expect(imagecolorat($sent, 152, 93) & 0xF0F0F0)->toBe(0); // campo do paciente tarjado

    $run = AiRun::withoutGlobalScopes()->find($run->id);

    // Auditoria do envio: só o resumo (sem conteúdo) e a trilha só com esta coluna.
    expect($run->dispatch_audit['images'])->toBe(1)
        ->and($run->dispatch_audit['data_categories'])->toBe(['exam_metadata', 'images', 'request_text'])
        ->and($run->dispatch_audit['system_prompt_sha256'])->toBe(hash('sha256', $request->systemPrompt));

    $trail = DB::table('audit_logs')->where('auditable_id', $run->id)->where('event', 'updated')
        ->get()->first(fn ($row) => str_contains((string) $row->new_values, 'dispatch_audit'));
    expect(array_keys(json_decode($trail->new_values, true)))->toBe(['dispatch_audit']);

    expect($run->status)->toBe(AiRunStatus::WaitingApproval)
        ->and($run->safety_notes)->toContain(trans_choice('ai.eye_image_some_not_sent', 1, ['count' => 1]))
        ->and(deidentOutcome($run, $known))->toBe('oculus_pentacam_panel_a_pt')
        ->and(deidentOutcome($run, $unknown))->toBe('unrecognized_layout')
        ->and(deidentOutcome($run, $missing))->toBeNull();
});

it('[LGPD] nenhuma imagem reconhecida: falha antes do provedor, com o motivo para o médico', function () {
    $unknown = deidentExam('unknown');
    $run     = deidentRun([$unknown]);

    expect(fn () => app(AiRunExecutionService::class)->execute($run))
        ->toThrow(AiImageNotDeidentifiedException::class, trans_choice('ai.eye_image_none_sent', 1, ['count' => 1]));

    $run = AiRun::withoutGlobalScopes()->find($run->id);
    expect(collect($this->fakes)->sum(fn ($f) => count($f->requests)))->toBe(0)
        ->and($run->status)->toBe(AiRunStatus::Failed)
        ->and($run->dispatch_audit)->toBeNull() // nada saiu
        ->and($run->error_message)->toBe(trans_choice('ai.eye_image_none_sent', 1, ['count' => 1]))
        ->and(deidentOutcome($run, $unknown))->toBe('unrecognized_layout');
});

it('[GAP] exame de vídeo selecionado (IA só analisa foto): falha antes do provedor, nunca cobra, nunca confunde com motivo de layout/LGPD', function () {
    $video = deidentExam('video');
    $run   = deidentRun([$video]);

    expect(fn () => app(AiRunExecutionService::class)->execute($run))
        ->toThrow(AiImageNotDeidentifiedException::class, trans_choice('ai.eye_image_none_available', 1, ['count' => 1]));

    $run = AiRun::withoutGlobalScopes()->find($run->id);
    expect(collect($this->fakes)->sum(fn ($f) => count($f->requests)))->toBe(0) // nunca chamou o provedor
        ->and($run->status)->toBe(AiRunStatus::Failed)
        ->and($run->dispatch_audit)->toBeNull() // nada saiu
        ->and($run->error_message)->toBe(trans_choice('ai.eye_image_none_available', 1, ['count' => 1]))
        // Nunca registra 'unrecognized_layout' — a causa aqui nunca é o
        // layout de tarja (achado via revisão Codex: a checagem antiga só
        // cobria esse motivo específico, e um vídeo nunca chega nele —
        // $sent ficava vazio e $blocked ficava zero, deixando passar pro
        // provedor sem nenhuma imagem).
        ->and(deidentOutcome($run, $video))->toBeNull();
});

it('[GAP] foto + vídeo selecionados juntos: a IA analisa só a foto, avisa sobre o vídeo (nunca confunde com motivo de layout/LGPD) — achado via revisão adversarial Codex', function () {
    $known = deidentExam('known', 1);
    $video = deidentExam('video', 2, $known);
    $run   = deidentRun([$known, $video]);

    app(AiRunExecutionService::class)->execute($run);

    // Passa (pelo menos uma imagem FOI enviada — a foto) — diferente do
    // teste "só vídeo" acima, que falha antes do provedor.
    $request = $this->fakes['openai']->requests[0] ?? null;
    expect($this->fakes['openai']->requests)->toHaveCount(1)
        ->and($request->attachments)->toHaveCount(1)
        ->and($request->attachments[0]['exam_id'])->toBe((string) $known->id)
        ->and($request->context['selected_exams'])->toBe([
            ['image' => 1, 'exam_type' => 'Exame 0', 'eye' => 'OD', 'exam_date' => '2026-10-03'],
        ]);

    $run = AiRun::withoutGlobalScopes()->find($run->id);
    // Sem a correção, $blocked só contava "layout não reconhecido" — um
    // vídeo nunca chega nesse motivo (nem outcome chega a ter), então
    // $notes ficava vazio e o médico nunca saberia que o vídeo não foi
    // analisado, mesmo tendo sido selecionado junto com a foto.
    expect($run->safety_notes)->toContain(trans_choice('ai.eye_image_some_unsupported', 1, ['count' => 1]))
        ->and($run->safety_notes)->not->toContain(trans_choice('ai.eye_image_some_not_sent', 1, ['count' => 1])) // motivo certo, nunca o de LGPD
        ->and(deidentOutcome($run, $known))->toBe('oculus_pentacam_panel_a_pt')
        ->and(deidentOutcome($run, $video))->toBeNull(); // nunca chega a ter outcome — nem tentativa, nem bloqueio
});

it('[GAP] retry (queue redelivera o job após o worker crashar/timeout — markRunning() permite rodar de novo, ver RunAiWorkflowJob::failed()) nunca herda o outcome de SUCESSO de uma tentativa anterior pro exame que esta tentativa não conseguiu enviar — achado na 6ª rodada de revisão adversarial Codex', function () {
    $examA = deidentExam('known', 1);
    $examB = deidentExam('known', 2, $examA);
    $run   = deidentRun([$examA, $examB]);

    // Simula uma tentativa ANTERIOR que escreveu sucesso pros dois exames e
    // foi interrompida ANTES de chegar no provedor — worker crashou/timeout
    // (RunAiWorkflowJob::failed() documenta exatamente isto: o run fica
    // preso em status não-terminal, nunca chega a Failed/compensateFailedRun,
    // e o queue REDELIVERA o job, chamando execute() de novo no MESMO run —
    // markRunning() existe justamente pra isto, "em caso de retry do job").
    DB::table('ai_run_patient_exam')->where('ai_run_id', $run->id)
        ->update(['image_deidentification' => 'oculus_pentacam_panel_a_pt']);

    // Arquivo do exame B "some" ENTRE a tentativa interrompida e esta —
    // só se manifesta agora.
    Storage::disk('s3')->delete($examB->archive);

    app(AiRunExecutionService::class)->execute($run);

    $run     = AiRun::withoutGlobalScopes()->find($run->id);
    $request = $this->fakes['openai']->requests[0] ?? null;
    expect($this->fakes['openai']->requests)->toHaveCount(1)
        ->and($request->attachments)->toHaveCount(1)
        ->and($request->attachments[0]['exam_id'])->toBe((string) $examA->id);

    // NUNCA o outcome de sucesso da tentativa interrompida — B não foi
    // enviado NESTA tentativa (a que de fato prosseguiu e vai ser aprovada),
    // então nunca pode contar como laudado quando este run for aprovado.
    expect(deidentOutcome($run, $examA))->toBe('oculus_pentacam_panel_a_pt')
        ->and(deidentOutcome($run, $examB))->toBeNull();
});

it('[GAP] falha no meio do laço de outcomes desfaz também o zerar — atomicidade de verdade, nunca autocommits separados que deixassem o run observável NO MEIO da sequência (achado na 12ª rodada de revisão adversarial Codex)', function () {
    // A migration de backfill de legado (database/migrations/
    // 2026_10_21_000200_...) trava as linhas candidatas (FOR UPDATE) antes
    // de classificar — mas isso só serializa contra OUTRA transação, nunca
    // protege contra observar ESTE run exatamente entre zerar e escrever
    // (zerado, outcome real ainda não re-escrito): sem esta correção, um
    // exame nunca tentado no laço ficaria com o sentinela de legado da
    // migration pra sempre, já que o laço nunca o sobrescreve.
    $examA = deidentExam('known', 1);
    $examB = deidentExam('known', 2, $examA);
    $run   = deidentRun([$examA, $examB]);

    // Simula uma tentativa ANTERIOR bem-sucedida pra A.
    DB::table('ai_run_patient_exam')->where('ai_run_id', $run->id)->where('patient_exam_id', $examA->id)
        ->update(['image_deidentification' => 'outcome_de_tentativa_anterior']);

    // Mock de EyeImageAttachmentService::prepare() força o 2º outcome do
    // laço a usar uma chave que não é um UUID válido — o update() real no
    // Postgres lança "invalid input syntax for type uuid", interrompendo o
    // laço DEPOIS do outcome de A já ter sido escrito. Prova a atomicidade
    // de verdade: sem transação, o zerar (já comitado sozinho) e a
    // reescrita de A (também já comitada) sobreviveriam à exceção, agora
    // mesmo sem o outcome de A novo, pois B nunca é alcançado — um estado
    // PIOR que antes (A perderia o outcome da tentativa anterior sem
    // ganhar o novo). Com a transação, tudo volta ao estado anterior.
    $fakeAttachments = Mockery::mock(EyeImageAttachmentService::class);
    $fakeAttachments->shouldReceive('prepare')->once()->andReturn([
        'attachments' => [['exam_id' => (string) $examA->id, 'data' => 'x', 'mime_type' => 'image/jpeg']],
        'outcomes'    => [
            (string) $examA->id => 'oculus_pentacam_panel_a_pt',
            'not-a-valid-uuid'  => 'oculus_pentacam_panel_a_pt',
        ],
    ]);
    app()->instance(EyeImageAttachmentService::class, $fakeAttachments);

    expect(fn () => app(AiRunExecutionService::class)->execute($run))->toThrow(QueryException::class);

    expect(deidentOutcome($run, $examA))->toBe('outcome_de_tentativa_anterior')
        ->and(deidentOutcome($run, $examB))->toBeNull();
});

it('job: imagem sem tarja possível falha de vez (sem nova tentativa)', function () {
    $run = deidentRun([deidentExam('unknown')]);

    (new RunAiWorkflowJob((string) $run->id))->handle(app(AiRunRepositoryInterface::class), app(AiRunExecutionService::class));

    expect(AiRun::withoutGlobalScopes()->find($run->id)->status)->toBe(AiRunStatus::Failed)
        ->and(collect($this->fakes)->sum(fn ($f) => count($f->requests)))->toBe(0);
});
