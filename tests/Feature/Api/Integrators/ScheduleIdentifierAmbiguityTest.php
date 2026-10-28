<?php

/**
 * F3 — a API de integradores nunca resolve um identificador para um
 * agendamento/paciente ARBITRÁRIO.
 *
 * Antes: `code = SDL-N OR import_code = N` + first()/firstOrFail() sem ordem.
 * Com código duplicado (corrida antiga na numeração) ou com o número N sendo
 * SDL-N de um agendamento e import_code de OUTRO (agenda legada importada),
 * o PostgreSQL devolvia qualquer uma das linhas — e o exame herdava paciente
 * e médico do agendamento errado (mistura de dado de saúde).
 *
 * Agora: 0 = não encontrado, 1 = resolvido, 2+ = ambíguo (GET → 409; envio de
 * exame → 422 no campo). Código explícito (SDL-…) tem precedência sobre
 * import_code.
 */

use App\Models\{ExamType, Patient, PatientExam, Schedule};
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Dois agendamentos de pacientes DIFERENTES da mesma clínica. */
function ambiguityTwoSchedules(array $ctx): array
{
    $patientA = Patient::factory()->create(['active' => true, 'entity_id' => $ctx['entity']->id]);
    $patientB = Patient::factory()->create(['active' => true, 'entity_id' => $ctx['entity']->id]);

    $a = createScheduleForEntity($ctx['entity'], ['patient_id' => $patientA->id])['schedule'];
    $b = createScheduleForEntity($ctx['entity'], ['patient_id' => $patientB->id])['schedule'];

    return [$a, $b, $patientA, $patientB];
}

function ambiguityNumber(Schedule $schedule): string
{
    return (string) (int) substr($schedule->code, 4);
}

describe('GET /api/integrators/v1/schedules/{id} — identificador ambíguo', function () {
    beforeEach(function () {
        $this->ctx           = setupIntegrator();
        [$this->a, $this->b] = ambiguityTwoSchedules($this->ctx);
    });

    it('responde 409 (nunca uma linha arbitraria) quando o codigo SDL esta duplicado', function () {
        // Duplicata real de antes da correção (dois creates concorrentes).
        $this->b->forceFill(['code' => $this->a->code])->save();

        $this->getJson('/api/integrators/v1/schedules/' . ambiguityNumber($this->a), $this->ctx['headers'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('record_codes.ambiguous_identifier.schedule'));

        $this->getJson("/api/integrators/v1/schedules/{$this->a->code}", $this->ctx['headers'])
            ->assertStatus(409);
    });

    it('continua resolvendo pelo UUID mesmo com codigo duplicado', function () {
        $this->b->forceFill(['code' => $this->a->code])->save();

        $this->getJson("/api/integrators/v1/schedules/{$this->b->id}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->b->id]);
    });

    it('responde 409 quando o numero e SDL-N de um agendamento e import_code de outro', function () {
        $number = ambiguityNumber($this->a);
        $this->b->forceFill(['import_code' => $number])->save();

        $this->getJson("/api/integrators/v1/schedules/{$number}", $this->ctx['headers'])
            ->assertStatus(409)
            ->assertJsonPath('message', __('record_codes.ambiguous_identifier.schedule'));
    });

    it('codigo explicito (SDL-…, inclusive abreviado e minusculo) tem precedencia sobre import_code', function () {
        $number = ambiguityNumber($this->a);
        $this->b->forceFill(['import_code' => $number])->save();

        $this->getJson("/api/integrators/v1/schedules/{$this->a->code}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->a->id]);

        $this->getJson("/api/integrators/v1/schedules/sdl-{$number}", $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->a->id]);
    });

    it('import_code textual continua resolvendo quando nenhum codigo casa', function () {
        $this->b->forceFill(['import_code' => 'LEGADO-77'])->save();

        $this->getJson('/api/integrators/v1/schedules/LEGADO-77', $this->ctx['headers'])
            ->assertOk()
            ->assertJsonFragment(['id' => $this->b->id]);
    });

    it('a busca da listagem mostra TODOS os candidatos (o cliente ve a ambiguidade)', function () {
        $number = ambiguityNumber($this->a);
        $this->b->forceFill(['import_code' => $number])->save();

        $ids = collect(
            $this->getJson("/api/integrators/v1/schedules?search={$number}", $this->ctx['headers'])
                ->assertOk()
                ->assertJsonPath('meta.total', 2)
                ->json('data'),
        )->pluck('id');

        expect($ids)->toContain($this->a->id)->and($ids)->toContain($this->b->id);
    });
});

describe('Envio de exame com identificador ambíguo', function () {
    beforeEach(function () {
        Storage::fake('s3');

        $this->ctx                                             = setupIntegrator();
        $this->examType                                        = ExamType::factory()->create(['entity_id' => null]);
        [$this->a, $this->b, $this->patientA, $this->patientB] = ambiguityTwoSchedules($this->ctx);

        $this->number = ambiguityNumber($this->a);
        $this->b->forceFill(['import_code' => $this->number])->save();
    });

    it('POST /patients/{p}/exams recusa schedule_identifier ambiguo com 422 no campo, sem gravar nem subir arquivo', function () {
        $this->postJson(
            "/api/integrators/v1/patients/{$this->patientA->id}/exams",
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->number,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Agendamento Ambiguo',
            ],
            $this->ctx['headers'],
        )->assertStatus(422)
            ->assertJsonPath('errors.schedule_identifier.0', __('record_codes.ambiguous_identifier.schedule'));

        expect(PatientExam::where('name', 'Exame Agendamento Ambiguo')->exists())->toBeFalse()
            ->and(Storage::disk('s3')->allFiles())->toBe([]);
    });

    it('POST /exams recusa schedule_identifier ambiguo (paciente viria do agendamento errado)', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->number,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Exams Ambiguo',
            ],
            $this->ctx['headers'],
        )->assertStatus(422)
            ->assertJsonPath('errors.schedule_identifier.0', __('record_codes.ambiguous_identifier.schedule'));

        expect(PatientExam::whereIn('patient_id', [$this->patientA->id, $this->patientB->id])->exists())->toBeFalse()
            ->and(Storage::disk('s3')->allFiles())->toBe([]);
    });

    it('POST /exams com o codigo SDL completo resolve o agendamento certo (precedencia do codigo)', function () {
        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'     => $this->examType->code,
                'schedule_identifier' => $this->a->code,
                'archive'             => UploadedFile::fake()->image('exam.jpg'),
                'name'                => 'Exame Codigo Completo',
            ],
            $this->ctx['headers'],
        )->assertCreated();

        $exam = PatientExam::where('name', 'Exame Codigo Completo')->firstOrFail();

        expect($exam->schedule_id)->toBe($this->a->id)
            ->and($exam->patient_id)->toBe($this->patientA->id);
    });

    it('POST /exams recusa patient_identifier ambiguo (PAC-N de um paciente e import_code N de outro)', function () {
        $patientNumber = (string) (int) substr($this->patientA->code, 4);
        $this->patientB->forceFill(['import_code' => $patientNumber])->save();

        $this->postJson(
            '/api/integrators/v1/exams',
            [
                'exam_identifier'    => $this->examType->code,
                'patient_identifier' => $patientNumber,
                'archive'            => UploadedFile::fake()->image('exam.jpg'),
                'name'               => 'Exame Paciente Ambiguo',
            ],
            $this->ctx['headers'],
        )->assertStatus(422)
            ->assertJsonPath('errors.patient_identifier.0', __('record_codes.ambiguous_identifier.patient'));

        expect(PatientExam::where('name', 'Exame Paciente Ambiguo')->exists())->toBeFalse();
    });
});
