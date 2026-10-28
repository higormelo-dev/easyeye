<?php

use App\Models\{Doctor, Entity, EntityUser, People, User};

// Seed do médico de teste CY-MED (usado pela spec da secretária — a UI de
// cadastro é 403 para ela por design; ver clinic.secretary.cy.js).
$pe = People::updateOrCreate(
    ['email' => 'cymed@easyeye.test'],
    ['full_name' => 'CY-MED ESCALA', 'cellphone' => ''],
);
$u = User::updateOrCreate(
    ['email' => 'cymed@easyeye.test'],
    ['name' => 'CY-MED ESCALA', 'password' => 'CyMed@123456', 'email_verified_at' => now()],
);
$ent = Entity::where('name', 'like', '%TESTE INTEGRADOR%')->firstOrFail();
$eu  = EntityUser::updateOrCreate(
    ['entity_id' => $ent->id, 'user_id' => $u->id],
    ['rule' => 'doctor', 'active' => true],
);
$d = Doctor::withTrashed()->updateOrCreate(
    ['entity_user_id' => $eu->id],
    ['person_id' => $pe->id, 'record' => '990001', 'record_specialty' => '990001', 'color' => '#0a5c5c', 'partner' => false, 'active' => true, 'deleted_at' => null],
);
// Escala/bloqueios sempre zerados: o teste (e seus retries) começa limpo.
DB::table('doctor_work_schedules')->where('doctor_id', $d->id)->delete();
DB::table('schedule_blocks')->where('doctor_id', $d->id)->delete();
echo 'cymed:', $d->id;
