<?php

namespace Database\Seeders;

use App\Enums\{ScheduleSituation, SubscriptionStatus};
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
    TvDisplay,
    User,
    VisitType};
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
        $this->command->info('⏳ Criando People (3.000)...');
        People::factory(3000)->create();

        $this->command->info('⏳ Criando Entities (15)...');
        Entity::factory(15)
            ->sequence(fn ($attributes) => [
                'subdomain' => Str::slug(fake()->company()) . '-' . ($attributes->index + 1),
            ])
            ->create();

        // Apenas as entities-cliente criadas pelo factory (exclui o grupo gestor)
        $entities = Entity::query()
            ->where('is_client', true)
            ->whereNot('name', 'Medical Group')
            ->get();

        $this->command->info('⏳ Criando Users (95)...');
        $users = User::factory(95)->create(['password' => Hash::make('123456789')]);

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
                        'entity_id'  => $entity->id,
                        'plan_id'    => $planId,
                        'status'     => SubscriptionStatus::Expired,
                        'starts_at'  => now()->subYear(),
                        'ends_at'    => now()->subMonths(fake()->numberBetween(1, 6)),
                    ],
                    default => [ // Active
                        'entity_id'  => $entity->id,
                        'plan_id'    => $planId,
                        'status'     => SubscriptionStatus::Active,
                        'starts_at'  => now()->subMonth(),
                        'ends_at'    => now()->addYear(),
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

            // TVs da sala de espera: 1-3 por entity (mix de estados)
            $tvCount = fake()->numberBetween(1, 3);
            for ($i = 0; $i < $tvCount; $i++) {
                $isPending = fake()->boolean(25); // ~25% pendente, ~75% ativa
                TvDisplay::create([
                    'entity_id' => $entity->id,
                    'name'      => 'TV Sala ' . ($i + 1),
                    'status'    => $isPending ? TvDisplay::STATUS_PENDING : TvDisplay::STATUS_ACTIVE,
                    'token'     => $isPending ? null : TvDisplay::generateToken(),
                    'pin'       => $isPending ? TvDisplay::generatePin() : null,
                    'active'    => ! $isPending,
                ]);
            }
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
            'entity_id'  => $testEntity->id,
            'plan_id'    => $planPro?->id,
            'status'     => SubscriptionStatus::Active,
            'starts_at'  => now()->subMonth(),
            'ends_at'    => now()->addYear(),
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

        // ── Patients ─────────────────────────────────────────────────────────
        $this->command->info('⏳ Criando Patients...');
        $people    = People::all();
        $skinTypes = SkinType::all();
        $irisTypes = IrisType::all();
        $covenants = Covenant::all();

        $people->each(function ($person) use ($entities, $skinTypes, $irisTypes, $covenants) {
            $numberOfEntities = fake()->numberBetween(1, 3);
            $selectedEntities = $entities->random($numberOfEntities);

            $selectedEntities->each(function ($entity) use ($person, $skinTypes, $irisTypes, $covenants) {
                Patient::create([
                    'entity_id'   => $entity->id,
                    'person_id'   => $person->id,
                    'covenant_id' => $covenants->isNotEmpty() ? $covenants->random()->id : null,
                    'skin_id'     => $skinTypes->random()->id,
                    'iris_id'     => $irisTypes->random()->id,
                    'card_number' => fake()->optional(0.6)->creditCardNumber(),
                    'active'      => fake()->boolean(90),
                ]);
            });
        });

        // ── Doctors ──────────────────────────────────────────────────────────
        $this->command->info('⏳ Criando Doctors (250)...');
        $doctorCount = 250;

        for ($i = 0; $i < $doctorCount; $i++) {
            $userDoctor = User::factory()->create(['password' => Hash::make('123456789')]);
            $person     = People::factory()->create();

            $numberOfEntities = fake()->numberBetween(1, 6);
            $selectedEntities = $entities->random($numberOfEntities);

            $selectedEntities->each(function ($entity) use ($userDoctor, $person) {
                $entityUserDoctor = EntityUser::create([
                    'entity_id' => $entity->id,
                    'user_id'   => $userDoctor->id,
                    'active'    => true,
                    'rule'      => 'doctor',
                ]);

                Doctor::create([
                    'entity_user_id'   => $entityUserDoctor->id,
                    'person_id'        => $person->id,
                    'record'           => fake()->unique()->numerify('######'),
                    'record_specialty' => fake()->optional(0.7)->numerify('#####'),
                    'color'            => fake()->hexColor(),
                    'partner'          => fake()->boolean(20),
                    'active'           => fake()->boolean(90),
                    'observation'      => fake()->optional(0.3)->sentence(),
                ]);
            });
        }

        // ── Schedules + PatientExams ─────────────────────────────────────────
        $this->command->info('⏳ Criando Schedules e PatientExams...');
        $this->createSchedules();
    }

    /**
     * Criar schedules cobrindo 15 dias passados e 15 dias futuros (dias úteis).
     * Situações realistas: passados → Attended/NoShow/Cancelled; futuros → Scheduled/Cancelled.
     * PatientExams são gerados para 30% dos agendamentos já Attended.
     */
    private function createSchedules(): void
    {
        $doctors        = Doctor::all();
        $schedulesBatch = [];
        $batchSize      = 1000;
        $codeCounter    = [];

        // Cache em memória para evitar queries repetidas durante o loop
        $entityUsers        = EntityUser::all()->keyBy('id');
        $allPatients        = Patient::with('person')->get()->groupBy('entity_id');
        $allCovenants       = Covenant::all();
        $globalCovenants    = $allCovenants->whereNull('entity_id');
        $covenantsByEntity  = $allCovenants->whereNotNull('entity_id')->groupBy('entity_id');
        $allVisitTypes      = VisitType::all();
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

        // Janela temporal: 15 dias passados até 15 dias futuros
        $startDate      = Carbon::now()->subDays(15);
        $daysToGenerate = 30;
        $currentDay     = 0;
        $date           = $startDate->copy();

        // Coleta de agendamentos Attended para gerar PatientExams posteriormente
        $attendedForExams = [];

        while ($currentDay < $daysToGenerate) {
            if ($date->isWeekday()) {
                $currentDay++;
                $usedPatientsPerDay = [];
                $isPast             = $date->copy()->endOfDay()->isPast();

                foreach ($doctors as $doctor) {
                    $entityUser = $entityUsers->get($doctor->entity_user_id);

                    if (! $entityUser) {
                        continue;
                    }

                    $entityId = $entityUser->entity_id;

                    if (! isset($usedPatientsPerDay[$entityId])) {
                        $usedPatientsPerDay[$entityId] = [];
                    }

                    if (! isset($codeCounter[$entityId])) {
                        $codeCounter[$entityId] = 0;
                    }

                    $patientsOfEntity   = $allPatients->get($entityId, collect());
                    $covenantsOfEntity  = $covenantsByEntity->get($entityId, collect())->merge($globalCovenants);
                    $visitTypesOfEntity = $visitTypesByEntity->get($entityId, collect())->merge($globalVisitTypes);

                    if ($patientsOfEntity->isEmpty() || $covenantsOfEntity->isEmpty() || $visitTypesOfEntity->isEmpty()) {
                        continue;
                    }

                    $availablePatients = $patientsOfEntity->filter(
                        fn ($patient) => ! in_array($patient->id, $usedPatientsPerDay[$entityId])
                    );

                    if ($availablePatients->isEmpty()) {
                        continue;
                    }

                    $morningSlots   = $this->generateTimeSlots($date, 8, 12, $doctor, $entityId, $availablePatients, $covenantsOfEntity, $visitTypesOfEntity, $codeCounter, $usedPatientsPerDay, $isPast);
                    $afternoonSlots = $this->generateTimeSlots($date, 14, 18, $doctor, $entityId, $availablePatients, $covenantsOfEntity, $visitTypesOfEntity, $codeCounter, $usedPatientsPerDay, $isPast);

                    foreach (array_merge($morningSlots, $afternoonSlots) as $slot) {
                        $schedulesBatch[] = $slot;

                        // Seleciona 30% dos Attended passados para geração de exames
                        if ($isPast && $slot['situation'] === ScheduleSituation::Attended->value && fake()->boolean(30)) {
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
                }
            }

            $date->addDay();
        }

        if (! empty($schedulesBatch)) {
            Schedule::insert($schedulesBatch);
        }

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
        $patients,
        $covenants,
        $visitTypes,
        array &$codeCounter,
        array &$usedPatientsPerDay,
        bool $isPast = false,
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

        $availablePatients = $patients->values()->all();

        while ($currentTime < $endTime) {
            $availablePatients = array_values(array_filter(
                $availablePatients,
                fn ($p) => ! in_array($p->id, $usedPatientsPerDay[$entityId])
            ));

            if (empty($availablePatients)) {
                break;
            }

            $patient = $availablePatients[array_rand($availablePatients)];
            $usedPatientsPerDay[$entityId][] = $patient->id;

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

            $currentTime->addMinutes(fake()->numberBetween(20, 30));
        }

        return $schedules;
    }

    /**
     * Criar PatientExams para agendamentos Attended selecionados (1–3 exames cada).
     * Usa create() individual para disparar o booted() que auto-gera o código EXM-*.
     */
    private function createPatientExams(array $attendedSchedules): void
    {
        if (empty($attendedSchedules)) {
            return;
        }

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
                // create() dispara o booted() do modelo, gerando o código EXM-* por patient
                PatientExam::create([
                    'patient_id'  => $schedule['patient_id'],
                    'doctor_id'   => $schedule['doctor_id'],
                    'schedule_id' => $schedule['id'],
                    'exam_id'     => $examTypesOfEntity->random()->id,
                    'archive'     => 'exams/fake-' . Str::uuid() . '.jpg',
                    'name'        => fake()->optional(0.5)->sentence(3),
                    'active'      => true,
                ]);
            }
        }
    }
}
