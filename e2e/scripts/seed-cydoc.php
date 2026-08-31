<?php
// Seed do fluxo de ATENDIMENTO do médico (spec clinic.doctor):
// paciente CY-DOC + agendamento de HOJE com a Dra. Ana, pronto para
// "Iniciar atendimento". Idempotente e auto-limpante.
$ent = App\Models\Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$ana = App\Models\Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->firstOrFail();

// Limpa execuções anteriores (prontuários não assinados, agendamentos, paciente).
$peOld = App\Models\People::withTrashed()->where('full_name', 'CY-DOC PACIENTE')->first();
if ($peOld) {
    $patOld = App\Models\Patient::withTrashed()->where('person_id', $peOld->id)->first();
    if ($patOld) {
        $recs = App\Models\MedicalRecord::withTrashed()->where('patient_id', $patOld->id)->get();
        foreach ($recs as $r) {
            DB::table('medical_record_documentations')->where('medical_record_id', $r->id)->delete();
            DB::table('medical_record_files')->where('medical_record_id', $r->id)->delete();
            $r->forceDelete();
        }
        DB::table('schedules')->where('patient_id', $patOld->id)->delete();
        $patOld->forceDelete();
    }
    $peOld->forceDelete();
}

$pe = App\Models\People::create(['full_name' => 'CY-DOC PACIENTE', 'cellphone' => '11955554444']);
$cov = App\Models\Covenant::first();
$pat = App\Models\Patient::create([
    'entity_id' => $ent->id, 'person_id' => $pe->id,
    'covenant_id' => $cov?->id, 'active' => true,
]);
$sch = App\Models\Schedule::create([
    'entity_id' => $ent->id, 'doctor_id' => $ana->id, 'patient_id' => $pat->id,
    'full_name' => 'CY-DOC PACIENTE', 'date_time' => now()->startOfMinute(),
    'situation' => 2, 'active' => true,
]);
echo 'cydoc:', $sch->id;
