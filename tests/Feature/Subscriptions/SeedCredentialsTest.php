<?php

declare(strict_types=1);

use App\Models\{Entity, EntityUser, EntityUserIntegrator, User};
use App\Support\{AuditContext, SeedCredentials};
use Database\Seeders\{EntityAndUserAdministratorSeeder, IntegratorTestClinicSeeder, PlanSeeder};
use Illuminate\Support\Facades\{Artisan, DB, Hash};
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Decisão do dono (revisão final): senha fixa do repositório só em dev local
 * e nos testes automatizados (SEED_FIXED_CREDENTIALS); homologação — que roda
 * APP_ENV=testing — e produção recebem senha aleatória mostrada uma vez, e a
 * senha fixa de um seed antigo é substituída (com as sessões derrubadas).
 */
beforeEach(function () {
    $this->seed(PlanSeeder::class);
});

afterEach(function () {
    // O EntityAndUserAdministratorSeeder fixa o autor da auditoria (estático):
    // não pode vazar para o próximo teste (usuário apagado no rollback).
    AuditContext::setUserId(null);
    app()->detectEnvironment(fn () => 'testing');
    config(['app.seed_fixed_credentials' => true]);
});

const SC_STAFF = [
    'admin@clinicateste.com'      => 'Admin@123',
    'dra.ana@clinicateste.com'    => 'Medico@123',
    'dr.carlos@clinicateste.com'  => 'Medico@123',
    'secretaria@clinicateste.com' => 'Secretaria@123',
    'financeiro@clinicateste.com' => 'Financeiro@123',
];

/** Roda o seeder como em $env; $fixed = SEED_FIXED_CREDENTIALS. */
function scSeed(string $env, bool $fixed, string $class = IntegratorTestClinicSeeder::class): string
{
    app()->detectEnvironment(fn () => $env);
    config(['app.seed_fixed_credentials' => $fixed]);
    Artisan::call('db:seed', ['--class' => $class, '--force' => true]);

    return Artisan::output();
}

/** @return array<string, string> e-mail => senha impressa */
function scPrinted(string $output): array
{
    preg_match_all('/^\s+\w+ · (\S+@\S+) · (\S+)/m', $output, $m, PREG_SET_ORDER);

    return collect($m)->mapWithKeys(fn ($row) => [$row[1] => $row[2]])->all();
}

describe('modo das senhas', function () {
    it('fixa só em local ou com SEED_FIXED_CREDENTIALS; produção nunca', function () {
        $cases = [['local', false, true], ['testing', true, true], ['testing', false, false], ['staging', false, false], ['production', true, false]];

        foreach ($cases as [$env, $key, $expected]) {
            app()->detectEnvironment(fn () => $env);
            config(['app.seed_fixed_credentials' => $key]);

            expect(SeedCredentials::fixed())->toBe($expected, "{$env} / chave " . var_export($key, true));
        }
    });

    it('P7: a senha gerada sai do console exatamente como é (2.000 gerações)', function () {
        $formatter = new OutputFormatter(false);

        for ($i = 0; $i < 2000; $i++) {
            $password = SeedCredentials::generate();
            $line     = "  financial · e@x · {$password}";

            expect($formatter->format($line))->toBe($line)
                ->and(strlen($password))->toBe(24);
        }
    });
});

describe('IntegratorTestClinicSeeder fora do modo fixo', function () {
    it('homologação (APP_ENV=testing sem a chave): senhas aleatórias, e a impressa é a que funciona', function () {
        $printed = scPrinted(scSeed('testing', false));

        expect(array_keys($printed))->toEqualCanonicalizing([...array_keys(SC_STAFF), 'integrador@teste.com']);

        foreach (SC_STAFF as $email => $fixed) {
            $hash = User::firstWhere('email', $email)->password;

            expect(Hash::check($fixed, $hash))->toBeFalse()
                ->and(Hash::check($printed[$email], $hash))->toBeTrue();
        }

        expect(Hash::check($printed['integrador@teste.com'], EntityUserIntegrator::firstWhere('email', 'integrador@teste.com')->password))->toBeTrue();
    });

    it('P8: banco com o seed antigo (senhas fixas) — produção substitui, mostra uma vez e derruba sessões/tokens', function () {
        scSeed('testing', true); // seed antigo / dev: senhas fixas

        $admin      = User::firstWhere('email', 'admin@clinicateste.com');
        $integrator = EntityUserIntegrator::firstWhere('email', 'integrador@teste.com');
        $integrator->createToken('integrator-token');
        $admin->forceFill(['remember_token' => 'lembrar-antigo'])->saveQuietly();

        config(['session.driver' => 'database', 'session.connection' => null]);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $admin->id, 'payload' => 'x', 'last_activity' => now()->timestamp]);

        $output  = scSeed('production', true);
        $printed = scPrinted($output);

        expect($output)->toContain('senha fixa antiga substituída')
            ->and(Hash::check('Admin@123', $admin->fresh()->password))->toBeFalse()
            ->and(Hash::check('Financeiro@123', User::firstWhere('email', 'financeiro@clinicateste.com')->password))->toBeFalse()
            ->and(Hash::check('Integrador@123', $integrator->fresh()->password))->toBeFalse()
            ->and(Hash::check($printed['admin@clinicateste.com'], $admin->fresh()->password))->toBeTrue()
            ->and(Hash::check($printed['integrador@teste.com'], $integrator->fresh()->password))->toBeTrue()
            ->and($admin->fresh()->remember_token)->not->toBe('lembrar-antigo')
            ->and(DB::table('sessions')->where('user_id', $admin->id)->exists())->toBeFalse()
            ->and($integrator->tokens()->count())->toBe(0);

        // Rodar de novo não troca nada (a senha já não é a do repositório).
        $again = scSeed('production', true);
        expect($again)->toContain('senhas mantidas')
            ->and(Hash::check($printed['admin@clinicateste.com'], $admin->fresh()->password))->toBeTrue();
    });

    it('homologação com o seed antigo também tem as senhas fixas substituídas', function () {
        scSeed('testing', true);
        $output = scSeed('testing', false);

        expect($output)->toContain('senha fixa antiga substituída')
            ->and(Hash::check('Secretaria@123', User::firstWhere('email', 'secretaria@clinicateste.com')->password))->toBeFalse();
    });
});

describe('EntityAndUserAdministratorSeeder', function () {
    it('idempotente: rodar duas vezes não duplica nem aborta (modo fixo usa a senha do repositório)', function () {
        scSeed('testing', true, EntityAndUserAdministratorSeeder::class);
        scSeed('testing', true, EntityAndUserAdministratorSeeder::class);

        $entity = Entity::firstWhere('subdomain', 'medicalgroup');

        expect(User::whereIn('email', ['higor_ap89@icloud.com', 'joao9@adachioftalmologia.com.br'])->count())->toBe(2)
            ->and(Entity::where('subdomain', 'medicalgroup')->count())->toBe(1)
            ->and(EntityUser::where('entity_id', $entity->id)->where('rule', 'admin')->count())->toBe(2)
            ->and(Hash::check('Admin@2024!', User::firstWhere('email', 'higor_ap89@icloud.com')->password))->toBeTrue();
    });

    it('produção, banco novo: senha aleatória mostrada uma vez (a impressa funciona)', function () {
        $printed = scPrinted(scSeed('production', false, EntityAndUserAdministratorSeeder::class));
        $higor   = User::firstWhere('email', 'higor_ap89@icloud.com');

        expect(Hash::check('Admin@2024!', $higor->password))->toBeFalse()
            ->and(Hash::check($printed['higor_ap89@icloud.com'] ?? '', $higor->password))->toBeTrue();
    });

    it('admin existente com a senha do repositório: NÃO troca, só avisa', function () {
        scSeed('testing', true, EntityAndUserAdministratorSeeder::class);
        $before = User::firstWhere('email', 'higor_ap89@icloud.com')->password;

        $output = scSeed('production', false, EntityAndUserAdministratorSeeder::class);

        expect(User::firstWhere('email', 'higor_ap89@icloud.com')->password)->toBe($before)
            ->and($output)->toContain('higor_ap89@icloud.com ainda usa a senha do repositório');
    });
});

describe('clinics:ensure-financial-profiles', function () {
    beforeEach(function () {
        // ENT-0000000001 é a empresa do SaaS (o comando nunca a toca).
        Entity::factory()->create(['is_client' => false, 'active' => true]);
        $this->clinic    = Entity::factory()->create(['is_client' => true, 'active' => true]);
        $this->secretary = createEntityUser($this->clinic, User::factory()->create(), 'secretary');
    });

    it('produção: recusa (não promove ninguém de clínica real)', function () {
        app()->detectEnvironment(fn () => 'production');

        $exit = Artisan::call('clinics:ensure-financial-profiles', ['--force' => true]);

        expect($exit)->toBe(1)
            ->and(Artisan::output())->toContain('Recusado em produção')
            ->and($this->secretary->fresh()->rule)->toBe('secretary');
    });

    it('homologação (testing sem a chave): promove nas clínicas de teste, mas não cria o financeiro com a senha fixa', function () {
        scSeed('testing', false);
        $testClinic = Entity::firstWhere('subdomain', IntegratorTestClinicSeeder::ENTITY_SUBDOMAIN);
        EntityUser::where('entity_id', $testClinic->id)->where('rule', 'financial')->delete();
        $before = User::firstWhere('email', 'financeiro@clinicateste.com')->password;

        Artisan::call('clinics:ensure-financial-profiles', ['--force' => true]);

        expect($this->secretary->fresh()->rule)->toBe('financial')
            ->and(User::firstWhere('email', 'financeiro@clinicateste.com')->password)->toBe($before)
            ->and(Artisan::output())->toContain('IntegratorTestClinicSeeder');
    });
});
