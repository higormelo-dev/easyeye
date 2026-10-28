<?php

use App\Enums\ImportStatus;
use App\Events\ImportProgressUpdated;
use App\Jobs\{ProcessDoctorImportJob, ProcessMedicineImportJob, ProcessPatientImportJob, ProcessScheduleImportJob};
use App\Models\{DoctorImport, Entity, MedicineImport, PatientImport, ScheduleImport, User};
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Event;

/**
 * Worker morto no meio da importação (OOM, timeout, deploy): o catch do
 * serviço não roda e a fila só chama failed() do job na entrega seguinte
 * (MaxAttemptsExceededException). Caso real no ambiente de teste: lista CMED
 * estourou a RAM do nó e o import ficou "processando" pra sempre, bloqueando
 * novos envios de medicamentos.
 */
function failureClinicImport(string $model): PatientImport|DoctorImport|ScheduleImport
{
    $entity = Entity::factory()->create(['is_client' => true, 'active' => true]);

    return $model::query()->create([
        'entity_id'     => $entity->id,
        'user_id'       => User::factory()->create()->id,
        'status'        => ImportStatus::Processing,
        'file_path'     => 'imports/x.csv',
        'original_name' => 'x.csv',
        'total_rows'    => 100,
    ]);
}

function failureMedicineImport(ImportStatus $status = ImportStatus::Processing): MedicineImport
{
    return MedicineImport::query()->create([
        'status'             => $status,
        'phase'              => $status === ImportStatus::Processing ? 'reading' : null,
        'cmed_file_path'     => 'imports/medicines/x.xlsx',
        'cmed_original_name' => 'x.xlsx',
    ]);
}

it('[FILA] medicamentos: worker morto encerra o import como falho e transmite o estado final', function () {
    Event::fake([ImportProgressUpdated::class]);
    $import = failureMedicineImport();

    (new ProcessMedicineImportJob($import))->failed(new MaxAttemptsExceededException('attempted too many times'));

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->phase)->toBeNull()
        ->and($import->error)->toBe(__('manager_medicines.import_failed_generic'))
        ->and($import->finished_at)->not->toBeNull();

    Event::assertDispatched(ImportProgressUpdated::class, fn (ImportProgressUpdated $e) => $e->payload['status'] === ImportStatus::Failed->value);
});

it('[FILA] medicamentos: depois da falha, um novo envio não fica mais bloqueado', function () {
    $import = failureMedicineImport();

    expect(MedicineImport::query()->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])->exists())->toBeTrue();

    (new ProcessMedicineImportJob($import))->failed(new MaxAttemptsExceededException('x'));

    expect(MedicineImport::query()->whereIn('status', [ImportStatus::Pending->value, ImportStatus::Processing->value])->exists())->toBeFalse();
});

it('[FILA] desfecho já gravado pelo serviço não é sobrescrito', function (ImportStatus $status) {
    $import = failureMedicineImport($status);

    (new ProcessMedicineImportJob($import))->failed(new RuntimeException('x'));

    expect($import->fresh()->status)->toBe($status)
        ->and($import->fresh()->error)->toBeNull();
})->with([
    'concluído' => ImportStatus::Done,
    'falho'     => ImportStatus::Failed,
]);

it('[FILA] pacientes, médicos e agenda: worker morto encerra o import com motivo genérico', function (string $model, string $job) {
    $import = failureClinicImport($model);

    (new $job($import))->failed(new MaxAttemptsExceededException('x'));

    $import->refresh();

    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->abort_reason)->toBe(__('shared_identity.import.failed'))
        ->and($import->finished_at)->not->toBeNull();
})->with([
    'pacientes' => [PatientImport::class, ProcessPatientImportJob::class],
    'médicos'   => [DoctorImport::class, ProcessDoctorImportJob::class],
    'agenda'    => [ScheduleImport::class, ProcessScheduleImportJob::class],
]);
