<?php

declare(strict_types=1);

use App\Enums\FeatureKey;
use App\Enums\{SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Entity, EntityIntegrator, EntityUser, EntityUserIntegrator, Patient, Subscription, User};
use App\Models\EntityIntegratorEquipment;
use Database\Seeders\{IntegratorTestClinicSeeder, PlanSeeder};
use Illuminate\Support\Facades\{Artisan, Hash};

/**
 * Clínica Teste Integrador em seeder próprio: também roda em PRODUÇÃO
 * (treinamento e demonstração). Lá não pode haver senha do repositório,
 * paciente fictício nem reset do que foi mudado pelo painel.
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

afterEach(fn () => app()->detectEnvironment(fn () => 'testing'));

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
