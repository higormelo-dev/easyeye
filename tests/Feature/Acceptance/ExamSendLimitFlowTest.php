<?php

/**
 * Teste de aceitação: fluxo completo do limite mensal de envio de exames.
 *
 * Cenários cobertos:
 *  1. Plano com limite 3 → 3 envios OK → 4° bloqueado com 403
 *  2. Virada de mês → contador zera e novo envio é aceito
 *  3. Plano com limite 0 (ilimitado) → nunca bloqueia
 *  4. ApiIntegrator desativado → todos os envios bloqueados
 */

use App\Enums\FeatureKey;
use App\Models\{ExamType, FeatureUsage, Patient};
use App\Services\FeatureGateService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('s3');
    $this->examType = ExamType::factory()->create(['entity_id' => null]);
});

describe('Fluxo de limite mensal de exames (end-to-end)', function () {
    it('bloqueia no 4° envio com plano de limite 3', function () {
        $ctx      = setupIntegrator([
            FeatureKey::HasApiIntegrator->value    => '1',
            FeatureKey::ApiMonthlyExamSends->value => '3',
        ]);
        $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        $schedCtx = createScheduleForEntity($ctx['entity']);
        $url      = "/api/integrators/v1/patients/{$patient->id}/exams";

        // 3 envios bem-sucedidos
        foreach (range(1, 3) as $i) {
            $this->postJson($url, [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $schedCtx['schedule']->code,
                'archive'             => UploadedFile::fake()->image("exam{$i}.jpg"),
                'name'                => "Exame {$i}",
            ], $ctx['headers'])->assertCreated();
        }

        // 4° bloqueado
        $this->postJson($url, [
            'exam_identifier'     => $this->examType->code,
            'schedule_identifier' => $schedCtx['schedule']->code,
            'archive'             => UploadedFile::fake()->image('exam4.jpg'),
            'name'                => 'Exame 4',
        ], $ctx['headers'])->assertForbidden()->assertJsonStructure(['message', 'feature']);
    });

    it('reinicia o contador na virada do mês', function () {
        $ctx      = setupIntegrator([
            FeatureKey::HasApiIntegrator->value    => '1',
            FeatureKey::ApiMonthlyExamSends->value => '1',
        ]);
        $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        $schedCtx = createScheduleForEntity($ctx['entity']);
        $url      = "/api/integrators/v1/patients/{$patient->id}/exams";

        // Consome o limite do mês atual
        $this->postJson($url, [
            'exam_identifier'     => $this->examType->code,
            'schedule_identifier' => $schedCtx['schedule']->code,
            'archive'             => UploadedFile::fake()->image('jan.jpg'),
            'name'                => 'Exame Janeiro',
        ], $ctx['headers'])->assertCreated();

        // Confirma que está bloqueado
        $this->postJson($url, [
            'exam_identifier'     => $this->examType->code,
            'schedule_identifier' => $schedCtx['schedule']->code,
            'archive'             => UploadedFile::fake()->image('jan2.jpg'),
            'name'                => 'Exame Janeiro 2',
        ], $ctx['headers'])->assertForbidden();

        // Simula virada de mês apagando o registro de uso do período anterior
        FeatureUsage::where('entity_id', $ctx['entity']->id)
            ->where('feature', FeatureKey::ApiMonthlyExamSends->value)
            ->delete();

        // No "novo mês" o envio volta a funcionar
        $this->postJson($url, [
            'exam_identifier'     => $this->examType->code,
            'schedule_identifier' => $schedCtx['schedule']->code,
            'archive'             => UploadedFile::fake()->image('fev.jpg'),
            'name'                => 'Exame Fevereiro',
        ], $ctx['headers'])->assertCreated();
    });

    it('plano ilimitado (limite = 0) nunca bloqueia', function () {
        $ctx      = setupIntegrator([
            FeatureKey::HasApiIntegrator->value    => '1',
            FeatureKey::ApiMonthlyExamSends->value => '0',
        ]);
        $patient  = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);
        $schedCtx = createScheduleForEntity($ctx['entity']);
        $url      = "/api/integrators/v1/patients/{$patient->id}/exams";

        foreach (range(1, 5) as $i) {
            $this->postJson($url, [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $schedCtx['schedule']->code,
                'archive'             => UploadedFile::fake()->image("exam{$i}.jpg"),
                'name'                => "Exame {$i}",
            ], $ctx['headers'])->assertCreated();
        }

        $status = app(FeatureGateService::class)
            ->status($ctx['entity']->id, FeatureKey::ApiMonthlyExamSends);

        expect($status->isUnlimited)->toBeTrue();
    });

    it('bloqueia todos os envios quando HasApiIntegrator está desativado', function () {
        $ctx     = setupIntegrator([
            FeatureKey::HasApiIntegrator->value    => '0',
            FeatureKey::ApiMonthlyExamSends->value => '100',
        ]);
        $patient = Patient::factory()->create(['entity_id' => $ctx['entity']->id]);

        $this->postJson(
            "/api/integrators/v1/patients/{$patient->id}/exams",
            [
                'exam_identifier' => $this->examType->code,
                'archive'         => UploadedFile::fake()->image('blocked.jpg'),
            ],
            $ctx['headers']
        )->assertForbidden();
    });
});
