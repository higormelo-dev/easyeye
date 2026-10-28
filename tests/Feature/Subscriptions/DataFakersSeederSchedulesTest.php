<?php

declare(strict_types=1);

use App\Models\{Covenant, Entity, Patient, Schedule, VisitType};
use Carbon\Carbon;
use Database\Seeders\DataFakersSeeder;

afterEach(fn () => Carbon::setTestNow());

it('distribui pacientes entre seis médicos sem deixar o último sem agenda nem misturar clínicas', function () {
    Carbon::setTestNow('2026-10-08 07:00:00');
    $covenant = Covenant::firstOrCreate(['entity_id' => null, 'name' => 'PARTICULAR'], ['active' => true]);
    VisitType::firstOrCreate(['entity_id' => null, 'name' => 'CONSULTA'], ['active' => true]);
    $clinics = [];

    foreach (range(1, 2) as $index) {
        $clinic  = Entity::factory()->create(['is_client' => true, 'schedule_interval' => 15]);
        $doctors = [];

        foreach (range(1, 6) as $doctorIndex) {
            $doctor = createDoctorForEntity($clinic);
            $doctor->workSchedules()->create(['day_of_week' => 4, 'starts_at' => '08:00:00', 'ends_at' => '12:00:00']);
            $doctors[] = $doctor->id;
        }

        $patients  = Patient::factory()->count(20)->create(['entity_id' => $clinic->id, 'covenant_id' => $covenant->id, 'active' => true]);
        $clinics[] = [$clinic, $doctors, $patients->pluck('id')->all()];
    }

    (new ReflectionClass(DataFakersSeeder::class))->getMethod('createSchedules')->invoke(new DataFakersSeeder());

    foreach ($clinics as [$clinic, $doctors, $patients]) {
        $appointments = Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->whereDate('date_time', '2026-10-08')->get();
        expect($appointments)->toHaveCount(20)
            ->and($appointments->pluck('patient_id')->unique())->toHaveCount(20)
            ->and($appointments->groupBy('doctor_id')->map->count()->sort()->values()->all())->toBe([3, 3, 3, 3, 4, 4])
            ->and($appointments->whereNotIn('doctor_id', $doctors))->toBeEmpty()
            ->and($appointments->whereNotIn('patient_id', $patients))->toBeEmpty();
    }
});
