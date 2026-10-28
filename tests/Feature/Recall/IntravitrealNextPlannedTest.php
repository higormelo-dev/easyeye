<?php

declare(strict_types=1);

/**
 * A "próxima aplicação" do curso depois de uma execução (achado via revisão adversarial Codex): se o curso já tem uma
 * aplicação PLANEJADA, é ela a próxima e só ganha a data nova — nunca nasce outra. Dois pedidos de injeção no mesmo olho
 * antes de executar geram as aplicações 1 e 2 (uma por procedimento); inserir sempre fazia a execução da 1 criar a 3 e a
 * da 2 criar a 4, e ficavam duas "próximas" com datas diferentes. O agendamento da próxima pega a MAIS ANTIGA e
 * ficava com o intervalo velho. Relógio fixo: 10/03/2026.
 */

use App\Domains\Recall\Intravitreal\Enums\ApplicationStatus;
use App\Domains\Recall\Intravitreal\Models\{IntravitrealApplication, IntravitrealCourse};
use App\Domains\Recall\Intravitreal\Services\{IntravitrealApplicationService, IntravitrealCourseService};
use App\Models\{MedicalRecord, MedicalRecordProcedure, Procedure};
use App\Services\MedicalRecordProcedureExecutionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{DB, Notification};

beforeEach(function () {
    Notification::fake();
    $this->travelTo(CarbonImmutable::parse('2026-03-10 10:00:00'));

    ['entity' => $this->entity, 'doctor' => $this->doctor] = rcfClinic(recall: true);

    $this->patient = rcfPatient($this->entity);
    $this->record  = MedicalRecord::query()->create(['entity_id' => $this->entity->id, 'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id]);
    $this->ivt     = Procedure::query()->create(['entity_id' => $this->entity->id, 'code' => 'IVT-N', 'name' => 'Injeção intravítrea', 'clinical_kind' => Procedure::KIND_INTRAVITREAL, 'active' => true]);
});

/** Um pedido de injeção no olho direito (cria o curso no 1º e planeja uma aplicação ligada ao procedimento). */
function inpRequest(object $test): MedicalRecordProcedure
{
    $procedure = MedicalRecordProcedure::create([
        'entity_id'    => $test->entity->id, 'patient_id' => $test->patient->id, 'medical_record_id' => $test->record->id,
        'procedure_id' => $test->ivt->id, 'doctor_id' => $test->doctor->id, 'eye' => 'right',
    ]);

    app(IntravitrealCourseService::class)->ensureForProcedure($procedure, 'right', ['drug_key' => 'aflibercept']);

    return $procedure;
}

function inpExecute(object $test, MedicalRecordProcedure $procedure, int $weeks): void
{
    app(MedicalRecordProcedureExecutionService::class)->markDone(
        $procedure->refresh(),
        $test->doctor->entityUser,
        [],
        null,
        ['lot_number' => 'LOT-NEXT', 'lot_expiry' => '2027-01-31', 'next_interval_weeks' => $weeks],
    );
}

/**
 * As aplicações do curso em ordem de sequência: [sequência, situação, data prevista, procedimento (ou nulo)].
 *
 * @return list<array{int, string, ?string, ?string}>
 */
function inpApplications(): array
{
    return IntravitrealApplication::query()->withoutGlobalScopes()->orderBy('sequence_no')->get()
        ->map(static fn (IntravitrealApplication $application): array => [
            $application->sequence_no,
            $application->status->value,
            $application->planned_for?->toDateString(),
            $application->medical_record_procedure_id !== null ? (string) $application->medical_record_procedure_id : null,
        ])
        ->all();
}

/** @return list<array{int, string, ?string, ?string}> só as planejadas */
function inpPlanned(): array
{
    return array_values(array_filter(inpApplications(), static fn (array $row): bool => $row[1] === ApplicationStatus::Planned->value));
}

it('duas solicitações no mesmo olho antes de executar: executar as duas deixa UMA próxima aplicação, com a data da última decisão', function () {
    $first  = inpRequest($this);
    $second = inpRequest($this);

    expect(inpPlanned())->toHaveCount(2); // 1 (procedimento 1) e 2 (procedimento 2), as duas planejadas

    inpExecute($this, $first, 4); // 10/03 + 4 semanas = 07/04

    $this->travelTo(CarbonImmutable::parse('2026-03-24 10:00:00'));
    inpExecute($this, $second, 8); // 24/03 + 8 semanas = 19/05

    $course = IntravitrealCourse::query()->withoutGlobalScopes()->sole();

    // 1 e 2 realizadas; a 3 é a ÚNICA planejada e leva a data da decisão mais recente (não nasce uma 4).
    expect(inpApplications())->toBe([
        [1, 'performed', '2026-03-10', (string) $first->id],
        [2, 'performed', '2026-04-07', (string) $second->id],
        [3, 'planned', '2026-05-19', null],
    ])
        ->and($course->next_due_on->toDateString())->toBe('2026-05-19');
});

it('executar a 1ª com a 2ª já solicitada: a 2ª é a próxima e ganha a data nova — não nasce uma 3ª', function () {
    $first  = inpRequest($this);
    $second = inpRequest($this);

    inpExecute($this, $first, 4);

    $course = IntravitrealCourse::query()->withoutGlobalScopes()->sole();

    expect(inpApplications())->toHaveCount(2)
        ->and(inpPlanned())->toBe([[2, 'planned', '2026-04-07', (string) $second->id]])
        ->and($course->next_due_on->toDateString())->toBe('2026-04-07');
});

it('executar a 2ª solicitação antes da 1ª: a 1ª vira a próxima (data nova) e, executada depois, a seguinte nasce uma só vez', function () {
    $first  = inpRequest($this);
    $second = inpRequest($this);

    inpExecute($this, $second, 4);

    expect(inpApplications())->toHaveCount(2)
        ->and(inpPlanned())->toBe([[1, 'planned', '2026-04-07', (string) $first->id]]);

    $this->travelTo(CarbonImmutable::parse('2026-04-07 10:00:00'));
    inpExecute($this, $first, 8); // 07/04 + 8 semanas = 02/06

    expect(inpApplications())->toHaveCount(3)
        ->and(inpPlanned())->toBe([[3, 'planned', '2026-06-02', null]]);
});

it('a aplicação que ficou solta (procedimento cancelado) é a próxima: ganha a data nova em vez de nascer outra', function () {
    $first  = inpRequest($this);
    $second = inpRequest($this);

    DB::transaction(fn () => app(IntravitrealApplicationService::class)->releaseProcedure($first)); // cancelou o pedido 1: a aplicação 1 segue planejada, sem procedimento

    inpExecute($this, $second, 4);

    expect(inpApplications())->toHaveCount(2)
        ->and(inpPlanned())->toBe([[1, 'planned', '2026-04-07', null]]);
});

it('três solicitações: a execução da 1ª dá a data nova à MAIS ANTIGA das planejadas (a que o agendamento da próxima usa); a outra segue como pedida', function () {
    $first  = inpRequest($this);
    $second = inpRequest($this);
    $third  = inpRequest($this);

    inpExecute($this, $first, 4);

    expect(inpApplications())->toHaveCount(3)
        ->and(inpPlanned())->toBe([
            [2, 'planned', '2026-04-07', (string) $second->id],
            [3, 'planned', '2026-03-10', (string) $third->id],
        ]);
});

it('aplicação planejada excluída não é reaproveitada: a próxima nasce e a excluída fica como estava', function () {
    $first  = inpRequest($this);
    $second = inpRequest($this);

    IntravitrealApplication::query()->withoutGlobalScopes()->where('medical_record_procedure_id', $second->id)->firstOrFail()->delete();

    inpExecute($this, $first, 4);

    $visible = IntravitrealApplication::query()->withoutGlobalScopes()->whereNull('deleted_at')->where('status', ApplicationStatus::Planned->value)->get();
    $deleted = IntravitrealApplication::query()->withoutGlobalScopes()->onlyTrashed()->sole();

    expect($visible)->toHaveCount(1)
        ->and($visible[0]->sequence_no)->toBe(3)
        ->and($visible[0]->planned_for->toDateString())->toBe('2026-04-07')
        ->and($deleted->planned_for->toDateString())->toBe('2026-03-10');
});

it('sem nenhuma aplicação planejada (o caso comum), a execução cria a próxima com a data decidida', function () {
    $only = inpRequest($this);

    inpExecute($this, $only, 6); // 10/03 + 6 semanas = 21/04

    expect(inpApplications())->toBe([
        [1, 'performed', '2026-03-10', (string) $only->id],
        [2, 'planned', '2026-04-21', null],
    ]);
});
