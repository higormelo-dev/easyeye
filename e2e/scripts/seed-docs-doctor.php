<?php

use App\Models\{Covenant, Doctor, Entity, Patient, People, Schedule};

// Seed para as CAPTURAS do manual do médico: paciente com nome apresentável
// + agendamento de hoje com a Dra. Ana pronto para "Iniciar atendimento".
// Idempotente; limpeza: clean-docs-doctor.php.

$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$ana = Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->firstOrFail();

require __DIR__ . '/clean-docs-doctor.php';

$pe  = People::create(['full_name' => 'MARIANA COSTA E SILVA', 'cellphone' => '11998761234']);
$cov = Covenant::where('entity_id', $ent->id)->first() ?? Covenant::firstOrFail();
$pat = Patient::create([
    'entity_id'   => $ent->id, 'person_id' => $pe->id,
    'covenant_id' => $cov->id, 'active' => true,
]);
// Slot livre pra Dra. Ana (mesma trava de seed-cydoc.php: unique
// doctor_id+date_time colide com a agenda demo nos minutos redondos).
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
    'full_name' => 'MARIANA COSTA E SILVA', 'date_time' => $slot,
    'situation' => 2, 'active' => true,
]);
echo 'docsdoc:', $sch->id;
