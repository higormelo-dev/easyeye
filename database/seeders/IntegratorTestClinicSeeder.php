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
use App\Services\PatientService;
use App\Support\SeedCredentials;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\{DB, Hash};
use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Clínica Teste Integrador: clínica fixa para testes da API de integradores,
 * treinamento e demonstração a clientes — também em PRODUÇÃO. Idempotente:
 * rodar de novo nunca apaga assinatura, nunca troca senha de usuário que já
 * existe e preserva cadastros e escalas existentes. Escalas vazias são
 * completadas com expediente e bloqueios de demonstração.
 *
 * Senhas (decisão do dono — App\Support\SeedCredentials):
 *  - modo fixo (APP_ENV=local ou SEED_FIXED_CREDENTIALS=true — testes
 *    automatizados): credenciais fixas do repositório (Cypress, manuais) e os
 *    20 pacientes de exemplo e agendamentos para todos os médicos ativos;
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

    /** @var list<array{email: string, name: string, rule: string, password: string, national_registry?: string, doctor?: array{record: string, record_specialty: string, color: string}}> */
    private const STAFF = [
        ['email' => 'admin@clinicateste.com', 'name' => 'ADMIN CLÍNICA TESTE', 'rule' => 'admin', 'password' => 'Admin@123'],
        // Identificadores fictícios: não representam documentos de pessoas reais.
        ['email' => 'dra.ana@clinicateste.com', 'name' => 'DRA. ANA LIMA', 'rule' => 'doctor', 'password' => 'Medico@123', 'national_registry' => '00000000001', 'doctor' => ['record' => '123456', 'record_specialty' => '12345', 'color' => '#e91e63']],
        ['email' => 'dr.carlos@clinicateste.com', 'name' => 'DR. CARLOS SOUZA', 'rule' => 'doctor', 'password' => 'Medico@123', 'national_registry' => '00000000002', 'doctor' => ['record' => '654321', 'record_specialty' => '54321', 'color' => '#1976d2']],
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
        $this->generated = [];
        $plan            = $this->integratorPlan();

        [$entity, $integrator] = DB::transaction(function () use ($plan): array {
            $entity = $this->ensureEntity();
            $this->ensureSubscription($entity, $plan);

            foreach (self::STAFF as $member) {
                $this->ensureStaff($entity, $member);
            }

            $integrator = $this->ensureIntegrator($entity);

            if ($this->usesFixedCredentials()) {
                $this->seedExamplePatients($entity);
                app(DataFakersSeeder::class)->seedClinicSchedules($entity);
            }

            return [$entity, $integrator];
        });

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
                'email'     => 'admin@clinicateste.com',
                'locale'    => 'pt_BR',
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

    /** @param array{email: string, name: string, rule: string, password: string, national_registry?: string, doctor?: array{record: string, record_specialty: string, color: string}} $member */
    private function ensureStaff(Entity $entity, array $member): void
    {
        $user = $this->ensureUser($member['email'], $member['name'], $member['password'], $member['rule']);

        $entityUser = EntityUser::withTrashed()->firstOrCreate(
            ['entity_id' => $entity->id, 'user_id' => $user->id],
            [
                'rule'      => $member['rule'],
                'active'    => true,
                'is_owner'  => $member['rule'] === 'admin' && ! $entity->entityUsers()->where('is_owner', true)->exists(),
                'joined_at' => now(),
            ],
        );

        if (! $entityUser->trashed() && $entityUser->active) {
            $missingMembership = [];

            if ($entityUser->joined_at === null) {
                $missingMembership['joined_at'] = $entityUser->created_at;
            }

            // Seed antigo não cadastrava o responsável. Só completa quando
            // a clínica ainda não tem dono; preserva responsáveis do painel.
            if ($member['rule'] === 'admin' && $entityUser->rule === 'admin' && ! $entity->entityUsers()->where('is_owner', true)->exists()) {
                $missingMembership['is_owner'] = true;
            }

            if ($missingMembership !== []) {
                $entityUser->update($missingMembership);
            }
        }

        if (! isset($member['doctor']) || $entityUser->trashed()) {
            return;
        }

        $doctor = Doctor::withTrashed()->firstWhere('entity_user_id', $entityUser->id);

        if (! $doctor) {
            // Mesmo isolamento do cadastro pelo painel: People próprio do
            // médico nesta clínica, sem reaproveitar pessoa pelo e-mail global.
            $person = People::create([
                'full_name'         => $member['name'],
                'nickname'          => $member['name'],
                'email'             => $member['email'],
                'cellphone'         => '',
                'national_registry' => $member['national_registry'],
                'city'              => $entity->city,
                'state'             => $entity->state,
                'country'           => $entity->country,
            ]);

            $doctor = Doctor::create([
                'entity_user_id' => $entityUser->id,
                'person_id'      => $person->id,
                ...$member['doctor'],
                'partner' => false,
                'active'  => true,
            ]);
        }

        if (! $doctor->trashed() && $doctor->active && $entityUser->active) {
            // Seed antigo: completa campos obrigatórios que faltavam, somente
            // se a pessoa não for compartilhada com outra clínica.
            $person         = $doctor->person;
            $patientService = app(PatientService::class);

            if ($person && ! $patientService->personSharedWithOtherEntities((string) $person->id, (string) $entity->id)) {
                $missing = [];

                if (blank($person->nickname)) {
                    $missing['nickname'] = $person->full_name;
                }

                if (blank($person->national_registry)) {
                    $missing['national_registry'] = $member['national_registry'];
                }

                if ($missing !== []) {
                    $patientService->fillPersonGuarded($person, $missing, (string) $entity->id, 'national_registry')->save();
                }
            }

            $this->ensureDoctorAgenda($doctor);
        }
    }

    /** Completa a etapa de escala apenas quando ela ainda não existe. */
    private function ensureDoctorAgenda(Doctor $doctor): void
    {
        // Escala já configurada: mantém inclusive os bloqueios removidos ou
        // editados pelo painel. Não completa dias de folga de uma escala parcial.
        if ($doctor->workSchedules()->exists()) {
            return;
        }

        foreach (range(1, 5) as $day) {
            foreach ([['08:00:00', '12:00:00'], ['14:00:00', '18:00:00']] as [$startsAt, $endsAt]) {
                $doctor->workSchedules()->create([
                    'day_of_week' => $day,
                    'starts_at'   => $startsAt,
                    'ends_at'     => $endsAt,
                ]);
            }
        }

        // Intervalo e teto de encaixes herdam os padrões do sistema. Não
        // sobrescreve configurações do médico e não renova exemplos vencidos.
        if ($doctor->scheduleBlocks()->exists()) {
            return;
        }

        $monday = now()->next('Monday')->startOfDay();

        $doctor->scheduleBlocks()->create([
            'starts_at' => $monday->copy()->setTime(10, 0),
            'ends_at'   => $monday->copy()->setTime(11, 0),
            'reason'    => 'Reunião de equipe (exemplo de teste)',
            'type'      => 'meeting',
        ]);

        $doctor->scheduleBlocks()->create([
            'starts_at' => $monday->copy()->addDay()->startOfDay(),
            'ends_at'   => $monday->copy()->addDays(2)->startOfDay(),
            'reason'    => 'Ausência programada (exemplo de teste)',
            'type'      => 'absence',
        ]);
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
        $covenants = Covenant::query()
            ->where('active', true)
            ->where(fn ($query) => $query->whereNull('entity_id')->orWhere('entity_id', $entity->id))
            ->get();

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
