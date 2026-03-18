<?php

namespace Database\Seeders;

use App\Enums\SubscriptionStatus;
use App\Models\{Covenant,
    Doctor,
    Entity,
    EntityIntegrator,
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
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DataFakersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @method info(string $string)
     */
    public function run(): void
    {
        People::factory(3000)->create();
        Entity::factory(15)
            ->sequence(fn ($attributes) => [
                'subdomain' => Str::slug(fake()->company()) . '-' . ($attributes->index + 1),
            ])
            ->create();
        $entities = Entity::query()->whereNot('name', 'Medical Group')->get();
        $users    = User::factory(95)->create(['password' => Hash::make('123456789')]);

        // Carregar planos para distribuir entre as entidades
        $planBasico  = Plan::where('slug', 'basico')->first();
        $planPro     = Plan::where('slug', 'pro')->first();
        $planPremium = Plan::where('slug', 'premium')->first();

        // Distribuição: 20% Básico, 50% Pro, 30% Premium
        $planDistribution = array_merge(
            array_fill(0, 3, $planBasico?->id),
            array_fill(0, 7, $planPro?->id),
            array_fill(0, 5, $planPremium?->id),
        );

        $users->each(function ($user) use ($entities) {
            // Cada usuário se vincula a 1-4 entities aleatórias
            $numberOfEntities = fake()->numberBetween(1, 4);
            $selectedEntities = $entities->random($numberOfEntities);

            $selectedEntities->each(function ($entity) use ($user) {
                EntityUser::create([
                    'entity_id' => $entity->id,
                    'user_id'   => $user->id,
                    'active'    => true,
                    'rule'      => 'user',
                ]);
            });
        });

        // Após criar todos os vínculos, verificar entities com 2+ usuários e adicionar admin
        $entities->each(function ($entity) use ($planDistribution) {
            $userCount = EntityUser::query()->where('entity_id', $entity->id)->count();

            if ($userCount >= 2) {
                $randomEntityUser = EntityUser::query()
                    ->where('entity_id', $entity->id)
                    ->where('rule', 'user')
                    ->inRandomOrder()
                    ->first();

                if ($randomEntityUser) {
                    $randomEntityUser->update(['rule' => 'admin']);
                }
            }

            // Criar subscription com plano aleatório da distribuição
            $planId = $planDistribution[array_rand($planDistribution)];
            if ($planId) {
                Subscription::create([
                    'entity_id'  => $entity->id,
                    'plan_id'    => $planId,
                    'status'     => SubscriptionStatus::Active,
                    'starts_at'  => now()->subMonth(),
                    'ends_at'    => now()->addYear(),
                ]);
            }

            $entityUserIntegrator = EntityUserIntegrator::factory(5)
                ->create(['entity_id' => $entity->id, 'password' => Hash::make('123456789')]);

            $entityUserIntegrator->each(function ($entityUser) {
                EntityIntegrator::factory(10)
                    ->create(['entity_user_integrator_id' => $entityUser->id]);
            });
        });

        // ── Integrador de teste com credenciais fixas ────────────────────────
        // Entity dedicada para testes de integração
        $testEntity = Entity::create([
            'name'      => 'Clínica Teste Integrador',
            'subdomain' => 'clinica-teste-integrador',
            'city'      => 'São Paulo',
            'state'     => 'SP',
            'country'   => 'BR',
            'is_client' => true,
            'active'    => true,
        ]);

        // Subscription Pro para a entity de teste
        Subscription::create([
            'entity_id'  => $testEntity->id,
            'plan_id'    => $planPro?->id,
            'status'     => SubscriptionStatus::Active,
            'starts_at'  => now()->subMonth(),
            'ends_at'    => now()->addYear(),
        ]);

        // Usuário integrador com credenciais fixas
        $testIntegratorUser = EntityUserIntegrator::create([
            'entity_id'         => $testEntity->id,
            'name'              => 'Integrador de Teste',
            'email'             => 'integrador@teste.com',
            'email_verified_at' => now(),
            'password'          => Hash::make('Integrador@123'),
            'active'            => true,
        ]);

        // Integradores ativos (equipamentos) com credenciais conhecidas
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
        $this->command->info('  plano    : Pro (has_api_integrator=1, per_page max=100)');
        $this->command->info('═══════════════════════════════════════════════════════');
        $this->command->info('');

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

        // Criar Doctors com User, EntityUser e People
        $doctorCount = 250;

        for ($i = 0; $i < $doctorCount; $i++) {
            // Criar User para o médico
            $userDoctor = User::factory()->create(['password' => Hash::make('123456789')]);
            // Criar People para o médico (dados pessoais)
            $person = People::factory()->create();
            // Vincular a 1-6 entities aleatórias
            $numberOfEntities = fake()->numberBetween(1, 6);
            $selectedEntities = $entities->random($numberOfEntities);

            $selectedEntities->each(function ($entity) use ($userDoctor, $person) {
                // Criar EntityUser com rule 'doctor'
                $entityUserDoctor = EntityUser::create([
                    'entity_id' => $entity->id,
                    'user_id'   => $userDoctor->id,
                    'active'    => true,
                    'rule'      => 'doctor',
                ]);

                // Criar Doctor com todos os campos
                Doctor::create([
                    'entity_user_id'   => $entityUserDoctor->id,
                    'person_id'        => $person->id,
                    'record'           => fake()->unique()->numerify('CRM-######'),
                    'record_specialty' => fake()->optional(0.7)->numerify('RQE-#####'),
                    'color'            => fake()->hexColor(),
                    'partner'          => fake()->boolean(20),
                    'active'           => fake()->boolean(90),
                    'observation'      => fake()->optional(0.3)->sentence(),
                ]);
            });
        }

        // Criar Schedules para todos os médicos
        $this->createSchedules();
    }

    /**
     * Criar schedules para todos os médicos em dias úteis
     * Horários: Manhã (08:00-12:00) e Tarde (14:00-18:00)
     * Intervalos variando entre 20 a 30 minutos
     */
    private function createSchedules(): void
    {
        $doctors        = Doctor::all();
        $schedulesBatch = [];
        $batchSize      = 1000;
        $codeCounter    = [];

        // Cache de dados para evitar consultas repetidas
        $entityUsers       = EntityUser::all()->keyBy('id');
        $allPatients       = Patient::with('person')->get()->groupBy('entity_id');
        $allCovenants      = Covenant::all();
        $globalCovenants   = $allCovenants->whereNull('entity_id');
        $covenantsByEntity = $allCovenants->whereNotNull('entity_id')->groupBy('entity_id');

        // Cache de VisitTypes
        $allVisitTypes      = VisitType::all();
        $globalVisitTypes   = $allVisitTypes->whereNull('entity_id');
        $visitTypesByEntity = $allVisitTypes->whereNotNull('entity_id')->groupBy('entity_id');

        // Gerar agendamentos para os próximos 30 dias úteis
        $startDate      = Carbon::now();
        $daysToGenerate = 30;
        $currentDay     = 0;
        $date           = $startDate->copy();

        while ($currentDay < $daysToGenerate) {
            // Pular fins de semana
            if ($date->isWeekday()) {
                $currentDay++;
                
                // Reset dos pacientes usados para cada dia
                $usedPatientsPerDay = [];

                foreach ($doctors as $doctor) {
                    $entityUser = $entityUsers->get($doctor->entity_user_id);

                    if (! $entityUser) {
                        continue;
                    }

                    $entityId = $entityUser->entity_id;

                    // Inicializar contador de código por entity
                    if (! isset($codeCounter[$entityId])) {
                        $lastSchedule = Schedule::where('entity_id', $entityId)
                            ->where('code', 'like', 'SDL-%')
                            ->orderBy('code', 'desc')
                            ->first();
                        $codeCounter[$entityId] = $lastSchedule
                            ? (int) substr($lastSchedule->code, 4)
                            : 0;
                    }
                    
                    // Inicializar array de pacientes usados para esta entity neste dia
                    if (! isset($usedPatientsPerDay[$entityId])) {
                        $usedPatientsPerDay[$entityId] = [];
                    }

                    // Usar cache ao invés de consultar o banco
                    $patientsOfEntity   = $allPatients->get($entityId, collect());
                    $covenantsOfEntity  = $covenantsByEntity->get($entityId, collect())->merge($globalCovenants);
                    $visitTypesOfEntity = $visitTypesByEntity->get($entityId, collect())->merge($globalVisitTypes);

                    if ($patientsOfEntity->isEmpty() || $covenantsOfEntity->isEmpty() || $visitTypesOfEntity->isEmpty()) {
                        continue;
                    }
                    
                    // Filtrar pacientes que ainda não foram usados neste dia
                    $availablePatients = $patientsOfEntity->filter(function ($patient) use ($usedPatientsPerDay, $entityId) {
                        return !in_array($patient->id, $usedPatientsPerDay[$entityId]);
                    });
                    
                    if ($availablePatients->isEmpty()) {
                        continue;
                    }

                    // Gerar horários manhã e tarde
                    $morningSlots   = $this->generateTimeSlots($date, 8, 12, $doctor, $entityId, $availablePatients, $covenantsOfEntity, $visitTypesOfEntity, $codeCounter, $usedPatientsPerDay);
                    $afternoonSlots = $this->generateTimeSlots($date, 14, 18, $doctor, $entityId, $availablePatients, $covenantsOfEntity, $visitTypesOfEntity, $codeCounter, $usedPatientsPerDay);

                    foreach ($morningSlots as $slot) {
                        $schedulesBatch[] = $slot;
                    }

                    foreach ($afternoonSlots as $slot) {
                        $schedulesBatch[] = $slot;
                    }

                    // Inserir em lotes
                    if (count($schedulesBatch) >= $batchSize) {
                        Schedule::insert($schedulesBatch);
                        $schedulesBatch = [];
                    }
                }
            }

            $date->addDay();
        }

        // Inserir registros restantes
        if (! empty($schedulesBatch)) {
            Schedule::insert($schedulesBatch);
        }
    }

    /**
     * Gerar slots de horário com intervalos variáveis de 20-30 minutos
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
        array &$usedPatientsPerDay
    ): array {
        $schedules   = [];
        $currentTime = $date->copy()->setTime($startHour, 0, 0);
        $endTime     = $date->copy()->setTime($endHour, 0, 0);
        $now         = Carbon::now();
        
        // Converter para array para poder manipular
        $availablePatients = $patients->values()->all();

        while ($currentTime < $endTime) {
            // Filtrar pacientes ainda disponíveis (não usados neste dia)
            $availablePatients = array_filter($availablePatients, function ($patient) use ($usedPatientsPerDay, $entityId) {
                return !in_array($patient->id, $usedPatientsPerDay[$entityId]);
            });
            
            // Se não há mais pacientes disponíveis, parar de gerar slots
            if (empty($availablePatients)) {
                break;
            }
            
            // Reindexar array
            $availablePatients = array_values($availablePatients);
            
            // Selecionar paciente aleatório dos disponíveis
            $patient = $availablePatients[array_rand($availablePatients)];
            
            // Marcar paciente como usado neste dia para esta entity
            $usedPatientsPerDay[$entityId][] = $patient->id;
            
            $covenant  = $covenants->random();
            $visitType = $visitTypes->random();

            $fullName = $patient->person
                ? $patient->person->full_name
                : fake()->name();

            $codeCounter[$entityId]++;
            $code = sprintf('SDL-%010d', $codeCounter[$entityId]);

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
                'situation'          => fake()->randomElement([0, 1, 2, 3]),
                'active'             => fake()->boolean(95),
                'created_at'         => $now,
                'updated_at'         => $now,
            ];

            $interval = fake()->numberBetween(20, 30);
            $currentTime->addMinutes($interval);
        }

        return $schedules;
    }
}
