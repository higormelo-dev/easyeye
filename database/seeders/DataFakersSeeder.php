<?php

namespace Database\Seeders;

use App\Enums\{ActivationStep, BillingCycle, ScheduleSituation, SubscriptionBillingMode, SubscriptionStatus};
use App\Enums\Billing\SubscriptionCancelledReason;
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
    Schedule,
    SkinType,
    Subscription,
    User,
    VisitType};
use App\Services\ActivationService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DataFakersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('⏳ Garantindo catálogo de planos...');
        $this->call(PlanSeeder::class);
        $this->command->info('⏳ Garantindo catálogos clínicos base...');
        $this->call([
            CovenantsSeeder::class,
            SkinTypesSeeder::class,
            IrisTypesSeeder::class,
            VisitTypesSeeder::class,
            ExamTypesSeeder::class,
        ]);

        // Clínica Teste Integrador tem seeder próprio (também roda em
        // produção); aqui só garante que exista quando este seeder roda
        // sozinho — a agenda dela vem do createSchedules() abaixo.
        if (! Entity::where('subdomain', IntegratorTestClinicSeeder::ENTITY_SUBDOMAIN)->exists()) {
            $this->call(IntegratorTestClinicSeeder::class);
        }

        $peopleCount = $this->seedInt('SEED_FAKE_PEOPLE', 3000, 1);
        $this->command->info("⏳ Criando People ({$peopleCount})...");
        People::factory($peopleCount)->create();

        $entityCount = $this->seedInt('SEED_FAKE_ENTITIES', 15, 1);
        $this->command->info("⏳ Criando Entities ({$entityCount})...");
        Entity::factory($entityCount)
            ->sequence(fn ($attributes) => [
                'subdomain' => Str::slug(fake()->company()) . '-' . ($attributes->index + 1),
            ])
            ->create();

        // Apenas as entities-cliente criadas pelo factory (exclui o grupo gestor)
        $entities = Entity::query()
            ->where('is_client', true)
            ->whereNot('name', 'Medical Group')
            // Esta clínica é reservada para testes de API de integradores e não
            // deve receber massa fake (principalmente equipamentos).
            ->whereNot('subdomain', IntegratorTestClinicSeeder::ENTITY_SUBDOMAIN)
            ->get();

        $usersCount = $this->seedInt('SEED_FAKE_USERS', 95, 1);
        $this->command->info("⏳ Criando Users ({$usersCount})...");
        $users = User::factory($usersCount)->create(['password' => Hash::make('123456789')]);

        // ── Planos ──────────────────────────────────────────────────────────
        $planBasico  = Plan::where('slug', 'basico')->first();
        $planPro     = Plan::where('slug', 'pro')->first();
        $planPremium = Plan::where('slug', 'premium')->first();

        // Distribuição de planos: 20% Básico, 50% Pro, 30% Premium
        $planDistribution = array_merge(
            array_fill(0, 3, $planBasico?->id),
            array_fill(0, 7, $planPro?->id),
            array_fill(0, 5, $planPremium?->id),
        );

        // Cenários de assinatura coerentes com as regras de cobrança (para o
        // ambiente de teste mostrar avisos e régua): pagante em dia, cortesia,
        // trial, pagante em atraso D+1 (aviso), em atraso D+4 (acesso
        // limitado), contratação aguardando o 1º pagamento e cortesia vencida.
        $statusDistribution = array_merge(
            array_fill(0, 4, 'gateway_paid'),
            array_fill(0, 2, 'complimentary'),
            array_fill(0, 2, 'trial'),
            ['gateway_overdue', 'gateway_limited', 'awaiting_first_payment', 'expired'],
        );

        // Funções dos usuários comuns: maioria 'user', alguns 'secretary' e 'financial'
        $roleDistribution = ['user', 'user', 'user', 'user', 'secretary', 'secretary', 'financial'];

        $this->command->info('⏳ Vinculando Users a Entities...');
        $users->each(function ($user) use ($entities, $roleDistribution) {
            $numberOfEntities = fake()->numberBetween(1, min(4, $entities->count()));
            $selectedEntities = $entities->random($numberOfEntities);

            $selectedEntities->each(function ($entity) use ($user, $roleDistribution) {
                EntityUser::create([
                    'entity_id' => $entity->id,
                    'user_id'   => $user->id,
                    'active'    => true,
                    'rule'      => fake()->randomElement($roleDistribution),
                ]);
            });
        });

        // ── Configurar cada Entity: admin, assinatura, integradores e TVs ───
        $this->command->info('⏳ Configurando Entities (assinaturas, integradores, TVs)...');
        $entities->values()->each(function ($entity, int $index) use ($planDistribution, $statusDistribution) {
            // Promover um usuário aleatório a admin (quando há 2+ usuários)
            $userCount = EntityUser::query()->where('entity_id', $entity->id)->count();

            if ($userCount >= 2) {
                $randomEntityUser = EntityUser::query()
                    ->where('entity_id', $entity->id)
                    ->whereNotIn('rule', ['admin', 'doctor'])
                    ->inRandomOrder()
                    ->first();

                if ($randomEntityUser) {
                    $randomEntityUser->update(['rule' => 'admin']);
                }
            }

            // BUGFIX: 'financial' era só ~1/7 chance no roleDistribution acima e
            // podia ainda ser roubado pela promoção a admin logo acima — várias
            // clínicas de teste ficavam sem nenhum usuário financeiro, sem meio
            // de um QA testar esse fluxo. Garante ao menos 1 por entity.
            $hasFinancial = EntityUser::query()
                ->where('entity_id', $entity->id)
                ->where('rule', 'financial')
                ->exists();

            if (! $hasFinancial) {
                $financialCandidate = EntityUser::query()
                    ->where('entity_id', $entity->id)
                    ->whereNotIn('rule', ['admin', 'doctor'])
                    ->inRandomOrder()
                    ->first();

                if ($financialCandidate) {
                    $financialCandidate->update(['rule' => 'financial']);
                }
            }

            // Assinatura: os cenários se revezam entre as empresas (todos
            // aparecem a partir de 12 empresas); plano sorteado.
            $planId = $planDistribution[array_rand($planDistribution)];
            $status = $statusDistribution[$index % count($statusDistribution)];

            if ($planId) {
                $this->seedSubscription($entity, Plan::query()->find($planId), $status);
            }

            // Integradores: 2-5 usuários, cada um com 2-8 equipamentos lógicos e 1-3 físicos
            $entityUserIntegrators = EntityUserIntegrator::factory(fake()->numberBetween(2, 5))
                ->create(['entity_id' => $entity->id, 'password' => Hash::make('123456789')]);

            $entityUserIntegrators->each(function ($entityUserIntegrator) {
                $integrators = EntityIntegrator::factory(fake()->numberBetween(2, 8))
                    ->create(['entity_user_integrator_id' => $entityUserIntegrator->id]);

                $integrators->each(function ($integrator) {
                    EntityIntegratorEquipment::factory(fake()->numberBetween(1, 3))
                        ->create(['integrator_id' => $integrator->id]);
                });
            });
        });

        // ── Patients ─────────────────────────────────────────────────────────
        $this->command->info('⏳ Criando Patients...');
        $people               = People::query()->select('id')->get();
        $skinTypes            = SkinType::all();
        $irisTypes            = IrisType::all();
        $covenants            = Covenant::all();
        $patientsBatch        = [];
        $patientsNow          = now();
        $entityIds            = $entities->pluck('id')->all();
        $patientCodes         = $this->loadEntityCodeCounters(Patient::class, 'PAC', $entityIds);
        $entitiesWithPatients = [];

        foreach ($people as $person) {
            $numberOfEntities = fake()->numberBetween(1, min(3, $entities->count()));
            $selectedEntities = $entities->random($numberOfEntities);

            foreach ($selectedEntities as $entity) {
                $entityId                        = (string) $entity->id;
                $patientCodes[$entityId]         = ($patientCodes[$entityId] ?? 0) + 1;
                $entitiesWithPatients[$entityId] = true;
                $patientsBatch[]                 = [
                    'id'          => (string) Str::uuid(),
                    'entity_id'   => $entityId,
                    'person_id'   => (string) $person->id,
                    'covenant_id' => $covenants->isNotEmpty() ? $covenants->random()->id : null,
                    'skin_id'     => $skinTypes->random()->id,
                    'iris_id'     => $irisTypes->random()->id,
                    'code'        => sprintf('PAC-%010d', $patientCodes[$entityId]),
                    'card_number' => fake()->optional(0.6)->creditCardNumber(),
                    'active'      => fake()->boolean(90),
                    'created_at'  => $patientsNow,
                    'updated_at'  => $patientsNow,
                ];

                if (count($patientsBatch) >= 1000) {
                    Patient::insert($patientsBatch);
                    $patientsBatch = [];
                }
            }
        }

        if (! empty($patientsBatch)) {
            Patient::insert($patientsBatch);
        }

        $this->markActivationSteps(array_keys($entitiesWithPatients), ActivationStep::FirstPatientAdded);

        // ── Doctors ──────────────────────────────────────────────────────────
        $doctorCount = $this->seedInt('SEED_FAKE_DOCTORS', 250, 1);
        $this->command->info("⏳ Criando Doctors ({$doctorCount})...");
        $entityUsersBatch    = [];
        $doctorsBatch        = [];
        $doctorsNow          = now();
        $entitiesWithDoctors = [];
        $entityIsClient      = $entities->pluck('is_client', 'id')->all();
        $entityUserCode      = [
            'EU'  => $this->loadGlobalCodeCounter(EntityUser::class, 'EU'),
            'EUP' => $this->loadGlobalCodeCounter(EntityUser::class, 'EUP'),
        ];
        $doctorCode = $this->loadGlobalCodeCounter(Doctor::class, 'DOC');

        for ($i = 0; $i < $doctorCount; $i++) {
            $person     = People::factory()->create();
            $userDoctor = User::factory()->create([
                'name'     => $person->full_name,
                'email'    => $person->email,
                'password' => Hash::make('123456789'),
            ]);

            // random(N) lança se N > itens disponíveis — ambientes com poucas
            // entities (ex.: teste) quebravam aqui.
            $numberOfEntities = fake()->numberBetween(1, min(6, $entities->count()));
            $selectedEntities = $entities->random($numberOfEntities);

            foreach ($selectedEntities as $entity) {
                $entityId = (string) $entity->id;
                $prefix   = ($entityIsClient[$entityId] ?? true) ? 'EU' : 'EUP';
                $entityUserCode[$prefix]++;
                $doctorCode++;
                $entitiesWithDoctors[$entityId] = true;

                $entityUserId       = (string) Str::uuid();
                $entityUsersBatch[] = [
                    'id'         => $entityUserId,
                    'entity_id'  => $entityId,
                    'user_id'    => (string) $userDoctor->id,
                    'code'       => sprintf('%s-%010d', $prefix, $entityUserCode[$prefix]),
                    'rule'       => 'doctor',
                    'is_owner'   => false,
                    'active'     => true,
                    'created_at' => $doctorsNow,
                    'updated_at' => $doctorsNow,
                ];

                $doctorsBatch[] = [
                    'id'               => (string) Str::uuid(),
                    'entity_user_id'   => $entityUserId,
                    'person_id'        => (string) $person->id,
                    'code'             => sprintf('DOC-%010d', $doctorCode),
                    'record'           => fake()->numerify('######'),
                    'record_specialty' => fake()->optional(0.7)->numerify('#####'),
                    'color'            => fake()->hexColor(),
                    'partner'          => fake()->boolean(20),
                    'active'           => fake()->boolean(90),
                    'observation'      => fake()->optional(0.3)->sentence(),
                    'created_at'       => $doctorsNow,
                    'updated_at'       => $doctorsNow,
                ];

                if (count($entityUsersBatch) >= 500) {
                    EntityUser::insert($entityUsersBatch);
                    Doctor::insert($doctorsBatch);
                    $entityUsersBatch = [];
                    $doctorsBatch     = [];
                }
            }
        }

        if (! empty($entityUsersBatch)) {
            EntityUser::insert($entityUsersBatch);
            Doctor::insert($doctorsBatch);
        }

        $this->markActivationSteps(array_keys($entitiesWithDoctors), ActivationStep::FirstDoctorAdded);
        $this->markActivationSteps(array_keys($entitiesWithDoctors), ActivationStep::TeamMemberInvited);

        // ── Schedules ─────────────────────────────────────────────────────────
        $this->command->info('⏳ Criando Schedules...');
        $this->createSchedules();
    }

    /**
     * Criar schedules cobrindo 1 mês passado e 3 meses futuros (segunda a domingo).
     * Situações realistas: passados → Attended/NoShow/Cancelled; futuros → Scheduled/Cancelled.
     */
    /**
     * Assinatura fake de um cenário, no lugar do trial automático da empresa
     * (como faz a contratação de verdade). Nunca um estado impossível (ex.:
     * cortesia "em atraso"): em atraso é sempre cobrança automática que já
     * foi paga antes.
     */
    private function seedSubscription(Entity $entity, ?Plan $plan, string $scenario): void
    {
        if (! $plan) {
            return;
        }

        Subscription::query()
            ->forEntity((string) $entity->id)
            ->inForce()
            ->each(fn (Subscription $old) => $old->update([
                'status'           => SubscriptionStatus::Cancelled,
                'cancelled_at'     => now(),
                'cancelled_reason' => SubscriptionCancelledReason::Replaced->value,
            ]));

        $amount  = $plan->priceFor(BillingCycle::Monthly) ?? (float) $plan->price;
        $gateway = [
            'billing_mode'            => SubscriptionBillingMode::Gateway,
            'billing_cycle'           => BillingCycle::Monthly,
            'amount'                  => $amount,
            'gateway'                 => 'asaas',
            'pinned_gateway'          => 'asaas',
            'gateway_customer_id'     => 'cus_fake_' . Str::lower(Str::random(10)),
            'gateway_subscription_id' => 'sub_fake_' . Str::lower(Str::random(10)),
        ];

        $overdueSince = fn (int $days) => [
            ...$gateway,
            'status'          => SubscriptionStatus::PastDue,
            'billing_state'   => 'past_due',
            'starts_at'       => now()->subMonths(3),
            'last_payment_at' => now()->subDays($days)->subMonth(),
            'ends_at'         => now()->subDays($days)->endOfDay(),
            'next_billing_at' => now()->subDays($days)->endOfDay(),
            'past_due_at'     => now()->subDays($days)->endOfDay(),
        ];

        $paidDaysAgo = fake()->numberBetween(1, 25);

        Subscription::create([
            'entity_id' => $entity->id,
            'plan_id'   => $plan->id,
            ...match ($scenario) {
                'trial' => [
                    'status'        => SubscriptionStatus::Trial,
                    'billing_mode'  => null,
                    'trial_ends_at' => now()->addDays(fake()->numberBetween(1, 7)),
                    'starts_at'     => now(),
                    'ends_at'       => null,
                ],
                'complimentary' => [
                    'status'       => SubscriptionStatus::Active,
                    'billing_mode' => SubscriptionBillingMode::Complimentary,
                    'starts_at'    => now()->subMonth(),
                    'ends_at'      => now()->addMonths(3)->endOfDay(),
                ],
                'expired' => [
                    'status'       => SubscriptionStatus::Expired,
                    'billing_mode' => SubscriptionBillingMode::Complimentary,
                    'starts_at'    => now()->subYear(),
                    'ends_at'      => now()->subMonths(fake()->numberBetween(1, 6)),
                ],
                // Em atraso há 1 dia: acesso total com aviso e link.
                'gateway_overdue' => $overdueSince(1),
                // Em atraso há 4 dias: acesso limitado (IA e financeiro bloqueados).
                'gateway_limited' => $overdueSince(4),
                // Contratação por boleto/Pix: acesso até o fim do dia do 1º vencimento.
                'awaiting_first_payment' => [
                    ...$gateway,
                    'status'          => SubscriptionStatus::PastDue,
                    'billing_state'   => 'pending_activation',
                    'starts_at'       => now(),
                    'ends_at'         => now()->addDays(2)->endOfDay(),
                    'next_billing_at' => now()->addDays(2)->endOfDay(),
                ],
                // Pagante em dia (cobrança automática paga no ciclo atual).
                default => [
                    ...$gateway,
                    'status'          => SubscriptionStatus::Active,
                    'billing_state'   => 'paid',
                    'starts_at'       => now()->subMonths(2),
                    'last_payment_at' => now()->subDays($paidDaysAgo),
                    'ends_at'         => now()->subDays($paidDaysAgo)->addMonth()->endOfDay(),
                    'next_billing_at' => now()->subDays($paidDaysAgo)->addMonth()->endOfDay(),
                ],
            },
        ]);
    }

    private function createSchedules(): void
    {
        $doctors        = Doctor::query()->select('id', 'entity_user_id')->get();
        $schedulesBatch = [];
        $batchSize      = $this->seedInt('SEED_FAKE_SCHEDULE_BATCH_SIZE', 1000, 100);
        $codeCounter    = [];

        // Cache em memória para evitar queries repetidas durante o loop
        $entityUsers  = EntityUser::query()->select('id', 'entity_id')->get()->keyBy('id');
        $entitiesById = Entity::query()->select('id', 'schedule_interval')->get()->keyBy('id');
        $allPatients  = Patient::query()
            ->select('id', 'entity_id', 'person_id')
            ->with('person:id,full_name')
            ->get()
            ->groupBy('entity_id');
        $allCovenants       = Covenant::query()->select('id', 'entity_id')->get();
        $globalCovenants    = $allCovenants->whereNull('entity_id');
        $covenantsByEntity  = $allCovenants->whereNotNull('entity_id')->groupBy('entity_id');
        $allVisitTypes      = VisitType::query()->select('id', 'entity_id')->get();
        $globalVisitTypes   = $allVisitTypes->whereNull('entity_id');
        $visitTypesByEntity = $allVisitTypes->whereNotNull('entity_id')->groupBy('entity_id');

        // Inicializa contadores de código por entity antes do loop (evita queries repetidas)
        $entityIds = $entityUsers->pluck('entity_id')->unique();

        foreach ($entityIds as $entityId) {
            $lastSchedule = Schedule::where('entity_id', $entityId)
                ->where('code', 'like', 'SDL-%')
                ->orderBy('code', 'desc')
                ->first();
            $codeCounter[$entityId] = $lastSchedule
                ? (int) substr($lastSchedule->code, 4)
                : 0;
        }

        // Janela temporal: 1 mês passado até 3 meses futuros (todos os dias)
        $pastMonths            = $this->seedInt('SEED_FAKE_SCHEDULE_PAST_MONTHS', 1, 0);
        $futureMonths          = $this->seedInt('SEED_FAKE_SCHEDULE_FUTURE_MONTHS', 3, 0);
        $startDate             = Carbon::now()->subMonths($pastMonths)->startOfDay();
        $endDate               = Carbon::now()->addMonths($futureMonths)->endOfDay();
        $date                  = $startDate->copy();
        $entitiesWithSchedules = [];

        while ($date->lte($endDate)) {
            $dailyPatientPools = [];
            $isPast            = $date->copy()->endOfDay()->isPast();

            foreach ($doctors as $doctor) {
                $entityUser = $entityUsers->get($doctor->entity_user_id);

                if (! $entityUser) {
                    continue;
                }

                $entityId = $entityUser->entity_id;

                if (! isset($codeCounter[$entityId])) {
                    $codeCounter[$entityId] = 0;
                }

                $patientsOfEntity   = $allPatients->get($entityId, collect());
                $covenantsOfEntity  = $covenantsByEntity->get($entityId, collect())->merge($globalCovenants);
                $visitTypesOfEntity = $visitTypesByEntity->get($entityId, collect())->merge($globalVisitTypes);

                if ($patientsOfEntity->isEmpty() || $covenantsOfEntity->isEmpty() || $visitTypesOfEntity->isEmpty()) {
                    continue;
                }

                if (! isset($dailyPatientPools[$entityId])) {
                    $dailyPatientPools[$entityId] = $patientsOfEntity->shuffle()->values()->all();
                }

                $patientPool = &$dailyPatientPools[$entityId];

                if (empty($patientPool)) {
                    continue;
                }

                $interval       = $entitiesById->get($entityId)?->schedule_interval ?? 15;
                $morningSlots   = $this->generateTimeSlots($date, 8, 12, $doctor, $entityId, $patientPool, $covenantsOfEntity, $visitTypesOfEntity, $codeCounter, $isPast, $interval);
                $afternoonSlots = $this->generateTimeSlots($date, 14, 18, $doctor, $entityId, $patientPool, $covenantsOfEntity, $visitTypesOfEntity, $codeCounter, $isPast, $interval);

                foreach (array_merge($morningSlots, $afternoonSlots) as $slot) {
                    $schedulesBatch[]                          = $slot;
                    $entitiesWithSchedules[$slot['entity_id']] = true;
                }

                if (count($schedulesBatch) >= $batchSize) {
                    Schedule::insert($schedulesBatch);
                    $schedulesBatch = [];
                }

                unset($patientPool);
            }

            $date->addDay();
        }

        if (! empty($schedulesBatch)) {
            Schedule::insert($schedulesBatch);
        }

        $this->markActivationSteps(array_keys($entitiesWithSchedules), ActivationStep::FirstScheduleCreated);
    }

    /**
     * Gerar slots de horário com intervalos variáveis de 20–30 minutos.
     * Situações ponderadas pelo contexto temporal (passado vs. futuro).
     * Preenche arrived_at para pacientes que compareceram.
     */
    private function generateTimeSlots(
        Carbon $date,
        int $startHour,
        int $endHour,
        Doctor $doctor,
        string $entityId,
        array &$patientPool,
        $covenants,
        $visitTypes,
        array &$codeCounter,
        bool $isPast = false,
        int $intervalMinutes = 15,
    ): array {
        $schedules   = [];
        $currentTime = $date->copy()->setTime($startHour, 0, 0);
        $endTime     = $date->copy()->setTime($endHour, 0, 0);
        $now         = Carbon::now();

        // Peso por contexto temporal: passado prioriza Attended; futuro prioriza Scheduled
        $situationPool = $isPast
            ? [
                ScheduleSituation::Attended->value,
                ScheduleSituation::Attended->value,
                ScheduleSituation::Attended->value,
                ScheduleSituation::NoShow->value,
                ScheduleSituation::Cancelled->value,
            ]
            : [
                ScheduleSituation::Scheduled->value,
                ScheduleSituation::Scheduled->value,
                ScheduleSituation::Scheduled->value,
                ScheduleSituation::Scheduled->value,
                ScheduleSituation::Cancelled->value,
            ];

        while ($currentTime < $endTime && ! empty($patientPool)) {
            $patient = array_pop($patientPool);

            $covenant  = $covenants->random();
            $visitType = $visitTypes->random();
            $situation = $situationPool[array_rand($situationPool)];

            $fullName = $patient->person?->full_name ?? fake()->name();

            $codeCounter[$entityId]++;
            $code = sprintf('SDL-%010d', $codeCounter[$entityId]);

            // arrived_at: preenchido para pacientes que chegaram à clínica
            $arrivedAt = null;

            if ($isPast && in_array($situation, [
                ScheduleSituation::Waiting->value,
                ScheduleSituation::InProgress->value,
                ScheduleSituation::Attended->value,
            ])) {
                $arrivedAt = $currentTime->copy()
                    ->subMinutes(fake()->numberBetween(0, 15))
                    ->toDateTimeString();
            }

            $schedules[] = [
                'id'                 => (string) Str::uuid(),
                'entity_id'          => $entityId,
                'doctor_id'          => $doctor->id,
                'patient_id'         => $patient->id,
                'covenant_id'        => $covenant->id,
                'visit_id'           => $visitType->id,
                'code'               => $code,
                'full_name'          => $fullName,
                'date_time'          => $currentTime->copy()->toDateTimeString(),
                'telephone'          => fake()->boolean(30) ? fake()->numerify('##########') : null,
                'cellphone'          => fake()->numerify('###########'),
                'cellphone_whatsapp' => fake()->boolean(70),
                'situation'          => $situation,
                'arrived_at'         => $arrivedAt,
                'active'             => fake()->boolean(95),
                'created_at'         => $now,
                'updated_at'         => $now,
            ];

            $currentTime->addMinutes($intervalMinutes);
        }

        return $schedules;
    }

    /**
     * @param array<int, string> $entityIds
     *
     * @return array<string, int>
     */
    private function loadEntityCodeCounters(string $modelClass, string $prefix, array $entityIds): array
    {
        if (empty($entityIds)) {
            return [];
        }

        $codes = [];
        $rows  = $modelClass::withoutGlobalScopes()
            ->select('entity_id', 'code')
            ->whereIn('entity_id', $entityIds)
            ->where('code', 'like', $prefix . '-%')
            ->orderBy('entity_id')
            ->orderByDesc('code')
            ->get();

        foreach ($rows as $row) {
            $entityId = (string) $row->entity_id;

            if (isset($codes[$entityId])) {
                continue;
            }

            $codes[$entityId] = (int) substr((string) $row->code, strlen($prefix) + 1);
        }

        return $codes;
    }

    private function loadGlobalCodeCounter(string $modelClass, string $prefix): int
    {
        $lastCode = $modelClass::withoutGlobalScopes()
            ->where('code', 'like', $prefix . '-%')
            ->orderBy('code', 'desc')
            ->value('code');

        if (! is_string($lastCode)) {
            return 0;
        }

        return (int) substr($lastCode, strlen($prefix) + 1);
    }

    private function seedInt(string $key, int $default, int $min): int
    {
        $value = env($key);

        if (! is_numeric($value)) {
            return $default;
        }

        return max($min, (int) $value);
    }

    /**
     * @param array<int, string> $entityIds
     */
    private function markActivationSteps(array $entityIds, ActivationStep $step): void
    {
        if (empty($entityIds)) {
            return;
        }

        $activationService = app(ActivationService::class);

        foreach ($entityIds as $entityId) {
            if (blank($entityId)) {
                continue;
            }

            $activationService->complete((string) $entityId, $step);
        }
    }
}
