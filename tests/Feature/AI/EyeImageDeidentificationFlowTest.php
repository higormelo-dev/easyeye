<?php

use App\Domains\AI\Contracts\{AiProviderInterface, AiRunRepositoryInterface};
use App\Domains\AI\Exceptions\AiImageNotDeidentifiedException;
use App\Domains\AI\Models\AiRun;
use App\Domains\AI\Services\{AiProviderManager, AiProviderSettings, AiRunExecutionService};
use App\DTOs\AI\{AiProviderResponseData, AiRequestData, AiUsageData};
use App\Enums\AI\{AiProvider, AiRunStatus};
use App\Jobs\AI\RunAiWorkflowJob;
use App\Models\PatientExam;
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

it('job: imagem sem tarja possível falha de vez (sem nova tentativa)', function () {
    $run = deidentRun([deidentExam('unknown')]);

    (new RunAiWorkflowJob((string) $run->id))->handle(app(AiRunRepositoryInterface::class), app(AiRunExecutionService::class));

    expect(AiRun::withoutGlobalScopes()->find($run->id)->status)->toBe(AiRunStatus::Failed)
        ->and(collect($this->fakes)->sum(fn ($f) => count($f->requests)))->toBe(0);
});
