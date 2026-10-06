<?php

namespace Database\Seeders;

use App\Enums\Billing\SubscriptionCancelledReason;
use App\Enums\{FeatureKey, SubscriptionBillingMode, SubscriptionStatus};
use App\Models\{Covenant,
    Doctor,
    Entity,
    EntityIntegrator,
    EntityIntegratorEquipment,
    EntityUser,
    EntityUserIntegrator,
    IrisType,
    Patient,
    People,
    Plan,
    SkinType,
    Subscription,
    User};
use App\Support\SeedCredentials;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clínica Teste Integrador: clínica fixa para testes da API de integradores,
 * treinamento e demonstração a clientes — também em PRODUÇÃO. Idempotente:
 * rodar de novo nunca apaga assinatura, nunca troca senha de usuário que já
 * existe e nunca mexe no que foi alterado pelo painel.
 *
 * Senhas (decisão do dono — App\Support\SeedCredentials):
 *  - modo fixo (APP_ENV=local ou SEED_FIXED_CREDENTIALS=true — testes
 *    automatizados): credenciais fixas do repositório (Cypress, manuais) e os
 *    20 pacientes de exemplo; a agenda vem do DataFakersSeeder;
 *  - homologação (APP_ENV=testing!) e produção: senha forte e aleatória
 *    (24 letras/números), mostrada UMA vez — na criação do usuário e quando
 *    ele ainda usa a senha fixa antiga do repositório (seed anterior): essa é
 *    substituída e as sessões/tokens abertos caem. Senha trocada pelo painel
 *    nunca é mexida. Sem pacientes de exemplo.
 *
 * Uso em homologação/produção: php artisan db:seed --class=IntegratorTestClinicSeeder --force
 */
class IntegratorTestClinicSeeder extends Seeder
{
    public const ENTITY_NAME = 'Clínica Teste Integrador';

    // Público: reutilizado pelo DataFakersSeeder (fica fora da massa fake) e
    // pelo EnsureTestFinancialProfilesCommand.
    public const ENTITY_SUBDOMAIN = 'clinica-teste-integrador';

    /** @var list<array{email: string, name: string, rule: string, password: string, doctor?: array{record: string, record_specialty: string, color: string}}> */
    private const STAFF = [
        ['email' => 'admin@clinicateste.com', 'name' => 'ADMIN CLÍNICA TESTE', 'rule' => 'admin', 'password' => 'Admin@123'],
        ['email' => 'dra.ana@clinicateste.com', 'name' => 'DRA. ANA LIMA', 'rule' => 'doctor', 'password' => 'Medico@123', 'doctor' => ['record' => '123456', 'record_specialty' => '12345', 'color' => '#e91e63']],
        ['email' => 'dr.carlos@clinicateste.com', 'name' => 'DR. CARLOS SOUZA', 'rule' => 'doctor', 'password' => 'Medico@123', 'doctor' => ['record' => '654321', 'record_specialty' => '54321', 'color' => '#1976d2']],
        ['email' => 'secretaria@clinicateste.com', 'name' => 'SECRETÁRIA CLÍNICA TESTE', 'rule' => 'secretary', 'password' => 'Secretaria@123'],
        // A única clínica com credenciais conhecidas precisa de um Financeiro
        // para testar o fluxo financeiro sem depender da massa aleatória.
        ['email' => 'financeiro@clinicateste.com', 'name' => 'FINANCEIRO CLÍNICA TESTE', 'rule' => 'financial', 'password' => 'Financeiro@123'],
    ];

    private const INTEGRATOR_EMAIL = 'integrador@teste.com';

    private const INTEGRATOR_PASSWORD = 'Integrador@123';

    /** @var list<array{0: string, 1: string, 2: string, 3: bool}> senha gerada nesta execução: [perfil, e-mail, senha, substituiu a fixa antiga?] */
    private array $generated = [];

    public function run(): void
    {
        $plan   = $this->integratorPlan();
        $entity = $this->ensureEntity();

        $this->ensureSubscription($entity, $plan);

        foreach (self::STAFF as $member) {
            $this->ensureStaff($entity, $member);
        }

        $integrator = $this->ensureIntegrator($entity);

        if ($this->usesFixedCredentials()) {
            $this->seedExamplePatients($entity);
        }

        $this->report($entity, $plan, $integrator);
    }

    /** Senhas fixas do repositório só em dev local e nos testes automatizados. */
    private function usesFixedCredentials(): bool
    {
        return SeedCredentials::fixed();
    }

    private function integratorPlan(): Plan
    {
        $plan = Plan::query()
            ->where('active', true)
            ->whereHas('features', static function ($query): void {
                $query->where('feature', FeatureKey::HasApiIntegrator->value)
                    ->where('value', '1');
            })
            ->orderByDesc('sort_order')
            ->orderByDesc('price')
            ->first();

        if (! $plan) {
            throw new RuntimeException(
                'Nenhum plano ativo com acesso à API de integradores foi encontrado. Execute o PlanSeeder antes do IntegratorTestClinicSeeder.',
            );
        }

        return $plan->loadMissing('features');
    }

    private function ensureEntity(): Entity
    {
        // Só cria se não existir: nome/endereço ajustados pelo painel não
        // voltam ao padrão.
        $entity = Entity::firstWhere('subdomain', self::ENTITY_SUBDOMAIN);

        if (! $entity) {
            $entity = new Entity([
                'subdomain' => self::ENTITY_SUBDOMAIN,
                'name'      => self::ENTITY_NAME,
                'city'      => 'São Paulo',
                'state'     => 'SP',
                'country'   => 'BR',
                'is_client' => true,
                'active'    => true,
            ]);
            // Sem o trial automático (EntityObserver): a clínica nasce em
            // cortesia e não entra nas métricas de trial/funil do manager.
            $entity->skipAutoTrial = true;
            $entity->save();
        }

        if (! $entity->is_client || ! $entity->active) {
            $entity->update(['is_client' => true, 'active' => true]);
        }

        return $entity;
    }

    /**
     * Cortesia de 1 ano (sem cobrança, régua nem franquia de IA — créditos de
     * cortesia pelo manager). Clínica criada antes deste seeder pode estar no
     * trial automático (EntityObserver), que venceria e bloquearia a
     * demonstração: o trial é substituído. Qualquer outra assinatura em vigor (cortesia estendida pelo
     * manager, plano pago) é mantida — nunca apaga histórico.
     */
    private function ensureSubscription(Entity $entity, Plan $plan): void
    {
        $inForce = Subscription::query()
            ->forEntity((string) $entity->id)
            ->inForce()
            ->get();

        if ($inForce->contains(fn (Subscription $subscription) => $subscription->status !== SubscriptionStatus::Trial)) {
            return;
        }

        $inForce->each(fn (Subscription $trial) => $trial->update([
            'status'           => SubscriptionStatus::Cancelled,
            'cancelled_at'     => now(),
            'cancelled_reason' => SubscriptionCancelledReason::Replaced->value,
        ]));

        Subscription::create([
            'entity_id'    => $entity->id,
            'plan_id'      => $plan->id,
            'status'       => SubscriptionStatus::Active,
            'billing_mode' => SubscriptionBillingMode::Complimentary,
            'starts_at'    => now(),
            'ends_at'      => now()->addYear()->endOfDay(),
        ]);
    }

    /** @param array{email: string, name: string, rule: string, password: string, doctor?: array{record: string, record_specialty: string, color: string}} $member */
    private function ensureStaff(Entity $entity, array $member): void
    {
        $person = People::firstOrCreate(
            ['email' => $member['email']],
            ['full_name' => $member['name'], 'cellphone' => ''],
        );

        $user = $this->ensureUser($member['email'], $person->full_name, $member['password'], $member['rule']);

        $entityUser = EntityUser::firstOrCreate(
            ['entity_id' => $entity->id, 'user_id' => $user->id],
            ['rule' => $member['rule'], 'active' => true],
        );

        if (! isset($member['doctor'])) {
            return;
        }

        Doctor::firstOrCreate(
            ['entity_user_id' => $entityUser->id],
            [
                'person_id' => $person->id,
                ...$member['doctor'],
                'partner' => false,
                'active'  => true,
            ],
        );
    }

    /**
     * Modo fixo: senha fixa sempre reaplicada (os testes dependem dela).
     * Senão: senha aleatória na criação; usuário existente só tem a senha
     * trocada se ainda usa a fixa do repositório (seed antigo) — aí ganha
     * uma aleatória e perde as sessões abertas. Senha definida pelo painel
     * nunca é mexida.
     */
    private function ensureUser(string $email, string $name, string $fixedPassword, string $label): User
    {
        if ($this->usesFixedCredentials()) {
            return User::updateOrCreate(
                ['email' => $email],
                ['name' => $name, 'email_verified_at' => now(), 'password' => Hash::make($fixedPassword)],
            );
        }

        $user = User::firstWhere('email', $email);

        if ($user) {
            if ($this->stillUsesFixed($user->password, $fixedPassword)) {
                $password = SeedCredentials::generate();
                $user->update(['password' => Hash::make($password)]);
                SeedCredentials::invalidateSessions($user);
                $this->generated[] = [$label, $email, $password, true];
            }

            return $user;
        }

        $password          = SeedCredentials::generate();
        $this->generated[] = [$label, $email, $password, false];

        return User::create([
            'name'              => $name,
            'email'             => $email,
            'email_verified_at' => now(),
            'password'          => Hash::make($password),
        ]);
    }

    private function stillUsesFixed(?string $hash, string $fixedPassword): bool
    {
        return is_string($hash) && $hash !== '' && Hash::check($fixedPassword, $hash);
    }

    private function ensureIntegrator(Entity $entity): EntityIntegrator
    {
        $user = EntityUserIntegrator::firstWhere('email', self::INTEGRATOR_EMAIL);

        if ($this->usesFixedCredentials()) {
            $user = EntityUserIntegrator::updateOrCreate(
                ['email' => self::INTEGRATOR_EMAIL],
                [
                    'entity_id'         => $entity->id,
                    'name'              => 'Integrador de Teste',
                    'email_verified_at' => now(),
                    'password'          => Hash::make(self::INTEGRATOR_PASSWORD),
                    'active'            => true,
                ],
            );
        } elseif (! $user) {
            $password          = SeedCredentials::generate();
            $this->generated[] = ['integrator', self::INTEGRATOR_EMAIL, $password, false];

            $user = EntityUserIntegrator::create([
                'email'             => self::INTEGRATOR_EMAIL,
                'entity_id'         => $entity->id,
                'name'              => 'Integrador de Teste',
                'email_verified_at' => now(),
                'password'          => Hash::make($password),
                'active'            => true,
            ]);
        } elseif ($this->stillUsesFixed($user->password, self::INTEGRATOR_PASSWORD)) {
            // Seed antigo com a senha fixa: troca e derruba os tokens da API.
            $password = SeedCredentials::generate();
            $user->update(['password' => Hash::make($password)]);
            SeedCredentials::invalidateSessions($user);
            $this->generated[] = ['integrator', self::INTEGRATOR_EMAIL, $password, true];
        }

        $first = EntityIntegrator::firstOrCreate(
            ['mac' => 'AA:BB:CC:DD:EE:01'],
            ['entity_user_integrator_id' => $user->id, 'name' => 'Equipamento Teste 01', 'ip' => '192.168.1.100', 'active' => true],
        );

        EntityIntegrator::firstOrCreate(
            ['mac' => 'AA:BB:CC:DD:EE:02'],
            ['entity_user_integrator_id' => $user->id, 'name' => 'Equipamento Teste 02', 'ip' => '192.168.1.101', 'active' => true],
        );

        // Regra de negócio: esta clínica é exclusiva para testes da API de
        // integradores e deve permanecer sem equipamentos cadastrados.
        EntityIntegratorEquipment::withTrashed()
            ->whereHas('integrator.user', static function ($query) use ($entity) {
                $query->where('entity_id', $entity->id);
            })
            ->forceDelete();

        return $first;
    }

    /**
     * Só em local/testing (massa fictícia). Uma vez: se a clínica já tem
     * pacientes, não cria mais.
     */
    private function seedExamplePatients(Entity $entity): void
    {
        if (Patient::withoutGlobalScopes()->where('entity_id', $entity->id)->exists()) {
            return;
        }

        $this->command?->info('⏳ Criando pacientes de exemplo da Clínica Teste Integrador...');

        $skinTypes = SkinType::all();
        $irisTypes = IrisType::all();
        $covenants = Covenant::all();

        for ($i = 0; $i < 20; $i++) {
            Patient::create([
                'entity_id'   => $entity->id,
                'person_id'   => People::factory()->create()->id,
                'covenant_id' => $covenants->isNotEmpty() ? $covenants->random()->id : null,
                'skin_id'     => $skinTypes->isNotEmpty() ? $skinTypes->random()->id : null,
                'iris_id'     => $irisTypes->isNotEmpty() ? $irisTypes->random()->id : null,
                'card_number' => fake()->optional(0.6)->creditCardNumber(),
                'active'      => true,
            ]);
        }
    }

    private function report(Entity $entity, Plan $plan, EntityIntegrator $integrator): void
    {
        if (! $this->command) {
            return;
        }

        $line = '═══════════════════════════════════════════════════════';
        $this->command->info('');
        $this->command->info($line);
        $this->command->info('  ' . self::ENTITY_NAME . ' — ' . $entity->code . ' (plano ' . $plan->name . ', cortesia)');
        $this->command->info('  Integrador: POST /api/integrators/signin · code ' . $integrator->code);
        $this->command->info($line);

        if ($this->usesFixedCredentials()) {
            $this->command->info('  integrator · ' . self::INTEGRATOR_EMAIL . ' · ' . self::INTEGRATOR_PASSWORD);

            foreach (self::STAFF as $member) {
                $this->command->info("  {$member['rule']} · {$member['email']} · {$member['password']}");
            }
        } elseif ($this->generated === []) {
            $this->command->info('  Usuários já existiam: senhas mantidas.');
        } else {
            $this->command->warn('  Senhas geradas AGORA — anote; não serão mostradas de novo (ficam no histórico do terminal/log de CI):');

            foreach ($this->generated as [$label, $email, $password, $replaced]) {
                // Sem formatação do console (a senha sai exatamente como é).
                $this->command->getOutput()->writeln(
                    "  {$label} · {$email} · {$password}" . ($replaced ? ' (senha fixa antiga substituída — sessões encerradas)' : ''),
                    OutputInterface::OUTPUT_RAW,
                );
            }
        }

        $this->command->info($line);
        $this->command->info('');
    }
}
