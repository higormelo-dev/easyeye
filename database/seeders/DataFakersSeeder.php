<?php

namespace Database\Seeders;

use App\Models\{Covenant, Entity, EntityIntegrator, EntityUser, IrisType, Patient, People, SkinType, User};
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
        $entityUserCounters      = [];
        $entityIntegratorCounter = [];
        $patientCounter          = [];

        People::factory(3000)->create();
        Entity::factory(15)
            ->sequence(fn ($attributes) => [
                'code'      => 'ENT-' . str_pad($attributes->index + 2, 10, '0', STR_PAD_LEFT),
                'subdomain' => Str::slug(fake()->company()) . '-' . ($attributes->index + 1),
            ])
            ->create();
        $entities = Entity::query()->whereNot('name', 'Medical Group')->get();
        $users    = User::factory(95)->create(['password' => Hash::make('123456789')]);

        $users->each(function ($user) use ($entities, &$entityUserCounters) {
            // Cada usuário se vincula a 1-4 entities aleatórias
            $numberOfEntities = fake()->numberBetween(1, 4);
            $selectedEntities = $entities->random($numberOfEntities);

            $selectedEntities->each(function ($entity) use ($user, &$entityUserCounters) {
                // Inicializa contador para esta entity se não existir
                if (! isset($entityUserCounters[$entity->id])) {
                    $entityUserCounters[$entity->id] = 1;
                }

                EntityUser::create([
                    'entity_id' => $entity->id,
                    'user_id'   => $user->id,
                    'code'      => 'EU-' . str_pad($entityUserCounters[$entity->id]++, 10, '0', STR_PAD_LEFT),
                    'active'    => true,
                    'rule'      => 'user', // Inicialmente todos como user
                ]);
            });
        });

        // Após criar todos os vínculos, verificar entities com 2+ usuários e adicionar admin
        $entities->each(function ($entity) use (&$entityIntegratorCounter) {
	        // Inicializa contador para esta integrador se não existir
            if (! isset($entityIntegratorCounter[$entity->id])) {
                $entityIntegratorCounter[$entity->id] = 1;
            }

            $userCount = EntityUser::query()->where('entity_id', $entity->id)->count();

            if ($userCount >= 2) {
                // Pegar um usuário aleatório desta entity e tornar admin
                $randomEntityUser = EntityUser::query()
                    ->where('entity_id', $entity->id)
                    ->where('rule', 'user')
                    ->inRandomOrder()
                    ->first();

                if ($randomEntityUser) {
                    $randomEntityUser->update(['rule' => 'admin']);
                }
            }

            EntityIntegrator::factory(10)
                ->sequence(fn ($attributes) => [
                    'code' => 'EI-' . str_pad($entityIntegratorCounter[$entity->id]++, 10, '0', STR_PAD_LEFT),
                ])
                ->create(['entity_id' => $entity->id]);
        });

        $people    = People::all();
        $skinTypes = SkinType::all();
        $irisTypes = IrisType::all();
        $covenants = Covenant::all();

        $people->each(function ($person) use ($entities, $skinTypes, $irisTypes, $covenants, &$patientCounter) {
            $numberOfEntities = fake()->numberBetween(1, 3);
            $selectedEntities = $entities->random($numberOfEntities);

            $selectedEntities->each(function ($entity) use ($person, $skinTypes, $irisTypes, $covenants, &$patientCounter) {
                // Inicializa contador para este paciente se não existir
                if (! isset($patientCounter[$entity->id])) {
                    $patientCounter[$entity->id] = 1;
                }

                Patient::create([
                    'entity_id'   => $entity->id,
                    'person_id'   => $person->id,
                    'covenant_id' => $covenants->isNotEmpty() ? $covenants->random()->id : null,
                    'skin_id'     => $skinTypes->random()->id,
                    'iris_id'     => $irisTypes->random()->id,
                    'code'        => 'PAC-' . str_pad($patientCounter[$entity->id]++, 10, '0', STR_PAD_LEFT),
                    'card_number' => fake()->optional(0.6)->creditCardNumber(),
                    'active'      => fake()->boolean(90),
                ]);
            });
        });
    }
}
