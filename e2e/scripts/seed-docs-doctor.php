<?php
// Seed para as CAPTURAS do manual do médico: paciente com nome apresentável
// + agendamento de hoje com a Dra. Ana pronto para "Iniciar atendimento".
// Idempotente; limpeza: clean-docs-doctor.php.

$ent = App\Models\Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$ana = App\Models\Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->firstOrFail();

require __DIR__ . '/clean-docs-doctor.php';

$pe  = App\Models\People::create(['full_name' => 'MARIANA COSTA E SILVA', 'cellphone' => '11998761234']);
$cov = App\Models\Covenant::where('entity_id', $ent->id)->first() ?? App\Models\Covenant::firstOrFail();
$pat = App\Models\Patient::create([
    'entity_id' => $ent->id, 'person_id' => $pe->id,
    'covenant_id' => $cov->id, 'active' => true,
]);
$sch = App\Models\Schedule::create([
    'entity_id' => $ent->id, 'doctor_id' => $ana->id, 'patient_id' => $pat->id,
    'full_name' => 'MARIANA COSTA E SILVA', 'date_time' => now()->startOfMinute(),
    'situation' => 2, 'active' => true,
]);
echo 'docsdoc:', $sch->id;
