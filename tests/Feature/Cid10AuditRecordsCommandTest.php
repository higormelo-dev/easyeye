<?php

use App\Models\{Entity, MedicalRecord, Patient, PatientExam};
use Illuminate\Support\Facades\{Artisan, DB};

/**
 * cid10:audit-records — levantamento SÓ LEITURA dos registros que usaram um
 * código cuja descrição antiga no catálogo apontava para outra doença.
 */
function cidAuditRecord(Entity $entity, array $cids, bool $signed = false): MedicalRecord
{
    $record = MedicalRecord::create([
        'entity_id'      => $entity->id,
        'patient_id'     => Patient::factory()->create(['entity_id' => $entity->id])->id,
        'doctor_id'      => createDoctorForEntity($entity)->id,
        'diagnosis_cids' => $cids,
    ]);

    if ($signed) {
        DB::table('medical_records')->where('id', $record->id)->update(['signed_at' => now()]);
    }

    return $record->fresh();
}

it('conta por clínica prontuários e exames com os códigos afetados, sem alterar nada nem expor paciente', function () {
    $visao = Entity::factory()->create(['name' => 'Clínica Visão', 'is_client' => true]);
    $sul   = Entity::factory()->create(['name' => 'Ótica Sul', 'is_client' => true]);

    $signed = cidAuditRecord($visao, [['code' => 'H50.5', 'description' => 'Estrabismo paralítico']], signed: true);
    $open   = cidAuditRecord($visao, [['code' => 'H40.1', 'description' => 'Glaucoma'], ['code' => 'H50.5', 'description' => 'Estrabismo paralítico']]);
    cidAuditRecord($sul, [['code' => 'H40.1', 'description' => 'Glaucoma']]);
    $exam = PatientExam::factory()->create([
        'patient_id'     => Patient::factory()->create(['entity_id' => $sul->id])->id,
        'diagnosis_cids' => [['code' => 'B00.3', 'description' => 'Doença ocular herpética']],
    ]);
    $before = DB::table('medical_records')->orderBy('id')->get()->toJson() . DB::table('patient_exams')->orderBy('id')->get()->toJson();

    Artisan::call('cid10:audit-records', ['--details' => true]);
    $out = Artisan::output();

    expect($out)
        ->toMatch('/Clínica Visão\s*\|\s*H50\.5\s*\|\s*prontuário\s*\|\s*2\s*\|\s*1/iu')
        ->toMatch('/Ótica Sul\s*\|\s*B00\.3\s*\|\s*exame\s*\|\s*1\s*\|\s*0/iu')
        ->toContain('H50.5: oficial "Heteroforia" — o catálogo mostrava "Estrabismo paralítico"')
        ->toContain($signed->code)->toContain($open->code)->toContain($exam->code)
        // o prontuário da Ótica Sul só tem H40.1: não aparece (o código PMR é por clínica)
        ->not->toMatch('/Ótica Sul\s*\|\s*prontuário/iu')
        ->not->toContain($signed->patient->person->full_name)
        // nada mudou
        ->and(DB::table('medical_records')->orderBy('id')->get()->toJson() . DB::table('patient_exams')->orderBy('id')->get()->toJson())->toBe($before);
});

it('sem registros afetados, só informa', function () {
    Artisan::call('cid10:audit-records');

    expect(Artisan::output())->toContain('Nada a revisar');
});
