<?php

namespace Database\Seeders;

use App\Enums\{ActivationStep, ScheduleSituation, SubscriptionStatus};
use App\Models\{Covenant,
    Doctor,
    Entity,
    EntityIntegrator,
    EntityIntegratorEquipment,
    EntityUser,
    EntityUserIntegrator,
    ExamType,
    IrisType,
    Patient,
    PatientExam,
    People,
    Plan,
    Schedule,
    SkinType,
    Subscription,
    User,
    VisitType};
use Carbon\Carbon;
use App\Services\ActivationService;
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

        // Distribuição de status de assinatura: 60% Active, 20% Trial, 10% PastDue, 10% Expired
        $statusDistribution = array_merge(
            array_fill(0, 6, SubscriptionStatus::Active),
            array_fill(0, 2, SubscriptionStatus::Trial),
            array_fill(0, 1, SubscriptionStatus::PastDue),
            array_fill(0, 1, SubscriptionStatus::Expired),
        );

        // Funções dos usuários comuns: maioria 'user', alguns 'secretary' e 'financial'
        $roleDistribution = ['user', 'user', 'user', 'user', 'secretary', 'secretary', 'financial'];

        $this->command->info('⏳ Vinculando Users a Entities...');
        $users->each(function ($user) use ($entities, $roleDistribution) {
            $numberOfEntities = fake()->numberBetween(1, 4);
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
        $entities->each(function ($entity) use ($planDistribution, $statusDistribution) {
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

            // Assinatura com status variado
            $planId = $planDistribution[array_rand($planDistribution)];
            $status = $statusDistribution[array_rand($statusDistribution)];

            if ($planId) {
                $subscriptionData = match ($status) {
                    SubscriptionStatus::Trial => [
                        'entity_id'     => $entity->id,
                        'plan_id'       => $planId,
                        'status'        => SubscriptionStatus::Trial,
                        'trial_ends_at' => now()->addDays(fake()->numberBetween(1, 7)),
                        'starts_at'     => now(),
                        'ends_at'       => null,
                    ],
                    SubscriptionStatus::PastDue => [
                        'entity_id'            => $entity->id,
                        'plan_id'              => $planId,
                        'status'               => SubscriptionStatus::PastDue,
                        'starts_at'            => now()->subMonths(3),
                        'ends_at'              => now()->subDays(fake()->numberBetween(1, 3)),
                        'grace_period_ends_at' => now()->addDays(fake()->numberBetween(1, 3)),
                    ],
                    SubscriptionStatus::Expired => [
                        'entity_id' => $entity->id,
                        'plan_id'   => $planId,
                        'status'    => SubscriptionStatus::Expired,
                        'starts_at' => now()->subYear(),
                        'ends_at'   => now()->subMonths(fake()->numberBetween(1, 6)),
                    ],
                    default => [ // Active
                        'entity_id' => $entity->id,
                        'plan_id'   => $planId,
                        'status'    => SubscriptionStatus::Active,
                        'starts_at' => now()->subMonth(),
                        'ends_at'   => now()->addYear(),
                    ],
                };

                Subscription::create($subscriptionData);
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

        // ── Integrador de teste com credenciais fixas ────────────────────────
        $testEntity = Entity::create([
            'name'      => 'Clínica Teste Integrador',
            'subdomain' => 'clinica-teste-integrador',
            'city'      => 'São Paulo',
            'state'     => 'SP',
            'country'   => 'BR',
            'is_client' => true,
            'active'    => true,
        ]);

        Subscription::create([
            'entity_id' => $testEntity->id,
            'plan_id'   => $planPro?->id,
            'status'    => SubscriptionStatus::Active,
            'starts_at' => now()->subMonth(),
            'ends_at'   => now()->addYear(),
        ]);

        $testIntegratorUser = EntityUserIntegrator::create([
            'entity_id'         => $testEntity->id,
            'name'              => 'Integrador de Teste',
            'email'             => 'integrador@teste.com',
            'email_verified_at' => now(),
            'password'          => Hash::make('Integrador@123'),
            'active'            => true,
        ]);

        $testIntegrator = EntityIntegrator::create([
            'entity_user_integrator_id' => $testIntegratorUser->id,
            'name'                      => 'Equipamento Teste 01',
            'ip'                        => '192.168.1.100',
            'mac'                       => 'AA:BB:CC:DD:EE:01',
            'active'                    => true,
        ]);

        EntityIntegrator::create([
            'entity_user_integrator_id' => $testIntegratorUser->id,
            'name'                      => 'Equipamento Teste 02',
            'ip'                        => '192.168.1.101',
            'mac'                       => 'AA:BB:CC:DD:EE:02',
            'active'                    => true,
        ]);

        // ── Usuários fixos da Clínica Teste Integrador ───────────────────────

        // Admin
        $testAdminPerson = People::create([
            'full_name' => 'ADMIN CLÍNICA TESTE',
            'email'     => 'admin@clinicateste.com',
            'cellphone' => '',
        ]);
        $testAdminUser = User::create([
            'name'              => $testAdminPerson->full_name,
            'email'             => 'admin@clinicateste.com',
            'email_verified_at' => now(),
            'password'          => Hash::make('Admin@123'),
        ]);
        EntityUser::create([
            'entity_id' => $testEntity->id,
            'user_id'   => $testAdminUser->id,
            'rule'      => 'admin',
            'active'    => true,
        ]);

        // Dra. Ana Lima
        $testAnaPersona = People::create([
            'full_name' => 'DRA. ANA LIMA',
            'email'     => 'dra.ana@clinicateste.com',
            'cellphone' => '',
        ]);
        $testAnaUser = User::create([
            'name'              => $testAnaPersona->full_name,
            'email'             => 'dra.ana@clinicateste.com',
            'email_verified_at' => now(),
            'password'          => Hash::make('Medico@123'),
        ]);
        $testAnaEntityUser = EntityUser::create([
            'entity_id' => $testEntity->id,
            'user_id'   => $testAnaUser->id,
            'rule'      => 'doctor',
            'active'    => true,
        ]);
        $testAnaDoctor = Doctor::create([
            'entity_user_id'   => $testAnaEntityUser->id,
            'person_id'        => $testAnaPersona->id,
            'record'           => '123456',
            'record_specialty' => '12345',
            'color'            => '#e91e63',
            'partner'          => false,
            'active'           => true,
        ]);

        // Dr. Carlos Souza
        $testCarlosPerson = People::create([
            'full_name' => 'DR. CARLOS SOUZA',
            'email'     => 'dr.carlos@clinicateste.com',
            'cellphone' => '',
        ]);
        $testCarlosUser = User::create([
            'name'              => $testCarlosPerson->full_name,
            'email'             => 'dr.carlos@clinicateste.com',
            'email_verified_at' => now(),
            'password'          => Hash::make('Medico@123'),
        ]);
        $testCarlosEntityUser = EntityUser::create([
            'entity_id' => $testEntity->id,
            'user_id'   => $testCarlosUser->id,
            'rule'      => 'doctor',
            'active'    => true,
        ]);
        $testCarlosDoctor = Doctor::create([
            'entity_user_id'   => $testCarlosEntityUser->id,
            'person_id'        => $testCarlosPerson->id,
            'record'           => '654321',
            'record_specialty' => '54321',
            'color'            => '#1976d2',
            'partner'          => false,
            'active'           => true,
        ]);

        // Secretária
        $testSecretaryPerson = People::create([
            'full_name' => 'SECRETÁRIA CLÍNICA TESTE',
            'email'     => 'secretaria@clinicateste.com',
            'cellphone' => '',
        ]);
        $testSecretaryUser = User::create([
            'name'              => $testSecretaryPerson->full_name,
            'email'             => 'secretaria@clinicateste.com',
            'email_verified_at' => now(),
            'password'          => Hash::make('Secretaria@123'),
        ]);
        EntityUser::create([
            'entity_id' => $testEntity->id,
            'user_id'   => $testSecretaryUser->id,
            'rule'      => 'secretary',
            'active'    => true,
        ]);

        $this->createTestEntityData($testEntity);

        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('  INTEGRADOR DE TESTE — credenciais fixas');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('  POST /api/integrators/signin');
        $this->command->info('  email    : integrador@teste.com');
        $this->command->info('  password : Integrador@123');
        $this->command->info('  code     : ' . $testIntegrator->code);
        $this->command->info('  entity   : ' . $testEntity->name);
        $this->command->info('  plano    : Pro (has_api_integrator=1, api_monthly_exam_sends=100)');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('  CLÍNICA TESTE — credenciais fixas');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('  ADMIN');
        $this->command->info('  email   : admin@clinicateste.com');
        $this->command->info('  password: Admin@123');
        $this->command->info('───────────────────────────────────────────────────────');
        $this->command->info('  MÉDICO 1 — Dra. Ana Lima');
        $this->command->info('  email   : dra.ana@clinicateste.com');
        $this->command->info('  password: Medico@123');
        $this->command->info('  MÉDICO 2 — Dr. Carlos Souza');
        $this->command->info('  email   : dr.carlos@clinicateste.com');
        $this->command->info('  password: Medico@123');
        $this->command->info('───────────────────────────────────────────────────────');
        $this->command->info('  SECRETÁRIA');
        $this->command->info('  email   : secretaria@clinicateste.com');
        $this->command->info('  password: Secretaria@123');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('');

        // ── Patients ─────────────────────────────────────────────────────────
        $this->command->info('⏳ Criando Patients...');
        $people    = People::query()->select('id')->get();
        $skinTypes = SkinType::all();
        $irisTypes = IrisType::all();
        $covenants = Covenant::all();
        $patientsBatch = [];
        $patientsNow   = now();
        $entityIds     = $entities->pluck('id')->all();
        $patientCodes  = $this->loadEntityCodeCounters(Patient::class, 'PAC', $entityIds);
        $entitiesWithPatients = [];

        foreach ($people as $person) {
            $numberOfEntities = fake()->numberBetween(1, 3);
            $selectedEntities = $entities->random($numberOfEntities);

            foreach ($selectedEntities as $entity) {
                $entityId                 = (string) $entity->id;
                $patientCodes[$entityId]  = ($patientCodes[$entityId] ?? 0) + 1;
                $entitiesWithPatients[$entityId] = true;
                $patientsBatch[]          = [
                    'id'         => (string) Str::uuid(),
                    'entity_id'  => $entityId,
                    'person_id'  => (string) $person->id,
                    'covenant_id' => $covenants->isNotEmpty() ? $covenants->random()->id : null,
                    'skin_id'    => $skinTypes->random()->id,
                    'iris_id'    => $irisTypes->random()->id,
                    'code'       => sprintf('PAC-%010d', $patientCodes[$entityId]),
                    'card_number' => fake()->optional(0.6)->creditCardNumber(),
                    'active'     => fake()->boolean(90),
                    'created_at' => $patientsNow,
                    'updated_at' => $patientsNow,
                ];

                if (count($patientsBatch) >= 1000) {
                    Patient::insert($patientsBatch);
                    $patientsBatch = [];
                }
            }
        }

        if (!empty($patientsBatch)) {
            Patient::insert($patientsBatch);
        }

        $this->markActivationSteps(array_keys($entitiesWithPatients), ActivationStep::FirstPatientAdded);

        // ── Doctors ──────────────────────────────────────────────────────────
        $doctorCount = $this->seedInt('SEED_FAKE_DOCTORS', 250, 1);
        $this->command->info("⏳ Criando Doctors ({$doctorCount})...");
        $entityUsersBatch = [];
        $doctorsBatch     = [];
        $doctorsNow       = now();
        $entitiesWithDoctors = [];
        $entityIsClient   = $entities->pluck('is_client', 'id')->all();
        $entityUserCode   = [
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

            $numberOfEntities = fake()->numberBetween(1, 6);
            $selectedEntities = $entities->random($numberOfEntities);

            foreach ($selectedEntities as $entity) {
                $entityId = (string) $entity->id;
                $prefix   = ($entityIsClient[$entityId] ?? true) ? 'EU' : 'EUP';
                $entityUserCode[$prefix]++;
                $doctorCode++;
                $entitiesWithDoctors[$entityId] = true;

                $entityUserId = (string) Str::uuid();
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

        if (!empty($entityUsersBatch)) {
            EntityUser::insert($entityUsersBatch);
            Doctor::insert($doctorsBatch);
        }

        $this->markActivationSteps(array_keys($entitiesWithDoctors), ActivationStep::FirstDoctorAdded);
        $this->markActivationSteps(array_keys($entitiesWithDoctors), ActivationStep::TeamMemberInvited);

        // ── Schedules + PatientExams ─────────────────────────────────────────
        $this->command->info('⏳ Criando Schedules e PatientExams...');
        $this->createSchedules();
    }

    /**
     * Criar schedules cobrindo 1 mês passado e 3 meses futuros (segunda a domingo).
     * Situações realistas: passados → Attended/NoShow/Cancelled; futuros → Scheduled/Cancelled.
     * PatientExams são gerados para 30% dos agendamentos já Attended.
     */
    private function createSchedules(): void
    {
        $doctors        = Doctor::query()->select('id', 'entity_user_id')->get();
        $schedulesBatch = [];
        $batchSize      = $this->seedInt('SEED_FAKE_SCHEDULE_BATCH_SIZE', 1000, 100);
        $codeCounter    = [];

        // Cache em memória para evitar queries repetidas durante o loop
        $entityUsers        = EntityUser::query()->select('id', 'entity_id')->get()->keyBy('id');
        $entitiesById       = Entity::query()->select('id', 'schedule_interval')->get()->keyBy('id');
        $allPatients        = Patient::query()
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
        $pastMonths  = $this->seedInt('SEED_FAKE_SCHEDULE_PAST_MONTHS', 1, 0);
        $futureMonths = $this->seedInt('SEED_FAKE_SCHEDULE_FUTURE_MONTHS', 3, 0);
        $startDate = Carbon::now()->subMonths($pastMonths)->startOfDay();
        $endDate   = Carbon::now()->addMonths($futureMonths)->endOfDay();
        $date      = $startDate->copy();
        $attendedExamChance = $this->seedPercent('SEED_FAKE_ATTENDED_EXAM_PERCENT', 30);

        // Coleta de agendamentos Attended para gerar PatientExams posteriormente
        $attendedForExams = [];
        $entitiesWithSchedules = [];

        while ($date->lte($endDate)) {
            $dailyPatientPools = [];
            $isPast            = $date->copy()->endOfDay()->isPast();

            foreach ($doctors as $doctor) {
                $entityUser = $entityUsers->get($doctor->entity_user_id);

                if (!$entityUser) {
                    continue;
                }

                $entityId = $entityUser->entity_id;

                if (!isset($codeCounter[$entityId])) {
                    $codeCounter[$entityId] = 0;
                }

                $patientsOfEntity   = $allPatients->get($entityId, collect());
                $covenantsOfEntity  = $covenantsByEntity->get($entityId, collect())->merge($globalCovenants);
                $visitTypesOfEntity = $visitTypesByEntity->get($entityId, collect())->merge($globalVisitTypes);

                if ($patientsOfEntity->isEmpty() || $covenantsOfEntity->isEmpty() || $visitTypesOfEntity->isEmpty()) {
                    continue;
                }

                if (!isset($dailyPatientPools[$entityId])) {
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
                    $schedulesBatch[] = $slot;
                    $entitiesWithSchedules[$slot['entity_id']] = true;

                    // Seleciona 30% dos Attended passados para geração de exames
                    if ($isPast && $slot['situation'] === ScheduleSituation::Attended->value && fake()->boolean($attendedExamChance)) {
                        $attendedForExams[] = [
                            'id'         => $slot['id'],
                            'patient_id' => $slot['patient_id'],
                            'doctor_id'  => $slot['doctor_id'],
                            'entity_id'  => $slot['entity_id'],
                        ];
                    }
                }

                if (count($schedulesBatch) >= $batchSize) {
                    Schedule::insert($schedulesBatch);
                    $schedulesBatch = [];
                }

                unset($patientPool);
            }

            $date->addDay();
        }

        if (!empty($schedulesBatch)) {
            Schedule::insert($schedulesBatch);
        }

        $this->markActivationSteps(array_keys($entitiesWithSchedules), ActivationStep::FirstScheduleCreated);
        $this->createPatientExams($attendedForExams);
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

        while ($currentTime < $endTime && !empty($patientPool)) {
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
     * Criar PatientExams para agendamentos Attended selecionados (1–3 exames cada).
     * Usa inserção em lote com contador EXM-* em memória por paciente.
     */
    private function createPatientExams(array $attendedSchedules): void
    {
        if (empty($attendedSchedules)) {
            return;
        }

        $patientExamBatch  = [];
        $patientExamNow    = now();
        $patientExamCode   = $this->loadPatientExamCodeCounters();
        $allExamTypes      = ExamType::all();
        $globalExamTypes   = $allExamTypes->whereNull('entity_id');
        $examTypesByEntity = $allExamTypes->whereNotNull('entity_id')->groupBy('entity_id');

        foreach ($attendedSchedules as $schedule) {
            $examTypesOfEntity = $examTypesByEntity
                ->get($schedule['entity_id'], collect())
                ->merge($globalExamTypes);

            if ($examTypesOfEntity->isEmpty()) {
                continue;
            }

            $examCount = fake()->numberBetween(1, 3);

            for ($i = 0; $i < $examCount; $i++) {
                $patientId                    = (string) $schedule['patient_id'];
                $patientExamCode[$patientId]  = ($patientExamCode[$patientId] ?? 0) + 1;
                $patientExamBatch[]           = [
                    'id'         => (string) Str::uuid(),
                    'patient_id' => $patientId,
                    'doctor_id'  => $schedule['doctor_id'],
                    'schedule_id' => $schedule['id'],
                    'exam_id'    => $examTypesOfEntity->random()->id,
                    'code'       => sprintf('EXM-%010d', $patientExamCode[$patientId]),
                    'archive'    => 'exams/fake-' . Str::uuid() . '.jpg',
                    'name'       => fake()->optional(0.5)->sentence(3),
                    'laterality' => fake()->randomElement([null, null, 0, 1, 2]),
                    'active'     => true,
                    'created_at' => $patientExamNow,
                    'updated_at' => $patientExamNow,
                ];

                if (count($patientExamBatch) >= 1000) {
                    PatientExam::insert($patientExamBatch);
                    $patientExamBatch = [];
                }
            }
        }

        if (!empty($patientExamBatch)) {
            PatientExam::insert($patientExamBatch);
        }
    }

    /**
     * Criar pacientes para a entidade de teste do integrador.
     * Schedules e exames são gerados pelo createSchedules() via Doctor::all().
     */
    private function createTestEntityData(Entity $entity): void
    {
        $this->command->info('⏳ Criando pacientes de teste para Clínica Teste Integrador...');

        $skinTypes = SkinType::all();
        $irisTypes = IrisType::all();
        $covenants = Covenant::all();

        for ($i = 0; $i < 20; $i++) {
            $person = People::factory()->create();
            Patient::create([
                'entity_id'   => $entity->id,
                'person_id'   => $person->id,
                'covenant_id' => $covenants->isNotEmpty() ? $covenants->random()->id : null,
                'skin_id'     => $skinTypes->random()->id,
                'iris_id'     => $irisTypes->random()->id,
                'card_number' => fake()->optional(0.6)->creditCardNumber(),
                'active'      => true,
            ]);
        }
    }

    /**
     * @param  array<int, string>  $entityIds
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

        if (!is_string($lastCode)) {
            return 0;
        }

        return (int) substr($lastCode, strlen($prefix) + 1);
    }

    /**
     * @return array<string, int>
     */
    private function loadPatientExamCodeCounters(): array
    {
        $codes = [];
        $rows  = PatientExam::withoutGlobalScopes()
            ->select('patient_id', 'code')
            ->where('code', 'like', 'EXM-%')
            ->orderBy('patient_id')
            ->orderByDesc('code')
            ->get();

        foreach ($rows as $row) {
            $patientId = (string) $row->patient_id;

            if (isset($codes[$patientId])) {
                continue;
            }

            $codes[$patientId] = (int) substr((string) $row->code, 4);
        }

        return $codes;
    }

    private function seedInt(string $key, int $default, int $min): int
    {
        $value = env($key);

        if (!is_numeric($value)) {
            return $default;
        }

        return max($min, (int) $value);
    }

    private function seedPercent(string $key, int $default): int
    {
        $value = env($key);

        if (!is_numeric($value)) {
            return $default;
        }

        return min(100, max(0, (int) $value));
    }

    /**
     * @param  array<int, string>  $entityIds
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
