<?php

/**
 * Cobre o comando `eye-images:backfill-derivatives` — enfileira geração de
 * miniatura/JPEG de exibição pra exames que ficaram sem `thumb_archive`
 * (criados antes da migration de derivados, ou vítimas do bug de dispatch
 * sem afterCommit corrigido em PatientExamService).
 */

use App\Jobs\GenerateExamDerivatives;
use App\Models\{Entity, Patient, PatientExam};
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    $this->entity  = Entity::factory()->create(['is_client' => true, 'active' => true]);
    $this->patient = Patient::factory()->create(['entity_id' => $this->entity->id]);
});

it('enfileira apenas exames sem thumb_archive', function () {
    Bus::fake();

    $pending = PatientExam::factory()->create([
        'patient_id'    => $this->patient->id,
        'archive'       => 'exams/fake-1.jpg',
        'thumb_archive' => null,
    ]);
    $done = PatientExam::factory()->create([
        'patient_id'    => $this->patient->id,
        'archive'       => 'exams/fake-2.jpg',
        'thumb_archive' => 'exams/fake-2_thumb.jpg',
    ]);

    $this->artisan('eye-images:backfill-derivatives', ['--all' => true])
        ->assertExitCode(0);

    Bus::assertDispatched(GenerateExamDerivatives::class, fn ($job) => $job->patientExamId === $pending->id);
    Bus::assertNotDispatched(GenerateExamDerivatives::class, fn ($job) => $job->patientExamId === $done->id);
});

it('--force reprocessa mesmo quem já tem thumb_archive', function () {
    Bus::fake();

    $done = PatientExam::factory()->create([
        'patient_id'    => $this->patient->id,
        'archive'       => 'exams/fake-1.jpg',
        'thumb_archive' => 'exams/fake-1_thumb.jpg',
    ]);

    $this->artisan('eye-images:backfill-derivatives', ['--all' => true, '--force' => true])
        ->assertExitCode(0);

    Bus::assertDispatched(GenerateExamDerivatives::class, fn ($job) => $job->patientExamId === $done->id);
});

it('--patient filtra pelo código do paciente', function () {
    Bus::fake();

    $otherPatient = Patient::factory()->create(['entity_id' => $this->entity->id]);
    $target       = PatientExam::factory()->create([
        'patient_id' => $this->patient->id, 'archive' => 'exams/fake-1.jpg', 'thumb_archive' => null,
    ]);
    $other = PatientExam::factory()->create([
        'patient_id' => $otherPatient->id, 'archive' => 'exams/fake-2.jpg', 'thumb_archive' => null,
    ]);

    $this->artisan('eye-images:backfill-derivatives', ['--patient' => $this->patient->code])
        ->assertExitCode(0);

    Bus::assertDispatched(GenerateExamDerivatives::class, fn ($job) => $job->patientExamId === $target->id);
    Bus::assertNotDispatched(GenerateExamDerivatives::class, fn ($job) => $job->patientExamId === $other->id);
});

it('exige --patient, --limit ou --all', function () {
    $this->artisan('eye-images:backfill-derivatives')->assertExitCode(1);
});

it('--limit respeita o teto informado', function () {
    Bus::fake();

    PatientExam::factory()->count(3)->create([
        'patient_id' => $this->patient->id, 'archive' => 'exams/fake.jpg', 'thumb_archive' => null,
    ]);

    $this->artisan('eye-images:backfill-derivatives', ['--limit' => 2])
        ->assertExitCode(0);

    Bus::assertDispatchedTimes(GenerateExamDerivatives::class, 2);
});
