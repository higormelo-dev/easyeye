<?php

/**
 * Cobre o dispatch de GenerateExamDerivatives no upload de exame via API de
 * integrador (PatientExamService::persistExam). Antes desta correção o job
 * era despachado DENTRO de DB::transaction() sem ->afterCommit() — com
 * QUEUE_CONNECTION=redis (after_commit=false por padrão em config/queue.php),
 * um worker podia pegar o job antes do commit e não achar o registro ainda
 * não persistido, falhando silenciosamente sem gerar a miniatura.
 */

use App\Enums\FeatureKey;
use App\Jobs\GenerateExamDerivatives;
use App\Models\{ExamType, Patient, PatientExam};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Bus, Storage};

beforeEach(function () {
    Storage::fake('s3');

    $this->ctx      = setupIntegrator([FeatureKey::HasApiIntegrator->value => '1']);
    $this->patient  = Patient::factory()->create(['entity_id' => $this->ctx['entity']->id]);
    $this->examType = ExamType::factory()->create(['entity_id' => null]);

    $schedCtx       = createScheduleForEntity($this->ctx['entity'], ['patient_id' => $this->patient->id]);
    $this->schedule = $schedCtx['schedule'];
});

it('despacha GenerateExamDerivatives com afterCommit ao criar um exame novo', function () {
    Bus::fake();

    $this->postJson(
        '/api/integrators/v1/exams',
        [
            'exam_identifier'     => $this->examType->code,
            'schedule_identifier' => $this->schedule->code,
            'archive'             => UploadedFile::fake()->image('exam.jpg'),
            'name'                => 'Exame Novo',
        ],
        $this->ctx['headers'],
    )->assertCreated();

    $exam = PatientExam::where('name', 'Exame Novo')->firstOrFail();

    Bus::assertDispatched(
        GenerateExamDerivatives::class,
        fn (GenerateExamDerivatives $job) => $job->patientExamId === $exam->id
            && $job->afterCommit === true,
    );
});

/**
 * O branch de update de `PatientExamService::persistExam()` (existingRecord
 * encontrado por patient_id+name) recebeu o mesmo fix (`->afterCommit()`),
 * mas não é alcançável de forma limpa via `POST /exams` num teste: reenviar
 * o mesmo exam_identifier+schedule_identifier+name é rejeitado antes disso
 * por uma regra de validação de unicidade dessa combinação (regra de negócio
 * separada, não relacionada a este fix). Confirmado por leitura de código
 * que os dois branches (linhas ~287 e ~303) recebem o mesmo `->afterCommit()`.
 */
