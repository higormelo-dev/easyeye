<?php

namespace Database\Seeders;

use App\Models\{Covenant, Entity, EntityIntegrator, EntityUser, IrisType, Patient, People, SkinType, User};
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DataFakersSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        People::factory(3000)->create();
        Entity::factory(15)->create();
        $entities = Entity::query()->whereNot('name', 'Medical Group')->get();
        $users    = User::factory(95)->create(['password' => Hash::make('123456789')]);

        $users->each(function ($user) use ($entities) {
            // Cada usuário se vincula a 1-4 entities aleatórias
            $numberOfEntities = fake()->numberBetween(1, 4);
            $selectedEntities = $entities->random($numberOfEntities);

            $selectedEntities->each(function ($entity) use ($user) {
                EntityUser::create([
                    'entity_id' => $entity->id,
                    'user_id'   => $user->id,
                    'active'    => true,
                    'rule'      => 'user', // Inicialmente todos como user
                ]);
            });
        });

        // Após criar todos os vínculos, verificar entities com 2+ usuários e adicionar admin
        $entities->each(function ($entity) {
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

            EntityIntegrator::factory(10)->create(['entity_id' => $entity->id]);
        });

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
                    'code'        => fake()->unique()->numerify('PAC####'),
                    'card_number' => fake()->optional(0.6)->creditCardNumber(),
                    'active'      => fake()->boolean(90),
                ]);
            });
        });
    }
}
