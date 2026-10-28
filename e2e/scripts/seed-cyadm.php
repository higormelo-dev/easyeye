<?php

use App\Domains\Tiss\Models\{TissGlosa, TissOperator};
use App\Models\{Covenant, Doctor, Entity, Patient, People, Schedule};

// Seed do spec clinic.admin (cobertura total):
// - paciente CY-ADM + agendamento FALTOU hoje (relatório de absenteísmo);
// - glosa TISS aberta (fluxo "Recorrer" na conciliação de glosas).
// Idempotente: limpa execuções anteriores antes de criar.

require __DIR__ . '/clean-cyadm.php';

$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$ana = Doctor::whereHas('person', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->firstOrFail();

$pe  = People::create(['full_name' => 'CY-ADM PACIENTE', 'cellphone' => '11955553333']);
$cov = Covenant::where('entity_id', $ent->id)->first() ?? Covenant::firstOrFail();
$pat = Patient::create([
    'entity_id'   => $ent->id, 'person_id' => $pe->id,
    'covenant_id' => $cov?->id, 'active' => true,
]);

// Agendamento de HOJE marcado como Faltou (situation 8) — alimenta o
// relatório de absenteísmo do período corrente.
$sch = Schedule::create([
    'entity_id' => $ent->id, 'doctor_id' => $ana->id, 'patient_id' => $pat->id,
    'full_name' => 'CY-ADM PACIENTE', 'date_time' => now()->startOfHour(),
    'situation' => 8, 'active' => true,
]);

// Glosa TISS aberta (identificada hoje) — habilita o botão "Recorrer".
$op = TissOperator::firstOrFail();
$gl = TissGlosa::create([
    'entity_id'  => $ent->id, 'operator_id' => $op->id, 'status' => 'open',
    'glosa_code' => 'CY01', 'glosa_description' => 'CY-ADM GLOSA TESTE',
    'amount'     => 150.00, 'identified_at' => now(),
]);

echo 'cyadm:', $sch->id, ':', $gl->id;
