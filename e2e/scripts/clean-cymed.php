<?php

use App\Models\{Doctor, EntityUser, People, User};

// Limpeza total do médico de teste CY-MED (escala, bloqueios, vínculo, user).
$pe = People::withTrashed()->where('email', 'cymed@easyeye.test')->first();

if ($pe) {
    $d = Doctor::withTrashed()->where('person_id', $pe->id)->first();

    if ($d) {
        DB::table('doctor_work_schedules')->where('doctor_id', $d->id)->delete();
        DB::table('schedule_blocks')->where('doctor_id', $d->id)->delete();
        $euId = $d->entity_user_id;
        $d->forceDelete();
        EntityUser::withTrashed()->where('id', $euId)->forceDelete();
    }
    User::withTrashed()->where('email', 'cymed@easyeye.test')->forceDelete();
    $pe->forceDelete();
}
echo 'limpo';
