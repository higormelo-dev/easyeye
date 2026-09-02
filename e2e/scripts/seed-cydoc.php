<?php

use App\Models\{Covenant, Doctor, Entity, MedicalRecord, Patient, People, Schedule};

// Seed do fluxo de ATENDIMENTO do médico (spec clinic.doctor):
// paciente CY-DOC + agendamento de HOJE com a Dra. Ana, pronto para
// "Iniciar atendimento". Idempotente e auto-limpante.
$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$ana = Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->firstOrFail();

// Limpa execuções anteriores (prontuários não assinados, agendamentos, paciente).
$peOld = People::withTrashed()->where('full_name', 'CY-DOC PACIENTE')->first();

if ($peOld) {
    $patOld = Patient::withTrashed()->where('person_id', $peOld->id)->first();

    if ($patOld) {
        $recs = MedicalRecord::withTrashed()->where('patient_id', $patOld->id)->get();

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

$pe  = People::create(['full_name' => 'CY-DOC PACIENTE', 'cellphone' => '11955554444']);
$cov = Covenant::first();
$pat = Patient::create([
    'entity_id'   => $ent->id, 'person_id' => $pe->id,
    'covenant_id' => $cov?->id, 'active' => true,
]);
// Slot livre pra Dra. Ana: a unique (doctor_id, date_time) entre ativos
// colide com a agenda demo local nos minutos "redondos" (14:45 etc.).
$slot = now()->startOfMinute();

for ($i = 0; $i < 30; $i++) {
    $busy = DB::table('schedules')->where('doctor_id', $ana->id)
        ->where('date_time', $slot->format('Y-m-d H:i:s'))
        ->whereNull('deleted_at')->where('active', true)->exists();

    if (! $busy) {
        break;
    }
    $slot = $slot->copy()->addMinute();
}
$sch = Schedule::create([
    'entity_id' => $ent->id, 'doctor_id' => $ana->id, 'patient_id' => $pat->id,
    'full_name' => 'CY-DOC PACIENTE', 'date_time' => $slot,
    'situation' => 2, 'active' => true,
]);
echo 'cydoc:', $sch->id;
