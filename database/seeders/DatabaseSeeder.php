<?php

namespace Database\Seeders;

use App\Models\{Entity, EntityIntegrator, EntityUser, IrisType, People, SkinType, User};
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $entity = Entity::create([
            'name'                   => 'Medical Group',
            'subdomain'              => 'medicalgroup',
            'zipcode'                => '09015620',
            'address'                => 'Rua Tatuí',
            'number'                 => '507',
            'complement'             => 'Apto 82',
            'district'               => 'Casa Branca',
            'city'                   => 'Santo André',
            'state'                  => 'SP',
            'country'                => 'Brasil',
            'national_registration'  => '01234567890123',
            'state_registration'     => '4567890123456',
            'municipal_registration' => '78901234567890',
            'telephone'              => '1140028922',
            'cellphone'              => '11999999999',
            'email'                  => 'contato@medicalgroup.com',
            'website'                => 'medicalgroup.com',
            'logo'                   => null,
            'is_client'              => false,
            'active'                 => true,
        ]);
        $higor = User::create([
            'name'              => 'Higor',
            'email'             => 'higor_ap89@icloud.com',
            'email_verified_at' => Carbon::now(),
            'password'          => Hash::make('$2y$10$92IX'),
            'remember_token'    => Str::random(10),
        ]);
        EntityUser::create([
            'entity_id' => $entity->id,
            'user_id'   => $higor->id,
            'active'    => true,
            'rule'      => 'admin',
        ]);

        People::factory(3000)->create();
        Entity::factory(15)->create();
        $entities = Entity::all();
        $users    = User::factory(95)->create(['password' => Hash::make('123456789')]);

        $skinTypes = [
            ['name' => 'Extremamente branca', 'active' => true],
            ['name' => 'Branca', 'active' => true],
            ['name' => 'Morena clara', 'active' => true],
            ['name' => 'Morena média', 'active' => true],
            ['name' => 'Morena escura', 'active' => true],
            ['name' => 'Negra', 'active' => true],
        ];

        foreach ($skinTypes as $skinType) {
            SkinType::query()->create($skinType);
        }

        $irisTypes = [
            ['name' => 'Azul', 'active' => true],
            ['name' => 'Verde', 'active' => true],
            ['name' => 'Castanho', 'active' => true],
        ];

        foreach ($irisTypes as $irisType) {
            IrisType::query()->create($irisType);
        }

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
    }
}
