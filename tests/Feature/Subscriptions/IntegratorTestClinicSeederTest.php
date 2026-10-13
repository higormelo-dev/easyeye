<?php

declare(strict_types=1);

use App\Enums\FeatureKey;
use App\Enums\{ScheduleSituation, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Covenant, Doctor, DoctorWorkSchedule, Entity, EntityIntegrator, EntityUser, EntityUserIntegrator, Patient, People, Schedule, Subscription, User, VisitType};
use App\Models\EntityIntegratorEquipment;
use App\Services\Schedules\ScheduleAvailabilityService;
use App\Services\ScheduleService;
use Carbon\Carbon;
use Database\Seeders\{DataFakersSeeder, IntegratorTestClinicSeeder, PlanSeeder};
use Illuminate\Support\Facades\{Artisan, Event, Hash};

/**
 * Clínica Teste Integrador em seeder próprio: também roda em PRODUÇÃO
 * (treinamento e demonstração). Lá não pode haver senha do repositório,
 * paciente fictício nem reset do que foi mudado pelo painel.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

afterEach(fn () => app()->detectEnvironment(fn () => 'testing'));
afterEach(fn () => Carbon::setTestNow());

function itcClinic(): Entity
{
    return Entity::where('subdomain', IntegratorTestClinicSeeder::ENTITY_SUBDOMAIN)->firstOrFail();
}

function itcRunAs(string $env): string
{
    app()->detectEnvironment(fn () => $env);
    Artisan::call('db:seed', ['--class' => IntegratorTestClinicSeeder::class, '--force' => true]);

    return Artisan::output();
}

it('local/testing: credenciais fixas, equipe completa, cortesia e pacientes de exemplo', function () {
    itcRunAs('testing');
    $clinic = itcClinic();

    expect(Hash::check('Admin@123', User::firstWhere('email', 'admin@clinicateste.com')->password))->toBeTrue()
        ->and(Hash::check('Integrador@123', EntityUserIntegrator::firstWhere('email', 'integrador@teste.com')->password))->toBeTrue()
        ->and(EntityUser::where('entity_id', $clinic->id)->pluck('rule')->sort()->values()->all())
        ->toBe(['admin', 'doctor', 'doctor', 'financial', 'secretary'])
        ->and(EntityIntegrator::whereIn('mac', ['AA:BB:CC:DD:EE:01', 'AA:BB:CC:DD:EE:02'])->count())->toBe(2)
        ->and(Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->count())->toBe(20);

    $subscription = Subscription::query()->forEntity((string) $clinic->id)->inForce()->sole();
    expect($subscription->billing_mode)->toBe(SubscriptionBillingMode::Complimentary)
        ->and($subscription->status)->toBe(SubscriptionStatus::Active)
        ->and($subscription->plan->featureValue(FeatureKey::HasApiIntegrator))->toBe('1');
});

it('produção: senha aleatória forte mostrada uma vez, sem pacientes; rodar de novo não troca senha nem duplica', function () {
    $output = itcRunAs('production');
    $clinic = itcClinic();
    $admin  = User::firstWhere('email', 'admin@clinicateste.com');

    // Nenhuma senha do repositório em produção.
    expect(Hash::check('Admin@123', $admin->password))->toBeFalse()
        ->and(Hash::check('Integrador@123', EntityUserIntegrator::firstWhere('email', 'integrador@teste.com')->password))->toBeFalse()
        ->and($output)->toContain('admin@clinicateste.com')->not->toContain('Admin@123')
        ->and(Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->exists())->toBeFalse();
    expect(Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->exists())->toBeFalse();

    // A senha mostrada é a que funciona.
    preg_match('/admin · admin@clinicateste\.com · (\S+)/', $output, $m);
    expect($m[1] ?? null)->not->toBeNull()
        ->and(strlen($m[1]))->toBe(24)
        ->and(Hash::check($m[1], $admin->password))->toBeTrue();

    // Mudanças feitas pelo painel sobrevivem a uma nova execução.
    $admin->update(['password' => Hash::make('Trocada#2026')]);
    $clinic->update(['name' => 'EasyEye Demo']);

    $again = itcRunAs('production');

    expect(Hash::check('Trocada#2026', $admin->fresh()->password))->toBeTrue()
        ->and($clinic->fresh()->name)->toBe('EASYEYE DEMO')
        ->and($again)->toContain('senhas mantidas')->not->toMatch('/admin@clinicateste\.com · \S{24}/')
        ->and(Entity::where('subdomain', IntegratorTestClinicSeeder::ENTITY_SUBDOMAIN)->count())->toBe(1)
        ->and(EntityUser::where('entity_id', $clinic->id)->count())->toBe(5)
        ->and(Subscription::query()->forEntity((string) $clinic->id)->inForce()->count())->toBe(1);
});

it('clínica nova nasce em cortesia, sem trial automático (fora das métricas de trial)', function () {
    itcRunAs('production');

    expect(Subscription::query()->forEntity((string) itcClinic()->id)->pluck('status')->all())
        ->toBe([SubscriptionStatus::Active]);
});

it('clínica já existente em trial automático (seed antigo) passa para cortesia', function () {
    $clinic = Entity::factory()->create(['subdomain' => IntegratorTestClinicSeeder::ENTITY_SUBDOMAIN, 'is_client' => true, 'active' => true]);
    expect(Subscription::query()->forEntity((string) $clinic->id)->inForce()->sole()->status)->toBe(SubscriptionStatus::Trial);

    itcRunAs('production');

    expect(Subscription::query()->forEntity((string) $clinic->id)->inForce()->sole()->billing_mode)->toBe(SubscriptionBillingMode::Complimentary);
});

it('nunca apaga a assinatura em vigor (ex.: período estendido pelo manager)', function () {
    itcRunAs('production');
    $clinic       = itcClinic();
    $subscription = Subscription::query()->forEntity((string) $clinic->id)->inForce()->sole();
    $subscription->update(['ends_at' => now()->addYears(3)]);

    itcRunAs('production');

    expect(Subscription::query()->forEntity((string) $clinic->id)->inForce()->sole()->id)->toBe($subscription->id)
        ->and($subscription->fresh()->ends_at->year)->toBe(now()->addYears(3)->year);
});

it('a clínica segue sem equipamentos cadastrados (regra da API de integradores)', function () {
    itcRunAs('testing');
    $integrator = EntityIntegrator::firstWhere('mac', 'AA:BB:CC:DD:EE:01');
    EntityIntegratorEquipment::factory()->create(['integrator_id' => $integrator->id]);

    itcRunAs('testing');

    expect(EntityIntegratorEquipment::withTrashed()->where('integrator_id', $integrator->id)->exists())->toBeFalse();
});

it('clinics:ensure-financial-profiles fora de local/testing não cria o financeiro com a senha fixa', function () {
    itcRunAs('production');
    $clinic = itcClinic();
    EntityUser::where('entity_id', $clinic->id)->where('rule', 'financial')->delete();
    $before = User::firstWhere('email', 'financeiro@clinicateste.com')->password;

    app()->detectEnvironment(fn () => 'production');
    Artisan::call('clinics:ensure-financial-profiles', ['--force' => true]);

    expect(Artisan::output())->toContain('IntegratorTestClinicSeeder')
        ->and(User::firstWhere('email', 'financeiro@clinicateste.com')->password)->toBe($before)
        ->and(EntityUser::where('entity_id', $clinic->id)->where('rule', 'financial')->exists())->toBeFalse();
});

it('prepara o vínculo do responsável e o cadastro médico com escala, reunião e ausência', function (string $environment) {
    Carbon::setTestNow('2026-10-08 09:00:00');
    itcRunAs($environment);
    $clinic = itcClinic();
    $admin  = $clinic->entityUsers()->where('rule', 'admin')->sole();

    expect($admin->is_owner)->toBeTrue()
        ->and($admin->joined_at)->not->toBeNull()
        ->and($clinic->locale)->toBe('pt_BR')
        ->and($clinic->email)->toBe('admin@clinicateste.com');

    $doctors = Doctor::ofEntity((string) $clinic->id)->with('person')->get();
    expect($doctors)->toHaveCount(2)
        ->and($doctors->pluck('person.national_registry')->unique())->toHaveCount(2);

    foreach ($doctors as $doctor) {
        expect($doctor->person->nickname)->not->toBeEmpty()
            ->and($doctor->workSchedules()->count())->toBe(10)
            ->and($doctor->effectiveInterval())->toBe($clinic->schedule_interval)
            ->and($doctor->fitInLimits())->toBe(['morning' => 2, 'afternoon' => 2, 'evening' => 2])
            ->and($doctor->scheduleBlocks()->pluck('type')->sort()->values()->all())->toBe(['absence', 'meeting']);

        $service  = app(ScheduleService::class);
        $existing = Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->where('doctor_id', $doctor->id);
        expect($service->validateSlot((string) $doctor->id, Carbon::parse('2026-10-12 08:00:00'), (clone $existing)->where('date_time', '2026-10-12 08:00:00')->value('id')))->toBe([])
            ->and($service->validateSlot((string) $doctor->id, Carbon::parse('2026-10-12 12:00:00')))->not->toBeEmpty()
            ->and($service->validateSlot((string) $doctor->id, Carbon::parse('2026-10-10 08:00:00')))->not->toBeEmpty()
            ->and($service->validateSlot((string) $doctor->id, Carbon::parse('2026-10-12 10:00:00')))->toContain(__('actions.work_schedule_blocked'))
            ->and($service->validateSlot((string) $doctor->id, Carbon::parse('2026-10-13 08:00:00')))->toContain(__('actions.work_schedule_blocked'))
            ->and($service->validateSlot((string) $doctor->id, Carbon::parse('2026-10-12 11:00:00'), (clone $existing)->where('date_time', '2026-10-12 11:00:00')->value('id')))->toBe([]);

        $availability = app(ScheduleAvailabilityService::class)->month((string) $clinic->id, now()->startOfMonth(), (string) $doctor->id);
        expect($availability['days']['2026-10-13']['state'])->toBe('blocked')
            ->and($availability['days']['2026-10-10']['state'])->toBe('off')
            ->and($availability['days']['2026-10-12']['capacity'])->toBe(28);
    }
})->with(['testing', 'production']);

it('reexecução preserva escala parcial, intervalo, encaixes e bloqueios editados ou removidos', function () {
    Carbon::setTestNow('2026-10-08 09:00:00');
    itcRunAs('testing');
    $clinic = itcClinic();
    $doctor = Doctor::ofEntity((string) $clinic->id)->firstOrFail();
    $other  = Doctor::ofEntity((string) $clinic->id)->whereKeyNot($doctor->id)->firstOrFail();

    $doctor->workSchedules()->delete();
    $range = $doctor->workSchedules()->create(['day_of_week' => 3, 'starts_at' => '09:00:00', 'ends_at' => '11:00:00']);
    $doctor->update(['schedule_interval' => 30, 'fit_in_limit_morning' => 0, 'active' => true]);
    $meeting = $doctor->scheduleBlocks()->where('type', 'meeting')->sole();
    $meeting->update(['reason' => 'Compromisso alterado pelo painel', 'ends_at' => $meeting->ends_at->addHour()]);
    $other->scheduleBlocks()->delete();
    $personId = $doctor->person_id;

    Carbon::setTestNow('2026-11-08 09:00:00');
    itcRunAs('testing');

    expect($doctor->workSchedules()->sole()->id)->toBe($range->id)
        ->and($doctor->fresh()->schedule_interval)->toBe(30)
        ->and($doctor->fresh()->fit_in_limit_morning)->toBe(0)
        ->and($doctor->fresh()->person_id)->toBe($personId)
        ->and($meeting->fresh()->reason)->toBe('Compromisso alterado pelo painel')
        ->and($meeting->fresh()->ends_at->format('H:i'))->toBe('12:00')
        ->and($doctor->scheduleBlocks()->count())->toBe(2)
        ->and($other->scheduleBlocks()->count())->toBe(0)
        ->and(Doctor::ofEntity((string) $clinic->id)->count())->toBe(2)
        ->and(Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->count())->toBe(20);
});

it('completa médicos do seed antigo sem alterar bloqueios e dados pessoais já preenchidos', function () {
    itcRunAs('testing');
    $admin = itcClinic()->entityUsers()->where('rule', 'admin')->sole();
    $admin->update(['is_owner' => false, 'joined_at' => null]);
    $doctor = Doctor::ofEntity((string) itcClinic()->id)->firstOrFail();
    $doctor->workSchedules()->delete();
    $blocks = $doctor->scheduleBlocks()->pluck('id')->all();
    $doctor->person->update(['nickname' => null, 'national_registry' => null, 'full_name' => 'Nome mantido']);
    $doctor->update(['schedule_interval' => 30]);

    itcRunAs('testing');

    expect($doctor->workSchedules()->count())->toBe(10)
        ->and($admin->fresh()->is_owner)->toBeTrue()
        ->and($admin->fresh()->joined_at->equalTo($admin->created_at))->toBeTrue()
        ->and($doctor->scheduleBlocks()->pluck('id')->all())->toBe($blocks)
        ->and($doctor->fresh()->schedule_interval)->toBe(30)
        ->and($doctor->person->fresh()->nickname)->toBe('NOME MANTIDO')
        ->and($doctor->person->fresh()->national_registry)->not->toBeEmpty();
});

it('preserva o responsável definido pelo painel e pessoas compartilhadas do seed legado', function () {
    itcRunAs('testing');
    $clinic = itcClinic();
    $admin  = $clinic->entityUsers()->where('rule', 'admin')->sole();
    $owner  = $clinic->entityUsers()->where('rule', 'financial')->sole();
    $admin->update(['is_owner' => false]);
    $owner->update(['is_owner' => true]);
    $doctor = Doctor::ofEntity((string) $clinic->id)->firstOrFail();
    $doctor->person->update(['nickname' => null, 'national_registry' => null]);
    $otherClinic = Entity::factory()->create(['is_client' => true]);
    $otherDoctor = createDoctorForEntity($otherClinic);
    $otherDoctor->update(['person_id' => $doctor->person_id]);

    itcRunAs('testing');

    expect($admin->fresh()->is_owner)->toBeFalse()
        ->and($owner->fresh()->is_owner)->toBeTrue()
        ->and($doctor->person->fresh()->nickname)->toBeNull()
        ->and($otherDoctor->person->fresh()->national_registry)->toBeNull();
});

it('não reaproveita pessoa de outra clínica pelo e-mail e só usa convênios ativos acessíveis', function () {
    $foreignPerson = People::factory()->create(['email' => 'dra.ana@clinicateste.com']);
    $foreignClinic = Entity::factory()->create(['is_client' => true]);
    $foreignDoctor = createDoctorForEntity($foreignClinic);
    $foreignDoctor->update(['person_id' => $foreignPerson->id]);
    Covenant::factory()->create(['entity_id' => $foreignClinic->id, 'active' => true]);
    Covenant::factory()->create(['entity_id' => null, 'active' => false]);
    Covenant::factory()->create(['entity_id' => null, 'active' => true]);
    $allowed = Covenant::whereNull('entity_id')->where('active', true)->pluck('id')->all();

    itcRunAs('testing');
    $clinic = itcClinic();
    $ana    = Doctor::ofEntity((string) $clinic->id)->whereHas('entityUser.user', fn ($q) => $q->where('email', 'dra.ana@clinicateste.com'))->sole();

    expect($ana->person_id)->not->toBe($foreignPerson->id)
        ->and($foreignDoctor->fresh()->person_id)->toBe($foreignPerson->id)
        ->and($foreignDoctor->workSchedules()->count())->toBe(0)
        ->and($foreignDoctor->scheduleBlocks()->count())->toBe(0)
        ->and(Patient::withoutGlobalScopes()->where('entity_id', $clinic->id)->whereNotIn('covenant_id', $allowed)->exists())->toBeFalse();
});

it('não recria médicos excluídos nem configura a agenda de vínculos inativos', function () {
    itcRunAs('testing');
    $doctors = Doctor::ofEntity((string) itcClinic()->id)->get();
    $deleted = $doctors[0];
    $deleted->delete();
    $inactive = $doctors[1];
    $inactive->workSchedules()->delete();
    $inactive->entityUser->update(['active' => false]);

    itcRunAs('testing');

    expect($deleted->fresh()->trashed())->toBeTrue()
        ->and(Doctor::withTrashed()->where('entity_user_id', $deleted->entity_user_id)->count())->toBe(1)
        ->and($inactive->workSchedules()->count())->toBe(0);
});

it('uma falha na configuração da escala desfaz o cadastro inteiro', function () {
    Event::listen('eloquent.creating: ' . DoctorWorkSchedule::class, function () {
        throw new RuntimeException('Falha simulada ao gravar a escala');
    });

    expect(fn () => itcRunAs('testing'))->toThrow(RuntimeException::class, 'Falha simulada ao gravar a escala');

    expect(Entity::where('subdomain', IntegratorTestClinicSeeder::ENTITY_SUBDOMAIN)->exists())->toBeFalse()
        ->and(User::where('email', 'admin@clinicateste.com')->exists())->toBeFalse()
        ->and(People::where('email', 'dra.ana@clinicateste.com')->exists())->toBeFalse()
        ->and(DoctorWorkSchedule::count())->toBe(0)
        ->and(Subscription::count())->toBe(0);
});

it('a massa da agenda respeita escala, intervalo do médico, ausência e reunião', function () {
    Carbon::setTestNow('2026-10-08 09:00:00');
    Covenant::factory()->create(['entity_id' => null, 'active' => true]);
    $allowed = Covenant::whereNull('entity_id')->where('active', true)->pluck('id')->all();
    VisitType::firstOrCreate(['entity_id' => null, 'name' => 'CONSULTA'], ['active' => true]);
    itcRunAs('testing');
    $clinic  = itcClinic();
    $doctors = Doctor::ofEntity((string) $clinic->id)->get();

    // Exercita uma geração nova com intervalo personalizado, sem a agenda
    // que o seeder isolado já preparou com o intervalo padrão de 15 minutos.
    Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->forceDelete();

    foreach ($doctors as $doctor) {
        $doctor->update(['schedule_interval' => 30]);
    }

    $service = app(ScheduleAvailabilityService::class);
    $doctor  = $doctors->first();
    $before  = $service->month((string) $clinic->id, now()->startOfMonth(), (string) $doctor->id);
    $version = ScheduleAvailabilityService::version((string) $clinic->id);

    app(DataFakersSeeder::class)->seedClinicSchedules($clinic);

    $schedules = Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->get();
    $after     = $service->month((string) $clinic->id, now()->startOfMonth(), (string) $doctor->id);
    expect($schedules)->not->toBeEmpty()
        ->and(ScheduleAvailabilityService::version((string) $clinic->id))->not->toBe($version)
        ->and($after['days']['2026-10-12']['free'])->toBeLessThan($before['days']['2026-10-12']['free'])
        ->and($schedules->contains(fn (Schedule $s) => $s->date_time->format('Y-m-d H:i') === '2026-10-12 11:00'))->toBeTrue()
        ->and($schedules->contains(fn (Schedule $s) => $s->date_time->toDateString() === '2026-10-13'))->toBeFalse();

    $service = app(ScheduleService::class);

    foreach ($schedules as $schedule) {
        expect($service->isWithinWorkSchedule((string) $schedule->doctor_id, $schedule->date_time))->toBeTrue()
            ->and($service->isIntervalAligned((string) $schedule->doctor_id, $schedule->date_time))->toBeTrue()
            ->and($service->isBlocked((string) $schedule->doctor_id, $schedule->date_time))->toBeFalse()
            ->and($schedule->covenant_id)->toBeIn($allowed);
    }
});

it('o seeder isolado distribui a agenda entre todos os médicos com o intervalo padrão', function (string $environment) {
    Carbon::setTestNow('2026-10-08 07:00:00');
    itcRunAs($environment);
    $clinic    = itcClinic();
    $doctors   = Doctor::ofEntity((string) $clinic->id)->get();
    $schedules = Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)
        ->whereDate('date_time', '2026-10-08')->get();

    expect($schedules)->toHaveCount(20)
        ->and($schedules->pluck('patient_id')->unique())->toHaveCount(20)
        ->and($schedules->pluck('code')->unique())->toHaveCount(20);

    foreach ($doctors as $doctor) {
        $appointments = $schedules->where('doctor_id', $doctor->id);
        expect($doctor->effectiveInterval())->toBe(15)
            ->and($appointments)->toHaveCount(10)
            ->and($appointments->every(fn (Schedule $schedule) => $schedule->active && $schedule->situation === ScheduleSituation::Scheduled))->toBeTrue();
    }
})->with(['local', 'testing']);

it('completa apenas o médico sem agenda e preserva agendamentos existentes na reexecução', function () {
    Carbon::setTestNow('2026-10-08 07:00:00');
    itcRunAs('testing');
    $clinic  = itcClinic();
    $doctors = Doctor::ofEntity((string) $clinic->id)->get();
    $first   = $doctors[0];
    $missing = $doctors[1];
    Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->where('doctor_id', $missing->id)->forceDelete();
    $existing = Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->where('doctor_id', $first->id)->orderBy('code')->get();
    $custom   = $existing->first();
    $custom->update(['notes' => 'Alterado pelo painel']);

    itcRunAs('testing');

    expect(Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->where('doctor_id', $first->id)->orderBy('code')->pluck('id')->all())->toBe($existing->pluck('id')->all())
        ->and($custom->fresh()->notes)->toBe('Alterado pelo painel')
        ->and(Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->where('doctor_id', $missing->id)->exists())->toBeTrue();

    $ids = Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->orderBy('code')->pluck('id')->all();
    itcRunAs('testing');
    // A massa geral do DatabaseSeeder também não duplica a agenda reservada.
    (new ReflectionClass(DataFakersSeeder::class))->getMethod('createSchedules')->invoke(new DataFakersSeeder());

    expect(Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->orderBy('code')->pluck('id')->all())->toBe($ids);
});

it('agenda médicos com escala parcial e não cria horários durante bloqueios de dia inteiro', function () {
    Carbon::setTestNow('2026-10-08 07:00:00');
    itcRunAs('testing');
    $clinic  = itcClinic();
    $doctors = Doctor::ofEntity((string) $clinic->id)->get();
    $partial = $doctors[0];
    $partial->workSchedules()->delete();
    $partial->workSchedules()->create(['day_of_week' => 4, 'starts_at' => '14:00:00', 'ends_at' => '16:00:00']);
    $doctors[1]->scheduleBlocks()->create([
        'starts_at' => '2026-10-08 00:00:00', 'ends_at' => '2026-10-09 00:00:00', 'type' => 'absence',
    ]);
    Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->forceDelete();

    itcRunAs('testing');

    foreach ($doctors as $doctor) {
        $appointments = Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->where('doctor_id', $doctor->id)->get();
        expect($appointments)->not->toBeEmpty();

        foreach ($appointments as $appointment) {
            expect(app(ScheduleService::class)->validateSlot((string) $doctor->id, $appointment->date_time, (string) $appointment->id))->toBe([]);
        }
    }

    expect(Schedule::withoutGlobalScopes()->where('entity_id', $clinic->id)->where('doctor_id', $doctors[1]->id)->whereDate('date_time', '2026-10-08')->exists())->toBeFalse();
});
